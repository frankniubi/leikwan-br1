import importlib.util
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


client_agent_module = load_module("client_agent_self_leg_runtime_shape_under_test", "client-agent/client_agent.py")


class SelfLegDirectCfRuntimeShapeTests(unittest.TestCase):
    def test_self_leg_candidate_requires_explicit_direct_cf_fields(self) -> None:
        agent = client_agent_module.ClientAgent.__new__(client_agent_module.ClientAgent)
        candidate = {
            "candidate_kind": "self_leg",
            "probe_transport": "cf_direct_wss",
            "dial_host": "162.159.39.16",
            "dial_port": 2053,
            "cf_sni_host": "nrt1.nbwly.com",
            "path_prefix": "07289d1f5ed25399",
        }
        shape = client_agent_module.ClientAgent.normalize_self_leg_runtime_shape(agent, candidate)
        self.assertEqual("cf_direct_wss", shape["probe_transport"])
        self.assertEqual("162.159.39.16", shape["dial_host"])
        self.assertEqual(2053, shape["dial_port"])


if __name__ == "__main__":
    unittest.main()
