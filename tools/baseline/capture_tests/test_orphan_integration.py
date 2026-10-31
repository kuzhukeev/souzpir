
"""Private fake-HTTP capture of a lost settled reply and its explicit replacement.

The real production handler/export code runs through the keyword-only synthetic
test seam. Artifacts are never relabeled recorded and no live key/ledger is read.
"""
from __future__ import annotations
import argparse
from contextlib import ExitStack, closing
import json
from pathlib import Path
import sqlite3
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import capture
import verify
from capture_broker import CaptureBroker, MODELS, _HttpTransport
from orphan_ledger import OrphanDisposer
from test_integration import FakeProductionTransport, FAKE_KEY

PHP = Path("C:/OSPanel/modules/PHP-8.4/php.exe")
PROTOTYPE = capture.ROOT / ".local/prototype"


def query(ledger, statement):
    with closing(sqlite3.connect(ledger)) as db:
        return db.execute(statement).fetchall()


def verify_synthetic_export(output):
    manifest = verify.read(output / "manifest.json")
    used = {"manifest.json"}
    payloads = {}
    for key, ref in manifest["artifacts"].items():
        assert verify.sha(output / ref["path"]) == ref["sha256"]
        used.add(ref["path"])
        payloads[key] = verify.read(output / ref["path"])
    hashing, harness = verify.parse_harness(payloads["harness"])
    for name, expected in harness.items():
        assert verify.harness_source_sha(capture.ROOT / name, hashing) == expected
    levels = verify.validate_resume(output, manifest, payloads, used, source_provenance="synthetic")
    records, observed, annotations, receipt_ids = [], {}, {}, set()
    for item in manifest["cases"]:
        assert item["provenance"] == "synthetic"
        assert verify.sha(output / item["fixture"]) == item["fixture_sha256"]
        used.add(item["fixture"])
        case = verify.read(output / item["fixture"])
        assert case["provenance"] == "synthetic"
        assert case["capture"]["method"] == "test" and case["capture"]["provider"] == "fake IPC broker"
        annotations[case["case_id"]] = case["capture"].get("replacement_for_call_ids", [])
        verify.validate_resume_case(levels, case)
        trace_hash = verify.digest_value(case["trace"])
        assert case["replay"]["run_trace_sha256"] == [trace_hash, trace_hash]
        assert case["replay"]["network_calls"] == 0
        for ref in case["llm_calls"]:
            assert verify.sha(output / ref["path"]) == ref["sha256"]
            used.add(ref["path"])
            record = verify.read(output / ref["path"])
            assert record["provenance"] == "synthetic" and record["provider"] == "fake IPC broker"
            assert record["request_sha256"] == verify.digest_value(record["request"])
            assert record["response_sha256"] == verify.digest_value(record["response"])
            assert record["evidence"]["request_body_sha256"] == verify.digest_value(record["evidence"]["request_body"])
            identity = record["evidence"]["provider_request_id"]
            assert identity not in receipt_ids
            receipt_ids.add(identity)
            verify.no_secrets(record)
            verify.verify_wire(record["request"], record["evidence"]["request_body"])
            verify.validate_resume_call(levels, case, ref, record)
            observed[ref["path"]] = verify.call_audit_summary(case, ref, record)
            records.append(record)
    assert all(level["tail_checked"] == set(level["next_requests"]) for level in levels)
    audit = verify.validate_abandoned_calls(output, manifest, levels, used, observed, annotations)
    assert audit["abandoned_paid_calls"] == 1
    assert set(verify.files(output)) == used
    return manifest, records, audit


