import asyncio
import importlib.util
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


install_runtime = load_module(
    "install_forward_runtime_preconnect_under_test",
    "client-agent/install_forward_runtime.py",
)


class MptcpPreconnectRuntimeTests(unittest.TestCase):
    def test_mptcp_render_preserves_slave_relay_fields_for_tcp_and_udp(self) -> None:
        forward = {
            "forward_id": 47,
            "listen_port": 11035,
            "mode": "mptcp",
            "transport": "tcp+udp",
            "target": "127.0.0.1:5201",
            "sni": "v3.example.com",
            "path_prefix": "forward-47",
            "mptcp": {
                "sidecar_host": "10.203.201.135",
                "sidecar_port": 14969,
                "relay_host": "sidecar.example.com",
                "relay_port": 14969,
                "tcp_session_token": "tcp-token",
                "udp_session_token": "udp-token",
                "slave_relay_host": "10.203.201.134",
                "slave_relay_port": 8076,
                "slave_uot_relay_host": "10.203.201.134",
                "slave_uot_relay_port": 8077,
            },
        }

        rendered = install_runtime.render_forward_runtime(forward, {"state_dir": "/var/lib/leikwan-client-agent"})
        config_file = rendered["files"][0]

        self.assertIn('"slave_relay_host": "10.203.201.134"', config_file["content"])
        self.assertIn('"slave_relay_port": 8076', config_file["content"])
        self.assertIn('"slave_uot_relay_host": "10.203.201.134"', config_file["content"])
        self.assertIn('"slave_uot_relay_port": 8077', config_file["content"])
        self.assertIn('"transport": "tcp+udp"', config_file["content"])
        self.assertIn('"udp_session_token": "udp-token"', config_file["content"])


class FakeWriter:
    def __init__(self) -> None:
        self.writes: list[bytes] = []
        self.closed = False

    def write(self, data: bytes) -> None:
        self.writes.append(data)

    async def drain(self) -> None:
        return None

    def close(self) -> None:
        self.closed = True

    async def wait_closed(self) -> None:
        return None


class MptcpClientUdpPreconnectTests(unittest.IsolatedAsyncioTestCase):
    async def test_udp_uses_slave_uot_relay_without_client_side_handshake(self) -> None:
        client_runtime = load_module(
            "leikwan_mptcp_client_udp_preconnect_under_test",
            "client-agent/leikwan_mptcp_client.py",
        )
        calls: list[tuple[str, int]] = []
        fake_reader = asyncio.StreamReader()
        fake_writer = FakeWriter()

        async def open_slave(host: str, port: int, *_args, **_kwargs):
            calls.append((host, port))
            return fake_reader, fake_writer

        async def fail_open_remote(*_args, **_kwargs):
            raise AssertionError("UDP preconnect must not open a direct MPTCP stream")

        async def fail_handshake(*_args, **_kwargs):
            raise AssertionError("UDP preconnect must leave the sidecar handshake to the slave relay")

        config = {
            "forward_id": 47,
            "remote_host": "10.203.201.135",
            "remote_port": 14969,
            "slave_relay_host": "10.203.201.134",
            "slave_relay_port": 8076,
            "slave_uot_relay_host": "10.203.201.134",
            "slave_uot_relay_port": 8077,
            "connect_timeout_ms": 3000,
            "allow_tcp_fallback": False,
            "tcp_congestion_control": "bbr",
            "socket_buffer_bytes": 8388608,
        }
        protocol = client_runtime.UdpCompatProtocol(config)

        with mock.patch.object(client_runtime, "open_slave_relay_stream", side_effect=open_slave), \
             mock.patch.object(client_runtime, "open_remote_stream", side_effect=fail_open_remote), \
             mock.patch.object(client_runtime, "send_handshake", side_effect=fail_handshake):
            await protocol.handle_datagram(b"ping", ("127.0.0.1", 53000))

        self.assertEqual([("10.203.201.134", 8077)], calls)
        self.assertEqual([b"\x00\x00\x00\x04", b"ping"], fake_writer.writes)

        assoc = protocol.associations[("127.0.0.1", 53000)]
        assoc.task.cancel()
        with self.assertRaises(asyncio.CancelledError):
            await assoc.task


if __name__ == "__main__":
    unittest.main()
