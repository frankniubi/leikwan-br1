import importlib.util
import json
import pathlib
import subprocess
import sys
import tempfile
import unittest
from types import SimpleNamespace
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


slave_agent_module = load_module("slave_agent_static_uplink_under_test", "slave-agent/slave_agent.py")


class SlaveStaticSidecarUplinkTests(unittest.TestCase):
    def setUp(self):
        # Isolate the shared restart-ledger circuit breaker per test so that
        # repeated runs (with fixed mocked timestamps) do not accumulate ledger
        # entries on disk and trip the breaker, suppressing the restarts these
        # tests assert on.
        ledger_dir = tempfile.TemporaryDirectory()
        self.addCleanup(ledger_dir.cleanup)
        patch = mock.patch.object(
            slave_agent_module,
            "RESTART_LEDGER_DIR",
            pathlib.Path(ledger_dir.name),
        )
        patch.start()
        self.addCleanup(patch.stop)

    def make_agent(self):
        tmp_dir = tempfile.TemporaryDirectory()
        self.addCleanup(tmp_dir.cleanup)
        tmp = pathlib.Path(tmp_dir.name)
        config_path = tmp / "config.json"
        config_path.write_text(json.dumps({
            "master_url": "https://panel.example.com",
            "node_token": "token",
            "state_dir": str(tmp / "state"),
            "systemd_dir": str(tmp / "systemd"),
            "wireguard_config_dir": str(tmp / "wireguard"),
            "wstunnel_bin": "/usr/local/bin/wstunnel",
        }), encoding="utf-8")
        return slave_agent_module.SlaveAgent(config_path)

    def static_uplink_management(self, **overrides):
        uplink = {
            "enabled": True,
            "interface_name": "lws4c2n19p",
            "lane": "primary",
            "local_address": "10.240.8.2",
            "peer_address": "10.240.8.1",
            "sidecar_peer_public_key": "sidecar-pub",
            "sidecar_endpoint_host": "138.252.162.120",
            "sidecar_endpoint_port": 14970,
            "local_listen_port": 43123,
            "outer_transport": "udp",
            "peer_keepalive_seconds": 25,
            "watchdog": {"enabled": True, "handshake_stale_seconds": 15},
        }
        uplink.update(overrides)
        return {
            "agent_config_json": {},
            "sidecar_static_uplinks": [uplink],
        }

    @staticmethod
    def shared_uplink(combination_id: int = 13, **overrides) -> dict:
        uplink = {
            "enabled": True,
            "mode": "static_slave_sidecar",
            "wireguard_peer_mode": "shared",
            "forward_combination_id": combination_id,
            "sidecar_point_id": 10,
            "node_id": 2,
            "lane": "shared",
            "interface_name": "lwsp10n2",
            "listen_port": 18502,
            "local_address": "10.240.3.2",
            "peer_address": "10.240.3.1",
            "sidecar_peer_public_key": "sidecar-public-key",
            "sidecar_endpoint_host": "hk-sidecar.example",
            "sidecar_endpoint_port": 18502,
            "local_listen_port": 43172,
            "outer_transport": "udp",
            "peer_keepalive_seconds": 25,
        }
        uplink.update(overrides)
        return uplink

    def test_shared_point_is_applied_once_when_panel_repeats_combination_provenance(self):
        agent = self.make_agent()
        first = self.shared_uplink(combination_id=13)
        repeated = self.shared_uplink(combination_id=99)

        with mock.patch.object(agent, "apply_shared_sidecar_uplink") as apply_uplink:
            with mock.patch.object(agent, "watch_static_sidecar_uplink"):
                agent.apply_static_sidecar_uplinks([first, repeated])

        apply_uplink.assert_called_once()

    def test_conflicting_shared_duplicates_preserve_previous_interface(self):
        agent = self.make_agent()
        agent.save_static_sidecar_uplink_state({
            "interfaces": {
                "lwsp10n2": {
                    "interface_name": "lwsp10n2",
                    "sidecar_point_id": 10,
                    "node_id": 2,
                    "lane": "shared",
                }
            }
        })
        first = self.shared_uplink(combination_id=13)
        conflict = self.shared_uplink(combination_id=99, sidecar_endpoint_port=18504)

        with mock.patch.object(agent, "apply_shared_sidecar_uplink") as apply_uplink:
            with mock.patch.object(agent, "watch_static_sidecar_uplink"):
                with mock.patch.object(agent, "remove_sidecar_uplink") as remove_uplink:
                    agent.apply_static_sidecar_uplinks([first, conflict])

        apply_uplink.assert_not_called()
        remove_uplink.assert_not_called()
        state = agent.load_static_sidecar_uplink_state()
        self.assertIn("lwsp10n2", state.get("interfaces", {}))

    def test_incomplete_shared_record_preserves_previous_interface(self):
        agent = self.make_agent()
        agent.save_static_sidecar_uplink_state({
            "interfaces": {
                "lwsp10n2": {
                    "interface_name": "lwsp10n2",
                    "sidecar_point_id": 10,
                    "node_id": 2,
                    "lane": "shared",
                }
            }
        })
        incomplete = self.shared_uplink(sidecar_peer_public_key="")

        with mock.patch.object(agent, "apply_shared_sidecar_uplink") as apply_uplink:
            with mock.patch.object(agent, "remove_sidecar_uplink") as remove_uplink:
                agent.apply_static_sidecar_uplinks([incomplete])

        apply_uplink.assert_not_called()
        remove_uplink.assert_not_called()
        state = agent.load_static_sidecar_uplink_state()
        self.assertIn("lwsp10n2", state.get("interfaces", {}))

    def test_shared_apply_failure_does_not_commit_or_cleanup(self):
        agent = self.make_agent()
        previous = {
            "interface_name": "lwsp10n2",
            "sidecar_point_id": 10,
            "node_id": 2,
            "lane": "shared",
        }
        agent.save_static_sidecar_uplink_state({"interfaces": {"lwsp10n2": previous}})

        with mock.patch.object(agent, "apply_shared_sidecar_uplink", side_effect=RuntimeError("start failed")):
            with mock.patch.object(agent, "remove_sidecar_uplink") as remove_uplink:
                agent.apply_static_sidecar_uplinks([self.shared_uplink()])

        remove_uplink.assert_not_called()
        state = agent.load_static_sidecar_uplink_state()
        self.assertEqual(previous, state.get("interfaces", {}).get("lwsp10n2"))

    def test_shared_restart_failure_restores_previous_config(self):
        agent = self.make_agent()
        agent.wireguard_private_key_path().parent.mkdir(parents=True, exist_ok=True)
        agent.wireguard_private_key_path().write_text("slave-private\n", encoding="utf-8")
        config_path = agent.wireguard_interface_path("lwsp10n2")
        config_path.parent.mkdir(parents=True, exist_ok=True)
        config_path.write_text("old-config\n", encoding="utf-8")
        restart_attempts = 0

        def fake_run(command: str, check: bool = True):
            nonlocal restart_attempts
            if command == "systemctl is-active --quiet wg-quick@lwsp10n2.service":
                return SimpleNamespace(returncode=0, stdout="", stderr="")
            if command == "systemctl restart wg-quick@lwsp10n2.service":
                restart_attempts += 1
                return SimpleNamespace(returncode=1 if restart_attempts == 1 else 0, stdout="", stderr="restart failed")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(slave_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(slave_agent_module, "run_command", side_effect=fake_run):
                with self.assertRaises(RuntimeError):
                    agent.apply_shared_sidecar_uplink("lwsp10n2", self.shared_uplink())

        self.assertEqual("old-config\n", config_path.read_text(encoding="utf-8"))
        self.assertEqual(2, restart_attempts)

    def mark_selector_interfaces_ready(self, agent, *interfaces: str) -> None:
        for iface in interfaces:
            path = agent.wireguard_interface_path(iface)
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text("[Interface]\n", encoding="utf-8")

    def test_management_applies_static_uplinks_with_pinned_listen_port(self):
        agent = self.make_agent()
        agent.wireguard_private_key_path().parent.mkdir(parents=True, exist_ok=True)
        agent.wireguard_private_key_path().write_text("slave-private\n", encoding="utf-8")
        commands: list[str] = []
        written: dict[str, str] = {}

        def fake_run(command: str, check: bool = True):
            commands.append(command)
            if command == "wg show lws4c2n19p latest-handshakes":
                return SimpleNamespace(returncode=0, stdout="peer 999\n", stderr="")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        def fake_write(path, content):
            written[str(path)] = content
            return True

        with mock.patch.object(slave_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(slave_agent_module, "run_command", side_effect=fake_run):
                with mock.patch.object(slave_agent_module.time, "time", return_value=1000):
                    with mock.patch.object(agent, "ensure_wireguard_identity", return_value="slave-pub"):
                        with mock.patch.object(agent, "write_text_if_changed", side_effect=fake_write):
                            agent.apply_management_state(self.static_uplink_management())

        rendered = "\n".join(written.values())
        self.assertIn("ListenPort = 43123", rendered)
        self.assertIn("Address = 10.240.8.2/32", rendered)
        self.assertIn("Endpoint = 138.252.162.120:14970", rendered)
        self.assertIn("AllowedIPs = 10.240.8.1/32", rendered)
        self.assertIn("systemctl restart wg-quick@lws4c2n19p.service", commands)

    def test_management_derives_stable_listen_port_when_panel_payload_is_older(self):
        agent = self.make_agent()
        agent.wireguard_private_key_path().parent.mkdir(parents=True, exist_ok=True)
        agent.wireguard_private_key_path().write_text("slave-private\n", encoding="utf-8")
        written: dict[str, str] = {}
        management = self.static_uplink_management(
            forward_combination_id=2,
            sidecar_point_id=4,
            node_id=19,
        )
        del management["sidecar_static_uplinks"][0]["local_listen_port"]

        def fake_run(command: str, check: bool = True):
            if command == "wg show lws4c2n19p latest-handshakes":
                return SimpleNamespace(returncode=0, stdout="peer 999\n", stderr="")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(slave_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(slave_agent_module, "run_command", side_effect=fake_run):
                with mock.patch.object(slave_agent_module.time, "time", return_value=1000):
                    with mock.patch.object(agent, "ensure_wireguard_identity", return_value="slave-pub"):
                        with mock.patch.object(agent, "write_text_if_changed", side_effect=lambda path, content: written.setdefault(str(path), content) is not None):
                            agent.apply_management_state(management)

        rendered = "\n".join(written.values())
        self.assertIn("ListenPort = 43621", rendered)

    def test_leigod_uplink_renders_gost_client_and_repins_endpoint(self):
        agent = self.make_agent()
        agent.wireguard_private_key_path().parent.mkdir(parents=True, exist_ok=True)
        agent.wireguard_private_key_path().write_text("slave-private\n", encoding="utf-8")
        written: dict[str, str] = {}
        management = self.static_uplink_management(
            outer_transport="leigod",
            sidecar_endpoint_host="127.0.0.1",
            sidecar_endpoint_port=43123,
            local_listen_port=43123,
            gost_listen_udp_port=43123,
            sidecar_wg_listen_port=14970,
            leigod_line_id=451,
            sidecar_gost_host="138.252.162.120",
            sidecar_gost_port=47123,
            socks_user="wguot",
            socks_pass="s3cret",
            mtu=1280,
        )

        def fake_run(command: str, check: bool = True):
            if command == "wg show lws4c2n19p latest-handshakes":
                return SimpleNamespace(returncode=0, stdout="peer 999\n", stderr="")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(slave_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(slave_agent_module, "run_command", side_effect=fake_run):
                with mock.patch.object(slave_agent_module.time, "time", return_value=1000):
                    with mock.patch.object(agent, "ensure_wireguard_identity", return_value="slave-pub"):
                        with mock.patch.object(agent, "write_text_if_changed", side_effect=lambda path, content: written.setdefault(str(path), content) is not None):
                            with mock.patch.object(agent, "ensure_leigod_line_service"):
                                agent.apply_management_state(management)

        rendered = "\n".join(written.values())
        gost_unit = next((c for p, c in written.items() if "leikwan-gost-lws4c2n19p" in p), None)
        self.assertIsNotNone(gost_unit, "gost client unit not written")
        self.assertIn("-L udp://127.0.0.1:43123/127.0.0.1:14970?keepAlive=true&ttl=30s", gost_unit)
        self.assertIn("-F socks5://127.0.0.1:20451", gost_unit)
        self.assertIn("-F socks5://wguot:s3cret@138.252.162.120:47123?udp=true", gost_unit)
        self.assertIn("Endpoint = 127.0.0.1:43123", rendered)
        self.assertNotIn("ListenPort = 43123", rendered)
        self.assertIn("MTU = 1280", rendered)

    def test_leigod_uplink_lguot_impl_renders_lguot_client(self):
        agent = self.make_agent()
        agent.wireguard_private_key_path().parent.mkdir(parents=True, exist_ok=True)
        agent.wireguard_private_key_path().write_text("slave-private\n", encoding="utf-8")
        written: dict[str, str] = {}
        management = self.static_uplink_management(
            outer_transport="leigod",
            sidecar_endpoint_host="127.0.0.1",
            sidecar_endpoint_port=43123,
            local_listen_port=43123,
            gost_listen_udp_port=43123,
            sidecar_wg_listen_port=14970,
            leigod_line_id=451,
            sidecar_gost_host="138.252.162.120",
            sidecar_gost_port=47123,
            socks_user="wguot",
            socks_pass="s3cret",
            leigod_uot_impl="lguot",
            mtu=1280,
        )

        def fake_run(command: str, check: bool = True):
            if command == "wg show lws4c2n19p latest-handshakes":
                return SimpleNamespace(returncode=0, stdout="peer 999\n", stderr="")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(slave_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(slave_agent_module, "run_command", side_effect=fake_run):
                with mock.patch.object(slave_agent_module.time, "time", return_value=1000):
                    with mock.patch.object(agent, "ensure_wireguard_identity", return_value="slave-pub"):
                        with mock.patch.object(agent, "write_text_if_changed", side_effect=lambda path, content: written.setdefault(str(path), content) is not None):
                            with mock.patch.object(agent, "ensure_leigod_line_service"):
                                with mock.patch.object(agent, "ensure_lguot_binary"):
                                    agent.apply_management_state(management)

        rendered = "\n".join(written.values())
        lguot_unit = next((c for p, c in written.items() if "leikwan-lguot-lws4c2n19p" in p), None)
        self.assertIsNotNone(lguot_unit, "lguot client unit not written")
        self.assertIn("/usr/local/bin/lguot client", lguot_unit)
        self.assertIn("-listen 127.0.0.1:43123", lguot_unit)
        self.assertIn("-socks 127.0.0.1:20451", lguot_unit)
        self.assertIn("-target 138.252.162.120:47123", lguot_unit)
        self.assertIn("-token s3cret", lguot_unit)
        # gost client unit must NOT be written when the lguot impl is selected
        gost_unit = next((c for p, c in written.items() if "leikwan-gost-lws4c2n19p" in p), None)
        self.assertIsNone(gost_unit, "gost client unit should not be written for lguot impl")
        self.assertIn("Endpoint = 127.0.0.1:43123", rendered)

    def test_apply_leigod_session_writes_file_0600(self):
        agent = self.make_agent()
        chmod_calls: list = []
        with tempfile.TemporaryDirectory() as ld:
            agent.config["leigod_root"] = ld
            with mock.patch.object(slave_agent_module.os, "chmod", side_effect=lambda p, m: chmod_calls.append((str(p), m))):
                agent.apply_leigod_session('{"client_token":"x"}')
                # empty / non-str must be a no-op (no extra chmod, no crash)
                agent.apply_leigod_session("")
                agent.apply_leigod_session(None)
            session_path = pathlib.Path(ld) / "session.json"
            self.assertTrue(session_path.exists())
            self.assertIn("client_token", session_path.read_text(encoding="utf-8"))
            self.assertTrue(any(m == 0o600 and p.endswith("session.json") for p, m in chmod_calls), chmod_calls)
            self.assertEqual(1, len(chmod_calls))  # only the real write chmod'd

    def test_apply_management_state_pushes_leigod_session(self):
        agent = self.make_agent()
        seen: list = []
        with mock.patch.object(agent, "apply_leigod_session", side_effect=lambda s: seen.append(s)):
            with mock.patch.object(agent, "apply_static_sidecar_uplinks"):
                agent.apply_management_state({"leigod_session_json": "BLOB"}, schedule_updates=False)
        self.assertIn("BLOB", seen)

    def test_selector_records_active_forward_combination_id(self):
        agent = self.make_agent()
        selector = {
            "single_lane_monitor": False,
            "sidecar_service_port": 9100,
            "max_loss_percent": 0,
            "ping_count": 1,
            "ping_timeout_seconds": 1,
            "switch_improvement_ms": 3,
            "candidates": [
                {"lane": "primary", "forward_combination_id": 10, "interface_name": "lws-cnix",
                 "ping_interface": "lws-cnix", "ping_address": "10.0.0.2", "sidecar_address": "10.0.0.2", "sidecar_port": 9100},
                {"lane": "backup", "forward_combination_id": 11, "interface_name": "lws-leig",
                 "ping_interface": "lws-leig", "ping_address": "10.0.1.2", "sidecar_address": "10.0.1.2", "sidecar_port": 9100},
            ],
        }

        def healthy_ping(command: str, check: bool = True):
            return SimpleNamespace(
                returncode=0,
                stdout="rtt min/avg/max/mdev = 1.000/2.000/3.000/0.500 ms\n5 packets transmitted, 5 received, 0% packet loss",
                stderr="",
            )

        # Case 1: both bases healthy -> the active (first) candidate is recorded as live.
        with mock.patch.object(slave_agent_module, "run_command", side_effect=healthy_ping):
            with mock.patch.object(agent, "sidecar_selector_candidate_interface_ready", return_value=True):
                state = agent.evaluate_sidecar_uplink_selector(selector, {})
        self.assertEqual(10, state["active_forward_combination_id"])
        self.assertEqual("10.0.0.2", state["active_target_host"])

        # Case 2: the cnix basis is down (interface missing) -> selector fails over to leigod (id 11).
        with mock.patch.object(slave_agent_module, "run_command", side_effect=healthy_ping):
            with mock.patch.object(agent, "sidecar_selector_candidate_interface_ready", side_effect=lambda iface: iface == "lws-leig"):
                state2 = agent.evaluate_sidecar_uplink_selector(selector, {})
        self.assertEqual(11, state2["active_forward_combination_id"])
        self.assertEqual("10.0.1.2", state2["active_target_host"])

    def test_wstunnel_udp_uplink_does_not_bind_wireguard_to_local_wstunnel_port(self):
        agent = self.make_agent()
        agent.wireguard_private_key_path().parent.mkdir(parents=True, exist_ok=True)
        agent.wireguard_private_key_path().write_text("slave-private\n", encoding="utf-8")
        written: dict[str, str] = {}
        management = self.static_uplink_management(
            outer_transport="wstunnel_udp",
            sidecar_endpoint_host="127.0.0.1",
            sidecar_endpoint_port=44499,
            local_listen_port=44499,
            local_wstunnel_udp_port=44499,
            cf_target_host="127.0.0.1",
            cf_target_port=14994,
            cf_dial_host="185.220.238.143",
            cf_dial_port=2053,
            cf_path_prefix="lws-5-1-19-p",
        )

        def fake_run(command: str, check: bool = True):
            if command == "wg show lws4c2n19p latest-handshakes":
                return SimpleNamespace(returncode=0, stdout="peer 999\n", stderr="")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(slave_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(slave_agent_module, "run_command", side_effect=fake_run):
                with mock.patch.object(slave_agent_module.time, "time", return_value=1000):
                    with mock.patch.object(agent, "ensure_wireguard_identity", return_value="slave-pub"):
                        with mock.patch.object(agent, "write_text_if_changed", side_effect=lambda path, content: written.setdefault(str(path), content) is not None):
                            with mock.patch.object(agent, "ensure_sidecar_wstunnel_udp_client_service"):
                                agent.apply_management_state(management)

        rendered = "\n".join(written.values())
        self.assertNotIn("ListenPort = 44499", rendered)
        self.assertIn("Endpoint = 127.0.0.1:44499", rendered)

    def test_wstunnel_udp_static_uplink_uses_cf_location_best_ip_when_available(self):
        agent = self.make_agent()
        agent.wireguard_private_key_path().parent.mkdir(parents=True, exist_ok=True)
        agent.wireguard_private_key_path().write_text("slave-private\n", encoding="utf-8")
        management = self.static_uplink_management(
            outer_transport="wstunnel_udp",
            sidecar_endpoint_host="127.0.0.1",
            sidecar_endpoint_port=44499,
            local_listen_port=44499,
            local_wstunnel_udp_port=44499,
            cf_target_host="127.0.0.1",
            cf_target_port=14994,
            cf_dial_host="185.220.238.143",
            cf_dial_port=2053,
            cf_sni_host="nrt.example.com",
            cf_path_prefix="lws-5-1-19-p",
            cf_mode={
                "location_group_id": 7,
                "cidrs": ["162.159.39.0/24"],
                "probe_mode": "tcp",
                "dial_port": 2053,
            },
        )
        service_args = {}

        def fake_run(command: str, check: bool = True):
            if command == "wg show lws4c2n19p latest-handshakes":
                return SimpleNamespace(returncode=0, stdout="peer 999\n", stderr="")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        def fake_ensure(*args):
            service_args["args"] = args

        with mock.patch.object(slave_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(slave_agent_module, "run_command", side_effect=fake_run):
                with mock.patch.object(slave_agent_module.time, "time", return_value=1000):
                    with mock.patch.object(agent, "ensure_wireguard_identity", return_value="slave-pub"):
                        with mock.patch.object(agent, "write_text_if_changed", return_value=True):
                            with mock.patch.object(agent, "select_cf_location_ip", return_value={"selected_ip": "162.159.39.16"}) as select_mock:
                                with mock.patch.object(agent, "ensure_sidecar_wstunnel_udp_client_service", side_effect=fake_ensure):
                                    agent.apply_management_state(management)

        select_mock.assert_called_once()
        self.assertEqual("162.159.39.16", service_args["args"][4])
        self.assertEqual("nrt.example.com", service_args["args"][6])

    def test_wstunnel_udp_static_uplink_validates_cf_edge_with_real_probe_target(self):
        # Regression for the CF-edge-selector blind-spot: an edge that 400s the routed
        # lws-5-1-N websocket path passes the raw-socket probe (false 101) but fails a
        # real wstunnel client. The slave-transit selection must therefore pass
        # probe_target (= the real transit target) + validate_current so the selector
        # validates the chosen edge with a real handshake and rotates off a 400 edge.
        agent = self.make_agent()
        agent.wireguard_private_key_path().parent.mkdir(parents=True, exist_ok=True)
        agent.wireguard_private_key_path().write_text("slave-private\n", encoding="utf-8")
        management = self.static_uplink_management(
            outer_transport="wstunnel_udp",
            sidecar_endpoint_host="127.0.0.1",
            sidecar_endpoint_port=44499,
            local_listen_port=44499,
            local_wstunnel_udp_port=44499,
            cf_target_host="127.0.0.1",
            cf_target_port=14994,
            cf_dial_host="185.220.238.143",
            cf_dial_port=2053,
            cf_sni_host="nrt.example.com",
            cf_path_prefix="lws-5-1-19-p",
            cf_mode={
                "location_group_id": 7,
                "cidrs": ["162.159.39.0/24"],
                "probe_mode": "tcp",
                "dial_port": 2053,
            },
        )

        def fake_run(command: str, check: bool = True):
            if command == "wg show lws4c2n19p latest-handshakes":
                return SimpleNamespace(returncode=0, stdout="peer 999\n", stderr="")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(slave_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(slave_agent_module, "run_command", side_effect=fake_run):
                with mock.patch.object(slave_agent_module.time, "time", return_value=1000):
                    with mock.patch.object(agent, "ensure_wireguard_identity", return_value="slave-pub"):
                        with mock.patch.object(agent, "write_text_if_changed", return_value=True):
                            with mock.patch.object(agent, "select_cf_location_ip", return_value={"selected_ip": "162.159.39.16"}) as select_mock:
                                with mock.patch.object(agent, "ensure_sidecar_wstunnel_udp_client_service"):
                                    agent.apply_management_state(management)

        select_mock.assert_called_once()
        selection_mode = select_mock.call_args.args[0]
        self.assertEqual("127.0.0.1:14994", selection_mode.get("probe_target"))
        self.assertTrue(selection_mode.get("validate_current"))

    def test_static_uplink_start_falls_back_to_wg_quick_on_systemctl_timeout(self):
        agent = self.make_agent()
        agent.wireguard_private_key_path().parent.mkdir(parents=True, exist_ok=True)
        agent.wireguard_private_key_path().write_text("slave-private\n", encoding="utf-8")
        commands: list[str] = []

        def fake_run(command: str, check: bool = True):
            commands.append(command)
            if command == "systemctl is-active --quiet wg-quick@lws4c2n19p.service":
                return SimpleNamespace(returncode=3, stdout="", stderr="")
            if command == "systemctl start wg-quick@lws4c2n19p.service":
                return SimpleNamespace(returncode=124, stdout="", stderr="command timed out after 30s")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(slave_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(slave_agent_module, "run_command", side_effect=fake_run):
                with mock.patch.object(slave_agent_module.time, "time", return_value=1000):
                    with mock.patch.object(agent, "ensure_wireguard_identity", return_value="slave-pub"):
                        with mock.patch.object(agent, "write_text_if_changed", return_value=False):
                            agent.apply_management_state(self.static_uplink_management())

        self.assertIn("systemctl start wg-quick@lws4c2n19p.service", commands)
        self.assertIn("wg-quick up lws4c2n19p", commands)

    def test_watchdog_restarts_only_after_a_seen_handshake_goes_stale(self):
        agent = self.make_agent()
        agent.wireguard_private_key_path().parent.mkdir(parents=True, exist_ok=True)
        agent.wireguard_private_key_path().write_text("slave-private\n", encoding="utf-8")
        commands: list[str] = []

        def fake_run(command: str, check: bool = True):
            commands.append(command)
            if command == "wg show lws4c2n19p latest-handshakes":
                return SimpleNamespace(returncode=0, stdout="peer 100\n", stderr="")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(slave_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(slave_agent_module, "run_command", side_effect=fake_run):
                with mock.patch.object(slave_agent_module.time, "time", return_value=1000):
                    with mock.patch.object(agent, "ensure_wireguard_identity", return_value="slave-pub"):
                        with mock.patch.object(agent, "write_text_if_changed", return_value=False):
                            agent.apply_management_state(self.static_uplink_management())

        self.assertIn("systemctl restart wg-quick@lws4c2n19p.service", commands)

    def test_watchdog_does_not_restart_never_handshaken_lane(self):
        agent = self.make_agent()
        agent.wireguard_private_key_path().parent.mkdir(parents=True, exist_ok=True)
        agent.wireguard_private_key_path().write_text("slave-private\n", encoding="utf-8")
        commands: list[str] = []

        def fake_run(command: str, check: bool = True):
            commands.append(command)
            if command == "wg show lws4c2n19p latest-handshakes":
                return SimpleNamespace(returncode=0, stdout="peer 0\n", stderr="")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(slave_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(slave_agent_module, "run_command", side_effect=fake_run):
                with mock.patch.object(slave_agent_module.time, "time", return_value=1000):
                    with mock.patch.object(agent, "ensure_wireguard_identity", return_value="slave-pub"):
                        with mock.patch.object(agent, "write_text_if_changed", return_value=False):
                            agent.apply_management_state(self.static_uplink_management())

        self.assertNotIn("systemctl restart wg-quick@lws4c2n19p.service", commands)

    def test_run_command_returns_timeout_result_when_check_false(self):
        with mock.patch.object(
            slave_agent_module.subprocess,
            "run",
            side_effect=subprocess.TimeoutExpired(cmd="systemctl start slow.service", timeout=30, output="partial", stderr="late"),
        ):
            result = slave_agent_module.run_command("systemctl start slow.service", check=False)

        self.assertEqual(124, result.returncode)
        self.assertIn("timed out", result.stderr)

    def test_enabled_uplink_with_missing_peer_key_is_torn_down_not_orphaned(self):
        # Regression: an enabled static uplink whose sidecar WG public key is not
        # yet known must NOT be left as an enabled wg-quick@ unit pointing at an
        # absent /etc/wireguard/<iface>.conf (the EADDRINUSE / "conf does not
        # exist" fail-loop observed on a slave). It must be torn down until the
        # panel publishes complete data.
        agent = self.make_agent()
        agent.wireguard_private_key_path().parent.mkdir(parents=True, exist_ok=True)
        agent.wireguard_private_key_path().write_text("slave-private\n", encoding="utf-8")
        commands: list[str] = []
        written: dict[str, str] = {}
        management = self.static_uplink_management(sidecar_peer_public_key="")

        def fake_run(command: str, check: bool = True):
            commands.append(command)
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        def fake_write(path, content):
            written[str(path)] = content
            return True

        with mock.patch.object(slave_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(slave_agent_module, "run_command", side_effect=fake_run):
                with mock.patch.object(slave_agent_module.time, "time", return_value=1000):
                    with mock.patch.object(agent, "ensure_wireguard_identity", return_value="slave-pub"):
                        with mock.patch.object(agent, "write_text_if_changed", side_effect=fake_write):
                            agent.apply_management_state(management)

        self.assertIn("systemctl disable wg-quick@lws4c2n19p.service", commands)
        self.assertNotIn("systemctl enable wg-quick@lws4c2n19p.service", commands)
        self.assertFalse(any("lws4c2n19p" in path for path in written),
                         "no WireGuard config should be written for an incomplete lane")

    def test_selector_scores_lanes_by_allowed_ip_ping_and_restarts_degraded_active_lane(self):
        agent = self.make_agent()
        selector = {
            "enabled": True,
            "selector_port": 45200,
            "sidecar_service_port": 9000,
            "candidates": [
                {
                    "lane": "primary",
                    "interface_name": "lws4c2n19p",
                    "ping_interface": "lws4c2n19p",
                    "ping_address": "10.240.8.1",
                    "sidecar_address": "10.240.8.1",
                    "sidecar_port": 9000,
                },
                {
                    "lane": "backup",
                    "interface_name": "lws4c2n19b",
                    "ping_interface": "lws4c2n19b",
                    "ping_address": "10.240.8.5",
                    "sidecar_address": "10.240.8.5",
                    "sidecar_port": 9000,
                },
            ],
            "max_active_avg_ms": 70,
            "switch_improvement_ms": 20,
            "restart_cooldown_seconds": 60,
        }
        commands: list[str] = []
        self.mark_selector_interfaces_ready(agent, "lws4c2n19p", "lws4c2n19b")

        def fake_run(command: str, check: bool = True):
            commands.append(command)
            if command == "wg show interfaces":
                return SimpleNamespace(returncode=0, stdout="lws4c2n19p lws4c2n19b\n", stderr="")
            if "lws4c2n19p" in command and "10.240.8.1" in command:
                return SimpleNamespace(returncode=0, stdout="""
--- 10.240.8.1 ping statistics ---
3 packets transmitted, 3 received, 0% packet loss, time 2002ms
rtt min/avg/max/mdev = 95.000/105.000/120.000/10.000 ms
""", stderr="")
            if "lws4c2n19b" in command and "10.240.8.5" in command:
                return SimpleNamespace(returncode=0, stdout="""
--- 10.240.8.5 ping statistics ---
3 packets transmitted, 3 received, 0% packet loss, time 2002ms
rtt min/avg/max/mdev = 35.000/38.000/42.000/2.000 ms
""", stderr="")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(slave_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(slave_agent_module, "run_command", side_effect=fake_run):
                with mock.patch.object(slave_agent_module.time, "time", return_value=1000):
                    state = agent.evaluate_sidecar_uplink_selector(selector, previous_state={"active_lane": "primary", "last_restart_at": {}})

        self.assertEqual("backup", state["active_lane"])
        self.assertEqual("latency_switch", state["switch_reason"])
        self.assertEqual("10.240.8.5", state["active_target_host"])
        self.assertEqual(9000, state["active_target_port"])
        self.assertIn("systemctl restart leikwan-wgcf-lws4c2n19p.service", commands)
        self.assertIn("systemctl restart wg-quick@lws4c2n19p.service", commands)

    def test_selector_keeps_active_lane_when_backup_is_not_better_enough(self):
        agent = self.make_agent()
        selector = {
            "enabled": True,
            "sidecar_service_port": 9000,
            "candidates": [
                {"lane": "primary", "interface_name": "lws4c2n19p", "ping_interface": "lws4c2n19p", "ping_address": "10.240.8.1", "sidecar_address": "10.240.8.1", "sidecar_port": 9000},
                {"lane": "backup", "interface_name": "lws4c2n19b", "ping_interface": "lws4c2n19b", "ping_address": "10.240.8.5", "sidecar_address": "10.240.8.5", "sidecar_port": 9000},
            ],
            "max_active_avg_ms": 70,
            "switch_improvement_ms": 20,
        }
        self.mark_selector_interfaces_ready(agent, "lws4c2n19p", "lws4c2n19b")

        def fake_run(command: str, check: bool = True):
            if command == "wg show interfaces":
                return SimpleNamespace(returncode=0, stdout="lws4c2n19p lws4c2n19b\n", stderr="")
            if "lws4c2n19p" in command:
                return SimpleNamespace(returncode=0, stdout="rtt min/avg/max/mdev = 40.000/45.000/50.000/3.000 ms\n3 packets transmitted, 3 received, 0% packet loss", stderr="")
            if "lws4c2n19b" in command:
                return SimpleNamespace(returncode=0, stdout="rtt min/avg/max/mdev = 35.000/38.000/42.000/2.000 ms\n3 packets transmitted, 3 received, 0% packet loss", stderr="")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(slave_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(slave_agent_module, "run_command", side_effect=fake_run):
                with mock.patch.object(slave_agent_module.time, "time", return_value=1000):
                    state = agent.evaluate_sidecar_uplink_selector(selector, previous_state={"active_lane": "primary"})

        self.assertEqual("primary", state["active_lane"])
        self.assertEqual("keep_active", state["switch_reason"])
        self.assertEqual("10.240.8.1", state["active_target_host"])

    def test_selector_default_hysteresis_switches_when_active_is_modestly_worse(self):
        agent = self.make_agent()
        selector = {
            "enabled": True,
            "sidecar_service_port": 9000,
            "candidates": [
                {"lane": "primary", "interface_name": "lws4c2n19p", "ping_interface": "lws4c2n19p", "ping_address": "10.240.8.1", "sidecar_address": "10.240.8.1", "sidecar_port": 9000},
                {"lane": "backup", "interface_name": "lws4c2n19b", "ping_interface": "lws4c2n19b", "ping_address": "10.240.8.5", "sidecar_address": "10.240.8.5", "sidecar_port": 9000},
            ],
            "max_active_avg_ms": 70,
        }
        self.mark_selector_interfaces_ready(agent, "lws4c2n19p", "lws4c2n19b")

        def fake_run(command: str, check: bool = True):
            if command == "wg show interfaces":
                return SimpleNamespace(returncode=0, stdout="lws4c2n19p lws4c2n19b\n", stderr="")
            if "lws4c2n19p" in command:
                return SimpleNamespace(returncode=0, stdout="rtt min/avg/max/mdev = 26.000/28.000/30.000/0.050 ms\n3 packets transmitted, 3 received, 0% packet loss", stderr="")
            if "lws4c2n19b" in command:
                return SimpleNamespace(returncode=0, stdout="rtt min/avg/max/mdev = 20.000/22.000/24.000/0.050 ms\n3 packets transmitted, 3 received, 0% packet loss", stderr="")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(slave_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(slave_agent_module, "run_command", side_effect=fake_run):
                with mock.patch.object(slave_agent_module.time, "time", return_value=1000):
                    state = agent.evaluate_sidecar_uplink_selector(selector, previous_state={"active_lane": "primary"})

        self.assertEqual("backup", state["active_lane"])
        self.assertEqual("latency_switch", state["switch_reason"])
        self.assertEqual("10.240.8.5", state["active_target_host"])

    def test_selector_service_restarts_when_config_changes_but_unit_is_unchanged(self):
        agent = self.make_agent()
        service_name = "wstunnel-svc-2-571"
        config_path = agent.sidecar_uplink_selector_config_path(service_name)
        config_path.parent.mkdir(parents=True, exist_ok=True)
        old_selector = {
            "enabled": True,
            "selector_port": 45621,
            "sidecar_service_port": 9000,
            "switch_improvement_ms": 15,
            "candidates": [],
        }
        config_path.write_text(json.dumps(old_selector, ensure_ascii=False, indent=2), encoding="utf-8")
        new_selector = dict(old_selector)
        new_selector["switch_improvement_ms"] = 3
        commands: list[str] = []

        def fake_run(command: str, check: bool = True):
            commands.append(command)
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(slave_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(slave_agent_module, "run_command", side_effect=fake_run):
                with mock.patch.object(agent, "write_text_if_changed", return_value=False):
                    agent.ensure_sidecar_uplink_selector(service_name, new_selector)

        self.assertIn("systemctl restart wstunnel-svc-2-571-sidecar-selector.service", commands)

    def test_selector_unit_tracks_agent_version_so_binary_updates_restart_selector(self):
        agent = self.make_agent()
        unit = agent.render_sidecar_uplink_selector_unit("wstunnel-svc-2-571", {"selector_port": 45621})
        self.assertIn(f"AgentVersion={slave_agent_module.AGENT_VERSION}", unit)
        self.assertIn("Restart=on-failure", unit)
        self.assertIn("StartLimitIntervalSec=300", unit)
        self.assertIn("StartLimitBurst=5", unit)
        self.assertIn("RestartSec=5", unit)

    def test_apply_static_uplinks_prunes_selector_candidates_not_in_active_set(self):
        agent = self.make_agent()
        service_name = "wstunnel-svc-2-571"
        selector = {
            "enabled": True,
            "selector_port": 45621,
            "sidecar_service_port": 9000,
            "candidates": [
                {"lane": "primary", "interface_name": "lws4c2n19p"},
                {"lane": "backup", "interface_name": "lws5c2n20b"},
            ],
        }
        config_path = agent.sidecar_uplink_selector_config_path(service_name)
        config_path.parent.mkdir(parents=True, exist_ok=True)
        config_path.write_text(json.dumps(selector), encoding="utf-8")
        kept: list[dict] = []

        with mock.patch.object(agent, "apply_sidecar_uplink"):
            with mock.patch.object(agent, "watch_static_sidecar_uplink"):
                with mock.patch.object(agent, "remove_sidecar_uplink"):
                    with mock.patch.object(agent, "ensure_sidecar_uplink_selector", side_effect=lambda _name, payload: kept.append(payload)):
                        agent.apply_static_sidecar_uplinks([
                            {
                                "enabled": True,
                                "interface_name": "lws4c2n19p",
                                "forward_combination_id": 2,
                                "sidecar_point_id": 4,
                                "node_id": 19,
                            }
                        ])

        self.assertEqual(1, len(kept))
        self.assertEqual(["lws4c2n19p"], [item["interface_name"] for item in kept[0]["candidates"]])

    def test_apply_static_uplinks_removes_selector_when_all_candidates_are_stale(self):
        agent = self.make_agent()
        service_name = "wstunnel-svc-2-571"
        config_path = agent.sidecar_uplink_selector_config_path(service_name)
        config_path.parent.mkdir(parents=True, exist_ok=True)
        config_path.write_text(json.dumps({
            "enabled": True,
            "selector_port": 45621,
            "candidates": [{"lane": "backup", "interface_name": "lws5c2n20b"}],
        }), encoding="utf-8")
        removed: list[str] = []

        with mock.patch.object(agent, "apply_sidecar_uplink"):
            with mock.patch.object(agent, "watch_static_sidecar_uplink"):
                with mock.patch.object(agent, "remove_sidecar_uplink"):
                    with mock.patch.object(agent, "remove_sidecar_uplink_selector", side_effect=lambda name: removed.append(name)):
                        agent.apply_static_sidecar_uplinks([
                            {"enabled": True, "interface_name": "lws4c2n19p"}
                        ])

        self.assertEqual([service_name], removed)

    def test_selector_probe_skips_missing_interface_without_ping_or_restart(self):
        agent = self.make_agent()
        selector = {
            "enabled": True,
            "sidecar_service_port": 9000,
            "candidates": [
                {"lane": "primary", "interface_name": "lws5c1n20p", "ping_address": "10.240.190.233", "sidecar_address": "10.240.190.233"},
            ],
        }
        commands: list[str] = []

        def fake_run(command: str, check: bool = True):
            commands.append(command)
            if command == "wg show interfaces":
                return SimpleNamespace(returncode=0, stdout="lws4c2n19p\n", stderr="")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(slave_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(slave_agent_module, "run_command", side_effect=fake_run):
                with mock.patch.object(slave_agent_module.time, "time", return_value=1000):
                    state = agent.evaluate_sidecar_uplink_selector(selector, previous_state={})

        self.assertEqual("interface_missing", state["samples"][0]["error"])
        self.assertFalse(any(command.startswith("ping ") for command in commands))
        self.assertFalse(any(command.startswith("systemctl restart") for command in commands))

    def test_selector_does_not_restart_when_all_candidates_are_unhealthy(self):
        agent = self.make_agent()
        for iface in ("lws4c2n19p", "lws4c2n19b"):
            path = agent.wireguard_interface_path(iface)
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text("[Interface]\n", encoding="utf-8")
        selector = {
            "enabled": True,
            "sidecar_service_port": 9000,
            "candidates": [
                {"lane": "primary", "interface_name": "lws4c2n19p", "ping_interface": "lws4c2n19p", "ping_address": "10.240.8.1", "sidecar_address": "10.240.8.1"},
                {"lane": "backup", "interface_name": "lws4c2n19b", "ping_interface": "lws4c2n19b", "ping_address": "10.240.8.5", "sidecar_address": "10.240.8.5"},
            ],
            "restart_cooldown_seconds": 60,
        }
        commands: list[str] = []

        def fake_run(command: str, check: bool = True):
            commands.append(command)
            if command == "wg show interfaces":
                return SimpleNamespace(returncode=0, stdout="lws4c2n19p lws4c2n19b\n", stderr="")
            if command.startswith("ping "):
                return SimpleNamespace(returncode=1, stdout="3 packets transmitted, 0 received, 100% packet loss", stderr="")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(slave_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(slave_agent_module, "run_command", side_effect=fake_run):
                with mock.patch.object(slave_agent_module.time, "time", return_value=1000):
                    state = agent.evaluate_sidecar_uplink_selector(selector, previous_state={"active_lane": "primary", "last_restart_at": {}})

        self.assertEqual("all_unhealthy", state["switch_reason"])
        self.assertEqual([], state["restarted_lanes"])
        self.assertFalse(any(command.startswith("systemctl restart") for command in commands))

    def test_run_sends_heartbeat_before_slow_startup_reconciliation(self):
        agent = self.make_agent()
        events: list[str] = []

        class StopLoop(Exception):
            pass

        with mock.patch.object(agent, "refresh_network_identity", side_effect=lambda force=False: events.append("identity")):
            with mock.patch.object(agent, "provision_inline_tls_assets", side_effect=lambda: events.append("tls")):
                with mock.patch.object(agent, "send_heartbeat", side_effect=lambda: events.append("heartbeat")):
                    with mock.patch.object(agent, "reconcile_runtime", side_effect=lambda: events.append("reconcile")):
                        with mock.patch.object(agent, "cleanup_orphan_mptcp_underlays", side_effect=lambda: events.append("cleanup")):
                            with mock.patch.object(agent, "report_usage", side_effect=lambda: events.append("usage")):
                                with mock.patch.object(agent, "process_tasks", side_effect=lambda: events.append("tasks")):
                                    with mock.patch.object(slave_agent_module.time, "sleep", side_effect=StopLoop):
                                        with self.assertRaises(StopLoop):
                                            agent.run()

        self.assertLess(events.index("heartbeat"), events.index("reconcile"))

    def test_publish_task_uses_selector_port_as_local_backend_target(self):
        agent = self.make_agent()
        task = {"service_id": 6, "forward_id": 59, "endpoint_id": 571, "payload": {}}
        payload = {
            "target": "10.240.8.1:9000",
            "listen_port": 7000,
            "path_prefix": "f59",
            "route_key": "f59",
            "transport_mode": "tcp+udp",
            "ingress_protocol": "plain_tcp_mux",
            "backend_protocol": "tcp_relay",
            "ingress_auth_token": "token",
            "publish_bond_runtime": True,
            "sidecar_uplink": {"enabled": True, "relay_target": "10.240.8.1:9000"},
            "sidecar_uplink_selector": {
                "enabled": True,
                "selector_port": 45200,
                "sidecar_service_port": 9000,
                "candidates": [
                    {"lane": "primary", "interface_name": "lws4c2n19p", "ping_interface": "lws4c2n19p", "ping_address": "10.240.8.1", "sidecar_address": "10.240.8.1", "sidecar_port": 9000},
                    {"lane": "backup", "interface_name": "lws4c2n19b", "ping_interface": "lws4c2n19b", "ping_address": "10.240.8.5", "sidecar_address": "10.240.8.5", "sidecar_port": 9000},
                ],
            },
        }
        selector_calls: list[dict] = []

        with mock.patch.object(agent, "ensure_sidecar_uplink_selector", side_effect=lambda service, selector: selector_calls.append(selector)):
            with mock.patch.object(agent, "apply_sidecar_uplink"):
                with mock.patch.object(agent, "ensure_nft_table"):
                    with mock.patch.object(agent, "delete_nft_rules"):
                        with mock.patch.object(agent, "add_nft_rules"):
                            with mock.patch.object(agent, "ensure_backend_service"):
                                with mock.patch.object(agent, "sync_listener"):
                                    with mock.patch.object(agent, "write_filter_marker", return_value={"filter_profile": "off"}):
                                        result = agent.publish_task(task, payload)

        self.assertEqual(45200, selector_calls[0]["selector_port"])
        self.assertEqual(45200, result["selector_port"])
        metadata = agent.load_service_metadata("wstunnel-svc-6-571")
        self.assertEqual("127.0.0.1:45200", metadata["target"])
        self.assertTrue(metadata["sidecar_uplink_selector"]["enabled"])


if __name__ == "__main__":
    unittest.main()
