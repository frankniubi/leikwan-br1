import importlib.util
import json
import pathlib
import sys
import tempfile
import unittest
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


slave_agent_module = load_module("slave_agent_mptcp_uplink_under_test", "slave-agent/slave_agent.py")


class SlaveMptcpUnderlayLeigodUplinkTests(unittest.TestCase):
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

    def test_true_mptcp_underlay_applies_leigod_static_sidecar_uplink_before_return(self):
        agent = self.make_agent()
        task = {"service_id": 6, "forward_id": 59, "endpoint_id": 571}
        mptcp_underlay = {
            "enabled": True,
            "uses_static_sidecar_uplink": True,
            "interface_name": "lwf59n571",
        }
        sidecar_uplink = {
            "enabled": True,
            "outer_transport": "leigod",
            "interface_name": "lws6c59n571p",
        }
        payload = {
            "target": "10.240.8.1:9000",
            "listen_port": 7000,
            "path_prefix": "f59",
            "transport_mode": "tcp+udp",
            "publish_bond_runtime": False,
            "mptcp_underlay": mptcp_underlay,
            "sidecar_uplink": sidecar_uplink,
        }

        with mock.patch.object(agent, "cleanup_superseded_services_for_forward"):
            with mock.patch.object(agent, "apply_mptcp_underlay") as apply_mptcp_underlay:
                with mock.patch.object(agent, "apply_sidecar_uplink") as apply_sidecar_uplink:
                    with mock.patch.object(agent, "ensure_sidecar_uplink_selector"):
                        with mock.patch.object(agent, "remove_sidecar_uplink_selector"):
                            agent.publish_task(task, payload)

        apply_mptcp_underlay.assert_called_once_with("wstunnel-svc-6-571", mptcp_underlay)
        apply_sidecar_uplink.assert_called_once_with("wstunnel-svc-6-571", sidecar_uplink)


if __name__ == "__main__":
    unittest.main()
