import asyncio
import importlib.util
import json
import pathlib
import socket
import struct
import sys
import tempfile
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


preconnect = load_module("preconnect_relay_under_test", "slave-agent/preconnect_relay.py")


async def read_handshake(reader: asyncio.StreamReader) -> dict:
    header = await reader.readexactly(4)
    length = struct.unpack(">I", header)[0]
    return json.loads((await reader.readexactly(length)).decode("utf-8"))


class PreconnectRelayTests(unittest.IsolatedAsyncioTestCase):
    async def test_udp_datagram_is_uot_framed_through_slave_relay(self) -> None:
        remote_handshakes: list[dict] = []
        remote_frames: list[bytes] = []
        remote_ready = asyncio.Event()

        async def remote_handler(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
            remote_handshakes.append(await read_handshake(reader))
            remote_ready.set()
            header = await reader.readexactly(4)
            length = struct.unpack(">I", header)[0]
            remote_frames.append(await reader.readexactly(length))
            writer.write(struct.pack(">I", 4) + b"pong")
            await writer.drain()
            writer.close()
            await writer.wait_closed()

        remote_server = await asyncio.start_server(remote_handler, "127.0.0.1", 0)
        remote_port = remote_server.sockets[0].getsockname()[1]
        uot_port = remote_port + 1
        while True:
            probe = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
            try:
                probe.bind(("127.0.0.1", uot_port))
                probe.close()
                break
            except OSError:
                probe.close()
                uot_port += 1

        config = {
            "listen_host": "127.0.0.1",
            "listen_port": uot_port + 1,
            "uot_listen_host": "127.0.0.1",
            "uot_listen_port": uot_port,
            "sidecar_host": "127.0.0.1",
            "sidecar_port": remote_port,
            "tcp_session_token": "tcp-token",
            "udp_session_token": "udp-token",
            "forward_id": 47,
            "transport": "udp",
        }
        relay = preconnect.PreConnectRelay(config)
        uot_server = await relay.start_uot_listener()
        try:
            reader, writer = await asyncio.open_connection("127.0.0.1", uot_port)
            try:
                writer.write(struct.pack(">I", 4) + b"ping")
                await writer.drain()
                header = await asyncio.wait_for(reader.readexactly(4), timeout=2)
                length = struct.unpack(">I", header)[0]
                response = await asyncio.wait_for(reader.readexactly(length), timeout=2)
                await asyncio.wait_for(remote_ready.wait(), timeout=2)
            finally:
                writer.close()
                await writer.wait_closed()
        finally:
            uot_server.close()
            await uot_server.wait_closed()
            remote_server.close()
            await remote_server.wait_closed()

        self.assertEqual(b"pong", response)
        self.assertEqual("udp", remote_handshakes[0]["protocol"])
        self.assertEqual("udp-token", remote_handshakes[0]["session_token"])
        self.assertEqual(b"ping", remote_frames[0])


class PreconnectSlaveAgentTests(unittest.TestCase):
    def test_preconnect_relay_config_includes_uot_udp_when_transport_has_udp(self) -> None:
        slave_agent = load_module("slave_agent_preconnect_under_test", "slave-agent/slave_agent.py")
        with tempfile.TemporaryDirectory() as tmp:
            root = pathlib.Path(tmp)
            config_path = root / "config.json"
            config_path.write_text(
                json.dumps({
                    "master_url": "https://panel.example.com",
                    "node_token": "token",
                    "state_dir": str(root / "state"),
                    "systemd_dir": str(root / "systemd"),
                }),
                encoding="utf-8",
            )
            agent = slave_agent.SlaveAgent(config_path)
            underlay = {
                "slave_preconnect": True,
                "slave_address": "10.203.201.134",
                "sidecar_address": "10.203.201.135",
                "preconnect_relay_port": 8076,
                "preconnect_uot_port": 8077,
                "sidecar_mptcp_port": 14969,
                "tcp_session_token": "tcp-token",
                "udp_session_token": "udp-token",
                "transport": "tcp+udp",
                "forward_id": 47,
            }
            with mock.patch.object(slave_agent.shutil, "which", return_value=None):
                result = agent.ensure_preconnect_relay("wstunnel-svc-47-316", underlay)

            relay_config = json.loads(agent.preconnect_relay_config_path("wstunnel-svc-47-316").read_text(encoding="utf-8"))
            self.assertEqual(result["relay_port"], 8076)
            self.assertEqual(result["uot_relay_port"], 8077)
            self.assertEqual(relay_config["transport"], "tcp+udp")
            self.assertEqual(relay_config["udp_session_token"], "udp-token")
            self.assertEqual(relay_config["uot_listen_host"], "10.203.201.134")
            self.assertEqual(relay_config["uot_listen_port"], 8077)
            self.assertEqual(relay_config["health_host"], "127.0.0.1")


if __name__ == "__main__":
    unittest.main()
