import importlib.util
import pathlib
import sys
import unittest

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(module_name: str, relative_path: str):
    path = REPO_ROOT / relative_path
    spec = importlib.util.spec_from_file_location(module_name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[module_name] = module
    spec.loader.exec_module(module)
    return module


sidecar_agent = load_module("sidecar_agent_iperf_under_test", "sidecar-agent/sidecar_agent.py")


class ManagedIperf3ServerTests(unittest.TestCase):
    def test_render_managed_iperf3_unit_uses_requested_port_and_persistent_server(self) -> None:
        unit = sidecar_agent.SidecarAgent.render_managed_iperf3_unit(
            service_name="leikwan-bond-sidecar-5-iperf3-5201",
            iperf3_bin="/usr/bin/iperf3",
            port=5201,
        )

        self.assertIn("ExecStart=/usr/bin/iperf3 -s -p 5201", unit)
        self.assertIn("Restart=always", unit)
        # Persistent server: a forward target must not exit after one session
        # (--one-off leaves a ~RestartSec gap that drops in-flight forward traffic
        # and makes back-to-back iperf3 probes fail with "control socket closed").
        self.assertNotIn("--one-off", unit)
        self.assertNotIn("127.0.0.1", unit)

    def test_apply_config_patch_persists_disable_auto_update(self) -> None:
        import tempfile
        with tempfile.TemporaryDirectory() as tmp_dir:
            tmp = pathlib.Path(tmp_dir)
            config_path = tmp / "config.json"
            state_dir = tmp / "state"
            config_path.write_text(
                '{"master_url":"http://panel","agent_token":"token","state_dir":"%s"}' % str(state_dir).replace('\\', '/'),
                encoding="utf-8",
            )
            agent = sidecar_agent.SidecarAgent(config_path)

            agent.apply_config_patch({"disable_auto_update": True, "managed_iperf3_ports": [5201]})

            self.assertTrue(agent.config["disable_auto_update"])
            self.assertEqual([5201], agent.config["managed_iperf3_ports"])


if __name__ == "__main__":
    unittest.main()
