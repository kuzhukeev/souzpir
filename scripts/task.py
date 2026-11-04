#!/usr/bin/env python3
"""Coordinate one local Git worktree per task. Python standard library only."""
from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
import tempfile
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path


class TaskError(RuntimeError):
    pass


def git(root: Path, *args: str, check: bool = True) -> str:
    root = root.resolve()
    result = subprocess.run(
        ["git", "-c", "safe.directory=" + root.as_posix(), "-C", str(root), *args],
        capture_output=True, text=True,
        encoding="utf-8", errors="replace",
    )
    if check and result.returncode:
        raise TaskError(result.stderr.strip() or "Git operation failed")
    return result.stdout.rstrip("\r\n") if result.returncode == 0 else ""


def key_name(value: str) -> str:
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_.-]{0,79}", value):
        raise TaskError("Invalid task key")
    return value


# The default outputs of scripts/generate-types/generate.php follow routes and DTOs
# owned by individual tasks and therefore do not require a separate claim.
GENERATED_CONTRACT_FILES = frozenset(
    "resources/js/types/generated/" + name
    for name in ("data.ts", "translations.ts", "permissions.ts", "routes.d.ts")
)

# Narrow integrator-approved scope corrections for shared routes, UI and CI
# regressions. They do not broaden ownership to neighboring modules.
CI_SCOPE_EXCEPTIONS = {
    "SP-E7-03E": ("tests/Browser/E7-03D/fixture.tsx", "tests/Browser/E7-03D/compare.spec.ts"),
    "SP-E1-09D": ("tests/Browser/E1-09/journal.spec.ts", "tests/Unit/Shell/Journal/actorFilter.test.tsx", "tests/Browser/E1-09/selected-actor.spec.ts"),
    "SP-E8-15C": ("tests/Browser/E8-03/pages.spec.ts", "tests/Browser/E8-03/e2e.spec.ts", "tests/Browser/E8-15B/questions.spec.ts"),
    "SP-E8-24B": ("tests/Browser/E8-24/fixture.tsx",),
    "SP-E8-18B": ("tests/Unit/Vision/Reports/ReportSectionsTest.php",),
    "SP-E8-22B": ("tests/Browser/SP-UI-01/history.spec.ts",),
    "SP-E4-01J": ("tests/Feature/Seller/Knowledge/Releases/ReleasesTest.php",),
    "SP-E6-02C": ("tests/Feature/Seller/Quality/EvaluationFeedbackTest.php", "tests/Feature/Seller/Quality/ReportActionsTest.php"),
    "SP-E8-20D": ("tests/Feature/Seller/Quality/EvaluationFeedbackTest.php", "tests/Feature/Seller/Quality/ReportActionsTest.php"),
    "SP-E8-16F": ("tests/Feature/Seller/Quality/EvaluationFeedbackTest.php", "tests/Feature/Seller/Quality/ReportActionsTest.php"),
    "SP-E7-05D": ("scripts/ci/browser-smoke.sh", ".github/workflows/ci.yml"),
    "SP-E8-18D": ("tests/Browser/E8-18B/report.spec.ts",),
    "SP-E7-04C": ("tests/Feature/Seller/Quality/EvaluationFeedbackTest.php", "tests/Feature/Seller/Quality/ReportActionsTest.php"),
    "SP-E8-18C": ("tests/Unit/Vision/Reports/ReportSectionsTest.php", "tests/Feature/Seller/Quality/EvaluationFeedbackTest.php", "tests/Feature/Seller/Quality/ReportActionsTest.php"),
    "SP-E1-09E": ("tests/Browser/E1-09C/fixture.tsx", "tests/Browser/E1-09C/journal.spec.ts"),
    "SP-E8-15B": ("tests/Browser/E8-03/e2e.spec.ts",),
    "SP-E5-04G": ("tests/Browser/E5-04E/filters.spec.ts",),
    "SP-E8-17": ("tests/Browser/E8-10/wizard.spec.ts",),
    "SP-E8-17C": ("tests/Browser/E8-10/wizard.spec.ts",),
    "SP-E8-18": ("tests/Unit/Vision/Reports/ReportSectionsTest.php",),
    "SP-E8-21": ("resources/js/features/Vision/Site/audit.test.tsx",),
    "SP-E4-04C": ("tests/Browser/E4-04/fixture.tsx",),
    "SP-E1-09C": ("tests/Unit/Shell/Journal/actorFilter.test.tsx",),
    "SP-E8-13": ("tests/Browser/E8-10/wizard.spec.ts",),
    "SP-E1-02B": ("tests/Browser/SP-UI-11/readonly.spec.ts", "tests/Browser/E1-02/onboarding-e2e.spec.ts"),
    "SP-E7-01F": ("tests/Browser/E7-01D/fixture.tsx", "tests/Browser/E7-01D/quality.spec.ts"),
    "SP-E3-09": (
        "app/ProtectedOperations/FirstTurnCoordinator.php",
        "app/ProtectedOperations/FirstTurnTextFinalizer.php",
        "app/ProtectedOperations/FirstTurnProtectedClaim.php",
        "app/ProtectedOperations/FirstTurnDeterministicGuard.php",
        "app/Ai/Protected/",
        "database/migrations/C-02/",
        "database/migrations/E3-09/",
        "tests/Feature/ProtectedOperations/",
        "tests/Feature/Ai/Protected/",
        "tests/Unit/Seller/Engine/ToolRunnerTest.php",
    ),
    "SP-E5-05C": ("database/migrations/E5-05C/",),
    "SP-E3-08": (
        "app/ProtectedOperations/SellerFollowup/",
        "routes/features/E3-08.php",
        "tests/Feature/ProtectedOperations/SellerFollowup/",
    ),
    "SP-E1-08C": (
        "resources/js/pages/Shell/Auth/Ready.tsx",
        "routes/features/E0-02.php",
        "routes/features/E1-01.php",
        "tests/Feature/Shell/Auth/LogoutReceiptTest.php",
        "tests/Browser/E1-08/",
    ),
    "SP-E1-10C": ("tests/Browser/E1-10/integrations-ui.spec.ts",),
    "SP-E8-04B": ("tests/Unit/Vision/Measurements/measurement-list.test.tsx",),
}


