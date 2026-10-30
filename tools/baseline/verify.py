#!/usr/bin/env python3
"""Validate offline baseline evidence without loading PHP or making network requests."""
import argparse
import hashlib
import json
import re
from pathlib import Path


def read(path):
    return json.loads(path.read_text(encoding="utf-8"))


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def check(condition, message):
    if not condition:
        raise ValueError(message)


def files(root):
    return {str(path.relative_to(root)).replace("\\", "/"): sha(path) for path in root.rglob("*") if path.is_file()}


def validate(root, prototype=None, compare=None, require_recorded=False):
    manifest = read(root / "manifest.json")
    cases = manifest["cases"]
    check(len(cases) == 41 and len({item["id"] for item in cases}) == 41, "Expected 41 distinct cases")
    check(sum(item["kind"] == "persona" for item in cases) == 29, "Expected 29 personas")
    check(sum(item["kind"] == "manual" for item in cases) == 12, "Expected 12 manual cases")
    check(manifest["equivalence_claim"] is False, "This package cannot claim full live equivalence")
    check(all(item["provenance"] in ("recorded", "synthetic", "missing") for item in cases), "Unknown provenance")
    missing = [item["id"] for item in cases if item["provenance"] == "missing"]
    for item in cases:
        if item["provenance"] == "missing":
            check(item["fixture"] is None, "Missing evidence cannot reference an invented fixture")
        else:
            data = read(root / item["fixture"])
            check(data["case_id"] == item["id"] and data["provenance"] == item["provenance"], "Fixture identity differs")
    snapshots = manifest["prompt_snapshots"]
    check(len(snapshots) == 20 and len({item["state"] for item in snapshots}) == 5, "Expected 4 prompts across 5 states")
    for item in snapshots:
        check(sha(root / item["path"]) == item["sha256"], "Prompt changed: " + item["path"])
    traces = {item["id"]: read(root / item["fixture"])["trace"] for item in cases if item["kind"] == "manual"}
    check(traces["manual.opt_out"][-1]["goal"]["kind"] == "opt_out", "opt-out behavior drift")
    check([turn["goal"]["kind"] for turn in traces["manual.off_topic_three"]] == ["decline_off_topic", "decline_off_topic", "silent"], "off-topic behavior drift")
    for name in ("negative_twice", "deferred_twice", "wants_human"):
        check(traces["manual." + name][-1]["goal"]["kind"] == "handoff", name + " behavior drift")
    check(traces["manual.prompt_injection"][-1]["goal"]["kind"] == "decline_injection", "injection behavior drift")
    check(traces["manual.large_order"][-1]["state"]["signals"]["notify_manager"] == "big_order", "large order must notify")
    check(traces["manual.closed_topic"][-1]["goal"]["kind"] == "closed_topic", "closed topic behavior drift")
    check(traces["manual.no_repeat_filled_slot"][-1]["goal"]["slot"] != "city", "Filled city asked again")
    check(traces["manual.force_close_14"][-1]["state"]["stage"] == "close_contact", "14 turn force-close drift")
    check([turn["followups_sent"] for turn in traces["manual.followup_20h"]] == [0, 1], "20 hour boundary drift")
    check([turn["followups_sent"] for turn in traces["manual.followup_40h"]] == [1, 1, 2, 2, 2], "40 hour boundary drift")
    check(traces["manual.followup_40h"][-1]["outcome"] == "stale", "Post-second followup stale boundary drift")
    records = sorted((root / "llm" / "synthetic_open_turn").glob("*.json"))
    check(len(records) == 3, "Expected three synthetic LLM calls")
    check(all(read(path)["provenance"] == "synthetic" for path in records), "Synthetic calls mislabeled recorded")
    if prototype:
        fingerprints = read(root / "source-fingerprints.json")
        for name, expected in fingerprints.items():
            check(sha(prototype / name) == expected, "Donor changed: " + name)
        persona_ids = re.findall(r"^- id:\s*(\S+)", (prototype / "tools/personas.yaml").read_text(encoding="utf-8"), re.M)
        check({"persona." + value for value in persona_ids} == {item["id"] for item in cases if item["kind"] == "persona"}, "Persona inventory differs from donor")
    if compare:
        expected, actual = files(root), files(compare)
        changes = sorted(name for name in expected.keys() | actual.keys() if expected.get(name) != actual.get(name))
        check(not changes, "Regenerated evidence differs: " + ", ".join(changes))
    recorded = sum(item["provenance"] == "recorded" for item in cases)
    if require_recorded:
        check(recorded == 41, f"Real recorded baseline incomplete: {recorded}/41; synthetic fixtures do not count")
    return {"result": "passed", "cases": len(cases), "prompt_snapshots": len(snapshots),
            "recorded_real_llm": recorded, "synthetic": 12, "missing": len(missing), "equivalence_claim": False}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--fixtures", type=Path, default=Path("tests/fixtures/prototype"))
    parser.add_argument("--prototype", type=Path)
    parser.add_argument("--compare", type=Path)
    parser.add_argument("--require-recorded", action="store_true")
    args = parser.parse_args()
    try:
        print(json.dumps(validate(args.fixtures, args.prototype, args.compare, args.require_recorded), ensure_ascii=False))
    except (OSError, ValueError, KeyError) as error:
        print(json.dumps({"result": "failed", "error": str(error)}, ensure_ascii=False))
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
