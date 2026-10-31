"""Offline pseudo-child/clock regressions; no process, ledger or network."""
from __future__ import annotations

from contextlib import ExitStack, redirect_stdout
import io
from pathlib import Path
import sys
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import capture


class Pipe:
    def __init__(self):
        self.data = bytearray()
        self.closed = False

    def write(self, value):
        self.data.extend(value)
        return len(value)

    def flush(self):
        pass

    def close(self):
        self.closed = True


class ChildHarness:
    def __init__(self, events):
        self.now = 0.0
        self.events = iter(events)
        self.timeouts = []
        self.process = Mock()
        self.process.stdin = Pipe()
        self.process.stdout = Pipe()
        self.returncode = None
        self.process.poll.side_effect = lambda: self.returncode
        self.process.wait.side_effect = self.wait
        self.process.kill.side_effect = self.kill
        self.lines = Mock()
        self.lines.get.side_effect = self.get

    def wait(self, timeout):
        if self.returncode is None:
            self.returncode = 0
        return self.returncode

    def kill(self):
        self.returncode = -9

    def get(self, timeout):
        self.timeouts.append(timeout)
        delay, message = next(self.events)
        if delay > timeout or message == "timeout":
            self.now += timeout
            raise capture.queue.Empty()
        self.now += delay
        return None if message is None else capture.json.dumps(message).encode() + b"\n"

    def patches(self):
        stack = ExitStack()
        stack.enter_context(patch.object(capture.subprocess, "Popen", return_value=self.process))
        stack.enter_context(patch.object(capture.threading, "Thread"))
        stack.enter_context(patch.object(capture.queue, "Queue", return_value=self.lines))
        stack.enter_context(patch.object(capture.time, "monotonic", side_effect=lambda: self.now))
        stack.enter_context(patch.object(capture, "child_environment", return_value={}))
        stack.enter_context(redirect_stdout(io.StringIO()))
        return stack

    def run(self, handler, prefix=0):
        return capture.run_child(Path("unused.php"), Path("unused-prototype"),
            ["--case=manual.opt_out"], handler, resume_prefix=prefix,
            prefix_provenance="synthetic" if prefix else None)


def call(sequence):
    return {"op": "call", "case_id": "manual.opt_out", "sequence": sequence}


COMPLETE = {"op": "complete", "case_id": "manual.opt_out"}


