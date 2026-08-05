from __future__ import annotations

import importlib.util
import pathlib
import unittest


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]
MODULE_PATH = REPO_ROOT / "client-agent" / "install_forward_runtime.py"
SPEC = importlib.util.spec_from_file_location("leigod_fixed_endpoint_bond_runtime", MODULE_PATH)
assert SPEC is not None and SPEC.loader is not None
runtime = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(runtime)


def fixed_forward() -> dict:
    return {
        "service_id": 2,
        "forward_id": 47,
        "listen_port": 12022,
        "target": "192.0.2.80:80",
        "sni": "edge.example.com",
        "path_prefix": "fixed-bond-route",
        "transport": "tcp+udp",
        "mode": "bond",
        "backend_mode": "leigod_mode",
        "active_family": "sidecar_aggregation",
        "target_contract": "sidecar_aggregation",
        "sidecar_required": True,
        "aggregation_enabled": True,
        "bonding": {
            "enabled": True,
            "fixed_endpoint": True,
            "fixed_endpoint_legs": [
                {"leg_route_id": route_id, "priority": 10}
                for route_id in (447, 451, 453, 455, 456)
            ],
            "preferred_initial_legs": 5,
            "minimum_initial_legs": 2,
            "initial_leg_wait_ms": 3000,
            "leg_reconnect_backoff_ms": 5000,
            "leg_reconnect_backoff_max_ms": 60000,
            "ingress_mode": "plain_tcp_mux",
            "route_key": "fixed-bond-route",
            "remote_target": "198.51.100.25:18501",
            "session_token": "tcp-token",
            "udp_session_token": "udp-token",
            "quic_enabled": False,
            "quic_via_slave": False,
            "wss_standby_enabled": False,
        },
        "endpoints": [
            {
                "endpoint_id": 316,
                "node_id": 15,
                "host": "203.0.113.15",
                "port": 7003,
                "runtime_target": "127.0.0.1:48316",
                "priority": 10,
                "ingress_protocol": "plain_tcp_mux",
                "ingress_auth_token": "auth-316",
                "supports_quic_leg": False,
            }
        ],
    }


class FixedEndpointBondRuntimeTests(unittest.TestCase):
    def test_one_physical_helper_expands_into_route_tagged_tcp_and_udp_legs(self) -> None:
        rendered = runtime.render_forward_runtime(fixed_forward(), {})
        script = str(rendered.get("start_script") or "")

        self.assertEqual(1, script.count("leikwan-ingress client --listen"))
        self.assertEqual(1, script.count("--remote 203.0.113.15:7003"))
        self.assertEqual(2, script.count("leikwan-bond client --protocol"))
        for leg_route_id in (447, 451, 453, 455, 456):
            self.assertEqual(2, script.count(f"leg_route_id={leg_route_id}"))
        self.assertEqual(2, script.count("--preferred-initial-legs 5"))
        self.assertEqual(2, script.count("--minimum-initial-legs 2"))
        self.assertEqual(2, script.count("--initial-leg-wait-ms 3000"))
        self.assertEqual(2, script.count("--leg-reconnect-backoff-ms 5000"))
        self.assertEqual(2, script.count("--leg-reconnect-backoff-max-ms 60000"))

    def test_fixed_leg_routes_must_be_two_to_eight_unique_positive_values(self) -> None:
        invalid_rows = {
            "one": [{"leg_route_id": 451}],
            "zero": [{"leg_route_id": 451}, {"leg_route_id": 0}],
            "duplicate": [{"leg_route_id": 451}, {"leg_route_id": 451}],
            "nine": [{"leg_route_id": route_id} for route_id in range(1, 10)],
        }
        for label, rows in invalid_rows.items():
            with self.subTest(label=label):
                forward = fixed_forward()
                forward["bonding"]["fixed_endpoint_legs"] = rows
                with self.assertRaisesRegex(RuntimeError, "fixed endpoint bond"):
                    runtime.render_forward_runtime(forward, {})

    def test_fixed_bond_requires_exactly_one_physical_endpoint(self) -> None:
        for endpoints in ([], fixed_forward()["endpoints"] * 2):
            with self.subTest(endpoint_count=len(endpoints)):
                forward = fixed_forward()
                forward["endpoints"] = endpoints
                with self.assertRaisesRegex(RuntimeError, "exactly one physical endpoint"):
                    runtime.render_forward_runtime(forward, {})

    def test_ordinary_bond_keeps_per_protocol_per_endpoint_helpers(self) -> None:
        forward = fixed_forward()
        forward["bonding"] = dict(forward["bonding"], fixed_endpoint=False)
        forward["endpoints"] = [
            dict(forward["endpoints"][0], endpoint_id=316, node_id=15, host="203.0.113.15", port=7003),
            dict(forward["endpoints"][0], endpoint_id=317, node_id=16, host="203.0.113.16", port=7004),
        ]

        script = str(runtime.render_forward_runtime(forward, {}).get("start_script") or "")

        self.assertEqual(4, script.count("leikwan-ingress client --listen"))
        self.assertEqual(2, script.count("--remote 203.0.113.15:7003"))
        self.assertEqual(2, script.count("--remote 203.0.113.16:7004"))
        self.assertNotIn("leg_route_id=", script)


if __name__ == "__main__":
    unittest.main()