def simulate(directory: Path, *, full=False):
    directory = directory.resolve()
    if not directory.is_relative_to(capture.LOCAL.resolve()) or directory.exists():
        raise capture.CaptureError("simulation_requires_new_task_local_directory")
    directory.mkdir(parents=True)
    key = directory / "synthetic.key"
    key.write_text(FAKE_KEY, encoding="utf-8")
    catalog = directory / "catalog.json"
    catalog.write_text(json.dumps({"data": [{"id": model} for model in MODELS]}), encoding="utf-8")
    ledger = directory / "budget.sqlite"
    transport = FakeProductionTransport()
    description = capture.run_child(PHP, PROTOTYPE, ["--action=describe"])
    first = "persona.newbie_voronezh"
    selected = description["cases"] if full else [case for case in description["cases"] if case["id"] == first]
    loss_boundary = ("persona.experienced_kazan" if full else first, 2)
    later_stop = ("persona.for_self_wedding", 2) if full else None
    sources = [None, directory / "source-a", directory / "source-b"] if full else [None, directory / "source-a"]
    targets = [directory / "source-a", directory / "source-b", directory / "final"] if full else [directory / "source-a", directory / "final"]
    snapshots = {}
    original_run_child = capture.run_child
    lost_id, old_row, disposition = None, None, None
    strict_error = None
    with patch("urllib.request.OpenerDirector.open", side_effect=AssertionError("Real network forbidden")), \
         patch("socket.create_connection", side_effect=AssertionError("Real socket forbidden")):
        for stage, (source, target) in enumerate(zip(sources, targets)):
            stop = loss_boundary if stage == 0 else (later_stop if full and stage == 1 else None)
            after_settlement = stage == 0

            def child(php, prototype, arguments, handler=None, **kwargs):
                if handler is None:
                    return original_run_child(php, prototype, arguments, **kwargs)

                def intercepted(message):
                    current = (message["case_id"], message["sequence"])
                    if current == stop and not after_settlement:
                        raise capture.CaptureError("synthetic_stop_before_post")
                    transport.message = message
                    reply = handler(message)
                    if current == stop and after_settlement:
                        # Broker settlement/checkpoint completed. Deliberately lose
                        # the fake reply before PHP can write any replay record.
                        raise capture.CaptureError("synthetic_settled_response_lost")
                    return reply
                return original_run_child(php, prototype, arguments, intercepted, **kwargs)

            args = argparse.Namespace(
                prototype=PROTOTYPE, php=PHP, output=target, ledger=ledger,
                key_file=key, catalog=catalog, allow_live_openrouter=False,
                acknowledge_pseudonymized_inputs=False, preflight_only=False,
                test_case="all" if full else first, resume_from=source,
            )
            with CaptureBroker(key, catalog, ledger, transport=transport) as broker:
                if stage == 1:
                    assert capture.validate_resume_ledger(ledger, prepare(source)) == disposition["replacement_context"]
                with patch.object(capture, "run_child", side_effect=child):
                    try:
                        result = capture.execute(args, test_broker=broker)
                    except capture.CaptureError as error:
                        expected = "synthetic_settled_response_lost" if stage == 0 else "synthetic_stop_before_post"
                        if stop is None or str(error) != expected:
                            raise
                    else:
                        if stop is not None:
                            raise AssertionError("Expected synthetic interruption")
                summary = broker.summary()
            for original, before in snapshots.items():
                assert verify.files(original) == before, "Original source changed"
            if stop is not None:
                snapshots[target] = verify.files(target)
            if stage == 0:
                lost_id = len(transport.posts)
                expected_id = 4 if full else 2
                assert lost_id == expected_id
                old_row = query(ledger, f"SELECT * FROM calls WHERE id={lost_id}")
                assert old_row[0][5] == "completed"
                assert not (target / "llm" / loss_boundary[0] / "0002.json").exists()
                def prepare(path):
                    return capture.prepare_resume(path, selected, description, PHP, PROTOTYPE, True)
                before = query(ledger, "SELECT * FROM calls")
                try:
                    capture.validate_resume_ledger(ledger, prepare(target))
                except capture.CaptureError as error:
                    assert str(error) == "resume_settled_response_missing"
                else:
                    raise AssertionError("Unapproved paid loss was accepted")
                disposition = OrphanDisposer(ledger, prepare=prepare).dispose(
                    target, lost_id, recovery_unavailable=True, apply=True)
                assert query(ledger, "SELECT * FROM calls") == before
                assert not disposition["response_recovered"]
            if full and stage == 1:
                assert len(transport.posts) == 6, "Paid prefix or replacement repeated"
        output = directory / "final"
        manifest, records, audit = verify_synthetic_export(output)
        assert query(ledger, f"SELECT * FROM calls WHERE id={lost_id}") == old_row
        assert len(records) == (116 if full else 2)
        assert len(transport.posts) == len(records) + 1
        assert query(ledger, "SELECT orphan_call_id,replacement_call_id FROM capture_orphan_replacements") == [(lost_id, lost_id + 1)]
        assert query(ledger, "SELECT SUM(charged_micros) FROM calls")[0][0] == summary["spent_micros"]
        assert sum(row["evidence"]["budget"]["charged_micros"] for row in records) + audit["abandoned_charged_micros"] == summary["spent_micros"]
        assert capture.validate_resume_ledger(ledger, {"records": records, "run_id": manifest["run_id"], "frontier": None}) is None
        assert manifest["coverage"]["recorded_real_llm"] == 0 and manifest["coverage"]["synthetic"] == (41 if full else 1)
        if full:
            try:
                verify.validate(output, require_recorded=True)
            except ValueError as error:
                strict_error = str(error)
                assert "required recorded evidence" in strict_error
            else:
                raise AssertionError("Synthetic capture was accepted as recorded")
        return {
            "result": "passed", "synthetic_cases": len(manifest["cases"]),
            "retained_fake_responses": len(records), "fake_physical_posts": len(transport.posts),
            "explicit_paid_lost_calls": 1, "replacement_bindings": 1,
            "final_offline_replay_receipts": len(manifest["cases"]) * 2,
            "resume_levels": len(manifest["resume"].get("ancestors", [])) + 1,
            "source_bytes_unchanged": True, "lost_call_charge_unchanged": True,
            "strict_recorded_rejection": strict_error,
            "real_network_calls": 0, "output": str(output),
        }


