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


slave_agent_module = load_module("slave_agent_udp_uot_under_test", "slave-agent/slave_agent.py")


class SlaveUdpUotShadowRouteTests(unittest.TestCase):
    def make_agent(self) -> slave_agent_module.SlaveAgent:
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
                    "udp_uot_sidepath": {
                        "enabled_forward_ids": [26],
                        "shadow_route_suffix": ".uot",
                        "sidecar_port": 8999,
                    },
                }
            ),
            encoding="utf-8",
        )
        return slave_agent_module.SlaveAgent(config_path)

    def test_apply_config_patch_can_add_and_remove_udp_uot_sidepath(self) -> None:
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
        sidepath = {
            "enabled_forward_ids": [26],
            "shadow_route_suffix": ".uot",
            "sidecar_port": 8999,
        }

        agent.apply_config_patch({"artifact_base_url": "https://artifacts.example.com", "udp_uot_sidepath": sidepath})
        persisted = json.loads(config_path.read_text(encoding="utf-8"))
        self.assertEqual(persisted["artifact_base_url"], "https://artifacts.example.com")
        self.assertEqual(persisted["udp_uot_sidepath"], sidepath)

        agent.apply_config_patch({"udp_uot_sidepath": None})
        persisted = json.loads(config_path.read_text(encoding="utf-8"))
        self.assertNotIn("udp_uot_sidepath", persisted)

    def publish_with_mocks(self, agent: slave_agent_module.SlaveAgent, task: dict, payload: dict):
        with mock.patch.object(agent, "ensure_nft_table"):
            with mock.patch.object(agent, "delete_nft_rules") as delete_nft_rules:
                with mock.patch.object(agent, "add_nft_rules"):
                    with mock.patch.object(agent, "write_filter_marker", return_value={"filter_profile": "off"}):
                        agent.publish_task(task, payload)
        return delete_nft_rules

    def test_publish_task_creates_shadow_uot_service_for_enabled_forward(self) -> None:
        agent = self.make_agent()
        task = {"service_id": 1, "forward_id": 26, "endpoint_id": 184}
        payload = {
            "listen_port": 7001,
            "target": "138.252.162.120:9000",
            "path_prefix": "a43547b6350f72cd",
            "route_key": "a43547b6350f72cd",
            "transport_mode": "tcp+udp",
            "ingress_protocol": "plain_tcp_mux",
            "ingress_auth_token": "token-123",
            "backend_protocol": "tcp_relay",
            "filter_profile": "off",
        }

        self.publish_with_mocks(agent, task, payload)

        routes = agent.listener_routes(7001)
        route_keys = [item["route_key"] for item in routes]
        self.assertIn("a43547b6350f72cd", route_keys)
        self.assertIn("a43547b6350f72cd.uot", route_keys)
        uot_route = next(item for item in routes if item["route_key"] == "a43547b6350f72cd.uot")
        self.assertEqual(uot_route["backend_protocol"], "tcp_relay")
        self.assertEqual(uot_route["backend_port"] > 0, True)

    def test_shadow_uot_backend_targets_sidecar_8999(self) -> None:
        agent = self.make_agent()
        task = {"service_id": 1, "forward_id": 26, "endpoint_id": 184}
        payload = {
            "listen_port": 7001,
            "target": "138.252.162.120:9000",
            "path_prefix": "a43547b6350f72cd",
            "route_key": "a43547b6350f72cd",
            "transport_mode": "tcp+udp",
            "ingress_protocol": "plain_tcp_mux",
            "ingress_auth_token": "token-123",
            "backend_protocol": "tcp_relay",
            "filter_profile": "off",
        }

        self.publish_with_mocks(agent, task, payload)

        shadow_name = "wstunnel-svc-1-184-udp-uot"
        metadata = agent.load_service_metadata(shadow_name)
        self.assertEqual(metadata["target"], "138.252.162.120:8999")
        self.assertEqual(metadata["route_key"], "a43547b6350f72cd.uot")
        self.assertEqual(metadata["ingress_auth_token"], "token-123")

    def test_republish_same_forward_keeps_single_shadow_and_no_extra_destroy(self) -> None:
        agent = self.make_agent()
        task = {"service_id": 1, "forward_id": 26, "endpoint_id": 184}
        payload = {
            "listen_port": 7001,
            "target": "138.252.162.120:9000",
            "path_prefix": "a43547b6350f72cd",
            "route_key": "a43547b6350f72cd",
            "transport_mode": "tcp+udp",
            "ingress_protocol": "plain_tcp_mux",
            "ingress_auth_token": "token-123",
            "backend_protocol": "tcp_relay",
            "filter_profile": "off",
        }
        payload_republish = dict(payload)
        payload_republish["target"] = "138.252.162.120:9010"

        first_delete_call = self.publish_with_mocks(agent, task, payload)
        second_delete_call = self.publish_with_mocks(agent, task, payload_republish)

        self.assertEqual(first_delete_call.call_count, 1)
        self.assertEqual(second_delete_call.call_count, 1)

        shadow_name = "wstunnel-svc-1-184-udp-uot"
        base_name = "wstunnel-svc-1-184"
        self.assertEqual(agent.load_service_metadata(base_name)["target"], "138.252.162.120:9010")
        self.assertEqual(agent.load_service_metadata(shadow_name)["target"], "138.252.162.120:8999")

        shadow_metadata_files = list(agent.services_dir.glob("*-udp-uot.json"))
        self.assertEqual(len(shadow_metadata_files), 1)
        self.assertTrue(agent.service_unit_path(shadow_name).exists())

    def test_republish_same_forward_keeps_shadow_backend_port_stable(self) -> None:
        agent = self.make_agent()
        task = {"service_id": 1, "forward_id": 26, "endpoint_id": 184}
        payload = {
            "listen_port": 7001,
            "target": "138.252.162.120:9000",
            "path_prefix": "a43547b6350f72cd",
            "route_key": "a43547b6350f72cd",
            "transport_mode": "tcp+udp",
            "ingress_protocol": "plain_tcp_mux",
            "ingress_auth_token": "token-123",
            "backend_protocol": "tcp_relay",
            "filter_profile": "off",
        }

        self.publish_with_mocks(agent, task, payload)
        shadow_name = "wstunnel-svc-1-184-udp-uot"
        first_shadow_port = agent.load_service_metadata(shadow_name)["backend_port"]
        first_routes = agent.listener_routes(7001)
        first_route = next(item for item in first_routes if item["route_key"] == "a43547b6350f72cd.uot")
        self.assertEqual(first_route["backend_port"], first_shadow_port)
        first_unit = agent.service_unit_path(shadow_name).read_text(encoding="utf-8")
        self.assertIn(f"--listen 127.0.0.1:{first_shadow_port}", first_unit)

        payload_republish = dict(payload)
        payload_republish["target"] = "138.252.162.120:9010"
        original_tcp_port_available = agent.tcp_port_available

        def fake_tcp_port_available(port: int) -> bool:
            if port == first_shadow_port:
                return False
            return original_tcp_port_available(port)

        with mock.patch.object(agent, "tcp_port_available", side_effect=fake_tcp_port_available):
            self.publish_with_mocks(agent, task, payload_republish)

        second_shadow_port = agent.load_service_metadata(shadow_name)["backend_port"]
        second_routes = agent.listener_routes(7001)
        second_route = next(item for item in second_routes if item["route_key"] == "a43547b6350f72cd.uot")
        self.assertEqual(second_shadow_port, first_shadow_port)
        self.assertEqual(second_route["backend_port"], first_shadow_port)
        second_unit = agent.service_unit_path(shadow_name).read_text(encoding="utf-8")
        self.assertIn(f"--listen 127.0.0.1:{first_shadow_port}", second_unit)

    def test_destroy_removes_shadow_metadata_and_route(self) -> None:
        agent = self.make_agent()
        task = {"service_id": 1, "forward_id": 26, "endpoint_id": 184}
        payload = {
            "listen_port": 7001,
            "target": "138.252.162.120:9000",
            "path_prefix": "a43547b6350f72cd",
            "route_key": "a43547b6350f72cd",
            "transport_mode": "tcp+udp",
            "ingress_protocol": "plain_tcp_mux",
            "ingress_auth_token": "token-123",
            "backend_protocol": "tcp_relay",
            "filter_profile": "off",
        }
        self.publish_with_mocks(agent, task, payload)

        shadow_name = "wstunnel-svc-1-184-udp-uot"
        self.assertIsNotNone(agent.load_service_metadata(shadow_name))
        self.assertTrue(agent.service_unit_path(shadow_name).exists())

        with mock.patch.object(agent, "delete_nft_rules"):
            agent.destroy_service(task)

        routes = agent.listener_routes(7001)
        route_keys = [item["route_key"] for item in routes]
        self.assertNotIn("a43547b6350f72cd", route_keys)
        self.assertNotIn("a43547b6350f72cd.uot", route_keys)
        self.assertIsNone(agent.load_service_metadata(shadow_name))
        self.assertFalse(agent.service_unit_path(shadow_name).exists())

    def test_reconcile_runtime_preserves_valid_base_shadow_pair(self) -> None:
        agent = self.make_agent()
        task = {"service_id": 1, "forward_id": 26, "endpoint_id": 184}
        payload = {
            "listen_port": 7001,
            "target": "138.252.162.120:9000",
            "path_prefix": "a43547b6350f72cd",
            "route_key": "a43547b6350f72cd",
            "transport_mode": "tcp+udp",
            "ingress_protocol": "plain_tcp_mux",
            "ingress_auth_token": "token-123",
            "backend_protocol": "tcp_relay",
            "filter_profile": "off",
        }
        self.publish_with_mocks(agent, task, payload)

        with mock.patch.object(agent, "ensure_nft_table"):
            with mock.patch.object(agent, "delete_nft_rules"):
                with mock.patch.object(agent, "add_nft_rules"):
                    agent.reconcile_runtime()

        base_name = "wstunnel-svc-1-184"
        shadow_name = "wstunnel-svc-1-184-udp-uot"
        self.assertIsNotNone(agent.load_service_metadata(base_name))
        self.assertIsNotNone(agent.load_service_metadata(shadow_name))
        route_keys = [item["route_key"] for item in agent.listener_routes(7001)]
        self.assertIn("a43547b6350f72cd", route_keys)
        self.assertIn("a43547b6350f72cd.uot", route_keys)
        shadow_unit = agent.service_unit_path(shadow_name).read_text(encoding="utf-8")
        self.assertIn("Leikwan Plain TCP Backend", shadow_unit)
        self.assertIn(" backend --listen ", shadow_unit)

    def test_reconcile_runtime_backfills_missing_udp_uot_shadow_service(self) -> None:
        agent = self.make_agent()
        task = {"service_id": 1, "forward_id": 26, "endpoint_id": 184}
        payload = {
            "listen_port": 7001,
            "target": "138.252.162.120:9000",
            "path_prefix": "a43547b6350f72cd",
            "route_key": "a43547b6350f72cd",
            "transport_mode": "tcp+udp",
            "ingress_protocol": "plain_tcp_mux",
            "ingress_auth_token": "token-123",
            "backend_protocol": "tcp_relay",
            "filter_profile": "off",
        }
        self.publish_with_mocks(agent, task, payload)

        shadow_name = "wstunnel-svc-1-184-udp-uot"
        agent.metadata_path(shadow_name).unlink()
        agent.service_unit_path(shadow_name).unlink()

        with mock.patch.object(agent, "ensure_nft_table"):
            with mock.patch.object(agent, "delete_nft_rules"):
                with mock.patch.object(agent, "add_nft_rules"):
                    agent.reconcile_runtime()

        self.assertIsNotNone(agent.load_service_metadata(shadow_name))
        self.assertTrue(agent.service_unit_path(shadow_name).exists())
        route_keys = [item["route_key"] for item in agent.listener_routes(7001)]
        self.assertIn("a43547b6350f72cd.uot", route_keys)

    def test_republish_non_shadow_eligible_removes_shadow_metadata_and_route(self) -> None:
        agent = self.make_agent()
        task = {"service_id": 1, "forward_id": 26, "endpoint_id": 184}
        payload_shadow = {
            "listen_port": 7001,
            "target": "138.252.162.120:9000",
            "path_prefix": "a43547b6350f72cd",
            "route_key": "a43547b6350f72cd",
            "transport_mode": "tcp+udp",
            "ingress_protocol": "plain_tcp_mux",
            "ingress_auth_token": "token-123",
            "backend_protocol": "tcp_relay",
            "filter_profile": "off",
        }
        payload_plain_tcp = dict(payload_shadow)
        payload_plain_tcp["transport_mode"] = "tcp"
        payload_plain_tcp["target"] = "138.252.162.120:9010"

        self.publish_with_mocks(agent, task, payload_shadow)
        self.publish_with_mocks(agent, task, payload_plain_tcp)

        shadow_name = "wstunnel-svc-1-184-udp-uot"
        self.assertIsNone(agent.load_service_metadata(shadow_name))
        self.assertFalse(agent.service_unit_path(shadow_name).exists())
        route_keys = [item["route_key"] for item in agent.listener_routes(7001)]
        self.assertIn("a43547b6350f72cd", route_keys)
        self.assertNotIn("a43547b6350f72cd.uot", route_keys)


