"""Resume checks use fake HTTP and private temporary ledgers only."""
from __future__ import annotations
import argparse
from contextlib import closing
import copy
import json
from pathlib import Path
import shutil
import sqlite3
import sys
import tempfile
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import capture
import verify
from capture_broker import CaptureBroker, MODELS
from test_integration import FakeProductionTransport, FAKE_KEY

PHP = Path("C:/OSPanel/modules/PHP-8.4/php.exe")
PROTOTYPE = capture.ROOT / ".local/prototype"
CASE = "manual.no_repeat_filled_slot"


class ResumeTests(unittest.TestCase):
    def setUp(self):
        parent = capture.LOCAL / "integration-tests"
        parent.mkdir(parents=True, exist_ok=True)
        self.temporary = tempfile.TemporaryDirectory(dir=parent)
        self.directory = Path(self.temporary.name)
        self.assertTrue(self.directory.resolve().is_relative_to(capture.LOCAL.resolve()))
        self.addCleanup(self.temporary.cleanup)
        self.source = self.directory / "partial"
        self.source.mkdir()
        self.key = self.directory / "fake.key"
        self.key.write_text(FAKE_KEY, encoding="utf-8")
        self.catalog = self.directory / "catalog.json"
        self.catalog.write_text(json.dumps({"data": [{"id": model} for model in MODELS]}), encoding="utf-8")
        self.ledger = self.directory / "budget.sqlite"
        self.transport = FakeProductionTransport()
        self.broker = CaptureBroker(self.key, self.catalog, self.ledger, transport=self.transport)
        self.addCleanup(self.broker.close)
        self.broker.preflight(sorted(MODELS))
        self.description = capture.run_child(PHP, PROTOTYPE, ["--action=describe"])
        self.definitions = [x for x in self.description["cases"] if x["id"] == CASE]
        for filename, source in [("baseline-manifest.json", capture.BASELINE / "manifest.json"),
                                 ("source-fingerprints.json", capture.BASELINE / "source-fingerprints.json")]:
            capture.copy_new(source, self.source / filename)
        fingerprints = verify.read(capture.BASELINE / "source-fingerprints.json")
        for key, value in [
            ("config", self.description["config"]),
            ("prompts", {name: sha for name, sha in fingerprints.items() if name.startswith("resources/views/prompts/")}),
            ("harness", {name: capture.digest(capture.ROOT / name) for name in capture.HARNESS}),
        ]:
            capture.write_new(self.source / (key + ".json"), value)
        self.common = [f"--case={CASE}", "--run-id=synthetic_resume", f"--output={self.source}"]

        def stop_before_second_post(message):
            if message["sequence"] == 2:
                raise capture.CaptureError("intentional_offline_stop")
            return self.handler(message)

        with self.assertRaisesRegex(capture.CaptureError, "intentional_offline_stop"):
            capture.run_child(PHP, PROTOTYPE, self.common + ["--mode=test"], stop_before_second_post)
        self.assertEqual(len(self.transport.posts), 1)
        self.assertEqual(len(list(self.source.glob("llm/*/*.json"))), 1)
        self.original_inventory = verify.files(self.source)

    def handler(self, message):
        self.transport.message = message
        return self.broker.call(message["body"])

    def prepared(self):
        return capture.prepare_resume(self.source, self.definitions, self.description, PHP, PROTOTYPE, True)

    def args(self, **overrides):
        values = dict(prototype=PROTOTYPE, php=PHP, test_case=CASE, ledger=self.ledger,
                      allow_live_openrouter=False, acknowledge_pseudonymized_inputs=False,
                      preflight_only=False, output=self.directory / "continued", resume_from=self.source,
                      key_file=self.key, catalog=self.catalog)
        values.update(overrides)
        return argparse.Namespace(**values)

    def test_paid_prefix_replays_offline_then_only_tail_posts_and_two_full_replays(self):
        resume = self.prepared()
        self.assertEqual(len(self.transport.posts), 1)
        capture.validate_resume_ledger(self.ledger, resume)
        output = self.directory / "tail"
        output.mkdir()
        for entry in resume["lineage"]["prefix"]:
            capture.copy_new(self.source / entry["path"], output / entry["path"], entry["sha256"])
        common = [f"--case={CASE}", "--run-id=synthetic_resume", f"--output={output}"]
        result = capture.run_child(PHP, PROTOTYPE, common + ["--mode=test"],
                                   capture.resume_handler(self.handler, resume), resume_prefix=1,
                                   prefix_provenance="synthetic")
        self.assertEqual(len(self.transport.posts), 2)  # one prefix + one tail, no repeated prefix POST
        for _ in range(2):
            replay = capture.run_child(PHP, PROTOTYPE, common + ["--mode=replay"])
            self.assertEqual(replay["trace_sha256"], result["trace_sha256"])
            self.assertEqual(replay["calls"], result["calls"])
        self.assertEqual(len(self.transport.posts), 2)
        self.assertEqual(verify.files(self.source), self.original_inventory)
        self.assertEqual(capture.digest(output / resume["lineage"]["prefix"][0]["path"]),
                         resume["lineage"]["prefix"][0]["sha256"])

    def test_orchestrator_creates_new_lineage_manifest_without_touching_source(self):
        capture.validate_resume_ledger(self.ledger, self.prepared())
        with patch.object(capture, "FakeBroker", return_value=self.handler):
            result = capture.execute(self.args())
        self.assertEqual(result["cases"], 1)
        self.assertEqual(result["real_llm_calls"], 0)
        manifest = verify.read(self.args().output / "manifest.json")
        self.assertEqual(manifest["run_id"], "synthetic_resume")
        self.assertEqual(manifest["resume"]["source_run_id"], manifest["run_id"])
        self.assertEqual(manifest["resume"]["preflight"]["passes"], 2)
        self.assertEqual(manifest["resume"]["preflight"]["network_calls"], 0)
        self.assertEqual(len(self.transport.posts), 2)
        self.assertEqual(verify.files(self.source), self.original_inventory)
        for name, item in manifest["resume"]["source_artifacts"].items():
            self.assertEqual(capture.digest(self.args().output / item["path"]), item["sha256"])
        payloads = {key: verify.read(self.args().output / entry["path"]) for key, entry in manifest["artifacts"].items()}
        fixture = verify.read(self.args().output / f"cases/{CASE}.json")
        used = set(item["path"] for item in manifest["artifacts"].values())
        used.update(item["fixture"] for item in manifest["cases"])
        used.update(call["path"] for call in fixture["llm_calls"])
        with patch.object(verify, "EXPECTED_CASES", {CASE: "manual"}):
            chain = verify.validate_resume(self.args().output, manifest, payloads, used)
            for reference in fixture["llm_calls"]:
                verify.validate_resume_call(chain, fixture, reference, verify.read(self.args().output / reference["path"]))

    def test_gap_and_partial_write_are_refused_before_any_post(self):
        first = self.source / f"llm/{CASE}/0001.json"
        first.rename(first.with_name("0002.json"))
        with self.assertRaisesRegex(capture.CaptureError, "resume_prefix_gap"):
            self.prepared()
        first.with_name("0002.json").rename(first)
        first.write_bytes(b'{"schema_version":')
        with self.assertRaisesRegex(capture.CaptureError, "resume_source_invalid"):
            self.prepared()
        self.assertEqual(len(self.transport.posts), 1)

    def test_hash_tampering_is_refused_without_http(self):
        first = self.source / f"llm/{CASE}/0001.json"
        payload = verify.read(first)
        payload["response"]["content"] = "modified"
        first.write_text(json.dumps(payload), encoding="utf-8")
        with self.assertRaisesRegex(capture.CaptureError, "resume_record_identity_or_hash_differs"):
            self.prepared()
        self.assertEqual(len(self.transport.posts), 1)

    def test_rehashed_different_request_fails_actual_donor_prefix_replay(self):
        first = self.source / f"llm/{CASE}/0001.json"
        payload = verify.read(first)
        payload["request"]["messages"][0]["content"] += "\nDifferent authored input"
        payload["evidence"]["request_body"]["messages"][0]["content"] += "\nDifferent authored input"
        payload["request_sha256"] = verify.digest_value(payload["request"])
        payload["evidence"]["request_body_sha256"] = verify.digest_value(payload["evidence"]["request_body"])
        first.write_text(verify.canonical_json(payload), encoding="utf-8")
        with self.assertRaisesRegex(capture.CaptureError, "offline_case_failed"):
            self.prepared()
        self.assertEqual(len(self.transport.posts), 1)

    def test_synthetic_prefix_cannot_enter_live_mode(self):
        import capture_broker
        with patch.object(capture_broker, "CaptureBroker") as broker:
            with self.assertRaises(capture.CaptureError):
                capture.execute(self.args(test_case=None, ledger=capture.LOCAL / "budget.sqlite",
                                          allow_live_openrouter=True, acknowledge_pseudonymized_inputs=True))
            broker.assert_not_called()
        self.assertEqual(len(self.transport.posts), 1)

    def test_wrong_first_tail_body_is_blocked_before_handler(self):
        resume = self.prepared()
        handler = Mock()
        guarded = capture.resume_handler(handler, resume)
        with self.assertRaisesRegex(capture.CaptureError, "resume_tail_request_differs"):
            guarded({"case_id": CASE, "sequence": 2, "request": {}, "body": {}})
        handler.assert_not_called()
        self.assertEqual(len(self.transport.posts), 1)

    def test_existing_output_is_never_overwritten_or_paid_again(self):
        output = self.args().output
        output.mkdir()
        marker = output / "preserve"
        marker.write_bytes(b"keep")
        with patch.object(capture, "FakeBroker", return_value=self.handler):
            with self.assertRaisesRegex(capture.CaptureError, "output_must_be_new"):
                capture.execute(self.args())
        self.assertEqual(marker.read_bytes(), b"keep")
        self.assertEqual(len(self.transport.posts), 1)

    def test_settled_but_lost_response_blocks_retry_without_mutating_ledger(self):
        resume = self.prepared()
        # Simulate physical POST settling, followed by a master/child crash before
        # any response can become a durable call file.
        first = resume["records"][0]
        self.transport.message = {"request": first["request"], "body": verify.resume_donor_body(first)}
        self.broker.call(self.transport.message["body"])
        before = capture.digest(self.ledger)
        with self.assertRaisesRegex(capture.CaptureError, "resume_settled_response_missing"):
            capture.validate_resume_ledger(self.ledger, resume)
        self.assertEqual(capture.digest(self.ledger), before)
        self.assertEqual(len(self.transport.posts), 2)

    def test_record_budget_mapping_must_match_existing_confirmed_row(self):
        resume = self.prepared()
        resume["records"][0]["evidence"]["budget"]["charged_micros"] += 1
        before = capture.digest(self.ledger)
        with self.assertRaisesRegex(capture.CaptureError, "resume_ledger_receipt_differs"):
            capture.validate_resume_ledger(self.ledger, resume)
        self.assertEqual(capture.digest(self.ledger), before)
        self.assertEqual(len(self.transport.posts), 1)

    def test_receipt_checkpoint_identity_is_bound_before_new_post(self):
        resume = self.prepared()
        checkpoint = Path(str(self.ledger) + ".checkpoints") / "000001.receipt.json"
        value = verify.read(checkpoint)
        value["generation_id"] = "gen-different-synthetic-receipt"
        checkpoint.write_text(json.dumps(value), encoding="utf-8")
        before = capture.digest(self.ledger)
        with self.assertRaisesRegex(capture.CaptureError, "resume_ledger_checkpoint_differs"):
            capture.validate_resume_ledger(self.ledger, resume)
        self.assertEqual(capture.digest(self.ledger), before)
        self.assertEqual(len(self.transport.posts), 1)

    def test_offline_prefix_rejects_broker_handler(self):
        with self.assertRaisesRegex(capture.CaptureError, "offline_prefix_handler_forbidden"):
            capture.run_child(PHP, PROTOTYPE, self.common + ["--mode=replay"], self.handler,
                              resume_prefix=1, prefix_only=True, prefix_provenance="synthetic")
        self.assertEqual(len(self.transport.posts), 1)

    def test_complete_or_nested_source_requires_separate_review(self):
        capture.write_new(self.source / "manifest.json", {})
        with self.assertRaisesRegex(capture.CaptureError, "resume_source_already_finalized"):
            self.prepared()
        self.assertEqual(len(self.transport.posts), 1)


if __name__ == "__main__":
    unittest.main()
