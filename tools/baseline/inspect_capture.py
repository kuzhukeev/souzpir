#!/usr/bin/env python3
"""Read-only, metadata-only diagnosis. Never sends HTTP or changes accounting."""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import re
import sqlite3
from decimal import Decimal

ROOT = Path(__file__).resolve().parents[2]
DEFAULT_LEDGER = ROOT / ".local/baseline/budget.sqlite"


def read_diagnosis(ledger: Path) -> dict:
    ledger = ledger.resolve(strict=True)
    local = (ROOT / ".local").resolve(strict=True)
    if not local.is_relative_to(ROOT.resolve()) or not ledger.is_relative_to(local):
        raise ValueError("diagnostic_path_refused")
    connection = sqlite3.connect(ledger.as_uri() + "?mode=ro", uri=True, isolation_level=None)
    try:
        connection.execute("BEGIN")
        budget = connection.execute("SELECT limit_micros,blocked FROM budget WHERE id=1").fetchone()
        rows = connection.execute("SELECT id,request_hash,model,reserved_micros,charged_micros,state,reason,created_at,settled_at FROM calls ORDER BY id").fetchall()
        connection.execute("ROLLBACK")
    finally:
        connection.close()
    directory = Path(str(ledger) + ".checkpoints")
    safe_directory = not directory.is_symlink() and directory.resolve().parent == ledger.parent
    calls = []
    for call_id, request_hash, model, reserved, charged, state, reason, created, settled in rows:
        if any(type(value) is not int or value < 0 for value in (call_id, reserved)):
            raise ValueError("diagnostic_ledger_invalid")
        if charged is not None and (type(charged) is not int or charged < 0):
            raise ValueError("diagnostic_ledger_invalid")
        item = {
            "call_id": call_id, "model": model, "state": state, "reason": reason,
            "reserved_usd": format(Decimal(reserved) / 1000000, "f"),
            "charged_usd": None if charged is None else format(Decimal(charged) / 1000000, "f"),
            "created_at": created, "settled_at": settled,
            "checkpoint": {"present": False, "replay_eligible": False},
            "poll_attempts": [],
        }
        path = directory / f"{call_id:06d}.receipt.json"
        if safe_directory and path.is_file() and not path.is_symlink() and path.stat().st_size <= 65536:
            data = json.loads(path.read_text(encoding="utf-8"))
            if data.get("call_id") == call_id and data.get("request_sha256") == request_hash and data.get("replay_eligible") is False:
                generation = data.get("generation_id")
                if generation is not None and not (isinstance(generation, str) and re.fullmatch(r"gen-[A-Za-z0-9_-]{1,200}", generation)):
                    raise ValueError("diagnostic_checkpoint_invalid")
                usage = {}
                for name in ("prompt_tokens", "completion_tokens", "total_tokens"):
                    value = data.get("usage", {}).get(name)
                    if type(value) is int and value >= 0:
                        usage[name] = value
                cost = data.get("usage", {}).get("cost_usd")
                if isinstance(cost, str) and re.fullmatch(r"\d+(?:\.\d+)?(?:[eE][+-]?\d+)?", cost):
                    usage["cost_usd"] = cost
                item["checkpoint"] = {"present": True, "generation_id": generation,
                                      "usage": usage, "replay_eligible": False, "response_retained": False}
        if safe_directory and directory.is_dir():
            for poll in sorted(directory.glob(f"{call_id:06d}.poll-*.json")):
                if poll.is_symlink() or poll.stat().st_size > 65536:
                    continue
                data = json.loads(poll.read_text(encoding="utf-8"))
                outcome, attempt, status = data.get("outcome"), data.get("attempt"), data.get("http_status")
                if data.get("call_id") != call_id or not isinstance(outcome, str) or not re.fullmatch(r"[a-z_]{1,80}", outcome) or type(attempt) is not int:
                    continue
                item["poll_attempts"].append({"attempt": attempt, "outcome": outcome,
                                               "http_status": status if type(status) is int and 100 <= status <= 599 else None})
        calls.append(item)
    return {
        "result": "read_only", "limit_usd": format(Decimal(budget[0]) / 1000000, "f"),
        "blocked": budget[1], "calls": calls,
        "limitations": [
            "Missing checkpoints cannot recover a lost generation ID or response.",
            "A reservation is an upper bound, not a confirmed provider charge.",
            "This command does not reconcile, unlock, retry, or send any request.",
        ],
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--ledger", type=Path, default=DEFAULT_LEDGER)
    args = parser.parse_args()
    try:
        print(json.dumps(read_diagnosis(args.ledger), ensure_ascii=False))
    except Exception:
        print(json.dumps({"result": "unavailable", "code": "diagnostic_read_failed"}))
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
