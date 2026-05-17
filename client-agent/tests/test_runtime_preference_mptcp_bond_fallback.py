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
