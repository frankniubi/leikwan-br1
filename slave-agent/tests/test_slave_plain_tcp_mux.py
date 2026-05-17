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


slave_agent_module = load_module(
    "slave_agent_plain_tcp_mux_under_test",
    "slave-agent/slave_agent.py",
)


class SlavePlainTcpMuxTests(unittest.TestCase):
    def make_agent(self) -> slave_agent_module.SlaveAgent:
        tmp_dir = tempfile.TemporaryDirectory()
        self.addCleanup(tmp_dir.cleanup)
        root = pathlib.Path(tmp_dir.name)
        config_path = root / "config.json"
        config_path.write_text(
            json.dumps(
                {
                    "master_url": "https://panel.example.com",
                    "node_token": "token",
                    "state_dir": str(root / "state"),
                    "systemd_dir": str(root / "systemd"),
                    "backend_port_range_start": 20000,
                    "backend_port_range_end": 20010,
                }
            ),
            encoding="utf-8",
        )
        return slave_agent_module.SlaveAgent(config_path)

    def test_listener_routes_include_plain_tcp_fields(self) -> None:
        agent = self.make_agent()
        service_name = slave_agent_module.safe_name(1, 190)
        agent.write_json(
            agent.metadata_path(service_name),
            {
                "service_name": service_name,
                "service_id": 1,
                "forward_id": 26,
                "endpoint_id": 190,
                "listen_port": 7001,
                "backend_port": 6002,
                "target": "138.252.162.120:9000",
                "path_prefix": "a43547b6350f72cd",
                "route_key": "a43547b6350f72cd",
                "ingress_protocol": "plain_tcp_mux",
                "ingress_auth_token": "token-123",
                "backend_protocol": "tcp_relay",
                "transport_mode": "tcp+udp",
                "relay_protocols": [],
                "relay_target": "",
                "relay_service_name": "",
                "relay_client_service_name": "",
                "relay_client_port": 0,
                "mptcp_underlay": {},
                "filter_profile": "off",
                "status": "active",
            },
        )

        routes = agent.listener_routes(7001)
        self.assertEqual(routes[0]["route_key"], "a43547b6350f72cd")
        self.assertEqual(routes[0]["ingress_auth_token"], "token-123")
        self.assertEqual(routes[0]["ingress_protocol"], "plain_tcp_mux")

    def test_publish_task_uses_plain_tcp_backend_unit(self) -> None:
        agent = self.make_agent()
        task = {
            "service_id": 1,
            "forward_id": 26,
            "endpoint_id": 190,
            "payload": {},
        }
        payload = {
            "target": "138.252.162.120:9000",
            "listen_port": 7001,
            "path_prefix": "a43547b6350f72cd",
            "route_key": "a43547b6350f72cd",
            "transport_mode": "tcp+udp",
            "filter_profile": "off",
            "ingress_protocol": "plain_tcp_mux",
            "backend_protocol": "tcp_relay",
            "ingress_auth_token": "token-123",
        }

        with mock.patch.object(slave_agent_module.shutil, "which", return_value=None):
            with mock.patch.object(agent, "ensure_nft_table"):
                with mock.patch.object(agent, "delete_nft_rules"):
                    with mock.patch.object(agent, "add_nft_rules"):
                        with mock.patch.object(agent, "ensure_backend_service"):
                            with mock.patch.object(agent, "write_filter_marker", return_value={"filter_profile": "off"}):
                                agent.publish_task(task, payload)

        unit_body = agent.service_unit_path(slave_agent_module.safe_name(1, 190)).read_text(encoding="utf-8")
        self.assertIn("leikwan-ingress", unit_body)
        self.assertIn("backend --listen", unit_body)
        self.assertIn("--target", unit_body)

    def test_mptcp_preferred_bond_payload_with_underlay_still_renders_plain_tcp_listener(self) -> None:
        agent = self.make_agent()
        agent.config["backend_port_range_start"] = 7000
        agent.config["backend_port_range_end"] = 7004
        task = {
            "service_id": 2,
            "forward_id": 47,
            "endpoint_id": 316,
            "payload": {},
        }
        payload = {
            "target": "127.0.0.1:8899",
            "listen_port": 7000,
            "path_prefix": "07289d1f5ed25399",
            "route_key": "07289d1f5ed25399",
            "transport_mode": "tcp",
            "filter_profile": "off",
            "ingress_protocol": "plain_tcp_mux",
            "backend_protocol": "wss_backend",
            "ingress_auth_token": "token-316",
            "publish_bond_runtime": True,
            "mptcp_underlay": {
                "enabled": True,
                "interface_name": "lwf47n12",
                "slave_address": "10.203.201.174",
                "client_address": "10.203.201.173",
                "sidecar_address": "10.203.201.175",
                "client_public_key": "client-public-key",
                "sidecar_public_key": "sidecar-public-key",
            },
        }

        with mock.patch.object(slave_agent_module.shutil, "which", return_value=None):
            with mock.patch.object(agent, "ensure_nft_table"):
                with mock.patch.object(agent, "delete_nft_rules"):
                    with mock.patch.object(agent, "add_nft_rules"):
                        with mock.patch.object(agent, "ensure_backend_service"):
                            with mock.patch.object(agent, "write_filter_marker", return_value={"filter_profile": "off"}):
                                result = agent.publish_task(task, payload)

        self.assertGreater(result["backend_port"], 0)
        self.assertEqual("leikwan-tcp-mux-7000", result["mux_service"])
        routes = agent.listener_routes(7000)
        self.assertEqual("plain_tcp_mux", routes[0]["ingress_protocol"])
        self.assertEqual("token-316", routes[0]["ingress_auth_token"])
        self.assertEqual(result["backend_port"], routes[0]["backend_port"])

    def test_plain_tcp_mux_unit_uses_all_configured_listen_hosts(self) -> None:
        agent = self.make_agent()
        agent.config["mux_listen_hosts"] = ["::", "0.0.0.0"]

        unit_body = agent.render_plain_tcp_mux_unit(
            "leikwan-tcp-mux-7001",
            7001,
            agent.listener_routes_path(7001),
        )

        self.assertIn("[::]:7001", unit_body)
        self.assertIn("0.0.0.0:7001", unit_body)

    def test_ensure_backend_service_restarts_when_running_process_shape_is_stale(self) -> None:
        agent = self.make_agent()
        commands: list[str] = []

        def fake_run(command: str, check: bool = True):
            commands.append(command)

            class Result:
                returncode = 0

            return Result()

        with mock.patch.object(slave_agent_module.shutil, "which", return_value="/bin/systemctl"):
            with mock.patch.object(agent, "systemd_is_active", return_value=True):
                with mock.patch.object(agent, "service_main_process_cmdline", return_value="/usr/local/bin/wstunnel server ws://127.0.0.1:6002"):
                    with mock.patch.object(slave_agent_module, "run_command", side_effect=fake_run):
                        agent.ensure_backend_service(
                            "wstunnel-svc-1-184",
                            unit_changed=False,
                            expected_exec_substring="leikwan-ingress backend",
                        )

        self.assertIn("systemctl daemon-reload", commands)
        self.assertIn("systemctl enable wstunnel-svc-1-184.service", commands)
        self.assertIn("systemctl restart wstunnel-svc-1-184.service", commands)

    def test_publish_task_never_allocates_backend_on_public_listener_port(self) -> None:
        agent = self.make_agent()
        agent.config["backend_port_range_start"] = 7000
        agent.config["backend_port_range_end"] = 7004
        task = {
            "service_id": 1,
            "forward_id": 26,
            "endpoint_id": 190,
            "payload": {},
        }
        payload = {
            "target": "138.252.162.120:9000",
            "listen_port": 7001,
            "path_prefix": "a43547b6350f72cd",
            "route_key": "a43547b6350f72cd",
            "transport_mode": "tcp+udp",
            "filter_profile": "off",
            "ingress_protocol": "plain_tcp_mux",
            "backend_protocol": "tcp_relay",
            "ingress_auth_token": "token-123",
        }

        with mock.patch.object(slave_agent_module.shutil, "which", return_value=None):
            with mock.patch.object(agent, "ensure_nft_table"):
                with mock.patch.object(agent, "delete_nft_rules"):
                    with mock.patch.object(agent, "add_nft_rules"):
                        with mock.patch.object(agent, "ensure_backend_service"):
                            with mock.patch.object(agent, "write_filter_marker", return_value={"filter_profile": "off"}):
                                result = agent.publish_task(task, payload)

        self.assertEqual(result["backend_port"], 7000)
        self.assertNotEqual(result["backend_port"], payload["listen_port"])
        routes = agent.listener_routes(7001)
        self.assertEqual(routes[0]["backend_port"], 7000)


    def test_cf_websocket_probe_does_not_send_application_bytes_to_bond_backend(self) -> None:
        agent = self.make_agent()
        agent.config["wstunnel_bin"] = "/usr/local/bin/wstunnel"

        class FakePortSocket:
            def bind(self, address):
                pass
            def getsockname(self):
                return ("127.0.0.1", 45125)
            def close(self):
                pass

        class FakeProbeSocket:
            sent_payloads = []
            def __enter__(self):
                return self
            def __exit__(self, exc_type, exc, tb):
                return False
            def settimeout(self, timeout):
                pass
            def sendall(self, payload):
                FakeProbeSocket.sent_payloads.append(payload)

        class FakeProcess:
            returncode = None
            def __init__(self, *args, **kwargs):
                self.stderr = kwargs.get("stderr")
            def poll(self):
                return None
            def terminate(self):
                pass
            def wait(self, timeout=None):
                return 0
            def kill(self):
                pass

        with mock.patch.object(slave_agent_module.Path, "exists", return_value=True),              mock.patch.object(slave_agent_module.socket, "socket", return_value=FakePortSocket()),              mock.patch.object(slave_agent_module.socket, "create_connection", return_value=FakeProbeSocket()),              mock.patch.object(slave_agent_module.subprocess, "Popen", FakeProcess):
            ok, latency_ms, error = agent.probe_cf_websocket_path(
                "162.159.39.4",
                2053,
                "nrt1.nbwly.com",
                "07289d1f5ed25399",
                "127.0.0.1:8899",
                timeout=0.6,
                settle_seconds=0.1,
            )

        self.assertTrue(ok)
        self.assertIsNotNone(latency_ms)
        self.assertIsNone(error)
        self.assertEqual([], FakeProbeSocket.sent_payloads)

    def test_reconcile_preserves_cf_wss_backend_for_plain_tcp_mux_route(self) -> None:
        agent = self.make_agent()
        service_name = slave_agent_module.safe_name(2, 316)
        agent.write_json(
            agent.metadata_path(service_name),
            {
                "service_name": service_name,
                "service_id": 2,
                "forward_id": 47,
                "endpoint_id": 316,
                "listen_port": 7000,
                "backend_port": 7002,
                "target": "127.0.0.1:8899",
                "path_prefix": "07289d1f5ed25399",
                "route_key": "07289d1f5ed25399",
                "ingress_protocol": "plain_tcp_mux",
                "ingress_auth_token": "token-316",
                "backend_protocol": "wss_backend",
                "backend_mode": "cf_mode",
                "cf_mode": {
                    "dial_host": "185.220.238.143",
                    "dial_port": 2053,
                    "sni_host": "nrt1.nbwly.com",
                    "path_prefix": "07289d1f5ed25399",
                },
                "selected_cf_ip": "162.159.39.4",
                "transport_mode": "tcp",
                "relay_protocols": [],
                "relay_target": "127.0.0.1:8899",
                "relay_service_name": "",
                "relay_client_service_name": "",
                "relay_client_port": 0,
                "mptcp_underlay": {},
                "filter_profile": "off",
                "status": "active",
            },
        )

        with mock.patch.object(slave_agent_module.shutil, "which", return_value=None):
            with mock.patch.object(agent, "ensure_nft_table"):
                with mock.patch.object(agent, "delete_nft_rules"):
                    with mock.patch.object(agent, "add_nft_rules"):
                        with mock.patch.object(agent, "ensure_backend_service"):
                            agent.reconcile_runtime()

        unit_body = agent.service_unit_path(service_name).read_text(encoding="utf-8")
        self.assertIn("wstunnel client", unit_body)
        self.assertIn("wss://162.159.39.4:2053", unit_body)
        self.assertNotIn("wstunnel server ws://127.0.0.1:7002", unit_body)

    def test_reconcile_reallocates_backend_port_colliding_with_listener(self) -> None:
        agent = self.make_agent()
        agent.config["backend_port_range_start"] = 7000
        agent.config["backend_port_range_end"] = 7004
        service_name = slave_agent_module.safe_name(1, 190)
        agent.write_json(
            agent.metadata_path(service_name),
            {
                "service_name": service_name,
                "service_id": 1,
                "forward_id": 26,
                "endpoint_id": 190,
                "listen_port": 7001,
                "backend_port": 7001,
                "target": "138.252.162.120:9000",
                "path_prefix": "a43547b6350f72cd",
                "route_key": "a43547b6350f72cd",
                "ingress_protocol": "plain_tcp_mux",
                "ingress_auth_token": "token-123",
                "backend_protocol": "tcp_relay",
                "transport_mode": "tcp+udp",
                "relay_protocols": [],
                "relay_target": "",
                "relay_service_name": "",
                "relay_client_service_name": "",
                "relay_client_port": 0,
                "mptcp_underlay": {},
                "filter_profile": "off",
                "status": "active",
            },
        )

        with mock.patch.object(slave_agent_module.shutil, "which", return_value=None):
            with mock.patch.object(agent, "ensure_nft_table"):
                with mock.patch.object(agent, "delete_nft_rules"):
                    with mock.patch.object(agent, "add_nft_rules"):
                        with mock.patch.object(agent, "ensure_backend_service"):
                            agent.reconcile_runtime()

        metadata = json.loads(agent.metadata_path(service_name).read_text(encoding="utf-8"))
        self.assertEqual(metadata["backend_port"], 7000)
        self.assertNotEqual(metadata["backend_port"], metadata["listen_port"])


if __name__ == "__main__":
    unittest.main()
