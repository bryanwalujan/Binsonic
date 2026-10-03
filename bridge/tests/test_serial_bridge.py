import io
import json
import logging
import os
from pathlib import Path
import pty
import tempfile
import threading
import time
import unittest
from unittest.mock import Mock, patch

import requests

from bridge.serial_bridge import (ApiClient, ENDPOINTS, FatalBridgeError, Heartbeat,
    InvalidMessage, LineFramer, Liveness, Outbox, deliver_one, parse_line, read_serial,
    retry_delay, verify_identity)


def frame(kind='binsonic.reading', **extra):
    payload = {'event_id': 'boot-7-reading-1', 'captured_at': None, 'weight_calibrated': False,
               'weight_g': None, 'loadcell_raw': 0, 'moisture_adc': None, 'wet_contact': False,
               'metal_detected': None, 'sensor_status': {'loadcell': 'uncalibrated', 'metal': None}}
    payload.update(extra)
    return json.dumps({'version': 1, 'type': kind, 'board_id': 'portenta-01', 'payload': payload}).encode()


def status(kind='status'):
    payload = {'status': 'ready', 'uptime_ms': 1234} if kind == 'status' else {'message': 'DEVICE_TOKEN=do-not-log-this'}
    return json.dumps(dict(version=1, type=kind, board_id='portenta-01', **payload)).encode()


def response(code, data=None, headers=None):
    r = Mock(status_code=code, headers=headers or {})
    r.json.return_value = data or {'data': {'id': 1, 'event_id': 'boot-7-reading-1'}}
    return r


class ParsingTests(unittest.TestCase):
    def test_null_false_and_zero_remain_distinct(self):
        kind, payload = parse_line(frame(), 'portenta-01')
        self.assertEqual(kind, 'binsonic.reading')
        self.assertIsNone(payload['captured_at'])
        self.assertIsNone(payload['weight_g'])
        self.assertIsNone(payload['metal_detected'])
        self.assertIs(payload['wet_contact'], False)
        self.assertEqual(payload['loadcell_raw'], 0)
        self.assertNotIn('gateway_received_at', payload)
        self.assertNotIn('destination', payload)

    def test_device_timestamp_normalized_not_replaced(self):
        _, payload = parse_line(frame(captured_at='2026-10-02T13:00:00.123456+08:00'), 'portenta-01')
        self.assertEqual(payload['captured_at'], '2026-10-02T05:00:00.123456Z')

    def test_event_classification_defaults_to_null(self):
        kind, payload = parse_line(frame('binsonic.event'), 'portenta-01')
        self.assertEqual(ENDPOINTS[kind], 'binsonic/events')
        for key in ('audio_label', 'vision_label', 'destination', 'audio_confidence', 'final_label'):
            self.assertIsNone(payload[key])

    def test_invalid_fields_and_ranges(self):
        for changes in [{'weight_g': 1}, {'loadcell_raw': True}, {'wet_contact': 0}, {'moisture_adc': -1},
                        {'sensor_status': {'metal': 'iron'}}, {'sensor_status': []}, {'captured_at': '2026-10-02'},
                        {'captured_at': '2026-99-02T00:00:00Z'}, {'device_id': 3}, {'is_simulated': True},
                        {'received_at': '2026-10-02T00:00:00Z'}, {'gateway_received_at': '2026-10-02T00:00:00Z'},
                        {'destination': None}, {'event_id': 'a/b'}, {'weight_calibrated': None}]:
            with self.subTest(changes=changes), self.assertRaises(InvalidMessage):
                parse_line(frame(**changes), 'portenta-01')

    def test_malformed_nan_duplicate_and_wrong_board(self):
        for line in [b'boot debug text', b'[]', b'\xff', b'{"version":1,"version":1}', frame(loadcell_raw=float('nan')), b'x' * 16385]:
            with self.subTest(), self.assertRaises(InvalidMessage):
                parse_line(line, 'portenta-01')
        with self.assertRaises(InvalidMessage):
            parse_line(frame(), 'another-board')

    def test_debug_and_status_are_not_data(self):
        self.assertEqual(parse_line(status(), 'portenta-01'), ('status', None))
        self.assertEqual(parse_line(status('debug'), 'portenta-01'), ('debug', None))

    def test_partial_crlf_and_oversize_resynchronization(self):
        f = LineFramer()
        self.assertEqual(f.feed(b'{"a"'), [])
        self.assertEqual(f.feed(b':1}\r\n'), [b'{"a":1}'])
        self.assertEqual(f.feed(b'x' * 18000), [])
        self.assertEqual(f.feed(b'garbage\n{}\n'), [b'{}'])


class OutboxTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.path = Path(self.temp.name) / 'queue.sqlite'
        self.queue = Outbox(self.path, 'http://localhost/api/v1', 'portenta-01')
        self.kind, self.payload = parse_line(frame(), 'portenta-01')
        self.queue.enqueue(ENDPOINTS[self.kind], self.payload, '2026-10-02T05:00:00.000000Z')
        self.client = Mock()

    def tearDown(self):
        self.queue.close()
        self.temp.cleanup()

    def test_reopen_duplicate_keeps_first_gateway_timestamp(self):
        first = self.queue.due(0)['payload']
        self.queue.close()
        self.queue = Outbox(self.path, 'http://localhost/api/v1', 'portenta-01')
        self.queue.enqueue(ENDPOINTS[self.kind], self.payload, '2026-10-03T05:00:00.000000Z')
        self.assertEqual(self.queue.due(0)['payload'], first)
        self.assertEqual(self.queue.counts(), {'pending': 1})
        self.assertEqual(os.stat(self.path).st_mode & 0o777, 0o600)

    def test_local_id_collision_is_not_silently_overwritten(self):
        with self.assertRaises(InvalidMessage):
            self.queue.enqueue(ENDPOINTS[self.kind], dict(self.payload, loadcell_raw=10))
        self.assertEqual(self.queue.counts(), {'pending': 1})

    def test_network_retry_uses_identical_event_and_timestamp(self):
        self.client.request.side_effect = [requests.Timeout(), response(201)]
        self.assertTrue(deliver_one(self.queue, self.client, now=100))
        self.assertFalse(deliver_one(self.queue, self.client, now=101))
        self.assertTrue(deliver_one(self.queue, self.client, now=102))
        calls = self.client.request.call_args_list
        self.assertEqual(calls[0], calls[1])
        self.assertEqual(self.queue.counts(), {'sent': 1})
        self.queue.enqueue(ENDPOINTS[self.kind], self.payload)
        self.assertFalse(deliver_one(self.queue, self.client, now=999))

    def test_retry_wait_starts_after_slow_http_failure(self):
        self.client.request.side_effect = requests.Timeout()
        with patch('bridge.serial_bridge.time.time', side_effect=[100, 110]):
            deliver_one(self.queue, self.client)
        self.assertIsNone(self.queue.due(111))
        self.assertIsNotNone(self.queue.due(112))

    def test_retry_429_honors_seconds_and_does_not_delete_payload(self):
        self.client.request.return_value = response(429, headers={'Retry-After': '60'})
        deliver_one(self.queue, self.client, now=10)
        self.assertIsNone(self.queue.due(69))
        self.assertIsNotNone(self.queue.due(70))
        self.assertEqual([retry_delay(i) for i in range(4)], [2, 4, 8, 16])
        self.assertEqual(retry_delay(99), 300)

    def test_422_and_409_are_reported_once_and_quarantined(self):
        self.client.request.return_value = response(422)
        with self.assertLogs('serial_bridge', 'ERROR') as logs:
            deliver_one(self.queue, self.client, now=0)
        self.assertIn('422', logs.output[0])
        self.assertEqual(self.queue.counts(), {'dead': 1})
        self.assertFalse(deliver_one(self.queue, self.client, now=9999))
        self.assertEqual(self.client.request.call_count, 1)
        self.queue.enqueue(ENDPOINTS[self.kind], dict(self.payload, event_id='second'))
        self.client.request.return_value = response(409)
        deliver_one(self.queue, self.client, now=0)
        self.assertEqual(self.queue.counts(), {'dead': 2})

    def test_auth_error_stops_without_losing_queue(self):
        self.client.request.return_value = response(401)
        with self.assertRaises(FatalBridgeError):
            deliver_one(self.queue, self.client, now=0)
        self.assertEqual(self.queue.counts(), {'pending': 1})

    def test_malformed_success_response_is_retried_safely(self):
        self.client.request.return_value = response(200, {'data': {'event_id': 'wrong', 'id': 1}})
        deliver_one(self.queue, self.client, now=0)
        self.assertEqual(self.queue.counts(), {'pending': 1})

    def test_http_redirect_is_not_treated_as_delivery(self):
        self.client.request.return_value = response(302)
        deliver_one(self.queue, self.client, now=0)
        self.assertEqual(self.queue.counts(), {'dead': 1})

    def test_queue_cannot_be_opened_twice_or_switched_to_other_device(self):
        with self.assertRaises(FatalBridgeError):
            Outbox(self.path, 'http://localhost/api/v1', 'portenta-01')
        self.queue.bind('device_id', 1)
        with self.assertRaises(FatalBridgeError):
            self.queue.bind('device_id', 2)

    def test_identity_get_does_not_heartbeat_and_rejects_simulation_by_default(self):
        self.client.request.return_value = response(200, {'type': 'binsonic', 'device_id': 1, 'is_simulated': True, 'online_seconds': 60})
        with self.assertRaises(FatalBridgeError):
            verify_identity(self.client, self.queue, False, 15)
        verify_identity(self.client, self.queue, True, 15)
        self.assertEqual(self.client.request.call_args.args, ('GET', 'device'))


