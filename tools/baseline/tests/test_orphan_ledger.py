"""Lost confirmed reply accounting: fake HTTP and private temporary SQLite only."""
from contextlib import closing
import copy
import hashlib
import json
from pathlib import Path
import sqlite3
import sys
import tempfile
import unittest
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
sys.path.insert(0, str(Path(__file__).resolve().parent))
from capture_broker import BrokerError, CaptureBroker, TASK_ROOT, encoded
from orphan_ledger import OrphanDisposer, read_orphan_state, plan_resume, exported_entries
from test_broker import FakeTransport, FAKE_KEY, MODEL, BODY
import capture
import verify


class OrphanTests(unittest.TestCase):
    def setUp(self):
        folder = TASK_ROOT / '.local/orphan-tests';folder.mkdir(exist_ok=True)
        self.temp = tempfile.TemporaryDirectory(dir=folder);self.root = Path(self.temp.name)
        self.ledger = self.root / 'budget.sqlite'
        self.key = self.root / 'fake.key';self.key.write_text(FAKE_KEY, encoding='utf-8')
        self.catalog = self.root / 'catalog.json';self.catalog.write_text(json.dumps({'data': [{'id': MODEL}]}), encoding='utf-8')
        self.transport = FakeTransport();self.brokers = []
        self.network = patch('urllib.request.OpenerDirector.open', side_effect=AssertionError('Real network forbidden'))
        self.network.start()
        broker = self.broker()
        self.transport.receipt['id'] = self.transport.billing['id'] = 'gen-offline-prefix'
        first = broker.call(BODY)
        self.transport.receipt['id'] = self.transport.billing['id'] = 'gen-offline-lost'
        self.lost = broker.call(BODY)
        broker.close()
        self.record = self.record_for(first, 1)
        self.source = self.root / 'source';self.source.mkdir()
        (self.source / 'inventory.json').write_text('{"synthetic":true}', encoding='utf-8')
        self.resume = {'source': self.source, 'records': [self.record], 'run_id': 'test_orphan',
                       'inventory': verify.files(self.source), 'sidecar_hash': None,
                       'frontier': {'case_id': 'manual.orphan', 'count': 1,
                                    'next_request': {'request_sha256': 'a'*64, 'request_body_sha256': verify.digest_value(BODY)}}}
        self.tool = OrphanDisposer(self.ledger, prepare=lambda source: copy.deepcopy(self.resume))

    def record_for(self, result, sequence):
        return {'request_sha256': 'a'*64, 'evidence': {'request_body': result['request_body'], 'budget': result['budget'],
                'case_id': 'manual.orphan', 'sequence': sequence, 'provider_request_id': result['data']['id']}}

    def broker(self):
        broker = CaptureBroker(self.key, self.catalog, self.ledger, transport=self.transport)
        self.brokers.append(broker);broker.preflight([MODEL]);return broker

    def tearDown(self):
        for broker in self.brokers:broker.close()
        self.network.stop();self.temp.cleanup()

    def query(self, sql):
        with closing(sqlite3.connect(self.ledger)) as db:return db.execute(sql).fetchall()

    def assertCode(self, code, operation):
        with self.assertRaises(BrokerError) as caught:operation()
        self.assertEqual(code, caught.exception.code)

    def apply(self):
        return self.tool.dispose(self.source, 2, recovery_unavailable=True, apply=True)

    def test_preview_is_read_only_and_apply_preserves_all_charges(self):
        before = self.ledger.read_bytes();calls = self.query('SELECT * FROM calls')
        preview = self.tool.dispose(self.source, 2, recovery_unavailable=True)
        self.assertEqual('orphan_preview', preview['result']);self.assertEqual(before,self.ledger.read_bytes())
        result = self.apply()
        self.assertEqual(calls, self.query('SELECT * FROM calls'))
        self.assertEqual(100,result['charged_micros']);self.assertFalse(result['response_recovered'])
        self.assertEqual([(200,)],self.query('SELECT SUM(charged_micros) FROM calls'))
        with closing(sqlite3.connect(self.ledger)) as db:
            audits, bindings = read_orphan_state(db,self.ledger)
            self.assertEqual({2},set(audits));self.assertEqual({},bindings)
            audit = audits[2]['audit'];self.assertFalse(audit['replay_eligible'])
            self.assertNotIn(FAKE_KEY,json.dumps(audit));self.assertNotIn('Offline test',json.dumps(audit))
            context = plan_resume(db,self.ledger,self.resume,[1])
        self.assertEqual(result['replacement_context'],context)
        self.assertEqual(context,capture.validate_resume_ledger(self.ledger,self.resume))

    def test_replacement_is_one_new_charge_and_one_atomic_binding(self):
        context = self.apply()['replacement_context']
        # Match RecordedClient prefix_ready's PHP-canonical body proof, rather
        # than the broker's intentionally different physical-wire ledger hash.
        self.assertEqual(verify.digest_value(BODY), context['request_body_sha256'])
        self.assertNotEqual(hashlib.sha256(encoded(BODY)).hexdigest(), context['request_body_sha256'])
        self.transport.receipt['id'] = self.transport.billing['id'] = 'gen-offline-replacement'
        broker = self.broker();original = self.query('SELECT * FROM calls WHERE id=2')
        result = broker.call(BODY,replacement=context)
        self.assertEqual(original,self.query('SELECT * FROM calls WHERE id=2'))
        self.assertEqual(3,result['budget']['call_id'])
        self.assertEqual([(2,3)],self.query('SELECT orphan_call_id,replacement_call_id FROM capture_orphan_replacements'))
        self.assertEqual(300,broker.summary()['spent_micros'])
        self.assertEqual(49999700,broker.summary()['available_micros'])
        posts = len(self.transport.posts)
        self.assertCode('orphan_replacement_invalid',lambda:broker.call(BODY,replacement=context))
        self.assertEqual(posts,len(self.transport.posts));self.assertEqual(3,broker.summary()['calls'])
        self.resume['records'].append(self.record_for(result,2));self.resume['frontier'] = None
        self.assertIsNone(capture.validate_resume_ledger(self.ledger,self.resume))
        entries = exported_entries(broker._connection,self.ledger,'test_orphan',self.resume['records'])
        self.assertEqual(1,len(entries));self.assertEqual(3,entries[0][1])
        self.assertNotIn('key_fingerprint_sha256',entries[0][0]);self.assertNotIn('ledger_before_sha256',entries[0][0])
        self.assertEqual(100,entries[0][0]['charged_micros'])

    def test_foreign_frontier_or_run_is_rejected_without_post(self):
        context = self.apply()['replacement_context']
        for field,value in [('case_id','manual.other'),('count',8),('next_request',{'request_sha256':'b'*64,'request_body_sha256':'c'*64})]:
            altered = copy.deepcopy(self.resume);altered['frontier'][field] = value
            with closing(sqlite3.connect(self.ledger)) as db:
                self.assertCode('orphan_frontier_mismatch',lambda:plan_resume(db,self.ledger,altered,[1]))
        altered=copy.deepcopy(self.resume);altered['run_id']='test_other'
        with closing(sqlite3.connect(self.ledger)) as db:self.assertCode('orphan_run_mismatch',lambda:plan_resume(db,self.ledger,altered,[1]))
        broker=self.broker();posts=len(self.transport.posts)
        changed=copy.deepcopy(BODY);changed['messages'][0]['content']='Different offline frontier'
        self.assertCode('orphan_frontier_mismatch',lambda:broker.call(changed,replacement=context))
        self.assertCode('orphan_replacement_required',lambda:broker.call(BODY))
        context['sequence'] = 99
        self.assertCode('orphan_replacement_invalid',lambda:broker.call(BODY,replacement=context))
        self.assertEqual(posts,len(self.transport.posts));self.assertEqual(2,broker.summary()['calls'])

    def test_audit_must_be_fsynced_before_db_disposition(self):
        before=self.query('SELECT * FROM calls')
        with patch('orphan_ledger.os.fsync',side_effect=OSError('simulated audit disk error')):
            self.assertCode('orphan_audit_write_failed',self.apply)
        self.assertEqual(before,self.query('SELECT * FROM calls'))
        self.assertEqual([],self.query("SELECT name FROM sqlite_master WHERE name='capture_orphan_dispositions'"))
        with self.assertRaisesRegex(capture.CaptureError,'resume_settled_response_missing'):
            capture.validate_resume_ledger(self.ledger,self.resume)

    def test_audit_before_commit_crash_does_not_allow_replacement(self):
        original=self.tool._write_audit
        def crash(audit):
            original(audit);raise RuntimeError('simulated crash after fsync')
        with patch.object(self.tool,'_write_audit',side_effect=crash):
            with self.assertRaises(RuntimeError):self.apply()
        self.assertEqual(1,len(list((self.root/'orphan-dispositions').glob('*.json'))))
        self.assertEqual([],self.query("SELECT name FROM sqlite_master WHERE name='capture_orphan_dispositions'"))
        with self.assertRaisesRegex(capture.CaptureError,'resume_settled_response_missing'):
            capture.validate_resume_ledger(self.ledger,self.resume)

    def test_snapshot_cas_catches_out_of_lock_writer(self):
        original=self.tool._write_audit
        def race(audit):
            result=original(audit)
            with closing(sqlite3.connect(self.ledger)) as db:
                db.execute("UPDATE calls SET reason='external-state-change' WHERE id=1");db.commit()
            return result
        with patch.object(self.tool,'_write_audit',side_effect=race):self.assertCode('ledger_changed',self.apply)
        self.assertEqual([],self.query("SELECT name FROM sqlite_master WHERE name='capture_orphan_dispositions'"))
        self.assertEqual([('completed',100)],self.query('SELECT state,charged_micros FROM calls WHERE id=2'))

    def test_bind_failure_rolls_back_new_reservation_before_post(self):
        context=self.apply()['replacement_context'];broker=self.broker();posts=len(self.transport.posts)
        from orphan_ledger import bind_replacement
        def fail(*args):
            bind_replacement(*args);raise RuntimeError('simulated failure before commit')
        with patch('orphan_ledger.bind_replacement',side_effect=fail):
            with self.assertRaises(RuntimeError):broker.call(BODY,replacement=context)
        self.assertEqual(posts,len(self.transport.posts));self.assertEqual(2,broker.summary()['calls'])
        self.assertEqual([],self.query('SELECT * FROM capture_orphan_replacements'))

    def test_crash_after_reservation_commit_never_repeats_post(self):
        context=self.apply()['replacement_context'];broker=self.broker();posts=len(self.transport.posts)
        with patch.object(broker,'_checkpoint',side_effect=KeyboardInterrupt('simulated power loss')):
            with self.assertRaises(KeyboardInterrupt):broker.call(BODY,replacement=context)
        self.assertEqual(posts,len(self.transport.posts));self.assertEqual([('reserved',None)],self.query('SELECT state,charged_micros FROM calls WHERE id=3'))
        self.assertEqual([(2,3)],self.query('SELECT orphan_call_id,replacement_call_id FROM capture_orphan_replacements'))
        self.assertCode('ledger_requires_reconciliation',lambda:broker.call(BODY,replacement=context))

    def test_lost_settled_replacement_refuses_unexportable_retry_chain(self):
        context=self.apply()['replacement_context'];broker=self.broker()
        self.transport.receipt['id']=self.transport.billing['id']='gen-offline-lost-again'
        broker.call(BODY,replacement=context);broker.close()
        with self.assertRaisesRegex(capture.CaptureError,'resume_settled_response_missing'):
            capture.validate_resume_ledger(self.ledger,self.resume)
        self.assertCode('orphan_already_disposed',self.apply)
        before=self.query('SELECT * FROM calls')
        self.assertCode('orphan_replacement_unresolved',lambda:self.tool.dispose(self.source,3,recovery_unavailable=True,apply=True))
        self.assertEqual(before,self.query('SELECT * FROM calls'))
        self.assertEqual([(300,)],self.query('SELECT SUM(charged_micros) FROM calls'))

    def test_tampered_or_missing_receipt_and_audit_fail_closed(self):
        self.apply()
        with closing(sqlite3.connect(self.ledger)) as db:audits,_=read_orphan_state(db,self.ledger)
        audit_path=audits[2]['path'];original=audit_path.read_bytes()
        audit_path.write_bytes(original+b' ')
        with closing(sqlite3.connect(self.ledger)) as db:self.assertCode('orphan_audit_invalid',lambda:read_orphan_state(db,self.ledger))
        audit_path.write_bytes(original)
        receipt=Path(str(self.ledger)+'.checkpoints')/'000002.receipt.json';receipt.unlink()
        with closing(sqlite3.connect(self.ledger)) as db:self.assertCode('orphan_audit_invalid',lambda:read_orphan_state(db,self.ledger))

    def test_disposition_requires_explicit_recovery_attestation_and_exact_gap(self):
        self.assertCode('orphan_recovery_attestation_required',lambda:self.tool.dispose(self.source,2,apply=True))
        self.assertCode('orphan_exact_missing_call_required',lambda:self.tool.dispose(self.source,1,recovery_unavailable=True))
        self.resume['records']=[]
        self.assertCode('orphan_exact_missing_call_required',self.apply)

    def test_append_only_disposition_and_binding_trigger_guards(self):
        context=self.apply()['replacement_context'];broker=self.broker()
        self.transport.receipt['id']=self.transport.billing['id']='gen-offline-replacement'
        broker.call(BODY,replacement=context)
        for table in ['capture_orphan_dispositions','capture_orphan_replacements']:
            for statement in [f'DELETE FROM {table}',f'UPDATE {table} SET rowid=rowid']:
                with closing(sqlite3.connect(self.ledger)) as db:
                    with self.assertRaises(sqlite3.IntegrityError):db.execute(statement)
        self.assertEqual(300,broker.summary()['spent_micros'])



    def test_wrong_trigger_sql_is_rejected(self):
        self.apply()
        with closing(sqlite3.connect(self.ledger)) as db:
            db.execute('DROP TRIGGER capture_orphan_dispositions_no_delete')
            db.execute('CREATE TRIGGER capture_orphan_dispositions_no_delete BEFORE DELETE ON capture_orphan_dispositions BEGIN SELECT 1; END')
            db.commit()
            self.assertCode('orphan_audit_invalid',lambda:read_orphan_state(db,self.ledger))
        with self.assertRaisesRegex(BrokerError,'orphan_audit_invalid'):self.broker()


if __name__=='__main__':unittest.main()