class ChildDeadlineTests(unittest.TestCase):
    def test_slow_handlers_do_not_consume_child_execution_budget(self):
        child = ChildHarness([(1, call(1)), (1, call(2)), (1, COMPLETE), (0, None)])
        seen = []
        def handler(message):
            child.now += 3601
            seen.append(message["sequence"])
            return {"ok": True, "sequence": message["sequence"]}
        with child.patches():
            self.assertEqual(child.run(handler), COMPLETE)
        self.assertEqual(seen, [1, 2])
        replies = [capture.json.loads(line) for line in child.process.stdin.data.splitlines()]
        self.assertEqual(replies, [{"ok": True, "sequence": 1}, {"ok": True, "sequence": 2}])
        self.assertEqual(child.timeouts, [120, 120, 120, 120])
        child.process.kill.assert_not_called()
        self.assertTrue(child.process.stdin.closed and child.process.stdout.closed)

    def test_child_idle_guard_still_kills_after_slow_handler(self):
        child = ChildHarness([(1, call(1)), (0, "timeout")])
        def handler(message):
            child.now += 7200
            return {"ok": True}
        with child.patches(), self.assertRaisesRegex(capture.CaptureError, "^offline_child_timeout$"):
            child.run(handler)
        self.assertEqual(child.timeouts, [120, 120])
        child.process.kill.assert_called_once()
        self.assertEqual(len(child.process.stdin.data.splitlines()), 1)

    def test_execution_budget_still_expires_before_next_handler(self):
        child = ChildHarness([(4, call(1)), (2, call(2))])
        handler = Mock(side_effect=lambda message: self.slow_reply(child))
        with child.patches(), patch.object(capture, "CHILD_EXECUTION_SECONDS", 5), \
             self.assertRaisesRegex(capture.CaptureError, "^offline_child_execution_timeout$"):
            child.run(handler)
        handler.assert_called_once()
        self.assertEqual(child.timeouts, [5, 120])
        child.process.kill.assert_called_once()

    @staticmethod
    def slow_reply(child):
        child.now += 7200
        return {"ok": True}

    def test_near_deadline_reply_write_and_fsync_finish_before_stop(self):
        child = ChildHarness([(4.9, call(1)), (2, COMPLETE), (0, None)])
        original_write = child.process.stdin.write
        def slow_write(payload):
            child.now += 1  # Encoding/write can consume the last active fraction.
            return original_write(payload)
        child.process.stdin.write = slow_write
        handler = Mock(side_effect=lambda message: self.slow_reply(child))
        with child.patches(), patch.object(capture, "CHILD_EXECUTION_SECONDS", 5):
            self.assertEqual(child.run(handler), COMPLETE)
        handler.assert_called_once()
        self.assertEqual(child.timeouts, [5, 120, 120])
        self.assertEqual(len(child.process.stdin.data.splitlines()), 1)
        child.process.kill.assert_not_called()

    def test_near_deadline_reply_grace_cannot_admit_another_paid_call(self):
        child = ChildHarness([(4.9, call(1)), (2, call(2))])
        original_write = child.process.stdin.write
        def slow_write(payload):
            child.now += 1
            return original_write(payload)
        child.process.stdin.write = slow_write
        handler = Mock(side_effect=lambda message: self.slow_reply(child))
        with child.patches(), patch.object(capture, "CHILD_EXECUTION_SECONDS", 5), \
             self.assertRaisesRegex(capture.CaptureError, "^offline_child_execution_timeout$"):
            child.run(handler)
        handler.assert_called_once_with(call(1))
        self.assertEqual(child.timeouts, [5, 120])
        self.assertEqual(len(child.process.stdin.data.splitlines()), 1)
        child.process.kill.assert_called_once()

    def test_expired_pending_reply_still_has_a_finite_idle_grace(self):
        child = ChildHarness([(4.9, call(1)), (0, "timeout")])
        original_write = child.process.stdin.write
        def slow_write(payload):
            child.now += 1
            return original_write(payload)
        child.process.stdin.write = slow_write
        with child.patches(), patch.object(capture, "CHILD_EXECUTION_SECONDS", 5), \
             self.assertRaisesRegex(capture.CaptureError, "^offline_child_timeout$"):
            child.run(lambda message: {"ok": True})
        self.assertEqual(child.timeouts, [5, 120])
        child.process.kill.assert_called_once()

    def test_message_at_expired_deadline_never_calls_handler(self):
        child = ChildHarness([(5, call(1))])
        handler = Mock()
        with child.patches(), patch.object(capture, "CHILD_EXECUTION_SECONDS", 5), \
             self.assertRaisesRegex(capture.CaptureError, "^offline_child_execution_timeout$"):
            child.run(handler)
        handler.assert_not_called()
        child.process.kill.assert_called_once()

    def test_child_hanging_after_completion_is_killed(self):
        child = ChildHarness([(1, COMPLETE), (0, "timeout")])
        with child.patches(), self.assertRaisesRegex(capture.CaptureError, "^offline_child_timeout$"):
            child.run(None)
        child.process.kill.assert_called_once()

    def test_handler_failure_propagates_and_kills_child_without_reply(self):
        child = ChildHarness([(1, call(1))])
        def handler(message):
            child.now += 7200
            raise capture.CaptureError("fake_billing_unknown")
        with child.patches(), self.assertRaisesRegex(capture.CaptureError, "^fake_billing_unknown$"):
            child.run(handler)
        self.assertEqual(child.process.stdin.data, b"")
        child.process.kill.assert_called_once()

    def test_request_200_is_allowed_but_201_never_reaches_handler(self):
        child = ChildHarness([(1, call(200)), (1, call(201))])
        handler = Mock(return_value={"ok": True})
        with child.patches(), self.assertRaisesRegex(capture.CaptureError, "^case_request_limit$"):
            child.run(handler, prefix=199)
        handler.assert_called_once_with(call(200))
        child.process.kill.assert_called_once()
        self.assertEqual(capture.CHILD_EXECUTION_SECONDS, 3600)
        self.assertEqual(capture.CHILD_IDLE_SECONDS, 120)


if __name__ == "__main__":
    unittest.main()
