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


slave_agent_module = load_module("slave_agent_overlay_under_test", "slave-agent/slave_agent.py")


class SlaveLeigodOverlayRoutingTests(unittest.TestCase):
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

    def test_leigod_uplink_carries_overlay_sidecar_in_allowedips(self):
        # The leigod static uplink must carry the MPTCP overlay sidecar address(es)
        # so the slave routes overlay traffic (dst = sidecar overlay addr) to the
        # sidecar over the leigod hop. Without this AllowedIP, wg-quick installs no
        # route and the overlay blackholes out eth0 (the production bug).
        agent = self.make_agent()
        agent.wireguard_private_key_path().parent.mkdir(parents=True, exist_ok=True)
        agent.wireguard_private_key_path().write_text("slave-private\n", encoding="utf-8")
        written: dict[str, str] = {}
        uplink = {
            "enabled": True,
            "interface_name": "lws5c5n20p",
            "local_address": "10.240.29.6",
            "peer_address": "10.240.29.5",
            "sidecar_peer_public_key": "sidecar-pub",
            "outer_transport": "leigod",
            "local_listen_port": 43040,
            "gost_listen_udp_port": 43040,
            "sidecar_wg_listen_port": 14980,
            "leigod_line_id": 453,
            "sidecar_gost_host": "185.220.238.143",
            "sidecar_gost_port": 47980,
            "socks_user": "wguot",
            "socks_pass": "s3cret",
            "leigod_uot_impl": "lguot",
            "mtu": 1280,
            "overlay_peer_allowed_ips": ["10.203.28.159"],
        }

        def fake_run(command: str, check: bool = True):
            return SimpleNamespace(returncode=0, stdout="", stderr="")

        with mock.patch.object(slave_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
            with mock.patch.object(slave_agent_module, "run_command", side_effect=fake_run):
                with mock.patch.object(slave_agent_module.time, "time", return_value=1000):
                    with mock.patch.object(agent, "ensure_wireguard_identity", return_value="slave-pub"):
                        with mock.patch.object(agent, "write_text_if_changed", side_effect=lambda path, content: written.setdefault(str(path), content) is not None):
                            with mock.patch.object(agent, "ensure_leigod_line_service"):
                                with mock.patch.object(agent, "ensure_lguot_binary"):
                                    agent.apply_sidecar_uplink("lws5c5n20p", uplink)

        rendered = "\n".join(written.values())
        self.assertIn("AllowedIPs = 10.240.29.5/32, 10.203.28.159/32", rendered)


if __name__ == "__main__":
    unittest.main()
