import importlib.util
import json
import pathlib
import sys
import tempfile
import unittest
from unittest import mock


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(module_name: str, relative_path: str):
    path = REPO_ROOT / relative_path
    spec = importlib.util.spec_from_file_location(module_name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[module_name] = module
    spec.loader.exec_module(module)
    return module


slave_agent_module = load_module("slave_agent_tcp_accel_under_test", "slave-agent/slave_agent.py")

SIDEPATH = {
    "sidecar_server": "89.144.30.80:29030",
    "bridges": {
        "121": {
            "remote": "89.144.30.80:29030",
            "listeners": [
                {"line_id": 455, "listen_port": 7455},
                {"line_id": 693, "listen_port": 7693},
            ],
        }
    },
}


class SlaveTcpAccelBridgeTests(unittest.TestCase):
    def make_agent(self) -> slave_agent_module.SlaveAgent:
        tmp_dir = tempfile.TemporaryDirectory()
        self.addCleanup(tmp_dir.cleanup)
        runtime_helper = pathlib.Path(tmp_dir.name) / "leigod_tcp_accel_bridge.py"
        runtime_helper.write_text("# stub\n", encoding="utf-8")
        config_path = pathlib.Path(tmp_dir.name) / "config.json"
        config_path.write_text(
            json.dumps(
                {
                    "master_url": "https://panel.example.com",
                    "node_token": "token",
                    "state_dir": str(pathlib.Path(tmp_dir.name) / "state"),
                    "systemd_dir": str(pathlib.Path(tmp_dir.name) / "systemd"),
                    "ingress_bin": "/usr/local/bin/leikwan-ingress",
                    "tcp_accel_bridge_bin": str(runtime_helper),
                    "tcp_accel_config_dir": str(pathlib.Path(tmp_dir.name) / "tcp-accel"),
                    "tcp_accel_sidepath": SIDEPATH,
                }
            ),
            encoding="utf-8",
        )
        return slave_agent_module.SlaveAgent(config_path)

    def test_reconcile_writes_bridge_config_and_unit(self) -> None:
        agent = self.make_agent()
        with mock.patch.object(slave_agent_module.shutil, "which", return_value=None):
            agent.reconcile_tcp_accel_bridge({"forward_id": 121})

        config_dir = pathlib.Path(str(agent.config["tcp_accel_config_dir"]))
        payload = json.loads((config_dir / "bridge.json").read_text(encoding="utf-8"))
        self.assertEqual(payload["remote"], "89.144.30.80:29030")
        # SOCKS dial addresses resolve to the slave-local line listeners.
        self.assertEqual(
            payload["listeners"],
            [
                {"listen": "0.0.0.0:7455", "socks": "127.0.0.1:20455", "line_id": 455},
                {"listen": "0.0.0.0:7693", "socks": "127.0.0.1:20693", "line_id": 693},
            ],
        )
        unit_path = pathlib.Path(str(agent.config["systemd_dir"])) / "leikwan-tcp-accel-bridge.service"
        unit = unit_path.read_text(encoding="utf-8")
        self.assertIn("bridge --config", unit)
        self.assertIn("leigod_tcp_accel_bridge.py", unit)

    def test_two_forwards_sharing_a_line_merge_into_one_unit(self) -> None:
        agent = self.make_agent()
        agent.config["tcp_accel_sidepath"] = {
            "bridges": {
                "121": {
                    "remote": "89.144.30.80:29030",
                    "listeners": [{"line_id": 455, "listen_port": 7455}],
                },
                "122": {
                    "remote": "89.144.30.80:29030",
                    "listeners": [{"line_id": 455, "listen_port": 7455}],
                },
            }
        }
        with mock.patch.object(slave_agent_module.shutil, "which", return_value=None):
            agent.reconcile_tcp_accel_bridge({"forward_id": 121})
        config_dir = pathlib.Path(str(agent.config["tcp_accel_config_dir"]))
        payload = json.loads((config_dir / "bridge.json").read_text(encoding="utf-8"))
        # One listener per line, not one per (forward, line): two units binding
        # the same deterministic port would crash-loop on EADDRINUSE.
        self.assertEqual(len(payload["listeners"]), 1)

    def test_reconcile_without_entry_removes_bridge(self) -> None:
        agent = self.make_agent()
        config_dir = pathlib.Path(str(agent.config["tcp_accel_config_dir"]))
        config_dir.mkdir(parents=True, exist_ok=True)
        (config_dir / "bridge.json").write_text("{}", encoding="utf-8")
        agent.config["tcp_accel_sidepath"] = {"bridges": {}}
        with mock.patch.object(slave_agent_module.shutil, "which", return_value=None):
            agent.remove_tcp_accel_bridge()
        self.assertFalse((config_dir / "bridge.json").exists())

    def test_apply_config_patch_accepts_tcp_accel_sidepath(self) -> None:
        tmp_dir = tempfile.TemporaryDirectory()
        self.addCleanup(tmp_dir.cleanup)
        config_path = pathlib.Path(tmp_dir.name) / "config.json"
        config_path.write_text(
            json.dumps(
                {
                    "master_url": "https://panel.example.com",
                    "node_token": "token",
                    "state_dir": str(pathlib.Path(tmp_dir.name) / "state"),
                    "systemd_dir": str(pathlib.Path(tmp_dir.name) / "systemd"),
                    "ingress_bin": "/usr/local/bin/leikwan-ingress",
                }
            ),
            encoding="utf-8",
        )
        agent = slave_agent_module.SlaveAgent(config_path)
        with mock.patch.object(slave_agent_module.shutil, "which", return_value=None):
            agent.apply_config_patch({"tcp_accel_sidepath": SIDEPATH})
        persisted = json.loads(config_path.read_text(encoding="utf-8"))
        self.assertEqual(persisted["tcp_accel_sidepath"], SIDEPATH)
        with mock.patch.object(slave_agent_module.shutil, "which", return_value=None):
            agent.apply_config_patch({"tcp_accel_sidepath": None})
        persisted = json.loads(config_path.read_text(encoding="utf-8"))
        self.assertNotIn("tcp_accel_sidepath", persisted)

    def test_reap_removes_unlisted_bridge_units(self) -> None:
        agent = self.make_agent()
        systemd_dir = pathlib.Path(str(agent.config["systemd_dir"]))
        systemd_dir.mkdir(parents=True, exist_ok=True)
        legacy_unit = systemd_dir / "leikwan-tcp-accel-bridge-99.service"
        legacy_unit.write_text("[Unit]\n", encoding="utf-8")
        with mock.patch.object(slave_agent_module.shutil, "which", return_value=None):
            agent.reap_stale_tcp_accel_bridges()
        self.assertFalse(legacy_unit.exists())
        # The merged unit survives while the panel still lists entries.
        kept_unit = systemd_dir / "leikwan-tcp-accel-bridge.service"
        kept_unit.write_text("[Unit]\n", encoding="utf-8")
        with mock.patch.object(slave_agent_module.shutil, "which", return_value=None):
            agent.reap_stale_tcp_accel_bridges()
        self.assertTrue(kept_unit.exists())
        # And is reaped once the panel lists nothing.
        agent.config["tcp_accel_sidepath"] = {"bridges": {}}
        with mock.patch.object(slave_agent_module.shutil, "which", return_value=None):
            agent.reap_stale_tcp_accel_bridges()
        self.assertFalse(kept_unit.exists())

    def test_published_slave_agent_is_byte_identical(self) -> None:
        source = (REPO_ROOT / "slave-agent/slave_agent.py").read_bytes()
        published = (REPO_ROOT / "artifacts/slave-agent.py").read_bytes()
        self.assertEqual(source, published)


if __name__ == "__main__":
    unittest.main()
