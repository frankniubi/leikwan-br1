import importlib.util
import json
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("ultra_runtime_under_test", ROOT / "client-agent" / "install_forward_runtime.py")
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(MODULE)


def sample_forward():
    return {
        "forward_id": 42,
        "listen_port": 11042,
        "target": "203.0.113.10:443",
        "sni": "ignored.example",
        "path_prefix": "ignored",
        "mode": "single",
        "transport": "tcp+udp",
        "backend_mode": "ultra",
        "active_family": "ultra",
        "target_contract": "ultra",
        "sidecar_required": False,
        "aggregation_enabled": False,
        "speed_limit_mbps": 20,
        "ultra_access": {
            "protocol": "leikwan-ultra-capability-v1",
            "token": "opaque-client-token",
            "issued_at": "2030-01-01T00:00:00Z",
            "expires_at": "2030-01-01T00:30:00Z",
            "generation": "generation-1",
            "scope": {"client_id": 7, "forward_id": 42},
            "line_ips": ["203.0.113.20", "203.0.113.21"],
            "transport": "tcp+udp",
            "rate_limit_mbps": 20,
            "fallback_policy": "detected_then_any",
            "zone_aliases": {
                "shanghai": ["Shanghai", "上海"],
                "beijing": ["Beijing", "北京"],
            },
        },
        "_ultra_material": {
            "forward_id": 42,
            "token": "opaque-client-token",
            "expires_at": "2030-01-01T00:30:00Z",
            "sealed_material": "um1.opaque-material",
        },
    }


class UltraRuntimeTests(unittest.TestCase):
    def test_ultra_renders_only_generic_direct_binary_runtime(self):
        rendered = MODULE.render_forward_runtime(sample_forward(), {})
        self.assertEqual("ultra-forward-42", rendered["service_name"])
        generated_text = rendered["service_unit"] + rendered["start_script"]
        self.assertIn("/usr/local/bin/ultra-client", generated_text)
        self.assertNotIn("wstunnel", generated_text.lower())
        self.assertNotIn("socks", generated_text.lower())
        self.assertNotIn("18443", generated_text)
        self.assertNotIn("gateway", generated_text.lower())
        generated = json.dumps(rendered, ensure_ascii=False).lower()
        self.assertNotIn("leigod", generated)
        self.assertNotIn("socks", generated)
        self.assertNotIn("18443", generated)
        self.assertNotIn("gateway", generated)
        self.assertNotIn("line-user", generated)
        self.assertNotIn("1234567890123456", generated)

        config_file = next(item for item in rendered["files"] if item["path"].endswith("forward-42.json"))
        config = json.loads(config_file["content"])
        self.assertNotIn("token", config)
        self.assertNotIn("entries", config)
        self.assertEqual("/run/ultra/forward-42.access.json", config["access_file"])
        self.assertEqual(42, config["forward_id"])
        self.assertEqual("203.0.113.10:443", config["target"])
        self.assertEqual(64, config["udp_queue_packets"])
        self.assertEqual("/run/ultra/forward-42.status.json", config["status_path"])
        self.assertEqual("/var/lib/ultra/location.json", config["location_cache_path"])
        self.assertEqual("/run/ultra/location.lock", config["location_lock_path"])
        self.assertEqual("detected_then_any", config["fallback_policy"])
        self.assertEqual(["Shanghai", "上海"], config["zone_aliases"]["shanghai"])
        self.assertEqual(4, config["probe_workers"])
        self.assertEqual(800, config["probe_timeout_ms"])
        self.assertIn("ReadWritePaths=/run/ultra /var/lib/ultra", rendered["service_unit"])
        access_file = next(item for item in rendered["files"] if item["path"].endswith("forward-42.access.json"))
        access = json.loads(access_file["content"])
        self.assertEqual("opaque-client-token", access["token"])
        self.assertEqual("um1.opaque-material", access["sealed_material"])
        self.assertNotIn("entries", access)
        self.assertFalse(access_file["restart_on_change"])

    def test_ultra_fails_closed_without_resolved_material(self):
        forward = sample_forward()
        del forward["_ultra_material"]
        with self.assertRaisesRegex(RuntimeError, "ultra material missing"):
            MODULE.render_forward_runtime(forward, {})


if __name__ == "__main__":
    unittest.main()
