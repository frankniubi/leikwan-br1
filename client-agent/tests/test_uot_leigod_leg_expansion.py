"""Tests for the UoT sidepath leigod multi-line leg expansion.

When udp_uot_sidepath.forward_leigod_lines assigns leigod lines to a bond forward,
the client renders one leg per (endpoint, line): each leg opens the slave's per-line
shadow route (<route_key><suffix>.l<line_id>) with path_group=line_id. The client only
ever opens plain-TCP ingress legs -- SOCKS5 is terminated on the slave, never here.
"""

import importlib.util
import json
import pathlib
import sys
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


install_forward_runtime = load_module(
    "install_forward_runtime_leigod_expansion_test",
    "client-agent/install_forward_runtime.py",
)

FORWARD = {
    "forward_id": 26,
    "listen_port": 11024,
    "target": "193.227.109.82:5201",
    "sni": "br1.nbwly.com",
    "path_prefix": "a43547b6350f72cd",
    "transport": "tcp+udp",
    "mode": "bond",
    "endpoints": [
        {"endpoint_id": 184, "host": "8.163.90.132", "port": 7001, "ingress_auth_token": "token-1"},
    ],
    "bonding": {
        "ingress_mode": "plain_tcp_mux",
        "route_key": "a43547b6350f72cd",
        "remote_target": "138.252.162.120:9000",
        "session_token": "tcp-token",
        "udp_session_token": "udp-token",
    },
}

SIDEPATH = {
    "enabled_forward_ids": [26],
    "shadow_route_suffix": ".uot",
    "sidecar_port": 8999,
    "scheduler_mode": "feedback_drr",
    "wireguard_latency_sensitive_max_bytes": 256,
    "wireguard_latency_sensitive_copies": 2,
}

AGENT_CONFIG = {
    "bond_bin": "/usr/local/bin/leikwan-bond",
    "udp_uot_sidepath": SIDEPATH,
}


@mock.patch.object(
    install_forward_runtime,
    "probe_uot_build_info",
    return_value={
        "protocol_version": 2,
        "capabilities": ["leg-progress-v1", "selective-first-wins-v1"],
        "scheduler_modes": ["legacy", "feedback_drr"],
    },
    create=True,
)
class UotLeigodLegExpansionTests(unittest.TestCase):
    def render_uot_config(self, sidepath_overrides=None):
        sidepath = dict(SIDEPATH)
        if sidepath_overrides:
            sidepath.update(sidepath_overrides)
        agent_config = dict(AGENT_CONFIG)
        agent_config["udp_uot_sidepath"] = sidepath
        rendered = install_forward_runtime.render_forward_runtime(dict(FORWARD), agent_config)
        self.assertEqual(len(rendered["extra_units"]), 1)
        udp_unit = rendered["extra_units"][0]
        config_file = next(f for f in udp_unit["files"] if f["path"].endswith(".json"))
        return json.loads(config_file["content"])

    def test_no_line_assignment_keeps_flat_legs(self, _probe) -> None:
        config = self.render_uot_config()
        self.assertEqual(len(config["legs"]), 1)
        self.assertEqual(config["legs"][0]["endpoint_id"], 184)
        self.assertNotIn("route_key", config["legs"][0])

    def test_line_assignment_fans_out_legs_with_route_and_path_group(self, _probe) -> None:
        config = self.render_uot_config({"forward_leigod_lines": {"26": [455, 693, 456]}})
        legs = config["legs"]
        self.assertEqual(len(legs), 3)
        endpoint_ids = {leg["endpoint_id"] for leg in legs}
        self.assertEqual(len(endpoint_ids), 3, "leg endpoint_ids must be unique")
        by_group = {leg["path_group"]: leg for leg in legs}
        self.assertEqual(set(by_group), {455, 693, 456})
        self.assertEqual(by_group[455]["route_key"], "a43547b6350f72cd.uot.l455")
        self.assertEqual(by_group[693]["route_key"], "a43547b6350f72cd.uot.l693")
        for leg in legs:
            # legs still open the slave ingress with plain TCP auth -- no socks5 anywhere
            self.assertEqual(leg["host"], "8.163.90.132")
            self.assertEqual(leg["port"], 7001)
            self.assertEqual(leg["auth_token"], "token-1")
            self.assertNotIn("socks5_proxy", leg)

    def test_assignment_for_other_forward_is_ignored(self, _probe) -> None:
        config = self.render_uot_config({"forward_leigod_lines": {"27": [455]}})
        self.assertEqual(len(config["legs"]), 1)
        self.assertNotIn("route_key", config["legs"][0])

    def test_first_wins_fields_land_in_rendered_config(self, _probe) -> None:
        config = self.render_uot_config()
        self.assertEqual(config["wireguard_latency_sensitive_max_bytes"], 256)
        self.assertEqual(config["wireguard_latency_sensitive_copies"], 2)
        self.assertEqual(config["startup_leg_window_bytes"], 131072)
        self.assertEqual(config["session_reorder_budget_ms"], 1)

    def test_first_wins_copies_two_requires_feedback_drr(self, _probe) -> None:
        with self.assertRaises(ValueError):
            self.render_uot_config({"scheduler_mode": "legacy"})


if __name__ == "__main__":
    unittest.main()
