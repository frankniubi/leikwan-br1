import asyncio
import pathlib
import sys
import tempfile
import unittest


sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))

from tcp_accel import (
    OrderedReassembler,
    load_client_config,
    parse_host_port,
    start_accel_client,
    start_accel_server,
    start_client_from_config,
)


class MemorySink:
    def __init__(self) -> None:
        self.data = bytearray()
        self.closed = False

    def write(self, payload: bytes) -> None:
        self.data.extend(payload)

    async def drain(self) -> None:
        await asyncio.sleep(0)

    def close(self) -> None:
        self.closed = True


class OrderedReassemblerTests(unittest.IsolatedAsyncioTestCase):
    async def test_releases_only_contiguous_chunks(self) -> None:
        sink = MemorySink()
        reassembler = OrderedReassembler(sink)

        await reassembler.feed_data(1, b"world")
        self.assertEqual(bytes(sink.data), b"")

        await reassembler.feed_data(0, b"hello ")
        self.assertEqual(bytes(sink.data), b"hello world")

        await reassembler.feed_eof(2)
        self.assertTrue(sink.closed)

    async def test_rejects_conflicting_duplicate_chunk(self) -> None:
        sink = MemorySink()
        reassembler = OrderedReassembler(sink)

        await reassembler.feed_data(1, b"first")
        with self.assertRaises(ValueError):
            await reassembler.feed_data(1, b"second")


