"""Explicit, audited retirement of a confirmed paid reply that was not retained.

This never changes calls or their charged amount, never reconstructs a response,
and never makes HTTP requests. A replacement is one new ordinary paid reservation.
"""
from __future__ import annotations
import argparse
from contextlib import closing
import hashlib
import json
import os
from pathlib import Path
import re
import sqlite3
from datetime import datetime

from capture_broker import (BrokerError, CANONICAL_LEDGER, TASK_ROOT, MODELS,
                            CaptureBroker, _ProcessLock, encoded, utc_now, decimal)

KIND = 'confirmed_response_lost'
REASON = 'normalization_failure_response_not_retained'
BILLING_REASON = 'billing_metadata_unavailable_response_not_retained'
SETTLEMENT_KIND = 'reconciled_checkpoint_generation_metadata'
LIMIT = 50000000
TABLES = {'capture_orphan_dispositions', 'capture_orphan_replacements'}


def checksum(value):
    return hashlib.sha256(encoded(value)).hexdigest()


def _sha(value):
    return isinstance(value, str) and re.fullmatch('[0-9a-f]{64}', value) is not None


def _read_json(path, limit=131072):
    if path.is_symlink() or not path.is_file() or path.stat().st_size > limit:
        raise BrokerError('orphan_audit_invalid')
    raw = path.read_bytes()
    return json.loads(raw), hashlib.sha256(raw).hexdigest()



def _time(value):
    if not isinstance(value, str):
        raise BrokerError('orphan_audit_invalid')
    parsed = datetime.fromisoformat(value.replace('Z', '+00:00'))
    if parsed.tzinfo is None:
        raise BrokerError('orphan_audit_invalid')
    return parsed


def _receipt_projection(receipt, call_id, row):
    fields = {'schema_version', 'call_id', 'recorded_at', 'generation_id', 'request_sha256', 'response_sha256',
              'model_requested', 'usage', 'response_retained', 'replay_eligible'}
    if not isinstance(receipt, dict) or set(receipt) != fields or type(receipt['schema_version']) is not int or receipt['schema_version'] != 1:
        raise BrokerError('orphan_receipt_invalid')
    usage = receipt['usage']
    if (not isinstance(usage, dict) or set(usage) != {'prompt_tokens', 'completion_tokens', 'total_tokens', 'cost_usd'}
        or any(type(usage[key]) is not int or usage[key] < 0 for key in ('prompt_tokens', 'completion_tokens', 'total_tokens'))
        or usage['total_tokens'] != usage['prompt_tokens'] + usage['completion_tokens']
        or decimal(usage['cost_usd'], 'orphan_receipt_invalid') > decimal(row[3], 'orphan_receipt_invalid') / 1000000):
        raise BrokerError('orphan_receipt_invalid')
    if (type(receipt['call_id']) is not int or receipt['call_id'] != call_id or receipt['request_sha256'] != row[0]
        or receipt['model_requested'] != row[1] or receipt['response_retained'] is not False or receipt['replay_eligible'] is not False
        or not _sha(receipt['response_sha256']) or not isinstance(receipt['generation_id'], str)
        or not re.fullmatch('gen-[A-Za-z0-9_-]{1,200}', receipt['generation_id'])):
        raise BrokerError('orphan_receipt_invalid')
    _time(receipt['recorded_at'])
    return {key: receipt[key] for key in ('generation_id', 'response_sha256', 'request_sha256', 'recorded_at')}


def _disposition_origin(reconciliations, call_id, receipt_sha, charged):
    entry = reconciliations.get(call_id)
    if entry is None:
        return REASON, {}
    audit = entry['audit']
    if (audit.get('schema_version') != 2 or audit.get('kind') != SETTLEMENT_KIND
        or audit.get('original_call', {}).get('state') != 'uncertain'
        or audit.get('original_call', {}).get('charged_micros') is not None
        or audit.get('original_call', {}).get('reason') != 'generation_billing_unknown'
        or audit.get('actual_cost_confirmed') is not True
        or audit.get('metadata', {}).get('confirmed_cost_micros') != charged
        or audit.get('checkpoint', {}).get('receipt_sha256') != receipt_sha):
        raise BrokerError('orphan_reconciliation_invalid')
    return BILLING_REASON, {'reconciliation_audit_sha256': entry['sha256']}


