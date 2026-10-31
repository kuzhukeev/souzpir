"""Task-local OpenRouter recorder transport with durable conservative reservations.

No CLI and no implicit live mode. Published endpoint prices are a bound only while
providers honour that tariff. A private BYOK contract or a later undisclosed fee
cannot be independently guaranteed by an application ledger; remote key controls
are complementary. Every unknown outcome permanently holds its reservation.
"""
from __future__ import annotations

import copy
import hashlib
import json
import os
from pathlib import Path
import re
import sqlite3
import ssl
import time
from datetime import datetime, timezone
from decimal import Decimal, InvalidOperation, ROUND_CEILING
import urllib.request
import urllib.error

BASE = 'https://openrouter.ai/api/v1'
MAX_BYTES = 1024 * 1024
MAX_RESPONSE = 8 * MAX_BYTES
MAX_ERROR_RESPONSE = 32768
ERROR_CATEGORIES = {'duplicate_tool_names', 'invalid_tool_schema', 'unsupported_parameter', 'context_length_exceeded', 'unknown'}
TASK_ROOT = Path(__file__).resolve().parents[2]
CANONICAL_LEDGER = TASK_ROOT / '.local/baseline/budget.sqlite'
MAX_USD = Decimal('50')
# One physical POST only. Its reservation stays held while metadata catches up.
# At most 21 GET attempts, each admitted within 15 minutes; longer scheduling
# gaps never exceed 60 seconds. An in-flight GET can outlast this admission window.
BILLING_DELAYS = (0, 1, 2, 4, 8, 15, 30) + (60,) * 14
BILLING_START_WINDOW_SECONDS = 15 * 60
MICROS = Decimal(1000000)
BYOK_FACTOR = Decimal('1.05')  # https://openrouter.ai/pricing/ : maximum published BYOK fee.
MODELS = {'google/gemini-2.5-flash', 'anthropic/claude-sonnet-5.5', 'anthropic/claude-haiku-5.5'}
INPUT_RATES = {'prompt', 'input_cache_read', 'input_cache_write', 'input_cache_write_1h'}
OUTPUT_RATES = {'completion', 'internal_reasoning'}
INACTIVE_RATES = {'image', 'audio', 'input_audio_cache', 'web_search'}
BODY_KEYS = {'model', 'messages', 'temperature', 'max_tokens', 'usage', 'reasoning', 'response_format', 'tools', 'tool_choice', 'provider', 'stream', 'seed', 'top_p', 'stop'}


class BrokerError(RuntimeError):
    """Only stable, non-sensitive codes may leave the broker."""
    def __init__(self, code: str, *, http_status: int | None = None, error_category: str = 'unknown'):
        self.code = code
        self.http_status = http_status if type(http_status) is int and 100 <= http_status <= 599 else None
        self.error_category = error_category if isinstance(error_category, str) and error_category in ERROR_CATEGORIES else 'unknown'
        super().__init__(code)


def utc_now():
    return datetime.now(timezone.utc).isoformat()


def decimal(value, code='invalid_price'):
    if isinstance(value, bool) or not isinstance(value, (str, int, float, Decimal)):
        raise BrokerError(code)
    try:
        number = Decimal(str(value))
    except (InvalidOperation, ValueError):
        raise BrokerError(code) from None
    if not number.is_finite() or number < 0:
        raise BrokerError(code)
    return number


def micros(value):
    return int((decimal(value) * MICROS).to_integral_value(rounding=ROUND_CEILING))


def encoded(value):
    try:
        raw = json.dumps(value, ensure_ascii=False, separators=(',', ':'), sort_keys=True, allow_nan=False).encode('utf-8')
    except (ValueError, TypeError, RecursionError):
        raise BrokerError('invalid_json') from None
    return raw


def _classify_http_error(raw):
    """Diagnostic hint only. Return an enum, never provider text or billing proof."""
    if not isinstance(raw, bytes) or not raw or len(raw) > MAX_ERROR_RESPONSE:
        return 'unknown'
    try:
        root = json.loads(raw)
        snippets = []
        def fields(value):
            if not isinstance(value, dict):
                return None
            error = value.get('error', value)
            if not isinstance(error, dict):
                return None
            for name in ('message', 'type', 'code'):
                item = error.get(name)
                if isinstance(item, str):
                    snippets.append(item)
            metadata = error.get('metadata')
            return metadata.get('raw') if isinstance(metadata, dict) else None
        nested = fields(root)
        if isinstance(nested, str) and len(nested.encode('utf-8')) <= MAX_ERROR_RESPONSE:
            try:
                fields(json.loads(nested))  # At most one nested provider JSON.
            except (ValueError, TypeError, RecursionError):
                pass
        text = ' '.join(snippets).lower().replace('_', ' ')
        if (('tool name' in text or 'function name' in text) and ('duplicate' in text or 'unique' in text)) or re.search(r'duplicate\s+(?:tools?|functions?)(?:\W|$)', text):
            return 'duplicate_tool_names'
        if ('tool' in text or 'function' in text) and 'schema' in text and ('invalid' in text or 'must' in text or 'expected' in text):
            return 'invalid_tool_schema'
        if ('parameter' in text or 'argument' in text) and ('unsupported' in text or 'not supported' in text or 'unrecognized' in text):
            return 'unsupported_parameter'
        if ('context length' in text or 'context window' in text) and ('exceed' in text or 'maximum' in text):
            return 'context_length_exceeded'
    except Exception:
        pass
    return 'unknown'


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        raise BrokerError('redirect_refused')


