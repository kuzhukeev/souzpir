"""Offline-only broker tests. No recorded fixtures and no real credentials."""
import copy
from contextlib import closing
import json
import io
import urllib.error
import os
from pathlib import Path
import sqlite3
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from capture_broker import BASE, CANONICAL_LEDGER, BrokerError, CaptureBroker, _HttpTransport, _NoRedirect, _rates, _classify_http_error, MAX_ERROR_RESPONSE, BILLING_DELAYS, BILLING_START_WINDOW_SECONDS

MODEL = 'anthropic/claude-sonnet-5.5'
FAKE_KEY = 'sk-or-v1-' + 'offline_fake_not_a_real_secret_00000'
BODY = {'model': MODEL, 'messages': [{'role': 'user', 'content': 'Offline test'}], 'max_tokens': 100, 'usage': {'include': True}, 'temperature': 0.7}
ENDPOINT = {'tag': 'anthropic', 'provider_name': 'Anthropic', 'context_length': 1000, 'max_completion_tokens': 1000,
            'supported_parameters': ['max_tokens', 'tools'], 'pricing': {'prompt': '0.000001', 'completion': '0.000002'}}
RECEIPT = {'id': 'gen-offline1', 'model': MODEL, 'provider': 'Anthropic', 'choices': [{'message': {'role': 'assistant', 'content': 'offline'}}],
           'usage': {'prompt_tokens': 10, 'completion_tokens': 5, 'total_tokens': 15, 'cost': 0.0001}}
BILLING = {'id': 'gen-offline1', 'is_byok': False, 'total_cost': 0.0001, 'upstream_inference_cost': 0.00008}


class FakeTransport:
    def __init__(self):
        self.endpoint = copy.deepcopy(ENDPOINT)
        self.receipt = copy.deepcopy(RECEIPT)
        self.billing = copy.deepcopy(BILLING)
        self.calls = []
        self.error = None
        self.billing_failures = 0
        self.before_post = None

    def request(self, method, url, headers, body=None):
        self.calls.append((method, url, copy.deepcopy(body)))
        if url == BASE + '/key':
            return {'data': {'limit': None, 'usage': 0}}
        if url.endswith('/endpoints'):
            return {'data': {'id': MODEL, 'endpoints': [copy.deepcopy(self.endpoint)]}}
        if '/generation?id=' in url:
            if self.billing_failures:
                self.billing_failures -= 1
                raise TimeoutError('sensitive upstream exception must never escape')
            return {'data': copy.deepcopy(self.billing)}
        if method == 'POST':
            if self.before_post:
                self.before_post()
            if self.error:
                raise self.error
            return copy.deepcopy(self.receipt)
        raise AssertionError('unexpected fake HTTP')

    @property
    def posts(self):
        return [c for c in self.calls if c[0] == 'POST']


