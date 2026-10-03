"""Actual CLI + pySerial PTY + local HTTP server; no physical board or real data."""
import json
import os
from pathlib import Path
import pty
import secrets
import sqlite3
import subprocess
import sys
import tempfile
import threading
import time
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer


class CliIntegrationTest(unittest.TestCase):
    def test_durable_retry_null_status_debug_and_422_without_secret_logging(self):
        calls = []; stored = {}; lock = threading.Lock(); identified = threading.Event()
        token = secrets.token_urlsafe(32)

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *_):
                pass

            def reply(self, code, data):
                body = json.dumps(data).encode()
                self.send_response(code); self.send_header('Content-Type', 'application/json')
                self.send_header('Content-Length', str(len(body))); self.end_headers(); self.wfile.write(body)

            def do_GET(self):
                if self.headers.get('Authorization') != 'Bearer ' + token:
                    return self.reply(401, {})
                identified.set()
                self.reply(200, {'device_id': 77, 'type': 'binsonic', 'is_simulated': True, 'online_seconds': 60})

            def do_POST(self):
                payload = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
                with lock:
                    calls.append((self.path, payload))
                    if self.path.endswith('/heartbeat'):
                        return self.reply(200, {'status': 'ok'})
                    if payload['event_id'] == 'reject-422':
                        return self.reply(422, {'errors': {'loadcell_raw': ['invalid']}})
                    key = payload['event_id']
                    if key not in stored:
                        stored[key] = payload
                        # Simulate server commit followed by failure/lost acknowledgement.
                        return self.reply(503, {})
                    self.reply(200 if stored[key] == payload else 409, {'data': {'id': 1, 'event_id': key}})

        def wait_for(predicate, timeout=12):
            deadline = time.monotonic() + timeout
            while time.monotonic() < deadline:
                if predicate(): return
                time.sleep(0.05)
            self.fail('Timed out waiting for bridge state')

        httpd = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
        thread = threading.Thread(target=httpd.serve_forever, daemon=True); thread.start()
        master, slave = pty.openpty()
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / 'queue.sqlite'
            env = os.environ.copy(); env['BRIDGE_TEST_TOKEN'] = token
            proc = subprocess.Popen([sys.executable, '-m', 'bridge.serial_bridge', '--port', os.ttyname(slave),
                '--board-id', 'pty-board', '--api-url', f'http://127.0.0.1:{httpd.server_port}/api/v1',
                '--token-env', 'BRIDGE_TEST_TOKEN', '--queue', str(path), '--allow-simulated',
                '--fresh-seconds', '0.6', '--heartbeat-seconds', '2'], env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            def counts():
                if not path.exists(): return {}
                try:
                    with sqlite3.connect(path) as db:
                        return dict(db.execute('SELECT state,count(*) FROM outbox GROUP BY state').fetchall())
                except sqlite3.OperationalError:
                    return {}
            def send(message):
                os.write(master, json.dumps(dict(version=1, board_id='pty-board', **message)).encode() + b'\n')
            try:
                self.assertTrue(identified.wait(5)); time.sleep(0.3)
                send({'type': 'debug', 'message': token})
                time.sleep(0.4)
                self.assertEqual(calls, [])
                send({'type': 'status', 'status': 'ready', 'uptime_ms': 100})
                wait_for(lambda: any(p.endswith('/heartbeat') for p, _ in calls))
                payload = {'event_id': 'real-serial-id', 'captured_at': None, 'weight_calibrated': False,
                    'weight_g': None, 'loadcell_raw': 12345, 'moisture_adc': 340, 'wet_contact': None,
                    'metal_detected': False, 'sensor_status': {'loadcell': 'uncalibrated'}}
                send({'type': 'binsonic.reading', 'payload': payload})
                wait_for(lambda: counts() == {'sent': 1})
                self.assertEqual(len(stored), 1)
                record = stored['real-serial-id']; self.assertIsNone(record['captured_at'])
                self.assertIsNone(record['weight_g']); self.assertIsNone(record['wet_contact'])
                self.assertIs(record['metal_detected'], False); self.assertIn('gateway_received_at', record)
                deliveries = [p for url, p in calls if url.endswith('/readings')]
                self.assertEqual(len(deliveries), 2); self.assertEqual(deliveries[0], deliveries[1])
                bad = dict(payload, event_id='reject-422')
                send({'type': 'binsonic.reading', 'payload': bad})
                wait_for(lambda: counts() == {'dead': 1, 'sent': 1})
                send({'type': 'binsonic.reading', 'payload': bad})
                os.close(master); master = None
                time.sleep(0.4)
                heartbeats = len([1 for url, _ in calls if url.endswith('/heartbeat')])
                time.sleep(2.3)
                self.assertEqual(heartbeats, len([1 for url, _ in calls if url.endswith('/heartbeat')]))
                self.assertEqual(1, len([p for url, p in calls if url.endswith('/readings') and p['event_id'] == 'reject-422']))
                self.assertFalse(any(url.endswith('/events') for url, _ in calls))
                proc.terminate(); stdout, stderr = proc.communicate(timeout=8)
                self.assertEqual(proc.returncode, 0)
                self.assertIn('422', stderr); self.assertNotIn(token, stdout + stderr)
                self.assertNotIn(token.encode(), path.read_bytes())
            finally:
                if proc.poll() is None:
                    proc.terminate(); proc.communicate(timeout=8)
                if master is not None: os.close(master)
                os.close(slave)
                httpd.shutdown(); httpd.server_close(); thread.join(2)


if __name__ == '__main__':
    unittest.main()
