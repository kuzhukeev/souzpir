"""Explicit operator accounting for uncertain SP-T-03 calls.

Only GET generation metadata is implemented. A provider generation ID must come
from the user's OpenRouter Logs; metadata does not prove the original request
hash. Neither reconciliation nor conservative forfeit recreates a lost reply.
"""
from __future__ import annotations

import argparse
from datetime import datetime, timedelta, timezone
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import sqlite3

from capture_broker import BASE, CANONICAL_LEDGER, TASK_ROOT, BrokerError, CaptureBroker, MODELS, _HttpTransport, _ProcessLock, decimal, encoded, micros

KEY_FILE = TASK_ROOT / '.local/secrets/openrouter.key'
AUDIT_DIR = CANONICAL_LEDGER.parent / 'reconciliation'
MODEL = 'anthropic/claude-sonnet-5.5'
PROVIDER = 'Anthropic'
LIMIT = 50000000
CLOCK_SKEW_SECONDS = 1
# Receipt-owned IDs tolerate a small provider/host clock difference. This never
# expands the creation window to the later billing-poll failure timestamp.
CHECKPOINT_CLOCK_SKEW_SECONDS = 5
FORFEIT_REASON = 'operator_conservative_forfeit'
RECONCILE_REASON = 'reconciled_operator_generation_metadata'
CHECKPOINT_REASON = 'reconciled_checkpoint_generation_metadata'
RECONCILIATION_TABLE = 'budget_reconciliations'
PROVIDERS = {model: ('Anthropic' if model.startswith('anthropic/') else 'Google AI Studio') for model in MODELS}


def _sha(value):
    return isinstance(value, str) and re.fullmatch('[0-9a-f]{64}', value) is not None


def _read_checkpoint(path):
    if path.is_symlink() or not path.is_file() or path.stat().st_size > 65536:
        raise BrokerError('reconciliation_checkpoint_invalid')
    raw = path.read_bytes()
    try:
        data = json.loads(raw)
    except (ValueError, UnicodeError):
        raise BrokerError('reconciliation_checkpoint_invalid') from None
    return data, hashlib.sha256(raw).hexdigest()


def validate_checkpoint(checkpoint, call):
    if not isinstance(checkpoint, dict) or set(checkpoint) != {'reservation_sha256', 'receipt_sha256', 'reservation', 'receipt'}:
        raise BrokerError('reconciliation_checkpoint_invalid')
    if not all(_sha(checkpoint[k]) for k in ('reservation_sha256', 'receipt_sha256')):
        raise BrokerError('reconciliation_checkpoint_invalid')
    reservation, receipt = checkpoint['reservation'], checkpoint['receipt']
    common = {'schema_version': 1, 'call_id': call['id'], 'request_sha256': call['request_hash'], 'model_requested': call['model'], 'replay_eligible': False}
    for record in (reservation, receipt):
        if not isinstance(record, dict) or any(encoded(record.get(k)) != encoded(v) for k, v in common.items()):
            raise BrokerError('reconciliation_checkpoint_invalid')
    if (set(reservation) != set(common) | {'recorded_at', 'reserved_micros'}
        or type(reservation['reserved_micros']) is not int or reservation['reserved_micros'] != call['reserved_micros']
        or set(receipt) != set(common) | {'recorded_at', 'generation_id', 'response_sha256', 'usage', 'response_retained'}
        or receipt['response_retained'] is not False or not _sha(receipt['response_sha256'])
        or not isinstance(receipt['generation_id'], str) or not re.fullmatch('gen-[A-Za-z0-9_-]{1,200}', receipt['generation_id'])):
        raise BrokerError('reconciliation_checkpoint_invalid')
    usage = receipt['usage']
    if (not isinstance(usage, dict) or set(usage) != {'prompt_tokens', 'completion_tokens', 'total_tokens', 'cost_usd'}
        or any(type(usage[k]) is not int or not 0 <= usage[k] <= 1000000000 for k in ('prompt_tokens', 'completion_tokens', 'total_tokens'))
        or usage['total_tokens'] != usage['prompt_tokens'] + usage['completion_tokens']
        or micros(decimal(usage['cost_usd'], 'reconciliation_checkpoint_invalid')) > call['reserved_micros']):
        raise BrokerError('reconciliation_checkpoint_invalid')
    start, reserved, received, held = map(timestamp, (call['created_at'], reservation['recorded_at'], receipt['recorded_at'], call['settled_at']))
    if not start <= reserved <= received <= held or received - start > timedelta(minutes=5):
        raise BrokerError('reconciliation_checkpoint_time_invalid')
    return receipt


