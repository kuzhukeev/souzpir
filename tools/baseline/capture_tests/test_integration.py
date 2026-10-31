"""Real broker + fake HTTP + real offline PHP IPC; never live/recorded provenance."""
from __future__ import annotations

import copy
import json
from pathlib import Path
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import capture
from capture_broker import BASE, CaptureBroker, MODELS

FAKE_KEY = "sk-or-v1-offline_fake_not_a_real_secret_00000"


class FakeProductionTransport:
    def __init__(self):
        self.message = None
        self.fake = capture.FakeBroker()
        self.posts = []
        self.receipts = {}

    def request(self, method, url, headers, body=None):
        if url == BASE + "/key":
            return {"data": {"limit": None, "usage": 0}}
        if url.endswith("/endpoints"):
            model = url.removeprefix(BASE + "/models/").removesuffix("/endpoints")
            is_google = model.startswith("google/")
            return {"data": {"id": model, "endpoints": [{
                "tag": "google-ai-studio" if is_google else "anthropic",
                "provider_name": "Google" if is_google else "Anthropic",
                "context_length": 100000, "max_completion_tokens": 5000,
                "supported_parameters": ["max_tokens", "tools", "response_format"],
                "pricing": {"prompt": "0.000001", "completion": "0.000002"},
            }]}}
        if "/generation?id=" in url:
            identity = url.split("=", 1)[1]
            return {"data": {"id": identity, "is_byok": False,
                             "total_cost": self.receipts[identity]["usage"]["cost"]}}
        if method == "POST" and url == BASE + "/chat/completions":
            self.posts.append(copy.deepcopy(body))
            response = self.fake(self.message)["data"]
            response["id"] = "gen-fake-" + str(len(self.posts))
            response["provider"] = "Google" if body["model"].startswith("google/") else "Anthropic"
            self.receipts[response["id"]] = response
            return response
        raise AssertionError("Unexpected fake transport destination")


class WrappedAliasTransport(FakeProductionTransport):
    def request(self, method, url, headers, body=None):
        result = super().request(method, url, headers, body)
        if method == "POST":
            # The scenario pre-seeds source contacts before any provider call.
            # Reasoning is retained evidence, but not fabricated donor dialogue.
            result["choices"][0]["message"]["reasoning"] = (
                "Known **contact-0001@baseline.invalid**, "
                + chr(96) + "contact-0001@baseline.invalid" + chr(96)
                + ", and 'contact-0001@baseline.invalid'."
            )
        return result


class IpcIntegrationTests(unittest.TestCase):
    def test_broker_actual_wire_records_then_replays_twice(self):
        self.assert_production_roundtrip(FakeProductionTransport())

    def test_issued_alias_wrappers_in_provider_reasoning_record_and_replay(self):
        self.assert_production_roundtrip(WrappedAliasTransport(), 'persona.unknown_item')

    def assert_production_roundtrip(self, transport, case_id='manual.no_repeat_filled_slot'):
        parent = capture.LOCAL / "integration-tests"
        parent.mkdir(parents=True, exist_ok=True)
        with tempfile.TemporaryDirectory(dir=parent) as temporary:
            directory = Path(temporary)
            key = directory / "fake.key"
            key.write_text(FAKE_KEY, encoding="utf-8")
            catalog = directory / "catalog.json"
            catalog.write_text(json.dumps({"data": [{"id": model} for model in MODELS]}), encoding="utf-8")
            output = directory / "synthetic"
            output.mkdir()
            with CaptureBroker(key, catalog, directory / "budget.sqlite", transport=transport) as broker:
                broker.preflight(sorted(MODELS))

                def handler(message):
                    transport.message = message
                    return broker.call(message["body"])

                common = [
                    f"--case={case_id}", f"--output={output}",
                    "--run-id=synthetic_production_wire",
                ]
                php = Path("C:/OSPanel/modules/PHP-8.4/php.exe")
                prototype = capture.ROOT / ".local/prototype"
                recorded = capture.run_child(php, prototype, common + ["--mode=test"], handler)
                after_capture_posts = len(transport.posts)
                self.assertGreaterEqual(after_capture_posts, 2)
                for _ in range(2):
                    replay = capture.run_child(php, prototype, common + ["--mode=replay"])
                    self.assertEqual(recorded["trace"], replay["trace"])
                    self.assertEqual(recorded["trace_sha256"], replay["trace_sha256"])
                    self.assertEqual(recorded["calls"], replay["calls"])
                    self.assertEqual(replay["network_calls"], 0)
                self.assertEqual(after_capture_posts, len(transport.posts))
                self.assertEqual(broker.summary()["held_micros"], 0)
                self.assertGreater(broker.summary()["spent_micros"], 0)
            calls = list((output / "llm" / case_id).glob("*.json"))
            self.assertEqual(len(calls), after_capture_posts)
            for path in calls:
                payload = json.loads(path.read_text(encoding="utf-8"))
                self.assertEqual(payload["provenance"], "synthetic")
                if isinstance(transport, WrappedAliasTransport):
                    reasoning = payload["response"]["raw"]["choices"][0]["message"]["reasoning"]
                    self.assertIn("**contact-0001@baseline.invalid**", reasoning)
                    self.assertIn("'contact-0001@baseline.invalid'", reasoning)
                    self.assertIn(chr(96) + "contact-0001@baseline.invalid" + chr(96), reasoning)
                wire = payload["evidence"]["request_body"]
                self.assertFalse(wire["stream"])
                self.assertEqual(wire["plugins"], [])
                self.assertFalse(wire["provider"]["allow_fallbacks"])
                self.assertIn("max_price", wire["provider"])
                self.assertIn("only", wire["provider"])
                self.assertGreater(payload["evidence"]["budget"]["reserved_micros"], 0)
                self.assertNotIn(FAKE_KEY, path.read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()
