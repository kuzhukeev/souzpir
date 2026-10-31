"""Generalized resume safety: real offline PHP, fake HTTP, private SQLite only."""
from __future__ import annotations

import argparse
from contextlib import redirect_stdout
import copy
import io
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import capture
import capture_broker
import verify
from capture_broker import CaptureBroker, MODELS
from test_integration import FakeProductionTransport, FAKE_KEY

PHP = Path('C:/OSPanel/modules/PHP-8.4/php.exe')
PROTOTYPE = capture.ROOT / '.local/prototype'
FIRST = 'manual.no_repeat_filled_slot'
SECOND = 'manual.negative_twice'


class GeneralizedResumeTests(unittest.TestCase):
    def setUp(self):
        parent = capture.LOCAL / 'integration-tests'
        parent.mkdir(parents=True, exist_ok=True)
        self.temporary = tempfile.TemporaryDirectory(dir=parent)
        self.directory = Path(self.temporary.name)
        self.addCleanup(self.temporary.cleanup)
        self.source = self.directory / 'partial'
        self.key = self.directory / 'fake.key'
        self.key.write_text(FAKE_KEY, encoding='utf-8')
        self.catalog = self.directory / 'catalog.json'
        self.catalog.write_text(json.dumps({'data': [{'id': model} for model in MODELS]}), encoding='utf-8')
        self.ledger = self.directory / 'budget.sqlite'
        self.transport = FakeProductionTransport()
        self.network = patch('urllib.request.OpenerDirector.open', side_effect=AssertionError('Network forbidden'))
        self.network.start()
        self.addCleanup(self.network.stop)
        self.broker = CaptureBroker(self.key, self.catalog, self.ledger, transport=self.transport)
        self.addCleanup(self.broker.close)
        self.broker.preflight(sorted(MODELS))
        self.description = capture.run_child(PHP, PROTOTYPE, ['--action=describe'])
        by_id = {definition['id']: definition for definition in self.description['cases']}
        self.definitions = [by_id[FIRST], by_id[SECOND]]
        self.last_message = None
        with patch.object(capture, 'FakeBroker', return_value=self.handler), redirect_stdout(io.StringIO()):
            capture.execute(self.args(test_case=FIRST, output=self.source))
        manifest = verify.read(self.source / 'manifest.json')
        self.run_id = manifest['run_id']
        # Emulate a task run interrupted after its first completed case, before
        # the final whole-bundle manifest. All fixtures still say synthetic.
        (self.source / 'manifest.json').unlink()
        self.assertEqual(2, len(self.transport.posts))
        self.first_fixture = capture.digest(self.source / f'cases/{FIRST}.json')

    def args(self, **overrides):
        values = dict(prototype=PROTOTYPE, php=PHP, test_case=FIRST, ledger=self.ledger,
                      allow_live_openrouter=False, acknowledge_pseudonymized_inputs=False,
                      preflight_only=False, output=self.directory / 'continued', resume_from=None,
                      key_file=self.key, catalog=self.catalog)
        values.update(overrides)
        return argparse.Namespace(**values)

    def handler(self, message):
        self.last_message = copy.deepcopy(message)
        self.transport.message = message
        return self.broker.call(message['body'])

    def partial_second(self):
        def stop_before_second(message):
            if message['sequence'] == 2:
                raise capture.CaptureError('intentional_private_test_stop')
            return self.handler(message)
        arguments = [f'--case={SECOND}', f'--output={self.source}', f'--run-id={self.run_id}', '--mode=test']
        with redirect_stdout(io.StringIO()), self.assertRaisesRegex(capture.CaptureError, 'intentional_private_test_stop'):
            capture.run_child(PHP, PROTOTYPE, arguments, stop_before_second)
        self.assertEqual(3, len(self.transport.posts))

    def prepare(self):
        return capture.prepare_resume(self.source, self.definitions, self.description, PHP, PROTOTYPE, True)

    def test_completed_case_and_partial_next_share_one_global_ledger_union(self):
        self.partial_second()
        before = verify.files(self.source)
        resume = self.prepare()
        self.assertEqual({FIRST}, set(resume['completed']))
        self.assertEqual(SECOND, resume['frontier']['case_id'])
        self.assertEqual(1, resume['frontier']['count'])
        self.assertEqual([1, 2, 3], [record['evidence']['budget']['call_id'] for record in resume['records']])
        self.assertEqual([1, 2, 1], [record['evidence']['sequence'] for record in resume['records']])
        self.assertEqual(self.run_id, resume['run_id'])
        capture.validate_resume_ledger(self.ledger, resume)
        self.assertEqual(3, len(self.transport.posts))
        self.assertEqual(before, verify.files(self.source))
        self.assertEqual(self.first_fixture, capture.digest(self.source / f'cases/{FIRST}.json'))

    def test_durable_replies_reconstruct_missing_case_fixture_without_post(self):
        (self.source / f'cases/{FIRST}.json').unlink()
        before = verify.files(self.source)
        resume = self.prepare()
        self.assertEqual({FIRST}, set(resume['completed']))
        self.assertEqual('complete', resume['completed'][FIRST]['op'])
        self.assertEqual([1, 2], resume['completed'][FIRST]['consumed_sequences'])
        self.assertEqual(SECOND, resume['frontier']['case_id'])
        self.assertEqual(0, resume['frontier']['count'])
        capture.validate_resume_ledger(self.ledger, resume)
        self.assertEqual(2, len(self.transport.posts))
        self.assertEqual(before, verify.files(self.source))
        self.assertFalse((self.source / f'cases/{FIRST}.json').exists())

    def test_settlement_without_reply_file_refuses_tail_without_accounting_mutation(self):
        self.partial_second()
        resume = self.prepare()
        # A physical fake POST settled, but its response never reached the PHP
        # recorder. Its metadata checkpoint cannot recreate the missing text.
        self.transport.message = self.last_message
        self.broker.call(self.last_message['body'])
        before = capture.digest(self.ledger)
        with self.assertRaisesRegex(capture.CaptureError, 'resume_settled_response_missing'):
            capture.validate_resume_ledger(self.ledger, resume)
        self.assertEqual(before, capture.digest(self.ledger))
        self.assertEqual(4, len(self.transport.posts))
        self.assertEqual(3, len(resume['records']))

    def test_omitting_earliest_completed_record_cannot_evade_union_guard(self):
        self.partial_second()
        resume = self.prepare()
        resume['records'] = resume['records'][1:]
        before = capture.digest(self.ledger)
        with self.assertRaisesRegex(capture.CaptureError, 'resume_settled_response_missing'):
            capture.validate_resume_ledger(self.ledger, resume)
        self.assertEqual(before, capture.digest(self.ledger))
        self.assertEqual(3, len(self.transport.posts))

    def test_duplicate_physical_id_across_cases_is_rejected(self):
        self.partial_second()
        resume = self.prepare()
        resume['records'][-1]['evidence']['budget']['call_id'] = 1
        with self.assertRaisesRegex(capture.CaptureError, 'resume_ledger_identity_invalid'):
            capture.validate_resume_ledger(self.ledger, resume)
        self.assertEqual(3, len(self.transport.posts))

    def test_fresh_live_attempt_cannot_bypass_existing_completed_reply_guard(self):
        before = capture.digest(self.ledger)
        options = self.args(test_case=None, allow_live_openrouter=True,
                            acknowledge_pseudonymized_inputs=True,
                            output=self.directory / 'fresh-would-repeat')
        # All paths and constructor results stay in the private test directory.
        # Preflight is a tripwire: this test must stop BEFORE even fake HTTP GET.
        with patch.object(capture, 'LOCAL', self.directory), \
             patch.object(capture_broker, 'CaptureBroker', return_value=self.broker), \
             patch.object(self.broker, 'preflight', side_effect=AssertionError('orphan guard missing')) as preflight:
            with self.assertRaisesRegex(capture.CaptureError, 'existing_paid_calls_require_resume'):
                capture.execute(options)
            preflight.assert_not_called()
        self.assertEqual(before, capture.digest(self.ledger))
        self.assertEqual(2, len(self.transport.posts))
        self.assertFalse(options.output.exists())


if __name__ == '__main__':
    unittest.main()