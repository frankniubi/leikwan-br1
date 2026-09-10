import importlib.util
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


sidecar_agent_module = load_module("sidecar_agent_self_leg_wg_under_test", "sidecar-agent/sidecar_agent.py")


class SidecarSelfLegWireGuardTests(unittest.TestCase):
    def test_apply_management_renders_self_leg_client_peer_and_restarts_when_changed(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            tmp = pathlib.Path(tmp_dir)
            config_path = tmp / "agent.json"
            config_path.write_text(
                "{\"state_dir\": \"%s\", \"service_name\": \"leikwan-bond-sidecar-5\"}" % str(tmp).replace("\\", "\\\\"),
                encoding="utf-8",
            )
            agent = sidecar_agent_module.SidecarAgent(config_path)
            (tmp / "wireguard").mkdir(parents=True, exist_ok=True)
            (tmp / "wireguard" / "private.key").write_text("sidecar-private\n", encoding="utf-8")
            (tmp / "wireguard" / "public.key").write_text("sidecar-public\n", encoding="utf-8")
            commands = []

            def fake_run(args, **kwargs):
                commands.append(args if isinstance(args, list) else str(args))
                return SimpleNamespace(returncode=0, stdout="inactive", stderr="")

            with mock.patch.object(sidecar_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
                with mock.patch.object(sidecar_agent_module.subprocess, "run", side_effect=fake_run):
                    agent.apply_management(
                        {
                            "config_patch": {"managed_iperf3_ports": []},
                            "self_leg_client_peers": [
                                {
                                    "enabled": True,
                                    "forward_id": 47,
                                    "client_id": 3,
                                    "interface_name": "lw-self-47",
                                    "listen_port": 14969,
                                    "sidecar_address": "10.204.47.2",
                                    "client_address": "10.204.47.1",
                                    "client_public_key": "client-pub",
                                    "persistent_keepalive": 25,
                                }
                            ],
                            "bond_runtime": None,
                            "mptcp_runtime": None,
                        }
                    )

            system_conf = pathlib.Path("/etc/wireguard/lw-self-47.conf")
            local_conf = tmp / "wireguard" / "self-leg-clients" / "lw-self-47.conf"
            self.assertTrue(local_conf.exists())
            rendered = local_conf.read_text(encoding="utf-8")
            self.assertIn("PrivateKey = sidecar-private", rendered)
            self.assertIn("ListenPort = 14969", rendered)
            self.assertIn("Address = 10.204.47.2/32", rendered)
            self.assertIn("PublicKey = client-pub", rendered)
            self.assertIn("AllowedIPs = 10.204.47.1/32", rendered)
            self.assertIn("PersistentKeepalive = 25", rendered)
            command_strings = [" ".join(cmd) if isinstance(cmd, list) else cmd for cmd in commands]
            self.assertTrue(any("wg-quick@lw-self-47.service" in line for line in command_strings))


    def test_apply_management_restarts_unchanged_inactive_self_leg_peer(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            tmp = pathlib.Path(tmp_dir)
            config_path = tmp / "agent.json"
            config_path.write_text(
                "{\"state_dir\": \"%s\", \"service_name\": \"leikwan-bond-sidecar-5\"}" % str(tmp).replace("\\", "\\\\"),
                encoding="utf-8",
            )
            agent = sidecar_agent_module.SidecarAgent(config_path)
            (tmp / "wireguard").mkdir(parents=True, exist_ok=True)
            (tmp / "wireguard" / "private.key").write_text("sidecar-private\n", encoding="utf-8")
            (tmp / "wireguard" / "public.key").write_text("sidecar-public\n", encoding="utf-8")
            commands = []

            def fake_run(args, **kwargs):
                commands.append(args if isinstance(args, list) else str(args))
                return SimpleNamespace(returncode=0, stdout="inactive", stderr="")

            with mock.patch.object(sidecar_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
                with mock.patch.object(sidecar_agent_module, "write_text_if_changed", return_value=False):
                    with mock.patch.object(agent, "systemd_is_active", return_value=False):
                        with mock.patch.object(sidecar_agent_module.subprocess, "run", side_effect=fake_run):
                            agent.apply_management(
                                {
                                    "config_patch": {"managed_iperf3_ports": []},
                                    "self_leg_client_peers": [
                                        {
                                            "enabled": True,
                                            "forward_id": 47,
                                            "client_id": 3,
                                            "interface_name": "lw-self-47",
                                            "listen_port": 14969,
                                            "sidecar_address": "10.204.47.2",
                                            "client_address": "10.204.47.1",
                                            "client_public_key": "client-pub",
                                            "persistent_keepalive": 25,
                                        }
                                    ],
                                    "bond_runtime": None,
                                    "mptcp_runtime": None,
                                }
                            )

            command_strings = [" ".join(cmd) if isinstance(cmd, list) else cmd for cmd in commands]
            self.assertTrue(any("enable wg-quick@lw-self-47.service" in line for line in command_strings))
            self.assertTrue(any("restart wg-quick@lw-self-47.service" in line for line in command_strings))

    def test_apply_management_restarts_unchanged_inactive_slave_sidecar_uplink(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            tmp = pathlib.Path(tmp_dir)
            config_path = tmp / "agent.json"
            config_path.write_text(
                "{\"state_dir\": \"%s\", \"service_name\": \"leikwan-bond-sidecar-5\"}" % str(tmp).replace("\\", "\\\\"),
                encoding="utf-8",
            )
            agent = sidecar_agent_module.SidecarAgent(config_path)
            (tmp / "wireguard").mkdir(parents=True, exist_ok=True)
            (tmp / "wireguard" / "private.key").write_text("sidecar-private\n", encoding="utf-8")
            (tmp / "wireguard" / "public.key").write_text("sidecar-public\n", encoding="utf-8")
            commands = []

            def fake_run(args, **kwargs):
                commands.append(args if isinstance(args, list) else str(args))
                return SimpleNamespace(returncode=0, stdout="inactive", stderr="")

            with mock.patch.object(sidecar_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
                with mock.patch.object(sidecar_agent_module, "write_text_if_changed", return_value=False):
                    with mock.patch.object(agent, "systemd_is_active", return_value=False):
                        with mock.patch.object(sidecar_agent_module.subprocess, "run", side_effect=fake_run):
                            agent.apply_management(
                                {
                                    "config_patch": {"managed_iperf3_ports": []},
                                    "sidecar_uplinks": [
                                        {
                                            "enabled": True,
                                            "interface_name": "lw-sc-5-0",
                                            "listen_port": 14983,
                                            "local_address": "10.203.201.135",
                                            "peer_address": "10.203.201.134",
                                            "slave_peer_public_key": "slave-pub",
                                            "outer_transport": "udp",
                                            "peer_keepalive_seconds": 0,
                                        }
                                    ],
                                    "bond_runtime": None,
                                    "mptcp_runtime": None,
                                }
                            )

            command_strings = [" ".join(cmd) if isinstance(cmd, list) else cmd for cmd in commands]
            self.assertTrue(any("enable wg-quick@lw-sc-5-0.service" in line for line in command_strings))
            self.assertTrue(any("restart wg-quick@lw-sc-5-0.service" in line for line in command_strings))



if __name__ == "__main__":
    unittest.main()
