"""Three-stage full synthetic capture with real broker/fake HTTP and no live keys.

Run once into a NEW directory under this worktree's .local/baseline.
The two simulated stops occur before POST. Saved provider responses are reused
without re-sending; all output remains explicitly synthetic.
"""
from __future__ import annotations
import argparse
import json
from pathlib import Path
import sys
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import capture
import verify
from capture_broker import CaptureBroker, MODELS
from test_integration import FakeProductionTransport, FAKE_KEY


def simulate(directory: Path) -> dict:
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
    stage_a, stage_b, stage_c = [directory / name for name in ("source-a", "source-b", "final")]
    sources = [None, stage_a, stage_b]
    targets = [stage_a, stage_b, stage_c]
    boundaries = [("persona.experienced_kazan", 2), ("persona.for_self_wedding", 2), None]
    source_snapshots = {}
    with CaptureBroker(key, catalog, ledger, transport=transport) as broker:
        broker.preflight(sorted(MODELS))
        for index, (source, output, boundary) in enumerate(zip(sources, targets, boundaries)):
            def handler(message):
                if boundary == (message["case_id"], message["sequence"]):
                    raise capture.CaptureError("synthetic_crash_before_post")
                transport.message = message
                return broker.call(message["body"])
            args = argparse.Namespace(
                prototype=capture.ROOT / ".local/prototype",
                php=Path("C:/OSPanel/modules/PHP-8.4/php.exe"),
                output=output, ledger=ledger, key_file=key, catalog=catalog,
                allow_live_openrouter=False, acknowledge_pseudonymized_inputs=False,
                preflight_only=False, test_case="all", resume_from=source,
            )
            with patch.object(capture, "FakeBroker", return_value=handler):
                try:
                    result = capture.execute(args)
                except capture.CaptureError as error:
                    if boundary is None or str(error) != "synthetic_crash_before_post":
                        raise
                else:
                    if boundary is not None:
                        raise AssertionError("Expected the synthetic stop")
            for path, before in source_snapshots.items():
                if verify.files(path) != before:
                    raise AssertionError("Historical source changed")
            if boundary is not None:
                source_snapshots[output] = verify.files(output)
                expected = 3 if index == 0 else 5
                if len(transport.posts) != expected:
                    raise AssertionError("Paid prefix was repeated")
        manifest = verify.read(stage_c / "manifest.json")
        records = [verify.read(stage_c / call["path"])
                   for case in manifest["cases"]
                   for call in verify.read(stage_c / case["fixture"])["llm_calls"]]
        capture.validate_resume_ledger(ledger, {"records": records})
        if len(records) != len(transport.posts) or len(records) != 116:
            raise AssertionError("Duplicate or missing synthetic physical POST")
        if len(manifest["resume"].get("ancestors", [])) != 1:
            raise AssertionError("Two source generations were not preserved")
        if manifest["coverage"]["synthetic"] != 41 or manifest["coverage"]["recorded_real_llm"] != 0:
            raise AssertionError("Synthetic provenance changed")
        return {"result": "passed", "synthetic_cases": 41, "fake_physical_posts": len(transport.posts),
                "final_offline_replay_receipts": 82, "resume_levels": 2, "real_network_calls": 0,
                "output": str(stage_c), "source_bytes_unchanged": True}


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    print(json.dumps(simulate(args.output), ensure_ascii=False), flush=True)
