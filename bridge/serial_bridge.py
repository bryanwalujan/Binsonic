#!/usr/bin/env python3
"""Portenta JSON-lines -> authenticated BINSONIC API, with a durable SQLite outbox."""
from __future__ import annotations

import argparse
from datetime import datetime, timezone
import fcntl
import hashlib
import json
import logging
import math
import os
from pathlib import Path
import re
import signal
import sqlite3
import threading
import time
from urllib.parse import urlsplit

import requests
import serial

LOG = logging.getLogger('serial_bridge')
MAX_LINE = 16384
ID = re.compile(r'^[A-Za-z0-9_-]{1,100}$')
ISO = re.compile(r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$')
SENSOR_KEYS = {'audio', 'vision', 'loadcell', 'moisture', 'metal', 'thermocouple', 'mq7'}
SENSOR_STATES = {'ok', 'error', 'unavailable', 'uncalibrated'}
FIELDS = {'event_id', 'captured_at', 'weight_calibrated', 'weight_g', 'loadcell_raw',
          'moisture_adc', 'wet_contact', 'metal_detected', 'sensor_status'}
CLASSIFICATION = {'audio_label', 'audio_confidence', 'vision_label', 'vision_confidence', 'final_label', 'destination'}
ENDPOINTS = {'binsonic.reading': 'binsonic/readings', 'binsonic.event': 'binsonic/events'}


class InvalidMessage(ValueError):
    """Messages contain only safe, predefined diagnostics, never raw serial text."""


class FatalBridgeError(RuntimeError):
    pass


def utc_now():
    return datetime.now(timezone.utc).isoformat(timespec='microseconds').replace('+00:00', 'Z')


def canonical(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), allow_nan=False)


def timestamp(value):
    if not isinstance(value, str) or not ISO.fullmatch(value):
        raise InvalidMessage('captured_at harus ISO 8601 dengan zona waktu atau null')
    try:
        return datetime.fromisoformat(value.replace('Z', '+00:00')).astimezone(timezone.utc).isoformat(timespec='microseconds').replace('+00:00', 'Z')
    except ValueError:
        raise InvalidMessage('captured_at tidak valid') from None


def number(value, low, high=None, integer=False):
    if value is None:
        return
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        raise InvalidMessage('Tipe angka sensor tidak valid')
    if integer and not isinstance(value, int):
        raise InvalidMessage('ADC/loadcell harus bilangan bulat atau null')
    try:
        finite = math.isfinite(value)
    except OverflowError:
        finite = False
    if not finite or value < low or (high is not None and value > high):
        raise InvalidMessage('Nilai sensor di luar rentang')


