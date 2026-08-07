import importlib.util
import json
import pathlib
import sys
import tempfile
import threading
import unittest
from unittest import mock


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(name: str, relative: str):
    path = REPO_ROOT / relative
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


client_agent = load_module("client_agent_config_watch_under_test", "client-agent/client_agent.py")


class RecordingStopEvent:
    def __init__(self, stop_after_waits: int):
        self.stop_after_waits = stop_after_waits
        self.waits: list[float] = []
        self.stopped = False

    def is_set(self) -> bool:
        return self.stopped

    def set(self) -> None:
        self.stopped = True

    def wait(self, seconds: float) -> bool:
        self.waits.append(seconds)
        if len(self.waits) >= self.stop_after_waits:
            self.stopped = True
        return self.stopped


class ClientAgentConfigWatchTests(unittest.TestCase):
    def make_agent(self, **dependencies):
        temp_dir = tempfile.TemporaryDirectory()
        self.addCleanup(temp_dir.cleanup)
        root = pathlib.Path(temp_dir.name)
        config = root / "config.json"
        config.write_text(
            json.dumps(
                {
                    "master_url": "https://panel.example.test",
                    "agent_token": "agent-token",
                    "client_id": 20,
                    "device_id": "linux-device-a",
                    "device_name": "canary-a",
                    "state_dir": str(root / "state"),
                    "poll_interval_seconds": 30,
                }
            ),
            encoding="utf-8",
        )
        return client_agent.ClientAgent(config, **dependencies)

    def test_watch_sends_revision_and_device_headers_and_only_sets_event(self):
        calls = []

        def fake_http(method, url, token, payload=None, *, extra_headers=None, timeout=30):
            calls.append((method, url, token, payload, extra_headers, timeout))
            return {"changed": True, "revision": 7}, {}

        changed = threading.Event()
        agent = self.make_agent(http_client=fake_http, config_changed_event=changed)
        agent.observed_config_revision = 3
        agent.sync_once = lambda: self.fail("watch thread must never reconcile")

        agent.config_watch_once()

        self.assertTrue(changed.is_set())
        self.assertEqual(7, agent.observed_config_revision)
        self.assertIn("revision=3", calls[0][1])
        self.assertIn("wait=25", calls[0][1])
        self.assertEqual("linux-device-a", calls[0][4]["X-Device-Id"])
        self.assertTrue(calls[0][4]["X-Device-Platform"].startswith("linux"))
        self.assertGreater(calls[0][5], 25)

    def test_watch_failure_backoff_is_bounded_and_never_calls_sync(self):
        stop = RecordingStopEvent(stop_after_waits=6)

        def failing_http(*_args, **_kwargs):
            raise RuntimeError("watch down")

        agent = self.make_agent(http_client=failing_http, stop_event=stop)
        agent.sync_once = lambda: self.fail("watch loop must never reconcile")

        agent.run_config_watch_loop()

        self.assertEqual([1, 2, 4, 8, 16, 30], stop.waits)

    def test_start_watch_creates_only_one_daemon_thread(self):
        created = []

        class FakeThread:
            def __init__(self, *, target, name, daemon):
                self.target = target
                self.name = name
                self.daemon = daemon
                self.started = False
                created.append(self)

            def is_alive(self):
                return self.started

            def start(self):
                self.started = True

        agent = self.make_agent()
        with mock.patch.object(client_agent.threading, "Thread", FakeThread):
            agent.start_config_watch()
            agent.start_config_watch()

        self.assertEqual(1, len(created))
        self.assertTrue(created[0].daemon)
        self.assertEqual("leikwan-config-watch", created[0].name)
        self.assertEqual(agent.run_config_watch_loop, created[0].target)

    def test_main_loop_consumes_change_and_full_pull_deadline_has_bounded_jitter(self):
        stop = RecordingStopEvent(stop_after_waits=1)
        changed = threading.Event()
        changed.set()
        calls = []
        agent = self.make_agent(
            stop_event=stop,
            config_changed_event=changed,
            clock=lambda: 100.0,
            jitter=lambda _low, _high: 0.5,
        )
        agent.start_config_watch = lambda: None
        agent.sync_once = lambda: calls.append("sync")
        agent.ensure_runtime_health = lambda _forwards: None
        agent.run_cfwg_latency_watchdog = lambda _forwards: None

        agent.run()

        self.assertEqual(["sync"], calls)
        self.assertFalse(changed.is_set())
        self.assertEqual(130.5, agent.next_full_sync_deadline(100.0))
        self.assertEqual([1.0], stop.waits)


if __name__ == "__main__":
    unittest.main()
