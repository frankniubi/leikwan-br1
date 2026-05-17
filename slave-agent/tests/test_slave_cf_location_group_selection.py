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

slave_agent = load_module("slave_agent_cf_mode_under_test", "slave-agent/slave_agent.py")


class SlaveCfLocationSelectionTests(unittest.TestCase):
    def make_agent(self, tmp_dir: str):
        config_path = pathlib.Path(tmp_dir) / "config.json"
        config_path.write_text(json.dumps({
            "master_url": "https://panel.example.com",
            "node_token": "token-123",
            "state_dir": str(pathlib.Path(tmp_dir) / "state"),
            "systemd_dir": str(pathlib.Path(tmp_dir) / "systemd"),
            "wstunnel_bin": "/usr/local/bin/wstunnel",
            "backend_port_range_start": 24000,
            "backend_port_range_end": 24010,
        }), encoding="utf-8")
        return slave_agent.SlaveAgent(config_path)

    def test_same_group_reuses_selected_ip_across_forwards(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            with mock.patch.object(
                agent,
                'probe_cf_candidates',
                return_value=[{'ip': '104.16.10.10', 'ok': True, 'latency_ms': 18.2, 'jitter_ms': 1.4, 'failures': 0, 'error': ''}],
            ):
                first = agent.select_cf_location_ip({
                    'location_group_id': 7,
                    'cidrs': ['104.16.0.0/13'],
                    'probe_mode': 'tcp',
                    'dial_port': 443,
                })
                second = agent.select_cf_location_ip({
                    'location_group_id': 7,
                    'cidrs': ['172.64.0.0/13'],
                    'probe_mode': 'tcp',
                    'dial_port': 443,
                })
            self.assertEqual(first['selected_ip'], '104.16.10.10')
            self.assertEqual(second['selected_ip'], '104.16.10.10')

    def test_render_cf_backend_unit_uses_selected_ip_and_preserves_sni(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            unit = agent.render_cf_wss_backend_unit(
                service_name='wstunnel-svc-1-184',
                backend_port=24001,
                target='127.0.0.1:51820',
                selected_ip='104.16.10.10',
                dial_host='',
                remote_port=443,
                sni_host='nrt.example.com',
                path_prefix='a43547b6350f72cd',
                transport_mode='tcp',
            )
            self.assertIn('wss://104.16.10.10:443', unit)
            self.assertNotIn('wss://nrt.example.com:443', unit)
            self.assertIn('--tls-sni-override nrt.example.com', unit)
            self.assertIn('--http-headers "Host: nrt.example.com"', unit)
            self.assertIn('--http-upgrade-path-prefix "a43547b6350f72cd"', unit)

    def test_publish_task_uses_selected_cf_ip_for_cf_backend_dial(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            task = {
                "action": "publish",
                "service_id": 2,
                "forward_id": 47,
                "endpoint_id": 316,
            }
            payload = {
                "target": "185.220.238.143:8899",
                "listen_port": 7000,
                "path_prefix": "07289d1f5ed25399",
                "backend_mode": "cf_mode",
                "transport_mode": "tcp+udp",
                "cf_mode": {
                    "location_group_id": 1,
                    "cidrs": ["104.16.0.0/13"],
                    "probe_mode": "tcp",
                    "dial_host": "185.220.238.143",
                    "dial_port": 2053,
                    "sni_host": "nrt1.nbwly.com",
                    "path_prefix": "07289d1f5ed25399",
                },
            }

            with mock.patch.object(agent, "select_cf_location_ip", return_value={"selected_ip": "162.159.39.16"}), \
                 mock.patch.object(agent, "ensure_listener_route_available"), \
                 mock.patch.object(agent, "allocate_backend_port", return_value=7002), \
                 mock.patch.object(agent, "ensure_backend_service"), \
                 mock.patch.object(agent, "ensure_nft_table"), \
                 mock.patch.object(agent, "delete_nft_rules"), \
                 mock.patch.object(agent, "add_nft_rules"), \
                 mock.patch.object(agent, "remove_udp_uot_shadow_service"):
                agent.publish_task(task, payload)

            unit = agent.service_unit_path("wstunnel-svc-2-316").read_text(encoding="utf-8")
            self.assertIn("wss://162.159.39.16:2053", unit)
            self.assertNotIn("wss://185.220.238.143:2053", unit)
            self.assertNotIn("wss://nrt1.nbwly.com:2053", unit)
            self.assertIn('--tls-sni-override nrt1.nbwly.com', unit)
            self.assertIn('--http-headers "Host: nrt1.nbwly.com"', unit)

    def test_publish_task_plain_tcp_mux_overrides_cf_backend_generation(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            task = {
                "action": "publish",
                "service_id": 2,
                "forward_id": 47,
                "endpoint_id": 316,
            }
            payload = {
                "target": "185.220.238.143:8899",
                "listen_port": 7000,
                "path_prefix": "07289d1f5ed25399",
                "backend_mode": "cf_mode",
                "transport_mode": "tcp",
                "ingress_protocol": "plain_tcp_mux",
                "backend_protocol": "tcp_relay",
                "ingress_auth_token": "token-123",
                "cf_mode": {
                    "location_group_id": 1,
                    "cidrs": ["104.16.0.0/13"],
                    "probe_mode": "tcp",
                    "dial_host": "185.220.238.143",
                    "dial_port": 2053,
                    "sni_host": "nrt1.nbwly.com",
                    "path_prefix": "07289d1f5ed25399",
                },
            }

            with mock.patch.object(agent, "select_cf_location_ip", return_value={"selected_ip": "162.159.39.16"}), \
                 mock.patch.object(agent, "ensure_listener_route_available"), \
                 mock.patch.object(agent, "allocate_backend_port", return_value=7002), \
                 mock.patch.object(agent, "ensure_backend_service"), \
                 mock.patch.object(agent, "ensure_nft_table"), \
                 mock.patch.object(agent, "delete_nft_rules"), \
                 mock.patch.object(agent, "add_nft_rules"), \
                 mock.patch.object(agent, "remove_udp_uot_shadow_service"):
                agent.publish_task(task, payload)

            unit = agent.service_unit_path("wstunnel-svc-2-316").read_text(encoding="utf-8")
            self.assertIn("leikwan-ingress", unit)
            self.assertIn("backend --listen 127.0.0.1:7002", unit)
            self.assertIn('--target "185.220.238.143:8899"', unit)
            self.assertNotIn("wss://", unit)

    def test_publish_task_plain_tcp_mux_keeps_cf_backend_when_backend_protocol_is_wss_backend(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            task = {
                "action": "publish",
                "service_id": 2,
                "forward_id": 47,
                "endpoint_id": 316,
            }
            payload = {
                "target": "185.220.238.143:8899",
                "listen_port": 7000,
                "path_prefix": "07289d1f5ed25399",
                "backend_mode": "cf_mode",
                "transport_mode": "tcp",
                "ingress_protocol": "plain_tcp_mux",
                "backend_protocol": "wss_backend",
                "ingress_auth_token": "token-123",
                "cf_mode": {
                    "location_group_id": 1,
                    "cidrs": ["104.16.0.0/13"],
                    "probe_mode": "tcp",
                    "dial_host": "185.220.238.143",
                    "dial_port": 2053,
                    "sni_host": "nrt1.nbwly.com",
                    "path_prefix": "07289d1f5ed25399",
                },
            }

            with mock.patch.object(agent, "select_cf_location_ip", return_value={"selected_ip": "162.159.39.16"}), \
                 mock.patch.object(agent, "ensure_listener_route_available"), \
                 mock.patch.object(agent, "allocate_backend_port", return_value=7002), \
                 mock.patch.object(agent, "ensure_backend_service"), \
                 mock.patch.object(agent, "ensure_nft_table"), \
                 mock.patch.object(agent, "delete_nft_rules"), \
                 mock.patch.object(agent, "add_nft_rules"), \
                 mock.patch.object(agent, "remove_udp_uot_shadow_service"):
                agent.publish_task(task, payload)

            unit = agent.service_unit_path("wstunnel-svc-2-316").read_text(encoding="utf-8")
            self.assertIn("wss://162.159.39.16:2053", unit)
            self.assertNotIn("backend --listen 127.0.0.1:7002", unit)
            metadata = json.loads(agent.metadata_path("wstunnel-svc-2-316").read_text(encoding="utf-8"))
            self.assertEqual(metadata["backend_protocol"], "wss_backend")
            self.assertEqual(metadata["ingress_protocol"], "plain_tcp_mux")
            self.assertEqual(metadata["selected_cf_ip"], "162.159.39.16")



    def test_select_cf_location_ip_reprobes_fresh_cached_ip_when_current_path_fails(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            agent.save_cf_location_state(
                {
                    "1": {
                        "location_group_id": 1,
                        "selected_ip": "162.159.36.11",
                        "last_latency_ms": 34.0,
                        "last_jitter_ms": 1.0,
                        "last_probe_at": 100.0,
                        "backup_ips": ["162.159.39.4"],
                        "consecutive_failures": 0,
                        "cooldown_until": 0,
                        "candidate_stats": {},
                    }
                }
            )
            with mock.patch.object(slave_agent.time, "time", return_value=110.0),                  mock.patch.object(agent, "expand_cf_candidates", return_value=["162.159.36.11", "162.159.39.4"]),                  mock.patch.object(agent, "probe_cf_websocket_path", return_value=(False, None, "Invalid status code: 520")),                  mock.patch.object(
                     agent,
                     "probe_cf_candidates",
                     return_value=[
                         {"ip": "162.159.39.4", "ok": True, "latency_ms": 35.0, "jitter_ms": 1.0, "failures": 0, "error": ""},
                     ],
                 ):
                selected = agent.select_cf_location_ip(
                    {
                        "location_group_id": 1,
                        "cidrs": ["162.159.36.0/24", "162.159.39.0/24"],
                        "dial_port": 2053,
                        "sni_host": "nrt1.nbwly.com",
                        "path_prefix": "07289d1f5ed25399",
                        "probe_target": "127.0.0.1:8899",
                        "validate_current": True,
                        "switch_improvement_ms": 15,
                        "cache_ttl_seconds": 120,
                        "max_candidates": 8,
                    }
                )

            self.assertEqual(selected["selected_ip"], "162.159.39.4")
            state = agent.load_cf_location_state()["1"]
            self.assertGreater(state["candidate_stats"]["162.159.36.11"]["cooldown_until"], 110.0)

    def test_reconcile_runtime_refreshes_stale_selected_cf_ip(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            agent.save_service_metadata(
                "wstunnel-svc-2-317",
                {
                    "service_name": "wstunnel-svc-2-317",
                    "service_id": 2,
                    "forward_id": 47,
                    "endpoint_id": 317,
                    "listen_port": 7000,
                    "backend_port": 24002,
                    "target": "127.0.0.1:8899",
                    "path_prefix": "07289d1f5ed25399",
                    "route_key": "07289d1f5ed25399",
                    "backend_mode": "cf_mode",
                    "cf_mode": {
                        "location_group_id": 1,
                        "cidrs": ["162.159.39.0/24"],
                        "probe_mode": "tcp",
                        "dial_host": "185.220.238.143",
                        "dial_port": 2053,
                        "sni_host": "nrt1.nbwly.com",
                        "path_prefix": "07289d1f5ed25399",
                    },
                    "selected_cf_ip": "162.159.36.11",
                    "ingress_protocol": "plain_tcp_mux",
                    "backend_protocol": "wss_backend",
                    "ingress_auth_token": "token-123",
                    "transport_mode": "tcp",
                    "relay_protocols": [],
                    "status": "active",
                },
            )

            with mock.patch.object(agent, "select_cf_location_ip", return_value={"selected_ip": "162.159.39.4"}) as select_mock,                  mock.patch.object(agent, "ensure_nft_table"),                  mock.patch.object(agent, "cleanup_superseded_forward_services"),                  mock.patch.object(agent, "ensure_backend_service"),                  mock.patch.object(agent, "delete_nft_rules"),                  mock.patch.object(agent, "add_nft_rules"),                  mock.patch.object(agent, "sync_listener"):
                agent.reconcile_runtime()

            select_mock.assert_called_once()
            selection_mode = select_mock.call_args.args[0]
            self.assertEqual(selection_mode["probe_target"], "127.0.0.1:8899")
            unit = agent.service_unit_path("wstunnel-svc-2-317").read_text(encoding="utf-8")
            self.assertIn("wss://162.159.39.4:2053", unit)
            self.assertNotIn("wss://162.159.36.11:2053", unit)
            metadata = json.loads(agent.metadata_path("wstunnel-svc-2-317").read_text(encoding="utf-8"))
            self.assertEqual(metadata["selected_cf_ip"], "162.159.39.4")

    def test_select_cf_location_ip_keeps_cached_ip_when_improvement_below_threshold(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            agent.save_cf_location_state(
                {
                    "1": {
                        "location_group_id": 1,
                        "selected_ip": "162.159.39.10",
                        "last_latency_ms": 40.0,
                        "last_jitter_ms": 1.0,
                        "last_probe_at": 0.0,
                        "backup_ips": ["162.159.39.11"],
                        "consecutive_failures": 0,
                        "cooldown_until": 0,
                        "candidate_stats": {},
                    }
                }
            )
            with mock.patch.object(agent, "expand_cf_candidates", return_value=["162.159.39.10", "162.159.39.11"]):
                with mock.patch.object(
                    agent,
                    "probe_cf_candidates",
                    return_value=[
                        {"ip": "162.159.39.11", "ok": True, "latency_ms": 30.0, "jitter_ms": 1.0, "failures": 0, "error": ""},
                        {"ip": "162.159.39.10", "ok": True, "latency_ms": 40.0, "jitter_ms": 1.0, "failures": 0, "error": ""},
                    ],
                ):
                    selected = agent.select_cf_location_ip(
                        {
                            "location_group_id": 1,
                            "cidrs": ["162.159.39.0/24"],
                            "dial_port": 2053,
                            "probe_mode": "tcp",
                            "switch_improvement_ms": 15,
                            "cache_ttl_seconds": 30,
                            "max_candidates": 8,
                        }
                    )

            self.assertEqual(selected["selected_ip"], "162.159.39.10")

    def test_select_cf_location_ip_switches_when_improvement_threshold_is_met(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            agent.save_cf_location_state(
                {
                    "1": {
                        "location_group_id": 1,
                        "selected_ip": "162.159.39.10",
                        "last_latency_ms": 55.0,
                        "last_jitter_ms": 1.0,
                        "last_probe_at": 0.0,
                        "backup_ips": ["162.159.39.11"],
                        "consecutive_failures": 0,
                        "cooldown_until": 0,
                        "candidate_stats": {},
                    }
                }
            )
            with mock.patch.object(agent, "expand_cf_candidates", return_value=["162.159.39.10", "162.159.39.11"]):
                with mock.patch.object(
                    agent,
                    "probe_cf_candidates",
                    return_value=[
                        {"ip": "162.159.39.11", "ok": True, "latency_ms": 30.0, "jitter_ms": 1.0, "failures": 0, "error": ""},
                        {"ip": "162.159.39.10", "ok": True, "latency_ms": 55.0, "jitter_ms": 1.0, "failures": 0, "error": ""},
                    ],
                ):
                    selected = agent.select_cf_location_ip(
                        {
                            "location_group_id": 1,
                            "cidrs": ["162.159.39.0/24"],
                            "dial_port": 2053,
                            "probe_mode": "tcp",
                            "switch_improvement_ms": 15,
                            "cache_ttl_seconds": 30,
                            "max_candidates": 8,
                        }
                    )

            self.assertEqual(selected["selected_ip"], "162.159.39.11")

    def test_select_cf_location_ip_skips_candidates_in_cooldown(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            now = 200.0
            agent.save_cf_location_state(
                {
                    "1": {
                        "location_group_id": 1,
                        "selected_ip": "162.159.39.10",
                        "last_latency_ms": 50.0,
                        "last_jitter_ms": 1.0,
                        "last_probe_at": 0.0,
                        "backup_ips": [],
                        "consecutive_failures": 0,
                        "cooldown_until": 0,
                        "candidate_stats": {
                            "162.159.39.11": {
                                "consecutive_failures": 3,
                                "cooldown_until": 260.0,
                            }
                        },
                    }
                }
            )
            with mock.patch.object(agent, "expand_cf_candidates", return_value=["162.159.39.10", "162.159.39.11", "162.159.39.12"]):
                with mock.patch.object(
                    agent,
                    "probe_cf_candidates",
                    return_value=[
                        {"ip": "162.159.39.12", "ok": True, "latency_ms": 45.0, "jitter_ms": 1.0, "failures": 0, "error": ""},
                        {"ip": "162.159.39.10", "ok": True, "latency_ms": 50.0, "jitter_ms": 1.0, "failures": 0, "error": ""},
                    ],
                ):
                    with mock.patch.object(slave_agent.time, "time", return_value=now):
                        selected = agent.select_cf_location_ip(
                            {
                                "location_group_id": 1,
                                "cidrs": ["162.159.39.0/24"],
                                "dial_port": 2053,
                                "probe_mode": "tcp",
                                "switch_improvement_ms": 15,
                                "cache_ttl_seconds": 30,
                                "max_candidates": 8,
                            }
                        )

            self.assertEqual(selected["selected_ip"], "162.159.39.10")

    def test_probe_target_cache_revalidates_by_default_and_switches_from_bad_cached_edge(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            agent.save_cf_location_state(
                {
                    "1": {
                        "location_group_id": 1,
                        "selected_ip": "162.159.39.6",
                        "last_latency_ms": 34.0,
                        "last_jitter_ms": 1.0,
                        "last_probe_at": 100.0,
                        "backup_ips": ["162.159.39.4"],
                        "consecutive_failures": 0,
                        "cooldown_until": 0,
                        "candidate_stats": {},
                    }
                }
            )
            with mock.patch.object(slave_agent.time, "time", return_value=110.0),                  mock.patch.object(agent, "expand_cf_candidates", return_value=["162.159.39.6", "162.159.39.4"]),                  mock.patch.object(agent, "probe_cf_websocket_path", return_value=(False, None, "Invalid status code: 520")) as validate_mock,                  mock.patch.object(
                     agent,
                     "probe_cf_candidates",
                     return_value=[
                         {"ip": "162.159.39.4", "ok": True, "latency_ms": 35.0, "jitter_ms": 1.0, "failures": 0, "error": ""},
                     ],
                 ):
                selected = agent.select_cf_location_ip(
                    {
                        "location_group_id": 1,
                        "cidrs": ["162.159.39.0/24"],
                        "dial_port": 2053,
                        "sni_host": "nrt1.nbwly.com",
                        "path_prefix": "07289d1f5ed25399",
                        "probe_target": "127.0.0.1:8899",
                        "switch_improvement_ms": 15,
                        "cache_ttl_seconds": 120,
                        "max_candidates": 8,
                    }
                )

            validate_mock.assert_called_once_with(
                "162.159.39.6",
                2053,
                "nrt1.nbwly.com",
                "07289d1f5ed25399",
                "127.0.0.1:8899",
            )
            self.assertEqual(selected["selected_ip"], "162.159.39.4")
            state = agent.load_cf_location_state()["1"]
            self.assertGreater(state["candidate_stats"]["162.159.39.6"]["cooldown_until"], 110.0)

    def test_probe_target_candidate_ranking_prefers_no_520_failures_before_lower_connect_latency(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            with mock.patch.object(agent, "tcp_ping", side_effect=[30.0, 31.0, 40.0, 41.0]),                  mock.patch.object(
                     agent,
                     "probe_cf_websocket_path",
                     side_effect=[
                         (False, None, "Invalid status code: 520"),
                         (True, 80.0, None),
                         (True, 90.0, None),
                         (True, 91.0, None),
                     ],
                 ):
                results = agent.probe_cf_candidates(
                    ["162.159.39.6", "162.159.39.4"],
                    {
                        "dial_port": 2053,
                        "sni_host": "nrt1.nbwly.com",
                        "path_prefix": "07289d1f5ed25399",
                        "probe_target": "127.0.0.1:8899",
                    },
                )

            self.assertEqual(results[0]["ip"], "162.159.39.4")
            self.assertEqual(results[0]["failures"], 0)
            self.assertEqual(results[1]["ip"], "162.159.39.6")
            self.assertEqual(results[1]["failures"], 1)


    def test_probe_target_default_requires_multiple_clean_samples_for_candidate(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            with mock.patch.object(agent, "tcp_ping", side_effect=[30.0, 30.0, 41.0, 42.0, 43.0]), mock.patch.object(
                agent,
                "probe_cf_websocket_path",
                side_effect=[
                    (True, 70.0, None),
                    (False, None, "Invalid status code: 520"),
                    (True, 90.0, None),
                    (True, 91.0, None),
                    (True, 92.0, None),
                ],
            ):
                results = agent.probe_cf_candidates(
                    ["162.159.39.4", "162.159.39.29"],
                    {
                        "dial_port": 2053,
                        "sni_host": "nrt1.nbwly.com",
                        "path_prefix": "07289d1f5ed25399",
                        "probe_target": "127.0.0.1:8899",
                    },
                )

            self.assertEqual(results[0]["ip"], "162.159.39.29")
            self.assertTrue(results[0]["ok"])
            rejected = next(item for item in results if item["ip"] == "162.159.39.4")
            self.assertFalse(rejected["ok"])
            self.assertEqual(rejected["failures"], 1)

    def test_probe_cf_candidates_uses_real_path_probe_samples(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            with mock.patch.object(
                agent,
                "probe_cf_websocket_path",
                side_effect=[
                    (True, 40.0, None),
                    (True, 44.0, None),
                    (False, None, "timeout"),
                    (True, 30.0, None),
                ],
            ):
                results = agent.probe_cf_candidates(
                    ["162.159.39.10", "162.159.39.11"],
                    {
                        "dial_port": 2053,
                        "sni_host": "nrt1.nbwly.com",
                        "path_prefix": "07289d1f5ed25399",
                        "probe_samples": 2,
                    },
                )

            self.assertEqual(results[0]["ip"], "162.159.39.11")
            self.assertEqual(results[0]["latency_ms"], 30.0)
            self.assertEqual(results[0]["jitter_ms"], 0.0)
            self.assertEqual(results[0]["failures"], 1)
            self.assertEqual(results[1]["ip"], "162.159.39.10")
            self.assertEqual(results[1]["latency_ms"], 42.0)
            self.assertEqual(results[1]["jitter_ms"], 4.0)

    def test_probe_cf_candidates_passes_probe_target_to_real_path_probe(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            agent = self.make_agent(tmp_dir)
            with mock.patch.object(agent, "probe_cf_websocket_path", return_value=(True, 31.0, None)) as probe_mock:
                agent.probe_cf_candidates(
                    ["162.159.39.10"],
                    {
                        "dial_port": 2053,
                        "sni_host": "nrt1.nbwly.com",
                        "path_prefix": "07289d1f5ed25399",
                        "probe_target": "127.0.0.1:8899",
                        "probe_samples": 1,
                    },
                )

            probe_mock.assert_called_once_with(
                "162.159.39.10",
                2053,
                "nrt1.nbwly.com",
                "07289d1f5ed25399",
                "127.0.0.1:8899",
            )
