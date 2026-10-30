"""Validate the planning registry without booting the app or contacting services."""
from __future__ import annotations

import json
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError(f"Duplicate JSON key: {key}")
        result[key] = value
    return result


def read(path):
    return json.loads(path.read_text(encoding="utf-8"), object_pairs_hook=unique_object)


def in_scope(path: str, scopes: list[str]) -> bool:
    path = path.replace("\\", "/").rstrip("/").casefold()
    for scope in scopes:
        normalized = scope.replace("\\", "/").rstrip("/").casefold()
        if path == normalized or (scope.endswith("/") and path.startswith(normalized + "/")):
            return True
    return False


def main():
    registry = read(ROOT / "docs/contracts/registry.json")
    plan = read(ROOT / "planning/tasks.json")
    tasks = {task["taskKey"]: task for task in plan["tasks"]}
    if len(tasks) != len(plan["tasks"]):
        raise ValueError("Duplicate task key")
    assert registry["version"] == 1

    for section in ("tables", "contracts", "events", "sharedFiles"):
        for name, item in registry[section].items():
            owner = item["owner"]
            assert owner in tasks, (section, name, "undefined owner", owner)
            if section == "contracts":
                assert in_scope(item["path"], tasks[owner]["scope"]), (name, "contract outside owner scope")
            if section == "sharedFiles":
                assert in_scope(name, tasks[owner]["scope"]), (name, "shared file outside owner scope")
                for writer in item["serialWriters"]:
                    assert writer in tasks and in_scope(name, tasks[writer]["scope"]), (name, writer)

    for name, table in registry["tables"].items():
        assert table["tenant"] in {"global", "organization", "brand", "platform_or_brand"} or table["tenant"].startswith("parent:"), name
        for parent in table["parents"]:
            assert parent in registry["tables"], (name, "unknown parent", parent)
        if table["tenant"].startswith("parent:"):
            assert table["tenant"].split(":", 1)[1] in table["parents"], name
    required = {"users", "organizations", "brands", "memberships", "invitations", "channel_accounts", "brand_seller_settings", "conversations", "seller_turns", "knowledge_releases", "kn_escalation", "organization_plan", "report_publications"}
    assert required <= set(registry["tables"])
    assert not set(registry["excluded"]["tables"]) & set(registry["tables"])

    visiting, visited = set(), set()
    def walk(name):
        assert name not in visiting, ("Circular domain import", name)
        if name in visited:
            return
        visiting.add(name)
        for dependency in registry["domains"][name]:
            assert dependency in registry["domains"], (name, dependency)
            walk(dependency)
        visiting.remove(name)
        visited.add(name)
    for name in registry["domains"]:
        walk(name)

    for prefix, expected in (("vision.", 6), ("seller.", 7)):
        actual = sum(key.startswith(prefix) and event["notificationEvent"] for key, event in registry["events"].items())
        assert actual == expected, (prefix, actual, expected)
    for event in registry["events"].values():
        assert event["schemaVersion"] == 1 and len(event["requiredData"]) == len(set(event["requiredData"]))
    print(json.dumps({"status": "ok", **{section: len(registry[section]) for section in ("tables", "contracts", "events", "sharedFiles", "domains")}, "domain_import_cycles": 0, "owner_scope_checks": "passed", "network_calls": 0}))


if __name__ == "__main__":
    main()
