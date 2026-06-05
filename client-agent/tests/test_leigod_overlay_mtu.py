import importlib.util
import pathlib
import sys
import tempfile
import unittest

REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(module_name: str, relative_path: str):
    path = REPO_ROOT / relative_path
    spec = importlib.util.spec_from_file_location(module_name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[module_name] = module
    spec.loader.exec_module(module)
    return module


leikwan_mptcp_client = load_module(
    "leikwan_mptcp_client_mtu_under_test",
    "client-agent/leikwan_mptcp_client.py",
)


class OverlayMtuTests(unittest.TestCase):
    def _build(self, extra):
        with tempfile.TemporaryDirectory() as tmp_dir:
            private_key = pathlib.Path(tmp_dir) / "private.key"
            private_key.write_text("client-private\n", encoding="utf-8")
            base = {
                "mode": "slave_transit",
                "interface_name": "lw-mptcp-65-0",
                "local_private_key_path": str(private_key),
                "listen_port": 43003,
                "legs": [
                    {
                        "local_address": "10.203.28.153",
                        "peer_address": "10.203.28.154",
                        "mptcp_remote_address": "10.203.28.155",
                        "peer_public_key": "slave-pub",
                        "peer_public_host": "127.0.0.1",
                        "peer_udp_port": 47045,
                        "outer_transport": "tcp",
                        "peer_tcp_fallback_port": 47045,
                        "local_uot_udp_port": 47045,
                    }
                ],
            }
            base.update(extra)
            return leikwan_mptcp_client.build_wireguard_interface_config(base)

    def test_mtu_rendered_when_config_supplies_it(self):
        # The leigod/UoT underlay peers at 127.0.0.1, so wg-quick auto-derives MTU from
        # loopback (65456) and oversized segments black-hole on the ~1280 leigod path.
        # A panel-supplied mtu must be rendered into the [Interface] block.
        config = self._build({"mtu": 1240})
        self.assertIn("MTU = 1240", config)

    def test_no_mtu_line_when_absent(self):
        config = self._build({})
        self.assertNotIn("MTU =", config)


if __name__ == "__main__":
    unittest.main()