def exported_reconciliation(db, ledger, call_id):
    """Return only sanitized durable settlement metadata; never provider content."""
    entries = CaptureBroker._read_reconciliations(db, ledger, LIMIT)
    entry = entries.get(call_id)
    if entry is None or entry['audit'].get('schema_version') != 2 or entry['audit'].get('kind') != SETTLEMENT_KIND:
        raise BrokerError('orphan_reconciliation_invalid')
    public = {key: value for key, value in entry['audit'].items()
              if key not in {'key_fingerprint_sha256', 'ledger_before_sha256'}}
    return {**public, 'source_audit_sha256': entry['sha256']}


def read_orphan_state(db, ledger):
    """Validate durable local state; local hashes are not external attestation."""
    try:
        present = {row[0] for row in db.execute("SELECT name FROM sqlite_master WHERE type='table'")} & TABLES
        if not present:
            return {}, {}
        if present != TABLES:
            raise BrokerError('orphan_audit_invalid')
        columns = {
            'capture_orphan_dispositions': ['call_id', 'audit_sha256', 'decided_at'],
            'capture_orphan_replacements': ['orphan_call_id', 'replacement_call_id', 'request_sha256', 'request_body_sha256', 'created_at'],
        }
        for table, expected in columns.items():
            if [row[1] for row in db.execute('PRAGMA table_info(' + table + ')')] != expected:
                raise BrokerError('orphan_audit_invalid')
            triggers = dict(db.execute("SELECT name,sql FROM sqlite_master WHERE type='trigger' AND tbl_name=?", (table,)))
            for action in ('UPDATE', 'DELETE'):
                expected_sql = f"CREATE TRIGGER {table}_no_{action.lower()} BEFORE {action} ON {table} BEGIN SELECT RAISE(ABORT,'immutable orphan accounting'); END"
                actual_sql = triggers.get(table + '_no_' + action.lower(), '')
                if ' '.join(actual_sql.split()) != ' '.join(expected_sql.split()):
                    raise BrokerError('orphan_audit_invalid')
        budget = db.execute('SELECT limit_micros,key_hash FROM budget WHERE id=1').fetchone()
        if budget is None or budget[0] != LIMIT or not _sha(budget[1]):
            raise BrokerError('orphan_audit_invalid')
        reconciliations = CaptureBroker._read_reconciliations(db, ledger, LIMIT)
        audits, bindings = {}, {}
        for call_id, digest, decided in db.execute('SELECT call_id,audit_sha256,decided_at FROM capture_orphan_dispositions'):
            if type(call_id) is not int or call_id < 1 or not _sha(digest) or call_id in audits:
                raise BrokerError('orphan_audit_invalid')
            path = Path(ledger).parent / 'orphan-dispositions' / f'{call_id:06d}-{digest}.json'
            audit, actual_digest = _read_json(path)
            if actual_digest != digest or not isinstance(audit, dict):
                raise BrokerError('orphan_audit_invalid')
            row = db.execute('SELECT request_hash,model,reserved_micros,charged_micros,state FROM calls WHERE id=?', (call_id,)).fetchone()
            if row is None or row[-1] != 'completed' or type(row[3]) is not int or not 0 <= row[3] <= row[2]:
                raise BrokerError('orphan_audit_invalid')
            reason, origin = _disposition_origin(reconciliations, call_id, audit.get('receipt_checkpoint_sha256'), row[3])
            expected = {'schema_version': 1, 'kind': KIND, 'call_id': call_id, 'request_sha256': row[0],
                        'model': row[1], 'reserved_micros': row[2], 'charged_micros': row[3],
                        'decided_at': decided, 'key_fingerprint_sha256': budget[1], 'reason': reason, **origin,
                        'provider_content_recovery': 'operator_attests_unavailable',
                        'response_retained': False, 'replay_eligible': False, 'replacement_authorized': True}
            if (set(audit) != set(expected) | {'ledger_before_sha256', 'receipt_checkpoint_sha256', 'receipt', 'source'}
                or any(encoded(audit.get(key)) != encoded(value) for key, value in expected.items())):
                raise BrokerError('orphan_audit_invalid')
            if not _sha(audit.get('ledger_before_sha256')) or not _sha(audit.get('receipt_checkpoint_sha256')):
                raise BrokerError('orphan_audit_invalid')
            source = audit.get('source')
            if (not isinstance(source, dict) or set(source) != {'run_id', 'case_id', 'sequence', 'inventory_sha256', 'request_sha256', 'request_body_sha256'}
                or not re.fullmatch('[A-Za-z0-9_-]{1,80}', source.get('run_id', ''))
                or not re.fullmatch('[a-zA-Z0-9_.-]{1,100}', source.get('case_id', ''))
                or type(source.get('sequence')) is not int or not 1 <= source['sequence'] <= 200
                or not all(_sha(source.get(key)) for key in ('inventory_sha256', 'request_sha256', 'request_body_sha256'))):
                raise BrokerError('orphan_audit_invalid')
            receipt_path = Path(str(ledger) + '.checkpoints') / f'{call_id:06d}.receipt.json'
            receipt, receipt_sha = _read_json(receipt_path)
            projected = _receipt_projection(receipt, call_id, row)
            if _time(projected['recorded_at']) > _time(decided):
                raise BrokerError('orphan_audit_invalid')
            if (receipt_sha != audit['receipt_checkpoint_sha256'] or audit.get('receipt') != projected
                or receipt.get('call_id') != call_id or receipt.get('request_sha256') != row[0]
                or receipt.get('model_requested') != row[1] or receipt.get('response_retained') is not False
                or receipt.get('replay_eligible') is not False or not _sha(receipt.get('response_sha256'))
                or not isinstance(receipt.get('generation_id'), str)
                or not re.fullmatch('gen-[A-Za-z0-9_-]{1,200}', receipt['generation_id'])):
                raise BrokerError('orphan_audit_invalid')
            audits[call_id] = {'audit': audit, 'sha256': digest, 'path': path}
        replacement_ids = set()
        for original, replacement, request_hash, body_hash, created in db.execute('SELECT * FROM capture_orphan_replacements'):
            if original in bindings or original not in audits or type(replacement) is not int or replacement <= original or replacement in replacement_ids:
                raise BrokerError('orphan_audit_invalid')
            source = audits[original]['audit']['source']
            row = db.execute('SELECT model FROM calls WHERE id=?', (replacement,)).fetchone()
            if row != (audits[original]['audit']['model'],) or request_hash != source['request_sha256'] or body_hash != source['request_body_sha256']:
                raise BrokerError('orphan_audit_invalid')
            if _time(created) < _time(audits[original]['audit']['decided_at']):
                raise BrokerError('orphan_audit_invalid')
            bindings[original] = replacement
            replacement_ids.add(replacement)
        if set(bindings.values()) & set(audits):
            # Chains need a distinct public provenance schema; never hide an
            # intermediate paid physical request or authorize an unexportable retry.
            raise BrokerError('orphan_replacement_unresolved')
        return audits, bindings
    except BrokerError:
        raise
    except Exception:
        raise BrokerError('orphan_audit_invalid') from None