def scope_allows(path: str, scope: list[str]) -> bool:
    path = path.replace("\\", "/").casefold()
    if path.startswith("/") or path.split("/", 1)[0].casefold() in (".local", ".worktrees", ".git"):
        return False
    if any(part in (".", "..") for part in path.split("/")):
        return False
    if path in GENERATED_CONTRACT_FILES:
        return True
    prefixes = [prefix.replace("\\", "/").casefold().rstrip("/") or "/" for prefix in scope]
    return any(prefix == "/" or path == prefix or path.startswith(prefix + "/") for prefix in prefixes)


def unapproved_changes(worktree: Path, base: str, head: str, scope: list[str]) -> list[str]:
    """Permit unchanged upstream content only with verified main ancestry."""
    changed = git(worktree, "diff", "--name-only", "-z", "--no-renames", base, head).split("\0")
    outside = [path for path in changed if path and not scope_allows(path, scope)]
    if not outside:
        return []
    # A clean task may fast-forward to main before its first commit; in that
    # case there is no merge commit to inspect. Its common ancestor must still
    # descend from the claimed base and its final file contents must match.
    # An explicitly merged parent is reviewed upstream only when it is in main.
    # Matching tree entries are compared at final HEAD, so later unauthorized
    # edits to those same files are rejected. Non-merged branches never qualify.
    approved = []
    try:
        common = git(worktree, "merge-base", head, "refs/heads/main")
        git(worktree, "merge-base", "--is-ancestor", base, common)
        approved.append(common)
    except TaskError:
        pass
    merges = git(worktree, "rev-list", "--first-parent", "--merges", base + ".." + head).splitlines()
    for merge in merges:
        parents = git(worktree, "rev-list", "--parents", "-n", "1", merge).split()[2:]
        for parent in parents:
            try:
                git(worktree, "merge-base", "--is-ancestor", parent, "refs/heads/main")
                git(worktree, "merge-base", "--is-ancestor", base, parent)
            except TaskError:
                continue
            approved.append(parent)
    remaining = set(outside)
    forbidden_roots = {".local", ".worktrees", ".git"}
    for parent in approved:
        differing = set(git(worktree, "diff", "--name-only", "-z", "--no-renames", parent, head, "--", *outside).split("\0"))
        remaining = {path for path in remaining if path in differing or path.replace("\\", "/").split("/", 1)[0].casefold() in forbidden_roots}
    return [path for path in outside if path in remaining]