class _HttpTransport:
    """Exactly one HTTP attempt; no proxy, redirects, retries, or alternate hosts."""
    def __init__(self):
        self.opener = urllib.request.build_opener(
            urllib.request.ProxyHandler({}), _NoRedirect(),
            urllib.request.HTTPSHandler(context=ssl.create_default_context()))

    def request(self, method, url, headers, body=None):
        allowed_gets = {BASE + '/key'} | {BASE + '/models/' + model + '/endpoints' for model in MODELS}
        generation_get = method == 'GET' and re.fullmatch(re.escape(BASE) + r'/generation\?id=gen-[A-Za-z0-9_-]{1,200}', url) is not None
        if not ((method == 'GET' and url in allowed_gets) or generation_get or (method == 'POST' and url == BASE + '/chat/completions')):
            raise BrokerError('endpoint_refused')
        data = encoded(body) if body is not None else None
        request = urllib.request.Request(url, data=data, headers=headers, method=method)
        started = time.monotonic()
        try:
            with self.opener.open(request, timeout=90 if method == 'POST' else 15) as response:
                if response.status != 200 or response.geturl() != url:
                    raise BrokerError('http_rejected', http_status=response.status)
                if response.headers.get('Content-Encoding', 'identity') != 'identity':
                    raise BrokerError('response_encoding_refused')
                length = response.headers.get('Content-Length')
                if length is not None and int(length) > MAX_RESPONSE:
                    raise BrokerError('response_too_large')
                pieces, size = [], 0
                while True:
                    if time.monotonic() - started > (120 if method == 'POST' else 15):
                        raise BrokerError('response_timeout')
                    piece = response.read(min(65536, MAX_RESPONSE + 1 - size))
                    if not piece:
                        break
                    pieces.append(piece)
                    size += len(piece)
                    if size > MAX_RESPONSE:
                        raise BrokerError('response_too_large')
                result = json.loads(b''.join(pieces))
                if not isinstance(result, dict):
                    raise BrokerError('invalid_response')
                return result
        except BrokerError:
            raise
        except urllib.error.HTTPError as error:
            category = 'unknown'
            try:
                # One bounded read of this already-received error response, using
                # the existing transport timeout. No extra HTTP request or retry.
                category = _classify_http_error(error.read(MAX_ERROR_RESPONSE + 1))
            except Exception:
                pass
            finally:
                try:
                    error.close()
                except Exception:
                    pass
            raise BrokerError('http_rejected', http_status=error.code, error_category=category) from None
        except Exception:
            # Never include HTTP response bodies, request headers, or exception text.
            raise BrokerError('transport_uncertain') from None