def checkpoint_metadata(data, call, checkpoint, clock_skew_seconds=CHECKPOINT_CLOCK_SKEW_SECONDS):
    """Pure validation used both before settlement and on every later ledger read."""
    receipt = validate_checkpoint(checkpoint, call)
    if (not isinstance(data, dict) or data.get('generation_id') != receipt['generation_id']
        or type(data.get('is_byok')) is not bool):
        raise BrokerError('generation_metadata_invalid')
    fields = {'generation_id', 'created_at', 'model', 'provider', 'is_byok', 'openrouter_cost_usd', 'receipt_cost_usd', 'counted_upstream_cost_usd', 'confirmed_cost_micros', 'tokens_prompt', 'tokens_completion', 'native_tokens_prompt', 'native_tokens_completion'}
    if set(data) != fields:
        raise BrokerError('generation_metadata_invalid')
    if (data['provider'] != PROVIDERS.get(call['model']) or not isinstance(data['model'], str)
        or not re.fullmatch(re.escape(call['model']) + r'(?:-\d{8})?', data['model'])):
        raise BrokerError('generation_identity_mismatch')
    created = timestamp(data['created_at'])
    if type(clock_skew_seconds) is not int or clock_skew_seconds not in (1, CHECKPOINT_CLOCK_SKEW_SECONDS):
        raise BrokerError('generation_clock_skew_invalid')
    skew = timedelta(seconds=clock_skew_seconds)
    if not timestamp(call['created_at']) - skew <= created <= timestamp(receipt['recorded_at']) + skew:
        raise BrokerError('generation_time_mismatch')
    for field in ('tokens_prompt', 'tokens_completion'):
        if type(data[field]) is not int or not 0 <= data[field] <= 1000000000:
            raise BrokerError('generation_tokens_invalid')
    native = [data['native_tokens_prompt'], data['native_tokens_completion']]
    if native != [None, None] and any(type(v) is not int or not 0 <= v <= 1000000000 for v in native):
        raise BrokerError('generation_tokens_invalid')
    tokens = native if native != [None, None] else [data['tokens_prompt'], data['tokens_completion']]
    if tokens != [receipt['usage']['prompt_tokens'], receipt['usage']['completion_tokens']]:
        raise BrokerError('generation_receipt_tokens_mismatch')
    cost = decimal(data['openrouter_cost_usd'], 'generation_cost_invalid')
    reported = decimal(data['receipt_cost_usd'], 'generation_cost_invalid')
    if reported != decimal(receipt['usage']['cost_usd']) or abs(cost - reported) > decimal('0.000001'):
        raise BrokerError('generation_receipt_cost_mismatch')
    total = max(cost, reported)
    if data['is_byok']:
        total += decimal(data['counted_upstream_cost_usd'], 'byok_cost_invalid')
    elif data['counted_upstream_cost_usd'] is not None:
        raise BrokerError('byok_cost_invalid')
    charge = micros(total)
    if type(data['confirmed_cost_micros']) is not int or data['confirmed_cost_micros'] != charge:
        raise BrokerError('generation_cost_invalid')
    if charge > call['reserved_micros'] or charge > LIMIT:
        raise BrokerError('reconciliation_exceeds_reserve')
    return charge



def timestamp(value):
    if not isinstance(value, str):
        raise BrokerError('timestamp_invalid')
    try:
        result = datetime.fromisoformat(value.replace('Z', '+00:00'))
    except ValueError:
        raise BrokerError('timestamp_invalid') from None
    if result.utcoffset() is None:
        raise BrokerError('timestamp_invalid')
    return result.astimezone(timezone.utc)


def digest(value):
    return hashlib.sha256(encoded(value)).hexdigest()