class OrphanIntegrationTests(unittest.TestCase):
    def test_settled_loss_replacement_and_export_with_synthetic_provenance(self):
        parent = capture.LOCAL / "integration-tests"
        parent.mkdir(exist_ok=True, parents=True)
        with tempfile.TemporaryDirectory(dir=parent) as temp:
            result = simulate(Path(temp) / "simulation")
            self.assertEqual(result["fake_physical_posts"], 3)
            self.assertEqual(result["explicit_paid_lost_calls"], 1)
            self.assertEqual(result["final_offline_replay_receipts"], 2)

    def test_injected_broker_cannot_claim_live_or_use_real_transport(self):
        broker = object.__new__(CaptureBroker)
        broker._live = False
        broker._transport = object()
        broker.ledger_path = capture.LOCAL / "integration-tests" / "unused.sqlite"
        options = argparse.Namespace(test_case=None, allow_live_openrouter=False, ledger=broker.ledger_path)
        for changes in (
            {}, {"test_case": "all", "allow_live_openrouter": True},
            {"test_case": "all", "allow_live_openrouter": False, "_live": True},
            {"test_case": "all", "allow_live_openrouter": False, "_transport": object.__new__(_HttpTransport)},
            {"test_case": "all", "allow_live_openrouter": False, "_transport": None},
            {"test_case": "all", "allow_live_openrouter": False, "_live": False, "ledger": capture.LOCAL / "budget.sqlite"},
        ):
            broker._live = changes.get("_live", False)
            broker._transport = changes.get("_transport", object())
            options.test_case = changes.get("test_case")
            options.allow_live_openrouter = changes.get("allow_live_openrouter", False)
            options.ledger = changes.get("ledger", broker.ledger_path)
            with self.assertRaisesRegex(capture.CaptureError, "test_broker_requires_synthetic_private_transport"):
                capture.execute(options, test_broker=broker)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", required=True, type=Path)
    args = parser.parse_args()
    print(json.dumps(simulate(args.output, full=True), ensure_ascii=False), flush=True)
