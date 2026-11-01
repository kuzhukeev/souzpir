"""Isolated Git tests; no HTTP calls or mutations of the real queue."""
import copy
import importlib.util
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

MODULE = Path(__file__).resolve().parents[2] / "scripts" / "task.py"
spec = importlib.util.spec_from_file_location("task_cli", MODULE)
task_cli = importlib.util.module_from_spec(spec)
spec.loader.exec_module(task_cli)


class FakeApi:
    def __init__(self):
        self.task = {"project": "souzpir", "taskKey": "SP-001", "status": "queue",
                     "revision": 1, "scope": ["app/Feature/"], "dependsOn": [], "ready": True}
        self.calls = []
        self.dependencies = []

    def call(self, action, payload=None, **query):
        self.calls.append(action)
        if action == "list":
            return {"tasks": copy.deepcopy([self.task, *self.dependencies])}
        if action == "ready":
            return {"tasks": [copy.deepcopy(self.task)] if self.task["ready"] else []}
        if action == "claim":
            if self.task["status"] != "queue" or not self.task["ready"]:
                raise task_cli.TaskError("Not ready")
            self.task.update(status="progress", worker=payload["worker"], ready=False)
            self.task["revision"] += 1
            return {"task": copy.deepcopy(self.task), "claimToken": "private-test-token"}
        if payload["expectedRevision"] != self.task["revision"]:
            raise task_cli.TaskError("Revision conflict")
        if action != "merge" and payload["claimToken"] != "private-test-token":
            raise task_cli.TaskError("Owner mismatch")
        if action == "submit":
            self.task.update(status="review", result={k: payload[k] for k in ("commit", "branch", "summary", "checks")})
        elif action == "release":
            self.task.update(status="queue", ready=True)
        elif action == "resume":
            self.task.update(status="progress")
        elif action == "merge":
            self.task.update(status="done", mergeCommit=payload["mergeCommit"])
        else:
            raise AssertionError(action)
        self.task["revision"] += 1
        return {"task": copy.deepcopy(self.task)}


class TaskCliTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix="souzpir-cli-test-")
        self.root = Path(self.tmp.name)
        task_cli.git(self.root, "init", "-b", "main")
        task_cli.git(self.root, "config", "user.name", "Isolated Test")
        task_cli.git(self.root, "config", "user.email", "test@example.invalid")
        task_cli.git(self.root, "config", "commit.gpgsign", "false")
        (self.root / ".gitignore").write_text(".local/\n.worktrees/\n", encoding="utf-8")
        (self.root / "planning").mkdir()
        plan = {"project": "souzpir", "tasks": [{"taskKey": "SP-001", "scope": ["app/Feature/"], "promptPath": "planning/prompts/SP-001.md"}]}
        (self.root / "planning" / "tasks.json").write_text(json.dumps(plan), encoding="utf-8")
        task_cli.git(self.root, "add", ".")
        task_cli.git(self.root, "commit", "-m", "Test baseline")
        self.api = FakeApi()
        self.client = task_cli.Coordinator(self.root, self.api)

    def tearDown(self):
        # TemporaryDirectory removes only the verified private test root it created.
        self.tmp.cleanup()

    def claim(self):
        result = self.client.claim("SP-001", "test-chat")
        return Path(result["worktree"]), result

    def commit(self, worktree, path="app/Feature/file.php"):
        target = worktree / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text("sample\n", encoding="utf-8")
        task_cli.git(worktree, "add", "--", path)
        task_cli.git(worktree, "commit", "-m", "Task implementation")

    def dependency(self, commit, status="done"):
        self.api.task["dependsOn"] = ["SP-000"]
        self.api.dependencies = [{"project": "souzpir", "taskKey": "SP-000", "status": status, "mergeCommit": commit}]
        path = self.root / "planning" / "tasks.json"
        plan = json.loads(path.read_text(encoding="utf-8"))
        plan["tasks"][0]["dependsOn"] = ["SP-000"]
        path.write_text(json.dumps(plan), encoding="utf-8")
        task_cli.git(self.root, "add", "planning/tasks.json")
        task_cli.git(self.root, "commit", "-m", "Record dependency")

    def test_ready_does_not_mutate(self):
        self.assertEqual(len(self.client.ready()["tasks"]), 1)
        self.assertEqual(self.api.calls, ["ready"])

    def test_ready_excludes_released_task_with_local_claim_or_worktree(self):
        worktree, _ = self.claim()
        self.client.release("SP-001", "Stopped")
        result = self.client.ready()
        self.assertEqual(result["tasks"], [])
        self.assertEqual(result["blockedLocal"][0]["taskKey"], "SP-001")
        self.assertEqual(set(result["blockedLocal"][0]["localBlockers"]),
                         {"local_claim", "local_worktree", "local_branch"})
        self.assertTrue(worktree.exists())

    def test_ready_excludes_task_with_leftover_branch_only(self):
        task_cli.git(self.root, "branch", "codex/sp-001")
        result = self.client.ready()
        self.assertEqual(result["tasks"], [])
        self.assertEqual(result["blockedLocal"][0]["localBlockers"], ["local_branch"])

    def test_claim_stores_token_only_in_local_record(self):
        worktree, result = self.claim()
        self.assertEqual(task_cli.git(worktree, "branch", "--show-current"), "codex/sp-001")
        self.assertNotIn("private-test-token", json.dumps(result))
        self.assertEqual(self.client.read_claim("SP-001")["claimToken"], "private-test-token")
        self.assertEqual(task_cli.git(self.root, "status", "--porcelain"), "")

    def test_existing_branch_is_not_claimed(self):
        task_cli.git(self.root, "branch", "codex/sp-001")
        with self.assertRaisesRegex(task_cli.TaskError, "already exists"):
            self.claim()
        self.assertNotIn("claim", self.api.calls)

    def test_unready_task_creates_no_worktree(self):
        self.api.task["ready"] = False
        with self.assertRaisesRegex(task_cli.TaskError, "Not ready"):
            self.claim()
        self.assertFalse((self.root / ".worktrees" / "sp-001").exists())

    def test_worktree_failure_releases_claim_without_deleting(self):
        original = task_cli.git
        def fail_worktree(root, *args, **kwargs):
            if args[:2] == ("worktree", "add"):
                raise task_cli.TaskError("simulated failure")
            return original(root, *args, **kwargs)
        with patch.object(task_cli, "git", side_effect=fail_worktree):
            with self.assertRaisesRegex(task_cli.TaskError, "claim released"):
                self.claim()
        self.assertEqual(self.api.task["status"], "queue")
        record = json.loads(self.client.local_path("SP-001").read_text(encoding="utf-8"))
        self.assertNotIn("claimToken", record)

    def test_submit_rejects_dirty_and_out_of_scope(self):
        worktree, _ = self.claim()
        (worktree / "stray.txt").write_text("uncommitted", encoding="utf-8")
        with self.assertRaisesRegex(task_cli.TaskError, "Commit changes"):
            self.client.submit("SP-001", "Changes", ["Tests passed"])
        task_cli.git(worktree, "add", "stray.txt")
        task_cli.git(worktree, "commit", "-m", "Outside scope")
        with self.assertRaisesRegex(task_cli.TaskError, "outside task ownership"):
            self.client.submit("SP-001", "Changes", ["Tests passed"])
        self.assertNotIn("submit", self.api.calls)

    def test_submit_allows_unchanged_files_from_merged_main(self):
        worktree, _ = self.claim()
        self.commit(worktree)
        self.commit(self.root, 'app/Common/dependency.php')
        task_cli.git(worktree, 'merge', '--no-ff', 'main', '-m', 'Bring approved dependency')
        self.assertEqual(self.client.submit('SP-001', 'Changes', ['Tests passed'])['status'], 'review')

    def test_submit_allows_fast_forwarded_main_before_task_commit(self):
        worktree, _ = self.claim()
        self.commit(self.root, 'app/Common/dependency.php')
        task_cli.git(worktree, 'merge', '--ff-only', 'main')
        self.commit(worktree)
        self.assertEqual(self.client.submit('SP-001', 'Changes', ['Tests passed'])['status'], 'review')

    def test_submit_rejects_modified_outside_scope_after_fast_forward(self):
        worktree, _ = self.claim()
        self.commit(self.root, 'app/Common/dependency.php')
        task_cli.git(worktree, 'merge', '--ff-only', 'main')
        (worktree / 'app/Common/dependency.php').write_text('unauthorized\n', encoding='utf-8')
        task_cli.git(worktree, 'add', 'app/Common/dependency.php')
        task_cli.git(worktree, 'commit', '-m', 'Unauthorized modification')
        with self.assertRaisesRegex(task_cli.TaskError, 'outside task ownership'):
            self.client.submit('SP-001', 'Changes', ['Tests passed'])

    def test_submit_rejects_copy_of_main_file_without_its_ancestry(self):
        worktree, _ = self.claim()
        self.commit(self.root, 'app/Common/dependency.php')
        task_cli.git(worktree, 'checkout', 'main', '--', 'app/Common/dependency.php')
        task_cli.git(worktree, 'commit', '-m', 'Copy unmerged file')
        with self.assertRaisesRegex(task_cli.TaskError, 'outside task ownership'):
            self.client.submit('SP-001', 'Changes', ['Tests passed'])

    def test_submit_rejects_outside_scope_modified_after_main_merge(self):
        worktree, _ = self.claim()
        self.commit(worktree)
        self.commit(self.root, 'app/Common/dependency.php')
        task_cli.git(worktree, 'merge', '--no-ff', 'main', '-m', 'Bring approved dependency')
        (worktree / 'app/Common/dependency.php').write_text('unauthorized\n', encoding='utf-8')
        task_cli.git(worktree, 'add', 'app/Common/dependency.php')
        task_cli.git(worktree, 'commit', '-m', 'Unauthorized change')
        with self.assertRaisesRegex(task_cli.TaskError, 'outside task ownership'):
            self.client.submit('SP-001', 'Changes', ['Tests passed'])

    def test_submit_rejects_unmerged_dependency_from_other_branch(self):
        worktree, _ = self.claim()
        self.commit(worktree)
        task_cli.git(self.root, 'checkout', '-b', 'unapproved')
        self.commit(self.root, 'app/Common/dependency.php')
        task_cli.git(self.root, 'checkout', 'main')
        task_cli.git(worktree, 'merge', '--no-ff', 'unapproved', '-m', 'Bring unapproved code')
        with self.assertRaisesRegex(task_cli.TaskError, 'outside task ownership'):
            self.client.submit('SP-001', 'Changes', ['Tests passed'])

    def test_submit_review_then_merge_gate(self):
        worktree, _ = self.claim()
        self.commit(worktree)
        result = self.client.submit("SP-001", "Scoped implementation", ["Isolated test passed"])
        self.assertEqual(result["status"], "review")
        with self.assertRaisesRegex(task_cli.TaskError, "not an ancestor"):
            self.client.mark_merged("SP-001")
        self.assertNotIn("merge", self.api.calls)
        task_cli.git(self.root, "merge", "--ff-only", "codex/sp-001")
        result = self.client.mark_merged("SP-001")
        self.assertEqual(result["status"], "done")
        self.assertEqual(result["mergeCommit"], task_cli.git(self.root, "rev-parse", "main"))
        record = json.loads(self.client.local_path("SP-001").read_text(encoding="utf-8"))
        self.assertNotIn("claimToken", record)

    def test_review_can_resume_with_same_owner(self):
        worktree, _ = self.claim()
        self.commit(worktree)
        self.client.submit("SP-001", "Changes", ["Tests passed"])
        self.assertEqual(self.client.resume("SP-001", "Fix review finding")["status"], "progress")

    def test_claim_can_be_read_from_child_worktree(self):
        worktree, _ = self.claim()
        client = task_cli.Coordinator(worktree, self.api)
        self.assertEqual(client.root, self.root.resolve())
        self.assertEqual(client.read_claim("SP-001")["worker"], "test-chat")

    def test_scope_prefix_does_not_allow_sibling_or_private_paths(self):
        self.assertTrue(task_cli.scope_allows("app/Feature/a.php", ["app/Feature/"]))
        self.assertTrue(task_cli.scope_allows("App/FEATURE/a.php", ["app/Feature/"]))
        self.assertFalse(task_cli.scope_allows("app/FeatureExtra/a.php", ["app/Feature/"]))
        self.assertFalse(task_cli.scope_allows(".local/claims/task.json", ["/"]))
        self.assertFalse(task_cli.scope_allows(".LOCAL/claims/task.json", ["/"]))
        self.assertFalse(task_cli.scope_allows("app/../outside.php", ["/"]))

    def test_submit_allows_default_generated_contracts_only(self):
        worktree, _ = self.claim()
        for name in ("data.ts", "translations.ts", "permissions.ts", "routes.d.ts"):
            self.commit(worktree, "resources/js/types/generated/" + name)
        self.assertEqual(self.client.submit("SP-001", "Generated contracts", ["Tests passed"])["status"], "review")
        self.assertFalse(task_cli.scope_allows("resources/js/types/generated/other.ts", ["app/Feature/"]))
        self.assertFalse(task_cli.scope_allows("resources/js/types/generated/../secret.ts", ["/"]))

    def test_api_refuses_remote_host_before_network(self):
        with self.assertRaisesRegex(task_cli.TaskError, "local task queue"):
            task_cli.Api("https://example.com/api.php")

    def test_claim_rejects_done_dependency_with_missing_sha_before_mutation(self):
        self.dependency("f" * 40)
        with self.assertRaisesRegex(task_cli.TaskError, "absent from the selected main base"):
            self.claim()
        self.assertNotIn("claim", self.api.calls)
        self.assertFalse(self.client.local_path("SP-001").exists())

    def test_claim_rejects_done_dependency_commit_not_merged_into_main(self):
        task_cli.git(self.root, "checkout", "-b", "dependency-unmerged")
        self.commit(self.root, "dependency.txt")
        unmerged = task_cli.git(self.root, "rev-parse", "HEAD")
        task_cli.git(self.root, "checkout", "main")
        self.dependency(unmerged)
        with self.assertRaisesRegex(task_cli.TaskError, "absent from the selected main base"):
            self.claim()
        self.assertNotIn("claim", self.api.calls)

    def test_claim_records_verified_dependency_commits(self):
        merged = task_cli.git(self.root, "rev-parse", "main")
        self.dependency(merged)
        worktree, result = self.claim()
        record = self.client.read_claim("SP-001")
        self.assertEqual(record["dependencyCommits"], {"SP-000": merged})
        self.assertEqual(task_cli.git(worktree, "rev-parse", "HEAD"), result["baseCommit"])

    def test_claim_rejects_non_done_dependency(self):
        self.dependency(task_cli.git(self.root, "rev-parse", "main"), status="review")
        with self.assertRaisesRegex(task_cli.TaskError, "Dependency is not done"):
            self.claim()
        self.assertNotIn("claim", self.api.calls)

    def test_claim_rejects_dependency_plan_mismatch(self):
        self.api.task["dependsOn"] = ["SP-000"]
        with self.assertRaisesRegex(task_cli.TaskError, "dependencies differ"):
            self.claim()
        self.assertNotIn("claim", self.api.calls)

    def test_claim_requires_clean_main_checkout(self):
        (self.root / "untracked.txt").write_text("dirty", encoding="utf-8")
        with self.assertRaisesRegex(task_cli.TaskError, "must be clean"):
            self.claim()
        self.assertNotIn("claim", self.api.calls)

    def test_claim_requires_integration_checkout_on_main(self):
        task_cli.git(self.root, "checkout", "-b", "unrelated")
        with self.assertRaisesRegex(task_cli.TaskError, "must be on main"):
            self.claim()
        self.assertNotIn("claim", self.api.calls)


if __name__ == "__main__":
    unittest.main()
