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
                    with mock.patch.object(agent, "cleanup_stale_mptcp_transit_interfaces", side_effect=lambda point_id: cleanup_calls.append(point_id)):
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


if __name__ == "__main__":
    unittest.main()