def read_reconciliations(db, ledger, limit_micros):
    """Validate append-only settlement references and their durable audit files."""
    try:
        reasons = (RECONCILE_REASON, CHECKPOINT_REASON)
        settled = {r[0] for r in db.execute('SELECT id FROM calls WHERE reason IN (?,?)', reasons)}
        if not db.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?", (RECONCILIATION_TABLE,)).fetchone():
            if settled:
                raise BrokerError('reconciliation_audit_invalid')
            return {}
        if limit_micros != LIMIT or [r[1] for r in db.execute('PRAGMA table_info(budget_reconciliations)')] != ['call_id', 'audit_sha256', 'decided_at']:
            raise BrokerError('reconciliation_audit_invalid')
        triggers = dict(db.execute("SELECT name,sql FROM sqlite_master WHERE type='trigger' AND tbl_name=?", (RECONCILIATION_TABLE,)))
        for action in ('UPDATE', 'DELETE'):
            sql = f"CREATE TRIGGER budget_reconciliations_no_{action.lower()} BEFORE {action} ON budget_reconciliations BEGIN SELECT RAISE(ABORT,'immutable budget reconciliation'); END"
            if ' '.join(triggers.get('budget_reconciliations_no_' + action.lower(), '').split()) != ' '.join(sql.split()):
                raise BrokerError('reconciliation_audit_invalid')
        budget = db.execute('SELECT limit_micros,key_hash FROM budget WHERE id=1').fetchone()
        if budget is None or budget[0] != LIMIT or not _sha(budget[1]):
            raise BrokerError('reconciliation_audit_invalid')
        columns = ['id', 'request_hash', 'model', 'reserved_micros', 'charged_micros', 'state', 'reason', 'created_at', 'settled_at']
        result = {}
        for call_id, checksum, decided in db.execute('SELECT call_id,audit_sha256,decided_at FROM budget_reconciliations'):
            if type(call_id) is not int or call_id < 1 or not _sha(checksum):
                raise BrokerError('reconciliation_audit_invalid')
            path = Path(ledger).parent / 'reconciliation' / f'{call_id:06d}-reconcile-{checksum}.json'
            if path.is_symlink() or not path.is_file() or path.stat().st_size > 131072:
                raise BrokerError('reconciliation_audit_invalid')
            raw = path.read_bytes(); audit = json.loads(raw)
            if hashlib.sha256(raw).hexdigest() != checksum or not isinstance(audit, dict):
                raise BrokerError('reconciliation_audit_invalid')
            row = db.execute('SELECT ' + ','.join(columns) + ' FROM calls WHERE id=?', (call_id,)).fetchone()
            original = audit.get('original_call')
            if row is None or not isinstance(original, dict) or set(original) != set(columns):
                raise BrokerError('reconciliation_audit_invalid')
            kind = audit.get('kind')
            skew = audit.get('clock_skew_seconds')
            if type(skew) is not int or skew not in ((1, CHECKPOINT_CLOCK_SKEW_SECONDS) if kind == CHECKPOINT_REASON else (CLOCK_SKEW_SECONDS,)):
                raise BrokerError('reconciliation_audit_invalid')
            common = {'schema_version': 2 if kind == CHECKPOINT_REASON else 1, 'kind': kind, 'call_id': call_id,
                      'decided_at': decided, 'request_sha256': row[1], 'key_fingerprint_sha256': budget[1],
                      'upper_bound_micros': row[3], 'task_limit_micros': LIMIT, 'response_retained': False,
                      'replay_evidence_created': False, 'actual_cost_confirmed': True,
                      'provider_confirmed_request_hash': False, 'clock_skew_seconds': skew,
                      'request_link_basis': ('durable_receipt_checkpoint_and_matching_generation_metadata' if kind == CHECKPOINT_REASON else 'operator_selected_openrouter_logs_id_and_matching_metadata')}
            fields = set(common) | {'ledger_before_sha256', 'original_call', 'metadata'} | ({'checkpoint'} if kind == CHECKPOINT_REASON else set())
            if kind not in reasons or set(audit) != fields or any(encoded(audit.get(k)) != encoded(v) for k, v in common.items()) or not _sha(audit['ledger_before_sha256']):
                raise BrokerError('reconciliation_audit_invalid')
            if (original['id'] != call_id or original['model'] not in MODELS or original['state'] != 'uncertain'
                or original['charged_micros'] is not None or original['reason'] != 'generation_billing_unknown'
                or type(original['reserved_micros']) is not int or not 0 < original['reserved_micros'] <= LIMIT
                or timestamp(original['settled_at']) < timestamp(original['created_at'])
                or timestamp(decided) < timestamp(original['settled_at'])):
                raise BrokerError('reconciliation_audit_invalid')
            if kind == CHECKPOINT_REASON:
                checkpoint = audit['checkpoint']
                charge = checkpoint_metadata(audit['metadata'], original, checkpoint, skew)
                directory = Path(str(ledger) + '.checkpoints')
                if directory.is_symlink() or directory.resolve().parent != Path(ledger).resolve().parent:
                    raise BrokerError('reconciliation_audit_invalid')
                for label in ('reservation', 'receipt'):
                    value, check = _read_checkpoint(directory / f'{call_id:06d}.{label}.json')
                    if check != checkpoint[label + '_sha256'] or encoded(value) != encoded(checkpoint[label]):
                        raise BrokerError('reconciliation_audit_invalid')
            else:
                metadata = audit['metadata']
                fields = {'generation_id', 'created_at', 'model', 'provider', 'is_byok', 'openrouter_cost_usd', 'counted_upstream_cost_usd', 'confirmed_cost_micros', 'tokens_prompt', 'tokens_completion'}
                if (call_id != 1 or original['model'] != MODEL or not isinstance(metadata, dict) or set(metadata) != fields
                    or type(metadata['is_byok']) is not bool or metadata['provider'] != PROVIDER
                    or not isinstance(metadata['model'], str) or not re.fullmatch(re.escape(MODEL) + r'(?:-\d{8})?', metadata['model'])
                    or not re.fullmatch('gen-[A-Za-z0-9_-]{1,200}', metadata['generation_id'])
                    or any(type(metadata[k]) is not int or metadata[k] < 0 for k in ('tokens_prompt', 'tokens_completion'))):
                    raise BrokerError('reconciliation_audit_invalid')
                start, held = timestamp(original['created_at']), timestamp(original['settled_at'])
                skew = timedelta(seconds=CLOCK_SKEW_SECONDS)
                if held - start > timedelta(minutes=5) or not start - skew <= timestamp(metadata['created_at']) <= held + skew:
                    raise BrokerError('reconciliation_audit_invalid')
                cost = decimal(metadata['openrouter_cost_usd'])
                if metadata['is_byok']:
                    cost += decimal(metadata['counted_upstream_cost_usd'])
                elif metadata['counted_upstream_cost_usd'] is not None:
                    raise BrokerError('reconciliation_audit_invalid')
                charge = micros(cost)
                if type(metadata['confirmed_cost_micros']) is not int or metadata['confirmed_cost_micros'] != charge:
                    raise BrokerError('reconciliation_audit_invalid')
            expected = dict(original, state='completed', charged_micros=charge, reason=kind, settled_at=decided)
            if encoded(dict(zip(columns, row))) != encoded(expected) or charge > row[3]:
                raise BrokerError('reconciliation_audit_invalid')
            result[call_id] = {'audit': audit, 'sha256': checksum, 'path': path}
        if set(result) != settled:
            raise BrokerError('reconciliation_audit_invalid')
        return result
    except BrokerError as error:
        if error.code == 'reconciliation_audit_invalid':
            raise
        raise BrokerError('reconciliation_audit_invalid') from None
    except Exception:
        raise BrokerError('reconciliation_audit_invalid') from None


