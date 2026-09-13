import importlib.util
import hashlib
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
            "protocol": "leikwan-ultra-policy-v2",
            "session_ticket": "opaque-client-token",
            "policy_generation": "a" * 64,
            "target_digest": hashlib.sha256(b"203.0.113.10:443").hexdigest(),
            "issued_at": "2030-01-01T00:00:00Z",
            "expires_at": "2030-01-01T00:30:00Z",
            "transport": "tcp+udp",
            "rate_limit_mbps": 20,
            "fallback_policy": "detected_then_any",
            "zone_aliases": {
                "shanghai": ["Shanghai", "上海"],
                "beijing": ["Beijing", "北京"],
            },
        },
    }


class UltraRuntimeTests(unittest.TestCase):
    def test_linux_installer_verifies_ultra_artifact_before_agent_restart(self):
        installer = (ROOT / "install" / "install-client-agent.sh").read_text(encoding="utf-8")
        download = 'download_manifest_artifact ultra_client_runtime "$ARTIFACT_BASE/artifacts/ultra/ultra-client_linux_amd64" "$BIN_DIR/ultra-client"'

        self.assertIn(download, installer)
        self.assertIn("expected_sha256=\"$(_vers_sha256 \"$key\")\"", installer)
        self.assertIn("downloaded artifact sha256 mismatch: $key", installer)
        self.assertLess(installer.index(download), installer.index('systemctl restart "$SERVICE_NAME.service"'))

    def test_ultra_runtime_emits_udp_prewarm_for_udp_capable_transports(self):
        for transport in ("udp", "tcp+udp"):
            with self.subTest(transport=transport):
                forward = sample_forward()
                forward["transport"] = transport
                forward["ultra_access"]["transport"] = transport
                rendered = MODULE.render_ultra_runtime(forward, {})
                config_file = next(item for item in rendered["files"] if item["path"].endswith("forward-42.json"))
                config = json.loads(config_file["content"])

                self.assertTrue(config["udp_prewarm"])
                self.assertEqual(5, config["udp_keepalive_interval_seconds"])

    def test_ultra_runtime_disables_udp_prewarm_for_tcp_only(self):
        forward = sample_forward()
        forward["transport"] = "tcp"
        forward["ultra_access"]["transport"] = "tcp"
        rendered = MODULE.render_ultra_runtime(forward, {})
        config_file = next(item for item in rendered["files"] if item["path"].endswith("forward-42.json"))
        config = json.loads(config_file["content"])

        self.assertFalse(config["udp_prewarm"])
        self.assertEqual(0, config["udp_keepalive_interval_seconds"])

    def test_ultra_renders_generic_direct_binary_with_auto_selection(self):
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
        self.assertEqual("/run/ultra/forward-42.control.sock", config["control_socket"])
        self.assertEqual(42, config["forward_id"])
        self.assertEqual("203.0.113.10:443", config["target"])
        self.assertEqual(256, config["udp_queue_packets"])
        self.assertEqual("/run/ultra/forward-42.status.json", config["status_path"])
        self.assertEqual("/var/lib/ultra/location.json", config["location_cache_path"])
        self.assertEqual("/run/ultra/location.lock", config["location_lock_path"])
        self.assertNotIn("fallback_policy", config)
        self.assertEqual(["Shanghai", "上海"], config["zone_aliases"]["shanghai"])
        self.assertTrue(config["auto_select"])
        self.assertEqual("1.1.1.1:53", config["probe_target"])
        self.assertEqual(4, config["probe_workers"])
        self.assertEqual(30, config["probe_duration_seconds"])
        self.assertEqual(800, config["probe_timeout_ms"])
        self.assertEqual(30, config["probe_samples"])
        self.assertEqual(1000, config["probe_interval_ms"])
        self.assertIn("ReadWritePaths=/run/ultra /var/lib/ultra", rendered["service_unit"])
        self.assertFalse(any(item["path"].endswith(".access.json") for item in rendered["files"]))

    def test_ultra_runtime_can_explicitly_enable_auto_selection(self):
        rendered = MODULE.render_ultra_runtime(sample_forward(), {"ultra_selection_mode": "auto"})
        config_file = next(item for item in rendered["files"] if item["path"].endswith("forward-42.json"))
        config = json.loads(config_file["content"])
        self.assertTrue(config["auto_select"])
        self.assertEqual("1.1.1.1:53", config["probe_target"])
        self.assertEqual(4, config["probe_workers"])
        self.assertEqual(30, config["probe_duration_seconds"])
        self.assertEqual(800, config["probe_timeout_ms"])
        self.assertEqual(30, config["probe_samples"])
        self.assertEqual(1000, config["probe_interval_ms"])

    def test_ultra_fails_closed_without_resolved_material(self):
        forward = sample_forward()
        forward["ultra_access"]["policy_generation"] = "invalid"
        with self.assertRaisesRegex(RuntimeError, "ultra policy scope invalid"):
            MODULE.render_forward_runtime(forward, {})


if __name__ == "__main__":
    unittest.main()