def replacement_context(audit):
    source = audit['source']
    return {'orphan_call_id': audit['call_id'], 'run_id': source['run_id'], 'case_id': source['case_id'],
            'sequence': source['sequence'], 'request_sha256': source['request_sha256'], 'request_body_sha256': source['request_body_sha256']}


def plan_resume(db, ledger, resume, identities):
    audits, bindings = read_orphan_state(db, ledger)
    missing = {row[0] for row in db.execute("SELECT id FROM calls WHERE state='completed'")} - set(identities)
    if missing - set(audits):
        raise BrokerError('resume_settled_response_missing')
    if set(audits) & set(identities):
        raise BrokerError('orphan_response_cannot_be_consumed')
    pending = []
    for call_id, entry in audits.items():
        audit = entry['audit']
        if audit['source']['run_id'] != resume['run_id']:
            raise BrokerError('orphan_run_mismatch')
        if call_id not in bindings:
            frontier = resume.get('frontier')
            source = audit['source']
            if (frontier is None or frontier['case_id'] != source['case_id'] or frontier['count'] + 1 != source['sequence']
                or frontier['next_request'] != {key: source[key] for key in ('request_sha256', 'request_body_sha256')}):
                raise BrokerError('orphan_frontier_mismatch')
            pending.append(replacement_context(audit))
        elif bindings[call_id] not in identities and bindings[call_id] not in audits:
            raise BrokerError('orphan_replacement_unresolved')
    if len(pending) > 1:
        raise BrokerError('orphan_multiple_replacements_pending')
    return pending[0] if pending else None