def scope_signature(scope: list[str]) -> list[str]:
    return sorted({path.replace("\\", "/").casefold().rstrip("/") or "/" for path in scope})


class Api:
    def __init__(self, base: str):
        parsed = urllib.parse.urlsplit(base)
        if parsed.scheme not in ("http", "https") or parsed.query or parsed.fragment:
            raise TaskError("API URL must be an HTTP(S) endpoint without query or fragment")
        if parsed.hostname not in ("tasks.local", "localhost", "127.0.0.1", "::1"):
            raise TaskError("Task tokens may only be sent to the local task queue")
        self.base = base

    def call(self, action: str, payload: dict | None = None, **query) -> dict:
        url = self.base + "?" + urllib.parse.urlencode({"action": action, **query})
        body = None if payload is None else json.dumps(payload).encode("utf-8")
        request = urllib.request.Request(
            url, data=body, headers={"Content-Type": "application/json", "Accept": "application/json"},
            method="GET" if body is None else "POST",
        )
        # Do not send local ownership tokens through a configured HTTP proxy.
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        try:
            with opener.open(request, timeout=20) as response:
                result = json.load(response)
        except urllib.error.HTTPError as error:
            try:
                details = json.loads(error.read())
                message = details.get("error", "Queue request rejected")
                blockers = details.get("blockers", [])
            except (ValueError, TypeError):
                message, blockers = "Queue request rejected", []
            raise TaskError(f"HTTP {error.code}: {message}" + (f"; blockers: {', '.join(blockers)}" if blockers else "")) from None
        except (urllib.error.URLError, TimeoutError, ValueError) as error:
            raise TaskError("Queue response unavailable. A mutation may already have succeeded; inspect the queue before retrying.") from error
        if not isinstance(result, dict):
            raise TaskError("Invalid queue response")
        return result


