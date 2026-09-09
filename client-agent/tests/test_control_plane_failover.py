import importlib.util
import json
import tempfile
import unittest
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("client_agent_failover", ROOT / "client-agent" / "client_agent.py")
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(MODULE)


class ControlPlaneFailoverTests(unittest.TestCase):
    def test_default_installer_mirror_is_cloudflare_safe(self):
        self.assertIn("https://api-leikwanbridge.nbwly.com", MODULE.DEFAULT_PANEL_MIRROR_URLS)

    def test_pull_switches_to_mirror_and_persists_current_endpoint(self):
        with tempfile.TemporaryDirectory() as td:
            state = Path(td) / "control-plane-endpoint.json"
            calls = []
            def request(method, url, token, payload=None, **kwargs):
                calls.append(url)
                if "v3.leikwanhost.com" in url:
                    raise TimeoutError("primary timeout")
                return {"forwards": []}, {"date": "today"}
            with mock.patch.object(MODULE, "http_json_with_headers", side_effect=request):
                payload, headers, selected = MODULE.pull_client_configs(
                    {"master_url": "https://v3.leikwanhost.com", "panel_mirror_urls": ["https://api-leikwanbridge.nbwly.com"]},
                    "token", state_path=state,
                )
            self.assertEqual("https://api-leikwanbridge.nbwly.com", selected)
            self.assertEqual(2, len(calls))
            saved = json.loads(state.read_text())
            self.assertEqual(selected, saved["current_endpoint"])

    def test_business_status_does_not_failover(self):
        with tempfile.TemporaryDirectory() as td:
            state = Path(td) / "endpoint.json"
            with mock.patch.object(MODULE, "http_json_with_headers", side_effect=RuntimeError("http 401 https://v3.leikwanhost.com: denied")) as request:
                with self.assertRaises(RuntimeError):
                    MODULE.pull_client_configs(
                        {"master_url": "https://v3.leikwanhost.com", "panel_mirror_urls": ["https://api-leikwanbridge.nbwly.com"]},
                        "token", state_path=state,
                    )
            request.assert_called_once()

    def test_current_endpoint_is_tried_first_after_success(self):
        with tempfile.TemporaryDirectory() as td:
            state = Path(td) / "endpoint.json"
            state.write_text(json.dumps({"current_endpoint": "https://api-leikwanbridge.nbwly.com"}))
            calls = []
            def request(method, url, token, payload=None, **kwargs):
                calls.append(url)
                return {"forwards": []}, {}
            with mock.patch.object(MODULE, "http_json_with_headers", side_effect=request):
                MODULE.pull_client_configs(
                    {"master_url": "https://v3.leikwanhost.com", "panel_mirror_urls": ["https://api-leikwanbridge.nbwly.com"]},
                    "token", state_path=state,
                )
            self.assertIn("api-leikwanbridge.nbwly.com", calls[0])

    def test_primary_is_probed_again_after_backoff(self):
        with tempfile.TemporaryDirectory() as td:
            state = Path(td) / "endpoint.json"
            state.write_text(json.dumps({
                "current_endpoint": "https://api-leikwanbridge.nbwly.com",
                "failures": {"https://v3.leikwanhost.com": {"count": 1, "last_failure_at": 0}},
            }))
            calls = []
            def request(method, url, token, payload=None, **kwargs):
                calls.append(url)
                return {"forwards": []}, {}
            with mock.patch.object(MODULE, "http_json_with_headers", side_effect=request), mock.patch.object(MODULE.time, "time", return_value=1000):
                MODULE.pull_client_configs(
                    {"master_url": "https://v3.leikwanhost.com", "panel_mirror_urls": ["https://api-leikwanbridge.nbwly.com"]},
                    "token", state_path=state,
                )
            self.assertIn("v3.leikwanhost.com", calls[0])
            self.assertEqual("https://v3.leikwanhost.com", json.loads(state.read_text())["current_endpoint"])

    def test_dual_outage_raises_without_mutating_runtime(self):
        with tempfile.TemporaryDirectory() as td:
            state = Path(td) / "endpoint.json"
            with mock.patch.object(MODULE, "http_json_with_headers", side_effect=TimeoutError("down")):
                with self.assertRaises(RuntimeError):
                    MODULE.pull_client_configs(
                        {"master_url": "https://v3.leikwanhost.com", "panel_mirror_urls": ["https://api-leikwanbridge.nbwly.com"]},
                        "token", state_path=state,
                    )


if __name__ == "__main__":
    unittest.main()
