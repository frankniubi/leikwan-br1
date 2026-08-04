import importlib.util
import pathlib
import sys
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[2]
MODULE_PATH = ROOT / "client-agent" / "install_forward_runtime.py"
SPEC = importlib.util.spec_from_file_location("single_race_sidecar_frontend_runtime", MODULE_PATH)
assert SPEC is not None and SPEC.loader is not None
runtime = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(runtime)

AGENT_MODULE_PATH = ROOT / "client-agent" / "client_agent.py"
AGENT_SPEC = importlib.util.spec_from_file_location("single_race_sidecar_frontend_agent", AGENT_MODULE_PATH)
assert AGENT_SPEC is not None and AGENT_SPEC.loader is not None
client_agent = importlib.util.module_from_spec(AGENT_SPEC)
sys.modules[AGENT_SPEC.name] = client_agent
AGENT_SPEC.loader.exec_module(client_agent)


def frontend_forward(mode: str) -> dict:
    return {
        "service_id": 6,
        "forward_id": 91 if mode == "single" else 92,
        "listen_port": 11091 if mode == "single" else 11092,
        "target": "v4-hkt-b.mcbar.cc:5201",
        "sni": "edge.example.test",
        "path_prefix": f"frontend-{mode}",
        "transport": "tcp+udp",
        "mode": mode,
        "backend_mode": "current_mode",
        "active_family": "sidecar_aggregation",
        "target_contract": "sidecar_aggregation",
        "sidecar_required": True,
        "aggregation_enabled": False,
        "bonding": {
            "enabled": True,
            "frontend_mode": mode,
            "strategy_locked": True,
            "ingress_mode": "plain_tcp_mux",
            "route_key": f"frontend-{mode}",
            "remote_target": "10.240.10.1:18501",
            "session_token": "tcp-token",
            "udp_session_token": "udp-token",
            "quic_enabled": False,
            "quic_via_slave": False,
            "wss_standby_enabled": False,
            "policy_mode": "latency_first",
            "udp_primary_only": True,
        },
        "endpoints": [
            {
                "endpoint_id": 601,
                "node_id": 2,
                "host": "203.0.113.11",
                "port": 7000,
                "priority": 10,
                "ingress_protocol": "plain_tcp_mux",
                "ingress_auth_token": "auth-601",
                "supports_quic_leg": False,
            },
            {
                "endpoint_id": 602,
                "node_id": 3,
                "host": "203.0.113.12",
                "port": 7000,
                "priority": 20,
                "ingress_protocol": "plain_tcp_mux",
                "ingress_auth_token": "auth-602",
                "supports_quic_leg": False,
            },
        ],
    }


class SingleRaceSidecarFrontendTests(unittest.TestCase):
    def test_agent_recognizes_only_explicit_sidecar_frontends_as_bond_runtime(self) -> None:
        self.assertEqual(
            "single",
            client_agent.ClientAgent.sidecar_frontend_mode(frontend_forward("single")),
        )
        self.assertEqual(
            "race",
            client_agent.ClientAgent.sidecar_frontend_mode(frontend_forward("race")),
        )
        self.assertTrue(client_agent.ClientAgent.forward_uses_bond_runtime(frontend_forward("single")))
        self.assertTrue(client_agent.ClientAgent.forward_uses_bond_runtime(frontend_forward("race")))

        legacy = frontend_forward("single")
        legacy["bonding"] = {}
        legacy["sidecar_required"] = False
        legacy["active_family"] = "single"
        legacy["target_contract"] = "direct_forward_node"
        self.assertEqual("", client_agent.ClientAgent.sidecar_frontend_mode(legacy))
        self.assertFalse(client_agent.ClientAgent.forward_uses_bond_runtime(legacy))

        bond = frontend_forward("single")
        bond["mode"] = "bond"
        bond["bonding"]["frontend_mode"] = ""
        self.assertTrue(client_agent.ClientAgent.forward_uses_bond_runtime(bond))

    def test_single_uses_one_locked_bond_frontend_leg(self) -> None:
        rendered = runtime.render_forward_runtime(frontend_forward("single"), {})
        script = str(rendered.get("start_script") or "")

        self.assertEqual(2, script.count("leikwan-ingress client --listen"))
        self.assertEqual(2, script.count("--remote 203.0.113.11:7000"))
        self.assertNotIn("203.0.113.12:7000", script)
        self.assertIn("leikwan-bond client --protocol tcp", script)
        self.assertIn("leikwan-bond client --protocol udp", script)
        self.assertNotIn("wstunnel client", script)
        self.assertNotIn("leikwan-tcp-race.py", script)
        self.assertNotIn("--strategy-file", script)
        self.assertIn("--default-strategy latency_best", script)

    def test_race_uses_all_locked_bond_frontend_legs(self) -> None:
        rendered = runtime.render_forward_runtime(frontend_forward("race"), {})
        script = str(rendered.get("start_script") or "")

        self.assertEqual(4, script.count("leikwan-ingress client --listen"))
        self.assertEqual(2, script.count("--remote 203.0.113.11:7000"))
        self.assertEqual(2, script.count("--remote 203.0.113.12:7000"))
        self.assertIn("leikwan-bond client --protocol tcp", script)
        self.assertIn("leikwan-bond client --protocol udp", script)
        self.assertNotIn("wstunnel client", script)
        self.assertNotIn("leikwan-tcp-race.py", script)
        self.assertNotIn("--strategy-file", script)
        self.assertIn("--default-strategy latency_best", script)

    def test_ungated_single_keeps_legacy_wss_runtime(self) -> None:
        forward = frontend_forward("single")
        forward["bonding"] = {}
        forward["sidecar_required"] = False
        forward["active_family"] = "single"
        forward["target_contract"] = "direct_forward_node"
        rendered = runtime.render_forward_runtime(forward, {})
        combined = str(rendered.get("service_unit") or "") + str(rendered.get("start_script") or "")

        self.assertIn("wstunnel client", combined)
        self.assertNotIn("leikwan-bond client", combined)


if __name__ == "__main__":
    unittest.main()
