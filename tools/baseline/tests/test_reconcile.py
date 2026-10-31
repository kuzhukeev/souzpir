"""Reconciliation/forfeit simulations: fake metadata, fake keys, private temp DBs."""
from contextlib import closing
import copy
import hashlib
import json
import os
from pathlib import Path
import sqlite3
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
sys.path.insert(0, str(Path(__file__).resolve().parent))
from capture_broker import BrokerError, CaptureBroker, TASK_ROOT, BASE
from reconcile import Reconciler, MODEL, PROVIDER, LIMIT
from test_broker import FakeTransport, FAKE_KEY, BODY

GENERATION = 'gen-operator-selected-offline-1'
RESERVE = 9042600


class FakeMetadata:
    def __init__(self):
        self.calls = []
        self.callback = None
        self.data = {'id': GENERATION, 'model': MODEL, 'provider_name': PROVIDER,
                     'created_at': '2026-10-31T10:14:04+00:00', 'is_byok': False,
                     'total_cost': '0.0123456', 'upstream_inference_cost': '0.01',
                     'tokens_prompt': 1000, 'tokens_completion': 200}

    def request(self, method, url, headers, body=None):
        if method != 'GET' or url != BASE + '/generation?id=' + GENERATION or body is not None:
            raise AssertionError('Only the selected metadata GET is allowed')
        self.calls.append((method, url))
        if self.callback:
            self.callback()
        return {'data': copy.deepcopy(self.data)}


