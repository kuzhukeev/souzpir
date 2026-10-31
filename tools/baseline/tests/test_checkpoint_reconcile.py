"""Checkpoint reconciliation: private SQLite, fake metadata, never live HTTP."""
from contextlib import closing
import copy
import hashlib
import json
from pathlib import Path
import sqlite3
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
sys.path.insert(0, str(Path(__file__).resolve().parent))
from capture_broker import BrokerError, CaptureBroker, encoded
from reconcile import CHECKPOINT_REASON, LIMIT, read_reconciliations, checkpoint_metadata
import test_reconcile as legacy
from test_reconcile import MODEL, GENERATION, RESERVE
from test_broker import FAKE_KEY


class CheckpointReconcileTests(unittest.TestCase):
    setUp = legacy.ReconcileTests.setUp
    tearDown = legacy.ReconcileTests.tearDown
    query = legacy.ReconcileTests.query
    assertCode = legacy.ReconcileTests.assertCode
    broker = legacy.ReconcileTests.broker

    def incident(self):
        self.tool.forfeit(operator_confirmed=True, apply=True)
        self.old_forfeit = next(self.audit_dir.glob('*forfeit*')).read_bytes()
        with closing(sqlite3.connect(self.ledger)) as db:
            db.execute("INSERT INTO calls VALUES(2,?,?,?,1234,'completed',NULL,?,?)", ('b'*64, MODEL, RESERVE, '2026-10-31T10:14:10+00:00', '2026-10-31T10:14:11+00:00'))
            db.execute("INSERT INTO calls VALUES(3,?,?,?,NULL,'uncertain','generation_billing_unknown',?,?)", ('c'*64, MODEL, RESERVE, '2026-10-31T10:15:00+00:00', '2026-10-31T10:30:00+00:00'))
            db.execute("UPDATE budget SET blocked='generation_billing_unknown'")
            db.commit()
        directory = Path(str(self.ledger)+'.checkpoints'); directory.mkdir()
        self.reservation = {'schema_version': 1, 'call_id': 3, 'recorded_at': '2026-10-31T10:15:00.1+00:00', 'request_sha256': 'c'*64, 'model_requested': MODEL, 'reserved_micros': RESERVE, 'replay_eligible': False}
        self.receipt = {'schema_version': 1, 'call_id': 3, 'recorded_at': '2026-10-31T10:15:03+00:00', 'request_sha256': 'c'*64, 'model_requested': MODEL, 'generation_id': GENERATION, 'response_sha256': 'd'*64, 'usage': {'prompt_tokens': 1000, 'completion_tokens': 200, 'total_tokens': 1200, 'cost_usd': '0.0123456'}, 'response_retained': False, 'replay_eligible': False}
        self.transport.data['created_at'] = '2026-10-31T10:15:01+00:00'
        self.write_checkpoints()

    def write_checkpoints(self):
        directory = Path(str(self.ledger)+'.checkpoints')
        for name, value in [('reservation', self.reservation), ('receipt', self.receipt)]:
            (directory/f'000003.{name}.json').write_bytes(encoded(value)+b'\n')

    def run_reconcile(self, apply=True):
        return self.tool.reconcile_checkpoint(call_id=3, apply=apply)

    def assertHeld(self):
        self.assertEqual([('uncertain', None, 'generation_billing_unknown')], self.query('SELECT state,charged_micros,reason FROM calls WHERE id=3'))
        self.assertEqual([('generation_billing_unknown',)], self.query('SELECT blocked FROM budget'))
        self.assertEqual(self.old_forfeit, next(self.audit_dir.glob('*forfeit*')).read_bytes())

    def test_preview_uses_checkpoint_id_and_all_previous_deductions(self):
        self.incident(); before = self.ledger.read_bytes()
        result = self.run_reconcile(False)
        self.assertEqual(12346, result['confirmed_cost_micros'])
        self.assertEqual(LIMIT-RESERVE-1234-12346, result['projected_available_micros'])
        self.assertEqual(before, self.ledger.read_bytes())
        self.assertEqual(1, len(self.transport.calls)); self.assertHeld()

    def test_apply_is_immutable_audit_then_atomic_ref_and_exact_charge(self):
        self.incident()
        result = self.run_reconcile()
        self.assertEqual(LIMIT-RESERVE-1234-12346, result['available_micros'])
        self.assertEqual([('completed', 12346, CHECKPOINT_REASON)], self.query('SELECT state,charged_micros,reason FROM calls WHERE id=3'))
        with closing(sqlite3.connect(self.ledger)) as db:
            state = read_reconciliations(db, self.ledger, LIMIT)
            self.assertEqual({3}, set(state))
            self.assertEqual(2, state[3]['audit']['schema_version'])
            self.assertEqual(result['audit_sha256'], state[3]['sha256'])
            for sql in ('UPDATE budget_reconciliations SET audit_sha256=\'x\'', 'DELETE FROM budget_reconciliations'):
                with self.assertRaises(sqlite3.IntegrityError): db.execute(sql)
        self.assertEqual(self.old_forfeit, next(self.audit_dir.glob('*forfeit*')).read_bytes())
        with self.broker() as broker:
            self.assertEqual(result['available_micros'], broker.summary()['available_micros'])
        self.assertCode('exact_pending_call_required', self.run_reconcile)

    def test_byok_native_tokens_and_receipt_rounding_are_conservative(self):
        self.incident()
        self.transport.data.update(is_byok=True, upstream_inference_cost='0.02', total_cost='0.012345', tokens_prompt=950, tokens_completion=190, native_tokens_prompt=1000, native_tokens_completion=200)
        self.assertEqual(32346, self.run_reconcile()['confirmed_cost_micros'])

    def test_late_poll_window_does_not_expand_generation_identity_time(self):
        self.incident(); self.transport.data['created_at']='2026-10-31T10:29:00+00:00'
        self.assertCode('generation_time_mismatch', self.run_reconcile); self.assertHeld()

    def test_five_second_checkpoint_clock_skew_has_exact_closed_boundaries(self):
        self.incident()
        for created in ('2026-10-31T10:14:55+00:00', '2026-10-31T10:14:58.932+00:00', '2026-10-31T10:15:08+00:00'):
            with self.subTest(created=created):
                self.transport.data['created_at']=created
                self.assertEqual('validated_preview',self.run_reconcile(False)['result']);self.assertHeld()
        for created in ('2026-10-31T10:14:54.999999+00:00', '2026-10-31T10:15:08.000001+00:00'):
            with self.subTest(created=created):
                self.transport.data['created_at']=created
                self.assertCode('generation_time_mismatch',lambda:self.run_reconcile(False));self.assertHeld()

    def test_recorded_one_second_checkpoint_audit_remains_valid_and_strict(self):
        self.incident();original=self.tool._load(3,checkpoint=True);cp=self.tool._checkpoint(original)
        metadata=self.tool._checkpoint_metadata(original,cp)
        audit=self.tool._audit(original,CHECKPOINT_REASON,{'schema_version':2,'actual_cost_confirmed':True,'metadata':metadata,'checkpoint':cp,
            'request_link_basis':'durable_receipt_checkpoint_and_matching_generation_metadata','provider_confirmed_request_hash':False,'clock_skew_seconds':1})
        self.tool._apply(original,audit,metadata['confirmed_cost_micros'])
        with closing(sqlite3.connect(self.ledger)) as db:
            self.assertEqual(1,read_reconciliations(db,self.ledger,LIMIT)[3]['audit']['clock_skew_seconds'])
        metadata['created_at']='2026-10-31T10:14:58.932+00:00'
        self.assertEqual(12346,checkpoint_metadata(metadata,original['call'],cp,5))
        self.assertCode('generation_time_mismatch',lambda:checkpoint_metadata(metadata,original['call'],cp,1))

    def test_unknown_or_boolean_recorded_clock_skew_is_rejected(self):
        self.incident();original=self.tool._load(3,checkpoint=True);cp=self.tool._checkpoint(original)
        metadata=self.tool._checkpoint_metadata(original,cp)
        for skew in (True,0,2,6,900,'5'):
            with self.subTest(skew=skew):
                self.assertCode('generation_clock_skew_invalid',lambda:checkpoint_metadata(metadata,original['call'],cp,skew))
        self.assertHeld()

    def test_metadata_identity_cost_token_routing_checks_preserve_hold(self):
        self.incident(); original=copy.deepcopy(self.transport.data)
        for field, value, code in [
            ('id', 'gen-other', 'generation_metadata_invalid'),
            ('model', 'google/gemini-2.5-flash', 'generation_identity_mismatch'),
            ('provider_name', 'Other', 'generation_identity_mismatch'),
            ('is_byok', None, 'generation_metadata_invalid'),
            ('total_cost', '0.1', 'generation_receipt_cost_mismatch'),
            ('total_cost', None, 'generation_cost_invalid'),
            ('tokens_prompt', 1001, 'generation_receipt_tokens_mismatch'),
            ('native_tokens_prompt', 1000, 'generation_tokens_invalid'),
            ('num_search_results', 1, 'generation_addon_mismatch'),
            ('router', {}, 'generation_routing_mismatch')]:
            with self.subTest(field=field):
                self.transport.data=copy.deepcopy(original);self.transport.data[field]=value
                self.assertCode(code, self.run_reconcile);self.assertHeld()

    def test_missing_or_wrong_checkpoint_refuses_before_get(self):
        self.incident(); self.receipt['request_sha256']='e'*64;self.write_checkpoints()
        self.assertCode('reconciliation_checkpoint_invalid', self.run_reconcile)
        self.assertEqual([], self.transport.calls);self.assertHeld()

    def test_missing_native_and_nominal_tokens_cannot_release_reserve(self):
        self.incident();self.transport.data.pop('tokens_completion')
        self.assertCode('generation_tokens_invalid', self.run_reconcile);self.assertHeld()

    def test_forfeited_call_cannot_be_reconciled_or_refunded(self):
        self.incident()
        self.assertCode('forfeit_already_accounted', lambda:self.tool.reconcile_checkpoint(call_id=1,apply=True))
        self.assertEqual([],self.transport.calls);self.assertHeld()

    def test_audit_failure_and_checkpoint_change_cannot_settle(self):
        self.incident()
        with patch.object(self.tool,'_write_audit',side_effect=BrokerError('audit_write_failed')):
            self.assertCode('audit_write_failed',self.run_reconcile)
        self.assertHeld()
        self.transport.callback=lambda:(self.receipt.update(response_sha256='e'*64),self.write_checkpoints())
        self.assertCode('reconciliation_checkpoint_changed',self.run_reconcile);self.assertHeld()

    def test_cas_includes_earlier_charges(self):
        self.incident()
        def changed():
            with closing(sqlite3.connect(self.ledger)) as db:
                db.execute('UPDATE calls SET charged_micros=1235 WHERE id=2');db.commit()
        self.transport.callback=changed
        self.assertCode('ledger_changed',self.run_reconcile);self.assertHeld()

    def test_sql_failure_rolls_back_charge_ref_and_unblock(self):
        self.incident();connect=self.tool._connect
        class Failure:
            def __init__(self,db):self.db=db
            def __getattr__(self,name):return getattr(self.db,name)
            def execute(self,sql,params=()):
                if sql.startswith('UPDATE budget SET blocked'):raise sqlite3.OperationalError('fake failure')
                return self.db.execute(sql,params)
        with patch.object(self.tool,'_connect',side_effect=lambda writable=False:Failure(connect(writable)) if writable else connect(False)):
            self.assertCode('settlement_failed',self.run_reconcile)
        self.assertHeld();self.assertEqual([],self.query("SELECT name FROM sqlite_master WHERE name='budget_reconciliations'"))

    def test_audit_missing_or_tampered_ref_blocks_future_broker(self):
        self.incident();self.run_reconcile()
        audit=next(self.audit_dir.glob('*reconcile*'));audit.write_bytes(b'{}')
        self.assertCode('reconciliation_audit_invalid',lambda:self.broker())

    def test_deleted_table_or_fake_trigger_cannot_erase_settlement_proof(self):
        self.incident();self.run_reconcile()
        with closing(sqlite3.connect(self.ledger)) as db:
            db.execute('DROP TRIGGER budget_reconciliations_no_update')
            db.execute('CREATE TRIGGER budget_reconciliations_no_update BEFORE UPDATE ON budget_reconciliations BEGIN SELECT 1; END');db.commit()
            self.assertCode('reconciliation_audit_invalid',lambda:read_reconciliations(db,self.ledger,LIMIT))
            db.execute('DROP TABLE budget_reconciliations');db.commit()
            self.assertCode('reconciliation_audit_invalid',lambda:read_reconciliations(db,self.ledger,LIMIT))

    def test_credential_fingerprint_and_reflections_never_leak_or_get_logged(self):
        self.incident(); self.key.write_text('sk-or-v1-'+'different_offline_only_key_value_00000',encoding='utf-8')
        self.assertCode('credential_change_refused',self.run_reconcile)
        self.assertEqual([],self.transport.calls)
        self.key.write_text(FAKE_KEY,encoding='utf-8')
        self.receipt['generation_id']='gen-'+FAKE_KEY;self.write_checkpoints()
        self.assertCode('checkpoint_contains_secret',self.run_reconcile)
        self.assertEqual([],self.transport.calls)
        self.receipt['generation_id']=GENERATION;self.write_checkpoints()
        self.transport.data['ignored_untrusted_field']=FAKE_KEY
        self.assertCode('metadata_contains_secret',self.run_reconcile)
        self.assertFalse(list(self.audit_dir.glob('*reconcile*')));self.assertHeld()

    def test_durable_audit_precedes_db_transaction_and_crash_keeps_hold(self):
        self.incident();connect=self.tool._connect;writes=[]
        def failed(writable=False):
            if writable:
                files=list(self.audit_dir.glob('*reconcile*'));self.assertEqual(1,len(files))
                self.assertEqual(3,json.loads(files[0].read_text(encoding='utf-8'))['call_id'])
                self.assertHeld();writes.append(1);raise OSError('simulated crash before DB open')
            return connect(False)
        with patch.object(self.tool,'_connect',side_effect=failed):
            with self.assertRaises(OSError):self.run_reconcile()
        self.assertEqual([1],writes);self.assertHeld()

    def test_process_lock_refuses_concurrent_reconciler_before_get(self):
        self.incident();lock=self.tool._lock()
        try:
            self.assertCode('ledger_in_use',self.run_reconcile)
        finally:lock.close()
        self.assertEqual([],self.transport.calls);self.assertHeld()

    def test_unbounded_byok_or_more_than_one_pending_never_unlocks(self):
        self.incident();self.transport.data.update(is_byok=True,upstream_inference_cost='10')
        self.assertCode('reconciliation_exceeds_reserve',self.run_reconcile);self.assertHeld()
        with closing(sqlite3.connect(self.ledger)) as db:
            db.execute('INSERT INTO calls SELECT 4,request_hash,model,reserved_micros,charged_micros,state,reason,created_at,settled_at FROM calls WHERE id=3');db.commit()
        self.transport.calls.clear()
        self.assertCode('exact_pending_call_required',self.run_reconcile)
        self.assertEqual([],self.transport.calls);self.assertHeld()


if __name__=='__main__':unittest.main()