def bind_replacement(db, ledger, new_call_id, context):
    """Called inside the broker's reservation transaction, before physical POST."""
    audits, bindings = read_orphan_state(db, ledger)
    pending = set(audits) - set(bindings)
    if context is None:
        if pending:
            raise BrokerError('orphan_replacement_required')
        return
    if not isinstance(context, dict) or type(context.get('orphan_call_id')) is not int:
        raise BrokerError('orphan_replacement_invalid')
    original = context['orphan_call_id']
    if pending != {original} or encoded(context) != encoded(replacement_context(audits[original]['audit'])):
        raise BrokerError('orphan_replacement_invalid')
    row = db.execute('SELECT model FROM calls WHERE id=?', (new_call_id,)).fetchone()
    if new_call_id <= original or row != (audits[original]['audit']['model'],):
        raise BrokerError('orphan_replacement_invalid')
    db.execute('INSERT INTO capture_orphan_replacements VALUES(?,?,?,?,?)',
               (original, new_call_id, context['request_sha256'], context['request_body_sha256'], utc_now()))


def exported_entries(db, ledger, run_id, records):
    audits, bindings = read_orphan_state(db, ledger)
    entries = []
    by_id = {record['evidence']['budget']['call_id']: record for record in records}
    for original, item in sorted(audits.items()):
        audit = item['audit']
        if audit['source']['run_id'] != run_id:
            raise BrokerError('orphan_run_mismatch')
        replacement = bindings.get(original)
        if replacement in audits:
            raise BrokerError('orphan_replacement_unresolved')
        if replacement not in by_id:
            raise BrokerError('orphan_replacement_unresolved')
        record = by_id[replacement]
        evidence, source = record['evidence'], audit['source']
        if (evidence['case_id'] != source['case_id'] or evidence['sequence'] != source['sequence']
            or record['request_sha256'] != source['request_sha256']
            or evidence['provider_request_id'] == audit['receipt']['generation_id']):
            raise BrokerError('orphan_replacement_invalid')
        public = {key: value for key, value in audit.items() if key not in {'key_fingerprint_sha256', 'ledger_before_sha256'}}
        public['source_audit_sha256'] = item['sha256']
        entries.append((public, replacement, record))
    return entries