class TcpAccelIntegrationTests(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self) -> None:
        self._servers: list[asyncio.AbstractServer] = []

    async def asyncTearDown(self) -> None:
        for server in reversed(self._servers):
            server.close()
            await server.wait_closed()

    def _remember(self, server: asyncio.AbstractServer) -> asyncio.AbstractServer:
        self._servers.append(server)
        return server

    async def test_full_duplex_single_tcp_stream_over_multiple_lanes(self) -> None:
        async def echo_target(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
            try:
                while chunk := await reader.read(32768):
                    writer.write(chunk.upper())
                    await writer.drain()
            finally:
                writer.close()
                await writer.wait_closed()

        target_server = self._remember(await asyncio.start_server(echo_target, "127.0.0.1", 0))
        target_port = target_server.sockets[0].getsockname()[1]

        accel_server = self._remember(
            await start_accel_server(
                listen_host="127.0.0.1",
                listen_port=0,
                target_host="127.0.0.1",
                target_port=target_port,
                token="test-token",
            )
        )
        accel_server_port = accel_server.sockets[0].getsockname()[1]

        accel_client = self._remember(
            await start_accel_client(
                listen_host="127.0.0.1",
                listen_port=0,
                remote_host="127.0.0.1",
                remote_port=accel_server_port,
                target_host="127.0.0.1",
                target_port=target_port,
                lanes=4,
                chunk_size=4096,
                token="test-token",
            )
        )
        accel_client_port = accel_client.sockets[0].getsockname()[1]

        reader, writer = await asyncio.open_connection("127.0.0.1", accel_client_port)
        payload = (b"abcdefghijklmnopqrstuvwxyz0123456789" * 4096) + b"tail"

        writer.write(payload)
        await writer.drain()
        writer.write_eof()

        received = await reader.readexactly(len(payload))
        self.assertEqual(received, payload.upper())

        writer.close()
        await writer.wait_closed()

    async def test_config_can_start_multiple_port_maps(self) -> None:
        async def echo_target(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
            try:
                while chunk := await reader.read(32768):
                    writer.write(chunk + b":ok")
                    await writer.drain()
            finally:
                writer.close()
                await writer.wait_closed()

        target_a = self._remember(await asyncio.start_server(echo_target, "127.0.0.1", 0))
        target_b = self._remember(await asyncio.start_server(echo_target, "127.0.0.1", 0))
        target_a_port = target_a.sockets[0].getsockname()[1]
        target_b_port = target_b.sockets[0].getsockname()[1]

        accel_server = self._remember(await start_accel_server(listen_host="127.0.0.1", listen_port=0, token="map-token"))
        accel_server_port = accel_server.sockets[0].getsockname()[1]

        config = load_client_config(
            {
                "token": "map-token",
                "remotes": [f"127.0.0.1:{accel_server_port}"],
                "lanes": 2,
                "minimum_lanes": 1,
                "join_timeout_ms": 50,
                "chunk_size": 1024,
                "forwards": [
                    {"listen": "127.0.0.1:0", "target": f"127.0.0.1:{target_a_port}"},
                    {"listen": "127.0.0.1:0", "target": f"127.0.0.1:{target_b_port}"},
                ],
            }
        )
        client_servers = await start_client_from_config(config)
        for server in client_servers:
            self._remember(server)

        ports = [server.sockets[0].getsockname()[1] for server in client_servers]
        for port, payload in zip(ports, [b"alpha", b"beta"]):
            reader, writer = await asyncio.open_connection("127.0.0.1", port)
            writer.write(payload)
            await writer.drain()
            writer.write_eof()
            self.assertEqual(await reader.readexactly(len(payload) + 3), payload + b":ok")
            writer.close()
            await writer.wait_closed()

    async def test_config_socks5_frontend_connects_to_arbitrary_target(self) -> None:
        async def echo_target(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
            try:
                while chunk := await reader.read(32768):
                    writer.write(chunk.upper())
                    await writer.drain()
            finally:
                writer.close()
                await writer.wait_closed()

        target = self._remember(await asyncio.start_server(echo_target, "127.0.0.1", 0))
        target_port = target.sockets[0].getsockname()[1]

        accel_server = self._remember(await start_accel_server(listen_host="127.0.0.1", listen_port=0, token="socks-token"))
        accel_server_port = accel_server.sockets[0].getsockname()[1]

        config = load_client_config(
            {
                "token": "socks-token",
                "remotes": [f"127.0.0.1:{accel_server_port}"],
                "lanes": 2,
                "minimum_lanes": 1,
                "join_timeout_ms": 50,
                "chunk_size": 1024,
                "socks5": [{"listen": "127.0.0.1:0"}],
            }
        )
        [socks_server] = await start_client_from_config(config)
        self._remember(socks_server)
        socks_port = socks_server.sockets[0].getsockname()[1]

        reader, writer = await asyncio.open_connection("127.0.0.1", socks_port)
        writer.write(b"\x05\x01\x00")
        await writer.drain()
        self.assertEqual(await reader.readexactly(2), b"\x05\x00")

        host = b"127.0.0.1"
        writer.write(b"\x05\x01\x00\x03" + bytes([len(host)]) + host + target_port.to_bytes(2, "big"))
        await writer.drain()
        self.assertEqual(await reader.readexactly(4), b"\x05\x00\x00\x01")
        await reader.readexactly(6)

        writer.write(b"socks works")
        await writer.drain()
        writer.write_eof()
        self.assertEqual(await reader.readexactly(len(b"SOCKS WORKS")), b"SOCKS WORKS")
        writer.close()
        await writer.wait_closed()

    async def test_can_start_with_minimum_lanes_when_one_remote_fails(self) -> None:
        async def echo_target(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
            try:
                while chunk := await reader.read(32768):
                    writer.write(chunk[::-1])
                    await writer.drain()
            finally:
                writer.close()
                await writer.wait_closed()

        target_server = self._remember(await asyncio.start_server(echo_target, "127.0.0.1", 0))
        target_port = target_server.sockets[0].getsockname()[1]

        accel_server = self._remember(
            await start_accel_server(
                listen_host="127.0.0.1",
                listen_port=0,
                target_host="127.0.0.1",
                target_port=target_port,
                token="test-token",
            )
        )
        accel_server_port = accel_server.sockets[0].getsockname()[1]
        unused_server = self._remember(await asyncio.start_server(lambda _r, w: w.close(), "127.0.0.1", 0))
        unused_port = unused_server.sockets[0].getsockname()[1]

        accel_client = self._remember(
            await start_accel_client(
                listen_host="127.0.0.1",
                listen_port=0,
                remote_host="127.0.0.1",
                remote_port=accel_server_port,
                target_host="127.0.0.1",
                target_port=target_port,
                lanes=2,
                minimum_lanes=1,
                join_timeout_ms=50,
                chunk_size=1024,
                token="test-token",
                remotes=[("127.0.0.1", accel_server_port), ("127.0.0.1", unused_port)],
            )
        )
        accel_client_port = accel_client.sockets[0].getsockname()[1]

        reader, writer = await asyncio.open_connection("127.0.0.1", accel_client_port)
        payload = b"minimum lane fallback works"
        writer.write(payload)
        await writer.drain()
        writer.write_eof()

        self.assertEqual(await reader.readexactly(len(payload)), payload[::-1])

        writer.close()
        await writer.wait_closed()


class TcpAccelConfigTests(unittest.TestCase):
    def test_parse_host_port_accepts_bracketed_ipv6(self) -> None:
        self.assertEqual(parse_host_port("[::1]:29030"), ("::1", 29030))

    def test_check_config_model_supports_port_map_and_socks5(self) -> None:
        config = load_client_config(
            {
                "token": "unit-token",
                "remotes": ["[::1]:29030"],
                "lanes": 4,
                "minimum_lanes": 1,
                "forwards": [{"listen": "127.0.0.1:15443", "target": "1.1.1.1:443"}],
                "socks5": "127.0.0.1:1080",
            }
        )
        self.assertEqual(config.remotes, [("::1", 29030)])
        self.assertEqual(config.forwards[0].target, ("1.1.1.1", 443))
        self.assertEqual(config.socks5[0].listen, ("127.0.0.1", 1080))

    def test_config_file_tolerates_utf8_bom(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = pathlib.Path(tmp) / "client.json"
            path.write_text(
                '\ufeff{"token":"bom-token","remotes":["127.0.0.1:29030"],"socks5":"127.0.0.1:1080"}',
                encoding="utf-8",
            )
            config = load_client_config(path)
        self.assertEqual(config.token, "bom-token")
        self.assertEqual(config.socks5[0].listen, ("127.0.0.1", 1080))


if __name__ == "__main__":
    unittest.main()
