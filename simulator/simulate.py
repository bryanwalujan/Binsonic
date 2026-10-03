#!/usr/bin/env python3
"""Simulator berlabel SIMULASI; telemetry berkala tidak menambah kejadian sampah."""
import argparse
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import random
import time
from urllib.parse import urlsplit
import uuid

import requests


class DeliveryError(RuntimeError):
    pass


class Client:
    def __init__(self, base_url, token, session=None, sleep=time.sleep):
        parsed = urlsplit(base_url)
        if parsed.scheme not in ('http', 'https') or not parsed.hostname or parsed.username or parsed.password or parsed.query or parsed.fragment:
            raise ValueError('Base URL harus HTTP(S) tanpa kredensial, query, atau fragment.')
        self.base_url = base_url.rstrip('/') + '/api/v1/'
        self.token = token
        self.session = session or requests.Session()
        self.session.trust_env = False
        self.session.headers.update({'Authorization': f'Bearer {token}', 'Accept': 'application/json'})
        self.sleep = sleep

    def report(self, status, body):
        # No raw HTML/debug trace/request headers in output, even for a misconfigured server.
        allowed = ('data', 'status', 'message', 'errors', 'device_id', 'type', 'is_simulated', 'server_time', 'online_seconds')
        def clean(value):
            if isinstance(value, dict):
                return {k: clean(v) for k, v in value.items() if not any(word in k.lower() for word in ('token', 'password', 'authorization', 'secret', 'trace'))}
            if isinstance(value, list):
                return [clean(v) for v in value]
            if isinstance(value, str):
                return value.replace(self.token, '[REDACTED]')
            return value
        response = {k: clean(v) for k, v in body.items() if k in allowed} if isinstance(body, dict) else {'message': 'Respons bukan objek JSON.'}
        print(f'HTTP {status}: {json.dumps(response, ensure_ascii=False)[:6000]}')

    def send(self, path, payload=None, method='POST'):
        for attempt in range(3):
            try:
                result = self.session.request(method, self.base_url + path, json=payload, timeout=(5, 10), allow_redirects=False)
            except requests.RequestException as exc:
                print(f'Gangguan jaringan: {type(exc).__name__}; percobaan {attempt + 1}/3.')
                if attempt == 2:
                    raise DeliveryError('Pengiriman gagal setelah 3 percobaan; ulangi payload tersimpan.') from None
            else:
                try:
                    body = result.json()
                except ValueError:
                    body = {'message': 'Respons bukan JSON; periksa document root, HTTPS, atau WAF hosting.'}
                self.report(result.status_code, body)
                if 200 <= result.status_code < 300:
                    if not isinstance(body, dict):
                        raise DeliveryError('Respons JSON tidak sesuai kontrak.')
                    return body
                retryable = result.status_code in (408, 429) or result.status_code >= 500
                if not retryable or attempt == 2:
                    raise DeliveryError(f'HTTP {result.status_code}: dihentikan. 422: perbaiki payload; 409: ID sudah digunakan dengan isi berbeda.')
            if attempt < 2:
                self.sleep(2 ** attempt)

    def verify_simulation(self, device_type):
        identity = self.send('device', method='GET')
        if identity.get('is_simulated') is not True or identity.get('type') != device_type:
            raise DeliveryError('Gunakan token perangkat SIMULASI dengan jenis yang sesuai; tidak ada heartbeat/data dikirim.')


def make_payload(device_type, kind='reading', untrained=False):
    payload = {'captured_at': datetime.now(timezone.utc).isoformat(), 'sensor_status': {}}
    if device_type == 'binsonic':
        payload.update(event_id=str(uuid.uuid4()), weight_g=None, loadcell_raw=random.randint(1000, 5000), weight_calibrated=False,
                       moisture_adc=random.randint(100, 600), wet_contact=False, metal_detected=False,
                       sensor_status={'audio': 'unavailable', 'vision': 'unavailable', 'loadcell': 'uncalibrated', 'moisture': 'ok', 'metal': 'ok'})
        if kind == 'event':
            payload.update(audio_label=None, audio_confidence=None, vision_label=None, vision_confidence=None,
                           final_label=None if untrained else 'botol plastik', destination=None if untrained else 'recycle')
    else:
        payload.update(reading_id=str(uuid.uuid4()), temperature_c=round(random.uniform(25, 85), 2), thermocouple_ok=True,
                       mq7_adc=random.randint(150, 700), mq7_voltage=None, mq7_voltage_basis=None, adc_resolution_bits=12,
                       sensor_status={'thermocouple': 'ok', 'mq7': 'uncalibrated'})
    return payload


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--url', default=os.environ.get('IOT_BASE_URL', 'https://gemastik.unima.space'))
    parser.add_argument('--type', choices=['binsonic', 'terra'], required=True)
    parser.add_argument('--kind', choices=['reading', 'event'], default='reading', help='Default reading; event hanya untuk kejadian pemilahan eksplisit.')
    parser.add_argument('--count', type=int, default=1)
    parser.add_argument('--interval', type=float, default=5)
    parser.add_argument('--untrained', action='store_true', help='Klasifikasi event null')
    parser.add_argument('--save-payload', type=Path, help='Simpan sebelum pengiriman untuk retry identik, tanpa token')
    parser.add_argument('--replay', type=Path, help='Kirim ulang JSON tanpa mengubah ID/waktu')
    args = parser.parse_args(argv)
    if (args.save_payload or args.replay) and args.count != 1:
        parser.error('--save-payload/--replay memerlukan --count 1')
    if args.save_payload and args.replay:
        parser.error('Pilih --save-payload atau --replay')
    if args.save_payload and args.save_payload.exists():
        parser.error('Berkas sudah ada; gunakan --replay atau nama baru')
    if args.count < 1 or args.interval < 0 or (args.type == 'terra' and args.kind == 'event'):
        parser.error('count minimal 1, interval tidak negatif; TERRA hanya reading')
    token = os.environ.get(args.type.upper() + '_TOKEN') or os.environ.get('DEVICE_TOKEN')
    if not token:
        parser.error('Isi environment ' + args.type.upper() + '_TOKEN (atau DEVICE_TOKEN); token tidak diterima sebagai argumen.')
    try:
        client = Client(args.url, token)
        client.verify_simulation(args.type)
        endpoint = args.type + ('/events' if args.kind == 'event' else '/readings')
        for i in range(args.count):
            payload = json.loads(args.replay.read_text()) if args.replay else make_payload(args.type, args.kind, args.untrained)
            if not isinstance(payload, dict):
                raise ValueError('Payload harus objek JSON.')
            if args.save_payload:
                with args.save_payload.open('x') as output:
                    json.dump(payload, output, indent=2)
                    output.write('\n')
            client.send('heartbeat', {})
            result = client.send(endpoint, payload)
            if result.get('data', {}).get('is_simulated') is not True:
                raise DeliveryError('Respons tidak berlabel SIMULASI; periksa konfigurasi server.')
            print(f'{i + 1}/{args.count}: SIMULASI {args.type} {args.kind}')
            if i + 1 < args.count:
                time.sleep(args.interval)
    except DeliveryError as exc:
        print(str(exc).replace(token, '[REDACTED]'))
        return 1
    except (ValueError, OSError):
        # Avoid reflecting arbitrary file paths/payloads or network exception text containing secrets.
        print('Simulator berhenti. Periksa HTTP status/pesan di atas, URL, environment, dan berkas payload.')
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