class _ProcessLock:
    def __init__(self, path):
        self.file = open(path, 'a+b')
        try:
            self.file.seek(0, os.SEEK_END)
            if not self.file.tell():
                self.file.write(b'0')
                self.file.flush()
            self.file.seek(0)
            if os.name == 'nt':
                import msvcrt
                msvcrt.locking(self.file.fileno(), msvcrt.LK_NBLCK, 1)
            else:
                import fcntl
                fcntl.flock(self.file.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError:
            self.file.close()
            raise BrokerError('ledger_in_use') from None

    def close(self):
        if not self.file.closed:
            self.file.seek(0)
            if os.name == 'nt':
                import msvcrt
                msvcrt.locking(self.file.fileno(), msvcrt.LK_UNLCK, 1)
            else:
                import fcntl
                fcntl.flock(self.file.fileno(), fcntl.LOCK_UN)
            self.file.close()


def _rates(pricing):
    if not isinstance(pricing, dict) or not {'prompt', 'completion'} <= pricing.keys():
        raise BrokerError('pricing_missing')
    variants = [pricing]
    overrides = pricing.get('overrides', [])
    if not isinstance(overrides, list) or len(overrides) > 100:
        raise BrokerError('pricing_tiers_invalid')
    for tier in overrides:
        if not isinstance(tier, dict) or type(tier.get('min_prompt_tokens')) is not int or tier['min_prompt_tokens'] < 0 or 'overrides' in tier:
            raise BrokerError('pricing_tiers_invalid')
        variants.append(tier)
    result = {}
    for variant in variants:
        for key, value in variant.items():
            if key in {'overrides', 'min_prompt_tokens'}:
                continue
            price = decimal(value)
            if key not in INPUT_RATES | OUTPUT_RATES | INACTIVE_RATES | {'request', 'discount'}:
                if price != 0:
                    raise BrokerError('unknown_nonzero_price')
                continue
            # Inactive modalities/search are denied in the wire payload. Discounts
            # are never deducted. Sum all cache categories, even when exclusive.
            result[key] = max(price, result.get(key, Decimal(0)))
    return result


def _unique_tool_definitions(tools):
    """Wire-only compatibility: retain the first exact definition for each name.

    Donor request evidence and response tool calls must remain untouched. A name
    collision with any differing field is ambiguous and refuses before a POST.
    """
    if not isinstance(tools, list):
        raise BrokerError('tool_refused')
    seen, unique = {}, []
    for tool in tools:
        if not isinstance(tool, dict) or set(tool) != {'type', 'function'} or tool['type'] != 'function' or not isinstance(tool['function'], dict):
            raise BrokerError('tool_refused')
        name = tool['function'].get('name')
        if not isinstance(name, str) or not name:
            raise BrokerError('tool_refused')
        signature = encoded(tool)
        if name in seen:
            if seen[name] != signature:
                raise BrokerError('conflicting_tool_definitions')
            continue
        seen[name] = signature
        unique.append(copy.deepcopy(tool))
    return unique


class CaptureBroker:
    def __init__(self, key_file: Path, catalog_file: Path, ledger_path: Path, limit_usd='50', allow_live=False, *, transport=None):
        limit = decimal(limit_usd, 'invalid_budget')
        if limit <= 0 or limit > MAX_USD or limit * MICROS != (limit * MICROS).to_integral_value():
            raise BrokerError('invalid_budget')
        self.limit_micros = int(limit * MICROS)
        self._transport = transport if transport is not None else (_HttpTransport() if allow_live else None)
        self._live = transport is None
        self._key_file, self._catalog_file = Path(key_file), Path(catalog_file)
        self.ledger_path = Path(ledger_path).resolve()
        if self._live and self.ledger_path != CANONICAL_LEDGER.resolve():
            raise BrokerError('task_ledger_required')
        lock_path = Path(str(self.ledger_path) + '.lock')
        # Keep both files across restarts. Losing either must not silently reset
        # the task allowance or split the process lock from the existing ledger.
        fresh_ledger = not self.ledger_path.exists() and not lock_path.exists()
        if self.ledger_path.exists() != lock_path.exists():
            raise BrokerError('ledger_state_missing')
        self.ledger_path.parent.mkdir(parents=True, exist_ok=True)
        self._lock = _ProcessLock(lock_path)
        self._connection = None
        self._key = None
        self._plans = {}
        self._ready_at = 0
        try:
            if not fresh_ledger:
                self._validate_existing()
            self._connection = sqlite3.connect(self.ledger_path, timeout=1, isolation_level=None)
            self._connection.execute('PRAGMA journal_mode=DELETE')
            self._connection.execute('PRAGMA synchronous=FULL')
            if fresh_ledger:
                self._connection.executescript('''
                    CREATE TABLE budget (id INTEGER PRIMARY KEY CHECK(id=1), limit_micros INTEGER NOT NULL, key_hash TEXT, blocked TEXT);
                    CREATE TABLE calls (id INTEGER PRIMARY KEY, request_hash TEXT NOT NULL, model TEXT NOT NULL, reserved_micros INTEGER NOT NULL, charged_micros INTEGER, state TEXT NOT NULL, reason TEXT, created_at TEXT NOT NULL, settled_at TEXT);
                ''')
                self._connection.execute('INSERT INTO budget(id,limit_micros) VALUES(1,?)', (self.limit_micros,))
            if self._connection.execute('SELECT limit_micros FROM budget WHERE id=1').fetchone()[0] != self.limit_micros:
                raise BrokerError('budget_change_refused')
            self._check_open()
        except Exception:
            self.close()
            raise

    def _validate_existing(self):
        """Never repair/recreate pre-existing accounting state on normal startup."""
        check = None
        try:
            check = sqlite3.connect(self.ledger_path.as_uri() + '?mode=ro', uri=True, isolation_level=None)
            if check.execute('PRAGMA quick_check').fetchall() != [('ok',)]:
                raise BrokerError('ledger_integrity_invalid')
            expected = {
                'budget': ['id', 'limit_micros', 'key_hash', 'blocked'],
                'calls': ['id', 'request_hash', 'model', 'reserved_micros', 'charged_micros', 'state', 'reason', 'created_at', 'settled_at'],
            }
            for table, columns in expected.items():
                if [row[1] for row in check.execute('PRAGMA table_info(' + table + ')')] != columns:
                    raise BrokerError('ledger_schema_missing')
            rows = check.execute('SELECT id,limit_micros,key_hash,blocked FROM budget').fetchall()
            if len(rows) != 1 or rows[0][0] != 1 or type(rows[0][1]) is not int:
                raise BrokerError('ledger_budget_missing')
            if rows[0][1] != self.limit_micros:
                raise BrokerError('budget_change_refused')
            key_hash, blocked = rows[0][2:]
            if key_hash is not None and (not isinstance(key_hash, str) or not re.fullmatch(r'[0-9a-f]{64}', key_hash)):
                raise BrokerError('ledger_integrity_invalid')
            if blocked is not None and (not isinstance(blocked, str) or not re.fullmatch(r'[a-z_]{1,80}', blocked)):
                raise BrokerError('ledger_integrity_invalid')
            calls = check.execute('SELECT id,request_hash,model,reserved_micros,charged_micros,state FROM calls ORDER BY id').fetchall()
            if calls and key_hash is None:
                raise BrokerError('ledger_integrity_invalid')
            for index, (call_id, request_hash, model, reserve, charged, state) in enumerate(calls, 1):
                if call_id != index or not isinstance(request_hash, str) or not re.fullmatch(r'[0-9a-f]{64}', request_hash) or model not in MODELS:
                    raise BrokerError('ledger_integrity_invalid')
                if type(reserve) is not int or not 0 < reserve <= self.limit_micros or state not in {'reserved', 'completed', 'uncertain'}:
                    raise BrokerError('ledger_integrity_invalid')
                if charged is not None and (type(charged) is not int or charged < 0):
                    raise BrokerError('ledger_integrity_invalid')
                if state == 'completed' and (charged is None or charged > reserve):
                    raise BrokerError('ledger_integrity_invalid')
            if sum(row[4] or 0 for row in calls if row[5] == 'completed') + sum(self._forfeitures(check).values()) > self.limit_micros:
                raise BrokerError('ledger_integrity_invalid')
        except BrokerError:
            raise
        except Exception:
            raise BrokerError('ledger_integrity_invalid') from None
        finally:
            if check is not None:
                check.close()

    def close(self):
        if self._connection is not None:
            self._connection.close()
            self._connection = None
        if getattr(self, '_lock', None) is not None:
            self._lock.close()
        self._key = None

    def __enter__(self):
        return self

    def __exit__(self, *args):
        self.close()

    def _forfeitures(self, db=None):
        return self._read_forfeitures(db if db is not None else self._connection, self.ledger_path, self.limit_micros)

    @staticmethod
    def _read_forfeitures(db, ledger_path, limit_micros):
        if not db.execute("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='budget_forfeitures'").fetchone()[0]:
            return {}
        try:
            expected = ['call_id', 'upper_bound_micros', 'reason', 'audit_sha256', 'decided_at']
            if [row[1] for row in db.execute('PRAGMA table_info(budget_forfeitures)')] != expected:
                raise BrokerError('forfeit_audit_invalid')
            triggers = {row[0] for row in db.execute("SELECT name FROM sqlite_master WHERE type='trigger' AND tbl_name='budget_forfeitures'")}
            if not {'budget_forfeitures_no_update', 'budget_forfeitures_no_delete'} <= triggers:
                raise BrokerError('forfeit_audit_invalid')
            key_hash = db.execute('SELECT key_hash FROM budget WHERE id=1').fetchone()[0]
            result = {}
            for call_id, amount, reason, checksum, decided in db.execute('SELECT call_id,upper_bound_micros,reason,audit_sha256,decided_at FROM budget_forfeitures'):
                if type(call_id) is not int or call_id < 1 or type(amount) is not int or not 0 < amount <= limit_micros or reason != 'operator_conservative_forfeit' or not isinstance(checksum, str) or not re.fullmatch('[0-9a-f]{64}', checksum):
                    raise BrokerError('forfeit_audit_invalid')
                call = db.execute('SELECT request_hash,model,reserved_micros,charged_micros,state FROM calls WHERE id=?', (call_id,)).fetchone()
                if call is None or call[2:] != (amount, None, 'uncertain'):
                    raise BrokerError('forfeit_audit_invalid')
                path = ledger_path.parent / 'reconciliation' / (str(call_id).zfill(6) + '-forfeit-' + checksum + '.json')
                if not path.is_file() or path.is_symlink() or path.stat().st_size > 131072:
                    raise BrokerError('forfeit_audit_invalid')
                raw = path.read_bytes()
                if hashlib.sha256(raw).hexdigest() != checksum:
                    raise BrokerError('forfeit_audit_invalid')
                audit = json.loads(raw)
                if not isinstance(audit, dict) or audit.get('kind') != reason or audit.get('call_id') != call_id or audit.get('decided_at') != decided or audit.get('request_sha256') != call[0] or audit.get('key_fingerprint_sha256') != key_hash:
                    raise BrokerError('forfeit_audit_invalid')
                if audit.get('upper_bound_micros') != amount or audit.get('forfeited_micros') != amount or audit.get('actual_cost_confirmed') is not False or audit.get('confirmed_cost_micros') is not None or audit.get('replay_evidence_created') is not False:
                    raise BrokerError('forfeit_audit_invalid')
                if audit.get('decision_basis') != 'explicit_operator_choice_full_uncertain_reserve_irrecoverable_deduction':
                    raise BrokerError('forfeit_audit_invalid')
                original = audit.get('original_call')
                if not isinstance(original, dict) or original.get('id') != call_id or original.get('model') != call[1] or original.get('reserved_micros') != amount or original.get('charged_micros') is not None or original.get('state') != 'uncertain':
                    raise BrokerError('forfeit_audit_invalid')
                result[call_id] = amount
            if sum(result.values()) > limit_micros:
                raise BrokerError('forfeit_audit_invalid')
            return result
        except BrokerError:
            raise
        except Exception:
            raise BrokerError('forfeit_audit_invalid') from None

    def _check_open(self):
        forfeitures = self._forfeitures()
        self._read_reconciliations(self._connection, self.ledger_path, self.limit_micros)
        from orphan_ledger import read_orphan_state
        read_orphan_state(self._connection, self.ledger_path)
        blocked = self._connection.execute('SELECT blocked FROM budget WHERE id=1').fetchone()[0]
        pending = self._connection.execute("SELECT id FROM calls WHERE state != 'completed'").fetchall()
        if blocked or any(row[0] not in forfeitures for row in pending):
            raise BrokerError('ledger_requires_reconciliation')

    @staticmethod
    def _read_reconciliations(db, ledger_path, limit_micros):
        from reconcile import read_reconciliations
        return read_reconciliations(db, ledger_path, limit_micros)

    def summary(self):
        forfeitures = self._forfeitures()
        rows = self._connection.execute('SELECT id,charged_micros,reserved_micros,state FROM calls').fetchall()
        spent = sum(row[1] or 0 for row in rows)
        held = sum(row[2] for row in rows if row[3] != 'completed' and row[0] not in forfeitures)
        forfeited = sum(forfeitures.values())
        blocked = self._connection.execute('SELECT blocked FROM budget WHERE id=1').fetchone()[0]
        return {'limit_micros': self.limit_micros, 'spent_micros': spent, 'held_micros': held, 'forfeited_micros': forfeited,
                'deducted_micros': spent + forfeited, 'available_micros': self.limit_micros - spent - forfeited - held,
                'calls': len(rows), 'blocked': blocked}

    def _request(self, method, path, body=None, authenticated=False):
        if self._transport is None:
            raise BrokerError('live_not_authorized')
        headers = {'Accept': 'application/json', 'Accept-Encoding': 'identity', 'Content-Type': 'application/json'}
        if authenticated:
            headers['Authorization'] = 'Bearer ' + self._key
        try:
            return self._transport.request(method, BASE + path, headers, body)
        except BrokerError:
            raise
        except Exception:
            raise BrokerError('transport_uncertain') from None

    def preflight(self, model_ids):
        self._check_open()
        if not isinstance(model_ids, list) or not model_ids or len(set(model_ids)) != len(model_ids) or not set(model_ids) <= MODELS:
            raise BrokerError('model_refused')
        if self._transport is None:
            raise BrokerError('live_not_authorized')
        if self._key_file.is_symlink() or not self._key_file.is_file() or self._key_file.stat().st_size > 1024:
            raise BrokerError('credential_file_invalid')
        try:
            self._key = self._key_file.read_text(encoding='utf-8').strip()
            if not re.fullmatch(r'sk-or-v1-[A-Za-z0-9_-]{32,200}', self._key):
                raise BrokerError('credential_format_invalid')
            raw = self._catalog_file.read_bytes()
            if len(raw) > MAX_RESPONSE:
                raise BrokerError('catalog_too_large')
            catalog = json.loads(raw)
        except BrokerError:
            raise
        except Exception:
            raise BrokerError('local_configuration_invalid') from None
        catalog_ids = {item.get('id') for item in catalog.get('data', []) if isinstance(item, dict)}
        if not set(model_ids) <= catalog_ids:
            raise BrokerError('catalog_model_missing')
        key_hash = hashlib.sha256(self._key.encode()).hexdigest()
        prior = self._connection.execute('SELECT key_hash FROM budget WHERE id=1').fetchone()[0]
        if prior is not None and prior != key_hash:
            raise BrokerError('credential_change_refused')
        self._connection.execute('UPDATE budget SET key_hash=? WHERE id=1', (key_hash,))
        key_info = self._request('GET', '/key', authenticated=True).get('data')
        if not isinstance(key_info, dict):
            raise BrokerError('credential_metadata_invalid')
        # A remote cap is informative, never treated as an implicit unlimited budget.
        remote_limit = key_info.get('limit')
        if remote_limit is not None:
            decimal(remote_limit, 'credential_metadata_invalid')
        self._plans = {model: self._endpoint_plan(model) for model in model_ids}
        self._ready_at = time.monotonic()
        return {'ok': True, 'limit_micros': self.limit_micros, 'remote_limit_configured': remote_limit is not None,
                'catalog_sha256': hashlib.sha256(raw).hexdigest(), 'models': {m: self._public_plan(p) for m, p in self._plans.items()},
                'bound_basis': 'published_endpoint_tariffs_all_tiers_cache_plus_5_percent_byok',
                'limitation': 'Independent BYOK contracts and undisclosed tariff changes are not verifiable from public metadata.'}

    @staticmethod
    def _public_plan(plan):
        return {k: v for k, v in plan.items() if k not in {'rates'}} | {'rates': {k: str(v) for k, v in plan['rates'].items()}}

    def _endpoint_plan(self, model):
        response = self._request('GET', '/models/' + model + '/endpoints')
        data = response.get('data')
        if not isinstance(data, dict) or data.get('id') != model or not isinstance(data.get('endpoints'), list):
            raise BrokerError('endpoint_metadata_invalid')
        endpoints = data['endpoints']
        preferred = 'anthropic' if model.startswith('anthropic/') else 'google-ai-studio'
        selected = [e for e in endpoints if isinstance(e, dict) and isinstance(e.get('tag'), str) and (e['tag'] == preferred or e['tag'].startswith(preferred + '/'))]
        if not selected:
            raise BrokerError('pinned_provider_missing')
        rates, contexts, output_caps, names = {}, [], [], set()
        for endpoint in selected:
            context, completion = endpoint.get('context_length'), endpoint.get('max_completion_tokens')
            if type(context) is not int or not 1 <= context <= 10000000 or type(completion) is not int or not 1 <= completion <= context:
                raise BrokerError('endpoint_bounds_invalid')
            if 'max_tokens' not in endpoint.get('supported_parameters', []):
                raise BrokerError('max_tokens_unsupported')
            contexts.append(context)
            output_caps.append(completion)
            if not isinstance(endpoint.get('provider_name'), str):
                raise BrokerError('endpoint_metadata_invalid')
            names.add(endpoint['provider_name'])
            for name, price in _rates(endpoint.get('pricing')).items():
                rates[name] = max(price, rates.get(name, Decimal(0)))
        return {'provider': preferred, 'provider_names': sorted(names), 'context_length': max(contexts),
                'max_completion_tokens': min(output_caps), 'rates': rates,
                'endpoints_sha256': hashlib.sha256(encoded(response)).hexdigest(), 'fetched_at': utc_now()}

    def _wire(self, body):
        if not isinstance(body, dict) or not set(body) <= BODY_KEYS or len(encoded(body)) > MAX_BYTES:
            raise BrokerError('request_refused')
        model = body.get('model')
        if model not in self._plans:
            raise BrokerError('model_refused')
        if body.get('stream', False) is not False or body.get('usage', {'include': True}) != {'include': True}:
            raise BrokerError('request_refused')
        provider = body.get('provider', {})
        if not isinstance(provider, dict) or not set(provider) <= {'require_parameters'} or ('require_parameters' in provider and type(provider['require_parameters']) is not bool):
            raise BrokerError('provider_override_refused')
        tokens = body.get('max_tokens')
        if type(tokens) is not int or not 1 <= tokens <= self._plans[model]['max_completion_tokens']:
            raise BrokerError('output_bound_invalid')
        messages = body.get('messages')
        if not isinstance(messages, list) or not messages or len(messages) > 1000:
            raise BrokerError('messages_invalid')
        for message in messages:
            if not isinstance(message, dict) or not set(message) <= {'role', 'content', 'name', 'tool_calls', 'tool_call_id'} or message.get('role') not in {'system', 'user', 'assistant', 'tool'}:
                raise BrokerError('messages_invalid')
            if not isinstance(message.get('content'), str) and not (message.get('role') == 'assistant' and message.get('content') is None and message.get('tool_calls')):
                raise BrokerError('modality_refused')
            for call in message.get('tool_calls', []):
                if not isinstance(call, dict) or call.get('type') != 'function' or not isinstance(call.get('function'), dict):
                    raise BrokerError('tool_refused')
        tools = _unique_tool_definitions(body.get('tools', []))
        if 'temperature' in body and not Decimal(0) <= decimal(body['temperature'], 'temperature_invalid') <= Decimal(2):
            raise BrokerError('temperature_invalid')
        if 'top_p' in body and not Decimal(0) <= decimal(body['top_p'], 'top_p_invalid') <= Decimal(1):
            raise BrokerError('top_p_invalid')
        if 'seed' in body and type(body['seed']) is not int:
            raise BrokerError('seed_invalid')
        reasoning = body.get('reasoning', {})
        if not isinstance(reasoning, dict) or not set(reasoning) <= {'effort', 'max_tokens', 'exclude', 'enabled'}:
            raise BrokerError('reasoning_invalid')
        if 'max_tokens' in reasoning and (type(reasoning['max_tokens']) is not int or not 0 <= reasoning['max_tokens'] <= tokens):
            raise BrokerError('reasoning_invalid')
        if self._key and self._key.encode() in encoded(body):
            raise BrokerError('request_contains_secret')
        wire = copy.deepcopy(body)
        if 'tools' in wire:
            wire['tools'] = tools
        plan, rates = self._plans[model], self._plans[model]['rates']
        wire['stream'] = False
        wire['usage'] = {'include': True}
        wire['plugins'] = []
        wire['provider'] = {'only': [plan['provider']], 'order': [plan['provider']], 'allow_fallbacks': False, 'require_parameters': provider.get('require_parameters', False),
                            'max_price': {'prompt': float(rates['prompt'] * MICROS), 'completion': float(rates['completion'] * MICROS), 'request': float(rates.get('request', 0))}}
        bound = (Decimal(plan['context_length']) * sum((rates.get(k, Decimal(0)) for k in INPUT_RATES), Decimal(0))
                 + Decimal(tokens) * sum((rates.get(k, Decimal(0)) for k in OUTPUT_RATES), Decimal(0)) + rates.get('request', Decimal(0))) * BYOK_FACTOR
        reserved = micros(bound)
        if reserved <= 0:
            raise BrokerError('zero_reservation_refused')
        return wire, reserved

    def _reserve(self, wire, amount, replacement=None):
        db = self._connection
        db.execute('BEGIN IMMEDIATE')
        try:
            self._check_open()
            spent = db.execute('SELECT coalesce(sum(charged_micros),0) FROM calls').fetchone()[0]
            forfeited = sum(self._forfeitures(db).values())
            if amount > self.limit_micros - spent - forfeited:
                raise BrokerError('budget_exhausted')
            cursor = db.execute("INSERT INTO calls(request_hash,model,reserved_micros,state,created_at) VALUES(?,?,?,'reserved',?)",
                                (hashlib.sha256(encoded(wire)).hexdigest(), wire['model'], amount, utc_now()))
            from orphan_ledger import bind_replacement
            bind_replacement(db, self.ledger_path, cursor.lastrowid, replacement)
            db.commit()
            return cursor.lastrowid
        except Exception:
            db.rollback()
            raise

    def _hold(self, call_id, reason, known_cost=None):
        self._connection.execute('BEGIN IMMEDIATE')
        try:
            self._connection.execute("UPDATE calls SET state='uncertain',reason=?,charged_micros=?,settled_at=? WHERE id=?", (reason, known_cost, utc_now(), call_id))
            self._connection.execute('UPDATE budget SET blocked=? WHERE id=1', (reason,))
            self._connection.commit()
        except Exception:
            self._connection.rollback()
            # The earlier committed 'reserved' row remains a restart barrier.
            raise BrokerError('ledger_write_failed') from None

    def _checkpoint(self, call_id, kind, value):
        if type(call_id) is not int or call_id < 1 or not re.fullmatch(r'(reservation|receipt|failure|poll-[0-9]{2})', kind):
            raise BrokerError('checkpoint_path_refused')
        # Metadata only: no prompt, content, headers, credential, or replay claim.
        directory = Path(str(self.ledger_path) + '.checkpoints')
        if directory.is_symlink() or directory.resolve().parent != self.ledger_path.parent:
            raise BrokerError('checkpoint_path_refused')
        directory.mkdir(mode=0o700, exist_ok=True)
        path = directory / f'{call_id:06d}.{kind}.json'
        payload = encoded({'schema_version': 1, 'call_id': call_id,
                           **value, 'replay_eligible': False}) + b'\n'
        try:
            with path.open('xb') as handle:
                handle.write(payload)
                handle.flush()
                os.fsync(handle.fileno())
        except Exception:
            raise BrokerError('checkpoint_write_failed') from None

    def _checkpoint_receipt(self, call_id, data, wire):
        # Validate the entire receipt before projecting even its ID: a provider
        # could reflect a credential into an otherwise syntactically valid ID.
        if self._key and self._key.encode() in encoded(data):
            raise BrokerError('receipt_contains_secret')
        generation_id = data.get('id') if isinstance(data, dict) else None
        if not isinstance(generation_id, str) or not re.fullmatch(r'gen-[A-Za-z0-9_-]{1,200}', generation_id):
            generation_id = None
        usage = data.get('usage') if isinstance(data, dict) else None
        numeric = {}
        if isinstance(usage, dict):
            for name in ('prompt_tokens', 'completion_tokens', 'total_tokens'):
                value = usage.get(name)
                if type(value) is int and 0 <= value <= 1000000000:
                    numeric[name] = value
            try:
                numeric['cost_usd'] = str(decimal(usage.get('cost'), 'receipt_cost_invalid'))
            except BrokerError:
                pass
        self._checkpoint(call_id, 'receipt', {
            'recorded_at': utc_now(), 'generation_id': generation_id,
            'request_sha256': hashlib.sha256(encoded(wire)).hexdigest(),
            'response_sha256': hashlib.sha256(encoded(data)).hexdigest(),
            'model_requested': wire['model'], 'usage': numeric,
            'response_retained': False,
        })

    @staticmethod
    def _billing_shape(response, generation_id):
        candidate = response.get('data') if isinstance(response, dict) else None
        if not isinstance(candidate, dict):
            return 'billing_data_missing', None
        if candidate.get('id') != generation_id:
            return 'billing_identity_mismatch', None
        if type(candidate.get('is_byok')) is not bool:
            return 'billing_byok_status_missing', None
        if 'total_cost' not in candidate:
            return 'billing_total_cost_missing', None
        return 'billing_ready', candidate

    def _poll_billing(self, generation_id, call_id):
        started = time.monotonic()
        for attempt, delay in enumerate(BILLING_DELAYS, 1):
            remaining = BILLING_START_WINDOW_SECONDS - (time.monotonic() - started)
            if remaining <= delay:
                break
            if delay:
                time.sleep(delay)
            # A suspended/oversleeping process must not start a late GET merely
            # because it passed the admission check before sleeping.
            if time.monotonic() - started >= BILLING_START_WINDOW_SECONDS:
                break
            http_status = None
            category = 'unknown'
            candidate = None
            try:
                response = self._request('GET', '/generation?id=' + generation_id, authenticated=True)
                code, candidate = self._billing_shape(response, generation_id)
                http_status = 200
            except BrokerError as error:
                code = error.code
                http_status = error.http_status
                category = error.error_category
            self._checkpoint(call_id, f'poll-{attempt:02d}', {
                'recorded_at': utc_now(), 'attempt': attempt, 'generation_id': generation_id,
                'outcome': code, 'http_status': http_status, 'error_category': category,
                'elapsed_ms': round((time.monotonic() - started) * 1000),
            })
            if candidate is not None:
                return candidate
        raise BrokerError('generation_billing_unknown')

    def _receipt_cost(self, data, wire, call_id):
        if not isinstance(data, dict) or 'error' in data or not isinstance(data.get('choices'), list) or not data['choices']:
            raise BrokerError('receipt_invalid')
        if self._key.encode() in encoded(data):
            raise BrokerError('receipt_contains_secret')
        usage = data.get('usage')
        if not isinstance(usage, dict) or 'cost' not in usage:
            raise BrokerError('receipt_cost_missing')
        for name, cap in [('prompt_tokens', self._plans[wire['model']]['context_length']), ('completion_tokens', wire['max_tokens'])]:
            if type(usage.get(name)) is not int or not 0 <= usage[name] <= cap:
                raise BrokerError('receipt_tokens_invalid')
        if type(usage.get('total_tokens')) is not int or usage['total_tokens'] != usage['prompt_tokens'] + usage['completion_tokens']:
            raise BrokerError('receipt_total_tokens_invalid')
        if data.get('provider') not in self._plans[wire['model']]['provider_names']:
            raise BrokerError('receipt_provider_mismatch')
        model = data.get('model')
        if not isinstance(model, str) or not (model == wire['model'] or model.startswith(wire['model'] + '-')):
            raise BrokerError('receipt_model_mismatch')
        generation_id = data.get('id')
        if not isinstance(generation_id, str) or not re.fullmatch(r'gen-[A-Za-z0-9_-]{1,200}', generation_id):
            raise BrokerError('generation_id_invalid')
        # Only metadata GETs are retried. The receipt checkpoint is already durable.
        billing = self._poll_billing(generation_id, call_id)
        if self._key.encode() in encoded(billing):
            raise BrokerError('receipt_contains_secret')
        cost = decimal(billing['total_cost'], 'receipt_cost_invalid')
        if 'cost' in usage:
            reported = decimal(usage['cost'], 'receipt_cost_invalid')
            if abs(cost - reported) > Decimal('0.000001'):
                raise BrokerError('receipt_cost_mismatch')
            cost = max(cost, reported)
        if billing['is_byok']:
            if 'upstream_inference_cost' not in billing:
                raise BrokerError('byok_cost_missing')
            cost += decimal(billing['upstream_inference_cost'], 'byok_cost_invalid')
        for name in ['num_fetches', 'num_input_audio_prompt', 'num_media_completion', 'num_media_prompt', 'num_search_results']:
            if billing.get(name) not in (None, 0):
                raise BrokerError('unexpected_paid_addon')
        if billing.get('preset_id') is not None or billing.get('router') is not None:
            raise BrokerError('unexpected_routing')
        return micros(cost)

    def call(self, body, *, replacement=None):
        self._check_open()
        if replacement is not None:
            # This is the original donor IPC body, whose frontier is hashed by
            # PHP canonical JSON. Physical wire accounting uses encoded() below.
            from verify import digest_value
            if not isinstance(replacement, dict) or digest_value(body) != replacement.get('request_body_sha256'):
                raise BrokerError('orphan_frontier_mismatch')
        if not self._plans or not self._ready_at:
            raise BrokerError('preflight_required')
        wire, _ = self._wire(body)
        # Refresh public endpoint tariffs before each physical paid HTTP attempt.
        self._plans[wire['model']] = self._endpoint_plan(wire['model'])
        wire, reserved = self._wire(body)
        call_id = self._reserve(wire, reserved, replacement)
        try:
            self._checkpoint(call_id, 'reservation', {
                'recorded_at': utc_now(), 'request_sha256': hashlib.sha256(encoded(wire)).hexdigest(),
                'model_requested': wire['model'], 'reserved_micros': reserved,
            })
            data = self._request('POST', '/chat/completions', wire, authenticated=True)
            self._checkpoint_receipt(call_id, data, wire)
            cost = self._receipt_cost(data, wire, call_id)
        except Exception as error:
            code = error.code if isinstance(error, BrokerError) else 'receipt_invalid'
            if not isinstance(code, str) or not re.fullmatch(r'[a-z_]{1,80}', code):
                code = 'receipt_invalid'
            status = error.http_status if isinstance(error, BrokerError) else None
            status = status if type(status) is int and 100 <= status <= 599 else None
            category = error.error_category if isinstance(error, BrokerError) else 'unknown'
            category = category if isinstance(category, str) and category in ERROR_CATEGORIES else 'unknown'
            self._hold(call_id, code)
            try:
                self._checkpoint(call_id, 'failure', {
                    'recorded_at': utc_now(), 'reason': code, 'http_status': status, 'error_category': category,
                    'provider_billing_confirmed': False, 'response_retained': False,
                })
            except Exception:
                # The committed unknown hold remains authoritative if diagnostic
                # storage fails. No status, including 4xx, releases its reserve.
                pass
            raise BrokerError(code, http_status=status, error_category=category) from None
        if cost > reserved:
            self._hold(call_id, 'reservation_exceeded', cost)
            raise BrokerError('reservation_exceeded')
        try:
            self._connection.execute('BEGIN IMMEDIATE')
            cursor = self._connection.execute("UPDATE calls SET state='completed',charged_micros=?,settled_at=? WHERE id=? AND state='reserved'", (cost, utc_now(), call_id))
            if cursor.rowcount != 1:
                raise BrokerError('ledger_state_invalid')
            self._connection.commit()
        except Exception:
            self._connection.rollback()
            raise BrokerError('ledger_write_failed') from None
        return {'ok': True, 'data': data, 'captured_at': utc_now(), 'request_body': wire,
                'budget': {'call_id': call_id, 'reserved_micros': reserved, 'charged_micros': cost},
                'pricing': self._public_plan(self._plans[wire['model']])}
