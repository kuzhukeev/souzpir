"""Portable harness identity and synthetic transport normalization, no network."""
import copy
import hashlib
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import capture
import verify
from capture_broker import BrokerError


class PortableHarnessTests(unittest.TestCase):
    def setUp(self):
        parent = capture.LOCAL / "integration-tests"
        parent.mkdir(parents=True, exist_ok=True)
        temporary = tempfile.TemporaryDirectory(dir=parent)
        self.directory = Path(temporary.name)
        self.assertTrue(self.directory.resolve().is_relative_to(capture.LOCAL.resolve()))
        self.addCleanup(temporary.cleanup)

    def test_new_fingerprints_are_explicit_utf8_lf_and_legacy_values_stay_raw(self):
        path = self.directory / "source.php"
        crlf = "<?php\r\n// Русский комментарий\r\n".encode("utf-8")
        lf = crlf.replace(b"\r\n", b"\n")
        raw_hash = hashlib.sha256(crlf).hexdigest()
        path.write_bytes(crlf)
        portable_hash = verify.harness_source_sha(path, capture.HARNESS_HASHING)
        path.write_bytes(lf)
        self.assertEqual(verify.harness_source_sha(path, capture.HARNESS_HASHING), portable_hash)
        self.assertTrue(capture.source_hash_matches(path, "sha256-raw-v1", raw_hash))
        historical = {"tools/baseline/example.php": raw_hash}
        before = copy.deepcopy(historical)
        self.assertEqual(verify.parse_harness(historical), ("sha256-raw-v1", historical))
        self.assertEqual(historical, before)
        new = {"schema_version": 2, "hashing": capture.HARNESS_HASHING,
               "files": {"tools/baseline/example.php": portable_hash}}
        self.assertEqual(verify.parse_harness(new), (capture.HARNESS_HASHING, new["files"]))
        self.assertNotEqual(raw_hash, portable_hash)

    def test_semantic_edits_are_not_accepted_as_newline_equivalence(self):
        path = self.directory / "source.py"
        original = b"answer = 1\r\n"
        expected = hashlib.sha256(original).hexdigest()
        path.write_bytes(b"answer = 2\n")
        self.assertFalse(capture.source_hash_matches(path, "sha256-raw-v1", expected))
        self.assertNotEqual(verify.harness_source_sha(path, capture.HARNESS_HASHING),
                            hashlib.sha256(original.replace(b"\r\n", b"\n")).hexdigest())

    def test_portable_mode_rejects_non_utf8_source(self):
        path = self.directory / "binary.py"
        path.write_bytes(b"\xff\xfe")
        with self.assertRaises((ValueError, UnicodeError)):
            verify.harness_source_sha(path, capture.HARNESS_HASHING)

    def test_normalizer_migration_is_exact_source_and_reviewed_target_only(self):
        relative = "tools/baseline/ContactNormalizer.php"
        actual = verify.harness_source_sha(capture.ROOT / relative, capture.HARNESS_HASHING)
        self.assertEqual(actual, capture.NORMALIZER_REVIEWED_HASH)
        for historical in capture.NORMALIZER_OLD_HASHES:
            self.assertTrue(capture.reviewed_normalizer_transition(relative, historical))
            self.assertFalse(capture.reviewed_normalizer_transition("tools/baseline/capture-case.php", historical))
        self.assertFalse(capture.reviewed_normalizer_transition(relative, "0" * 64))
        with patch.object(verify, "harness_source_sha", return_value="f" * 64):
            for historical in capture.NORMALIZER_OLD_HASHES:
                self.assertFalse(capture.reviewed_normalizer_transition(relative, historical))

    def test_synthetic_wire_deduplicates_without_mutating_donor_request(self):
        tool = {"type": "function", "function": {"name": "send_price", "description": "Send once",
                "parameters": {"type": "object", "properties": {}}}}
        message = {"body": {"model": "anthropic/claude-sonnet-5.5", "tools": [tool, copy.deepcopy(tool)]},
                   "request": {"options": {"component": "responder", "tools": [tool, copy.deepcopy(tool)]}}}
        before = copy.deepcopy(message)
        reply = capture.FakeBroker()(message)
        self.assertEqual(message, before)
        self.assertEqual(reply["request_body"]["tools"], [tool])
        self.assertEqual(len(message["request"]["options"]["tools"]), 2)

    def test_conflicting_same_name_definitions_fail_even_in_synthetic_transport(self):
        one = {"type": "function", "function": {"name": "send_price", "description": "One"}}
        two = copy.deepcopy(one)
        two["function"]["description"] = "Two"
        message = {"body": {"model": "anthropic/claude-sonnet-5.5", "tools": [one, two]},
                   "request": {"options": {"component": "responder"}}}
        with self.assertRaises(BrokerError):
            capture.FakeBroker()(message)


if __name__ == "__main__":
    unittest.main()
