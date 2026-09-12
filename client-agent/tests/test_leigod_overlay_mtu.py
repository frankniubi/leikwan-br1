import importlib.util
import json
import os
import pathlib
import sys
import tempfile
import time
import unittest
from unittest import mock

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]
OVERLAY_FORWARD_ID = 65
OVERLAY_INTERFACE = f"lw-mptcp-{OVERLAY_FORWARD_ID}-0"


def load_module(module_name: str, relative_path: str):
    path = REPO_ROOT / relative_path
    spec = importlib.util.spec_from_file_location(module_name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[module_name] = module
    spec.loader.exec_module(module)
    return module


leikwan_mptcp_client = load_module(
    "leikwan_mptcp_client_mtu_under_test",
    "client-agent/leikwan_mptcp_client.py",
)
install_runtime = load_module(
    "install_forward_runtime_overlay_mtu_under_test",
    "client-agent/install_forward_runtime.py",
)


def write_ultra_status(path: pathlib.Path, *, ceiling: int | None, age_seconds: float = 0.0) -> pathlib.Path:
    payload: dict = {"forward_id": OVERLAY_FORWARD_ID, "state": "ready", "updated_at": "2030-01-01T00:00:00Z"}
    if ceiling is not None:
        payload["udp_payload_ceiling"] = ceiling
    path.write_text(json.dumps(payload), encoding="utf-8")
    if age_seconds:
        stamp = time.time() - age_seconds
        os.utime(path, (stamp, stamp))
    return path


class _CommandResult:
    def __init__(self, returncode: int = 0, stdout: str = "", stderr: str = "") -> None:
        self.returncode = returncode
        self.stdout = stdout
        self.stderr = stderr


class OverlayMtuTests(unittest.TestCase):
    def _build(self, extra):
        with tempfile.TemporaryDirectory() as tmp_dir:
            private_key = pathlib.Path(tmp_dir) / "private.key"
            private_key.write_text("client-private\n", encoding="utf-8")
            base = {
                "mode": "slave_transit",
                "interface_name": "lw-mptcp-65-0",
                "local_private_key_path": str(private_key),
                "listen_port": 43003,
                "legs": [
                    {
                        "local_address": "10.203.28.153",
                        "peer_address": "10.203.28.154",
                        "mptcp_remote_address": "10.203.28.155",
                        "peer_public_key": "slave-pub",
                        "peer_public_host": "127.0.0.1",
                        "peer_udp_port": 47045,
                        "outer_transport": "tcp",
                        "peer_tcp_fallback_port": 47045,
                        "local_uot_udp_port": 47045,
                    }
                ],
            }
            base.update(extra)
            return leikwan_mptcp_client.build_wireguard_interface_config(base)

    def test_mtu_rendered_when_config_supplies_it(self):
        # The leigod/UoT underlay peers at 127.0.0.1, so wg-quick auto-derives MTU from
        # loopback (65456) and oversized segments black-hole on the ~1280 leigod path.
        # A panel-supplied mtu must be rendered into the [Interface] block.
        config = self._build({"mtu": 1240})
        self.assertIn("MTU = 1240", config)

    def test_no_mtu_line_when_absent(self):
        config = self._build({})
        self.assertNotIn("MTU =", config)


class OverlayMtuCeilingTests(unittest.TestCase):
    """The overlay MTU follows the ultra runtime's measured per-association payload ceiling."""

    def _underlay(self, tmp_dir: str, extra: dict | None = None) -> tuple[dict, pathlib.Path]:
        private_key = pathlib.Path(tmp_dir) / "private.key"
        private_key.write_text("client-private\n", encoding="utf-8")
        status_path = pathlib.Path(tmp_dir) / f"forward-{OVERLAY_FORWARD_ID}.status.json"
        underlay = {
            "mode": "slave_transit",
            "interface_name": OVERLAY_INTERFACE,
            "local_private_key_path": str(private_key),
            "listen_port": 43003,
            "mtu": 1472,
            "ultra_status_path": str(status_path),
            "legs": [
                {
                    "forward_id": OVERLAY_FORWARD_ID,
                    "local_address": "10.203.28.153",
                    "peer_address": "10.203.28.154",
                    "mptcp_remote_address": "10.203.28.155",
                    "peer_public_key": "slave-pub",
                    "peer_public_host": "127.0.0.1",
                    "peer_udp_port": 47045,
                    "outer_transport": "tcp",
                    "peer_tcp_fallback_port": 47045,
                    "local_uot_udp_port": 47045,
                }
            ],
        }
        underlay.update(extra or {})
        return underlay, status_path

    def _render(
        self,
        *,
        ceiling: int | None = 1440,
        configured_mtu: int = 1472,
        age_seconds: float = 0.0,
        write_status: bool = True,
    ) -> tuple[str, object]:
        with tempfile.TemporaryDirectory() as tmp_dir:
            underlay, status_path = self._underlay(tmp_dir, {"mtu": configured_mtu} if configured_mtu is not None else {})
            if write_status:
                write_ultra_status(status_path, ceiling=ceiling, age_seconds=age_seconds)
            resolution = leikwan_mptcp_client.resolve_overlay_mtu(underlay)
            return leikwan_mptcp_client.build_wireguard_interface_config(underlay), resolution

    def test_measured_ceiling_clamps_overlay_mtu(self):
        config, resolution = self._render(ceiling=1440)
        self.assertIn("MTU = 1396", config)
        self.assertEqual(1440, resolution.ceiling)
        self.assertEqual("ceiling_measured", resolution.reason)
        self.assertFalse(resolution.unhealthy)

    def test_absent_ceiling_keeps_configured_mtu(self):
        config, resolution = self._render(ceiling=None)
        self.assertIn("MTU = 1472", config)
        self.assertIsNone(resolution.ceiling)
        self.assertEqual("ceiling_unmeasured", resolution.reason)
        self.assertFalse(resolution.unhealthy)

    def test_low_ceiling_uses_overlay_floor(self):
        config, resolution = self._render(ceiling=1200)
        self.assertIn("MTU = 1280", config)
        self.assertEqual(1280, resolution.mtu)

    def test_stale_status_file_is_ignored(self):
        config, resolution = self._render(ceiling=1440, age_seconds=600)
        self.assertIn("MTU = 1472", config)
        self.assertEqual("status_stale", resolution.reason)

    def test_missing_status_file_keeps_configured_mtu(self):
        config, resolution = self._render(write_status=False)
        self.assertIn("MTU = 1472", config)
        self.assertEqual("status_missing", resolution.reason)

    def test_zero_ceiling_keeps_configured_mtu_and_marks_unhealthy(self):
        with self.assertLogs("root", level="WARNING") as captured:
            config, resolution = self._render(ceiling=0)
        self.assertIn("MTU = 1472", config)
        self.assertTrue(resolution.unhealthy)
        self.assertEqual("ceiling_zero", resolution.reason)
        self.assertTrue(any("udp_payload_ceiling=0" in line for line in captured.output))

    def test_ceiling_never_raises_above_configured_mtu(self):
        config, resolution = self._render(ceiling=1500, configured_mtu=1280)
        self.assertIn("MTU = 1280", config)
        self.assertEqual(1280, resolution.mtu)

    def test_ceiling_supplies_mtu_when_panel_omits_one(self):
        config, _resolution = self._render(ceiling=1440, configured_mtu=None)
        self.assertIn("MTU = 1396", config)

    def test_default_status_path_follows_forward_id(self):
        with tempfile.TemporaryDirectory() as tmp_dir:
            underlay, _status_path = self._underlay(tmp_dir)
            underlay.pop("ultra_status_path")
            self.assertEqual(
                pathlib.Path(f"/run/ultra/forward-{OVERLAY_FORWARD_ID}.status.json"),
                leikwan_mptcp_client.ultra_status_path(underlay),
            )

    def test_explicit_zero_ceiling_is_not_a_measurement(self):
        with tempfile.TemporaryDirectory() as tmp_dir:
            _underlay, status_path = self._underlay(tmp_dir)
            write_ultra_status(status_path, ceiling=0)
            ceiling, reason = leikwan_mptcp_client.read_ultra_payload_ceiling(status_path)
        self.assertEqual(0, ceiling)
        self.assertEqual("ceiling_zero", reason)

    def test_live_ceiling_change_reapplies_interface_mtu(self):
        with tempfile.TemporaryDirectory() as tmp_dir:
            config_path = pathlib.Path(tmp_dir) / f"{OVERLAY_INTERFACE}.conf"
            underlay, status_path = self._underlay(tmp_dir, {"config_path": str(config_path)})
            runtime_config = {"forward_id": OVERLAY_FORWARD_ID, "underlays": [underlay]}
            commands: list[list[str]] = []

            def fake_run_command(parts, check=False):
                commands.append([str(part) for part in parts])
                return _CommandResult()

            states: dict = {}
            write_ultra_status(status_path, ceiling=1440)
            with mock.patch.object(leikwan_mptcp_client, "run_command", side_effect=fake_run_command):
                first = leikwan_mptcp_client.refresh_overlay_mtu(runtime_config, states)
                self.assertEqual({OVERLAY_INTERFACE: 1396}, first)
                self.assertIn("MTU = 1396", config_path.read_text(encoding="utf-8"))

                write_ultra_status(status_path, ceiling=1360)
                second = leikwan_mptcp_client.refresh_overlay_mtu(runtime_config, states)
                self.assertEqual({OVERLAY_INTERFACE: 1316}, second)
                self.assertIn("MTU = 1316", config_path.read_text(encoding="utf-8"))

                # A settled ceiling is not re-applied again.
                self.assertEqual({}, leikwan_mptcp_client.refresh_overlay_mtu(runtime_config, states))

            self.assertEqual(
                [
                    ["ip", "link", "set", "dev", OVERLAY_INTERFACE, "mtu", "1396"],
                    ["ip", "link", "set", "dev", OVERLAY_INTERFACE, "mtu", "1316"],
                ],
                commands,
            )

    def test_unusable_association_does_not_touch_live_interface(self):
        with tempfile.TemporaryDirectory() as tmp_dir:
            underlay, status_path = self._underlay(tmp_dir)
            runtime_config = {"forward_id": OVERLAY_FORWARD_ID, "underlays": [underlay]}
            write_ultra_status(status_path, ceiling=0)
            commands: list[list[str]] = []
            with self.assertLogs("root", level="WARNING") as captured:
                with mock.patch.object(
                    leikwan_mptcp_client,
                    "run_command",
                    side_effect=lambda parts, check=False: commands.append(parts) or _CommandResult(),
                ):
                    applied = leikwan_mptcp_client.refresh_overlay_mtu(runtime_config, {})
        self.assertEqual({}, applied)
        self.assertEqual([], commands)
        self.assertTrue(any("udp_payload_ceiling=0" in line for line in captured.output))


class OverlayMtuMonitorRenderTests(unittest.TestCase):
    def _render(self, underlays: list[dict]):
        forward = {
            "forward_id": 47,
            "listen_port": 11035,
            "mode": "mptcp",
            "transport": "tcp",
            "target": "127.0.0.1:5201",
            "path_prefix": "index.m3u8",
            "sni": "nrt1.nbwly.com",
            "mptcp": {
                "tcp_session_token": "tcp-token",
                "sidecar_host": "10.203.201.175",
                "sidecar_port": 8898,
                "slave_relay_host": "10.203.201.174",
                "slave_relay_port": 7598,
                "mptcp_endpoints": [],
                "underlays": underlays,
            },
        }
        return install_runtime.render_forward_runtime(
            forward,
            {
                "mptcp_client_bin": "/usr/local/bin/leikwan-mptcp-client.py",
                "state_dir": "/var/lib/leikwan-client-agent",
                "wireguard_listen_port": 43000,
            },
        )

    def _transit_underlay(self) -> dict:
        return {
            "interface_name": "lw-mptcp-47-0",
            "mode": "slave_transit",
            "legs": [
                {
                    "endpoint_id": 500,
                    "outer_transport": "wstunnel_udp_via_slave",
                    "peer_udp_port": 40283,
                    "local_address": "10.203.201.173",
                    "peer_address": "10.203.201.174",
                    "mptcp_remote_address": "10.203.201.175",
                    "slave_dial_host": "47.116.11.144",
                    "slave_dial_port": 7329,
                    "slave_sni_host": "nrt1.nbwly.com",
                    "slave_path_prefix": "content/81743c72/episode-143.mp4",
                    "cf_target_host": "127.0.0.1",
                    "cf_target_port": 7329,
                }
            ],
        }

    def test_start_script_keeps_mtu_monitor_beside_forward_runtime(self):
        rendered = self._render([self._transit_underlay()])
        script = rendered["start_script"]
        self.assertIn("--monitor-overlay-mtu &", script)
        self.assertLess(script.index("--setup-underlays"), script.index("--monitor-overlay-mtu"))
        self.assertEqual(1, script.count("--setup-underlays"))

    def test_start_script_skips_mtu_monitor_without_transit_underlays(self):
        rendered = self._render([])
        self.assertNotIn("--monitor-overlay-mtu", rendered["start_script"])


if __name__ == "__main__":
    unittest.main()
