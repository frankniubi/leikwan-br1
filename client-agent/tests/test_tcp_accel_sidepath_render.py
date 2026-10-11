import importlib.util
import json
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


runtime = load_module(
    "install_forward_runtime_tcp_accel_under_test", "client-agent/install_forward_runtime.py"
)


def forward_fixture() -> dict:
    return {
        "forward_id": 121,
        "listen_port": 5201,
        "mode": "bond",
        "transport": "tcp+udp",
        "target_host": "89.144.30.119",
        "target_port": 5201,
    }


def agent_config_fixture(entry: dict | None = None) -> dict:
    forwards_payload = {} if entry is None else {"121": entry}
    return {
        "tcp_accel_sidepath": {
            "enabled_forward_ids": [121],
            "token": "tok-1",
            "lanes": 4,
            "minimum_lanes": 2,
            "join_timeout_ms": 1200,
            "chunk_size": 65536,
            "lane_queue_frames": 16,
            "tcp_user_timeout_ms": 30000,
            "forwards": forwards_payload,
        }
    }


class TcpAccelSidepathSettingsTests(unittest.TestCase):
    def test_disabled_or_absent_returns_none(self) -> None:
        self.assertIsNone(runtime.tcp_accel_sidepath_settings(forward_fixture(), {}))
        self.assertIsNone(
            runtime.tcp_accel_sidepath_settings(forward_fixture(), {"tcp_accel_sidepath": {}})
        )
        other = forward_fixture()
        other["forward_id"] = 999
        self.assertIsNone(runtime.tcp_accel_sidepath_settings(other, agent_config_fixture(_entry())))

    def test_valid_config_is_parsed(self) -> None:
        settings = runtime.tcp_accel_sidepath_settings(forward_fixture(), agent_config_fixture(_entry()))
        self.assertEqual(settings["target"], "89.144.30.119:5201")
        self.assertEqual(settings["remotes"], ["101.133.142.65:7455"])
        self.assertEqual(settings["lanes"], 4)
        self.assertEqual(settings["minimum_lanes"], 2)
        self.assertEqual(settings["token"], "tok-1")

    def test_invalid_remote_or_target_raises(self) -> None:
        with self.assertRaises(ValueError):
            runtime.tcp_accel_sidepath_settings(
                forward_fixture(), agent_config_fixture(_entry(remotes=["bad-host:99999"]))
            )
        with self.assertRaises(ValueError):
            runtime.tcp_accel_sidepath_settings(
                forward_fixture(), agent_config_fixture(_entry(target="no-port"))
            )
        with self.assertRaises(ValueError):
            runtime.tcp_accel_sidepath_settings(
                forward_fixture(), agent_config_fixture(_entry(remotes=[]))
            )

    @unittest.skipUnless(
        (REPO_ROOT / "artifacts/install-forward-runtime.py").is_file(),
        "release mirror artifacts/install-forward-runtime.py is not tracked in git; runs on the release machine",
    )
    def test_published_installer_is_byte_identical(self) -> None:
        source = (REPO_ROOT / "client-agent/install_forward_runtime.py").read_bytes()
        published = (REPO_ROOT / "artifacts/install-forward-runtime.py").read_bytes()
        self.assertEqual(source, published)


class TcpAccelClientUnitTests(unittest.TestCase):
    def test_unit_renders_valid_tcp_accel_config(self) -> None:
        settings = runtime.tcp_accel_sidepath_settings(forward_fixture(), agent_config_fixture(_entry()))
        unit = runtime.render_tcp_accel_client_unit(forward_fixture(), agent_config_fixture(_entry()), settings)
        self.assertEqual(unit["service_name"], "leikwan-client-121-tcp-accel")
        self.assertEqual(
            unit["service_path"], "/etc/systemd/system/leikwan-client-121-tcp-accel.service"
        )
        self.assertIn("tcp_accel.py --log-level INFO client-config --config", unit["service_unit"])
        payload = json.loads(unit["files"][0]["content"])
        self.assertEqual(payload["remotes"], ["101.133.142.65:7455"])
        self.assertEqual(payload["lanes"], 4)
        self.assertEqual(payload["token"], "tok-1")
        self.assertEqual(
            payload["forwards"],
            [{"name": "forward-121", "listen": "0.0.0.0:5201", "target": "89.144.30.119:5201"}],
        )

    def test_zero_user_timeout_and_missing_token_are_omitted(self) -> None:
        config = agent_config_fixture(_entry())
        config["tcp_accel_sidepath"]["tcp_user_timeout_ms"] = 0
        config["tcp_accel_sidepath"]["token"] = None
        settings = runtime.tcp_accel_sidepath_settings(forward_fixture(), config)
        unit = runtime.render_tcp_accel_client_unit(forward_fixture(), config, settings)
        payload = json.loads(unit["files"][0]["content"])
        self.assertNotIn("tcp_user_timeout_ms", payload)
        self.assertNotIn("token", payload)


def _entry(remotes: list[str] | None = None, target: str | None = None) -> dict:
    return {
        "target": target or "89.144.30.119:5201",
        "remotes": remotes if remotes is not None else ["101.133.142.65:7455"],
    }


if __name__ == "__main__":
    unittest.main()