class OrphanDisposer:
    def __init__(self, ledger=CANONICAL_LEDGER, *, prepare=None):
        self.ledger = Path(ledger).resolve()
        if prepare is None and self.ledger != CANONICAL_LEDGER.resolve():
            raise BrokerError('task_ledger_required')
        if not self.ledger.is_relative_to((TASK_ROOT / '.local').resolve()):
            raise BrokerError('task_ledger_required')
        self.prepare = prepare

    def _connection(self, writable=False):
        db = sqlite3.connect(self.ledger.as_uri() + ('?mode=rw' if writable else '?mode=ro'), uri=True, isolation_level=None)
        db.execute('PRAGMA synchronous=FULL' if writable else 'PRAGMA query_only=ON')
        return db

    def _snapshot(self, db):
        if db.execute('PRAGMA quick_check').fetchall() != [('ok',)]:
            raise BrokerError('ledger_integrity_invalid')
        budget = db.execute('SELECT id,limit_micros,key_hash,blocked FROM budget').fetchall()
        if len(budget) != 1 or budget[0][0:2] != (1, LIMIT) or not _sha(budget[0][2]) or budget[0][3] is not None:
            raise BrokerError('orphan_budget_invalid')
        calls = db.execute('SELECT id,request_hash,model,reserved_micros,charged_micros,state,reason,created_at,settled_at FROM calls ORDER BY id').fetchall()
        forfeits = CaptureBroker._read_forfeitures(db, self.ledger, LIMIT)
        audits, bindings = read_orphan_state(db, self.ledger)
        reconciliations = CaptureBroker._read_reconciliations(db, self.ledger, LIMIT)
        for index, row in enumerate(calls, 1):
            if (row[0] != index or not _sha(row[1]) or row[2] not in MODELS or type(row[3]) is not int or not 0 < row[3] <= LIMIT
                or row[5] not in {'reserved', 'completed', 'uncertain'} or (row[4] is not None and (type(row[4]) is not int or row[4] < 0))
                or (row[5] == 'completed' and (row[4] is None or row[4] > row[3]))):
                raise BrokerError('ledger_integrity_invalid')
        if sum(row[4] or 0 for row in calls) + sum(forfeits.values()) > LIMIT:
            raise BrokerError('ledger_integrity_invalid')
        if any(row[5] != 'completed' and row[0] not in forfeits for row in calls):
            raise BrokerError('ledger_requires_reconciliation')
        return {'budget': budget, 'calls': calls, 'forfeits': forfeits,
                'orphans': {key: value['sha256'] for key, value in audits.items()}, 'bindings': bindings,
                'reconciliations': {key: value['sha256'] for key, value in reconciliations.items()}}

    def _write_audit(self, audit):
        raw = encoded(audit) + b'\n'
        digest = hashlib.sha256(raw).hexdigest()
        directory = self.ledger.parent / 'orphan-dispositions'
        if directory.is_symlink():
            raise BrokerError('orphan_audit_invalid')
        directory.mkdir(exist_ok=True)
        path = directory / f"{audit['call_id']:06d}-{digest}.json"
        try:
            with path.open('xb') as file:
                file.write(raw)
                file.flush()
                os.fsync(file.fileno())
        except Exception:
            raise BrokerError('orphan_audit_write_failed') from None
        return digest

    def dispose(self, source, call_id, *, recovery_unavailable=False, apply=False):
        if recovery_unavailable is not True:
            raise BrokerError('orphan_recovery_attestation_required')
        if type(call_id) is not int or call_id < 1:
            raise BrokerError('call_id_invalid')
        marker = Path(str(self.ledger) + '.lock')
        if not self.ledger.is_file() or not marker.is_file() or not self.ledger.stat().st_size or not marker.stat().st_size:
            raise BrokerError('ledger_state_missing')
        lock = _ProcessLock(marker)
        try:
            import capture
            if self.prepare is None:
                php = Path('C:/OSPanel/modules/PHP-8.4/php.exe')
                prototype = TASK_ROOT / '.local/prototype'
                description = capture.run_child(php, prototype, ['--action=describe'])
                resume = capture.prepare_resume(Path(source), description['cases'], description, php, prototype, False)
            else:
                resume = self.prepare(source)
            with closing(self._connection()) as db:
                snapshot = self._snapshot(db)
                if call_id in snapshot['orphans']:
                    raise BrokerError('orphan_already_disposed')
                if call_id in snapshot['bindings'].values():
                    raise BrokerError('orphan_replacement_unresolved')
                rows = {row[0]: row for row in snapshot['calls']}
                row = rows.get(call_id)
                if row is None or row[5] != 'completed' or row[2] not in MODELS or type(row[4]) is not int or not 0 <= row[4] <= row[3]:
                    raise BrokerError('orphan_confirmed_call_required')
                identities = []
                for record in resume['records']:
                    evidence = record['evidence']; budget = evidence['budget']; identity = budget['call_id']
                    expected = (hashlib.sha256(encoded(evidence['request_body'])).hexdigest(), evidence['request_body']['model'], budget['reserved_micros'], budget['charged_micros'], 'completed')
                    if identity in identities or identity not in rows or rows[identity][1:6] != expected:
                        raise BrokerError('orphan_saved_receipt_invalid')
                    identities.append(identity)
                missing = {r[0] for r in snapshot['calls'] if r[5] == 'completed'} - set(identities) - set(snapshot['orphans'])
                if (missing != {call_id} or identities != sorted(identities)
                    or call_id != max(r[0] for r in snapshot['calls'] if r[5] == 'completed')
                    or set(snapshot['orphans']) - set(snapshot['bindings'])):
                    raise BrokerError('orphan_exact_missing_call_required')
                frontier = resume.get('frontier')
                if frontier is None:
                    raise BrokerError('orphan_frontier_mismatch')
                receipt_path = Path(str(self.ledger) + '.checkpoints') / f'{call_id:06d}.receipt.json'
                receipt, receipt_sha = _read_json(receipt_path)
                _receipt_projection(receipt, call_id, row[1:6])
                if _time(receipt['recorded_at']) < _time(row[7]):
                    raise BrokerError('orphan_receipt_invalid')
                if (receipt.get('call_id') != call_id or receipt.get('request_sha256') != row[1] or receipt.get('model_requested') != row[2]
                    or receipt.get('replay_eligible') is not False or receipt.get('response_retained') is not False
                    or not _sha(receipt.get('response_sha256')) or not isinstance(receipt.get('generation_id'), str)
                    or not re.fullmatch('gen-[A-Za-z0-9_-]{1,200}', receipt['generation_id'])):
                    raise BrokerError('orphan_receipt_invalid')
                reason, origin = _disposition_origin(CaptureBroker._read_reconciliations(db, self.ledger, LIMIT), call_id, receipt_sha, row[4])
            audit = {'schema_version': 1, 'kind': KIND, 'call_id': call_id, 'model': row[2], 'request_sha256': row[1],
                     'reserved_micros': row[3], 'charged_micros': row[4], 'decided_at': utc_now(), 'reason': reason, **origin,
                     'provider_content_recovery': 'operator_attests_unavailable', 'response_retained': False,
                     'replay_eligible': False, 'replacement_authorized': True,
                     'key_fingerprint_sha256': snapshot['budget'][0][2], 'ledger_before_sha256': checksum(snapshot),
                     'receipt_checkpoint_sha256': receipt_sha,
                     'receipt': {key: receipt[key] for key in ('generation_id', 'response_sha256', 'request_sha256', 'recorded_at')},
                     'source': {'run_id': resume['run_id'], 'case_id': frontier['case_id'], 'sequence': frontier['count'] + 1,
                                'inventory_sha256': checksum(resume['inventory']), **frontier['next_request']}}
            digest = None
            if apply:
                capture.validate_resume_source(resume)
                digest = self._write_audit(audit)
                with closing(self._connection(True)) as db:
                    try:
                        db.execute('BEGIN IMMEDIATE')
                        if checksum(self._snapshot(db)) != checksum(snapshot):
                            raise BrokerError('ledger_changed')
                        if not snapshot['orphans'] and not db.execute("SELECT 1 FROM sqlite_master WHERE name='capture_orphan_dispositions'").fetchone():
                            db.execute('CREATE TABLE capture_orphan_dispositions(call_id INTEGER PRIMARY KEY,audit_sha256 TEXT NOT NULL,decided_at TEXT NOT NULL)')
                            db.execute('CREATE TABLE capture_orphan_replacements(orphan_call_id INTEGER PRIMARY KEY,replacement_call_id INTEGER UNIQUE NOT NULL,request_sha256 TEXT NOT NULL,request_body_sha256 TEXT NOT NULL,created_at TEXT NOT NULL)')
                            for table in sorted(TABLES):
                                for action in ('UPDATE', 'DELETE'):
                                    db.execute(f"CREATE TRIGGER {table}_no_{action.lower()} BEFORE {action} ON {table} BEGIN SELECT RAISE(ABORT,'immutable orphan accounting'); END")
                        db.execute('INSERT INTO capture_orphan_dispositions VALUES(?,?,?)', (call_id, digest, audit['decided_at']))
                        read_orphan_state(db, self.ledger)
                        db.commit()
                    except Exception:
                        db.rollback()
                        raise
            return {'result': 'orphan_disposed' if apply else 'orphan_preview', 'call_id': call_id,
                    'charged_micros': row[4], 'ledger_calls_changed': False, 'ledger_changed': apply,
                    'response_recovered': False, 'audit_sha256': digest, 'replacement_context': replacement_context(audit)}
        finally:
            lock.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', type=Path, required=True)
    parser.add_argument('--call-id', type=int, required=True)
    parser.add_argument('--recovery-unavailable', action='store_true')
    parser.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    try:
        result = OrphanDisposer().dispose(args.source, args.call_id, recovery_unavailable=args.recovery_unavailable, apply=args.apply)
        print(json.dumps(result));return 0
    except Exception as error:
        print(json.dumps({'result': 'refused', 'code': error.code if isinstance(error, BrokerError) else 'orphan_disposition_failed'}));return 2


if __name__ == '__main__':
    raise SystemExit(main())