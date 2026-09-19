import json
import pathlib
import sys
import unittest

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1] / "client-agent"))
from install_forward_runtime import render_ultra_runtime


class ExternalUotDeliveryModeTests(unittest.TestCase):
    def render(self, mode=None):
        grant = {"enabled": True, "sidecar_target": "127.0.0.1:7443", "token": "test-token"}
        if mode is not None:
            grant["delivery_mode"] = mode
        return render_ultra_runtime({
            "forward_id": 170, "listen_port": 10001, "target": "127.0.0.1:60042",
            "ultra_access": {
                "protocol": "leikwan-ultra-policy-v2", "session_ticket": "ticket",
                "policy_generation": "a" * 64, "target_digest": "b" * 64,
                "external_uot": grant,
            },
        }, {})

    def config(self, result):
        return json.loads(result["files"][0]["content"])

    def test_raw_omits_new_key_for_old_strict_runtime(self):
        for mode in (None, "", "raw"):
            with self.subTest(mode=mode):
                self.assertNotIn("delivery_mode", self.config(self.render(mode))["external_uot"])

    def test_reliable_mode_is_preserved(self):
        self.assertEqual("reliable_datagram", self.config(self.render("reliable_datagram"))["external_uot"]["delivery_mode"])

    def test_unsupported_mode_fails_closed(self):
        for mode in ("guaranteed_delivery", "invalid"):
            with self.subTest(mode=mode), self.assertRaisesRegex(RuntimeError, "delivery mode unsupported"):
                self.render(mode)


if __name__ == "__main__":
    unittest.main()