class HeartbeatTests(unittest.TestCase):
    def test_stale_disconnect_and_reconnect_require_new_valid_serial(self):
        clock = [0]
        live = Liveness(10, lambda: clock[0])
        client = Mock()
        client.request.return_value = response(200)
        hb = Heartbeat(5)
        live.connect()
        self.assertFalse(hb.tick(live, client, now=0))
        live.observe()
        self.assertTrue(hb.tick(live, client, now=0))
        clock[0] = 11
        self.assertFalse(hb.tick(live, client, now=11))
        live.observe()
        live.disconnect()
        self.assertFalse(hb.tick(live, client, now=12))
        live.connect()
        self.assertFalse(hb.tick(live, client, now=13))
        live.observe()
        self.assertTrue(hb.tick(live, client, now=14))

    def test_failed_heartbeat_not_replayed_when_stale(self):
        clock = [0]
        live = Liveness(1, lambda: clock[0]); live.connect(); live.observe()
        client = Mock(); client.request.side_effect = requests.Timeout()
        hb = Heartbeat(2)
        hb.tick(live, client, now=0)
        clock[0] = 3
        self.assertFalse(hb.tick(live, client, now=3))
        self.assertEqual(client.request.call_count, 1)

    def test_client_disables_redirects_and_has_bounded_timeouts(self):
        client = ApiClient('http://localhost/api/v1', 'test-secret-not-logged')
        self.assertFalse(client.session.trust_env)
        client.session.request = Mock(return_value=response(200))
        client.request('POST', 'heartbeat', {})
        self.assertFalse(client.session.request.call_args.kwargs['allow_redirects'])
        self.assertEqual(client.session.request.call_args.kwargs['timeout'], (3, 5))
        client.close()


class UsbReconnectTests(unittest.TestCase):
    def test_real_pyserial_pty_partial_lines_disconnect_reconnect_debug_and_null(self):
        def wait_for(predicate, seconds=5):
            deadline = time.monotonic() + seconds
            while not predicate() and time.monotonic() < deadline:
                time.sleep(0.02)
            self.assertTrue(predicate())
        with tempfile.TemporaryDirectory() as tmp:
            port = Path(tmp) / 'portenta'
            master, slave = pty.openpty(); port.symlink_to(os.ttyname(slave))
            queue = Outbox(Path(tmp) / 'queue.sqlite', 'http://localhost/api/v1', 'portenta-01')
            live = Liveness(0.5); stop = threading.Event()
            worker = threading.Thread(target=read_serial, args=(str(port), 115200, 'portenta-01', queue, live, stop))
            output = io.StringIO(); handler = logging.StreamHandler(output)
            logger = logging.getLogger('serial_bridge'); logger.addHandler(handler)
            try:
                worker.start(); wait_for(lambda: live.connected)
                os.write(master, status('debug') + b'\n'); time.sleep(0.25)
                self.assertFalse(live.fresh())
                data = frame(); os.write(master, data[:20]); time.sleep(0.25)
                self.assertEqual(queue.counts(), {})
                os.write(master, data[20:] + b'\r\n'); wait_for(lambda: queue.counts() == {'pending': 1})
                self.assertTrue(live.fresh())
                self.assertIsNone(json.loads(queue.due(time.time())['payload'])['captured_at'])
                os.close(master); master = None; os.close(slave); slave = None
                wait_for(lambda: not live.connected)
                self.assertFalse(live.fresh())
                master, slave = pty.openpty(); port.unlink(); port.symlink_to(os.ttyname(slave))
                wait_for(lambda: live.connected)
                self.assertFalse(live.fresh())
                os.write(master, status() + b'\n'); wait_for(live.fresh)
                os.write(master, frame() + b'\n'); time.sleep(0.25)
                self.assertEqual(queue.counts(), {'pending': 1})
                time.sleep(0.7); self.assertFalse(live.fresh())
                self.assertNotIn('do-not-log-this', output.getvalue())
            finally:
                stop.set(); worker.join(3); queue.close(); logger.removeHandler(handler)
                if master is not None: os.close(master)
                if slave is not None: os.close(slave)
                self.assertFalse(worker.is_alive())


if __name__ == '__main__':
    unittest.main()
