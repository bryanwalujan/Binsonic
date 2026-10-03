import contextlib
import io
import unittest
from unittest.mock import Mock

import requests
from simulate import Client, DeliveryError, make_payload


def response(status, body):
    result = Mock(status_code=status)
    result.json.return_value = body
    return result


class SimulatorTests(unittest.TestCase):
    def client(self, outcomes):
        session = Mock(headers={})
        session.request.side_effect = outcomes
        sleep = Mock()
        return Client('https://example.test', 'test-secret-token', session, sleep), session, sleep

    def test_retry_preserves_exact_id_payload_and_time(self):
        p = make_payload('binsonic', 'event', True)
        client, session, sleep = self.client([requests.Timeout(), response(503, {'message': 'busy'}), response(201, {'data': {'is_simulated': True}})])
        with contextlib.redirect_stdout(io.StringIO()):
            client.send('binsonic/events', p)
        self.assertEqual(session.request.call_count, 3)
        for call in session.request.call_args_list:
            self.assertIs(call.kwargs['json'], p)
            self.assertFalse(call.kwargs['allow_redirects'])
            self.assertEqual(call.kwargs['timeout'], (5, 10))
        self.assertEqual([call.args[0] for call in sleep.call_args_list], [1, 2])

    def test_422_and_409_are_reported_without_retry(self):
        for status in (422, 409, 401, 403, 302):
            client, session, sleep = self.client([response(status, {'message': 'Invalid', 'errors': {'weight_g': ['Berat harus null']}})])
            output = io.StringIO()
            with contextlib.redirect_stdout(output), self.assertRaises(DeliveryError):
                client.send('binsonic/events', {})
            self.assertIn(f'HTTP {status}', output.getvalue())
            self.assertEqual(session.request.call_count, 1)
            sleep.assert_not_called()

    def test_retry_is_bounded(self):
        client, session, sleep = self.client([response(429, {})] * 3)
        with contextlib.redirect_stdout(io.StringIO()), self.assertRaises(DeliveryError):
            client.send('heartbeat', {})
        self.assertEqual(session.request.call_count, 3)
        self.assertEqual(sleep.call_count, 2)

    def test_identity_check_does_not_post_to_real_or_wrong_device(self):
        for data in ({'type': 'binsonic', 'is_simulated': False}, {'type': 'terra', 'is_simulated': True}):
            client, session, _ = self.client([response(200, data)])
            with contextlib.redirect_stdout(io.StringIO()), self.assertRaises(DeliveryError):
                client.verify_simulation('binsonic')
            self.assertEqual(session.request.call_count, 1)
            self.assertEqual(session.request.call_args.args[0], 'GET')
            self.assertTrue(session.request.call_args.args[1].endswith('/device'))

    def test_null_and_sensor_readings_are_not_events(self):
        p = make_payload('binsonic')
        self.assertNotIn('destination', p)
        self.assertNotIn('audio_label', p)
        self.assertIsNone(p['weight_g'])
        self.assertIs(p['weight_calibrated'], False)
        self.assertIs(p['wet_contact'], False)
        event = make_payload('binsonic', 'event', True)
        for key in ('destination', 'final_label', 'audio_label', 'vision_label'):
            self.assertIsNone(event[key])
        terra = make_payload('terra')
        self.assertIn('reading_id', terra)
        self.assertIsNone(terra['mq7_voltage'])
        self.assertIsNone(terra['mq7_voltage_basis'])

    def test_logs_never_contain_token_or_debug_trace(self):
        client, _, _ = self.client([response(422, {'message': 'test-secret-token', 'trace': ['secret stack'], 'errors': {'token': ['test-secret-token']}})])
        output = io.StringIO()
        with contextlib.redirect_stdout(output), self.assertRaises(DeliveryError):
            client.send('heartbeat', {})
        self.assertNotIn('test-secret-token', output.getvalue())
        self.assertNotIn('secret stack', output.getvalue())
        self.assertIn('[REDACTED]', output.getvalue())


if __name__ == '__main__':
    unittest.main()
