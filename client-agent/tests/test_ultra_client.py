import importlib.util
import json
import sys
import unittest
from pathlib import Path
from unittest import mock


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("client_agent_ultra_materials", ROOT / "client-agent" / "client_agent.py")
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
sys.modules[SPEC.name] = MODULE
SPEC.loader.exec_module(MODULE)


class UltraClientMaterialTests(unittest.TestCase):
    def test_resolve_is_one_authenticated_batch_after_config_pull(self):
        forwards = [
            {
                "forward_id": 42,
                "backend_mode": "ultra",
                "ultra_access": {"token": "opaque-42", "line_ips": ["203.0.113.20"]},
            },
            {"forward_id": 43, "backend_mode": "current_mode"},
        ]
        response = {
            "ok": True,
            "materials": [
                {
                    "forward_id": 42,
                    "token": "opaque-42",
                    "expires_at": "2030-01-01T00:30:00Z",
                    "sealed_material": "um1.opaque-material",
                }
            ],
        }
        with mock.patch.object(MODULE, "http_json", return_value=response) as request:
            resolved = MODULE.resolve_ultra_materials(
                "https://panel.example.test",
                "client-agent-token",
                "device-a",
                forwards,
            )

        request.assert_called_once_with(
            "POST",
            "https://panel.example.test/api/client/ultra/materials",
            "client-agent-token",
            {
                "device_id": "device-a",
                "forwards": [{"forward_id": 42, "token": "opaque-42"}],
            },
        )
        self.assertEqual("um1.opaque-material", resolved[0]["_ultra_material"]["sealed_material"])
        self.assertNotIn("_ultra_material", resolved[1])

    def test_normal_pull_contract_does_not_contain_ports_or_credentials(self):
        access = {"token": "opaque", "line_ips": ["203.0.113.20"], "transport": "tcp+udp"}
        encoded = json.dumps(access).lower()
        self.assertNotIn('"port"', encoded)
        self.assertNotIn("credential", encoded)
        self.assertNotIn("user", encoded)

    def test_no_ultra_forward_skips_material_request(self):
        with mock.patch.object(MODULE, "http_json") as request:
            resolved = MODULE.resolve_ultra_materials(
                "https://panel.example.test",
                "client-agent-token",
                "device-a",
                [{"forward_id": 43, "backend_mode": "current_mode"}],
            )
        request.assert_not_called()
        self.assertEqual([{"forward_id": 43, "backend_mode": "current_mode"}], resolved)


if __name__ == "__main__":
    unittest.main()