class ReconcileTests(unittest.TestCase):
    def setUp(self):
        folder = TASK_ROOT / '.local/reconcile-tests'
        folder.mkdir(parents=True, exist_ok=True)
        self.temp = tempfile.TemporaryDirectory(dir=folder)
        self.root = Path(self.temp.name)
        self.ledger = self.root / 'budget.sqlite'
        self.key = self.root / 'fake.key'
        self.key.write_text(FAKE_KEY, encoding='utf-8')
        self.catalog = self.root / 'catalog.json'
        self.catalog.write_text(json.dumps({'data': [{'id': MODEL}]}), encoding='utf-8')
        self.audit_dir = self.root / 'reconciliation'
        with closing(sqlite3.connect(self.ledger)) as db:
            db.executescript('''CREATE TABLE budget(id INTEGER PRIMARY KEY,limit_micros INTEGER NOT NULL,key_hash TEXT,blocked TEXT);
                CREATE TABLE calls(id INTEGER PRIMARY KEY,request_hash TEXT NOT NULL,model TEXT NOT NULL,reserved_micros INTEGER NOT NULL,charged_micros INTEGER,state TEXT NOT NULL,reason TEXT,created_at TEXT NOT NULL,settled_at TEXT);''')
            db.execute('INSERT INTO budget VALUES(1,?,?,?)', (LIMIT, hashlib.sha256(FAKE_KEY.encode()).hexdigest(), 'generation_billing_unknown'))
            db.execute("INSERT INTO calls VALUES(1,?,?,?,NULL,'uncertain','generation_billing_unknown',?,?)",
                       ('a' * 64, MODEL, RESERVE, '2026-10-31T10:14:02.065353+00:00', '2026-10-31T10:14:08.163378+00:00'))
            db.commit()
        Path(str(self.ledger) + '.lock').write_bytes(b'0')
        self.transport = FakeMetadata()
        self.tool = Reconciler(self.ledger, self.key, self.audit_dir, transport=self.transport)
        self.network = patch('urllib.request.OpenerDirector.open', side_effect=AssertionError('Network forbidden in tests'))
        self.network.start()

    def tearDown(self):
        self.network.stop()
        self.temp.cleanup()

    def assertCode(self, code, action):
        with self.assertRaises(BrokerError) as error:
            action()
        self.assertEqual(code, error.exception.code)

    def query(self, sql):
        with closing(sqlite3.connect(self.ledger)) as db:
            return db.execute(sql).fetchall()

    def assertPending(self):
        self.assertEqual([('uncertain', None, 'generation_billing_unknown')], self.query('SELECT state,charged_micros,reason FROM calls WHERE id=1'))
        self.assertEqual([('generation_billing_unknown',)], self.query('SELECT blocked FROM budget'))

    def apply_reconcile(self):
        return self.tool.reconcile(GENERATION, from_openrouter_logs=True, apply=True)

    def apply_forfeit(self):
        return self.tool.forfeit(operator_confirmed=True, apply=True)

    def broker(self, transport=None):
        broker = CaptureBroker(self.key, self.catalog, self.ledger, transport=transport or FakeTransport())
        broker.preflight([MODEL])
        return broker

    def test_dry_run_gets_metadata_without_touching_accounting(self):
        before = self.ledger.read_bytes()
        result = self.tool.reconcile(GENERATION, from_openrouter_logs=True)
        self.assertEqual('validated_preview', result['result'])
        self.assertEqual(12346, result['confirmed_cost_micros'])
        self.assertFalse(result['provider_confirmed_request_hash'])
        self.assertEqual(before, self.ledger.read_bytes())
        self.assertFalse(self.audit_dir.exists())
        self.assertPending()
        self.assertEqual(1, len(self.transport.calls))

    def test_user_logs_attestation_and_valid_id_required_before_get(self):
        self.assertCode('operator_logs_id_required', lambda: self.tool.reconcile(GENERATION))
        self.assertCode('generation_id_invalid', lambda: self.tool.reconcile('../other', from_openrouter_logs=True))
        self.assertEqual([], self.transport.calls)
        self.assertPending()

    def test_apply_confirms_cost_with_immutable_audit_before_db_write(self):
        original_connect = self.tool._connect
        writes = []
        durable = patch('reconcile.os.fsync', wraps=os.fsync)
        fsync = durable.start()
        self.addCleanup(durable.stop)
        def connect(writable=False):
            if writable:
                files = list(self.audit_dir.glob('*.json'))
                self.assertEqual(1, len(files))
                self.assertEqual(1, fsync.call_count)
                self.assertPending()
                writes.append(files[0])
            return original_connect(writable)
        with patch.object(self.tool, '_connect', side_effect=connect):
            result = self.apply_reconcile()
        self.assertEqual('reconciled', result['result'])
        self.assertEqual([('completed', 12346)], self.query('SELECT state,charged_micros FROM calls'))
        self.assertEqual([(None,)], self.query('SELECT blocked FROM budget'))
        self.assertEqual(1, len(writes))
        raw = writes[0].read_bytes()
        self.assertEqual(result['audit_sha256'], hashlib.sha256(raw).hexdigest())
        self.assertNotIn(FAKE_KEY.encode(), raw)
        audit = json.loads(raw)
        self.assertFalse(audit['provider_confirmed_request_hash'])
        self.assertFalse(audit['response_retained'])
        self.assertFalse(audit['replay_evidence_created'])
        with self.broker() as broker:
            self.assertEqual(12346, broker.summary()['spent_micros'])

    def test_byok_adds_upstream_amount_to_openrouter_fee(self):
        self.transport.data.update(is_byok=True, upstream_inference_cost='0.0200001')
        self.assertEqual(32346, self.apply_reconcile()['confirmed_cost_micros'])

    def test_same_key_fingerprint_required_before_get(self):
        self.key.write_text('sk-or-v1-' + 'different_offline_only_key_value_00000', encoding='utf-8')
        self.assertCode('credential_change_refused', self.apply_reconcile)
        self.assertEqual([], self.transport.calls)
        self.assertPending()

    def test_identity_and_timestamp_and_cost_checks_fail_closed(self):
        original = copy.deepcopy(self.transport.data)
        cases = [('provider_name', 'Other', 'generation_identity_mismatch'),
                 ('model', 'anthropic/other', 'generation_identity_mismatch'),
                 ('created_at', '2026-10-31T11:00:00+00:00', 'generation_time_mismatch'),
                 ('created_at', '2026-10-31T10:14:04', 'timestamp_invalid'),
                 ('total_cost', '10', 'reconciliation_exceeds_reserve'),
                 ('total_cost', 'NaN', 'generation_cost_invalid'),
                 ('tokens_prompt', True, 'generation_tokens_invalid'),
                 ('num_search_results', 1, 'generation_addon_mismatch')]
        for field, value, code in cases:
            with self.subTest(field=field, value=value):
                self.transport.data = copy.deepcopy(original)
                self.transport.data[field] = value
                self.assertCode(code, self.apply_reconcile)
                self.assertPending()
        self.assertFalse(self.audit_dir.exists())

    def test_metadata_reflecting_secret_is_not_logged_or_stored(self):
        self.transport.data['untrusted_field'] = FAKE_KEY
        self.assertCode('metadata_contains_secret', self.apply_reconcile)
        self.assertFalse(self.audit_dir.exists())
        self.assertPending()

    def test_audit_failure_cannot_settle_ledger(self):
        with patch.object(self.tool, '_write_audit', side_effect=BrokerError('audit_write_failed')):
            self.assertCode('audit_write_failed', self.apply_reconcile)
        self.assertPending()

    def test_external_change_fails_cas_without_overwriting_it(self):
        def mutate():
            with closing(sqlite3.connect(self.ledger)) as db:
                db.execute("UPDATE calls SET settled_at='2026-10-31T10:14:08.5+00:00'")
                db.commit()
        self.transport.callback = mutate
        self.assertCode('ledger_changed', self.apply_reconcile)
        self.assertPending()
        self.assertEqual(1, len(list(self.audit_dir.glob('*.json'))))

    def test_forfeit_requires_explicit_choice_and_does_not_use_network(self):
        self.assertCode('explicit_forfeit_choice_required', lambda: self.tool.forfeit(apply=True))
        preview = self.tool.forfeit()
        self.assertEqual(40957400, preview['projected_available_micros'])
        self.assertFalse(preview['actual_cost_confirmed'])
        self.assertEqual([], self.transport.calls)
        self.assertPending()

    def test_full_forfeit_preserves_uncertain_null_and_exact_residual(self):
        result = self.apply_forfeit()
        self.assertEqual(RESERVE, result['forfeited_micros'])
        self.assertEqual(40957400, result['available_micros'])
        self.assertEqual([('uncertain', None, 'generation_billing_unknown')], self.query('SELECT state,charged_micros,reason FROM calls'))
        self.assertEqual([(RESERVE, 'operator_conservative_forfeit')], self.query('SELECT upper_bound_micros,reason FROM budget_forfeitures'))
        self.assertEqual([], self.transport.calls)
        with self.broker() as broker:
            summary = broker.summary()
            self.assertEqual(0, summary['spent_micros'])
            self.assertEqual(0, summary['held_micros'])
            self.assertEqual(RESERVE, summary['forfeited_micros'])
            self.assertEqual(40957400, summary['available_micros'])

    def test_forfeit_is_not_refunded_or_reclassified_as_confirmed_receipt(self):
        self.apply_forfeit()
        self.assertCode('forfeit_already_accounted', self.apply_forfeit)
        self.assertCode('forfeit_already_accounted', self.apply_reconcile)
        with closing(sqlite3.connect(self.ledger)) as db:
            with self.assertRaises(sqlite3.IntegrityError):
                db.execute('UPDATE budget_forfeitures SET upper_bound_micros=1')
            with self.assertRaises(sqlite3.IntegrityError):
                db.execute('DELETE FROM budget_forfeitures')
        self.assertEqual([(RESERVE,)], self.query('SELECT upper_bound_micros FROM budget_forfeitures'))

    def test_forfeit_deduction_survives_all_later_run_ids(self):
        self.apply_forfeit()
        transport = FakeTransport()
        transport.endpoint['pricing'] = {'prompt': '0.03', 'completion': '0'}
        transport.receipt['usage']['cost'] = 30
        transport.billing['total_cost'] = 30
        with self.broker(transport) as first:
            first.call(BODY)
            self.assertEqual(10957400, first.summary()['available_micros'])
        second_transport = FakeTransport()
        second_transport.endpoint = copy.deepcopy(transport.endpoint)
        with self.broker(second_transport) as second:
            self.assertCode('budget_exhausted', lambda: second.call(BODY))
            self.assertEqual(RESERVE, second.summary()['forfeited_micros'])
        self.assertEqual([], second_transport.posts)

    def test_lost_or_tampered_forfeit_audit_blocks_future_requests(self):
        self.apply_forfeit()
        file = next(self.audit_dir.glob('*.json'))
        original = file.read_bytes()
        file.write_bytes(original + b' ')
        self.assertCode('forfeit_audit_invalid', self.broker)
        file.unlink()
        self.assertCode('forfeit_audit_invalid', self.broker)

    def test_partial_forfeit_record_cannot_unlock_pending_call(self):
        self.apply_forfeit()
        with closing(sqlite3.connect(self.ledger)) as db:
            db.execute('DROP TRIGGER budget_forfeitures_no_update')
            db.commit()
        self.assertCode('forfeit_audit_invalid', self.broker)

    def test_other_pending_call_is_not_covered_by_first_call_forfeit(self):
        self.apply_forfeit()
        with self.broker() as broker:
            wire, amount = broker._wire(BODY)
            broker._reserve(wire, amount)
        self.assertCode('ledger_requires_reconciliation', self.broker)

    def test_audit_only_crash_cannot_unlock_pending_call(self):
        original_write = self.tool._write_audit
        def crash(audit):
            original_write(audit)
            raise BrokerError('simulated_crash')
        with patch.object(self.tool, '_write_audit', side_effect=crash):
            self.assertCode('simulated_crash', self.apply_forfeit)
        self.assertPending()
        self.assertCode('ledger_requires_reconciliation', self.broker)

    def test_forfeit_sql_failure_rolls_back_table_and_deduction(self):
        connect = self.tool._connect
        class FailBeforeUnblock:
            def __init__(self, db):
                self.db = db
            def __getattr__(self, name):
                return getattr(self.db, name)
            def execute(self, sql, parameters=()):
                if sql.startswith('UPDATE budget SET blocked'):
                    raise sqlite3.OperationalError('simulated database interruption')
                return self.db.execute(sql, parameters)
        def fail_connection(writable=False):
            db = connect(writable)
            return FailBeforeUnblock(db) if writable else db
        with patch.object(self.tool, '_connect', side_effect=fail_connection):
            self.assertCode('settlement_failed', self.apply_forfeit)
        self.assertPending()
        self.assertEqual([(0,)], self.query("SELECT count(*) FROM sqlite_master WHERE name='budget_forfeitures'"))
        self.assertEqual(1, len(list(self.audit_dir.glob('*.json'))))
        self.assertCode('ledger_requires_reconciliation', self.broker)

    def second_incident(self):
        self.apply_forfeit()
        with closing(sqlite3.connect(self.ledger)) as db:
            for call_id in range(2, 24):
                db.execute("INSERT INTO calls VALUES(?,?,?,?,?,'completed',NULL,?,?)",
                           (call_id, 'b' * 64, MODEL, 1000000, 70442 if call_id == 2 else 0,
                            '2026-10-31T10:30:00+00:00', '2026-10-31T10:30:01+00:00'))
            db.execute("INSERT INTO calls VALUES(24,?,?,?,NULL,'uncertain','http_rejected',?,?)",
                       ('c' * 64, MODEL, RESERVE, '2026-10-31T10:46:38+00:00', '2026-10-31T10:46:40+00:00'))
            db.execute("UPDATE budget SET blocked='http_rejected'")
            db.commit()

    def test_explicit_second_forfeit_preserves_first_and_all_confirmed_spend(self):
        self.second_incident()
        before = self.query('SELECT * FROM calls')
        first = self.query('SELECT * FROM budget_forfeitures WHERE call_id=1')
        first_audit = next(self.audit_dir.glob('000001-*.json')).read_bytes()
        preview = self.tool.forfeit(call_id=24)
        self.assertEqual(31844358, preview['projected_available_micros'])
        self.assertCode('exact_pending_call_required', lambda: self.tool.forfeit(call_id=2, operator_confirmed=True, apply=True))
        self.assertCode('forfeit_already_accounted', self.apply_forfeit)
        result = self.tool.forfeit(call_id=24, operator_confirmed=True, apply=True)
        self.assertEqual(24, result['call_id'])
        self.assertEqual(31844358, result['available_micros'])
        self.assertEqual(before, self.query('SELECT * FROM calls'))
        self.assertEqual(first, self.query('SELECT * FROM budget_forfeitures WHERE call_id=1'))
        self.assertEqual(first_audit, next(self.audit_dir.glob('000001-*.json')).read_bytes())
        self.assertEqual(1, len(list(self.audit_dir.glob('000024-*.json'))))
        self.assertEqual([], self.transport.calls)
        with self.broker() as broker:
            summary = broker.summary()
            self.assertEqual(70442, summary['spent_micros'])
            self.assertEqual(18085200, summary['forfeited_micros'])
            self.assertEqual(0, summary['held_micros'])
            self.assertEqual(31844358, summary['available_micros'])
        self.assertCode('forfeit_already_accounted', lambda: self.tool.forfeit(call_id=24, operator_confirmed=True, apply=True))

    def test_both_forfeits_remain_deducted_in_future_broker_runs(self):
        self.second_incident()
        self.tool.forfeit(call_id=24, operator_confirmed=True, apply=True)
        transport = FakeTransport()
        transport.endpoint['pricing'] = {'prompt': '0.03', 'completion': '0'}
        transport.receipt['usage']['cost'] = 30
        transport.billing['total_cost'] = 30
        with self.broker(transport) as broker:
            broker.call(BODY)
            self.assertEqual(1844358, broker.summary()['available_micros'])
        later = FakeTransport()
        later.endpoint = copy.deepcopy(transport.endpoint)
        with self.broker(later) as broker:
            self.assertCode('budget_exhausted', lambda: broker.call(BODY))
        self.assertEqual([], later.posts)

    def test_other_uncovered_pending_and_invalid_selection_cannot_unlock(self):
        self.second_incident()
        for selected in (True, 0, -1, '24'):
            self.assertCode('call_id_invalid', lambda: self.tool.forfeit(call_id=selected))
        self.assertCode('exact_pending_call_required', lambda: self.tool.forfeit(call_id=25))
        with closing(sqlite3.connect(self.ledger)) as db:
            db.execute("INSERT INTO calls SELECT 25,request_hash,model,reserved_micros,charged_micros,state,reason,created_at,settled_at FROM calls WHERE id=24")
            db.commit()
        self.assertCode('exact_pending_call_required', lambda: self.tool.forfeit(call_id=24, operator_confirmed=True, apply=True))
        self.assertEqual([(1,)], self.query('SELECT count(*) FROM budget_forfeitures'))
        self.assertEqual([('http_rejected',)], self.query('SELECT blocked FROM budget'))

    def test_invalid_prior_audit_blocks_additional_forfeit(self):
        self.second_incident()
        next(self.audit_dir.glob('000001-*.json')).write_text('{}')
        self.assertCode('forfeit_audit_invalid', lambda: self.tool.forfeit(call_id=24, operator_confirmed=True, apply=True))
        self.assertEqual([(1,)], self.query('SELECT count(*) FROM budget_forfeitures'))

    def test_second_forfeit_cas_observes_previous_completed_rows(self):
        self.second_incident()
        write = self.tool._write_audit
        def changed(audit):
            checksum = write(audit)
            with closing(sqlite3.connect(self.ledger)) as db:
                db.execute('UPDATE calls SET charged_micros=70443 WHERE id=2')
                db.commit()
            return checksum
        with patch.object(self.tool, '_write_audit', side_effect=changed):
            self.assertCode('ledger_changed', lambda: self.tool.forfeit(call_id=24, operator_confirmed=True, apply=True))
        self.assertEqual([(1,)], self.query('SELECT count(*) FROM budget_forfeitures'))
        self.assertEqual([('http_rejected',)], self.query('SELECT blocked FROM budget'))

    def test_second_forfeit_sql_failure_preserves_existing_deduction(self):
        self.second_incident()
        connect = self.tool._connect
        class Failure:
            def __init__(self, db): self.db = db
            def __getattr__(self, name): return getattr(self.db, name)
            def execute(self, sql, parameters=()):
                if sql.startswith('UPDATE budget SET blocked'):
                    raise sqlite3.OperationalError('simulated transaction failure')
                return self.db.execute(sql, parameters)
        with patch.object(self.tool, '_connect', side_effect=lambda writable=False: Failure(connect(True)) if writable else connect()):
            self.assertCode('settlement_failed', lambda: self.tool.forfeit(call_id=24, operator_confirmed=True, apply=True))
        self.assertEqual([(1, RESERVE)], self.query('SELECT call_id,upper_bound_micros FROM budget_forfeitures'))
        self.assertEqual([('http_rejected',)], self.query('SELECT blocked FROM budget'))
        self.assertCode('ledger_requires_reconciliation', self.broker)

    def test_second_deduction_cannot_exceed_total_task_allowance(self):
        self.second_incident()
        with closing(sqlite3.connect(self.ledger)) as db:
            db.execute('UPDATE calls SET reserved_micros=41000000 WHERE id=24')
            db.commit()
        self.assertCode('ledger_integrity_invalid', lambda: self.tool.forfeit(call_id=24, operator_confirmed=True, apply=True))
        self.assertEqual([(1,)], self.query('SELECT count(*) FROM budget_forfeitures'))
        self.assertEqual(1, len(list(self.audit_dir.glob('*.json'))))

    def test_known_cost_uncertain_cannot_be_counted_twice_as_full_forfeit(self):
        self.second_incident()
        with closing(sqlite3.connect(self.ledger)) as db:
            db.execute('UPDATE calls SET charged_micros=100 WHERE id=24')
            db.commit()
        self.assertCode('exact_pending_call_required', lambda: self.tool.forfeit(call_id=24, operator_confirmed=True, apply=True))
        self.assertEqual([(1,)], self.query('SELECT count(*) FROM budget_forfeitures'))
        self.assertEqual([('http_rejected',)], self.query('SELECT blocked FROM budget'))

    def test_noncanonical_production_paths_refused_before_io(self):
        self.assertCode('task_paths_required', lambda: Reconciler(self.ledger, self.key, self.audit_dir))


if __name__ == '__main__':
    unittest.main()