class SlaveUdpUotLeigodShadowTests(unittest.TestCase):
    def make_agent(self, forward_lines: dict) -> slave_agent_module.SlaveAgent:
        tmp_dir = tempfile.TemporaryDirectory()
        self.addCleanup(tmp_dir.cleanup)
        self.systemd_dir = pathlib.Path(tmp_dir.name) / "systemd"
        config_path = pathlib.Path(tmp_dir.name) / "config.json"
        config_path.write_text(
            json.dumps(
                {
                    "master_url": "https://panel.example.com",
                    "node_token": "token",
                    "state_dir": str(pathlib.Path(tmp_dir.name) / "state"),
                    "systemd_dir": str(self.systemd_dir),
                    "ingress_bin": "/usr/local/bin/leikwan-ingress",
                    "udp_uot_sidepath": {
                        "enabled_forward_ids": [26],
                        "shadow_route_suffix": ".uot",
                        "sidecar_port": 8999,
                        "forward_leigod_lines": forward_lines,
                    },
                }
            ),
            encoding="utf-8",
        )
        agent = slave_agent_module.SlaveAgent(config_path)
        agent.save_leigod_desired_lines(
            [
                {"line_id": 455, "socks_port": 20455},
                {"line_id": 693, "socks_port": 20693},
            ]
        )
        return agent

    def publish(self, agent) -> None:
        task = {"service_id": 1, "forward_id": 26, "endpoint_id": 184}
        payload = {
            "listen_port": 7001,
            "target": "138.252.162.120:9000",
            "path_prefix": "a43547b6350f72cd",
            "route_key": "a43547b6350f72cd",
            "transport_mode": "tcp+udp",
            "ingress_protocol": "plain_tcp_mux",
            "ingress_auth_token": "token-123",
            "backend_protocol": "tcp_relay",
            "filter_profile": "off",
        }
        real_which = slave_agent_module.shutil.which

        def fake_which(binary, *args, **kwargs):
            if str(binary).endswith("gost"):
                return "/usr/local/bin/gost"
            if binary == "systemctl":
                return None
            return real_which(binary, *args, **kwargs)

        with mock.patch.object(slave_agent_module.shutil, "which", side_effect=fake_which):
            with mock.patch.object(agent, "ensure_nft_table"), mock.patch.object(agent, "delete_nft_rules"), mock.patch.object(
                agent, "add_nft_rules"
            ), mock.patch.object(agent, "write_filter_marker", return_value={"filter_profile": "off"}):
                agent.publish_task(task, payload)

    def test_leigod_lines_create_per_line_shadow_routes_and_gost_units(self) -> None:
        agent = self.make_agent({"26": [455, 693]})
        self.publish(agent)

        routes = agent.listener_routes(7001)
        route_keys = [item["route_key"] for item in routes]
        self.assertIn("a43547b6350f72cd.uot.l455", route_keys)
        self.assertIn("a43547b6350f72cd.uot.l693", route_keys)

        metadata = agent.load_service_metadata("wstunnel-svc-1-184-udp-uot-l455")
        self.assertIsNotNone(metadata)
        self.assertEqual(metadata["leigod_line_id"], 455)
        gost_port = int(metadata["leigod_gost_port"])
        self.assertGreater(gost_port, 0)
        self.assertEqual(metadata["target"], f"127.0.0.1:{gost_port}")

        gost_unit = (self.systemd_dir / "wstunnel-svc-1-184-udp-uot-lg455.service").read_text(encoding="utf-8")
        self.assertIn(f"-L tcp://127.0.0.1:{gost_port}/138.252.162.120:8999", gost_unit)
        self.assertIn("-F socks5://127.0.0.1:20455", gost_unit)

    def test_missing_leigod_line_is_skipped(self) -> None:
        agent = self.make_agent({"26": [455, 999]})
        self.publish(agent)
        route_keys = [item["route_key"] for item in agent.listener_routes(7001)]
        self.assertIn("a43547b6350f72cd.uot.l455", route_keys)
        self.assertNotIn("a43547b6350f72cd.uot.l999", route_keys)

    def test_lines_removed_from_config_cleanup_per_line_services(self) -> None:
        agent = self.make_agent({"26": [455]})
        self.publish(agent)
        self.assertIn("a43547b6350f72cd.uot.l455", [item["route_key"] for item in agent.listener_routes(7001)])

        config_path = agent.config_path if hasattr(agent, "config_path") else None
        # shrink the assignment to no lines and republish
        import json as _json

        cfg = _json.loads(pathlib.Path(agent.config_path).read_text(encoding="utf-8")) if config_path else None
        if cfg is None:
            # fall back: locate config via state_dir parent
            cfg_path = pathlib.Path(agent.state_dir).parent / "config.json"
        else:
            cfg_path = config_path
        cfg = _json.loads(pathlib.Path(cfg_path).read_text(encoding="utf-8"))
        cfg["udp_uot_sidepath"]["forward_leigod_lines"] = {}
        pathlib.Path(cfg_path).write_text(_json.dumps(cfg), encoding="utf-8")
        agent.config = cfg

        self.publish(agent)
        route_keys = [item["route_key"] for item in agent.listener_routes(7001)]
        self.assertNotIn("a43547b6350f72cd.uot.l455", route_keys)
        self.assertFalse((self.systemd_dir / "wstunnel-svc-1-184-udp-uot-lg455.service").exists())
        self.assertFalse((self.systemd_dir / "wstunnel-svc-1-184-udp-uot-l455.service").exists())
