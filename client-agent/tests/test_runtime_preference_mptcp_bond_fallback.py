import importlib.util
import json
import tempfile
from types import SimpleNamespace
import pathlib
import sys
import unittest

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(name: str, relative: str):
    path = REPO_ROOT / relative
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


client_agent_module = load_module("client_agent_runtime_pref_under_test", "client-agent/client_agent.py")


class RuntimePreferenceTests(unittest.TestCase):
    def test_dedicated_leigod_legacy_mptcp_contract_resolves_to_single_and_no_sidecar(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        forward = {
            "mode": "mptcp",
            "client_mode": "mptcp",
            "backend_mode": "leigod_mode",
            "active_family": "leigod_only",
            "target_contract": "dedicated_forward_node",
            "aggregation_enabled": False,
            "sidecar_required": False,
            "runtime_preference": "mptcp_preferred_bond_fallback",
            "bond_sidecar_point_id": 7,
            "bond_sidecar_host": "stale-sidecar.invalid",
            "bond_sidecar_port": 9443,
            "mptcp_sidecar_port": 8898,
            "bonding": {"session_token": "stale-token", "remote_target": "127.0.0.1:8899"},
            "mptcp": {"tcp_session_token": "stale-token", "sidecar_host": "127.0.0.1", "sidecar_port": 8898},
            "candidate_pool": [
                {"candidate_kind": "rented_group_leg", "selected_primary": True, "runtime_capabilities": ["bond", "mptcp"]},
            ],
        }

        family = client_agent_module.ClientAgent.choose_runtime_family(agent, forward, mptcp_supported=True)
        sanitized = client_agent_module.ClientAgent.canonicalize_forward_runtime_contract(agent, forward, family)

        self.assertEqual("single", family)
        self.assertEqual("single", sanitized["mode"])
        self.assertEqual("single", sanitized["client_mode"])
        self.assertEqual("single", sanitized["runtime_preference"])
        self.assertFalse(sanitized["sidecar_required"])
        self.assertFalse(sanitized["aggregation_enabled"])
        for key in (
            "bond_sidecar_point_id",
            "bond_sidecar_host",
            "bond_sidecar_port",
            "mptcp_sidecar_port",
            "bonding",
            "mptcp",
        ):
            self.assertNotIn(key, sanitized)

    def test_sidecar_aggregation_mptcp_preferred_bond_fallback_resolves_to_bond(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        family = client_agent_module.ClientAgent.choose_runtime_family(
            agent,
            {
                "mode": "mptcp",
                "backend_mode": "leigod_mode",
                "active_family": "sidecar_aggregation",
                "target_contract": "sidecar_aggregation",
                "aggregation_enabled": True,
                "sidecar_required": True,
                "runtime_preference": "mptcp_preferred_bond_fallback",
                "mptcp": {"tcp_session_token": "tcp-token", "sidecar_host": "10.203.0.9", "sidecar_port": 8898},
                "candidate_pool": [
                    {"candidate_kind": "rented_group_leg", "selected_primary": True, "runtime_capabilities": ["bond", "mptcp"]},
                    {"candidate_kind": "rented_group_leg", "runtime_capabilities": ["bond", "mptcp"]},
                ],
            },
            mptcp_supported=True,
        )

        self.assertEqual("bond", family)

    def test_self_leg_primary_forces_bond_family(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        family = client_agent_module.ClientAgent.choose_runtime_family(
            agent,
            {
                "runtime_preference": "mptcp_preferred_bond_fallback",
                "candidate_pool": [
                    {"candidate_kind": "self_leg", "selected_primary": True, "runtime_capabilities": ["bond"]},
                    {"candidate_kind": "rented_group_leg", "runtime_capabilities": ["bond", "mptcp"]},
                ],
            },
            mptcp_supported=True,
        )
        self.assertEqual("bond", family)

    def test_cf_direct_self_leg_without_mptcp_runtime_config_uses_bond_family_even_when_mptcp_capable(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        family = client_agent_module.ClientAgent.choose_runtime_family(
            agent,
            {
                "mode": "mptcp",
                "runtime_preference": "mptcp_preferred_bond_fallback",
                "backend_mode": "cf_mode",
                "candidate_pool": [
                    {
                        "candidate_kind": "self_leg",
                        "selected_primary": True,
                        "runtime_capabilities": ["bond", "mptcp"],
                        "probe_transport": "cf_direct_wss",
                        "target_sidecar_bond_port": 8899,
                        "target_sidecar_mptcp_port": 8898,
                    },
                    {"candidate_kind": "rented_group_leg", "runtime_capabilities": ["bond", "mptcp"]},
                ],
            },
            mptcp_supported=True,
        )
        self.assertEqual("bond", family)

    def test_cf_direct_self_leg_uses_bond_family_even_when_mptcp_runtime_config_is_present(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        family = client_agent_module.ClientAgent.choose_runtime_family(
            agent,
            {
                "mode": "mptcp",
                "runtime_preference": "mptcp_preferred_bond_fallback",
                "mptcp": {"tcp_session_token": "tcp-token", "sidecar_port": 8898},
                "candidate_pool": [
                    {
                        "candidate_kind": "self_leg",
                        "selected_primary": True,
                        "runtime_capabilities": ["bond", "mptcp"],
                        "probe_transport": "cf_direct_wss",
                        "target_sidecar_bond_port": 8899,
                        "target_sidecar_mptcp_port": 8898,
                    },
                    {"candidate_kind": "rented_group_leg", "runtime_capabilities": ["bond", "mptcp"]},
                ],
            },
            mptcp_supported=True,
        )
        self.assertEqual("bond", family)



    def test_apply_forward_canonicalizes_dedicated_leigod_before_rendering(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            tmp = pathlib.Path(tmp_dir)
            captured = {}
            rendered = {
                "service_name": "leikwan-client-47",
                "service_path": str(tmp / "leikwan-client-47.service"),
                "service_unit": "[Service]\nExecStart=/bin/true\n",
                "start_script_path": str(tmp / "start-47.sh"),
                "start_script": "#!/bin/sh\nexit 0\n",
                "files": [],
                "extra_units": [],
            }

            def render_forward_runtime(forward, agent_config):
                captured["forward"] = dict(forward)
                return rendered

            previous_module = sys.modules.get("install_forward_runtime")
            sys.modules["install_forward_runtime"] = SimpleNamespace(render_forward_runtime=render_forward_runtime)
            try:
                agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
                agent.select_forward_candidates = lambda forward: []
                agent.running_kernel_supports_mptcp = lambda: True
                agent.filter_mptcp_capable_candidates = lambda pool: pool
                agent.apply_mptcp_runtime_health_policy = lambda forward: forward
                agent.apply_latency_target_candidate_escalation = lambda forward: forward
                agent.select_bond_runtime_endpoints = lambda forward: forward.get("endpoints", [])
                agent.semantic_agent_config = lambda: {"bond_bin": "/bin/true"}
                agent.bond_binary_path = lambda: pathlib.Path("/bin/true")
                agent.mptcp_client_path = lambda: pathlib.Path(__file__)
                agent.binary_available = lambda path: True
                agent.delete_nft_rules = lambda forward_id: None
                agent.add_nft_rules = lambda forward_id, listen_port, transport: None
                agent.load_applied = lambda: {}
                agent.write_text_if_changed = lambda *args, **kwargs: False
                agent.systemd_is_active = lambda service_name: True
                agent.applied_path = tmp / "applied.json"
                agent.cleanup_mptcp_forward_runtime = lambda forward_id: None
                agent.mptcp_forward_runtime_interfaces = lambda forward_id: []

                agent.apply_forward(
                    {
                        "forward_id": 47,
                        "listen_port": 11035,
                        "mode": "mptcp",
                        "client_mode": "mptcp",
                        "backend_mode": "leigod_mode",
                        "active_family": "leigod_only",
                        "target_contract": "dedicated_forward_node",
                        "aggregation_enabled": False,
                        "sidecar_required": False,
                        "runtime_preference": "mptcp_preferred_bond_fallback",
                        "transport": "tcp",
                        "target": "162.245.221.78:80",
                        "sni": "edge.example.com",
                        "path_prefix": "dedicated-route",
                        "endpoints": [{"endpoint_id": 316, "host": "203.0.113.12", "port": 443}],
                        "bond_sidecar_point_id": 7,
                        "bond_sidecar_host": "stale-sidecar.invalid",
                        "bond_sidecar_port": 9443,
                        "mptcp_sidecar_port": 8898,
                        "mptcp": {"tcp_session_token": "stale-token", "sidecar_host": "127.0.0.1", "sidecar_port": 8898},
                        "bonding": {"session_token": "stale-token", "remote_target": "127.0.0.1:8899"},
                        "candidate_pool": [
                            {"candidate_kind": "rented_group_leg", "selected_primary": True, "runtime_capabilities": ["bond", "mptcp"]},
                        ],
                    }
                )
            finally:
                if previous_module is None:
                    sys.modules.pop("install_forward_runtime", None)
                else:
                    sys.modules["install_forward_runtime"] = previous_module

            rendered_forward = captured["forward"]
            self.assertEqual("single", rendered_forward["mode"])
            self.assertEqual("single", rendered_forward["client_mode"])
            self.assertEqual("single", rendered_forward["runtime_preference"])
            self.assertFalse(rendered_forward["sidecar_required"])
            self.assertFalse(rendered_forward["aggregation_enabled"])
            for key in ("bond_sidecar_point_id", "bond_sidecar_host", "bond_sidecar_port", "mptcp_sidecar_port", "bonding", "mptcp"):
                self.assertNotIn(key, rendered_forward)
            applied = json.loads(agent.applied_path.read_text(encoding="utf-8"))
            self.assertEqual("single", applied["47"]["runtime_mode"])

    def test_apply_forward_persists_effective_runtime_mode_for_health_checks(self) -> None:
        with tempfile.TemporaryDirectory() as tmp_dir:
            tmp = pathlib.Path(tmp_dir)
            rendered = {
                "service_name": "leikwan-client-47",
                "service_path": str(tmp / "leikwan-client-47.service"),
                "service_unit": "[Service]\nExecStart=/bin/true\n",
                "start_script_path": str(tmp / "start-47.sh"),
                "start_script": "#!/bin/sh\nexit 0\n",
                "files": [],
                "extra_units": [],
            }
            sys.modules["install_forward_runtime"] = SimpleNamespace(render_forward_runtime=lambda forward, agent_config: rendered)
            agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
            agent.select_forward_candidates = lambda forward: [
                {"candidate_kind": "self_leg", "selected_primary": True, "probe_transport": "cf_direct_wss", "target_sidecar_bond_port": 8899}
            ]
            agent.running_kernel_supports_mptcp = lambda: True
            agent.choose_runtime_family = lambda forward, supported: "bond"
            agent.apply_latency_target_candidate_escalation = lambda forward: forward
            agent.select_bond_runtime_endpoints = lambda forward: [{"endpoint_id": 316, "host": "47.116.11.144", "port": 7000}]
            agent.semantic_agent_config = lambda: {"bond_bin": "/bin/true"}
            agent.bond_binary_path = lambda: pathlib.Path("/bin/true")
            agent.mptcp_client_path = lambda: pathlib.Path(__file__)
            agent.binary_available = lambda path: True
            agent.delete_nft_rules = lambda forward_id: None
            agent.add_nft_rules = lambda forward_id, listen_port, transport: None
            agent.load_applied = lambda: {}
            agent.write_text_if_changed = lambda *args, **kwargs: False
            agent.systemd_is_active = lambda service_name: True
            agent.applied_path = tmp / "applied.json"
            agent.cleanup_mptcp_forward_runtime = lambda forward_id: None

            agent.apply_forward(
                {
                    "forward_id": 47,
                    "listen_port": 11035,
                    "mode": "mptcp",
                    "runtime_preference": "mptcp_preferred_bond_fallback",
                    "transport": "tcp",
                    "target": "185.220.238.143:5201",
                    "endpoints": [{"endpoint_id": 316, "host": "47.116.11.144", "port": 7000}],
                    "mptcp": {"tcp_session_token": "tcp-token", "sidecar_port": 8898},
                    "bonding": {"session_token": "tcp-token", "remote_target": "127.0.0.1:8899"},
                    "candidate_pool": [],
                }
            )

            applied = json.loads(agent.applied_path.read_text(encoding="utf-8"))
            self.assertEqual("mptcp", applied["47"]["published_mode"])
            self.assertEqual("bond", applied["47"]["runtime_mode"])
            agent.load_applied = lambda: applied
            self.assertEqual("bond", agent.applied_runtime_mode(47))

    def test_rented_only_candidates_prefer_mptcp_when_runtime_config_is_present(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        family = client_agent_module.ClientAgent.choose_runtime_family(
            agent,
            {
                "mode": "mptcp",
                "runtime_preference": "mptcp_preferred_bond_fallback",
                "mptcp": {"tcp_session_token": "tcp-token", "sidecar_port": 8898},
                "candidate_pool": [
                    {"candidate_kind": "rented_group_leg", "selected_primary": True, "runtime_capabilities": ["bond", "mptcp"]},
                ],
            },
            mptcp_supported=True,
        )
        self.assertEqual("mptcp", family)

    def test_bond_mode_rented_candidates_stay_bond_when_mptcp_runtime_config_is_missing(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        family = client_agent_module.ClientAgent.choose_runtime_family(
            agent,
            {
                "mode": "bond",
                "runtime_preference": "mptcp_preferred_bond_fallback",
                "candidate_pool": [
                    {"candidate_kind": "rented_group_leg", "selected_primary": True, "runtime_capabilities": ["bond", "mptcp"]},
                ],
            },
            mptcp_supported=True,
        )
        self.assertEqual("bond", family)

    def test_mptcp_forced_runtime_ignores_self_leg_primary(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        family = client_agent_module.ClientAgent.choose_runtime_family(
            agent,
            {
                "mode": "mptcp",
                "runtime_preference": "mptcp_forced",
                "candidate_pool": [
                    {"candidate_kind": "self_leg", "selected_primary": True, "runtime_capabilities": ["bond"]},
                    {"candidate_kind": "rented_group_leg", "runtime_capabilities": ["bond", "mptcp"]},
                ],
            },
            mptcp_supported=True,
        )
        self.assertEqual("mptcp", family)

    def test_solo_allowed_self_leg_can_keep_mptcp_when_helpers_are_slower(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        family = client_agent_module.ClientAgent.choose_runtime_family(
            agent,
            {
                "mode": "mptcp",
                "runtime_preference": "mptcp_preferred_bond_fallback",
                "candidate_pool": [
                    {
                        "candidate_kind": "self_leg",
                        "selected_primary": True,
                        "self_leg_mptcp_solo_allowed": True,
                        "runtime_capabilities": ["bond", "mptcp"],
                        "probe_transport": "cf_direct_wss",
                        "measured_rtt_ms": 35,
                    },
                    {
                        "candidate_kind": "rented_group_leg",
                        "runtime_capabilities": ["bond", "mptcp"],
                        "measured_rtt_ms": 80,
                    },
                ],
            },
            mptcp_supported=True,
        )
        self.assertEqual("mptcp", family)


if __name__ == "__main__":
    unittest.main()
