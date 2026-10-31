#!/usr/bin/env python3
"""SP-T-03 operator capture: isolated offline donor child, sole controlled broker.

No argument logs contain the credential. No raw provider output is written by this
process. Failed/partial runs are never finalized or automatically retried.
"""
from __future__ import annotations

import argparse
import copy
from contextlib import ExitStack, closing
import hashlib
import json
import os
from pathlib import Path
import queue
import re
import sqlite3
import subprocess
import sys
import threading
import time
from datetime import datetime, timezone
from typing import Callable

ROOT = Path(__file__).resolve().parents[2]
BASELINE = ROOT / "tests/fixtures/prototype"
LOCAL = ROOT / ".local/baseline"
MAX_LINE = 4_000_000
CHILD_EXECUTION_SECONDS = 3600
CHILD_IDLE_SECONDS = 120
DISABLED = ",".join([
    "curl_exec", "curl_multi_exec", "fsockopen", "pfsockopen",
    "stream_socket_client", "stream_socket_server", "mail",
    "socket_create", "socket_connect", "socket_send", "socket_sendto",
    "socket_write", "exec", "shell_exec", "system", "passthru",
    "popen", "proc_open", "pcntl_exec", "dl",
])
HARNESS = [
    "tools/baseline/bootstrap.php", "tools/baseline/FixtureClient.php",
    "tools/baseline/ContactNormalizer.php", "tools/baseline/RecordedClient.php",
    "tools/baseline/capture-case.php", "tools/baseline/capture-scenarios.php",
    "tools/baseline/capture.py", "tools/baseline/capture_broker.py", "tools/baseline/verify.py", "tools/baseline/orphan_ledger.py", "tools/baseline/reconcile.py",
]


class CaptureError(Exception):
    pass


