from __future__ import annotations

import asyncio
import importlib.util
import pathlib
import socket
import struct
import sys
import tempfile
import unittest


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]
BRIDGE_PATH = REPO_ROOT / "slave-agent" / "leigod_tcp_accel_bridge.py"


def load_bridge_module():
    spec = importlib.util.spec_from_file_location("leigod_tcp_accel_bridge", BRIDGE_PATH)
    assert spec is not None and spec.loader is not None
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


class TcpAccelBridgeTests(unittest.TestCase):
    def test_validate_bridge_config_accepts_leigod_forward_shape_and_enforces_slave_port_range(self) -> None:
        bridge = load_bridge_module()
        config = {
            "remote": "10.10.10.171:29030",
            "connect_timeout_seconds": 10,
            "tcp_user_timeout_ms": 30000,
            "half_close_drain_timeout_ms": 2000,
            "listeners": [
                {"listen": "0.0.0.0:8230", "socks": "127.0.0.1:20455", "line_id": 455},
                {"listen": "0.0.0.0:8231", "socks": "127.0.0.1:20456", "line_id": 456},
            ],
        }

        normalized = bridge.validate_bridge_config(config)

        self.assertEqual("10.10.10.171:29030", normalized["remote"])
        self.assertEqual(["0.0.0.0:8230", "0.0.0.0:8231"], [item["listen"] for item in normalized["listeners"]])

        duplicate_line = dict(config)
        duplicate_line["listeners"] = [
            {"listen": "0.0.0.0:8230", "socks": "127.0.0.1:20455", "line_id": 455},
            {"listen": "0.0.0.0:8231", "socks": "127.0.0.1:20455", "line_id": 455},
        ]
        self.assertEqual([455, 455], [item["line_id"] for item in bridge.validate_bridge_config(duplicate_line)["listeners"]])

        for bad_port in (6999, 9001):
            bad = dict(config)
            bad["listeners"] = [{"listen": f"0.0.0.0:{bad_port}", "socks": "127.0.0.1:20455", "line_id": 455}]
            with self.subTest(port=bad_port):
                with self.assertRaises(bridge.BridgeConfigError):
                    bridge.validate_bridge_config(bad)

    def test_encode_socks5_connect_supports_domain_ipv4_and_ipv6(self) -> None:
        bridge = load_bridge_module()

        self.assertEqual(b"\x05\x01\x00\x03\x0bexample.com\x01\xbb", bridge.encode_socks5_connect("example.com", 443))
        self.assertEqual(b"\x05\x01\x00\x01\x7f\x00\x00\x01\x1f\x40", bridge.encode_socks5_connect("127.0.0.1", 8000))
        encoded = bridge.encode_socks5_connect("2001:db8::1", 443)
        self.assertEqual(b"\x05\x01\x00\x04", encoded[:4])
        self.assertEqual(22, len(encoded))

    def test_cli_bridge_validate_prints_ok(self) -> None:
        bridge = load_bridge_module()
        config = {
            "remote": "10.10.10.171:29030",
            "listeners": [
                {"listen": "127.0.0.1:8230", "socks": "127.0.0.1:20455", "line_id": 455},
                {"listen": "127.0.0.1:8231", "socks": "127.0.0.1:20456", "line_id": 456},
            ],
        }
        with tempfile.TemporaryDirectory() as tmp:
            path = pathlib.Path(tmp) / "bridge.json"
            path.write_text(__import__("json").dumps(config), encoding="utf-8")
            self.assertEqual(0, bridge.main(["bridge-validate", "--config", str(path)]))

    def test_socks5_connect_and_bridge_handler_relay_bytes(self) -> None:
        bridge = load_bridge_module()

        async def run_case() -> None:
            async def echo_handler(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
                try:
                    while data := await reader.read(1024):
                        writer.write(data.upper())
                        await writer.drain()
                finally:
                    writer.close()
                    await writer.wait_closed()

            target_server = await asyncio.start_server(echo_handler, "127.0.0.1", 0)
            target_port = target_server.sockets[0].getsockname()[1]

            async def socks_handler(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
                greeting = await reader.readexactly(3)
                self.assertEqual(b"\x05\x01\x00", greeting)
                writer.write(b"\x05\x00")
                await writer.drain()
                header = await reader.readexactly(4)
                self.assertEqual(b"\x05\x01\x00", header[:3])
                atyp = header[3]
                if atyp == 1:
                    host = socket.inet_ntoa(await reader.readexactly(4))
                elif atyp == 3:
                    length = (await reader.readexactly(1))[0]
                    host = (await reader.readexactly(length)).decode("ascii")
                elif atyp == 4:
                    host = socket.inet_ntop(socket.AF_INET6, await reader.readexactly(16))
                else:
                    raise AssertionError(f"unexpected atyp {atyp}")
                port = struct.unpack("!H", await reader.readexactly(2))[0]
                upstream_reader, upstream_writer = await asyncio.open_connection(host, port)
                writer.write(b"\x05\x00\x00\x01\x00\x00\x00\x00\x00\x00")
                await writer.drain()

                async def pump(src: asyncio.StreamReader, dst: asyncio.StreamWriter) -> None:
                    try:
                        while data := await src.read(1024):
                            dst.write(data)
                            await dst.drain()
                    finally:
                        dst.close()

                await asyncio.gather(pump(reader, upstream_writer), pump(upstream_reader, writer), return_exceptions=True)

            socks_server = await asyncio.start_server(socks_handler, "127.0.0.1", 0)
            socks_port = socks_server.sockets[0].getsockname()[1]
            bridge_server = await asyncio.start_server(
                lambda reader, writer: bridge.handle_bridge_connection(
                    reader,
                    writer,
                    f"127.0.0.1:{socks_port}",
                    f"127.0.0.1:{target_port}",
                    line_id=455,
                    timeout_seconds=2.0,
                    tcp_user_timeout_ms=30000,
                    half_close_drain_timeout_ms=100,
                ),
                "127.0.0.1",
                0,
            )
            bridge_port = bridge_server.sockets[0].getsockname()[1]

            try:
                reader, writer = await asyncio.open_connection("127.0.0.1", bridge_port)
                writer.write(b"ping")
                await writer.drain()
                self.assertEqual(b"PING", await reader.readexactly(4))
                writer.close()
                await writer.wait_closed()
            finally:
                bridge_server.close()
                socks_server.close()
                target_server.close()
                await asyncio.gather(
                    bridge_server.wait_closed(),
                    socks_server.wait_closed(),
                    target_server.wait_closed(),
                )

        asyncio.run(run_case())


if __name__ == "__main__":
    unittest.main()