def no_duplicate_keys(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise InvalidMessage('Key JSON duplikat')
        result[key] = value
    return result


def reject_constant(_):
    raise InvalidMessage('NaN/Infinity bukan data sensor yang valid')


def parse_line(line: bytes, board_id: str):
    """Return (kind, payload). No laptop timestamp is ever substituted for captured_at."""
    if len(line) > MAX_LINE:
        raise InvalidMessage('Baris serial terlalu panjang')
    try:
        message = json.loads(line.decode('utf-8'), object_pairs_hook=no_duplicate_keys, parse_constant=reject_constant)
    except (ValueError, UnicodeError, RecursionError):
        raise InvalidMessage('Baris bukan JSON UTF-8 valid') from None
    if not isinstance(message, dict) or type(message.get('version')) is not int or message['version'] != 1:
        raise InvalidMessage('Envelope/version tidak valid')
    if message.get('board_id') != board_id:
        raise InvalidMessage('Identitas board tidak cocok')
    kind = message.get('type')
    if kind == 'debug':
        if set(message) != {'version', 'board_id', 'type', 'message'} or not isinstance(message['message'], str) or len(message['message']) > 1000:
            raise InvalidMessage('Envelope debug tidak valid')
        return kind, None  # Intentionally not logged and not proof of liveness.
    if kind == 'status':
        if set(message) != {'version', 'board_id', 'type', 'status', 'uptime_ms'}:
            raise InvalidMessage('Envelope status tidak valid')
        if message['status'] not in ('ready', 'initializing', 'sensor_error') or type(message['uptime_ms']) is not int or not 0 <= message['uptime_ms'] <= 2**53-1:
            raise InvalidMessage('Status serial tidak valid')
        return kind, None
    if not isinstance(kind, str) or kind not in ENDPOINTS or set(message) != {'version', 'board_id', 'type', 'payload'}:
        raise InvalidMessage('Jenis pesan tidak didukung')
    p = message['payload']
    allowed = FIELDS | (CLASSIFICATION if kind == 'binsonic.event' else set())
    if not isinstance(p, dict) or set(p) - allowed:
        raise InvalidMessage('Field payload tidak dikenali/dilarang')
    if not {'event_id', 'captured_at', 'weight_calibrated', 'sensor_status'} <= set(p):
        raise InvalidMessage('Field wajib payload tidak lengkap')
    if not isinstance(p['event_id'], str) or not ID.fullmatch(p['event_id']):
        raise InvalidMessage('event_id tidak valid')
    if type(p['weight_calibrated']) is not bool:
        raise InvalidMessage('weight_calibrated harus boolean')
    # Normalize optional fields while retaining null and explicit false/zero distinctly.
    p = {key: p.get(key) for key in allowed}
    if p['captured_at'] is not None:
        p['captured_at'] = timestamp(p['captured_at'])
    for key in ('wet_contact', 'metal_detected'):
        if p[key] is not None and type(p[key]) is not bool:
            raise InvalidMessage('Kontak basah/deteksi logam harus boolean atau null')
    number(p['loadcell_raw'], -2147483648, 2147483647, integer=True)
    number(p['moisture_adc'], 0, 65535, integer=True)
    number(p['weight_g'], 0)
    if not p['weight_calibrated'] and p['weight_g'] is not None:
        raise InvalidMessage('Berat belum dikalibrasi harus null')
    status = p['sensor_status']
    if status is not None:
        if not isinstance(status, dict) or set(status) - SENSOR_KEYS:
            raise InvalidMessage('Key status sensor tidak valid')
        if any(v is not None and (not isinstance(v, str) or v not in SENSOR_STATES) for v in status.values()):
            raise InvalidMessage('Nilai status sensor tidak valid')
    if kind == 'binsonic.event':
        for key in ('audio_label', 'vision_label', 'final_label'):
            if p[key] is not None and (not isinstance(p[key], str) or not p[key].strip() or len(p[key]) > 100):
                raise InvalidMessage('Label klasifikasi tidak valid')
        for key in ('audio_confidence', 'vision_confidence'):
            number(p[key], 0, 1)
        if p['destination'] is not None and p['destination'] not in ('recycle', 'organic', 'thermal_candidate', 'residue_unknown'):
            raise InvalidMessage('Tujuan pemilahan tidak valid')
    return kind, p


class LineFramer:
    """Retain partial reads, reject oversize frames, and resynchronize at the next LF."""
    def __init__(self):
        self.buffer = bytearray()
        self.discarding = False

    def feed(self, chunk):
        lines = []
        for byte in chunk:
            if byte == 10:
                if not self.discarding and self.buffer:
                    lines.append(bytes(self.buffer).rstrip(b'\r'))
                self.buffer.clear()
                self.discarding = False
            elif not self.discarding:
                self.buffer.append(byte)
                if len(self.buffer) > MAX_LINE:
                    self.buffer.clear()
                    self.discarding = True
                    LOG.warning('Baris serial terlalu panjang; dibuang sampai newline')
        return lines


class Liveness:
    def __init__(self, stale_seconds, clock=time.monotonic):
        self.clock, self.stale_seconds = clock, stale_seconds
        self.lock = threading.Lock()
        self.connected = False
        self.last_valid = None

    def connect(self):
        with self.lock:
            self.connected, self.last_valid = True, None

    def disconnect(self):
        with self.lock:
            self.connected, self.last_valid = False, None

    def observe(self):
        with self.lock:
            if self.connected:
                self.last_valid = self.clock()

    def fresh(self):
        with self.lock:
            return self.connected and self.last_valid is not None and 0 <= self.clock() - self.last_valid <= self.stale_seconds


class Outbox:
    """Durable payloads and dedup receipts. A single process owns this DB via flock."""
    def __init__(self, path, api_url, board_id):
        path = Path(path)
        path.parent.mkdir(parents=True, exist_ok=True)
        self.lockfile = open(str(path) + '.lock', 'a')
        os.chmod(str(path) + '.lock', 0o600)
        try:
            fcntl.flock(self.lockfile, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError:
            self.lockfile.close()
            raise FatalBridgeError('Antrean sedang dipakai proses bridge lain') from None
        self.lock = threading.RLock()
        self.db = sqlite3.connect(path, timeout=10, check_same_thread=False)
        os.chmod(path, 0o600)
        self.db.row_factory = sqlite3.Row
        self.db.execute('PRAGMA journal_mode=WAL')
        self.db.execute('PRAGMA synchronous=FULL')
        self.db.executescript('''
            CREATE TABLE IF NOT EXISTS metadata (key TEXT PRIMARY KEY, value TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS outbox (
                id INTEGER PRIMARY KEY, endpoint TEXT NOT NULL, event_id TEXT NOT NULL,
                source_hash TEXT NOT NULL, payload TEXT NOT NULL,
                state TEXT NOT NULL DEFAULT 'pending', attempts INTEGER NOT NULL DEFAULT 0,
                next_attempt REAL NOT NULL DEFAULT 0, error_code TEXT,
                UNIQUE(endpoint,event_id)
            );
            CREATE INDEX IF NOT EXISTS outbox_due ON outbox(state,next_attempt);
        ''')
        try:
            self.bind('source', canonical({'api_url': api_url, 'board_id': board_id}))
        except Exception:
            self.close()
            raise

    def bind(self, key, value):
        with self.lock, self.db:
            old = self.db.execute('SELECT value FROM metadata WHERE key=?', (key,)).fetchone()
            if old and old['value'] != str(value):
                raise FatalBridgeError('Antrean terikat ke server/board/perangkat lain; gunakan antrean yang sesuai')
            self.db.execute('INSERT OR IGNORE INTO metadata VALUES (?,?)', (key, str(value)))

    def enqueue(self, endpoint, source, gateway_time=None):
        digest = hashlib.sha256(canonical(source).encode()).hexdigest()
        with self.lock, self.db:
            old = self.db.execute('SELECT * FROM outbox WHERE endpoint=? AND event_id=?', (endpoint, source['event_id'])).fetchone()
            if old:
                if old['source_hash'] != digest:
                    raise InvalidMessage('event_id sama memiliki payload berbeda di antrean lokal')
                return old['id']  # Never overwrite first gateway timestamp, even after restart.
            payload = dict(source, gateway_received_at=gateway_time or utc_now())
            row = self.db.execute('INSERT INTO outbox(endpoint,event_id,source_hash,payload) VALUES (?,?,?,?)', (endpoint, source['event_id'], digest, canonical(payload)))
            return row.lastrowid

    def due(self, now):
        with self.lock:
            return self.db.execute("SELECT * FROM outbox WHERE state='pending' AND next_attempt<=? ORDER BY id LIMIT 1", (now,)).fetchone()

    def finish(self, row, state, error=None, next_attempt=0):
        with self.lock, self.db:
            self.db.execute('UPDATE outbox SET state=?, attempts=attempts+1, error_code=?, next_attempt=? WHERE id=?', (state, error, next_attempt, row['id']))

    def counts(self):
        with self.lock:
            return dict(self.db.execute('SELECT state,COUNT(*) FROM outbox GROUP BY state').fetchall())

    def close(self):
        self.db.close()
        self.lockfile.close()


def retry_delay(attempt, base=2.0, maximum=300.0):
    return min(maximum, base * 2 ** min(attempt, 16))


class ApiClient:
    def __init__(self, base_url, token):
        self.base_url = base_url.rstrip('/')
        self.session = requests.Session()
        self.session.trust_env = False  # Do not override Bearer via .netrc or send via ambient proxies.
        self.session.headers.update({'Authorization': 'Bearer ' + token, 'Accept': 'application/json'})

    def request(self, method, endpoint, payload=None):
        return self.session.request(method, self.base_url + '/' + endpoint, json=payload,
                                    timeout=(3, 5), allow_redirects=False)

    def close(self):
        self.session.close()


def deliver_one(outbox, client, now=None):
    """Return whether a request was attempted. Permanent failures go to retained dead letters."""
    use_wall_clock = now is None
    now = time.time() if use_wall_clock else now
    retry_at = lambda delay: (time.time() if use_wall_clock else now) + delay
    row = outbox.due(now)
    if row is None:
        return False
    delay = retry_delay(row['attempts'])
    try:
        response = client.request('POST', row['endpoint'], json.loads(row['payload']))
    except requests.RequestException:
        outbox.finish(row, 'pending', 'network', retry_at(delay))
        LOG.warning('Jaringan gagal; antrean #%d akan dicoba ulang dengan jeda', row['id'])
        return True
    code = response.status_code
    if code in (200, 201):
        try:
            data = response.json()['data']
            if data['event_id'] != row['event_id'] or type(data['id']) is not int:
                raise ValueError()
        except (ValueError, KeyError, TypeError):
            outbox.finish(row, 'pending', 'invalid_response', retry_at(delay))
            LOG.warning('Respons API tidak valid; antrean #%d tetap disimpan', row['id'])
        else:
            outbox.finish(row, 'sent')
            LOG.info('Antrean #%d diterima server (HTTP %d)', row['id'], code)
    elif code in (401, 403):
        outbox.finish(row, 'pending', 'auth', retry_at(delay))
        raise FatalBridgeError('Akses API ditolak; periksa token/jenis perangkat, lalu mulai ulang bridge')
    elif code in (408, 425, 429) or code >= 500:
        try:
            delay = max(delay, min(86400, max(0, float(response.headers.get('Retry-After', '0')))))
        except ValueError:
            pass
        outbox.finish(row, 'pending', 'http_' + str(code), retry_at(delay))
        LOG.warning('HTTP %d; antrean #%d dijadwalkan ulang', code, row['id'])
    else:
        outbox.finish(row, 'dead', 'http_' + str(code))
        LOG.error('HTTP %d: antrean #%d dikarantina; periksa kontrak/payload. Tidak diulang otomatis.', code, row['id'])
    return True


class Heartbeat:
    def __init__(self, interval):
        self.interval = interval
        self.next_at = 0
        self.failures = 0

    def tick(self, live, client, now=None):
        use_monotonic = now is None
        now = time.monotonic() if use_monotonic else now
        if not live.fresh() or now < self.next_at:
            return False
        try:
            response = client.request('POST', 'heartbeat', {})
            code = response.status_code
        except requests.RequestException:
            code = 503
        if code in (401, 403):
            raise FatalBridgeError('Heartbeat ditolak; periksa token lalu mulai ulang bridge')
        if code not in (200, 408, 425, 429) and code < 500:
            raise FatalBridgeError('Endpoint heartbeat menolak kontrak; periksa URL/API')
        finished = time.monotonic() if use_monotonic else now
        self.next_at = finished + (self.interval if code == 200 else max(self.interval, retry_delay(self.failures)))
        self.failures = 0 if code == 200 else self.failures + 1
        if code != 200:
            LOG.warning('Heartbeat gagal; retry hanya jika serial masih segar')
        return True


def read_serial(port, baud, board_id, outbox, live, stop, opener=serial.Serial, failure=None):
    reconnect = 0
    while not stop.is_set():
        device = None
        try:
            device = opener(port, baudrate=baud, timeout=0.2, exclusive=True)
            live.connect()
            framer = LineFramer()
            reconnect = 0
            LOG.info('USB tersambung; menunggu data/status valid')
            while not stop.is_set():
                chunk = device.read(4096)
                for line in framer.feed(chunk):
                    try:
                        kind, payload = parse_line(line, board_id)
                        if kind in ENDPOINTS:
                            outbox.enqueue(ENDPOINTS[kind], payload)
                            live.observe()
                        elif kind == 'status':
                            live.observe()
                        # Debug and malformed lines never refresh liveness, never become events.
                    except InvalidMessage as exc:
                        LOG.warning('Pesan serial ditolak: %s', exc)
        except (serial.SerialException, OSError):
            LOG.warning('USB tidak tersedia/terputus; heartbeat dihentikan sampai data serial segar')
        except Exception:
            LOG.error('Penyimpanan/pembacaan gagal; bridge dihentikan agar kehilangan data terlihat')
            if failure is not None:
                failure.set()
            stop.set()
        finally:
            live.disconnect()
            if device is not None:
                try:
                    device.close()
                except OSError:
                    pass
        if not stop.is_set():
            stop.wait(retry_delay(reconnect, base=1, maximum=30))
            reconnect += 1


def verify_identity(client, outbox, allow_simulated, heartbeat_interval):
    response = client.request('GET', 'device')
    if response.status_code in (408, 425, 429) or response.status_code >= 500:
        raise requests.ConnectionError()
    if response.status_code != 200:
        raise FatalBridgeError('Identitas API ditolak; periksa URL/token/versi API')
    try:
        identity = response.json()
        if identity['type'] != 'binsonic' or type(identity['device_id']) is not int or type(identity['is_simulated']) is not bool:
            raise ValueError()
        if not allow_simulated and identity['is_simulated']:
            raise FatalBridgeError('Token SIMULASI memerlukan opsi --allow-simulated')
        if not 0 < heartbeat_interval < identity['online_seconds']:
            raise FatalBridgeError('Interval heartbeat harus lebih kecil dari ambang online server')
    except (ValueError, TypeError, KeyError):
        raise FatalBridgeError('Respons identitas perangkat tidak valid') from None
    outbox.bind('device_id', identity['device_id'])


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--port', default=os.getenv('PORTENTA_PORT'))
    parser.add_argument('--baud', type=int, default=115200)
    parser.add_argument('--api-url', default=os.getenv('BINSONIC_API_URL', 'http://127.0.0.1:8000/api/v1'))
    parser.add_argument('--token-env', default='DEVICE_TOKEN', help='Nama environment variable, bukan nilai token')
    parser.add_argument('--board-id', required=True, help='Harus sama dengan board_id JSON serial')
    parser.add_argument('--queue', type=Path, default=Path('storage/app/private/portenta-outbox.sqlite'))
    parser.add_argument('--fresh-seconds', type=float, default=10)
    parser.add_argument('--heartbeat-seconds', type=float, default=15)
    parser.add_argument('--allow-simulated', action='store_true')
    args = parser.parse_args()
    if not args.port or args.baud <= 0 or not ID.fullmatch(args.board_id):
        parser.error('Port, baud, atau board-id tidak valid')
    if not math.isfinite(args.fresh_seconds) or not math.isfinite(args.heartbeat_seconds) or args.fresh_seconds <= 0 or args.heartbeat_seconds < 2:
        parser.error('Ambang segar harus positif; interval heartbeat minimal 2 detik')
    url = urlsplit(args.api_url)
    if url.scheme not in ('http', 'https') or not url.hostname or url.username or url.password or url.query or url.fragment:
        parser.error('URL API harus HTTP(S) tanpa kredensial/query/fragment')
    token = os.environ.get(args.token_env)
    if not token or any(ch.isspace() for ch in token):
        parser.error('Environment variable token belum diisi atau format tidak valid')
    os.umask(0o077)
    logging.basicConfig(level=logging.INFO, format='%(asctime)s %(levelname)s %(message)s')
    stop = threading.Event()
    reader_failure = threading.Event()
    for signum in (signal.SIGINT, signal.SIGTERM):
        signal.signal(signum, lambda *_: stop.set())
    outbox = client = reader = None
    try:
        outbox = Outbox(args.queue, args.api_url.rstrip('/'), args.board_id)
        live = Liveness(args.fresh_seconds)
        client = ApiClient(args.api_url, token)
        reader = threading.Thread(target=read_serial, args=(args.port, args.baud, args.board_id, outbox, live, stop), kwargs={'failure': reader_failure}, daemon=True)
        reader.start()
        failures = 0
        while not stop.is_set():
            try:
                verify_identity(client, outbox, args.allow_simulated, args.heartbeat_seconds)
                break
            except requests.RequestException:
                LOG.warning('Identitas server belum tersedia; serial tetap disimpan lokal')
                stop.wait(retry_delay(failures))
                failures += 1
        heartbeat = Heartbeat(args.heartbeat_seconds)
        next_delivery = 0
        while not stop.is_set():
            # No queued heartbeat. Freshness is checked immediately before each HTTP request.
            heartbeat.tick(live, client)
            if time.monotonic() >= next_delivery:
                deliver_one(outbox, client)
                next_delivery = time.monotonic() + 1  # <=60 data/min plus heartbeat: below API limit.
            stop.wait(0.1)
    except FatalBridgeError as exc:
        LOG.error('%s', exc)
        return 1
    except Exception:
        LOG.error('Bridge gagal; detail mentah tidak dicetak untuk melindungi kredensial')
        return 1
    finally:
        stop.set()
        if reader is not None:
            reader.join(timeout=5)
        if client is not None:
            client.close()
        if outbox is not None:
            LOG.info('Antrean tersimpan: %s', outbox.counts())
            outbox.close()
    return 1 if reader_failure.is_set() else 0


if __name__ == '__main__':
    raise SystemExit(main())