class Coordinator:
    def __init__(self, root: Path, api: Api, project: str = "souzpir"):
        requested = root.resolve()
        common = Path(git(requested, "rev-parse", "--git-common-dir"))
        common = common if common.is_absolute() else requested / common
        self.root = common.resolve().parent
        if not (self.root / ".git").is_dir():
            raise TaskError("Use a regular main checkout with a .git directory")
        self.api, self.project = api, project
        self.claims = self.root / ".local" / "claims"

    def local_path(self, key: str) -> Path:
        return self.claims / (key_name(key).lower() + ".json")

    def read_claim(self, key: str, *, allow_released: bool = False) -> dict:
        try:
            claim = json.loads(self.local_path(key).read_text(encoding="utf-8"))
        except (OSError, ValueError) as error:
            raise TaskError("No readable local ownership record for this task") from error
        if claim.get("taskKey") != key or claim.get("project") != self.project or (not claim.get("claimToken") and not (allow_released and claim.get("status") == "released")):
            raise TaskError("Local record has no active ownership token")
        return claim

    def save_claim(self, claim: dict, exclusive: bool = False) -> None:
        self.claims.mkdir(parents=True, exist_ok=True)
        path = self.local_path(claim["taskKey"])
        temporary = None
        if exclusive:
            descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        else:
            descriptor, filename = tempfile.mkstemp(prefix=path.name + ".", suffix=".tmp", dir=self.claims)
            temporary = Path(filename)
        try:
            with os.fdopen(descriptor, "w", encoding="utf-8") as handle:
                json.dump(claim, handle, ensure_ascii=False, indent=2)
                handle.flush()
                os.fsync(handle.fileno())
            if temporary is not None:
                os.replace(temporary, path)
        finally:
            if temporary is not None and temporary.exists():
                temporary.unlink()

    def task(self, key: str, tasks: list[dict] | None = None) -> dict:
        key_name(key)
        if tasks is None:
            tasks = self.api.call("list").get("tasks", [])
        matches = [task for task in tasks if task.get("project") == self.project and task.get("taskKey") == key]
        if len(matches) != 1:
            raise TaskError("Task not found or not unique in the project queue")
        return matches[0]

    def plan(self, key: str) -> dict:
        try:
            plan = json.loads((self.root / "planning" / "tasks.json").read_text(encoding="utf-8-sig"))
            matches = [task for task in plan["tasks"] if task["taskKey"] == key]
        except (OSError, ValueError, KeyError) as error:
            raise TaskError("Cannot read planning/tasks.json") from error
        if plan.get("project") != self.project or len(matches) != 1:
            raise TaskError("Task is absent from the local project plan")
        return matches[0]

    def ready(self) -> dict:
        response = self.api.call("ready", project=self.project)
        available, blocked = [], []
        for task in response.get("tasks", []):
            key = task["taskKey"]
            branch = "codex/" + key.lower()
            reasons = []
            if self.local_path(key).exists():
                reasons.append("local_claim")
            if (self.root / ".worktrees" / key.lower()).exists():
                reasons.append("local_worktree")
            if git(self.root, "rev-parse", "--verify", "refs/heads/" + branch, check=False):
                reasons.append("local_branch")
            if reasons and self.local_path(key).exists() and (self.root / ".worktrees" / key.lower()).is_dir():
                worktree = self.root / ".worktrees" / key.lower()
                try:
                    record = self.read_claim(key, allow_released=True)
                    if (record.get("status") == "released" and not record.get("claimToken")
                            and record.get("project") == self.project and record.get("branch") == branch
                            and Path(record.get("worktree", "")).resolve() == worktree.resolve()
                            and scope_signature(record.get("scope", [])) == scope_signature(task.get("scope", []))
                            and sorted(record.get("dependsOn", [])) == sorted(task.get("dependsOn", []))
                            and git(worktree, "branch", "--show-current") == branch
                            and not git(worktree, "status", "--porcelain=v1", "--untracked-files=all")
                            and git(worktree, "rev-parse", "HEAD^{commit}") == record.get("baseCommit")):
                        reasons = []
                except (OSError, TaskError, ValueError):
                    pass
            if reasons:
                blocked.append({"taskKey": key, "localBlockers": reasons})
            else:
                available.append(task)
        return {"tasks": available, "blockedLocal": blocked}

    def validate_plan(self, key: str, plan: dict, task: dict) -> None:
        if task.get("project") != self.project or task.get("taskKey") != key:
            raise TaskError("Local and queue project/task identity differ")
        if scope_signature(plan["scope"]) != scope_signature(task.get("scope", [])):
            raise TaskError("Local and queue ownership scopes differ; update the plan before claiming")
        if sorted(plan.get("dependsOn", [])) != sorted(task.get("dependsOn", [])):
            raise TaskError("Local and queue dependencies differ; update the plan before claiming")

    def integration_base(self) -> str:
        if git(self.root, "branch", "--show-current") != "main":
            raise TaskError("The integration checkout must be on main")
        if git(self.root, "status", "--porcelain=v1", "--untracked-files=all"):
            raise TaskError("The integration checkout must be clean before claiming")
        return git(self.root, "rev-parse", "refs/heads/main^{commit}")

    def dependency_commits(self, task: dict, tasks: list[dict], base: str) -> dict[str, str]:
        commits = {}
        for dependency in task.get("dependsOn", []):
            previous = self.task(dependency, tasks)
            if previous.get("status") != "done":
                raise TaskError("Dependency is not done: " + dependency)
            merged = previous.get("mergeCommit", "")
            if not isinstance(merged, str) or not re.fullmatch(r"[a-f0-9]{40}|[a-f0-9]{64}", merged):
                raise TaskError("Dependency has no full mergeCommit SHA: " + dependency)
            try:
                git(self.root, "cat-file", "-e", merged + "^{commit}")
                git(self.root, "merge-base", "--is-ancestor", merged, base)
            except TaskError:
                raise TaskError("Dependency mergeCommit is absent from the selected main base: " + dependency +
                                "; synchronize and verify main before claiming") from None
            commits[dependency] = merged
        return commits

    def claim(self, key: str, worker: str) -> dict:
        key_name(key)
        if not worker.strip():
            raise TaskError("A nonempty worker/chat identifier is required")
        plan = self.plan(key)
        tasks = self.api.call("list").get("tasks", [])
        task = self.task(key, tasks)
        self.validate_plan(key, plan, task)
        branch = "codex/" + key.lower()
        git(self.root, "check-ref-format", "--branch", branch)
        worktree = self.root / ".worktrees" / key.lower()
        if self.local_path(key).exists() or worktree.exists():
            if not self.local_path(key).exists() or not worktree.is_dir():
                raise TaskError("Local claim or worktree already exists; inspect it, do not overwrite it")
            record = self.read_claim(key, allow_released=True)
            if (record.get("status") != "released" or record.get("claimToken") or task.get("status") != "queue"
                    or record.get("project") != self.project or record.get("branch") != branch
                    or Path(record.get("worktree", "")).resolve() != worktree.resolve()
                    or scope_signature(record.get("scope", [])) != scope_signature(task.get("scope", []))
                    or sorted(record.get("dependsOn", [])) != sorted(task.get("dependsOn", []))):
                raise TaskError("Existing worktree is not safely released; inspect it without overwriting")
            base = self.integration_base()
            head = git(worktree, "rev-parse", "HEAD^{commit}")
            if (git(worktree, "branch", "--show-current") != branch
                    or git(worktree, "status", "--porcelain=v1", "--untracked-files=all")
                    or head != record.get("baseCommit")):
                raise TaskError("Released worktree has preserved changes; inspect it without overwriting")
            dependencies = self.dependency_commits(task, tasks, base)
            self.dependency_commits(task, tasks, head)
            response = self.api.call("claim", {"project": self.project, "taskKey": key, "worker": worker})
            token = response.get("claimToken")
            if not isinstance(token, str) or not token:
                raise TaskError("Claim response has no token; inspect the task before retrying")
            try:
                self.validate_plan(key, plan, response["task"])
                record.update(claimToken=token, worker=worker, dependencyCommits=dependencies,
                              status="progress", revision=response["task"]["revision"])
                self.save_claim(record)
            except (OSError, TaskError) as error:
                try:
                    self.api.call("release", {"project": self.project, "taskKey": key,
                        "claimToken": token, "expectedRevision": response["task"]["revision"],
                        "reason": "Released worktree reclaim failed; inspect preserved local state"})
                except Exception:
                    raise TaskError("Worktree reclaim failed and release could not be confirmed; inspect queue") from None
                raise TaskError("Worktree reclaim failed; preserved worktree was not modified: " + str(error)) from None
            return {"taskKey": key, "status": "progress", "branch": branch, "worktree": str(worktree),
                    "baseCommit": head, "promptPath": plan.get("promptPath", "")}
        if git(self.root, "rev-parse", "--verify", "refs/heads/" + branch, check=False):
            raise TaskError("Task branch already exists; inspect it, do not overwrite it")
        base = self.integration_base()
        dependencies = self.dependency_commits(task, tasks, base)
        response = self.api.call("claim", {"project": self.project, "taskKey": key, "worker": worker})
        token = response.get("claimToken")
        if not isinstance(token, str) or not token:
            raise TaskError("Claim response has no token; inspect the task before retrying")
        record = {"project": self.project, "taskKey": key, "claimToken": token, "worker": worker,
                  "baseCommit": base, "branch": branch, "worktree": str(worktree),
                  "scope": task["scope"], "dependsOn": task.get("dependsOn", []),
                  "dependencyCommits": dependencies, "status": "progress", "revision": response["task"]["revision"]}
        try:
            self.save_claim(record, exclusive=True)
            # The immutable selected base already contains every done dependency.
            # Recheck the server's claimed plan before creating files from that base.
            self.validate_plan(key, plan, response["task"])
            worktree.parent.mkdir(parents=True, exist_ok=True)
            git(self.root, "worktree", "add", "-b", branch, str(worktree), base)
        except (OSError, TaskError) as error:
            # Release only this exact token. Never delete a branch/worktree as rollback.
            try:
                released = self.api.call("release", {"project": self.project, "taskKey": key,
                    "claimToken": token, "expectedRevision": record["revision"],
                    "reason": "Local worktree setup failed; inspect preserved local state"})
                record.pop("claimToken", None)
                record.update(status="released", revision=released["task"]["revision"])
                if self.local_path(key).exists():
                    self.save_claim(record)
            except Exception:
                raise TaskError("Worktree setup failed and automatic release could not be confirmed. Inspect local claim file and queue; no files or branches were removed.") from None
            raise TaskError("Worktree setup failed; claim released. Inspect preserved local files and branch. " + str(error)) from None
        return {"taskKey": key, "status": "progress", "branch": branch, "worktree": str(worktree),
                "baseCommit": base, "promptPath": plan.get("promptPath", "")}

    def owned(self, key: str) -> tuple[dict, dict]:
        record, task = self.read_claim(key), self.task(key)
        if record.get("worker") != task.get("worker"):
            raise TaskError("Queue owner differs from the local claim")
        if scope_signature(record.get("scope", [])) != scope_signature(task.get("scope", [])):
            raise TaskError("Task ownership scope changed")
        if sorted(record.get("dependsOn", [])) != sorted(task.get("dependsOn", [])):
            raise TaskError("Task dependencies changed")
        return record, task

    def owner_action(self, action: str, key: str, **values) -> dict:
        record, task = self.owned(key)
        result = self.api.call(action, {"project": self.project, "taskKey": key,
            "claimToken": record["claimToken"], "expectedRevision": task["revision"], **values})["task"]
        record.update(status=result["status"], revision=result["revision"])
        if action == "release":
            record.pop("claimToken", None)
            record["status"] = "released"
        self.save_claim(record)
        return {"taskKey": key, "status": result["status"], "revision": result["revision"]}

    def submit(self, key: str, summary: str, checks: list[str]) -> dict:
        record, task = self.owned(key)
        if task["status"] != "progress":
            raise TaskError("Only a task in progress can be submitted")
        if not summary.strip() or not checks or any(not item.strip() for item in checks):
            raise TaskError("A summary and at least one actual verification result are required")
        worktree = Path(record["worktree"])
        expected_path = self.root / ".worktrees" / key.lower()
        if worktree.resolve() != expected_path.resolve() or not worktree.is_dir():
            raise TaskError("Claim does not identify the expected worktree")
        if git(worktree, "branch", "--show-current") != record["branch"]:
            raise TaskError("Worktree is on an unexpected branch")
        if git(worktree, "status", "--porcelain=v1", "--untracked-files=all"):
            raise TaskError("Commit changes and remove unintended untracked files before submitting")
        git(worktree, "merge-base", "--is-ancestor", record["baseCommit"], "HEAD")
        commit = git(worktree, "rev-parse", "HEAD^{commit}")
        if commit == record["baseCommit"]:
            raise TaskError("No task commit exists after the saved base")
        scope = [*record["scope"], *CI_SCOPE_EXCEPTIONS.get(key, ())]
        outside = unapproved_changes(worktree, record["baseCommit"], commit, scope)
        if outside:
            raise TaskError("Changes outside task ownership: " + ", ".join(outside))
        result = self.owner_action("submit", key, commit=commit, branch=record["branch"], summary=summary, checks=checks)
        result["commit"] = commit
        return result

    def release(self, key: str, reason: str) -> dict:
        if not reason.strip():
            raise TaskError("Release reason is required")
        return self.owner_action("release", key, reason=reason)

    def resume(self, key: str, reason: str) -> dict:
        if not reason.strip():
            raise TaskError("Review feedback is required")
        return self.owner_action("resume", key, reason=reason)

    def mark_merged(self, key: str) -> dict:
        task = self.task(key)
        if task["status"] != "review":
            raise TaskError("Task must be in review")
        commit = task.get("result", {}).get("commit", "")
        if not re.fullmatch(r"[a-f0-9]{40}|[a-f0-9]{64}", commit):
            raise TaskError("Task has no full reviewed commit SHA")
        if git(self.root, "branch", "--show-current") != "main":
            raise TaskError("The integration checkout must be on main")
        if git(self.root, "status", "--porcelain=v1", "--untracked-files=all"):
            raise TaskError("The integration checkout must be clean")
        main = git(self.root, "rev-parse", "refs/heads/main^{commit}")
        git(self.root, "cat-file", "-e", commit + "^{commit}")
        try:
            git(self.root, "merge-base", "--is-ancestor", commit, main)
        except TaskError:
            raise TaskError("Reviewed commit is not an ancestor of main; merge and verify it first") from None
        result = self.api.call("merge", {"project": self.project, "taskKey": key,
            "expectedRevision": task["revision"], "mergeCommit": main})["task"]
        path = self.local_path(key)
        if path.exists():
            record = json.loads(path.read_text(encoding="utf-8"))
            record.pop("claimToken", None)
            record.update(status="done", revision=result["revision"], mergeCommit=main)
            self.save_claim(record)
        return {"taskKey": key, "status": "done", "mergeCommit": main}


