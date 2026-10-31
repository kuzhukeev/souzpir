"""Operator-boundary regressions. Zero network and no real credentials."""
from __future__ import annotations

import argparse
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import capture


class OperatorBoundaryTests(unittest.TestCase):
    def args(self, **overrides):
        values = {
            "prototype": capture.ROOT / ".local/prototype",
            "php": Path("C:/OSPanel/modules/PHP-8.4/php.exe"),
            "test_case": None,
            "ledger": capture.LOCAL / "budget.sqlite",
            "allow_live_openrouter": True,
            "acknowledge_pseudonymized_inputs": True,
            "preflight_only": False,
            "output": capture.LOCAL / "unittest-never-created",
            "key_file": capture.ROOT / ".local/secrets/not-read",
            "catalog": capture.BASELINE / "manifest.json",
        }
        values.update(overrides)
        return argparse.Namespace(**values)

    def test_alternate_ledger_is_refused_before_child_or_secret_read(self):
        with patch.object(capture, "run_child") as child:
            with self.assertRaisesRegex(capture.CaptureError, "canonical_task_budget_required"):
                capture.execute(self.args(ledger=capture.LOCAL / "new-budget.sqlite"))
            child.assert_not_called()

    def test_foreign_prototype_is_refused_before_child(self):
        with patch.object(capture, "run_child") as child:
            with self.assertRaisesRegex(capture.CaptureError, "prototype_must_be_task_local"):
                capture.execute(self.args(prototype=capture.ROOT))
            child.assert_not_called()

    def test_child_environment_does_not_inherit_secrets_or_proxy(self):
        with patch.dict(capture.os.environ, {
            "OPENROUTER_API_KEY": "not-a-real-key", "ANTHROPIC_API_KEY": "not-a-real-key",
            "HTTP_PROXY": "http://127.0.0.1:9999", "PHPRC": "outside-php.ini",
            "DATABASE_URL": "not-a-real-database",
        }):
            env = capture.child_environment(capture.LOCAL / "tmp")
        self.assertNotIn("OPENROUTER_API_KEY", env)
        self.assertNotIn("ANTHROPIC_API_KEY", env)
        self.assertNotIn("HTTP_PROXY", env)
        self.assertNotIn("PHPRC", env)
        self.assertNotIn("DATABASE_URL", env)
        self.assertEqual(env["LLM_DRIVER"], "fake")
        self.assertEqual(env["MAIL_MAILER"], "array")

    def test_child_cannot_spawn_network_helpers(self):
        disabled = set(capture.DISABLED.split(","))
        self.assertTrue({"curl_exec", "stream_socket_client", "mail", "socket_connect",
                         "exec", "shell_exec", "proc_open", "popen"}.issubset(disabled))

    def test_actual_php_child_has_cli_opcache_disabled(self):
        original = capture.subprocess.Popen
        probes = []
        def launch(command, **kwargs):
            self.assertIn("opcache.enable_cli=0", command)
            script_index = command.index(str(capture.ROOT / "tools/baseline/capture-case.php"))
            probe_code = "echo json_encode(['enabled'=>(bool) ini_get('opcache.enable_cli'), 'active'=>function_exists('opcache_get_status') && opcache_get_status(false)!==false]);"
            probe = original(command[:script_index] + ["-r", probe_code],
                cwd=kwargs["cwd"], env=kwargs["env"], shell=False,
                stdout=capture.subprocess.PIPE, stderr=capture.subprocess.PIPE)
            stdout, stderr = probe.communicate(timeout=30)
            self.assertEqual(probe.returncode, 0)
            self.assertEqual(stderr, b"")
            probes.append(capture.json.loads(stdout))
            return original(command, **kwargs)
        with patch.object(capture.subprocess, "Popen", side_effect=launch):
            result = capture.run_child(Path("C:/OSPanel/modules/PHP-8.4/php.exe"),
                capture.ROOT / ".local/prototype", ["--action=describe"])
        self.assertEqual(probes, [{"enabled": False, "active": False}])
        self.assertEqual(result["config"]["network_calls_in_child"], 0)
        self.assertEqual(len(result["cases"]), 41)

    def test_direct_child_refuses_output_outside_capture_area(self):
        parent = capture.ROOT / ".local"
        with tempfile.TemporaryDirectory(dir=parent) as temporary:
            output = Path(temporary)
            with self.assertRaisesRegex(capture.CaptureError, "offline_case_failed"):
                capture.run_child(Path("C:/OSPanel/modules/PHP-8.4/php.exe"),
                    capture.ROOT / ".local/prototype",
                    ["--case=manual.opt_out", "--mode=test", "--run-id=path_guard",
                     f"--output={output}"], capture.FakeBroker())
            self.assertEqual(list(output.iterdir()), [])

    def test_preflight_failure_closes_broker(self):
        import capture_broker
        definitions = capture.json.loads((capture.BASELINE / "manifest.json").read_text(encoding="utf-8"))["cases"]
        described = {"cases": [{"id": item["id"]} for item in definitions],
                     "config": {"agent": {"models": {"simulator": "test/model"}}}}
        # Existing harmless public catalog file stands in for resolve(strict=True).
        args = self.args(key_file=capture.BASELINE / "manifest.json")
        with patch.object(capture, "run_child", return_value=described), \
             patch.object(capture_broker, "CaptureBroker") as klass, \
             patch.object(capture, "validate_fresh_ledger"):
            klass.return_value.preflight.side_effect = capture.CaptureError("test_preflight_refusal")
            with self.assertRaisesRegex(capture.CaptureError, "test_preflight_refusal"):
                capture.execute(args)
            klass.return_value.close.assert_called_once()
            klass.return_value.call.assert_not_called()

    def test_test_mode_never_instantiates_live_broker(self):
        import capture_broker
        definitions = capture.json.loads((capture.BASELINE / "manifest.json").read_text(encoding="utf-8"))["cases"]
        described = {"cases": [{"id": item["id"]} for item in definitions]}
        with patch.object(capture, "run_child", return_value=described), \
             patch.object(capture_broker, "CaptureBroker") as klass:
            result = capture.execute(self.args(test_case="all", allow_live_openrouter=False, preflight_only=True))
        self.assertEqual(result["real_llm_calls"], 0)
        self.assertEqual(result["cases"], 41)
        klass.assert_not_called()


if __name__ == "__main__":
    unittest.main()