class BrokerTests(unittest.TestCase):
    def setUp(self):
        root = Path(__file__).resolve().parents[3] / '.local/broker-tests'
        root.mkdir(parents=True, exist_ok=True)
        self.temp = tempfile.TemporaryDirectory(dir=root)
        self.root = Path(self.temp.name)
        self.key = self.root / 'fake.key'
        self.key.write_text(FAKE_KEY, encoding='utf-8')
        self.catalog = self.root / 'catalog.json'
        self.catalog.write_text(json.dumps({'data': [{'id': MODEL}]}), encoding='utf-8')
        self.ledger = self.root / 'budget.sqlite'
        self.transport = FakeTransport()
        self.brokers = []
        self.sleep = patch('capture_broker.time.sleep')
        self.sleep.start()

    def tearDown(self):
        for broker in reversed(self.brokers):
            broker.close()
        self.sleep.stop()
        self.temp.cleanup()

    def broker(self, limit='50', preflight=True, transport=None):
        broker = CaptureBroker(self.key, self.catalog, self.ledger, limit, transport=transport or self.transport)
        self.brokers.append(broker)
        if preflight:
            broker.preflight([MODEL])
        return broker

    def assertCode(self, code, action):
        with self.assertRaises(BrokerError) as error:
            action()
        self.assertEqual(code, error.exception.code)
        self.assertEqual(code, str(error.exception))

    def test_error_classifier_returns_only_fixed_hints_from_sensitive_payloads(self):
        sensitive = FAKE_KEY + ' private-person@example.org +7 999 123 45 67'
        examples = [('All tool names must be unique: ' + sensitive, 'duplicate_tool_names'),
                    ('Invalid tool input schema: ' + sensitive, 'invalid_tool_schema'),
                    ('Unsupported parameter: ' + sensitive, 'unsupported_parameter'),
                    ('Maximum context length exceeded: ' + sensitive, 'context_length_exceeded')]
        for message, category in examples:
            with self.subTest(category=category):
                raw = json.dumps({'error': {'message': message}}).encode()
                self.assertEqual(category, _classify_http_error(raw))
                nested = json.dumps({'error': {'message': 'Provider error', 'metadata': {'raw': raw.decode(), 'headers': sensitive}}}).encode()
                self.assertEqual(category, _classify_http_error(nested))
        self.assertEqual('unknown', BrokerError('http_rejected', error_category=sensitive).error_category)
        self.assertEqual('invalid_tool_schema', _classify_http_error(b'{"error":{"message":"Invalid tool schema contains duplicate properties"}}'))

    def test_malformed_oversized_and_unknown_error_bodies_are_unknown(self):
        for raw in (b'', b'{', b'[]', b'null', b'"duplicate tool names"', b'\xff',
                    b'{"error":{"message":null}}', b'{"error":{"metadata":{"raw":"{broken"}}}',
                    b' ' * (MAX_ERROR_RESPONSE + 1)):
            with self.subTest(size=len(raw)):
                self.assertEqual('unknown', _classify_http_error(raw))
        deeply_nested = b'[' * 2000 + b']' * 2000
        self.assertEqual('unknown', _classify_http_error(deeply_nested))

    def test_transport_error_body_read_is_bounded_closed_and_never_exposed(self):
        class ObservedBody(io.BytesIO):
            def __init__(self, value):
                super().__init__(value)
                self.read_sizes = []
            def read(self, amount=-1):
                self.read_sizes.append(amount)
                return super().read(amount)
        raw = json.dumps({'error': {'message': 'Duplicate tool names: ' + FAKE_KEY,
                                   'metadata': {'headers': 'private@example.org'}}}).encode()
        stream = ObservedBody(raw)
        error = urllib.error.HTTPError(BASE + '/chat/completions', 400, FAKE_KEY, {'x-secret': FAKE_KEY}, stream)
        transport = _HttpTransport()
        with patch.object(transport.opener, 'open', side_effect=error) as open_request:
            with self.assertRaises(BrokerError) as caught:
                transport.request('POST', BASE + '/chat/completions', {'Authorization': 'Bearer ' + FAKE_KEY}, BODY)
        self.assertEqual('http_rejected', str(caught.exception))
        self.assertEqual(400, caught.exception.http_status)
        self.assertEqual('duplicate_tool_names', caught.exception.error_category)
        self.assertEqual([MAX_ERROR_RESPONSE + 1], stream.read_sizes)
        self.assertTrue(stream.closed)
        open_request.assert_called_once()
        self.assertNotIn(FAKE_KEY, repr(caught.exception))

    def test_error_read_failure_still_returns_unknown_and_closes_stream(self):
        class BrokenBody(io.BytesIO):
            def read(self, amount=-1):
                raise TimeoutError(FAKE_KEY)
        stream = BrokenBody(b'')
        error = urllib.error.HTTPError(BASE + '/chat/completions', 500, FAKE_KEY, {}, stream)
        transport = _HttpTransport()
        with patch.object(transport.opener, 'open', side_effect=error):
            with self.assertRaises(BrokerError) as caught:
                transport.request('POST', BASE + '/chat/completions', {}, BODY)
        self.assertEqual('unknown', caught.exception.error_category)
        self.assertEqual(500, caught.exception.http_status)
        self.assertTrue(stream.closed)

    def test_classified_failure_remains_uncertain_without_retry_or_sensitive_logs(self):
        self.transport.error = BrokerError('http_rejected', http_status=400, error_category='duplicate_tool_names')
        broker = self.broker()
        with self.assertRaises(BrokerError) as caught:
            broker.call(BODY)
        self.assertEqual('duplicate_tool_names', caught.exception.error_category)
        failure = Path(str(self.ledger) + '.checkpoints') / '000001.failure.json'
        raw = failure.read_text()
        data = json.loads(raw)
        self.assertEqual('duplicate_tool_names', data['error_category'])
        self.assertFalse(data['provider_billing_confirmed'])
        self.assertEqual(1260, broker.summary()['held_micros'])
        self.assertEqual(0, broker.summary()['spent_micros'])
        self.assertEqual(1, len(self.transport.posts))
        self.assertNotIn(FAKE_KEY, raw)
        self.assertNotIn('message', data)
        self.assertNotIn('raw', data)
        self.assertNotIn('headers', data)

    def test_identical_tool_definitions_are_deduped_only_on_physical_wire(self):
        tool = {'type': 'function', 'function': {'name': 'send_price', 'description': 'offline only',
                'parameters': {'type': 'object', 'properties': {}, 'required': []}}}
        other = copy.deepcopy(tool)
        other['function']['name'] = 'set_followup'
        body = copy.deepcopy(BODY)
        body.update(tools=[tool, other, copy.deepcopy(tool)], tool_choice='auto')
        original = copy.deepcopy(body)
        broker = self.broker()
        result = broker.call(body)
        self.assertEqual(original, body)
        expected = [tool, other]
        self.assertEqual(expected, result['request_body']['tools'])
        self.assertEqual(expected, self.transport.posts[0][2]['tools'])
        self.assertEqual('auto', result['request_body']['tool_choice'])
        self.assertEqual(1, len(self.transport.posts))
        with closing(sqlite3.connect(self.ledger)) as db:
            wire_hash = db.execute('SELECT request_hash FROM calls').fetchone()[0]
        import hashlib
        from capture_broker import encoded
        self.assertEqual(hashlib.sha256(encoded(result['request_body'])).hexdigest(), wire_hash)

    def test_tool_order_and_distinct_names_are_preserved(self):
        tool = {'type': 'function', 'function': {'name': 'one', 'parameters': {'type': 'object', 'properties': {}}}}
        second = copy.deepcopy(tool)
        second['function']['name'] = 'two'
        reordered = {'function': copy.deepcopy(tool['function']), 'type': 'function'}
        body = copy.deepcopy(BODY)
        body['tools'] = [second, tool, reordered, copy.deepcopy(second)]
        result = self.broker().call(body)
        self.assertEqual([second, tool], result['request_body']['tools'])
        self.assertEqual(4, len(body['tools']))

    def test_conflicting_duplicate_tool_refuses_before_reservation_and_http(self):
        tool = {'type': 'function', 'function': {'name': 'same', 'description': 'one',
                'parameters': {'type': 'object', 'properties': {}}}}
        broker = self.broker()
        for variant in ('description', 'parameters'):
            with self.subTest(variant=variant):
                changed = copy.deepcopy(tool)
                if variant == 'description':
                    changed['function']['description'] = 'different'
                else:
                    changed['function']['parameters']['properties'] = {'value': {'type': 'string'}}
                body = copy.deepcopy(BODY)
                body['tools'] = [tool, changed]
                self.assertCode('conflicting_tool_definitions', lambda: broker.call(body))
                self.assertEqual(0, broker.summary()['calls'])
                self.assertEqual([], self.transport.posts)

    def test_definition_dedupe_never_removes_response_tool_invocations(self):
        tool = {'type': 'function', 'function': {'name': 'same', 'parameters': {'type': 'object', 'properties': {}}}}
        invocation = {'id': 'call_fake_1', 'type': 'function', 'function': {'name': 'same', 'arguments': '{}'}}
        self.transport.receipt['choices'][0]['message']['tool_calls'] = [invocation, copy.deepcopy(invocation)]
        self.transport.receipt['choices'][0]['message']['tool_calls'][1]['id'] = 'call_fake_2'
        body = copy.deepcopy(BODY)
        body['tools'] = [tool, copy.deepcopy(tool)]
        result = self.broker().call(body)
        self.assertEqual(1, len(result['request_body']['tools']))
        self.assertEqual(2, len(result['data']['choices'][0]['message']['tool_calls']))

    def test_http_status_is_preserved_but_never_releases_unknown_reserve(self):
        for status in (400, 401, 402, 408, 429, 500, None):
            with self.subTest(status=status), tempfile.TemporaryDirectory(dir=self.root) as folder:
                ledger = Path(folder) / 'budget.sqlite'
                transport = FakeTransport()
                transport.error = BrokerError('http_rejected', http_status=status)
                with CaptureBroker(self.key, self.catalog, ledger, transport=transport) as broker:
                    broker.preflight([MODEL])
                    with self.assertRaises(BrokerError) as error:
                        broker.call(BODY)
                    self.assertEqual('http_rejected', error.exception.code)
                    self.assertEqual(status, error.exception.http_status)
                    self.assertEqual(1260, broker.summary()['held_micros'])
                    self.assertEqual(0, broker.summary()['spent_micros'])
                    failure = Path(str(ledger) + '.checkpoints') / '000001.failure.json'
                    data = json.loads(failure.read_text())
                    self.assertEqual(status, data['http_status'])
                    self.assertFalse(data['provider_billing_confirmed'])
                    self.assertFalse(data['response_retained'])
                    self.assertNotIn(FAKE_KEY, failure.read_text())
                self.assertCode('ledger_requires_reconciliation', lambda: CaptureBroker(self.key, self.catalog, ledger, transport=transport))
                self.assertEqual(1, len(transport.posts))

    def test_failure_checkpoint_storage_error_cannot_release_hold(self):
        broker = self.broker()
        self.transport.error = BrokerError('http_rejected', http_status=429)
        checkpoint = broker._checkpoint
        def fail(call_id, kind, value):
            if kind == 'failure':
                raise OSError('private filesystem diagnostic')
            return checkpoint(call_id, kind, value)
        with patch.object(broker, '_checkpoint', side_effect=fail):
            with self.assertRaises(BrokerError) as error:
                broker.call(BODY)
        self.assertEqual(429, error.exception.http_status)
        self.assertEqual(1260, broker.summary()['held_micros'])
        self.assertEqual('http_rejected', broker.summary()['blocked'])
        self.assertEqual(1, len(self.transport.posts))

    def test_untrusted_status_and_error_code_never_leak_into_failure_metadata(self):
        broker = self.broker()
        self.transport.error = BrokerError(FAKE_KEY, http_status=FAKE_KEY)
        with self.assertRaises(BrokerError) as error:
            broker.call(BODY)
        self.assertEqual('receipt_invalid', error.exception.code)
        self.assertIsNone(error.exception.http_status)
        for file in Path(str(self.ledger) + '.checkpoints').glob('*.json'):
            self.assertNotIn(FAKE_KEY, file.read_text())
        self.assertEqual(1260, broker.summary()['held_micros'])

    def test_receipt_metadata_is_fsynced_before_first_billing_get(self):
        broker = self.broker()
        original = self.transport.request
        checkpoints = Path(str(self.ledger) + '.checkpoints')
        with patch('capture_broker.os.fsync', wraps=os.fsync) as sync:
            def observe(method, url, headers, body=None):
                if '/generation?id=' in url:
                    receipt = json.loads((checkpoints / '000001.receipt.json').read_text(encoding='utf-8'))
                    self.assertEqual('gen-offline1', receipt['generation_id'])
                    self.assertFalse(receipt['replay_eligible'])
                    self.assertFalse(receipt['response_retained'])
                    self.assertEqual('0.0001', receipt['usage']['cost_usd'])
                    self.assertGreaterEqual(sync.call_count, 2)
                return original(method, url, headers, body)
            self.transport.request = observe
            broker.call(BODY)
        self.assertEqual(1, len(self.transport.posts))

    def test_checkpoint_contains_no_provider_content_headers_or_credentials(self):
        broker = self.broker()
        self.transport.receipt['choices'][0]['message']['content'] = 'private-person@example.org +7 999 123 45 67'
        self.transport.receipt['private_headers'] = {'authorization': 'Bearer private'}
        broker.call(BODY)
        directory = Path(str(self.ledger) + '.checkpoints')
        for path in directory.glob('*.json'):
            text = path.read_text(encoding='utf-8')
            self.assertNotIn('private-person', text)
            self.assertNotIn('+7 999', text)
            self.assertNotIn('Bearer', text)
            self.assertNotIn('authorization', text)
            self.assertNotIn(FAKE_KEY, text)
            self.assertFalse(json.loads(text)['replay_eligible'])

    def test_reflected_key_in_generation_id_never_reaches_checkpoint(self):
        broker = self.broker()
        self.transport.receipt['id'] = 'gen-' + FAKE_KEY
        self.assertCode('receipt_contains_secret', lambda: broker.call(BODY))
        directory = Path(str(self.ledger) + '.checkpoints')
        self.assertFalse((directory / '000001.receipt.json').exists())
        for path in directory.glob('*.json'):
            self.assertNotIn(FAKE_KEY, path.read_text(encoding='utf-8'))
        self.assertEqual(1, len(self.transport.posts))
        self.assertEqual(0, sum('/generation?' in c[1] for c in self.transport.calls))
        self.assertGreater(broker.summary()['held_micros'], 0)

    def test_no_new_get_starts_after_admission_window(self):
        broker = self.broker()
        original = self.transport.request
        clock = [100.0]
        def delayed(method, url, headers, body=None):
            if '/generation?id=' in url:
                clock[0] += BILLING_START_WINDOW_SECONDS + 15.0
                return {'data': {}}
            return original(method, url, headers, body)
        self.transport.request = delayed
        with patch('capture_broker.time.monotonic', side_effect=lambda: clock[0]):
            self.assertCode('generation_billing_unknown', lambda: broker.call(BODY))
        directory = Path(str(self.ledger) + '.checkpoints')
        self.assertEqual(1, len(list(directory.glob('*.poll-*.json'))))
        self.assertEqual(1, len(self.transport.posts))

    def test_delayed_404_metadata_settles_after_five_minutes_without_second_post(self):
        broker = self.broker()
        clock = [1000.0]
        sleeps, starts = [], []
        original = self.transport.request
        def sleep(seconds):
            sleeps.append(seconds)
            clock[0] += seconds
        def eventually_ready(method, url, headers, body=None):
            if '/generation?id=' in url:
                starts.append(clock[0] - 1000.0)
                self.assertEqual(('reserved', None), broker._connection.execute(
                    'SELECT state,charged_micros FROM calls WHERE id=1').fetchone())
                self.assertEqual(1260, broker.summary()['held_micros'])
                if starts[-1] < 300:
                    raise BrokerError('http_rejected', http_status=404)
            return original(method, url, headers, body)
        self.transport.request = eventually_ready
        with patch('capture_broker.time.monotonic', side_effect=lambda: clock[0]), \
             patch('capture_broker.time.sleep', side_effect=sleep):
            result = broker.call(BODY)
        self.assertEqual(300, starts[-1])
        self.assertGreater(len(starts), 7)
        self.assertTrue(all(delay <= 60 for delay in sleeps))
        self.assertEqual(1, len(self.transport.posts))
        self.assertEqual(100, result['budget']['charged_micros'])
        self.assertEqual(0, broker.summary()['held_micros'])
        self.assertIsNone(broker.summary()['blocked'])
        polls = sorted(Path(str(self.ledger) + '.checkpoints').glob('*.poll-*.json'))
        self.assertEqual(len(starts), len(polls))
        self.assertEqual(404, json.loads(polls[0].read_text(encoding='utf-8'))['http_status'])
        self.assertEqual('billing_ready', json.loads(polls[-1].read_text(encoding='utf-8'))['outcome'])

    def test_exhausted_extended_window_keeps_full_reserve_and_no_post_retry(self):
        broker = self.broker()
        clock = [1000.0]
        starts, sleeps = [], []
        original = self.transport.request
        def unavailable(method, url, headers, body=None):
            if '/generation?id=' in url:
                starts.append(clock[0] - 1000.0)
                raise BrokerError('http_rejected', http_status=404)
            return original(method, url, headers, body)
        def sleep(seconds):
            sleeps.append(seconds)
            clock[0] += seconds
        self.transport.request = unavailable
        with patch('capture_broker.time.monotonic', side_effect=lambda: clock[0]), \
             patch('capture_broker.time.sleep', side_effect=sleep):
            self.assertCode('generation_billing_unknown', lambda: broker.call(BODY))
        self.assertGreaterEqual(starts[-1], 10 * 60)
        self.assertTrue(all(start < BILLING_START_WINDOW_SECONDS for start in starts))
        self.assertLessEqual(len(starts), len(BILLING_DELAYS))
        self.assertTrue(all(delay <= 60 for delay in sleeps))
        self.assertEqual(1, len(self.transport.posts))
        self.assertEqual(('uncertain', None), broker._connection.execute(
            'SELECT state,charged_micros FROM calls WHERE id=1').fetchone())
        self.assertEqual(1260, broker.summary()['held_micros'])
        self.assertEqual('generation_billing_unknown', broker.summary()['blocked'])
        self.assertCode('ledger_requires_reconciliation', lambda: broker.call(BODY))
        self.assertEqual(1, len(self.transport.posts))

    def test_process_suspension_during_sleep_cannot_admit_late_metadata_get(self):
        broker = self.broker()
        clock = [1000.0]
        starts = []
        original = self.transport.request
        def unavailable(method, url, headers, body=None):
            if '/generation?id=' in url:
                starts.append(clock[0])
                raise BrokerError('http_rejected', http_status=404)
            return original(method, url, headers, body)
        def oversleep(seconds):
            clock[0] += BILLING_START_WINDOW_SECONDS + 1
        self.transport.request = unavailable
        with patch('capture_broker.time.monotonic', side_effect=lambda: clock[0]), \
             patch('capture_broker.time.sleep', side_effect=oversleep):
            self.assertCode('generation_billing_unknown', lambda: broker.call(BODY))
        self.assertEqual([1000.0], starts)
        self.assertEqual(1, len(self.transport.posts))
        self.assertEqual(1260, broker.summary()['held_micros'])

    def test_billing_becomes_ready_after_more_than_original_three_attempts(self):
        broker = self.broker()
        self.transport.billing_failures = 5
        with patch('capture_broker.time.sleep') as sleep:
            broker.call(BODY)
        self.assertEqual([1, 2, 4, 8, 15], [item.args[0] for item in sleep.call_args_list])
        self.assertEqual(6, sum('/generation?' in call[1] for call in self.transport.calls))
        self.assertEqual(1, len(self.transport.posts))
        self.assertEqual(0, broker.summary()['held_micros'])
        self.assertTrue((Path(str(self.ledger) + '.checkpoints') / '000001.poll-06.json').is_file())

    def test_missing_billing_shape_is_diagnosed_without_raw_payload(self):
        broker = self.broker()
        del self.transport.billing['is_byok']
        self.transport.billing['secret'] = 'do-not-log-this'
        self.assertCode('generation_billing_unknown', lambda: broker.call(BODY))
        directory = Path(str(self.ledger) + '.checkpoints')
        polls = sorted(directory.glob('000001.poll-*.json'))
        self.assertEqual(len(BILLING_DELAYS), len(polls))
        for path in polls:
            event = json.loads(path.read_text(encoding='utf-8'))
            self.assertEqual('billing_byok_status_missing', event['outcome'])
            self.assertEqual(200, event['http_status'])
            self.assertNotIn('do-not-log-this', path.read_text(encoding='utf-8'))
        self.assertGreater(broker.summary()['held_micros'], 0)
        self.assertEqual(1, len(self.transport.posts))

    def test_safe_http_status_is_retained_in_poll_diagnostics(self):
        broker = self.broker()
        original = self.transport.request
        def unavailable(method, url, headers, body=None):
            if '/generation?id=' in url:
                raise BrokerError('http_rejected', http_status=404)
            return original(method, url, headers, body)
        self.transport.request = unavailable
        self.assertCode('generation_billing_unknown', lambda: broker.call(BODY))
        event = json.loads((Path(str(self.ledger) + '.checkpoints') / '000001.poll-01.json').read_text(encoding='utf-8'))
        self.assertEqual({'outcome': 'http_rejected', 'http_status': 404},
                         {key: event[key] for key in ('outcome', 'http_status')})
        self.assertEqual(1, len(self.transport.posts))

    def test_crash_after_post_before_receipt_checkpoint_never_repeats_post(self):
        broker = self.broker()
        with patch.object(broker, '_checkpoint_receipt', side_effect=SystemExit):
            with self.assertRaises(SystemExit):
                broker.call(BODY)
        self.assertEqual(1, len(self.transport.posts))
        self.assertFalse((Path(str(self.ledger) + '.checkpoints') / '000001.receipt.json').exists())
        with closing(sqlite3.connect(self.ledger)) as db:
            self.assertEqual('reserved', db.execute('SELECT state FROM calls').fetchone()[0])
        broker.close()
        replacement = FakeTransport()
        self.assertCode('ledger_requires_reconciliation', lambda: self.broker(transport=replacement))
        self.assertEqual([], replacement.posts)

    def test_crash_after_receipt_checkpoint_preserves_id_and_blocks_restart(self):
        broker = self.broker()
        with patch.object(broker, '_receipt_cost', side_effect=SystemExit):
            with self.assertRaises(SystemExit):
                broker.call(BODY)
        checkpoint = json.loads((Path(str(self.ledger) + '.checkpoints') / '000001.receipt.json').read_text(encoding='utf-8'))
        self.assertEqual('gen-offline1', checkpoint['generation_id'])
        broker.close()
        self.assertCode('ledger_requires_reconciliation', lambda: self.broker())
        self.assertEqual(1, len(self.transport.posts))

    def test_checkpoint_fsync_failure_holds_reservation_without_billing_or_retry(self):
        broker = self.broker()
        original = os.fsync
        calls = [0]
        def fail_second(handle):
            calls[0] += 1
            if calls[0] == 2:
                raise OSError('simulated disk flush failure')
            return original(handle)
        with patch('capture_broker.os.fsync', side_effect=fail_second):
            self.assertCode('checkpoint_write_failed', lambda: broker.call(BODY))
        self.assertEqual(1, len(self.transport.posts))
        self.assertEqual(0, sum('/generation?' in c[1] for c in self.transport.calls))
        self.assertGreater(broker.summary()['held_micros'], 0)

    def test_read_only_inspector_does_not_mutate_accounting(self):
        import hashlib
        from inspect_capture import read_diagnosis
        broker = self.broker()
        self.transport.billing_failures = len(BILLING_DELAYS) + 1
        self.assertCode('generation_billing_unknown', lambda: broker.call(BODY))
        before = hashlib.sha256(self.ledger.read_bytes()).hexdigest()
        report = read_diagnosis(self.ledger)
        self.assertEqual(before, hashlib.sha256(self.ledger.read_bytes()).hexdigest())
        self.assertEqual('read_only', report['result'])
        self.assertEqual('gen-offline1', report['calls'][0]['checkpoint']['generation_id'])
        self.assertFalse(report['calls'][0]['checkpoint']['replay_eligible'])
        self.assertEqual(len(BILLING_DELAYS), len(report['calls'][0]['poll_attempts']))
        self.assertEqual(1, len(self.transport.posts))

    def test_success_without_chat_is_byok_uses_generation_metadata(self):
        broker = self.broker()
        result = broker.call(BODY)
        self.assertEqual(100, result['budget']['charged_micros'])
        self.assertEqual(1260, result['budget']['reserved_micros'])
        self.assertEqual(1, len(self.transport.posts))
        self.assertEqual(['anthropic'], result['request_body']['provider']['only'])
        self.assertFalse(result['request_body']['provider']['allow_fallbacks'])
        self.assertEqual([], result['request_body']['plugins'])
        self.assertNotIn(FAKE_KEY, json.dumps(result))
        self.assertEqual(0, broker.summary()['held_micros'])

    def test_text_only_does_not_filter_provider_by_unused_image_price(self):
        self.transport.endpoint['pricing']['image'] = '0.001'
        broker = self.broker()
        result = broker.call(BODY)
        self.assertNotIn('image', result['request_body']['provider']['max_price'])
        self.assertEqual(1, len(self.transport.posts))

    def test_donor_require_parameters_is_preserved_without_forcing_temperature_support(self):
        broker = self.broker()
        first = broker.call(BODY)
        self.assertFalse(first['request_body']['provider']['require_parameters'])
        second = broker.call(dict(BODY, provider={'require_parameters': True}))
        self.assertTrue(second['request_body']['provider']['require_parameters'])
        self.assertEqual(0.7, second['request_body']['temperature'])

    def test_reservation_is_committed_before_actual_post(self):
        broker = self.broker()
        def inspect():
            with closing(sqlite3.connect(self.ledger)) as db:
                self.assertEqual(('reserved', 1260), db.execute('SELECT state,reserved_micros FROM calls').fetchone())
        self.transport.before_post = inspect
        broker.call(BODY)

    def test_task_budget_survives_run_a_to_run_b(self):
        self.transport.endpoint['pricing'] = {'prompt': '0.03', 'completion': '0'}
        self.transport.receipt['usage']['cost'] = 30
        self.transport.billing['total_cost'] = 30
        first = self.broker()
        first.call(BODY)
        first.close()
        next_transport = FakeTransport()
        next_transport.endpoint = copy.deepcopy(self.transport.endpoint)
        second = self.broker(transport=next_transport)
        self.assertEqual(30000000, second.summary()['spent_micros'])
        self.assertCode('budget_exhausted', lambda: second.call(BODY))
        self.assertEqual([], next_transport.posts)

    def test_pending_crash_blocks_every_later_run(self):
        broker = self.broker()
        wire, amount = broker._wire(BODY)
        broker._reserve(wire, amount)
        broker.close()
        self.assertCode('ledger_requires_reconciliation', lambda: self.broker())

    def test_timeout_is_never_retried_and_holds_reservation(self):
        broker = self.broker()
        self.transport.error = TimeoutError(FAKE_KEY)
        self.assertCode('transport_uncertain', lambda: broker.call(BODY))
        self.assertEqual(1, len(self.transport.posts))
        self.assertEqual(1260, broker.summary()['held_micros'])
        self.assertCode('ledger_requires_reconciliation', lambda: broker.call(BODY))
        broker.close()
        self.assertCode('ledger_requires_reconciliation', lambda: self.broker())

    def test_missing_chat_cost_holds_even_when_generation_has_cost(self):
        broker = self.broker()
        del self.transport.receipt['usage']['cost']
        self.assertCode('receipt_cost_missing', lambda: broker.call(BODY))
        self.assertEqual(1260, broker.summary()['held_micros'])
        self.assertEqual(1, len(self.transport.posts))
        self.assertEqual(0, sum('/generation?' in c[1] for c in self.transport.calls))

    def test_lost_database_cannot_reset_the_task_budget(self):
        broker = self.broker()
        broker.call(BODY)
        broker.close()
        self.ledger.unlink()
        self.assertCode('ledger_state_missing', lambda: self.broker())
        self.assertFalse(self.ledger.exists())

    def test_lost_lock_marker_does_not_create_a_second_writer(self):
        broker = self.broker()
        broker.close()
        Path(str(self.ledger) + '.lock').unlink()
        self.assertCode('ledger_state_missing', lambda: self.broker())

    def test_existing_zero_byte_database_never_reinitializes_budget(self):
        broker = self.broker()
        broker.call(BODY)
        broker.close()
        self.ledger.write_bytes(b'')
        self.assertCode('ledger_schema_missing', lambda: self.broker())
        self.assertEqual(b'', self.ledger.read_bytes())

    def test_replaced_empty_sqlite_database_never_reinitializes_budget(self):
        broker = self.broker()
        broker.call(BODY)
        broker.close()
        self.ledger.unlink()
        with closing(sqlite3.connect(self.ledger)) as db:
            db.execute('CREATE TABLE unrelated(value TEXT)')
            db.commit()
        before = self.ledger.read_bytes()
        self.assertCode('ledger_schema_missing', lambda: self.broker())
        self.assertEqual(before, self.ledger.read_bytes())

    def test_existing_corrupt_database_never_reinitializes_budget(self):
        broker = self.broker()
        broker.close()
        self.ledger.write_bytes(b'corrupt offline ledger bytes')
        self.assertCode('ledger_integrity_invalid', lambda: self.broker())
        self.assertEqual(b'corrupt offline ledger bytes', self.ledger.read_bytes())

    def test_existing_schema_without_budget_row_never_reinitializes_budget(self):
        broker = self.broker()
        broker.close()
        with closing(sqlite3.connect(self.ledger)) as db:
            db.execute('DELETE FROM budget')
            db.commit()
        self.assertCode('ledger_budget_missing', lambda: self.broker())
        with closing(sqlite3.connect(self.ledger)) as db:
            self.assertEqual(0, db.execute('SELECT count(*) FROM budget').fetchone()[0])

    def test_missing_total_tokens_holds_reservation_before_settlement(self):
        broker = self.broker()
        del self.transport.receipt['usage']['total_tokens']
        self.assertCode('receipt_total_tokens_invalid', lambda: broker.call(BODY))
        self.assertEqual(1260, broker.summary()['held_micros'])
        self.assertEqual(0, broker.summary()['spent_micros'])
        self.assertCode('ledger_requires_reconciliation', lambda: broker.call(BODY))

    def test_inconsistent_total_tokens_holds_reservation_before_settlement(self):
        broker = self.broker()
        self.transport.receipt['usage']['total_tokens'] = 14
        self.assertCode('receipt_total_tokens_invalid', lambda: broker.call(BODY))
        self.assertEqual(1260, broker.summary()['held_micros'])
        self.assertEqual(0, broker.summary()['spent_micros'])
        self.assertEqual(1, len(self.transport.posts))

    def test_generation_get_can_retry_but_post_never_repeats(self):
        broker = self.broker()
        self.transport.billing_failures = 2
        broker.call(BODY)
        self.assertEqual(1, len(self.transport.posts))
        self.assertEqual(3, sum('/generation?' in c[1] for c in self.transport.calls))

    def test_unknown_generation_cost_stops_and_holds(self):
        broker = self.broker()
        del self.transport.billing['total_cost']
        self.assertCode('generation_billing_unknown', lambda: broker.call(BODY))
        self.assertEqual(1260, broker.summary()['held_micros'])
        self.assertEqual(1, len(self.transport.posts))

    def test_byok_counts_upstream_plus_openrouter_fee(self):
        broker = self.broker()
        self.transport.billing.update(is_byok=True, upstream_inference_cost=0.0005)
        result = broker.call(BODY)
        self.assertEqual(600, result['budget']['charged_micros'])

    def test_unknown_byok_upstream_cost_never_becomes_zero(self):
        broker = self.broker()
        self.transport.billing['is_byok'] = True
        del self.transport.billing['upstream_inference_cost']
        self.assertCode('byok_cost_missing', lambda: broker.call(BODY))
        self.assertEqual(1260, broker.summary()['held_micros'])

    def test_malformed_negative_and_nonfinite_costs_fail_closed(self):
        for value in [None, True, -1, 'NaN', 'Infinity', 'secret text']:
            with self.subTest(value=value):
                self.assertCode('invalid_price', lambda: _rates({'prompt': value, 'completion': '0'}))

    def test_tiers_cache_and_reasoning_are_all_reserved_conservatively(self):
        self.transport.endpoint['pricing'].update(input_cache_read='0.0000001', input_cache_write='0.0000025', input_cache_write_1h='0.000004',
                                                  internal_reasoning='0.000003', overrides=[{'min_prompt_tokens': 100, 'prompt': '0.000005', 'completion': '0.00001', 'input_cache_write_1h': '0.00002'}])
        broker = self.broker()
        _, amount = broker._wire(BODY)
        # (1000*(5+.1+2.5+20) + 100*(10+3)) microUSD *1.05.
        self.assertEqual(30345, amount)

    def test_unknown_paid_pricing_rejected_before_any_post(self):
        self.transport.endpoint['pricing']['unrecognized_fee'] = '0.01'
        self.assertCode('unknown_nonzero_price', lambda: self.broker())
        self.assertEqual([], self.transport.posts)

    def test_invalid_tiers_rejected(self):
        self.transport.endpoint['pricing']['overrides'] = [{'prompt': '1'}]
        self.assertCode('pricing_tiers_invalid', lambda: self.broker())

    def test_provider_and_modality_and_addon_injection_rejected(self):
        broker = self.broker()
        attacks = [dict(BODY, provider={'only': ['attacker']}), dict(BODY, plugins=[{'id': 'web'}]), dict(BODY, stream=True),
                   dict(BODY, messages=[{'role': 'user', 'content': [{'type': 'image_url', 'image_url': {'url': 'http://example.test'}}]}]),
                   dict(BODY, tools=[{'type': 'web_search'}]), dict(BODY, model='attacker/model'), dict(BODY, max_tokens=True)]
        for attack in attacks:
            with self.subTest(keys=list(attack)):
                with self.assertRaises(BrokerError): broker.call(attack)
        self.assertEqual([], self.transport.posts)

    def test_unexpected_paid_addon_receipt_holds(self):
        broker = self.broker()
        self.transport.billing['num_search_results'] = 1
        self.assertCode('unexpected_paid_addon', lambda: broker.call(BODY))
        self.assertEqual(1260, broker.summary()['held_micros'])

    def test_exceeded_reservation_blocks_entire_task(self):
        broker = self.broker()
        self.transport.receipt['usage']['cost'] = 1
        self.transport.billing['total_cost'] = 1
        self.assertCode('reservation_exceeded', lambda: broker.call(BODY))
        self.assertEqual(1000000, broker.summary()['spent_micros'])
        self.assertEqual(1260, broker.summary()['held_micros'])

    def test_actual_key_never_passes_to_child_receipt(self):
        broker = self.broker()
        self.transport.receipt['choices'][0]['message']['content'] = FAKE_KEY
        self.assertCode('receipt_contains_secret', lambda: broker.call(BODY))

    def test_key_in_prompt_rejected_before_wire(self):
        broker = self.broker()
        self.assertCode('request_contains_secret', lambda: broker.call(dict(BODY, messages=[{'role': 'user', 'content': FAKE_KEY}])))
        self.assertEqual([], self.transport.posts)

    def test_budget_change_and_key_change_refused(self):
        broker = self.broker()
        broker.close()
        self.assertCode('budget_change_refused', lambda: self.broker(limit='49'))
        self.key.write_text('sk-or-v1-' + 'different_offline_key_value_00000000', encoding='utf-8')
        self.assertCode('credential_change_refused', lambda: self.broker())

    def test_production_uses_single_canonical_task_ledger(self):
        self.assertCode('task_ledger_required', lambda: CaptureBroker(self.key, self.catalog, self.ledger))
        self.assertTrue(str(CANONICAL_LEDGER).replace('\\', '/').endswith('/.local/baseline/budget.sqlite'))

    def test_second_process_cannot_enter_same_ledger(self):
        self.broker()
        script = "from capture_broker import CaptureBroker,BrokerError; from pathlib import Path; import sys\ntry:\n CaptureBroker(Path(sys.argv[1]),Path(sys.argv[2]),Path(sys.argv[3]),transport=object())\nexcept BrokerError as e:\n print(e.code)\n"
        env = dict(os.environ, PYTHONPATH=str(Path(__file__).resolve().parents[1]), PYTHONDONTWRITEBYTECODE='1')
        result = subprocess.run([sys.executable, '-c', script, str(self.key), str(self.catalog), str(self.ledger)], env=env, text=True, capture_output=True, timeout=10)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual('ledger_in_use', result.stdout.strip())

    def test_real_transport_refuses_nonallowlisted_url_without_network(self):
        transport = _HttpTransport()
        for url in ['http://openrouter.ai/api/v1/chat/completions', BASE + '/chat/completions?x=1', 'https://evil.test/api/v1/chat/completions', BASE + '/generation?id=../../secret']:
            self.assertCode('endpoint_refused', lambda: transport.request('POST', url, {}))
        self.assertCode('redirect_refused', lambda: _NoRedirect().redirect_request(None))

    def test_provider_price_refresh_revalidates_before_reservation(self):
        broker = self.broker()
        self.transport.endpoint['pricing']['unknown_fee'] = '1'
        self.assertCode('unknown_nonzero_price', lambda: broker.call(BODY))
        self.assertEqual(0, broker.summary()['calls'])
        self.assertEqual([], self.transport.posts)

    def test_submicro_costs_round_up(self):
        broker = self.broker()
        self.transport.receipt['usage']['cost'] = 0.00000001
        self.transport.billing['total_cost'] = 0.00000001
        self.assertEqual(1, broker.call(BODY)['budget']['charged_micros'])


if __name__ == '__main__':
    unittest.main()