def parser() -> argparse.ArgumentParser:
    result = argparse.ArgumentParser(description=__doc__)
    result.add_argument("--root", type=Path, default=Path(__file__).resolve().parents[1])
    result.add_argument("--api", default="http://tasks.local/api.php")
    result.add_argument("--project", default="souzpir")
    commands = result.add_subparsers(dest="command", required=True)
    commands.add_parser("ready", help="Read tasks currently available to claim")
    claim = commands.add_parser("claim", help="Atomically claim a task and create its isolated worktree")
    claim.add_argument("task_key"); claim.add_argument("--worker", required=True)
    submit = commands.add_parser("submit", help="Validate clean scoped commits and send to review")
    submit.add_argument("task_key"); submit.add_argument("--summary", required=True)
    submit.add_argument("--check", action="append", required=True, help="Actual verification result; repeat as needed")
    for name, help_text in [("release", "Release an uncompleted task, preserving local work"),
                            ("resume", "Resume your task after review feedback")]:
        action = commands.add_parser(name, help=help_text)
        action.add_argument("task_key"); action.add_argument("--reason", required=True)
    merged = commands.add_parser("mark-merged", help="Mark reviewed task done only after its commit is on main")
    merged.add_argument("task_key")
    return result


def main(argv: list[str] | None = None) -> int:
    args = parser().parse_args(argv)
    try:
        coordinator = Coordinator(args.root, Api(args.api), args.project)
        if args.command == "ready":
            output = coordinator.ready()
        elif args.command == "claim":
            output = coordinator.claim(args.task_key, args.worker)
        elif args.command == "submit":
            output = coordinator.submit(args.task_key, args.summary, args.check)
        elif args.command in ("release", "resume"):
            output = getattr(coordinator, args.command)(args.task_key, args.reason)
        else:
            output = coordinator.mark_merged(args.task_key)
        print(json.dumps(output, ensure_ascii=True, indent=2))
        return 0
    except (TaskError, OSError, ValueError) as error:
        print("ERROR: " + str(error), file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