class Reconciler:
    def __init__(self, ledger_path=CANONICAL_LEDGER, key_file=KEY_FILE, audit_dir=AUDIT_DIR, *, transport=None):
        self.ledger = Path(ledger_path).resolve()
        self.key_file = Path(key_file).resolve()
        self.audit_dir = Path(audit_dir).resolve()
        if transport is None and (self.ledger != CANONICAL_LEDGER.resolve() or self.key_file != KEY_FILE.resolve() or self.audit_dir != AUDIT_DIR.resolve()):
            raise BrokerError('task_paths_required')
        if not all(path.is_relative_to((TASK_ROOT / '.local').resolve()) for path in [self.ledger, self.key_file, self.audit_dir]):
            raise BrokerError('task_paths_required')
        if self.audit_dir != self.ledger.parent / 'reconciliation':
            raise BrokerError('audit_path_invalid')
        self.transport = transport if transport is not None else _HttpTransport()

    def _lock(self):
        marker = Path(str(self.ledger) + '.lock')
        if not self.ledger.is_file() or not marker.is_file() or self.ledger.stat().st_size == 0 or marker.stat().st_size == 0:
            raise BrokerError('ledger_state_missing')
        return _ProcessLock(marker)

    def _connect(self, writable=False):
        mode = 'rw' if writable else 'ro'
        db = sqlite3.connect(self.ledger.as_uri() + '?mode=' + mode, uri=True, isolation_level=None, timeout=1)
        if not writable:
            db.execute('PRAGMA query_only=ON')
        else:
            db.execute('PRAGMA synchronous=FULL')
        return db

    def _snapshot(self, db, call_id=1):
        if db.execute('PRAGMA quick_check').fetchall() != [('ok',)]:
            raise BrokerError('ledger_integrity_invalid')
        budget_columns = ['id', 'limit_micros', 'key_hash', 'blocked']
        call_columns = ['id', 'request_hash', 'model', 'reserved_micros', 'charged_micros', 'state', 'reason', 'created_at', 'settled_at']
        if [r[1] for r in db.execute('PRAGMA table_info(budget)')] != budget_columns or [r[1] for r in db.execute('PRAGMA table_info(calls)')] != call_columns:
            raise BrokerError('ledger_schema_invalid')
        budgets = db.execute('SELECT id,limit_micros,key_hash,blocked FROM budget').fetchall()
        calls = db.execute('SELECT id,request_hash,model,reserved_micros,charged_micros,state,reason,created_at,settled_at FROM calls ORDER BY id').fetchall()
        if len(budgets) != 1 or budgets[0][0] != 1 or budgets[0][1] != LIMIT:
            raise BrokerError('pending_budget_mismatch')
        if type(call_id) is not int or call_id < 1:
            raise BrokerError('call_id_invalid')
        selected = next((row for row in calls if row[0] == call_id), None)
        if selected is None:
            raise BrokerError('exact_pending_call_required')
        for index, row in enumerate(calls, 1):
            identity, request_hash, model, reserve, charged, state = row[:6]
            if identity != index or not isinstance(request_hash, str) or not re.fullmatch('[0-9a-f]{64}', request_hash) or model not in MODELS:
                raise BrokerError('ledger_integrity_invalid')
            if type(reserve) is not int or not 0 < reserve <= LIMIT or state not in {'reserved', 'completed', 'uncertain'}:
                raise BrokerError('ledger_integrity_invalid')
            if charged is not None and (type(charged) is not int or charged < 0):
                raise BrokerError('ledger_integrity_invalid')
            if state == 'completed' and (charged is None or charged > reserve):
                raise BrokerError('ledger_integrity_invalid')
        validated_forfeits = CaptureBroker._read_forfeitures(db, self.ledger, LIMIT)
        reconciliations = read_reconciliations(db, self.ledger, LIMIT)
        if sum(row[4] or 0 for row in calls) + sum(validated_forfeits.values()) > LIMIT:
            raise BrokerError('ledger_integrity_invalid')
        forfeits = []
        if db.execute("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='budget_forfeitures'").fetchone()[0]:
            forfeits = db.execute('SELECT call_id,upper_bound_micros,reason,audit_sha256,decided_at FROM budget_forfeitures ORDER BY call_id').fetchall()
        return {'budget': dict(zip(budget_columns, budgets[0])), 'call': dict(zip(call_columns, selected)), 'calls': [dict(zip(call_columns, row)) for row in calls], 'forfeitures': forfeits,
                'reconciliations': {str(k): v['sha256'] for k, v in reconciliations.items()}}

    @staticmethod
    def _pending(snapshot, forfeit=False, checkpoint=False):
        budget, call = snapshot['budget'], snapshot['call']
        accounted = {row[0] for row in snapshot['forfeitures']}
        if call['id'] in accounted:
            raise BrokerError('forfeit_already_accounted')
        if call['state'] != 'uncertain' or call['charged_micros'] is not None or not isinstance(call['reason'], str) or not re.fullmatch('[a-z_]{1,80}', call['reason']):
            raise BrokerError('exact_pending_call_required')
        if budget['id'] != 1 or budget['limit_micros'] != LIMIT or budget['blocked'] != call['reason']:
            raise BrokerError('pending_budget_mismatch')
        unresolved = [row['id'] for row in snapshot['calls'] if row['state'] != 'completed' and row['id'] not in accounted]
        if unresolved != [call['id']]:
            raise BrokerError('exact_pending_call_required')
        if not forfeit and (call['reason'] != 'generation_billing_unknown' or (not checkpoint and (len(snapshot['calls']) != 1 or call['id'] != 1 or call['model'] != MODEL))):
            raise BrokerError('exact_pending_call_required')
        if sum(row['charged_micros'] or 0 for row in snapshot['calls']) + sum(row[1] for row in snapshot['forfeitures']) + call['reserved_micros'] > LIMIT:
            raise BrokerError('ledger_integrity_invalid')
        if type(call['reserved_micros']) is not int or not 0 < call['reserved_micros'] <= LIMIT:
            raise BrokerError('reservation_invalid')
        if not isinstance(call['request_hash'], str) or not re.fullmatch('[0-9a-f]{64}', call['request_hash']):
            raise BrokerError('request_hash_invalid')
        if not isinstance(budget['key_hash'], str) or not re.fullmatch('[0-9a-f]{64}', budget['key_hash']):
            raise BrokerError('key_fingerprint_invalid')
        start, held = timestamp(call['created_at']), timestamp(call['settled_at'])
        if held < start or (not forfeit and not checkpoint and held - start > timedelta(minutes=5)):
            raise BrokerError('reservation_time_invalid')
        return start, held

    def _load(self, call_id=1, forfeit=False, checkpoint=False):
        db = self._connect()
        try:
            snapshot = self._snapshot(db, call_id)
            self._pending(snapshot, forfeit, checkpoint)
            return snapshot
        finally:
            db.close()

    def _key(self, snapshot):
        try:
            if not self.key_file.is_file() or self.key_file.is_symlink() or self.key_file.stat().st_size > 1024:
                raise BrokerError('credential_file_invalid')
            key = self.key_file.read_text(encoding='utf-8').strip()
            if not re.fullmatch('sk-or-v1-[A-Za-z0-9_-]{32,200}', key):
                raise BrokerError('credential_format_invalid')
            if not hmac.compare_digest(hashlib.sha256(key.encode()).hexdigest(), snapshot['budget']['key_hash']):
                raise BrokerError('credential_change_refused')
            return key
        except BrokerError:
            raise
        except Exception:
            raise BrokerError('credential_file_invalid') from None

    def _metadata(self, generation_id, snapshot):
        key = self._key(snapshot)
        if key in generation_id:
            raise BrokerError('metadata_contains_secret')
        try:
            response = self.transport.request('GET', BASE + '/generation?id=' + generation_id,
                                              {'Accept': 'application/json', 'Accept-Encoding': 'identity', 'Authorization': 'Bearer ' + key})
            if key.encode() in encoded(response):
                raise BrokerError('metadata_contains_secret')
        except BrokerError:
            raise
        except Exception:
            raise BrokerError('metadata_unavailable') from None
        data = response.get('data') if isinstance(response, dict) else None
        if not isinstance(data, dict) or data.get('id') != generation_id or type(data.get('is_byok')) is not bool:
            raise BrokerError('generation_metadata_invalid')
        if data.get('provider_name') != PROVIDER or not isinstance(data.get('model'), str) or not re.fullmatch(re.escape(MODEL) + r'(?:-\d{8})?', data['model']):
            raise BrokerError('generation_identity_mismatch')
        start, held = self._pending(snapshot)
        created = timestamp(data.get('created_at'))
        skew = timedelta(seconds=CLOCK_SKEW_SECONDS)
        if not start - skew <= created <= held + skew:
            raise BrokerError('generation_time_mismatch')
        for name in ['tokens_prompt', 'tokens_completion']:
            if type(data.get(name)) is not int or data[name] < 0:
                raise BrokerError('generation_tokens_invalid')
        for name in ['num_fetches', 'num_input_audio_prompt', 'num_media_completion', 'num_media_prompt', 'num_search_results']:
            if data.get(name) not in (None, 0):
                raise BrokerError('generation_addon_mismatch')
        if data.get('preset_id') is not None or data.get('router') is not None:
            raise BrokerError('generation_routing_mismatch')
        cost = decimal(data.get('total_cost'), 'generation_cost_invalid')
        upstream = None
        if data['is_byok']:
            upstream = decimal(data.get('upstream_inference_cost'), 'byok_cost_invalid')
            cost += upstream
        charge = micros(cost)
        if charge > snapshot['call']['reserved_micros'] or charge > LIMIT:
            raise BrokerError('reconciliation_exceeds_reserve')
        return {'generation_id': generation_id, 'created_at': created.isoformat(), 'model': data['model'], 'provider': data['provider_name'],
                'is_byok': data['is_byok'], 'openrouter_cost_usd': str(decimal(data['total_cost'])),
                'counted_upstream_cost_usd': str(upstream) if upstream is not None else None,
                'confirmed_cost_micros': charge, 'tokens_prompt': data['tokens_prompt'], 'tokens_completion': data['tokens_completion']}

    def _checkpoint(self, snapshot):
        call = snapshot['call']
        directory = Path(str(self.ledger) + '.checkpoints')
        if directory.is_symlink() or directory.resolve().parent != self.ledger.parent:
            raise BrokerError('reconciliation_checkpoint_invalid')
        result = {}
        for label in ('reservation', 'receipt'):
            result[label], result[label + '_sha256'] = _read_checkpoint(directory / f"{call['id']:06d}.{label}.json")
        validate_checkpoint(result, call)
        return result

    def _checkpoint_metadata(self, snapshot, checkpoint):
        receipt = validate_checkpoint(checkpoint, snapshot['call'])
        generation_id = receipt['generation_id']
        key = self._key(snapshot)
        if key.encode() in encoded(checkpoint):
            raise BrokerError('checkpoint_contains_secret')
        try:
            response = self.transport.request('GET', BASE + '/generation?id=' + generation_id,
                                              {'Accept': 'application/json', 'Accept-Encoding': 'identity', 'Authorization': 'Bearer ' + key})
            if key.encode() in encoded(response):
                raise BrokerError('metadata_contains_secret')
        except BrokerError:
            raise
        except Exception:
            raise BrokerError('metadata_unavailable') from None
        data = response.get('data') if isinstance(response, dict) else None
        if not isinstance(data, dict) or data.get('id') != generation_id or type(data.get('is_byok')) is not bool:
            raise BrokerError('generation_metadata_invalid')
        for name in ('num_fetches', 'num_input_audio_prompt', 'num_media_completion', 'num_media_prompt', 'num_search_results'):
            if data.get(name) not in (None, 0):
                raise BrokerError('generation_addon_mismatch')
        if data.get('preset_id') is not None or data.get('router') is not None:
            raise BrokerError('generation_routing_mismatch')
        cost = decimal(data.get('total_cost'), 'generation_cost_invalid')
        reported = decimal(receipt['usage']['cost_usd'], 'generation_cost_invalid')
        upstream = decimal(data.get('upstream_inference_cost'), 'byok_cost_invalid') if data['is_byok'] else None
        metadata = {'generation_id': generation_id, 'created_at': timestamp(data.get('created_at')).isoformat(),
                    'model': data.get('model'), 'provider': data.get('provider_name'), 'is_byok': data['is_byok'],
                    'openrouter_cost_usd': str(cost), 'receipt_cost_usd': str(reported),
                    'counted_upstream_cost_usd': str(upstream) if upstream is not None else None,
                    'confirmed_cost_micros': micros(max(cost, reported) + (upstream or 0)),
                    **{k: data.get(k) for k in ('tokens_prompt', 'tokens_completion', 'native_tokens_prompt', 'native_tokens_completion')}}
        checkpoint_metadata(metadata, snapshot['call'], checkpoint)
        return metadata

    def _audit(self, snapshot, kind, facts):
        return {'schema_version': 1, 'kind': kind, 'call_id': snapshot['call']['id'], 'decided_at': datetime.now(timezone.utc).isoformat(),
                'request_sha256': snapshot['call']['request_hash'], 'key_fingerprint_sha256': snapshot['budget']['key_hash'],
                'ledger_before_sha256': digest(snapshot), 'original_call': snapshot['call'],
                'upper_bound_micros': snapshot['call']['reserved_micros'], 'task_limit_micros': LIMIT,
                'response_retained': False, 'replay_evidence_created': False, **facts}

    def _write_audit(self, audit):
        raw = encoded(audit) + b'\n'
        checksum = hashlib.sha256(raw).hexdigest()
        kind = 'forfeit' if audit['kind'] == FORFEIT_REASON else 'reconcile'
        self.audit_dir.mkdir(parents=True, exist_ok=True)
        path = self.audit_dir / (str(audit['call_id']).zfill(6) + '-' + kind + '-' + checksum + '.json')
        try:
            with path.open('xb') as file:
                file.write(raw)
                file.flush()
                os.fsync(file.fileno())
        except FileExistsError:
            if path.read_bytes() != raw:
                raise BrokerError('audit_collision') from None
        except Exception:
            raise BrokerError('audit_write_failed') from None
        return checksum

    def _apply(self, original, audit, charge=None):
        checksum = self._write_audit(audit)  # Durable evidence precedes any settlement.
        db = self._connect(writable=True)
        try:
            db.execute('BEGIN IMMEDIATE')
            if digest(self._snapshot(db, original['call']['id'])) != digest(original):
                raise BrokerError('ledger_changed')
            if audit['kind'] == FORFEIT_REASON:
                if not db.execute("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='budget_forfeitures'").fetchone()[0]:
                    # execute individual statements: executescript would implicitly commit.
                    db.execute('CREATE TABLE budget_forfeitures (call_id INTEGER PRIMARY KEY, upper_bound_micros INTEGER NOT NULL CHECK(upper_bound_micros>0), reason TEXT NOT NULL, audit_sha256 TEXT NOT NULL, decided_at TEXT NOT NULL)')
                    db.execute("CREATE TRIGGER budget_forfeitures_no_update BEFORE UPDATE ON budget_forfeitures BEGIN SELECT RAISE(ABORT,'immutable budget forfeit'); END")
                    db.execute("CREATE TRIGGER budget_forfeitures_no_delete BEFORE DELETE ON budget_forfeitures BEGIN SELECT RAISE(ABORT,'immutable budget forfeit'); END")
                db.execute('INSERT INTO budget_forfeitures(call_id,upper_bound_micros,reason,audit_sha256,decided_at) VALUES(?,?,?,?,?)',
                           (original['call']['id'], original['call']['reserved_micros'], FORFEIT_REASON, checksum, audit['decided_at']))
            else:
                if audit['kind'] == CHECKPOINT_REASON and encoded(self._checkpoint(original)) != encoded(audit['checkpoint']):
                    raise BrokerError('reconciliation_checkpoint_changed')
                if not db.execute("SELECT 1 FROM sqlite_master WHERE type='table' AND name='budget_reconciliations'").fetchone():
                    db.execute('CREATE TABLE budget_reconciliations(call_id INTEGER PRIMARY KEY,audit_sha256 TEXT NOT NULL,decided_at TEXT NOT NULL)')
                    for action in ('UPDATE', 'DELETE'):
                        db.execute(f"CREATE TRIGGER budget_reconciliations_no_{action.lower()} BEFORE {action} ON budget_reconciliations BEGIN SELECT RAISE(ABORT,'immutable budget reconciliation'); END")
                result = db.execute("UPDATE calls SET state='completed',charged_micros=?,reason=?,settled_at=? WHERE id=? AND state='uncertain' AND charged_micros IS NULL AND reason=?", (charge, audit['kind'], audit['decided_at'], original['call']['id'], original['call']['reason']))
                if result.rowcount != 1:
                    raise BrokerError('ledger_changed')
                db.execute('INSERT INTO budget_reconciliations VALUES(?,?,?)', (original['call']['id'], checksum, audit['decided_at']))
                read_reconciliations(db, self.ledger, LIMIT)
            changed = db.execute("UPDATE budget SET blocked=NULL WHERE id=1 AND blocked=?", (original['budget']['blocked'],))
            if changed.rowcount != 1:
                raise BrokerError('ledger_changed')
            db.commit()
        except BrokerError:
            db.rollback()
            raise
        except Exception:
            db.rollback()
            raise BrokerError('settlement_failed') from None
        finally:
            db.close()
        return checksum

    def reconcile(self, generation_id, *, from_openrouter_logs=False, apply=False):
        if from_openrouter_logs is not True:
            raise BrokerError('operator_logs_id_required')
        if not isinstance(generation_id, str) or not re.fullmatch('gen-[A-Za-z0-9_-]{1,200}', generation_id):
            raise BrokerError('generation_id_invalid')
        lock = self._lock()
        try:
            original = self._load()
            metadata = self._metadata(generation_id, original)
            audit = self._audit(original, RECONCILE_REASON, {'actual_cost_confirmed': True, 'metadata': metadata,
                'request_link_basis': 'operator_selected_openrouter_logs_id_and_matching_metadata',
                'provider_confirmed_request_hash': False, 'clock_skew_seconds': CLOCK_SKEW_SECONDS})
            checksum = self._apply(original, audit, metadata['confirmed_cost_micros']) if apply else None
            return {'result': 'reconciled' if apply else 'validated_preview', 'call_id': 1,
                    'confirmed_cost_micros': metadata['confirmed_cost_micros'], ('available_micros' if apply else 'projected_available_micros'): self._available(original, metadata['confirmed_cost_micros']),
                    'ledger_changed': apply, 'audit_sha256': checksum, 'response_recovered': False,
                    'request_link_basis': audit['request_link_basis'], 'provider_confirmed_request_hash': False}
        finally:
            lock.close()

    @staticmethod
    def _available(snapshot, charge):
        return LIMIT - sum(row['charged_micros'] or 0 for row in snapshot['calls']) - sum(row[1] for row in snapshot['forfeitures']) - charge

    def reconcile_checkpoint(self, *, call_id, apply=False):
        """GET-only settlement of the exact ID durably captured before polling."""
        lock = self._lock()
        try:
            original = self._load(call_id, checkpoint=True)
            checkpoint = self._checkpoint(original)
            metadata = self._checkpoint_metadata(original, checkpoint)
            audit = self._audit(original, CHECKPOINT_REASON, {
                'schema_version': 2, 'actual_cost_confirmed': True, 'metadata': metadata, 'checkpoint': checkpoint,
                'request_link_basis': 'durable_receipt_checkpoint_and_matching_generation_metadata',
                'provider_confirmed_request_hash': False, 'clock_skew_seconds': CHECKPOINT_CLOCK_SKEW_SECONDS})
            checksum = self._apply(original, audit, metadata['confirmed_cost_micros']) if apply else None
            return {'result': 'reconciled' if apply else 'validated_preview', 'call_id': call_id,
                    'confirmed_cost_micros': metadata['confirmed_cost_micros'],
                    ('available_micros' if apply else 'projected_available_micros'): self._available(original, metadata['confirmed_cost_micros']),
                    'ledger_changed': apply, 'audit_sha256': checksum, 'response_recovered': False,
                    'request_link_basis': audit['request_link_basis'], 'provider_confirmed_request_hash': False}
        finally:
            lock.close()

    def forfeit(self, *, call_id=1, operator_confirmed=False, apply=False):
        if apply and operator_confirmed is not True:
            raise BrokerError('explicit_forfeit_choice_required')
        lock = self._lock()
        try:
            original = self._load(call_id, forfeit=True)
            amount = original['call']['reserved_micros']
            previous = sum(row['charged_micros'] or 0 for row in original['calls']) + sum(row[1] for row in original['forfeitures'])
            audit = self._audit(original, FORFEIT_REASON, {'actual_cost_confirmed': False,
                'decision_basis': 'explicit_operator_choice_full_uncertain_reserve_irrecoverable_deduction',
                'forfeited_micros': amount, 'confirmed_cost_micros': None})
            checksum = self._apply(original, audit) if apply else None
            return {'result': 'conservatively_forfeited' if apply else 'forfeit_preview', 'call_id': call_id,
                    'forfeited_micros': amount, 'confirmed_cost_micros': None, ('available_micros' if apply else 'projected_available_micros'): LIMIT - previous - amount,
                    'actual_cost_confirmed': False, 'ledger_changed': apply, 'audit_sha256': checksum, 'response_recovered': False}
        finally:
            lock.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--generation-id')
    mode.add_argument('--conservative-forfeit', action='store_true')
    mode.add_argument('--from-checkpoint', action='store_true')
    parser.add_argument('--from-openrouter-logs', action='store_true')
    parser.add_argument('--apply', action='store_true')
    parser.add_argument('--call-id', type=int, default=1, help='Exact uncertain call to conservatively forfeit; never auto-selected.')
    args = parser.parse_args()
    try:
        if not args.conservative_forfeit and not args.from_checkpoint and args.call_id != 1:
            raise BrokerError('metadata_reconciliation_first_call_only')
        reconciler = Reconciler()
        result = (reconciler.reconcile_checkpoint(call_id=args.call_id, apply=args.apply) if args.from_checkpoint else
                  reconciler.forfeit(call_id=args.call_id, operator_confirmed=args.conservative_forfeit, apply=args.apply) if args.conservative_forfeit else
                  reconciler.reconcile(args.generation_id, from_openrouter_logs=args.from_openrouter_logs, apply=args.apply))
        print(json.dumps(result, ensure_ascii=False))
        return 0
    except BrokerError as error:
        print(json.dumps({'result': 'refused', 'code': error.code}))
        return 2
    except Exception:
        print(json.dumps({'result': 'refused', 'code': 'reconciliation_failed'}))
        return 2


if __name__ == '__main__':
    raise SystemExit(main())
