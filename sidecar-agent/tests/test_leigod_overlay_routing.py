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


sidecar_agent_module = load_module("sidecar_agent_overlay_under_test", "sidecar-agent/sidecar_agent.py")


class SidecarLeigodOverlayRoutingTests(unittest.TestCase):
    def test_leigod_uplink_owns_overlay_sidecar_addr_and_routes_overlay_client(self):
        # For MPTCP-over-leigod, the sidecar end of the static uplink must (a) OWN the
        # overlay sidecar address so its 0.0.0.0:8898 MPTCP listener receives the subflow,
        # and (b) carry the overlay client address in AllowedIPs so the return path routes
        # back over the leigod hop. Without this the overlay blackholes (production bug).
        with tempfile.TemporaryDirectory() as tmp_dir:
            tmp = pathlib.Path(tmp_dir)
            config_path = tmp / "agent.json"
            config_path.write_text(json.dumps({
                "state_dir": str(tmp),
                "service_name": "leikwan-bond-sidecar-5",
                "managed_iperf3_ports": [],
            }), encoding="utf-8")
            agent = sidecar_agent_module.SidecarAgent(config_path)
            (tmp / "wireguard").mkdir(parents=True, exist_ok=True)
            (tmp / "wireguard" / "private.key").write_text("sidecar-private\n", encoding="utf-8")
            (tmp / "wireguard" / "public.key").write_text("sidecar-public\n", encoding="utf-8")
            written: dict[str, str] = {}

            uplink = {
                "enabled": True,
                "interface_name": "lws5c5n20p",
                "local_address": "10.240.29.5",
                "peer_address": "10.240.29.6",
                "slave_peer_public_key": "slave-pub",
                "listen_port": 14980,
                "outer_transport": "leigod",
                "gost_socks_listen_port": 47980,
                "socks_user": "wguot",
                "socks_pass": "s3cret",
                "leigod_uot_impl": "lguot",
                "overlay_peer_allowed_ips": ["10.203.28.157"],
                "overlay_local_addresses": ["10.203.28.159"],
            }

            def fake_run(args, **kwargs):
                return SimpleNamespace(returncode=0, stdout="inactive", stderr="")

            with mock.patch.object(sidecar_agent_module.shutil, "which", side_effect=lambda cmd: "/usr/bin/" + cmd):
                with mock.patch.object(sidecar_agent_module.subprocess, "run", side_effect=fake_run):
                    with mock.patch.object(sidecar_agent_module, "write_text_if_changed", side_effect=lambda path, content, mode=0o600: written.setdefault(str(path), content) is not None):
                        with mock.patch.object(agent, "ensure_wireguard_identity", return_value="sidecar-public"):
                            with mock.patch.object(agent, "ensure_sidecar_leigod_lguot_server_service"):
                                with mock.patch.object(agent, "remove_sidecar_leigod_gost_server_service"):
                                    agent.apply_slave_sidecar_uplink(uplink)

            rendered = "\n".join(written.values())
            self.assertIn("Address = 10.203.28.159/32", rendered)
            self.assertIn("AllowedIPs = 10.240.29.6/32, 10.203.28.157/32", rendered)


if __name__ == "__main__":
    unittest.main()
