"""Synthetic unit inputs for the verifier; these are never recorded baseline evidence."""
import copy
import hashlib
import importlib.util
import json
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch
from pathlib import Path

REPO = Path(__file__).resolve().parents[3]
SPEC = importlib.util.spec_from_file_location("baseline_verify", REPO / "tools/baseline/verify.py")
verify = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(verify)
REFERENCE = REPO / "tests/fixtures/prototype"


class VerifyTests(unittest.TestCase):
    def setUp(self):
        local = REPO / ".local/baseline-verifier-tests"
        local.mkdir(parents=True, exist_ok=True)
        self.temporary = tempfile.TemporaryDirectory(prefix="case-", dir=local)
        self.root = Path(self.temporary.name) / "bundle"
        self.root.mkdir()
        self.harness_root = Path(self.temporary.name) / "unit-harness"
        for name in verify.REQUIRED_HARNESS:
            source = self.harness_root / name
            source.parent.mkdir(parents=True, exist_ok=True)
            source.write_text("Synthetic verifier unit harness: " + name, encoding="utf-8")
        self.patch_harness = patch.object(verify, "HARNESS_ROOT", self.harness_root)
        self.patch_harness.start()
        self.manifest = self.make_bundle()

    def tearDown(self):
        self.patch_harness.stop()
        self.temporary.cleanup()

    def write(self, path, value):
        target = self.root / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(verify.canonical_json(value), encoding="utf-8", newline="\n")
        return verify.sha(target)

    def save(self):
        self.write("manifest.json", self.manifest)

    def make_bundle(self):
        artifacts = {}
        for name, filename in (("baseline_manifest", "manifest.json"), ("source_fingerprints", "source-fingerprints.json")):
            path = "artifacts/" + filename
            (self.root / path).parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(REFERENCE / filename, self.root / path)
            artifacts[name] = {"path": path, "sha256": verify.sha(self.root / path)}
        sources = verify.read(REFERENCE / "source-fingerprints.json")
        values = {
            "config": {"agent": {"models": {"responder": "anthropic/claude-sonnet-5.5"}}, "frozen_clock": "2026-10-31T06:00:00Z"},
            "prompts": {key: value for key, value in sources.items() if key.startswith("resources/views/prompts/")},
            "harness": {name: verify.sha(self.harness_root / name) for name in verify.REQUIRED_HARNESS},
        }
        for name, value in values.items():
            path = "artifacts/" + name + ".json"
            artifacts[name] = {"path": path, "sha256": self.write(path, value)}
        versions = {key: artifacts[name]["sha256"] for key, name in verify.VERSION_HASHES.items()}
        versions["normalization"] = "contacts-v1"
        cases = []
        for index, (case_id, kind) in enumerate(verify.EXPECTED_CASES.items()):
            scheduler = case_id in ("manual.followup_20h", "manual.followup_40h")
            layer = "donor_orchestrator_setup_and_scheduler" if scheduler else ("donor_orchestrator_persona" if kind == "persona" else "donor_orchestrator_scripted")
            phase = "setup" if scheduler else "dialogue"
            request = {"messages": [{"role": "user", "content": "Unit scenario with contact-0001@baseline.invalid"}],
                       "options": {"model": "anthropic/claude-sonnet-5.5", "temperature": 0.5, "max_tokens": 700}}
            wire = {**request["options"], "messages": request["messages"], "usage": {"include": True}, "reasoning": {"effort": "low"}, "stream": False, "plugins": [],
                    "provider": {"only": ["anthropic"], "order": ["anthropic"], "allow_fallbacks": False, "require_parameters": False,
                                 "max_price": {"prompt": 3.0, "completion": 15.0, "request": 0.0}}}
            usage = {"prompt_tokens": 10, "completion_tokens": 5, "total_tokens": 15, "cost": 0.000001}
            raw = {"id": "unit-receipt-" + str(index), "model": "anthropic/claude-sonnet-5.5", "usage": usage,
                   "choices": [{"message": {"role": "assistant", "content": "Unit response"}, "finish_reason": "stop"}]}
            response = {"content": "Unit response", "toolCalls": [], "usage": usage, "model": raw["model"],
                        "raw": raw, "costUsd": 0.000001, "error": None, "latencyMs": 1}
            call = {"schema_version": 1, "provenance": "recorded", "provider": "openrouter",
                    "request": request, "request_sha256": verify.digest_value(request),
                    "response": response, "response_sha256": verify.digest_value(response),
                    "evidence": {"run_id": "unit-run", "case_id": case_id, "sequence": 1, "phase": phase,
                                 "captured_at": "2026-10-31T12:00:00Z", "http_status": 200,
                                 "provider_request_id": raw["id"], "provider_model": raw["model"],
                                 "usage": {"prompt_tokens": 10, "completion_tokens": 5, "total_tokens": 15, "cost_usd": "0.000001"},
                                 "request_body": wire, "request_body_sha256": verify.digest_value(wire), "seed_requested": None}}
            path = "llm/" + case_id + "/0001.json"
            call_ref = {"sequence": 1, "path": path, "sha256": self.write(path, call), "phase": phase}
            scenario = {"case_id": case_id, "description": "Authored verifier unit input; never live evidence"}
            trace = {"turns": [{"reply": "Unit response", "stage": "qualify"}]}
            if scheduler:
                hours = [19.99, 20] if case_id == "manual.followup_20h" else [20, 39.99, 40, 79.99, 80]
                counts = [0, 1] if case_id == "manual.followup_20h" else [1, 1, 2, 2, 2]
                scenario["scheduler_hours"] = hours
                trace = {"setup": trace["turns"], "scheduler": [
                    {"elapsed_hours": hour, "followups_sent": count, "outcome": "stale" if hour == 80 else None}
                    for hour, count in zip(hours, counts)]}
            trace_hash = verify.digest_value(trace)
            case = {"schema_version": 2, "case_id": case_id, "kind": kind, "provenance": "recorded",
                    "capture": {"run_id": "unit-run", "method": "live", "provider": "openrouter", "captured_at": "2026-10-31T12:00:00Z",
                                "seed": {"runner": 20261031, "requested": None, "provider_support": "not_requested"},
                                "inputs_pseudonymized": True, "byte_identical_persona_inputs": False},
                    "versions": versions, "execution": {"layer": layer, "input_provenance": "donor_persona" if kind == "persona" else "authored_script",
                                                         "scenario": scenario, "scenario_sha256": verify.digest_value(scenario)},
                    "trace": trace, "llm_calls": [call_ref],
                    "replay": {"passes": 2, "trace_sha256": trace_hash, "run_trace_sha256": [trace_hash, trace_hash],
                               "consumed_sequences": [[1], [1]], "network_calls": 0}}
            case_path = "cases/" + case_id + ".json"
            cases.append({"id": case_id, "kind": kind, "provenance": "recorded", "fixture": case_path,
                          "fixture_sha256": self.write(case_path, case), "execution_layer": layer})
        manifest = {"schema_version": 2, "run_id": "unit-run", "captured_at": "2026-10-31T12:00:00Z",
                    "equivalence_claim": False, "versions": versions, "artifacts": artifacts, "cases": cases,
                    "coverage": {"total_cases": 41, "personas": 29, "manual": 12, "recorded_real_llm": 41, "synthetic": 0, "missing": 0}}
        self.write("manifest.json", manifest)
        return manifest

    def case(self, index=0):
        return verify.read(self.root / self.manifest["cases"][index]["fixture"])

    def edit_case(self, mutate, index=0):
        case = self.case(index)
        mutate(case)
        item = self.manifest["cases"][index]
        item["fixture_sha256"] = self.write(item["fixture"], case)
        self.save()

    def edit_call(self, mutate, index=0):
        case = self.case(index)
        reference = case["llm_calls"][0]
        call = verify.read(self.root / reference["path"])
        mutate(call)
        reference["sha256"] = self.write(reference["path"], call)
        item = self.manifest["cases"][index]
        item["fixture_sha256"] = self.write(item["fixture"], case)
        self.save()

    def rejects(self, pattern):
        with self.assertRaisesRegex(ValueError, pattern):
            verify.validate(self.root, require_recorded=True)

    def add_resume(self):
        case = self.case()
        prefix = dict(case["llm_calls"][0])
        tail = copy.deepcopy(verify.read(self.root / prefix["path"]))
        tail["evidence"]["sequence"] = 2
        tail["evidence"]["provider_request_id"] += "-tail"
        tail["response"]["raw"]["id"] = tail["evidence"]["provider_request_id"]
        tail["response_sha256"] = verify.digest_value(tail["response"])
        tail_path = f"llm/{case['case_id']}/0002.json"
        case["llm_calls"].append({"sequence": 2, "path": tail_path, "sha256": self.write(tail_path, tail), "phase": "dialogue"})
        case["replay"]["consumed_sequences"] = [[1, 2], [1, 2]]
        item = self.manifest["cases"][0]
        item["fixture_sha256"] = self.write(item["fixture"], case)
        sources = {}
        for name, reference in self.manifest["artifacts"].items():
            target = "lineage/source/" + Path(reference["path"]).name
            value = verify.read(self.root / reference["path"])
            if name == "harness":
                value[next(iter(value))] = "a" * 64  # Historical source is not relabelled as the new harness.
                checksum = self.write(target, value)
            else:
                (self.root / target).parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(self.root / reference["path"], self.root / target)
                checksum = verify.sha(self.root / target)
            sources[name] = {"path": target, "sha256": checksum}
        consumed = {case["case_id"]: [1]}
        following = {case["case_id"]: {"request_sha256": tail["request_sha256"],
                     "request_body_sha256": verify.digest_value(verify.resume_donor_body(tail))}}
        pass_hash = verify.digest_value({"consumed_sequences": consumed, "next_requests": following, "completed_trace_sha256": {}})
        self.manifest["resume"] = {"schema_version": 1, "source_run_id": self.manifest["run_id"],
            "source_artifacts": sources, "completed_cases": [], "prefix": [{"case_id": case["case_id"], **{key: prefix[key] for key in ("sequence", "path", "sha256")}}],
            "preflight": {"passes": 2, "network_calls": 0, "consumed_sequences": consumed,
                          "next_requests": following, "completed_trace_sha256": {}, "pass_sha256": [pass_hash, pass_hash]}}
        self.save()

    def add_second_resume(self):
        self.add_resume()
        ancestor = copy.deepcopy(self.manifest["resume"])
        for reference in ancestor["source_artifacts"].values():
            old = self.root / reference["path"]
            target = "lineage/ancestors/0001/" + old.name
            (self.root / target).parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(old, self.root / target)
            reference["path"] = target
        top = self.manifest["resume"]
        top["ancestors"] = [ancestor]
        current_harness = self.manifest["artifacts"]["harness"]
        source_harness = top["source_artifacts"]["harness"]
        shutil.copyfile(self.root / current_harness["path"], self.root / source_harness["path"])
        source_harness["sha256"] = current_harness["sha256"]
        case = self.case()
        second = case["llm_calls"][1]
        top["prefix"].append({"case_id": case["case_id"], **{key: second[key] for key in ("sequence", "path", "sha256")}})
        tail = copy.deepcopy(verify.read(self.root / second["path"]))
        tail["evidence"]["sequence"] = 3
        tail["evidence"]["provider_request_id"] += "-second-tail"
        tail["response"]["raw"]["id"] = tail["evidence"]["provider_request_id"]
        tail["response_sha256"] = verify.digest_value(tail["response"])
        tail_path = f"llm/{case['case_id']}/0003.json"
        case["llm_calls"].append({"sequence": 3, "path": tail_path, "sha256": self.write(tail_path, tail), "phase": "dialogue"})
        case["replay"]["consumed_sequences"] = [[1, 2, 3], [1, 2, 3]]
        item = self.manifest["cases"][0]
        item["fixture_sha256"] = self.write(item["fixture"], case)
        preflight = top["preflight"]
        preflight["consumed_sequences"] = {case["case_id"]: [1, 2]}
        preflight["next_requests"] = {case["case_id"]: {"request_sha256": tail["request_sha256"],
                                       "request_body_sha256": verify.digest_value(verify.resume_donor_body(tail))}}
        preflight["pass_sha256"] = [verify.digest_value({key: preflight[key] for key in ("consumed_sequences", "next_requests", "completed_trace_sha256")})] * 2
        self.save()

    def add_abandonment(self, *, existing_resume=False):
        # Entirely synthetic unit bookkeeping: no real ledger or recorded files.
        if not existing_resume:
            self.add_resume()
        helper = "tools/baseline/orphan_ledger.py"
        (self.harness_root / helper).write_text("Synthetic orphan unit helper", encoding="utf-8")
        harness_ref = self.manifest["artifacts"]["harness"]
        harness = verify.read(self.root / harness_ref["path"])
        harness[helper] = verify.sha(self.harness_root / helper)
        harness_ref["sha256"] = self.write(harness_ref["path"], harness)
        self.manifest["versions"]["harness_sha256"] = harness_ref["sha256"]
        next_id = 1
        hashes = {}
        for item in self.manifest["cases"]:
            case = verify.read(self.root / item["fixture"])
            case["versions"] = copy.deepcopy(self.manifest["versions"])
            for ref in case["llm_calls"]:
                if next_id == 2:
                    next_id += 1  # Physical call 2 was paid, but its response is lost.
                record = verify.read(self.root / ref["path"])
                record["evidence"]["provider_request_id"] = "gen-" + record["evidence"]["provider_request_id"]
                record["response"]["raw"]["id"] = record["evidence"]["provider_request_id"]
                record["response_sha256"] = verify.digest_value(record["response"])
                record["evidence"]["budget"] = {"call_id": next_id, "reserved_micros": 10000, "charged_micros": 1}
                next_id += 1
                ref["sha256"] = self.write(ref["path"], record)
                hashes[ref["path"]] = ref["sha256"]
            if item == self.manifest["cases"][0]:
                case["capture"]["replacement_for_call_ids"] = [2]
            item["fixture_sha256"] = self.write(item["fixture"], case)
        for level in self.manifest["resume"].get("ancestors", []) + [self.manifest["resume"]]:
            for ref in level["prefix"]:
                ref["sha256"] = hashes[ref["path"]]
        case = self.case()
        replacement_ref = case["llm_calls"][1]
        record = verify.read(self.root / replacement_ref["path"])
        original_wire_sha = hashlib.sha256(json.dumps(record["evidence"]["request_body"], ensure_ascii=False,
                                                      sort_keys=True, separators=(",", ":")).encode()).hexdigest()
        receipt = {"generation_id": "gen-unit-lost-2", "request_sha256": original_wire_sha,
                   "response_sha256": "a" * 64, "recorded_at": "2026-10-31T12:00:00Z"}
        checkpoint = {"schema_version": 1, "call_id": 2, **receipt, "model_requested": record["request"]["options"]["model"],
                      "usage": {"prompt_tokens": 10, "completion_tokens": 5, "total_tokens": 15, "cost_usd": "0.000001"},
                      "response_retained": False, "replay_eligible": False}
        checkpoint_ref = {"path": "lineage/abandoned/000002.receipt.json"}
        checkpoint_ref["sha256"] = self.write(checkpoint_ref["path"], checkpoint)
        audit = {"schema_version": 1, "kind": "confirmed_response_lost", "call_id": 2,
                 "model": record["request"]["options"]["model"], "request_sha256": original_wire_sha,
                 "reserved_micros": 10000, "charged_micros": 2, "receipt": receipt,
                 "receipt_checkpoint_sha256": checkpoint_ref["sha256"],
                 "source": {"run_id": self.manifest["run_id"], "case_id": case["case_id"], "sequence": 2,
                            "inventory_sha256": "d" * 64, "request_sha256": record["request_sha256"],
                            "request_body_sha256": verify.digest_value(verify.resume_donor_body(record))},
                 "source_audit_sha256": "e" * 64, "decided_at": "2026-10-31T12:01:00Z",
                 "reason": "normalization_failure_response_not_retained",
                 "provider_content_recovery": "operator_attests_unavailable",
                 "response_retained": False, "replay_eligible": False, "replacement_authorized": True}
        audit_ref = {"path": "lineage/abandoned/000002.audit.json"}
        audit_ref["sha256"] = self.write(audit_ref["path"], audit)
        self.manifest["abandoned_calls"] = [{"audit": audit_ref, "receipt_checkpoint": checkpoint_ref,
            "replacement": {"case_id": case["case_id"], "call_id": 3,
                            **{key: replacement_ref[key] for key in ("sequence", "path", "sha256")}}}]
        self.save()

    def edit_abandonment(self, mutate):
        ref = self.manifest["abandoned_calls"][0]["audit"]
        audit = verify.read(self.root / ref["path"])
        mutate(audit)
        ref["sha256"] = self.write(ref["path"], audit)
        self.save()

    def add_billing_abandonment(self):
        self.add_abandonment()
        name = "tools/baseline/reconcile.py"
        (self.harness_root / name).write_text("Synthetic reconciliation unit helper", encoding="utf-8")
        ref = self.manifest["artifacts"]["harness"]
        harness = verify.read(self.root / ref["path"])
        harness[name] = verify.sha(self.harness_root / name)
        ref["sha256"] = self.write(ref["path"], harness)
        self.manifest["versions"]["harness_sha256"] = ref["sha256"]
        for item in self.manifest["cases"]:
            case = verify.read(self.root / item["fixture"])
            case["versions"] = copy.deepcopy(self.manifest["versions"])
            item["fixture_sha256"] = self.write(item["fixture"], case)
        entry = self.manifest["abandoned_calls"][0]
        audit = verify.read(self.root / entry["audit"]["path"])
        audit["reason"] = "billing_metadata_unavailable_response_not_retained"
        audit["reconciliation_audit_sha256"] = "f" * 64
        entry["audit"]["sha256"] = self.write(entry["audit"]["path"], audit)
        receipt = verify.read(self.root / entry["receipt_checkpoint"]["path"])
        reservation = {"schema_version": 1, "call_id": 2, "recorded_at": "2026-10-31T11:59:58Z",
            "request_sha256": audit["request_sha256"], "model_requested": audit["model"],
            "reserved_micros": 10000, "replay_eligible": False}
        raw = json.dumps(reservation, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode() + b"\n"
        settlement = {"schema_version": 2, "kind": "reconciled_checkpoint_generation_metadata", "call_id": 2,
            "decided_at": "2026-10-31T12:00:50Z", "request_sha256": audit["request_sha256"],
            "original_call": {"id": 2, "request_hash": audit["request_sha256"], "model": audit["model"],
                "reserved_micros": 10000, "charged_micros": None, "state": "uncertain", "reason": "generation_billing_unknown",
                "created_at": "2026-10-31T11:59:58Z", "settled_at": "2026-10-31T12:00:30Z"},
            "upper_bound_micros": 10000, "task_limit_micros": 50000000, "response_retained": False,
            "replay_evidence_created": False, "actual_cost_confirmed": True, "source_audit_sha256": "f" * 64,
            "request_link_basis": "durable_receipt_checkpoint_and_matching_generation_metadata",
            "provider_confirmed_request_hash": False, "clock_skew_seconds": 5,
            "checkpoint": {"reservation_sha256": hashlib.sha256(raw).hexdigest(),
                "receipt_sha256": entry["receipt_checkpoint"]["sha256"], "reservation": reservation, "receipt": receipt},
            "metadata": {"generation_id": receipt["generation_id"], "created_at": "2026-10-31T11:59:59Z",
                "model": audit["model"], "provider": "Anthropic", "is_byok": False,
                "openrouter_cost_usd": "0.000002", "receipt_cost_usd": "0.000001", "counted_upstream_cost_usd": None,
                "confirmed_cost_micros": 2, "tokens_prompt": 10, "tokens_completion": 5,
                "native_tokens_prompt": None, "native_tokens_completion": None}}
        ref = {"path": "lineage/abandoned/000002.reconciliation.json"}
        ref["sha256"] = self.write(ref["path"], settlement)
        entry["reconciliation"] = ref
        self.save()

    def edit_reconciliation(self, mutate):
        ref = self.manifest["abandoned_calls"][0]["reconciliation"]
        value = verify.read(self.root / ref["path"])
        mutate(value)
        ref["sha256"] = self.write(ref["path"], value)
        self.save()

    def test_billing_loss_requires_confirmed_settlement_without_recovering_response(self):
        self.add_billing_abandonment()
        result = verify.validate(self.root, require_recorded=True)
        self.assertEqual((1, 2), (result["abandoned_paid_calls"], result["abandoned_charged_micros"]))

    def test_billing_loss_cannot_reuse_normalization_reason(self):
        self.add_billing_abandonment()
        self.edit_abandonment(lambda x: x.update(reason="normalization_failure_response_not_retained"))
        self.rejects("abandoned audit schema")

    def test_billing_loss_without_reconciliation_reference_is_rejected(self):
        self.add_billing_abandonment()
        del self.manifest["abandoned_calls"][0]["reconciliation"]
        self.save()
        self.rejects("reconciliation evidence")

    def test_billing_loss_source_audit_hash_must_match(self):
        self.add_billing_abandonment()
        self.edit_reconciliation(lambda x: x.update(source_audit_sha256="b"*64))
        self.rejects("source hash differs")

    def test_billing_metadata_cannot_claim_remote_request_hash_attestation(self):
        self.add_billing_abandonment()
        self.edit_reconciliation(lambda x: x.update(provider_confirmed_request_hash=True))
        self.rejects("reconciliation claims")

    def test_billing_original_state_cannot_be_confirmed_or_forfeited(self):
        self.add_billing_abandonment()
        self.edit_reconciliation(lambda x: x["original_call"].update(charged_micros=0, state="completed"))
        self.rejects("original call differs")

    def test_billing_receipt_hash_and_embedded_projection_are_required(self):
        self.add_billing_abandonment()
        self.edit_reconciliation(lambda x: x["checkpoint"]["receipt"].update(response_sha256="b"*64))
        self.rejects("checkpoint receipt differs")

    def test_billing_reservation_hash_cannot_be_invented(self):
        self.add_billing_abandonment()
        self.edit_reconciliation(lambda x: x["checkpoint"].update(reservation_sha256="b"*64))
        self.rejects("reservation hash differs")

    def test_billing_metadata_generation_must_match_original_receipt(self):
        self.add_billing_abandonment()
        self.edit_reconciliation(lambda x: x["metadata"].update(generation_id="gen-another-offline-call"))
        self.rejects("generation identity differs")

    def test_billing_metadata_provider_must_match_pinned_model(self):
        self.add_billing_abandonment()
        self.edit_reconciliation(lambda x: x["metadata"].update(provider="Other offline provider"))
        self.rejects("generation identity differs")

    def test_billing_native_token_counts_cannot_disagree_with_receipt(self):
        self.add_billing_abandonment()
        self.edit_reconciliation(lambda x: x["metadata"].update(native_tokens_prompt=11, native_tokens_completion=5))
        self.rejects("receipt tokens differ")

    def test_billing_partial_native_token_pair_is_rejected(self):
        self.add_billing_abandonment()
        self.edit_reconciliation(lambda x: x["metadata"].update(native_tokens_prompt=10))
        self.rejects("native token pair")

    def test_billing_declared_charge_is_recomputed_with_ceiling(self):
        self.add_billing_abandonment()
        self.edit_reconciliation(lambda x: x["metadata"].update(confirmed_cost_micros=1))
        self.rejects("confirmed charge differs")

    def test_billing_provider_cost_cannot_depart_from_original_receipt(self):
        self.add_billing_abandonment()
        self.edit_reconciliation(lambda x: x["metadata"].update(openrouter_cost_usd="0.5"))
        self.rejects("receipt cost differs")

    def test_billing_byok_without_upstream_cost_is_rejected(self):
        self.add_billing_abandonment()
        self.edit_reconciliation(lambda x: x["metadata"].update(is_byok=True))
        self.rejects("provider cost")

    def test_billing_metadata_time_is_bound_to_receipt_not_long_poll_wait(self):
        self.add_billing_abandonment()
        self.edit_reconciliation(lambda x: x["metadata"].update(created_at="2026-10-31T12:00:20Z"))
        self.rejects("generation time differs")

    def test_billing_current_skew_accepts_exact_five_second_boundaries(self):
        self.add_billing_abandonment()
        for when in ("2026-10-31T11:59:53Z", "2026-10-31T12:00:05Z", "2026-10-31T11:59:56.932000Z"):
            with self.subTest(when=when):
                self.edit_reconciliation(lambda x: x["metadata"].update(created_at=when))
                self.assertEqual(1, verify.validate(self.root, require_recorded=True)["abandoned_paid_calls"])

    def test_billing_current_skew_rejects_one_microsecond_outside_boundaries(self):
        self.add_billing_abandonment()
        for when in ("2026-10-31T11:59:52.999999Z", "2026-10-31T12:00:05.000001Z"):
            with self.subTest(when=when):
                self.edit_reconciliation(lambda x: x["metadata"].update(created_at=when))
                self.rejects("generation time differs")

    def test_billing_historical_one_second_skew_remains_strict(self):
        self.add_billing_abandonment()
        self.edit_reconciliation(lambda x: x.update(clock_skew_seconds=1))
        for when in ("2026-10-31T11:59:57Z", "2026-10-31T12:00:01Z"):
            self.edit_reconciliation(lambda x: x["metadata"].update(created_at=when))
            self.assertEqual(1, verify.validate(self.root, require_recorded=True)["abandoned_paid_calls"])
        for when in ("2026-10-31T11:59:56.999999Z", "2026-10-31T12:00:01.000001Z", "2026-10-31T11:59:56.932000Z"):
            self.edit_reconciliation(lambda x: x["metadata"].update(created_at=when))
            self.rejects("generation time differs")

    def test_billing_clock_skew_cannot_be_arbitrary_or_coerced(self):
        self.add_billing_abandonment()
        for value in (0, 2, 6, True, 5.0, "5"):
            with self.subTest(value=value):
                self.edit_reconciliation(lambda x: x.update(clock_skew_seconds=value))
                self.rejects("reconciliation claims")

    def test_billing_receipt_must_arrive_within_five_minutes_of_reservation(self):
        self.add_billing_abandonment()
        self.edit_reconciliation(lambda x: x["original_call"].update(created_at="2026-10-31T11:55:00Z"))
        self.assertEqual(1, verify.validate(self.root, require_recorded=True)["abandoned_paid_calls"])
        self.edit_reconciliation(lambda x: x["original_call"].update(created_at="2026-10-31T11:54:59.999999Z"))
        self.rejects("original capture window")

    def test_billing_token_counts_match_runtime_upper_bound(self):
        self.add_billing_abandonment()
        self.edit_reconciliation(lambda x: x["metadata"].update(tokens_prompt=1000000001))
        self.rejects("token count exceeds bound")
        self.edit_reconciliation(lambda x: x["metadata"].update(tokens_prompt=10, native_tokens_prompt=1000000001, native_tokens_completion=5))
        self.rejects("native token count exceeds bound")

    def test_billing_settlement_cannot_follow_abandonment(self):
        self.add_billing_abandonment()
        self.edit_reconciliation(lambda x: x.update(decided_at="2026-10-31T12:02:00Z"))
        self.rejects("time order differs")

    def test_billing_export_rejects_extra_private_or_raw_fields(self):
        self.add_billing_abandonment()
        self.edit_reconciliation(lambda x: x.update(ledger_before_sha256="b"*64))
        self.rejects("reconciliation schema")

    def test_abandoned_paid_response_is_disclosed_separately_from_replay(self):
        self.add_abandonment()
        result = verify.validate(self.root, require_recorded=True)
        self.assertEqual((42, 1, 2), (result["llm_calls"], result["abandoned_paid_calls"], result["abandoned_charged_micros"]))
        self.assertEqual("0.000042", result["provider_cost_usd"])

    def test_abandoned_frontier_can_be_preserved_in_an_ancestor(self):
        self.add_second_resume()
        self.add_abandonment(existing_resume=True)
        self.assertEqual(1, verify.validate(self.root, require_recorded=True)["abandoned_paid_calls"])

    def test_abandoned_response_cannot_claim_recovered_or_replayable_content(self):
        self.add_abandonment()
        self.edit_abandonment(lambda audit: audit.update(response_retained=True))
        self.rejects("replayable")

    def test_abandoned_audit_disallows_extra_raw_data_and_runtime_secrets(self):
        self.add_abandonment()
        self.edit_abandonment(lambda audit: audit.update(raw_response="Invented raw response"))
        self.rejects("audit schema")

    def test_abandoned_audit_cannot_suppress_confirmed_cost(self):
        self.add_abandonment()
        self.edit_abandonment(lambda audit: audit.update(charged_micros=0))
        self.rejects("undercounts")

    def test_abandoned_audit_cannot_exceed_original_reservation(self):
        self.add_abandonment()
        self.edit_abandonment(lambda audit: audit.update(charged_micros=10001))
        self.rejects("confirmed cost")

    def test_abandoned_checkpoint_is_required_and_hash_bound(self):
        self.add_abandonment()
        self.edit_abandonment(lambda audit: audit.update(receipt_checkpoint_sha256="b" * 64))
        self.rejects("checkpoint hash")

    def test_abandoned_checkpoint_fields_are_checked_even_after_rehash(self):
        self.add_abandonment()
        ref = self.manifest["abandoned_calls"][0]["receipt_checkpoint"]
        checkpoint = verify.read(self.root / ref["path"])
        checkpoint["generation_id"] = "gen-other-lost"
        ref["sha256"] = self.write(ref["path"], checkpoint)
        self.edit_abandonment(lambda audit: audit.update(receipt_checkpoint_sha256=ref["sha256"]))
        self.rejects("checkpoint differs")

    def test_abandoned_id_cannot_be_an_existing_replayed_call(self):
        self.add_abandonment()
        self.edit_abandonment(lambda audit: audit.update(call_id=1))
        self.rejects("replayed abandoned call")

    def test_abandoned_generation_cannot_be_a_replayed_receipt(self):
        self.add_abandonment()
        self.edit_abandonment(lambda audit: audit["receipt"].update(generation_id="gen-unit-receipt-0"))
        self.rejects("provider receipt")

    def test_abandoned_frontier_must_match_twice_replayed_boundary(self):
        self.add_abandonment()
        self.edit_abandonment(lambda audit: audit["source"].update(sequence=3))
        self.rejects("historical frontier")

    def test_abandoned_request_digest_cannot_be_substituted(self):
        self.add_abandonment()
        self.edit_abandonment(lambda audit: audit["source"].update(request_body_sha256="c" * 64))
        self.rejects("historical frontier")

    def test_abandoned_replacement_must_reference_actual_file_and_physical_id(self):
        self.add_abandonment()
        self.manifest["abandoned_calls"][0]["replacement"]["call_id"] = 999
        self.save()
        self.rejects("retained call")

    def test_abandoned_replacement_cannot_be_missing_case_annotation(self):
        self.add_abandonment()
        self.edit_case(lambda case: case["capture"].pop("replacement_for_call_ids"))
        self.rejects("annotations")

    def test_unbacked_abandoned_annotation_is_rejected_without_manifest_claim(self):
        self.edit_case(lambda case: case["capture"].update(replacement_for_call_ids=[2]))
        self.rejects("Unbacked")

    def test_abandoned_inventory_requires_budget_receipts_for_every_retained_call(self):
        self.add_abandonment()
        self.edit_call(lambda record: record["evidence"].pop("budget"), index=1)
        self.rejects("budget receipts")

    def test_abandoned_inventory_rejects_duplicate_retained_physical_ids(self):
        self.add_abandonment()
        self.edit_call(lambda record: record["evidence"]["budget"].update(call_id=1), index=1)
        self.rejects("Reused retained")

    def test_abandoned_inventory_requires_versioned_accounting_helper(self):
        self.add_abandonment()
        harness_ref = self.manifest["artifacts"]["harness"]
        harness = verify.read(self.root / harness_ref["path"])
        harness.pop("tools/baseline/orphan_ledger.py")
        harness_ref["sha256"] = self.write(harness_ref["path"], harness)
        self.manifest["versions"]["harness_sha256"] = harness_ref["sha256"]
        self.save()
        self.rejects("orphan accounting harness")

    def test_two_resume_levels_preserve_both_prefix_boundaries_and_harnesses(self):
        self.add_second_resume()
        self.assertEqual(43, verify.validate(self.root, require_recorded=True)["llm_calls"])

    def test_resume_ancestor_cannot_be_dropped_while_its_evidence_remains(self):
        self.add_second_resume()
        self.manifest["resume"].pop("ancestors")
        self.save()
        self.rejects("orphan")

    def test_resume_without_progress_preserves_both_unchanged_boundaries(self):
        self.add_second_resume()
        top = self.manifest["resume"]
        ancestor = top["ancestors"][0]
        top["prefix"] = copy.deepcopy(ancestor["prefix"])
        top["preflight"] = copy.deepcopy(ancestor["preflight"])
        self.save()
        self.assertEqual(43, verify.validate(self.root, require_recorded=True)["llm_calls"])

    def test_resume_ancestor_boundary_is_checked_against_the_preserved_tail(self):
        self.add_second_resume()
        preflight = self.manifest["resume"]["ancestors"][0]["preflight"]
        next(iter(preflight["next_requests"].values()))["request_sha256"] = "a" * 64
        preflight["pass_sha256"] = [verify.digest_value({key: preflight[key] for key in ("consumed_sequences", "next_requests", "completed_trace_sha256")})] * 2
        self.save()
        self.rejects("tail differs")

    def test_resume_ancestor_paths_cannot_alias_current_source_artifacts(self):
        self.add_second_resume()
        top = self.manifest["resume"]
        top["ancestors"][0]["source_artifacts"]["config"] = copy.deepcopy(top["source_artifacts"]["config"])
        self.save()
        self.rejects("path differs")

    def test_resume_ancestor_objects_cannot_hide_nested_history(self):
        self.add_second_resume()
        self.manifest["resume"]["ancestors"][0]["ancestors"] = []
        self.save()
        self.rejects("lineage schema")

    def refresh_resume_proof(self, level=None):
        preflight = (level or self.manifest["resume"])["preflight"]
        preflight["pass_sha256"] = [verify.digest_value({key: preflight[key] for key in (
            "consumed_sequences", "next_requests", "completed_trace_sha256")})] * 2
        self.save()

    def add_completed_frontier_resume(self):
        self.add_resume()
        first = self.case()
        removed = first["llm_calls"].pop()
        (self.root / removed["path"]).unlink()
        first["replay"]["consumed_sequences"] = [[1], [1]]
        first_item = self.manifest["cases"][0]
        first_item["fixture_sha256"] = self.write(first_item["fixture"], first)
        top = self.manifest["resume"]
        historical = copy.deepcopy(first)
        historical["versions"] = {key: top["source_artifacts"][name]["sha256"] for key, name in verify.VERSION_HASHES.items()}
        historical["versions"]["normalization"] = "contacts-v1"
        historical_path = f"lineage/source/cases/{first['case_id']}.json"
        top["completed_cases"] = [{"case_id": first["case_id"], "path": historical_path,
                                    "sha256": self.write(historical_path, historical)}]
        second = self.case(1)
        prefix = second["llm_calls"][0]
        top["prefix"].append({"case_id": second["case_id"], **{key: prefix[key] for key in ("sequence", "path", "sha256")}})
        tail = copy.deepcopy(verify.read(self.root / prefix["path"]))
        tail["evidence"]["sequence"] = 2
        tail["evidence"]["provider_request_id"] += "-frontier-tail"
        tail["response"]["raw"]["id"] = tail["evidence"]["provider_request_id"]
        tail["response_sha256"] = verify.digest_value(tail["response"])
        tail_path = f"llm/{second['case_id']}/0002.json"
        second["llm_calls"].append({"sequence": 2, "path": tail_path, "sha256": self.write(tail_path, tail), "phase": "dialogue"})
        second["replay"]["consumed_sequences"] = [[1, 2], [1, 2]]
        second_item = self.manifest["cases"][1]
        second_item["fixture_sha256"] = self.write(second_item["fixture"], second)
        preflight = top["preflight"]
        preflight["consumed_sequences"] = {first["case_id"]: [1], second["case_id"]: [1]}
        preflight["completed_trace_sha256"] = {first["case_id"]: verify.digest_value(first["trace"])}
        preflight["next_requests"] = {second["case_id"]: {"request_sha256": tail["request_sha256"],
            "request_body_sha256": verify.digest_value(verify.resume_donor_body(tail))}}
        self.refresh_resume_proof()

    def test_resume_completed_case_then_partial_next_case_keeps_historical_fixture(self):
        self.add_completed_frontier_resume()
        self.assertEqual(42, verify.validate(self.root, require_recorded=True)["llm_calls"])

    def test_resume_synthetic_archives_require_explicit_offline_helper_mode(self):
        self.add_completed_frontier_resume()
        reference = self.manifest["resume"]["completed_cases"][0]
        historical = verify.read(self.root / reference["path"])
        historical["provenance"] = "synthetic"
        historical["capture"].update({"method": "test", "provider": "fake IPC broker"})
        reference["sha256"] = self.write(reference["path"], historical)
        self.save()
        self.rejects("required recorded evidence")
        payloads = {"harness": verify.read(self.root / self.manifest["artifacts"]["harness"]["path"])}
        levels = verify.validate_resume(self.root, self.manifest, payloads, set(), source_provenance="synthetic")
        self.assertEqual("synthetic", levels[0]["archived"][self.case()["case_id"]]["provenance"])
        # The helper does not change the final verifier's strict production mode.
        self.rejects("required recorded evidence")

    def test_resume_offline_helper_cannot_claim_real_capture_provenance(self):
        self.add_completed_frontier_resume()
        reference = self.manifest["resume"]["completed_cases"][0]
        historical = verify.read(self.root / reference["path"])
        historical["provenance"] = "synthetic"
        reference["sha256"] = self.write(reference["path"], historical)
        self.save()
        payloads = {"harness": verify.read(self.root / self.manifest["artifacts"]["harness"]["path"])}
        with self.assertRaisesRegex(ValueError, "historical capture identity"):
            verify.validate_resume(self.root, self.manifest, payloads, set(), source_provenance="synthetic")
        with self.assertRaisesRegex(ValueError, "source provenance mode"):
            verify.validate_resume(self.root, self.manifest, payloads, set(), source_provenance="optional")

    def test_resume_frontier_can_have_no_previously_saved_response(self):
        self.add_completed_frontier_resume()
        top = self.manifest["resume"]
        second = self.case(1)
        top["prefix"].pop()
        preflight = top["preflight"]
        preflight["consumed_sequences"][second["case_id"]] = []
        first_new = verify.read(self.root / second["llm_calls"][0]["path"])
        preflight["next_requests"][second["case_id"]] = {"request_sha256": first_new["request_sha256"],
            "request_body_sha256": verify.digest_value(verify.resume_donor_body(first_new))}
        self.refresh_resume_proof()
        self.assertEqual(42, verify.validate(self.root, require_recorded=True)["llm_calls"])

    def test_resume_can_reconstruct_completion_after_call_fsync_before_case_fixture(self):
        self.add_completed_frontier_resume()
        reference = self.manifest["resume"]["completed_cases"].pop()
        (self.root / reference["path"]).unlink()
        self.save()
        self.assertEqual(42, verify.validate(self.root, require_recorded=True)["llm_calls"])

    def test_resume_completed_case_cannot_be_relabelled_to_current_harness(self):
        self.add_completed_frontier_resume()
        reference = self.manifest["resume"]["completed_cases"][0]
        historical = verify.read(self.root / reference["path"])
        historical["versions"] = self.manifest["versions"]
        reference["sha256"] = self.write(reference["path"], historical)
        self.save()
        self.rejects("historical case source versions")

    def test_resume_completed_case_trace_is_bound_to_original_fixture(self):
        self.add_completed_frontier_resume()
        preflight = self.manifest["resume"]["preflight"]
        preflight["completed_trace_sha256"][self.case()["case_id"]] = "b" * 64
        self.refresh_resume_proof()
        self.rejects("completed trace differs")

    def test_resume_without_case_fixture_still_checks_reconstructed_trace(self):
        self.add_completed_frontier_resume()
        reference = self.manifest["resume"]["completed_cases"].pop()
        (self.root / reference["path"]).unlink()
        self.manifest["resume"]["preflight"]["completed_trace_sha256"][self.case()["case_id"]] = "b" * 64
        self.refresh_resume_proof()
        self.rejects("completed case result changed")

    def test_resume_completed_case_order_cannot_skip_an_earlier_case(self):
        self.add_completed_frontier_resume()
        self.manifest["resume"]["preflight"]["completed_trace_sha256"] = {self.case(1)["case_id"]: "b" * 64}
        self.refresh_resume_proof()
        self.rejects("leading completed cases")

    def test_resume_equal_prefix_cannot_claim_a_different_boundary(self):
        self.add_second_resume()
        top = self.manifest["resume"]
        ancestor = top["ancestors"][0]
        top["prefix"] = copy.deepcopy(ancestor["prefix"])
        top["preflight"] = copy.deepcopy(ancestor["preflight"])
        next(iter(top["preflight"]["next_requests"].values()))["request_sha256"] = "a" * 64
        self.refresh_resume_proof()
        self.rejects("without progress changed")

    def test_resume_all_completed_cases_need_no_new_live_request(self):
        self.add_resume()
        top = self.manifest["resume"]
        top["prefix"] = []
        preflight = top["preflight"]
        preflight["consumed_sequences"] = {}
        preflight["completed_trace_sha256"] = {}
        preflight["next_requests"] = {}
        for index in range(41):
            case = self.case(index)
            for reference in case["llm_calls"]:
                top["prefix"].append({"case_id": case["case_id"], **{key: reference[key] for key in ("sequence", "path", "sha256")}})
            preflight["consumed_sequences"][case["case_id"]] = [ref["sequence"] for ref in case["llm_calls"]]
            preflight["completed_trace_sha256"][case["case_id"]] = verify.digest_value(case["trace"])
        self.refresh_resume_proof()
        self.assertEqual(42, verify.validate(self.root, require_recorded=True)["llm_calls"])

    def test_resume_preserves_original_harness_and_validates_live_tail(self):
        self.add_resume()
        result = verify.validate(self.root, require_recorded=True)
        self.assertEqual(42, result["llm_calls"])
        self.assertEqual("structural_integrity_not_independent_provider_attestation", result["provenance_verification"])

    def test_resume_requires_the_same_logical_run_id(self):
        self.add_resume()
        self.manifest["resume"]["source_run_id"] = "another-run"
        self.save()
        self.rejects("logical run ID")

    def test_resume_requires_all_original_artifacts(self):
        self.add_resume()
        self.manifest["resume"]["source_artifacts"].pop("harness")
        self.save()
        self.rejects("five original")

    def test_resume_source_bytes_and_semantic_versions_are_pinned(self):
        self.add_resume()
        ref = self.manifest["resume"]["source_artifacts"]["config"]
        target = self.root / ref["path"]
        target.write_text("{}", encoding="utf-8")
        self.rejects("source artifact checksum")
        ref["sha256"] = verify.sha(target)
        self.save()
        self.rejects("semantic source version")

    def test_resume_source_harness_cannot_add_arbitrary_paths(self):
        self.add_resume()
        ref = self.manifest["resume"]["source_artifacts"]["harness"]
        value = verify.read(self.root / ref["path"])
        value["../secret"] = "a" * 64
        ref["sha256"] = self.write(ref["path"], value)
        self.save()
        self.rejects("outside baseline tools")

    def test_resume_prefix_is_immutable_even_when_outer_hashes_are_updated(self):
        self.add_resume()
        def mutate(record):
            record["response"]["latencyMs"] += 1
            record["response_sha256"] = verify.digest_value(record["response"])
        self.edit_call(mutate)
        self.rejects("prefix bytes changed")

    def test_resume_prefix_must_be_first_case_contiguous_and_canonical(self):
        self.add_resume()
        original = copy.deepcopy(self.manifest["resume"])
        for change in ({"sequence": True}, {"sequence": 2}, {"case_id": "manual.opt_out"}, {"path": "../outside.json"}):
            with self.subTest(change=change):
                self.manifest["resume"] = copy.deepcopy(original)
                self.manifest["resume"]["prefix"][0].update(change)
                self.save()
                self.rejects("leading case order|per-case sequences|prefix path")

    def test_resume_requires_two_exhausted_identical_network_free_prefix_passes(self):
        self.add_resume()
        original = copy.deepcopy(self.manifest["resume"])
        attacks = [{"passes": 1}, {"network_calls": False}, {"network_calls": 1},
                   {"consumed_sequences": {}}, {"pass_sha256": ["a" * 64, "b" * 64]}, {"next_requests": {}}]
        for change in attacks:
            with self.subTest(change=change):
                self.manifest["resume"] = copy.deepcopy(original)
                self.manifest["resume"]["preflight"].update(change)
                self.save()
                self.rejects("Resume")

    def test_resume_next_request_is_bound_to_actual_tail_not_just_a_pair_of_hashes(self):
        self.add_resume()
        preflight = self.manifest["resume"]["preflight"]
        next(iter(preflight["next_requests"].values()))["request_body_sha256"] = "f" * 64
        value = {key: preflight[key] for key in ("consumed_sequences", "next_requests", "completed_trace_sha256")}
        preflight["pass_sha256"] = [verify.digest_value(value)] * 2
        self.save()
        self.rejects("tail differs")

    def test_resume_requires_an_observed_tail_after_the_prefix(self):
        self.add_resume()
        case = self.case()
        tail = case["llm_calls"].pop()
        (self.root / tail["path"]).unlink()
        case["replay"]["consumed_sequences"] = [[1], [1]]
        item = self.manifest["cases"][0]
        item["fixture_sha256"] = self.write(item["fixture"], case)
        self.save()
        self.rejects("observed live tail")

    def test_resume_cannot_turn_a_synthetic_prefix_into_recorded_evidence(self):
        self.add_resume()
        self.edit_call(lambda record: record.update(provenance="synthetic"))
        self.manifest["resume"]["prefix"][0]["sha256"] = self.case()["llm_calls"][0]["sha256"]
        self.save()
        self.rejects("real-provider recorded")

    def test_resume_lineage_does_not_permit_undeclared_files(self):
        self.add_resume()
        self.write("lineage/source/unlisted.json", {})
        self.rejects("orphan")

    def test_complete_unit_contract_is_structurally_valid_but_not_attested(self):
        result = verify.validate(self.root, require_recorded=True)
        self.assertEqual(result["llm_calls"], 41)
        self.assertEqual(result["offline_replay_passes"], 2)
        self.assertEqual(result["provenance_verification"], "structural_integrity_not_independent_provider_attestation")
        self.assertFalse(result["equivalence_claim"])

    def test_legacy_baseline_still_passes_and_is_not_recorded(self):
        result = verify.validate(REFERENCE)
        self.assertEqual(result["recorded_real_llm"], 0)
        self.assertEqual(result["missing"], 29)
        with self.assertRaisesRegex(ValueError, "incomplete"):
            verify.validate(REFERENCE, require_recorded=True)

    def test_41_exact_case_ids_and_kinds_are_required(self):
        self.manifest["cases"][0]["id"] = "persona.invented"
        self.save()
        self.rejects("Unknown case ID")

    def test_duplicate_cases_are_rejected(self):
        self.manifest["cases"][0] = copy.deepcopy(self.manifest["cases"][1])
        self.save()
        self.rejects("distinct")

    def portable_harness(self):
        reference = self.manifest["artifacts"]["harness"]
        _, files = verify.parse_harness(verify.read(self.root / reference["path"]))
        for name in files:
            source = self.harness_root / name
            source.write_bytes(source.read_bytes() + b"\nsecond source line\n")
        wrapper = {"schema_version": 2, "hashing": "sha256-utf8-lf-v1", "files": {
            name: verify.harness_source_sha(self.harness_root / name, "sha256-utf8-lf-v1") for name in files}}
        reference["sha256"] = self.write(reference["path"], wrapper)
        self.manifest["versions"]["harness_sha256"] = reference["sha256"]
        for index, item in enumerate(self.manifest["cases"]):
            case = self.case(index)
            case["versions"] = copy.deepcopy(self.manifest["versions"])
            item["fixture_sha256"] = self.write(item["fixture"], case)
        self.save()
        return wrapper

    def test_portable_harness_verifies_both_lf_and_crlf_checkouts(self):
        wrapper = self.portable_harness()
        self.assertEqual("passed", verify.validate(self.root)["result"])
        for name in wrapper["files"]:
            source = self.harness_root / name
            source.write_bytes(source.read_bytes().replace(b"\n", b"\r\n"))
        self.assertEqual("passed", verify.validate(self.root)["result"])

    def test_portable_harness_preserves_legacy_resume_artifact_bytes(self):
        self.add_completed_frontier_resume()
        old_reference = self.manifest["resume"]["source_artifacts"]["harness"]
        before = (self.root / old_reference["path"]).read_bytes()
        self.portable_harness()
        self.assertEqual("passed", verify.validate(self.root)["result"])
        self.assertEqual(before, (self.root / old_reference["path"]).read_bytes())
        self.assertEqual("sha256-raw-v1", verify.parse_harness(verify.read(self.root / old_reference["path"]))[0])

    def test_portable_harness_cannot_hide_a_source_content_change(self):
        wrapper = self.portable_harness()
        source = self.harness_root / next(iter(wrapper["files"]))
        source.write_bytes(source.read_bytes().replace(b"second source line", b"changed source line"))
        self.rejects("Harness version differs")

    def test_portable_harness_requires_explicit_known_schema_and_utf8(self):
        wrapper = self.portable_harness()
        for invalid in ({**wrapper, "hashing": "optional"}, {**wrapper, "schema_version": True},
                        {**wrapper, "extra": "untracked"}, {**wrapper, "files": {}}):
            with self.subTest(invalid=list(invalid)):
                with self.assertRaises(ValueError):
                    verify.parse_harness(invalid)
        source = self.harness_root / next(iter(wrapper["files"]))
        source.write_bytes(b"\xff")
        with self.assertRaises(UnicodeDecodeError):
            verify.harness_source_sha(source, "sha256-utf8-lf-v1")

    def test_portable_harness_does_not_normalize_lone_cr_or_other_bytes(self):
        source = self.harness_root / next(iter(verify.REQUIRED_HARNESS))
        source.write_bytes(b"first\nsecond\n")
        expected = verify.harness_source_sha(source, "sha256-utf8-lf-v1")
        source.write_bytes(b"first\rsecond\n")
        self.assertNotEqual(expected, verify.harness_source_sha(source, "sha256-utf8-lf-v1"))
        self.assertEqual(verify.sha(source), verify.harness_source_sha(source, "sha256-raw-v1"))
        with self.assertRaisesRegex(ValueError, "Unknown harness"):
            verify.harness_source_sha(source, "auto")

    def test_missing_or_synthetic_cases_never_count(self):
        self.manifest["cases"][0]["provenance"] = "synthetic"
        self.save()
        self.rejects("41 recorded")

    def test_case_hash_detects_unreviewed_changes(self):
        path = self.root / self.manifest["cases"][0]["fixture"]
        path.write_text(path.read_text(encoding="utf-8") + " ", encoding="utf-8")
        self.rejects("Case fixture checksum")

    def test_call_hash_detects_unreviewed_changes(self):
        path = self.root / self.case()["llm_calls"][0]["path"]
        path.write_text(path.read_text(encoding="utf-8") + " ", encoding="utf-8")
        self.rejects("LLM call file checksum")

    def test_recorded_label_cannot_wrap_fake_call(self):
        self.edit_call(lambda call: call.update(provenance="synthetic"))
        self.rejects("real-provider")

    def test_request_hash_is_recomputed(self):
        self.edit_call(lambda call: call["request"]["options"].update(temperature=0.7))
        self.rejects("request checksum")

    def test_response_hash_is_recomputed(self):
        self.edit_call(lambda call: call["response"].update(content="Changed"))
        self.rejects("response checksum")

    def test_body_hash_and_body_messages_are_verified(self):
        def mutate(call):
            call["evidence"]["request_body"]["messages"][0]["content"] = "Changed wire input"
            call["evidence"]["request_body_sha256"] = verify.digest_value(call["evidence"]["request_body"])
        self.edit_call(mutate)
        self.rejects("Wire messages differ")

    def test_production_broker_envelope_preserves_donor_anthropic_adaptation(self):
        record = verify.read(self.root / self.case()["llm_calls"][0]["path"])
        request, wire = record["request"], record["evidence"]["request_body"]
        schema = {"type": "object", "properties": {"answer": {"type": "string"}}}
        request["options"]["response_format"] = {"type": "json_schema", "json_schema": {"schema": schema}}
        wire["messages"] = request["messages"] + [{"role": "user", "content": "Ответь строго одним JSON-объектом без пояснений и без ``` по схеме: " + json.dumps(schema, ensure_ascii=False)}]
        verify.verify_wire(request, wire)
        wire["provider"]["require_parameters"] = True
        with self.assertRaisesRegex(ValueError, "donor parameter requirement"):
            verify.verify_wire(request, wire)

    def test_production_broker_envelope_preserves_google_structured_format(self):
        record = verify.read(self.root / self.case()["llm_calls"][0]["path"])
        request, wire = record["request"], record["evidence"]["request_body"]
        request["options"]["model"] = wire["model"] = "google/gemini-2.5-flash"
        request["options"]["response_format"] = wire["response_format"] = {"type": "json_object"}
        wire["provider"].update(only=["google-ai-studio"], order=["google-ai-studio"], require_parameters=True)
        verify.verify_wire(request, wire)

    def duplicate_tool_record(self):
        record = verify.read(self.root / self.case()["llm_calls"][0]["path"])
        first = {"type": "function", "function": {"name": "send_price", "description": "Unit tool", "parameters": {"type": "object", "properties": {}}}}
        second = {"type": "function", "function": {"name": "set_followup", "parameters": {"type": "object", "properties": {}}}}
        record["request"]["options"].update(tools=[first, second, copy.deepcopy(first)], tool_choice="auto")
        record["evidence"]["request_body"].update(tools=[copy.deepcopy(first), copy.deepcopy(second)], tool_choice="auto")
        record["request_sha256"] = verify.digest_value(record["request"])
        record["evidence"]["request_body_sha256"] = verify.digest_value(record["evidence"]["request_body"])
        return record

    def test_wire_removes_only_identical_tool_declarations_in_first_order(self):
        record = self.duplicate_tool_record()
        duplicate = record["request"]["options"]["tools"][-1]
        record["request"]["options"]["tools"][-1] = dict(reversed(list(duplicate.items())))
        original = copy.deepcopy(record["request"])
        verify.verify_wire(record["request"], record["evidence"]["request_body"])
        self.assertEqual(original, record["request"])
        self.assertEqual(3, len(record["request"]["options"]["tools"]))
        self.assertEqual(2, len(record["evidence"]["request_body"]["tools"]))

    def test_wire_rejects_conflicting_duplicate_tool_definitions(self):
        record = self.duplicate_tool_record()
        record["request"]["options"]["tools"][-1]["function"]["description"] = "Different tool contract"
        with self.assertRaisesRegex(ValueError, "Conflicting duplicate"):
            verify.verify_wire(record["request"], record["evidence"]["request_body"])

    def test_wire_rejects_duplicate_physical_tools_reordering_or_removal(self):
        record = self.duplicate_tool_record()
        variants = [record["request"]["options"]["tools"],
                    list(reversed(record["evidence"]["request_body"]["tools"])),
                    record["evidence"]["request_body"]["tools"][:1]]
        for tools in variants:
            body = copy.deepcopy(record["evidence"]["request_body"])
            body["tools"] = tools
            with self.assertRaisesRegex(ValueError, "Wire tool options differ"):
                verify.verify_wire(record["request"], body)

    def test_resume_frontier_restores_original_donor_tool_duplicates(self):
        record = self.duplicate_tool_record()
        expected = copy.deepcopy(record["evidence"]["request_body"])
        for key in ("stream", "plugins", "provider"):
            expected.pop(key)
        expected["tools"] = copy.deepcopy(record["request"]["options"]["tools"])
        original_wire = copy.deepcopy(record["evidence"]["request_body"])
        restored = verify.resume_donor_body(record)
        self.assertEqual(verify.digest_value(expected), verify.digest_value(restored))
        self.assertEqual(original_wire, record["evidence"]["request_body"])
        self.assertEqual(3, len(restored["tools"]))

    def test_tool_adapter_does_not_deduplicate_response_invocations(self):
        record = self.duplicate_tool_record()
        response = record["response"]
        response["toolCalls"] = [{"id": name, "name": "send_price", "arguments": []} for name in ("one", "two")]
        response["raw"]["choices"][0]["message"]["tool_calls"] = [
            {"id": name, "type": "function", "function": {"name": "send_price", "arguments": "{}"}}
            for name in ("one", "two")]
        record["response_sha256"] = verify.digest_value(response)
        verify.validate_call(record, self.case(), self.case()["llm_calls"][0], self.manifest["run_id"], set())
        self.assertEqual(2, len(response["toolCalls"]))

    def test_broker_envelope_rejects_redirected_routes_addons_and_unbounded_prices(self):
        original = verify.read(self.root / self.case()["llm_calls"][0]["path"])
        attacks = [
            lambda wire: wire.update(stream=True),
            lambda wire: wire.update(plugins=[{"id": "web"}]),
            lambda wire: wire["provider"].update(only=["other-provider"]),
            lambda wire: wire["provider"].update(order=["anthropic", "other-provider"]),
            lambda wire: wire["provider"].update(allow_fallbacks=True),
            lambda wire: wire["provider"].update(require_parameters=True),
            lambda wire: wire["provider"].update(extra="override"),
            lambda wire: wire["provider"]["max_price"].update(prompt="3"),
            lambda wire: wire["provider"]["max_price"].update(request=51),
            lambda wire: wire["provider"]["max_price"].update(completion=float("inf")),
            lambda wire: wire["provider"]["max_price"].update(image=1),
            lambda wire: wire.update(unknown_option=True),
            lambda wire: wire.update(reasoning={"effort": "high"}),
        ]
        for index, attack in enumerate(attacks):
            with self.subTest(index=index):
                wire = copy.deepcopy(original["evidence"]["request_body"])
                attack(wire)
                with self.assertRaises(ValueError):
                    verify.verify_wire(original["request"], wire)

    def test_reply_must_match_provider_receipt_even_with_recomputed_hash(self):
        def mutate(call):
            call["response"]["content"] = "Invented replay response"
            call["response_sha256"] = verify.digest_value(call["response"])
        self.edit_call(mutate)
        self.rejects("Replay content differs")

    def test_reused_provider_receipt_is_rejected(self):
        first_id = verify.read(self.root / self.case()["llm_calls"][0]["path"])["evidence"]["provider_request_id"]
        def mutate(call):
            call["evidence"]["provider_request_id"] = first_id
            call["response"]["raw"]["id"] = first_id
            call["response_sha256"] = verify.digest_value(call["response"])
        self.edit_call(mutate, 1)
        self.rejects("receipt ID")

    def test_false_cost_or_token_totals_are_rejected(self):
        self.edit_call(lambda call: call["evidence"]["usage"].update(cost_usd="0"))
        self.rejects("cost accounting")

    def test_sequence_gap_is_rejected(self):
        self.edit_case(lambda case: case["llm_calls"][0].update(sequence=2))
        self.rejects("sequence")

    def test_unused_calls_and_network_replay_are_rejected(self):
        self.edit_case(lambda case: case["replay"].update(consumed_sequences=[[1], []]))
        self.rejects("fully exhausted offline")

    def test_replay_trace_divergence_is_rejected(self):
        self.edit_case(lambda case: case["replay"].update(run_trace_sha256=["0" * 64, "1" * 64]))
        self.rejects("identical")

    def test_boolean_network_claim_does_not_equal_zero(self):
        self.edit_case(lambda case: case["replay"].update(network_calls=False))
        self.rejects("offline")

    def test_orphan_call_is_rejected(self):
        self.write("llm/orphan/0001.json", {"unused": True})
        self.rejects("orphan")

    def test_paths_reject_traversal_absolute_windows_ads_and_backslash(self):
        for path in ("../outside.json", "/tmp/outside.json", "C:/outside.json", "calls/x.json:stream", "calls\\x.json", "calls/CON.json"):
            with self.subTest(path=path):
                self.manifest["cases"][0]["fixture"] = path
                self.save()
                self.rejects("path")

    def test_symlink_is_rejected_when_supported(self):
        outside = Path(self.temporary.name) / "outside.json"
        outside.write_text("{}", encoding="utf-8")
        link = self.root / "linked.json"
        try:
            link.symlink_to(outside)
        except OSError:
            self.skipTest("Host does not permit symlink creation")
        self.manifest["cases"][0]["fixture"] = "linked.json"
        self.save()
        self.rejects("Linked evidence")

    def test_wrong_source_version_is_rejected(self):
        self.manifest["versions"]["prototype_source_sha256"] = "0" * 64
        self.save()
        self.rejects("Version digest")

    def test_missing_capture_normalization_disclosure_is_rejected(self):
        self.edit_case(lambda case: case["capture"].update(byte_identical_persona_inputs=True))
        self.rejects("normalization limits")

    def test_runner_seed_is_not_a_provider_seed(self):
        self.edit_case(lambda case: case["capture"]["seed"].update(requested=20261031, provider_support="supported"))
        self.rejects("Unsupported seed")

    def test_scheduler_calls_cannot_be_claimed_as_dialogue(self):
        self.edit_case(lambda case: case["llm_calls"][0].update(phase="dialogue"), 40)
        self.rejects("phase")

    def test_scheduler_claim_needs_actual_boundary_observations(self):
        self.edit_case(lambda case: case["trace"].pop("scheduler"), 40)
        self.rejects("timer observations")

    def test_missing_model_receipt_is_rejected(self):
        self.edit_call(lambda call: call["evidence"].update(provider_model=""))
        self.rejects("model receipt")

    def test_scenario_cannot_be_renamed_without_changing_its_bound_identity(self):
        self.edit_case(lambda case: case["execution"]["scenario"].update(case_id="manual.opt_out"))
        self.rejects("Scenario identity")

    def test_duplicate_json_keys_fail_before_schema_selection(self):
        (self.root / "manifest.json").write_text('{"schema_version": 2, "schema_version": 1}', encoding="utf-8")
        self.rejects("Duplicate JSON")

    def test_unredacted_contacts_and_credentials_are_rejected_without_echoing_them(self):
        secret = "sk-" + "a" * 20
        self.manifest["unexpected"] = secret
        self.save()
        with self.assertRaises(ValueError) as caught:
            verify.validate(self.root)
        self.assertNotIn(secret, str(caught.exception))
        self.assertIn("credential", str(caught.exception))

    def test_unredacted_real_email_is_rejected(self):
        self.manifest["unexpected"] = "person@example.test"
        self.save()
        self.rejects("email")

    def test_php_canonical_float_and_unicode_compatibility(self):
        self.assertEqual(verify.canonical_json([1e-6, 1e16, 1e17, -0.0]), '[\n    1.0e-6,\n    10000000000000000.0,\n    1.0e+17,\n    -0.0\n]\n')
        self.assertIn('\\u2028', verify.canonical_json("x\u2028y"))
        self.assertNotEqual(verify.digest_value({}), verify.digest_value([]))
        self.assertNotEqual(verify.digest_value(1.0), verify.digest_value(1))

    def test_cli_invalid_schema_fails_without_traceback(self):
        (self.root / "manifest.json").write_text("[]", encoding="utf-8")
        process = subprocess.run([sys.executable, str(REPO / "tools/baseline/verify.py"), "--fixtures", str(self.root)], capture_output=True, text=True, check=False)
        self.assertEqual(process.returncode, 1)
        self.assertEqual(json.loads(process.stdout)["result"], "failed")
        self.assertNotIn("Traceback", process.stderr)


if __name__ == "__main__":
    unittest.main()