def digest(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds").replace("+00:00", "Z")


def write_new(path: Path, value: object) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("x", encoding="utf-8", newline="\n") as handle:
        json.dump(value, handle, ensure_ascii=False, allow_nan=False, sort_keys=True, indent=4)
        handle.write("\n")
        handle.flush()
        os.fsync(handle.fileno())


def child_environment(temp: Path) -> dict[str, str]:
    env = {k: os.environ[k] for k in ("SystemRoot", "WINDIR", "PATH", "COMSPEC") if k in os.environ}
    temp.mkdir(parents=True, exist_ok=True)
    env.update({"TEMP": str(temp), "TMP": str(temp), "APP_ENV": "testing",
                "LLM_DRIVER": "fake", "MAIL_MAILER": "array", "PYTHONIOENCODING": "utf-8"})
    return env


def run_child(php: Path, prototype: Path, arguments: list[str],
              handler: Callable[[dict], dict] | None = None, *,
              resume_prefix: int = 0, prefix_only: bool = False,
              prefix_provenance: str | None = None) -> dict:
    if type(resume_prefix) is not int or not 0 <= resume_prefix <= 200:
        raise CaptureError("invalid_resume_prefix")
    if prefix_only and handler is not None:
        raise CaptureError("offline_prefix_handler_forbidden")
    command = [
        str(php), "-d", "opcache.enable_cli=0",
        "-d", "allow_url_fopen=0", "-d", f"disable_functions={DISABLED}",
        "-d", "display_errors=0", "-d", "log_errors=0",
        "-d", f"sys_temp_dir={LOCAL / 'tmp'}",
        str(ROOT / "tools/baseline/capture-case.php"), f"--prototype={prototype}",
        *arguments,
    ]
    lines: queue.Queue[bytes | None] = queue.Queue(maxsize=2)
    env = child_environment(LOCAL / "tmp")
    if resume_prefix or prefix_only:
        if prefix_provenance not in ("recorded", "synthetic"):
            raise CaptureError("invalid_resume_provenance")
        env.update({"BASELINE_RESUME_PREFIX": str(resume_prefix),
                    "BASELINE_RESUME_PROVENANCE": prefix_provenance,
                    "BASELINE_PREFIX_ONLY": "1" if prefix_only else "0"})
    process = subprocess.Popen(command, cwd=ROOT, env=env,
                               stdin=subprocess.PIPE, stdout=subprocess.PIPE,
                               stderr=subprocess.DEVNULL, shell=False)

    def reader() -> None:
        try:
            while True:
                line = process.stdout.readline(MAX_LINE + 1)
                lines.put(line or None)
                if not line:
                    break
        except (OSError, ValueError):
            lines.put(None)

    worker = threading.Thread(target=reader, daemon=True)
    worker.start()
    expected_sequence = resume_prefix + 1
    result = None
    pending_reply = False
    deadline = time.monotonic() + CHILD_EXECUTION_SECONDS
    try:
        while True:
            remaining = deadline - time.monotonic()
            # A paid reply must reach the child's fsync before its execution
            # budget can stop the run. Its next call/complete is the ACK; after
            # complete we only drain EOF. The ordinary idle bound still applies.
            if remaining <= 0 and not pending_reply:
                raise CaptureError("offline_child_execution_timeout")
            try:
                line = lines.get(timeout=CHILD_IDLE_SECONDS if pending_reply
                                 else min(CHILD_IDLE_SECONDS, remaining))
            except queue.Empty:
                code = ("offline_child_execution_timeout" if not pending_reply
                        and time.monotonic() >= deadline else "offline_child_timeout")
                raise CaptureError(code) from None
            if not pending_reply and time.monotonic() >= deadline:
                raise CaptureError("offline_child_execution_timeout")
            if line is None:
                break
            if len(line) > MAX_LINE or not line.endswith(b"\n"):
                raise CaptureError("offline_child_protocol_limit")
            try:
                message = json.loads(line)
            except (UnicodeDecodeError, json.JSONDecodeError):
                raise CaptureError("offline_child_protocol_invalid") from None
            if not isinstance(message, dict):
                raise CaptureError("offline_child_protocol_invalid")
            if message.get("op") == "call":
                expected_case = next((x[7:] for x in arguments if x.startswith("--case=")), None)
                if handler is None or message.get("case_id") != expected_case or message.get("sequence") != expected_sequence:
                    raise CaptureError("unexpected_or_replayed_ipc_call")
                if expected_sequence > 200:
                    raise CaptureError("case_request_limit")
                # Receiving a call ACKs the previous durable reply, but grace
                # never authorizes another paid request after active time expires.
                if time.monotonic() >= deadline:
                    raise CaptureError("offline_child_execution_timeout")
                pending_reply = False
                expected_sequence += 1
                handler_started = time.monotonic()
                response = handler(message)
                # The child waits on IPC while the broker performs its separately
                # bounded request/billing work. Do not spend the child's execution
                # budget there or discard an already-paid reply before delivery.
                deadline += time.monotonic() - handler_started
                payload = json.dumps(response, ensure_ascii=False, allow_nan=False, separators=(",", ":")).encode("utf-8") + b"\n"
                if len(payload) > MAX_LINE:
                    raise CaptureError("broker_response_too_large")
                process.stdin.write(payload)
                process.stdin.flush()
                pending_reply = True
                # Only safe counters are printed; no prompt, contact, error or receipt.
                print(json.dumps({"event": "call_complete", "case_id": expected_case,
                                  "sequence": message["sequence"]}), flush=True)
            elif (message.get("op") in ("describe", "complete")
                  or (prefix_only and message.get("op") == "prefix_ready")) and result is None:
                result = message
            else:
                raise CaptureError("offline_case_failed:" + str(message.get("location", {})))
        code = process.wait(timeout=10)
        if code != 0 or result is None:
            raise CaptureError("offline_child_failed")
        if expected_sequence > 1 and result.get("op") != "complete" and not (prefix_only and result.get("op") == "prefix_ready"):
            raise CaptureError("offline_child_incomplete")
        return result
    finally:
        if process.poll() is None:
            process.kill()
            process.wait(timeout=10)
        for stream in (process.stdin, process.stdout):
            if stream:
                stream.close()


class FakeBroker:
    """Only --test-case can reach this; its files always say synthetic."""
    def __init__(self):
        self.calls = 0

    def __call__(self, message: dict) -> dict:
        self.calls += 1
        body = message["body"]
        actual_body = copy.deepcopy(body)
        if "tools" in actual_body:
            from capture_broker import _unique_tool_definitions
            actual_body["tools"] = _unique_tool_definitions(actual_body["tools"])
        component = message["request"]["options"].get("component")
        if component == "simulator":
            content = "[конец]"
        elif component == "extractor":
            content = json.dumps({
                "slot_updates": {}, "client_intent": "answer_only", "client_questions": [],
                "objections": [], "sentiment": "neutral", "wants_only_prices": False,
                "asked_if_bot": False, "wants_human": False, "do_not_disturb": False,
                "off_topic": False, "prompt_injection": False, "closed_topic": None,
                "unknown_question": None, "client_claims": [], "refused_slots": [],
                "confidence": 0.5,
            }, ensure_ascii=False)
        elif component == "guard":
            content = '{"ok":true,"issues":[]}'
        elif component == "summary":
            content = "Клиент пока обдумывает закупку."
        else:
            content = "Понял вас. Продолжим обсуждение закупки."
        return {
            "ok": True, "captured_at": utc_now(), "request_body": actual_body,
            "data": {"id": f"fake-{self.calls}", "model": body["model"],
                     "choices": [{"finish_reason": "stop", "message": {"role": "assistant", "content": content}}],
                     "usage": {"prompt_tokens": 100, "completion_tokens": 20,
                               "total_tokens": 120, "cost": 0.00001}},
        }


ARTIFACT_NAMES = {
    "baseline_manifest": "baseline-manifest.json",
    "source_fingerprints": "source-fingerprints.json",
    "config": "config.json", "prompts": "prompts.json", "harness": "harness.json",
}


def copy_new(source: Path, target: Path, expected_hash: str | None = None) -> None:
    raw = source.read_bytes()
    if expected_hash is not None and hashlib.sha256(raw).hexdigest() != expected_hash:
        raise CaptureError("resume_source_changed")
    target.parent.mkdir(parents=True, exist_ok=True)
    with target.open("xb") as handle:
        if handle.write(raw) != len(raw):
            raise CaptureError("incomplete_evidence_copy")
        handle.flush()
        os.fsync(handle.fileno())


HARNESS_HASHING = "sha256-utf8-lf-v1"


def source_hash_matches(path: Path, hashing: str, expected: str) -> bool:
    """Legacy source guard only: prove exact text equality across newline styles.

    Historical artifact values are never rewritten. New hashes explicitly name
    their UTF-8/LF representation; raw mutation guards remain byte-exact.
    """
    import verify
    if verify.harness_source_sha(path, hashing) == expected:
        return True
    if hashing != "sha256-raw-v1":
        return False
    try:
        text = path.read_bytes().decode("utf-8").replace("\r\n", "\n")
    except UnicodeDecodeError:
        return False
    return expected in {
        hashlib.sha256(text.encode("utf-8")).hexdigest(),
        hashlib.sha256(text.replace("\n", "\r\n").encode("utf-8")).hexdigest(),
    }



NORMALIZER_OLD_HASHES = {
    '13085bba8c19fdd9618a4c856c817c78d32617b5c9cc4273b4d513cb619f76fb',
    '24106c29ad4ee54b1c8f7367c806a2efae151319738ca9df1a92c03b0fb0daf1',
    'e2f2eea8cc08bb232a62870cba287eaab6b02b108063f833ce338d50651a2879',
    'f2e7a473427cef0617a8ad1b6dedb0ca63a662ca3a85930f0bff7589a3d67b71',
}
NORMALIZER_REVIEWED_HASH = '6a33c547740bbe8d73f2220ac4cb334cd0f660a665637aa5ecedfe9f3acd9a2e'


def reviewed_normalizer_transition(relative: str, previous: str) -> bool:
    import verify
    return (relative == 'tools/baseline/ContactNormalizer.php' and previous in NORMALIZER_OLD_HASHES
            and verify.harness_source_sha(ROOT / relative, HARNESS_HASHING) == NORMALIZER_REVIEWED_HASH)


def reviewed_accounting_transition(relative: str, previous: str) -> bool:
    import verify
    # Exact reviewed normalization-loss -> checkpoint-billing-loss extension.
    # Historical receipts/audits are not rewritten or relabelled by this guard.
    return (relative == "tools/baseline/orphan_ledger.py"
            and previous == "907a0ae2bbb225ceae7d45275c8076ba3fa57981c67f5ec949471a52cafa496c"
            and verify.harness_source_sha(ROOT / relative, HARNESS_HASHING) == "bf90985a2a36f641bf1fceaccd492cc080645871400477659a27248583d7286d")


def prepare_resume(source: Path, definitions: list[dict], description: dict,
                   php: Path, prototype: Path, is_test: bool) -> dict:
    """Validate every saved case, then reproduce the exact network-free frontier."""
    import verify
    source = source.resolve(strict=True)
    if not source.is_relative_to(LOCAL.resolve()) or source == LOCAL.resolve():
        raise CaptureError("resume_source_must_be_task_local")
    try:
        source = verify.safe_path(LOCAL, source.relative_to(LOCAL.resolve()).as_posix(), directory=True)
        inventory = verify.files(source)
        if "manifest.json" in inventory:
            raise CaptureError("resume_source_already_finalized")
        for name in ARTIFACT_NAMES.values():
            verify.safe_path(source, name)
        if digest(source / "baseline-manifest.json") != digest(BASELINE / "manifest.json"):
            raise CaptureError("resume_baseline_differs")
        if digest(source / "source-fingerprints.json") != digest(BASELINE / "source-fingerprints.json"):
            raise CaptureError("resume_source_fingerprints_differ")
        if verify.digest_value(verify.read(source / "config.json")) != verify.digest_value(description["config"]):
            raise CaptureError("resume_config_differs")
        fingerprints = verify.read(BASELINE / "source-fingerprints.json")
        prompts = {name: sha for name, sha in fingerprints.items() if name.startswith("resources/views/prompts/")}
        if verify.read(source / "prompts.json") != prompts:
            raise CaptureError("resume_prompts_differ")
        old_harness = verify.read(source / "harness.json")
        old_hashing, old_harness_files = verify.parse_harness(old_harness)
        allowed_changes = {"tools/baseline/RecordedClient.php", "tools/baseline/capture.py",
                           "tools/baseline/capture_broker.py", "tools/baseline/verify.py"}
        if set(old_harness_files) - set(HARNESS) or set(HARNESS) - set(old_harness_files) - {"tools/baseline/verify.py", "tools/baseline/orphan_ledger.py", "tools/baseline/reconcile.py"}:
            raise CaptureError("resume_harness_inventory_differs")
        for name, sha in old_harness_files.items():
            verify.digest(sha)
            if (name not in allowed_changes and not source_hash_matches(ROOT / name, old_hashing, sha)
                and not reviewed_normalizer_transition(name, sha) and not reviewed_accounting_transition(name, sha)):
                raise CaptureError("resume_semantic_harness_changed")
        definitions_by_id = {item["id"]: item for item in definitions}
        provenance = "synthetic" if is_test else "recorded"
        per_case = {}
        receipt_ids = set()
        records = []
        prefix = []
        run_id = None
        for definition in definitions:
            case_id = definition["id"]
            names = sorted(name for name in inventory if name.startswith(f"llm/{case_id}/"))
            if len(names) > 200 or names != [f"llm/{case_id}/{i:04d}.json" for i in range(1, len(names) + 1)]:
                raise CaptureError("resume_prefix_gap_extra_or_wrong_case")
            phase = "setup" if "scheduler_hours" in definition["scenario"] else "dialogue"
            items = []
            for sequence, name in enumerate(names, 1):
                record = verify.read(source / name)
                evidence = record.get("evidence", {})
                if run_id is None:
                    run_id = evidence.get("run_id")
                if (not isinstance(run_id, str) or not re.fullmatch(r"[a-zA-Z0-9_-]{1,80}", run_id)
                    or record.get("schema_version") != 1 or record.get("provenance") != provenance
                    or evidence.get("run_id") != run_id or evidence.get("case_id") != case_id
                    or evidence.get("sequence") != sequence or evidence.get("phase") != phase
                    or record.get("request_sha256") != verify.digest_value(record.get("request"))
                    or record.get("response_sha256") != verify.digest_value(record.get("response"))
                    or evidence.get("request_body_sha256") != verify.digest_value(evidence.get("request_body"))):
                    raise CaptureError("resume_record_identity_or_hash_differs")
                verify.no_secrets(record)
                verify.verify_wire(record["request"], evidence["request_body"])
                if not is_test:
                    verify.validate_call(record, {"case_id": case_id},
                                         {"sequence": sequence, "phase": phase}, run_id, receipt_ids)
                records.append(record)
                items.append(record)
                prefix.append({"case_id": case_id, "sequence": sequence, "path": name, "sha256": inventory[name]})
            per_case[case_id] = items
        if not records:
            raise CaptureError("resume_has_no_saved_responses")
        source_artifacts = {key: {"path": name, "sha256": inventory[name]} for key, name in ARTIFACT_NAMES.items()}
        lineage_files = set(ARTIFACT_NAMES.values())
        sidecar = source.with_name(source.name + ".resume.json")
        history = []
        sidecar_hash = None
        if any(name.startswith("lineage/") for name in inventory) or sidecar.exists():
            sidecar = verify.safe_path(LOCAL, sidecar.relative_to(LOCAL.resolve()).as_posix())
            sidecar_hash = digest(sidecar)
            saved = verify.read(sidecar)
            if (not isinstance(saved, dict)
                or set(saved) != {"schema_version", "output_harness_sha256", "resume"}
                or saved["schema_version"] != 1
                or saved["output_harness_sha256"] != inventory["harness.json"]):
                raise CaptureError("resume_sidecar_invalid")
            prior = saved["resume"]
            envelope = {"run_id": run_id, "resume": prior, "artifacts": source_artifacts}
            chain = verify.validate_resume(source, envelope, {"harness": old_harness}, lineage_files,
                                           source_provenance="synthetic" if is_test else "recorded")
            for reference, record in zip(prefix, records):
                verify.validate_resume_call(chain, {"case_id": reference["case_id"]}, reference, record)
            history = prior.get("ancestors", []) + [{key: value for key, value in prior.items() if key != "ancestors"}]
            if len(history) >= 200:
                raise CaptureError("resume_lineage_limit")
        case_files = {definition["id"]: f"cases/{definition['id']}.json" for definition in definitions
                      if f"cases/{definition['id']}.json" in inventory}
        if set(inventory) != lineage_files | {entry["path"] for entry in prefix} | set(case_files.values()):
            raise CaptureError("resume_prefix_gap_extra_or_wrong_case")
        completed = {}
        completed_passes = {}
        consumed = {}
        next_requests = {}
        completed_trace = {}
        frontier = None

        def version_set(artifacts):
            return {"harness_sha256": artifacts["harness"]["sha256"],
                    "prototype_source_sha256": artifacts["source_fingerprints"]["sha256"],
                    "config_sha256": artifacts["config"]["sha256"],
                    "prompts_sha256": artifacts["prompts"]["sha256"],
                    "normalization": "contacts-v1"}

        def check_historical_fixture(path, case_id, artifacts):
            fixture = verify.read(path)
            result = completed.get(case_id)
            if result is None:
                raise CaptureError("resume_historical_case_not_complete")
            definition = definitions_by_id[case_id]
            sequences = result["consumed_sequences"]
            if (fixture.get("schema_version") != 2 or fixture.get("case_id") != case_id
                or fixture.get("kind") != definition["kind"] or fixture.get("provenance") != provenance
                or fixture.get("capture", {}).get("run_id") != run_id
                or fixture.get("versions") != version_set(artifacts)
                or fixture.get("execution", {}).get("scenario") != definition["scenario"]
                or fixture.get("execution", {}).get("scenario_sha256") != definition["scenario_sha256"]
                or fixture.get("trace") != result["trace"] or fixture.get("llm_calls") != result["calls"]
                or fixture.get("replay") != {
                    "passes": 2, "trace_sha256": result["trace_sha256"],
                    "run_trace_sha256": [result["trace_sha256"], result["trace_sha256"]],
                    "consumed_sequences": [sequences, sequences], "network_calls": 0}):
                raise CaptureError("resume_historical_fixture_differs")
            verify.no_secrets(fixture)

        for definition in definitions:
            case_id = definition["id"]
            count = len(per_case[case_id])
            if frontier is not None:
                if count or case_id in case_files:
                    raise CaptureError("resume_data_after_unfinished_case")
                continue
            arguments = [f"--case={case_id}", f"--output={source}", f"--run-id={run_id}", "--mode=replay"]
            passes = [run_child(php, prototype, arguments, resume_prefix=count,
                                prefix_only=True, prefix_provenance=provenance) for _ in range(2)]
            if passes[0] != passes[1]:
                raise CaptureError("resume_prefix_replay_differs")
            checked = passes[0]
            expected_sequences = list(range(1, count + 1))
            expected_calls = [{"sequence": entry["sequence"], "path": entry["path"], "sha256": entry["sha256"],
                               "phase": "setup" if "scheduler_hours" in definition["scenario"] else "dialogue"}
                              for entry in prefix if entry["case_id"] == case_id]
            if (checked.get("op") not in ("prefix_ready", "complete")
                or checked.get("consumed_sequences") != expected_sequences or checked.get("network_calls") != 0
                or checked.get("calls") != expected_calls):
                raise CaptureError("resume_prefix_replay_incomplete")
            consumed[case_id] = expected_sequences
            if checked["op"] == "complete":
                if not count or checked.get("definition") != definition:
                    raise CaptureError("resume_case_definition_differs")
                completed[case_id] = checked
                completed_passes[case_id] = passes
                completed_trace[case_id] = checked["trace_sha256"]
                if case_id in case_files:
                    check_historical_fixture(source / case_files[case_id], case_id, source_artifacts)
            else:
                if count >= 200 or case_id in case_files:
                    raise CaptureError("resume_incomplete_case_claim_or_limit")
                next_request = checked.get("next_request", {})
                if set(next_request) != {"request_sha256", "request_body_sha256"}:
                    raise CaptureError("resume_next_request_missing")
                for value in next_request.values():
                    verify.digest(value)
                next_requests[case_id] = next_request
                frontier = {"case_id": case_id, "count": count, "next_request": next_request}
        proof = {"consumed_sequences": consumed, "next_requests": next_requests,
                 "completed_trace_sha256": completed_trace}
        proof_hash = verify.digest_value(proof)
        lineage = {
            "schema_version": 1, "source_run_id": run_id,
            "source_artifacts": {key: {"path": "lineage/source/" + name, "sha256": inventory[name]}
                                 for key, name in ARTIFACT_NAMES.items()},
            "completed_cases": [{"case_id": case_id, "path": "lineage/source/" + name, "sha256": inventory[name]}
                                for case_id, name in case_files.items()],
            "prefix": prefix,
            "preflight": {"passes": 2, "network_calls": 0, **proof, "pass_sha256": [proof_hash, proof_hash]},
        }
        copies = [(name, "lineage/source/" + name, inventory[name]) for name in ARTIFACT_NAMES.values()]
        copies += [(name, "lineage/source/" + name, inventory[name]) for name in case_files.values()]
        ancestors = []
        for index, historical in enumerate(history, 1):
            if prefix[:len(historical["prefix"])] != historical["prefix"]:
                raise CaptureError("resume_historical_prefix_differs")
            for key, checksum in historical["preflight"]["completed_trace_sha256"].items():
                if completed_trace.get(key) != checksum:
                    raise CaptureError("resume_historical_trace_differs")
            for case_id, expected_next in historical["preflight"]["next_requests"].items():
                count = len(historical["preflight"]["consumed_sequences"][case_id])
                if len(per_case[case_id]) > count:
                    record = per_case[case_id][count]
                    if (record["request_sha256"] != expected_next["request_sha256"]
                        or verify.digest_value(verify.resume_donor_body(record)) != expected_next["request_body_sha256"]):
                        raise CaptureError("resume_historical_tail_differs")
                elif next_requests.get(case_id) != expected_next:
                    raise CaptureError("resume_historical_boundary_missing")
            for reference in historical["completed_cases"]:
                check_historical_fixture(source / reference["path"], reference["case_id"], historical["source_artifacts"])
            ancestor = copy.deepcopy(historical)
            for key, filename in ARTIFACT_NAMES.items():
                original = historical["source_artifacts"][key]
                target = f"lineage/ancestors/{index:04d}/{filename}"
                copies.append((original["path"], target, original["sha256"]))
                ancestor["source_artifacts"][key]["path"] = target
            for reference in ancestor["completed_cases"]:
                original_path = reference["path"]
                reference["path"] = f"lineage/ancestors/{index:04d}/cases/{reference['case_id']}.json"
                copies.append((original_path, reference["path"], reference["sha256"]))
            ancestors.append(ancestor)
        if ancestors:
            lineage["ancestors"] = ancestors
        if sidecar_hash is not None and digest(sidecar) != sidecar_hash:
            raise CaptureError("resume_source_changed")
        if verify.files(source) != inventory:
            raise CaptureError("resume_source_changed")
        return {"source": source, "records": records, "run_id": run_id,
                "captured_at": records[0]["evidence"]["captured_at"], "lineage": lineage,
                "inventory": inventory, "copies": copies, "completed": completed,
                "completed_passes": completed_passes, "frontier": frontier,
                "sidecar_hash": sidecar_hash,
                # Compatibility for operator-focused tests, never union ledger.
                "case_id": frontier["case_id"] if frontier else None,
                "count": frontier["count"] if frontier else 0,
                "next_request": frontier["next_request"] if frontier else None}
    except CaptureError:
        raise
    except Exception:
        raise CaptureError("resume_source_invalid") from None



def validate_resume_ledger(ledger: Path, resume: dict) -> dict | None:
    """Called while the broker holds its process lock. Never mutates the ledger."""
    from capture_broker import encoded
    try:
        with closing(sqlite3.connect(ledger.resolve().as_uri() + "?mode=ro", uri=True)) as db:
            rows = {row[0]: row[1:] for row in db.execute(
                "SELECT id,request_hash,model,reserved_micros,charged_micros,state FROM calls")}
        identities = []
        for record in resume["records"]:
            evidence = record["evidence"]
            budget = evidence.get("budget") or {}
            call_id = budget.get("call_id")
            if type(call_id) is not int or call_id <= 0 or call_id in identities:
                raise CaptureError("resume_ledger_identity_invalid")
            wire = evidence["request_body"]
            expected = (hashlib.sha256(encoded(wire)).hexdigest(), wire["model"],
                        budget.get("reserved_micros"), budget.get("charged_micros"), "completed")
            if rows.get(call_id) != expected:
                raise CaptureError("resume_ledger_receipt_differs")
            import verify
            directory = Path(str(ledger.resolve()) + ".checkpoints")
            checkpoint = verify.read(verify.safe_path(directory, f"{call_id:06d}.receipt.json"))
            if (checkpoint.get("call_id") != call_id
                or checkpoint.get("generation_id") != evidence.get("provider_request_id")
                or checkpoint.get("request_sha256") != expected[0]
                or checkpoint.get("model_requested") != wire["model"]
                or checkpoint.get("replay_eligible") is not False
                or checkpoint.get("response_retained") is not False):
                raise CaptureError("resume_ledger_checkpoint_differs")
            identities.append(call_id)
        if identities != sorted(identities):
            raise CaptureError("resume_ledger_order_differs")
        # Only an explicitly audited lost reply permits one new paid reservation.
        from orphan_ledger import plan_resume
        with closing(sqlite3.connect(ledger.resolve().as_uri() + "?mode=ro", uri=True)) as db:
            return plan_resume(db, ledger, resume, identities)
    except CaptureError:
        raise
    except Exception as error:
        raise CaptureError(getattr(error, 'code', 'resume_ledger_invalid')) from None


def validate_fresh_ledger(ledger: Path) -> None:
    """A new output is not permission to pay again for previously saved work."""
    try:
        with closing(sqlite3.connect(ledger.resolve().as_uri() + "?mode=ro", uri=True)) as db:
            exists = db.execute("SELECT 1 FROM calls WHERE state='completed' LIMIT 1").fetchone()
        if exists:
            raise CaptureError("existing_paid_calls_require_resume")
    except CaptureError:
        raise
    except Exception:
        raise CaptureError("fresh_ledger_invalid") from None


def validate_resume_source(resume: dict) -> None:
    import verify
    if verify.files(resume["source"]) != resume["inventory"]:
        raise CaptureError("resume_source_changed")
    sidecar = resume["source"].with_name(resume["source"].name + ".resume.json")
    expected = resume.get("sidecar_hash")
    if (expected is None and sidecar.exists()) or (expected is not None and digest(sidecar) != expected):
        raise CaptureError("resume_source_changed")


def resume_handler(handler: Callable[[dict], dict], resume: dict) -> Callable[[dict], dict]:
    """First tail request equals both network-free prefix observations."""
    import verify
    first = True
    def guarded(message: dict) -> dict:
        nonlocal first
        if first:
            expected = resume["next_request"]
            if (expected is None or message.get("case_id") != resume["case_id"]
                or message.get("sequence") != resume["count"] + 1
                or verify.digest_value(message.get("request")) != expected["request_sha256"]
                or verify.digest_value(message.get("body")) != expected["request_body_sha256"]):
                raise CaptureError("resume_tail_request_differs")
            first = False
        return handler(message)
    return guarded



def _execute(args: argparse.Namespace, cleanup: ExitStack, *, test_broker=None) -> dict:
    # Internal integration seam only: argparse never exposes this dependency.
    # A supplied transport cannot produce recorded provenance or reach the task ledger.
    if test_broker is not None:
        from capture_broker import CaptureBroker, _HttpTransport
        if (args.test_case is None or args.allow_live_openrouter
            or not isinstance(test_broker, CaptureBroker) or test_broker._live is not False
            or test_broker._transport is None or isinstance(test_broker._transport, _HttpTransport)
            or test_broker.ledger_path != args.ledger.resolve()
            or not test_broker.ledger_path.is_relative_to(LOCAL.resolve())
            or test_broker.ledger_path == (LOCAL / "budget.sqlite").resolve()):
            raise CaptureError("test_broker_requires_synthetic_private_transport")
    prototype = args.prototype.resolve(strict=True)
    php = args.php.resolve(strict=True)
    expected_prototype = (ROOT / ".local/prototype").resolve(strict=True)
    if prototype != expected_prototype or not prototype.is_relative_to(ROOT.resolve()):
        raise CaptureError("prototype_must_be_task_local")
    if not LOCAL.resolve().is_relative_to(ROOT.resolve()):
        raise CaptureError("task_local_directory_escaped")
    if args.test_case is None and args.ledger.resolve() != (LOCAL / "budget.sqlite").resolve():
        raise CaptureError("canonical_task_budget_required")
    if "/.local/references/" in prototype.as_posix().lower() or (prototype / ".env").exists():
        raise CaptureError("unsafe_prototype")
    fingerprints = json.loads((BASELINE / "source-fingerprints.json").read_text(encoding="utf-8"))
    for name, expected in fingerprints.items():
        path = prototype / name
        if not path.is_file() or digest(path) != expected:
            raise CaptureError("donor_source_changed")
    execution_harness = {name: digest(ROOT / name) for name in HARNESS}
    description = run_child(php, prototype, ["--action=describe"])
    definitions = description["cases"]
    expected = json.loads((BASELINE / "manifest.json").read_text(encoding="utf-8"))["cases"]
    if {x["id"] for x in definitions} != {x["id"] for x in expected} or len(definitions) != 41:
        raise CaptureError("case_coverage_changed")
    is_test = args.test_case is not None
    resume = None
    resume_source = getattr(args, "resume_from", None)
    if resume_source is not None:
        if is_test and args.test_case != "all":
            definitions = [x for x in definitions if x["id"] == args.test_case]
            if not definitions:
                raise CaptureError("unknown_test_case")
        resume = prepare_resume(resume_source, definitions, description, php, prototype, is_test)
    broker = None
    pending_replacement = None
    if is_test:
        if args.allow_live_openrouter:
            raise CaptureError("test_and_live_are_mutually_exclusive")
        if test_broker is None:
            fake = FakeBroker()
            if resume and hasattr(fake, "calls"):
                fake.calls = len(resume["records"])
            handler = fake
        else:
            # Caller owns this private fake broker's lifecycle across assertions.
            broker = test_broker
        if args.test_case != "all":
            definitions = [x for x in definitions if x["id"] == args.test_case]
            if not definitions:
                raise CaptureError("unknown_test_case")
    else:
        if not args.allow_live_openrouter or not args.acknowledge_pseudonymized_inputs:
            raise CaptureError("explicit_live_opt_ins_required")
        # Module import cannot initiate network. Its preflight checks budget and
        # operator credentials before any donor LLM request may reach a transport.
        from capture_broker import CaptureBroker
        broker = CaptureBroker(
            key_file=args.key_file.resolve(strict=True),
            catalog_file=args.catalog.resolve(strict=True),
            ledger_path=args.ledger.resolve(),
            limit_usd="50", allow_live=True,
        )
        cleanup.callback(broker.close)
    if broker is not None:
        if resume is not None:
            validate_resume_source(resume)
            pending_replacement = validate_resume_ledger(args.ledger, resume)
        else:
            validate_fresh_ledger(args.ledger)
        models = sorted(set(description["config"]["agent"]["models"].values()))
        broker.preflight(models)
        def handler(message):
            nonlocal pending_replacement
            context = pending_replacement
            if context is not None:
                import verify
                if (message.get('case_id') != context['case_id'] or message.get('sequence') != context['sequence']
                    or verify.digest_value(message.get('request')) != context['request_sha256']
                    or verify.digest_value(message.get('body')) != context['request_body_sha256']):
                    raise CaptureError('orphan_frontier_mismatch')
            result = broker.call(message['body'], replacement=context) if context is not None else broker.call(message['body'])
            pending_replacement = None
            return result
    if args.preflight_only:
        return {"result": "preflight_passed", "real_llm_calls": 0, "cases": len(definitions)}
    if any(digest(ROOT / name) != checksum for name, checksum in execution_harness.items()):
        raise CaptureError("harness_changed_during_preflight")
    if resume:
        validate_resume_source(resume)
    output = args.output.resolve()
    if not output.is_relative_to(LOCAL.resolve()) or output == LOCAL.resolve() or output.exists():
        raise CaptureError("output_must_be_new_task_local_directory")
    output.mkdir(parents=True)
    run_id = resume["run_id"] if resume else ("test_" if is_test else "capture_") + datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S") + "_" + os.urandom(4).hex()
    captured_at = resume["captured_at"] if resume else utc_now()
    artifacts = {}
    for key, source, filename in [
        ("baseline_manifest", BASELINE / "manifest.json", "baseline-manifest.json"),
        ("source_fingerprints", BASELINE / "source-fingerprints.json", "source-fingerprints.json"),
    ]:
        copy_new(source, output / filename)
        artifacts[key] = {"path": filename, "sha256": digest(output / filename)}
    prompts = {name: sha for name, sha in fingerprints.items() if name.startswith("resources/views/prompts/")}
    harness = {}
    for name in HARNESS:
        if not (ROOT / name).is_file():
            if is_test and name.endswith("capture_broker.py"):
                continue
            raise CaptureError("capture_harness_incomplete")
        harness[name] = execution_harness[name]
    import verify
    harness = {"schema_version": 2, "hashing": HARNESS_HASHING,
               "files": {name: verify.harness_source_sha(ROOT / name, HARNESS_HASHING) for name in harness}}
    for key, value in [("config", description["config"]), ("prompts", prompts), ("harness", harness)]:
        filename = key + ".json"
        write_new(output / filename, value)
        artifacts[key] = {"path": filename, "sha256": digest(output / filename)}
    versions = {
        "harness_sha256": artifacts["harness"]["sha256"],
        "prototype_source_sha256": artifacts["source_fingerprints"]["sha256"],
        "config_sha256": artifacts["config"]["sha256"],
        "prompts_sha256": artifacts["prompts"]["sha256"],
        "normalization": "contacts-v1",
    }
    if resume:
        for source_name, target_name, checksum in resume["copies"]:
            copy_new(resume["source"] / source_name, output / target_name, checksum)
        for key, entry in resume["lineage"]["source_artifacts"].items():
            if key != "harness" and entry["sha256"] != artifacts[key]["sha256"]:
                raise CaptureError("resume_artifact_bytes_differ")
        for entry in resume["lineage"]["prefix"]:
            copy_new(resume["source"] / entry["path"], output / entry["path"], entry["sha256"])
        write_new(output.with_name(output.name + ".resume.json"), {
            "schema_version": 1, "output_harness_sha256": artifacts["harness"]["sha256"],
            "resume": resume["lineage"],
        })
    cases = []
    for definition in definitions:
        case_id = definition["id"]
        arguments = [f"--case={case_id}", f"--output={output}", f"--run-id={run_id}"]
        completed_from_source = resume is not None and case_id in resume["completed"]
        continuing = resume is not None and resume["frontier"] is not None and case_id == resume["frontier"]["case_id"]
        if completed_from_source:
            # Both fresh network-free executions happened before broker creation.
            # Regenerate only the fixture projection; never redo a completed POST.
            capture = resume["completed"][case_id]
            replay_runs = resume["completed_passes"][case_id]
        else:
            capture = run_child(php, prototype, arguments + [f"--mode={'test' if is_test else 'capture'}"],
                                resume_handler(handler, resume["frontier"]) if continuing else handler,
                                resume_prefix=resume["frontier"]["count"] if continuing else 0,
                                prefix_provenance=("synthetic" if is_test else "recorded") if continuing else None)
            replay_runs = [run_child(php, prototype, arguments + ["--mode=replay"]) for _ in range(2)]
        for replay in replay_runs:
            if replay["trace_sha256"] != capture["trace_sha256"] or replay["trace"] != capture["trace"] or replay["calls"] != capture["calls"]:
                raise CaptureError("offline_replay_differs")
            if replay["consumed_sequences"] != capture["consumed_sequences"] or replay["network_calls"] != 0:
                raise CaptureError("offline_replay_incomplete")
        if capture["definition"] != definition:
            raise CaptureError("case_definition_changed")
        provenance = "synthetic" if is_test else "recorded"
        fixture = {
            "schema_version": 2, "case_id": case_id, "kind": definition["kind"], "provenance": provenance,
            "capture": {"run_id": run_id, "method": "test" if is_test else ("import" if completed_from_source else "live"),
                        "provider": "fake IPC broker" if is_test else "openrouter",
                        "captured_at": captured_at,
                        "seed": {"runner": 20261031, "requested": None, "provider_support": "not_requested"},
                        "inputs_pseudonymized": True, "byte_identical_persona_inputs": False},
            "versions": versions,
            "execution": {"layer": definition["execution_layer"],
                          "input_provenance": "donor_persona" if definition["kind"] == "persona" else "authored_script",
                          "scenario": definition["scenario"], "scenario_sha256": definition["scenario_sha256"]},
            "trace": capture["trace"], "llm_calls": capture["calls"],
            "replay": {"passes": 2, "trace_sha256": capture["trace_sha256"],
                       "run_trace_sha256": [x["trace_sha256"] for x in replay_runs],
                       "consumed_sequences": [x["consumed_sequences"] for x in replay_runs], "network_calls": 0},
        }
        name = f"cases/{case_id}.json"
        if broker is not None:
            from orphan_ledger import read_orphan_state
            audits, _ = read_orphan_state(broker._connection, args.ledger)
            replaced = sorted(identity for identity, entry in audits.items()
                              if entry['audit']['source']['case_id'] == case_id)
            if replaced:
                fixture['capture']['replacement_for_call_ids'] = replaced
        write_new(output / name, fixture)
        cases.append({"id": case_id, "kind": definition["kind"], "provenance": provenance,
                      "fixture": name, "fixture_sha256": digest(output / name),
                      "execution_layer": definition["execution_layer"]})
        print(json.dumps({"event": "case_verified", "case_id": case_id,
                          "llm_calls": len(capture["calls"]), "offline_replays": 2,
                          "provenance": provenance}), flush=True)
    # A source/harness change during a long capture cannot silently change versions.
    if any(digest(ROOT / name) != sha for name, sha in execution_harness.items()):
        raise CaptureError("harness_changed_during_capture")
    manifest = {
        "schema_version": 2, "run_id": run_id, "captured_at": captured_at,
        "equivalence_claim": False, "versions": versions, "artifacts": artifacts,
        "cases": cases, "coverage": {"total_cases": len(cases),
            "personas": sum(x["kind"] == "persona" for x in cases),
            "manual": sum(x["kind"] == "manual" for x in cases),
            "recorded_real_llm": 0 if is_test else len(cases),
            "synthetic": len(cases) if is_test else 0, "missing": 0},
    }
    if not is_test and len(cases) != 41:
        raise CaptureError("real_bundle_incomplete")
    if resume:
        validate_resume_source(resume)
        manifest["resume"] = resume["lineage"]
        for entry in resume["lineage"]["prefix"]:
            if digest(output / entry["path"]) != entry["sha256"]:
                raise CaptureError("resume_prefix_changed")
    if broker is not None:
        records = [json.loads((output / reference['path']).read_text(encoding='utf-8'))
                   for item in cases for reference in json.loads((output / item['fixture']).read_text(encoding='utf-8'))['llm_calls']]
        abandoned = export_abandoned_calls(broker._connection, args.ledger, run_id, records, output)
        if abandoned:
            manifest['abandoned_calls'] = abandoned
    write_new(output / "manifest.json", manifest)
    return {"result": "passed", "cases": len(cases), "offline_replays_per_case": 2,
            "provenance": "synthetic" if is_test else "recorded",
            "real_llm_calls": 0 if is_test else sum(len(json.loads((output / x["fixture"]).read_text(encoding="utf-8"))["llm_calls"]) for x in cases),
            "output": str(output)}


def export_abandoned_calls(db, ledger: Path, run_id: str, records: list[dict], output: Path) -> list[dict]:
    from orphan_ledger import BILLING_REASON, exported_entries, exported_reconciliation
    abandoned = []
    for audit, replacement_id, record in exported_entries(db, ledger, run_id, records):
        audit_path = f"lineage/abandoned/{audit['call_id']:06d}.audit.json"
        receipt_path = f"lineage/abandoned/{audit['call_id']:06d}.receipt.json"
        write_new(output / audit_path, audit)
        copy_new(Path(str(ledger) + '.checkpoints') / f"{audit['call_id']:06d}.receipt.json",
                 output / receipt_path, audit['receipt_checkpoint_sha256'])
        evidence = record['evidence']
        call_path = f"llm/{evidence['case_id']}/{evidence['sequence']:04d}.json"
        entry = {'audit': {'path': audit_path, 'sha256': digest(output / audit_path)},
                 'receipt_checkpoint': {'path': receipt_path, 'sha256': digest(output / receipt_path)},
                 'replacement': {'case_id': evidence['case_id'], 'sequence': evidence['sequence'],
                                 'path': call_path, 'sha256': digest(output / call_path), 'call_id': replacement_id}}
        if audit['reason'] == BILLING_REASON:
            settlement = exported_reconciliation(db, ledger, audit['call_id'])
            if settlement['source_audit_sha256'] != audit['reconciliation_audit_sha256']:
                raise CaptureError('orphan_reconciliation_invalid')
            path = f"lineage/abandoned/{audit['call_id']:06d}.reconciliation.json"
            write_new(output / path, settlement)
            entry['reconciliation'] = {'path': path, 'sha256': digest(output / path)}
        abandoned.append(entry)
    return abandoned


def execute(args: argparse.Namespace, *, test_broker=None) -> dict:
    with ExitStack() as cleanup:
        return _execute(args, cleanup, test_broker=test_broker)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--prototype", type=Path, default=ROOT / ".local/prototype")
    parser.add_argument("--php", type=Path, default=Path("C:/OSPanel/modules/PHP-8.4/php.exe"))
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--key-file", type=Path, default=ROOT / ".local/secrets/openrouter.key")
    parser.add_argument("--catalog", type=Path, default=LOCAL / "catalog.json")
    parser.add_argument("--ledger", type=Path, default=LOCAL / "budget.sqlite")
    parser.add_argument("--allow-live-openrouter", action="store_true")
    parser.add_argument("--acknowledge-pseudonymized-inputs", action="store_true")
    parser.add_argument("--preflight-only", action="store_true")
    parser.add_argument("--test-case", help="One case ID or 'all'; always synthetic, zero HTTP.")
    parser.add_argument("--resume-from", type=Path, help="First unfinished case; exact offline prefix then live tail into a NEW output.")
    args = parser.parse_args()
    try:
        result = execute(args)
    except Exception as error:
        # No raw exception, URL, headers, provider payload or filesystem secret.
        code = str(error) if isinstance(error, CaptureError) else getattr(error, "code", "capture_failed")
        print(json.dumps({"result": "stopped", "code": code}), file=sys.stderr)
        return 1
    print(json.dumps(result, ensure_ascii=False), flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
