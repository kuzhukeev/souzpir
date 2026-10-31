"""Billing-loss disposition: synthetic private ledger and fake metadata GET only."""
from contextlib import closing
import copy
import json
from pathlib import Path
import sqlite3
import unittest
from unittest.mock import patch
import test_orphan_ledger as helpers
from test_broker import BODY, MODEL, FAKE_KEY
from capture_broker import BrokerError, CaptureBroker, encoded
from orphan_ledger import BILLING_REASON, read_orphan_state, exported_entries, exported_reconciliation
from reconcile import Reconciler
import verify
import capture


class MetadataTransport:
    def __init__(self, data):
        self.data = data
        self.calls = []

    def request(self, method, url, headers, body=None):
        if method != 'GET' or '/generation?id=' not in url or body is not None:
            raise AssertionError('Only fake metadata GET is allowed')
        self.calls.append(method)
        return {'data': copy.deepcopy(self.data)}


class OrphanBillingTests(unittest.TestCase):
    tearDown = helpers.OrphanTests.tearDown
    query = helpers.OrphanTests.query
    assertCode = helpers.OrphanTests.assertCode
    broker = helpers.OrphanTests.broker
    record_for = helpers.OrphanTests.record_for
    apply = helpers.OrphanTests.apply

    def setUp(self):
        helpers.OrphanTests.setUp(self)
        # This entire SQLite file is synthetic. Model the interrupted billing
        # state using the actual broker-generated immutable receipt checkpoints.
        with closing(sqlite3.connect(self.ledger)) as db:
            db.execute("UPDATE calls SET state='uncertain',charged_micros=NULL,reason='generation_billing_unknown' WHERE id=2")
            db.execute("UPDATE budget SET blocked='generation_billing_unknown'")
            db.commit()
        receipt = json.loads((Path(str(self.ledger)+'.checkpoints')/'000002.receipt.json').read_text())
        usage = receipt['usage']
        self.metadata = MetadataTransport({'id': receipt['generation_id'], 'created_at': receipt['recorded_at'],
            'model': MODEL, 'provider_name': 'Anthropic', 'is_byok': False, 'total_cost': usage['cost_usd'],
            'tokens_prompt': usage['prompt_tokens'], 'tokens_completion': usage['completion_tokens']})
        self.reconciler = Reconciler(self.ledger, self.key, self.root/'reconciliation', transport=self.metadata)

    def settle(self):
        return self.reconciler.reconcile_checkpoint(call_id=2, apply=True)

    def test_accounting_migration_accepts_only_reviewed_source_pair(self):
        previous = '907a0ae2bbb225ceae7d45275c8076ba3fa57981c67f5ec949471a52cafa496c'
        self.assertTrue(capture.reviewed_accounting_transition('tools/baseline/orphan_ledger.py', previous))
        self.assertFalse(capture.reviewed_accounting_transition('tools/baseline/orphan_ledger.py', 'a'*64))
        self.assertFalse(capture.reviewed_accounting_transition('tools/baseline/ContactNormalizer.php', previous))
        with patch.object(verify, 'harness_source_sha', return_value='b'*64):
            self.assertFalse(capture.reviewed_accounting_transition('tools/baseline/orphan_ledger.py', previous))

    def test_billing_unknown_cannot_be_disposed_before_confirmed_settlement(self):
        before = self.ledger.read_bytes()
        self.assertCode('orphan_budget_invalid', self.apply)
        self.assertEqual(before, self.ledger.read_bytes())
        self.assertEqual([], self.metadata.calls)

    def test_billing_reason_binds_settlement_and_preserves_exact_charges(self):
        settlement = self.settle()
        before = self.query('SELECT * FROM calls')
        self.apply()
        self.assertEqual(before, self.query('SELECT * FROM calls'))
        with closing(sqlite3.connect(self.ledger)) as db:
            audits, bindings = read_orphan_state(db, self.ledger)
            audit = audits[2]['audit']
            self.assertEqual(BILLING_REASON, audit['reason'])
            self.assertEqual(settlement['audit_sha256'], audit['reconciliation_audit_sha256'])
            self.assertEqual(100, audit['charged_micros'])
            self.assertFalse(audit['replay_eligible'])
            self.assertEqual({}, bindings)
        self.assertEqual(['GET'], self.metadata.calls)

    def test_export_is_sanitized_and_verifier_checks_actual_runtime_schema(self):
        self.settle()
        context = self.apply()['replacement_context']
        self.transport.receipt['id'] = self.transport.billing['id'] = 'gen-offline-billing-replacement'
        broker = self.broker()
        result = broker.call(BODY, replacement=context)
        self.resume['records'].append(self.record_for(result, 2))
        entries = exported_entries(broker._connection, self.ledger, 'test_orphan', self.resume['records'])
        public = exported_reconciliation(broker._connection, self.ledger, 2)
        audit = entries[0][0]
        receipt_path = Path(str(self.ledger)+'.checkpoints')/'000002.receipt.json'
        receipt = json.loads(receipt_path.read_text())
        verify.validate_billing_reconciliation(public, audit, receipt, verify.sha(receipt_path))
        self.assertEqual(audit['reconciliation_audit_sha256'], public['source_audit_sha256'])
        self.assertNotIn('key_fingerprint_sha256', public)
        self.assertNotIn('ledger_before_sha256', public)
        self.assertNotIn(FAKE_KEY, json.dumps(public))
        self.assertNotIn('Offline test', json.dumps(public))
        self.assertEqual(300, broker.summary()['spent_micros'])
        self.assertEqual([(2, 3)], self.query('SELECT orphan_call_id,replacement_call_id FROM capture_orphan_replacements'))
        output = self.root/'export'
        call_path = 'llm/manual.orphan/0002.json'
        capture.write_new(output/call_path, self.resume['records'][-1])
        references = capture.export_abandoned_calls(broker._connection, self.ledger, 'test_orphan', self.resume['records'], output)
        entry = references[0]
        self.assertEqual('lineage/abandoned/000002.reconciliation.json', entry['reconciliation']['path'])
        self.assertEqual(public, verify.read(output/entry['reconciliation']['path']))
        observed = {call_path: {'case_id': 'manual.orphan', 'sequence': 2, 'path': call_path,
            'sha256': verify.sha(output/call_path), 'call_id': 3, 'generation_id': result['data']['id'],
            'request_sha256': 'a'*64, 'request_body_sha256': verify.digest_value(BODY), 'model': MODEL}}
        levels = [{'next_requests': {'manual.orphan': self.resume['frontier']['next_request']},
                   'grouped': {'manual.orphan': [1]}}]
        result = verify.validate_abandoned_calls(output, {'run_id': 'test_orphan', 'abandoned_calls': references},
            levels, set(), observed, {'manual.orphan': [2]})
        self.assertEqual({'abandoned_paid_calls': 1, 'abandoned_charged_micros': 100}, result)

    def test_missing_settlement_audit_blocks_existing_disposition(self):
        self.settle()
        self.apply()
        audit = next((self.root/'reconciliation').glob('*reconcile*'))
        audit.unlink()  # Only this test's private temporary artifact.
        with closing(sqlite3.connect(self.ledger)) as db:
            self.assertCode('reconciliation_audit_invalid', lambda: read_orphan_state(db, self.ledger))

    def test_billing_loss_cannot_be_downgraded_to_normalization_after_rehash(self):
        self.settle()
        self.apply()
        with closing(sqlite3.connect(self.ledger)) as db:
            audits, _ = read_orphan_state(db, self.ledger)
            audit = audits[2]['audit']
            audit['reason'] = 'normalization_failure_response_not_retained'
            audit.pop('reconciliation_audit_sha256')
            import hashlib
            raw = encoded(audit)+b'\n'
            checksum = hashlib.sha256(raw).hexdigest()
            (self.root/'orphan-dispositions'/('000002-'+checksum+'.json')).write_bytes(raw)
            db.execute('DROP TRIGGER capture_orphan_dispositions_no_update')
            db.execute('UPDATE capture_orphan_dispositions SET audit_sha256=? WHERE call_id=2', (checksum,))
            db.execute("CREATE TRIGGER capture_orphan_dispositions_no_update BEFORE UPDATE ON capture_orphan_dispositions BEGIN SELECT RAISE(ABORT,'immutable orphan accounting'); END")
            db.commit()
            self.assertCode('orphan_audit_invalid', lambda: read_orphan_state(db, self.ledger))

    def test_orphan_audit_fsync_failure_preserves_confirmed_paid_row(self):
        self.settle()
        calls = self.query('SELECT * FROM calls')
        with patch('orphan_ledger.os.fsync', side_effect=OSError('synthetic disk fault')):
            self.assertCode('orphan_audit_write_failed', self.apply)
        self.assertEqual(calls, self.query('SELECT * FROM calls'))
        self.assertEqual([], self.query("SELECT name FROM sqlite_master WHERE name='capture_orphan_dispositions'"))


if __name__ == '__main__':
    unittest.main()
