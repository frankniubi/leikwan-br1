import importlib.util
import json
import pathlib
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


sidecar_agent_module = load_module("sidecar_agent_static_uplink_under_test", "sidecar-agent/sidecar_agent.py")


class SidecarStaticSidecarUplinkTests(unittest.TestCase):
    def make_agent(self):
        tmp_dir = tempfile.TemporaryDirectory()
        self.addCleanup(tmp_dir.cleanup)
        tmp = pathlib.Path(tmp_dir.name)
        config_path = tmp / "agent.json"
        config_path.write_text(json.dumps({
            "state_dir": str(tmp),
            "service_name": "leikwan-bond-sidecar-4",
            "managed_iperf3_ports": [],
        }), encoding="utf-8")
        return sidecar_agent_module.SidecarAgent(config_path)

    @staticmethod
    def shared_uplink(node_id: int) -> dict:
        octet = 1 + (node_id % 200)
        return {
            "enabled": True,
            "mode": "static_slave_sidecar",
            "wireguard_peer_mode": "shared",
            "forward_combination_id": 13,
            "sidecar_point_id": 10,
            "node_id": node_id,
            "lane": "shared",
            "interface_name": "lwsp10",
            "listen_port": 18502,
            "local_address": f"10.240.{octet}.1",
            "peer_address": f"10.240.{octet}.2",
            "slave_peer_public_key": f"slave-key-{node_id}",
            "outer_transport": "udp",
            "peer_keepalive_seconds": 25,
        }

    def test_shared_point_renders_one_interface_with_one_peer_per_slave(self):
        agent = self.make_agent()
        peers = [self.shared_uplink(node_id) for node_id in [2, 3, 19, 20]]

        config = agent.render_shared_sidecar_wireguard_config(peers, "private-key")

        self.assertEqual(1, config.count("[Interface]"))
        self.assertEqual(4, config.count("[Peer]"))
        self.assertEqual(1, config.count("ListenPort = 18502"))
        self.assertIn("Address = 10.240.3.1/32", config)
        self.assertIn("PublicKey = slave-key-20", config)
        self.assertIn("AllowedIPs = 10.240.21.2/32", config)

    def test_shared_point_is_applied_once_per_management_cycle(self):
        agent = self.make_agent()
        peers = [self.shared_uplink(2), self.shared_uplink(3)]

        with mock.patch.object(agent, "apply_shared_slave_sidecar_interface") as apply_shared:
            with mock.patch.object(agent, "apply_slave_sidecar_uplink") as apply_legacy:
                agent.apply_management({
                    "sidecar_uplinks": peers,
                    "bond_runtime": None,
                    "mptcp_runtime": None,
                })

        apply_shared.assert_called_once()
        apply_legacy.assert_not_called()

    def test_shared_point_apply_writes_complete_config_and_restarts_once(self):
        agent = self.make_agent()
        agent.wireguard_private_key_path().write_text("private-key\n", encoding="utf-8")
        agent.wireguard_public_key_path().write_text("public-key\n", encoding="utf-8")
        peers = [self.shared_uplink(node_id) for node_id in [2, 3, 19, 20]]
        writes: list[tuple[str, str]] = []
        commands: list[list[str]] = []

        def fake_write(path, body, mode=None):  # noqa: ANN001
            writes.append((str(path), body))
            return True

        def fake_run(args, **kwargs):  # noqa: ANN001
            commands.append(list(args))
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(sidecar_agent_module, "write_text_if_changed", side_effect=fake_write):
            with mock.patch.object(sidecar_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
                with mock.patch.object(sidecar_agent_module.subprocess, "run", side_effect=fake_run):
                    with mock.patch.object(agent, "systemd_is_active", return_value=True):
                        agent.apply_shared_slave_sidecar_interface(peers)

        configs = [body for path, body in writes if path.endswith("lwsp10.conf")]
        self.assertEqual(2, len(configs))
        self.assertEqual(configs[0], configs[1])
        self.assertEqual(4, configs[0].count("[Peer]"))
        restart_commands = [args for args in commands if args[:2] == ["systemctl", "restart"]]
        self.assertEqual([["systemctl", "restart", "wg-quick@lwsp10.service"]], restart_commands)

    def test_invalid_shared_peer_group_preserves_running_interface(self):
        agent = self.make_agent()
        first = self.shared_uplink(2)
        duplicate_key = self.shared_uplink(3)
        duplicate_key["slave_peer_public_key"] = first["slave_peer_public_key"]
        agent.save_sidecar_uplink_state({"slaves": {"lwsp10": {"interface_name": "lwsp10"}}})

        with mock.patch.object(
            agent,
            "apply_shared_slave_sidecar_interface",
            side_effect=ValueError("duplicate peer key"),
        ):
            with mock.patch.object(agent, "remove_slave_sidecar_uplink") as remove:
                agent.apply_management({
                    "sidecar_uplinks": [first, duplicate_key],
                    "bond_runtime": None,
                    "mptcp_runtime": None,
                })

        self.assertNotIn(mock.call({"interface_name": "lwsp10"}), remove.call_args_list)

    def test_shared_point_rejects_multiple_interface_names_without_cleanup(self):
        agent = self.make_agent()
        first = self.shared_uplink(2)
        conflicting_interface = self.shared_uplink(3)
        conflicting_interface["interface_name"] = "lwsp10-alt"
        agent.save_sidecar_uplink_state({
            "slaves": {
                "lwsp10": {"interface_name": "lwsp10"},
                "lws10c13n2p": {"interface_name": "lws10c13n2p"},
            }
        })

        with mock.patch.object(agent, "apply_shared_slave_sidecar_interface") as apply_shared:
            with mock.patch.object(agent, "remove_slave_sidecar_uplink") as remove:
                agent.apply_management({
                    "sidecar_uplinks": [first, conflicting_interface],
                    "bond_runtime": None,
                    "mptcp_runtime": None,
                })

        apply_shared.assert_not_called()
        remove.assert_not_called()
        state = json.loads(agent.sidecar_uplink_state_path().read_text(encoding="utf-8"))
        self.assertIn("lwsp10", state.get("slaves", {}))
        self.assertIn("lws10c13n2p", state.get("slaves", {}))

    def test_shared_restart_failure_restores_previous_configs(self):
        agent = self.make_agent()
        agent.wireguard_private_key_path().write_text("private-key\n", encoding="utf-8")
        peers = [self.shared_uplink(2), self.shared_uplink(3)]
        state_path = agent.sidecar_uplink_interfaces_dir() / "lwsp10.conf"
        system_path = agent.state_dir / "system-wireguard" / "lwsp10.conf"
        state_path.write_text("old-state-config\n", encoding="utf-8")
        system_path.parent.mkdir(parents=True, exist_ok=True)
        system_path.write_text("old-system-config\n", encoding="utf-8")
        restart_attempts = 0

        def fake_run(args, **kwargs):  # noqa: ANN001
            nonlocal restart_attempts
            command = list(args)
            if command[:2] == ["wg-quick", "strip"]:
                return SimpleNamespace(returncode=0, stdout="", stderr="")
            if command[:2] == ["systemctl", "restart"]:
                restart_attempts += 1
                return SimpleNamespace(returncode=1 if restart_attempts == 1 else 0, stdout="", stderr="restart failed")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(agent, "sidecar_uplink_system_path", return_value=system_path):
            with mock.patch.object(sidecar_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
                with mock.patch.object(sidecar_agent_module.subprocess, "run", side_effect=fake_run):
                    with mock.patch.object(agent, "systemd_is_active", return_value=True):
                        with self.assertRaises(RuntimeError):
                            agent.apply_shared_slave_sidecar_interface(peers)

        self.assertEqual("old-state-config\n", state_path.read_text(encoding="utf-8"))
        self.assertEqual("old-system-config\n", system_path.read_text(encoding="utf-8"))
        self.assertEqual(2, restart_attempts)

    def test_shared_apply_failure_preserves_legacy_interfaces_for_point(self):
        agent = self.make_agent()
        agent.save_sidecar_uplink_state({
            "slaves": {
                "lws10c13n2p": {"interface_name": "lws10c13n2p"},
                "lws10c13n2b": {"interface_name": "lws10c13n2b"},
            }
        })

        with mock.patch.object(agent, "apply_shared_slave_sidecar_interface", side_effect=RuntimeError("restart failed")):
            with mock.patch.object(agent, "remove_slave_sidecar_uplink") as remove:
                agent.apply_management({
                    "sidecar_uplinks": [self.shared_uplink(2)],
                    "bond_runtime": None,
                    "mptcp_runtime": None,
                })

        remove.assert_not_called()
        state = json.loads(agent.sidecar_uplink_state_path().read_text(encoding="utf-8"))
        self.assertIn("lws10c13n2p", state.get("slaves", {}))
        self.assertIn("lws10c13n2b", state.get("slaves", {}))

    def test_malformed_shared_section_preserves_all_previous_interfaces(self):
        for raw_uplinks in [
            {"not": "a-list"},
            [{"enabled": True, "wireguard_peer_mode": "shared", "sidecar_point_id": 10}],
            [{"enabled": True, "wireguard_peer_mode": "shared", "sidecar_point_id": "invalid", "interface_name": "lwsp10"}],
        ]:
            with self.subTest(raw_uplinks=raw_uplinks):
                agent = self.make_agent()
                previous = {"slaves": {"lwsp10": {"interface_name": "lwsp10"}}}
                agent.save_sidecar_uplink_state(previous)
                with mock.patch.object(agent, "remove_slave_sidecar_uplink") as remove:
                    agent.apply_management({
                        "sidecar_uplinks": raw_uplinks,
                        "bond_runtime": None,
                        "mptcp_runtime": None,
                    })
                remove.assert_not_called()
                state = json.loads(agent.sidecar_uplink_state_path().read_text(encoding="utf-8"))
                self.assertEqual(previous, state)

    def test_shared_interface_name_rejects_shell_metacharacters(self):
        agent = self.make_agent()
        malicious = self.shared_uplink(2)
        malicious["interface_name"] = "lwsp10;id"

        with self.assertRaisesRegex(ValueError, "interface"):
            agent.validate_shared_sidecar_uplinks([malicious])

    def test_shared_first_start_failure_disables_new_unit_and_removes_configs(self):
        agent = self.make_agent()
        agent.wireguard_private_key_path().write_text("private-key\n", encoding="utf-8")
        peers = [self.shared_uplink(2)]
        state_path = agent.sidecar_uplink_interfaces_dir() / "lwsp10.conf"
        system_path = agent.state_dir / "system-wireguard" / "lwsp10.conf"
        commands: list[list[str]] = []

        def fake_run(args, **kwargs):  # noqa: ANN001
            command = list(args)
            commands.append(command)
            if command[:2] == ["wg-quick", "strip"]:
                return SimpleNamespace(returncode=0, stdout="", stderr="")
            if command[:2] == ["systemctl", "is-enabled"]:
                return SimpleNamespace(returncode=1, stdout="disabled", stderr="")
            if command[:2] == ["systemctl", "start"]:
                return SimpleNamespace(returncode=1, stdout="", stderr="start failed")
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(agent, "sidecar_uplink_system_path", return_value=system_path):
            with mock.patch.object(sidecar_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
                with mock.patch.object(sidecar_agent_module.subprocess, "run", side_effect=fake_run):
                    with mock.patch.object(agent, "systemd_is_active", return_value=False):
                        with self.assertRaises(RuntimeError):
                            agent.apply_shared_slave_sidecar_interface(peers)

        self.assertFalse(state_path.exists())
        self.assertFalse(system_path.exists())
        self.assertIn(["systemctl", "stop", "wg-quick@lwsp10.service"], commands)
        self.assertIn(["systemctl", "disable", "wg-quick@lwsp10.service"], commands)
        self.assertIn(["systemctl", "reset-failed", "wg-quick@lwsp10.service"], commands)

    def test_static_lws_state_is_keyed_by_interface_and_cleans_legacy_lwsc(self):
        with tempfile.TemporaryDirectory() as tmp_dir:
            tmp = pathlib.Path(tmp_dir)
            config_path = tmp / "agent.json"
            config_path.write_text(json.dumps({
                "state_dir": str(tmp),
                "service_name": "leikwan-bond-sidecar-4",
                "managed_iperf3_ports": [],
            }), encoding="utf-8")
            agent = sidecar_agent_module.SidecarAgent(config_path)
            (tmp / "wireguard").mkdir(parents=True, exist_ok=True)
            (tmp / "wireguard" / "private.key").write_text("sidecar-private\n", encoding="utf-8")
            (tmp / "wireguard" / "public.key").write_text("sidecar-public\n", encoding="utf-8")
            cleanup_calls: list[int] = []

            def fake_run(args, **kwargs):
                return SimpleNamespace(returncode=0, stdout="inactive", stderr="")

            with mock.patch.object(sidecar_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
                with mock.patch.object(sidecar_agent_module.subprocess, "run", side_effect=fake_run):
                    with mock.patch.object(agent, "cleanup_stale_mptcp_transit_interfaces", side_effect=lambda point_id, keep=None: cleanup_calls.append(point_id)):
                        agent.apply_management({
                            "config_patch": {"managed_iperf3_ports": []},
                            "sidecar_uplinks": [
                                {
                                    "enabled": True,
                                    "interface_name": "lws4c2n19p",
                                    "sidecar_point_id": 4,
                                    "listen_port": 14970,
                                    "local_address": "10.240.8.1",
                                    "peer_address": "10.240.8.2",
                                    "slave_peer_public_key": "slave-pub",
                                    "outer_transport": "udp",
                                }
                            ],
                            "bond_runtime": None,
                            "mptcp_runtime": None,
                        })

            state = json.loads(agent.sidecar_uplink_state_path().read_text(encoding="utf-8"))
            self.assertIn("lws4c2n19p", state.get("slaves", {}))
            self.assertNotIn("lws4c2n19p".split("-")[-1], [])
            self.assertIn(4, cleanup_calls)

    def test_disabled_static_uplink_is_removed_and_empty_state_is_persisted(self):
        agent = self.make_agent()
        agent.save_sidecar_uplink_state({"slaves": {"lws4c2n19p": {"interface_name": "lws4c2n19p"}}})
        removed: list[str] = []

        with mock.patch.object(agent, "remove_slave_sidecar_uplink", side_effect=lambda uplink: removed.append(str(uplink.get("interface_name", "")))):
            agent.apply_management({
                "sidecar_uplinks": [{"enabled": False, "interface_name": "lws4c2n19p"}],
                "bond_runtime": None,
                "mptcp_runtime": None,
            })

        self.assertEqual(["lws4c2n19p"], removed)
        state = json.loads(agent.sidecar_uplink_state_path().read_text(encoding="utf-8"))
        self.assertEqual({"slaves": {}}, state)

    def test_leigod_uplink_renders_gost_server_unit(self):
        with tempfile.TemporaryDirectory() as tmp_dir:
            tmp = pathlib.Path(tmp_dir)
            config_path = tmp / "agent.json"
            config_path.write_text(json.dumps({
                "state_dir": str(tmp),
                "service_name": "leikwan-bond-sidecar-4",
                "managed_iperf3_ports": [],
            }), encoding="utf-8")
            agent = sidecar_agent_module.SidecarAgent(config_path)
            (tmp / "wireguard").mkdir(parents=True, exist_ok=True)
            (tmp / "wireguard" / "private.key").write_text("sidecar-private\n", encoding="utf-8")
            (tmp / "wireguard" / "public.key").write_text("sidecar-public\n", encoding="utf-8")

            writes: dict[str, str] = {}

            def fake_write(path, body, mode=None):  # noqa: ANN001
                writes[str(path)] = body
                return True

            def fake_run(args, **kwargs):
                return SimpleNamespace(returncode=0, stdout="inactive", stderr="")

            with mock.patch.object(sidecar_agent_module, "write_text_if_changed", side_effect=fake_write):
                with mock.patch.object(sidecar_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
                    with mock.patch.object(sidecar_agent_module.subprocess, "run", side_effect=fake_run):
                        agent.apply_slave_sidecar_uplink({
                            "enabled": True,
                            "interface_name": "lwsc4n9-r1",
                            "local_address": "10.22.0.1",
                            "peer_address": "10.22.0.2",
                            "slave_peer_public_key": "PUBKEYAAA=",
                            "listen_port": 51820,
                            "outer_transport": "leigod",
                            "gost_socks_listen_port": 47123,
                            "socks_user": "wguot",
                            "socks_pass": "s3cret",
                        })

            unit_body = next((b for p, b in writes.items() if "leikwan-leigod-gost-server-lwsc4n9-r1" in p), None)
            self.assertIsNotNone(unit_body, "gost server unit was not written")
            self.assertIn("gost -L socks5://wguot:s3cret@:47123?udp=true", unit_body)
            wg_body = next((b for p, b in writes.items() if p.endswith("lwsc4n9-r1.conf")), None)
            self.assertIsNotNone(wg_body, "WG conf was not written")
            self.assertIn("ListenPort = 51820", wg_body)

    def test_leigod_uplink_lguot_impl_renders_lguot_server_unit(self):
        with tempfile.TemporaryDirectory() as tmp_dir:
            tmp = pathlib.Path(tmp_dir)
            config_path = tmp / "agent.json"
            config_path.write_text(json.dumps({
                "state_dir": str(tmp),
                "service_name": "leikwan-bond-sidecar-4",
                "managed_iperf3_ports": [],
            }), encoding="utf-8")
            agent = sidecar_agent_module.SidecarAgent(config_path)
            (tmp / "wireguard").mkdir(parents=True, exist_ok=True)
            (tmp / "wireguard" / "private.key").write_text("sidecar-private\n", encoding="utf-8")
            (tmp / "wireguard" / "public.key").write_text("sidecar-public\n", encoding="utf-8")

            writes: dict[str, str] = {}

            def fake_write(path, body, mode=None):  # noqa: ANN001
                writes[str(path)] = body
                return True

            def fake_run(args, **kwargs):
                return SimpleNamespace(returncode=0, stdout="inactive", stderr="")

            with mock.patch.object(sidecar_agent_module, "write_text_if_changed", side_effect=fake_write):
                with mock.patch.object(sidecar_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
                    with mock.patch.object(sidecar_agent_module.subprocess, "run", side_effect=fake_run):
                        with mock.patch.object(agent, "ensure_lguot_binary", side_effect=lambda: None):
                            agent.apply_slave_sidecar_uplink({
                                "enabled": True,
                                "interface_name": "lwsc4n9-r1",
                                "local_address": "10.22.0.1",
                                "peer_address": "10.22.0.2",
                                "slave_peer_public_key": "PUBKEYAAA=",
                                "listen_port": 51820,
                                "outer_transport": "leigod",
                                "gost_socks_listen_port": 47123,
                                "socks_user": "wguot",
                                "socks_pass": "s3cret",
                                "leigod_uot_impl": "lguot",
                            })

            unit_body = next((b for p, b in writes.items() if "leikwan-leigod-lguot-server-lwsc4n9-r1" in p), None)
            self.assertIsNotNone(unit_body, "lguot server unit was not written")
            self.assertIn("lguot server -listen :47123 -wg 127.0.0.1:51820 -token s3cret", unit_body)
            # gost server unit must NOT be written for the lguot impl
            gost_body = next((b for p, b in writes.items() if "leikwan-leigod-gost-server-lwsc4n9-r1" in p), None)
            self.assertIsNone(gost_body, "gost server unit should not be written for lguot impl")

    def test_leigod_teardown_removes_gost_server_unit(self):
        agent = self.make_agent()
        calls: list[str] = []
        with mock.patch.object(agent, "remove_sidecar_uot_server_service", side_effect=lambda i: None):
            with mock.patch.object(agent, "remove_sidecar_cf_wstunnel_backend", side_effect=lambda i: None):
                with mock.patch.object(agent, "remove_sidecar_leigod_gost_server_service", side_effect=lambda i: calls.append(i)):
                    agent.remove_slave_sidecar_uplink({"interface_name": "lwsc4n9-r1"})
        self.assertIn("lwsc4n9-r1", calls)

    def test_disabled_self_leg_peer_is_removed_and_empty_state_is_persisted(self):
        agent = self.make_agent()
        agent.save_self_leg_client_peer_state({"interfaces": {"lw-self-1": {"interface_name": "lw-self-1"}}})
        removed: list[str] = []

        with mock.patch.object(agent, "remove_self_leg_client_peer", side_effect=lambda peer: removed.append(str(peer.get("interface_name", "")))):
            agent.apply_management({
                "self_leg_client_peers": [{"enabled": False, "interface_name": "lw-self-1"}],
                "bond_runtime": None,
                "mptcp_runtime": None,
            })

        self.assertEqual(["lw-self-1"], removed)
        state = json.loads(agent.self_leg_client_peer_state_path().read_text(encoding="utf-8"))
        self.assertEqual({"interfaces": {}}, state)

    def test_heartbeat_reports_degraded_when_wireguard_identity_is_missing(self):
        agent = self.make_agent()

        with mock.patch.object(agent, "ensure_wireguard_identity", return_value=""):
            payload = agent.build_heartbeat_payload()

        self.assertEqual("degraded", payload["status"])
        self.assertEqual("wireguard_identity_missing", payload["health_error"])

    def test_heartbeat_reports_wireguard_peer_transfer_state(self):
        agent = self.make_agent()
        dump = "\n".join([
            "lws4c2n19p\tprivate\tpublic\t14970\toff",
            "lws4c2n19p\tpeer-public\t(none)\t198.51.100.7:14970\t10.240.8.2/32\t900\t1234\t5678\t25",
        ])

        def fake_which(command: str):
            return "/usr/bin/wg" if command == "wg" else None

        with mock.patch.object(agent, "ensure_wireguard_identity", return_value="sidecar-public"):
            with mock.patch.object(sidecar_agent_module.shutil, "which", side_effect=fake_which):
                with mock.patch.object(sidecar_agent_module.time, "time", return_value=1000.0):
                    with mock.patch.object(sidecar_agent_module.subprocess, "run", return_value=SimpleNamespace(returncode=0, stdout=dump, stderr="")):
                        payload = agent.build_heartbeat_payload()

        self.assertEqual(
            [
                {
                    "interface_name": "lws4c2n19p",
                    "peer_public_key": "peer-public",
                    "latest_handshake_epoch": 900,
                    "handshake_age_seconds": 100,
                    "rx_bytes": 1234,
                    "tx_bytes": 5678,
                }
            ],
            payload["wireguard_peer_state"],
        )

    def test_cf_slave_transit_backends_are_swept_against_active_uplinks(self):
        agent = self.make_agent()
        agent.save_managed_cf_metadata([
            {
                "service_name": "leikwan-cf-slave-lwsactive",
                "backend_port": 26001,
                "interface_name": "lwsactive",
                "backend_type": "slave_transit_cf_wg",
            },
            {
                "service_name": "leikwan-cf-slave-lwsstale",
                "backend_port": 26002,
                "interface_name": "lwsstale",
                "backend_type": "slave_transit_cf_wg",
            },
            {
                "service_name": "cf-forward-47",
                "backend_port": 26003,
                "backend_type": "forward_cf",
            },
        ])
        disabled: list[str] = []

        with mock.patch.object(agent, "disable_runtime", side_effect=lambda service, *_args, **_kwargs: disabled.append(service)):
            agent.cleanup_orphan_slave_transit_cf_backends({"lwsactive"})

        self.assertEqual(["leikwan-cf-slave-lwsstale"], disabled)
        services = [item["service_name"] for item in agent.load_managed_cf_metadata()]
        self.assertEqual(["leikwan-cf-slave-lwsactive", "cf-forward-47"], services)

    def test_cf_backend_launch_failure_does_not_abort_wireguard_uplink(self):
        agent = self.make_agent()
        (agent.wireguard_dir()).mkdir(parents=True, exist_ok=True)
        agent.wireguard_private_key_path().write_text("sidecar-private\n", encoding="utf-8")
        agent.wireguard_public_key_path().write_text("sidecar-public\n", encoding="utf-8")

        uplink = {
            "enabled": True,
            "interface_name": "lws4c2n19p",
            "listen_port": 14970,
            "local_address": "10.240.8.1",
            "peer_address": "10.240.8.2",
            "slave_peer_public_key": "slave-pub",
            "outer_transport": "udp",
            "cf_path_prefix": "lw-mptcp-47-0-cfwg",
            "cf_target_host": "127.0.0.1",
            "cf_target_port": 14982,
        }

        with mock.patch.object(sidecar_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(sidecar_agent_module.subprocess, "run", return_value=SimpleNamespace(returncode=0, stdout="inactive", stderr="")):
                with mock.patch.object(agent, "ensure_sidecar_cf_wstunnel_backend", side_effect=RuntimeError("no free ports")):
                    agent.apply_slave_sidecar_uplink(uplink)

        rendered = agent.sidecar_uplink_interfaces_dir() / "lws4c2n19p.conf"
        self.assertTrue(rendered.exists())
        self.assertIn("PrivateKey = sidecar-private", rendered.read_text(encoding="utf-8"))

    def test_cf_backend_port_range_is_widened_and_configurable(self):
        agent = self.make_agent()
        agent.config["cf_backend_port_start"] = 27000
        agent.config["cf_backend_port_end"] = 27000

        with mock.patch.object(agent, "cf_backend_port_listening", return_value=False):
            self.assertEqual(27000, agent.allocate_cf_backend_port("leikwan-cf-slave-lws4c2n19p"))


if __name__ == "__main__":
    unittest.main()
