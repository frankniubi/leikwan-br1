from __future__ import annotations

import asyncio
import contextlib
import importlib.util
import pathlib
import socket
import struct
import unittest


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]
MODULE_PATH = REPO_ROOT / "slave-agent" / "leigod_forward_relay.py"
SPEC = importlib.util.spec_from_file_location("leigod_forward_relay", MODULE_PATH)
relay_module = importlib.util.module_from_spec(SPEC)
assert SPEC is not None and SPEC.loader is not None
SPEC.loader.exec_module(relay_module)


def encode_address(host: str, port: int) -> bytes:
    try:
        packed = socket.inet_pton(socket.AF_INET, host)
        return b"\x01" + packed + struct.pack(">H", port)
    except OSError:
        encoded = host.encode("idna")
        return b"\x03" + bytes([len(encoded)]) + encoded + struct.pack(">H", port)


def decode_address(data: bytes, offset: int = 0) -> tuple[str, int, int]:
    atyp = data[offset]
    offset += 1
    if atyp == 1:
        host = socket.inet_ntop(socket.AF_INET, data[offset : offset + 4])
        offset += 4
    elif atyp == 4:
        host = socket.inet_ntop(socket.AF_INET6, data[offset : offset + 16])
        offset += 16
    elif atyp == 3:
        length = data[offset]
        offset += 1
        host = data[offset : offset + length].decode("idna")
        offset += length
    else:
        raise ValueError("invalid address type")
    port = struct.unpack(">H", data[offset : offset + 2])[0]
    return host, port, offset + 2


def free_loopback_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as sock:
        sock.bind(("127.0.0.1", 0))
        return int(sock.getsockname()[1])


async def read_socks_address(reader: asyncio.StreamReader, atyp: int) -> tuple[str, int]:
    if atyp == 1:
        host = socket.inet_ntop(socket.AF_INET, await reader.readexactly(4))
    elif atyp == 4:
        host = socket.inet_ntop(socket.AF_INET6, await reader.readexactly(16))
    elif atyp == 3:
        length = (await reader.readexactly(1))[0]
        host = (await reader.readexactly(length)).decode("idna")
    else:
        raise ValueError("invalid address type")
    port = struct.unpack(">H", await reader.readexactly(2))[0]
    return host, port


async def copy_stream(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
    try:
        while data := await reader.read(65536):
            writer.write(data)
            await writer.drain()
    except (ConnectionError, asyncio.IncompleteReadError):
        pass
    finally:
        with contextlib.suppress(Exception):
            writer.close()


class TcpEchoServer:
    def __init__(self) -> None:
        self.server: asyncio.AbstractServer | None = None

    async def __aenter__(self) -> "TcpEchoServer":
        self.server = await asyncio.start_server(self._handle, "127.0.0.1", 0)
        return self

    async def __aexit__(self, *_args: object) -> None:
        assert self.server is not None
        self.server.close()
        await self.server.wait_closed()

    @property
    def address(self) -> tuple[str, int]:
        assert self.server is not None
        host, port = self.server.sockets[0].getsockname()[:2]
        return str(host), int(port)

    async def _handle(self, reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
        try:
            while data := await reader.read(65536):
                writer.write(data)
                await writer.drain()
        finally:
            writer.close()
            with contextlib.suppress(Exception):
                await writer.wait_closed()


class UdpQueueProtocol(asyncio.DatagramProtocol):
    def __init__(self) -> None:
        self.transport: asyncio.DatagramTransport | None = None
        self.queue: asyncio.Queue[tuple[bytes, tuple[str, int]]] = asyncio.Queue()

    def connection_made(self, transport: asyncio.BaseTransport) -> None:
        self.transport = transport  # type: ignore[assignment]

    def datagram_received(self, data: bytes, addr: tuple[str, int]) -> None:
        self.queue.put_nowait((data, addr))


class UdpEchoProtocol(asyncio.DatagramProtocol):
    def connection_made(self, transport: asyncio.BaseTransport) -> None:
        self.transport = transport

    def datagram_received(self, data: bytes, addr: tuple[str, int]) -> None:
        self.transport.sendto(data, addr)


class UdpEchoServer:
    def __init__(self) -> None:
        self.transport: asyncio.DatagramTransport | None = None

    async def __aenter__(self) -> "UdpEchoServer":
        loop = asyncio.get_running_loop()
        transport, _ = await loop.create_datagram_endpoint(UdpEchoProtocol, local_addr=("127.0.0.1", 0))
        self.transport = transport
        return self

    async def __aexit__(self, *_args: object) -> None:
        assert self.transport is not None
        self.transport.close()

    @property
    def address(self) -> tuple[str, int]:
        assert self.transport is not None
        host, port = self.transport.get_extra_info("sockname")[:2]
        return str(host), int(port)


class FakeSocksServer:
    def __init__(self, line_id: int, delay_ms: int = 0, udp_reply: bool = True) -> None:
        self.line_id = line_id
        self.delay_ms = delay_ms
        self.udp_reply = udp_reply
        self.fail_connect = False
        self.server: asyncio.AbstractServer | None = None
        self.udp_transport: asyncio.DatagramTransport | None = None
        self.udp_protocol: FakeSocksUdpProtocol | None = None

    async def __aenter__(self) -> "FakeSocksServer":
        loop = asyncio.get_running_loop()
        transport, protocol = await loop.create_datagram_endpoint(
            lambda: FakeSocksUdpProtocol(self), local_addr=("127.0.0.1", 0)
        )
        self.udp_transport = transport
        self.udp_protocol = protocol
        self.server = await asyncio.start_server(self._handle, "127.0.0.1", 0)
        return self

    async def __aexit__(self, *_args: object) -> None:
        if self.server is not None:
            self.server.close()
            await self.server.wait_closed()
        if self.udp_transport is not None:
            self.udp_transport.close()

    @property
    def address(self) -> tuple[str, int]:
        assert self.server is not None
        host, port = self.server.sockets[0].getsockname()[:2]
        return str(host), int(port)

    def candidate(self, combination_id: int, sort_order: int) -> dict[str, object]:
        host, port = self.address
        return {
            "combination_id": combination_id,
            "line_id": self.line_id,
            "socks_host": host,
            "socks_port": port,
            "sort_order": sort_order,
        }

    async def _handle(self, reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
        remote_writer: asyncio.StreamWriter | None = None
        try:
            version, method_count = await reader.readexactly(2)
            methods = await reader.readexactly(method_count)
            if version != 5 or 0 not in methods:
                return
            writer.write(b"\x05\x00")
            await writer.drain()
            version, command, _reserved, atyp = await reader.readexactly(4)
            host, port = await read_socks_address(reader, atyp)
            if version != 5:
                return
            if command == 1:
                if self.delay_ms:
                    await asyncio.sleep(self.delay_ms / 1000)
                if self.fail_connect:
                    writer.write(b"\x05\x05\x00\x01\x00\x00\x00\x00\x00\x00")
                    await writer.drain()
                    return
                remote_reader, remote_writer = await asyncio.open_connection(host, port)
                writer.write(b"\x05\x00\x00\x01\x7f\x00\x00\x01\x00\x00")
                await writer.drain()
                await asyncio.gather(copy_stream(reader, remote_writer), copy_stream(remote_reader, writer))
                return
            if command == 3:
                assert self.udp_transport is not None
                udp_host, udp_port = self.udp_transport.get_extra_info("sockname")[:2]
                writer.write(b"\x05\x00\x00" + encode_address(str(udp_host), int(udp_port)))
                await writer.drain()
                await reader.read()
        except (asyncio.IncompleteReadError, ConnectionError, OSError):
            pass
        finally:
            if remote_writer is not None:
                remote_writer.close()
            writer.close()
            with contextlib.suppress(Exception):
                await writer.wait_closed()


class FakeSocksUdpProtocol(asyncio.DatagramProtocol):
    def __init__(self, owner: FakeSocksServer) -> None:
        self.owner = owner
        self.transport: asyncio.DatagramTransport | None = None

    def connection_made(self, transport: asyncio.BaseTransport) -> None:
        self.transport = transport  # type: ignore[assignment]

    def datagram_received(self, data: bytes, addr: tuple[str, int]) -> None:
        asyncio.create_task(self._forward(data, addr))

    async def _forward(self, data: bytes, client_addr: tuple[str, int]) -> None:
        if not self.owner.udp_reply or len(data) < 4 or data[:3] != b"\x00\x00\x00":
            return
        try:
            host, port, offset = decode_address(data, 3)
        except (ValueError, OSError, struct.error):
            return
        payload = data[offset:]
        loop = asyncio.get_running_loop()
        protocol = UdpQueueProtocol()
        transport, _ = await loop.create_datagram_endpoint(lambda: protocol, remote_addr=(host, port))
        try:
            transport.sendto(payload)
            response, response_addr = await asyncio.wait_for(protocol.queue.get(), timeout=1)
            assert self.transport is not None
            self.transport.sendto(b"\x00\x00\x00" + encode_address(response_addr[0], response_addr[1]) + response, client_addr)
        except (asyncio.TimeoutError, OSError):
            pass
        finally:
            transport.close()


class LeigodForwardRelayTests(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self) -> None:
        self.runtimes: list[object] = []

    async def asyncTearDown(self) -> None:
        for runtime in reversed(self.runtimes):
            await runtime.close()

    def config(
        self,
        mode: str,
        target: tuple[str, int],
        candidates: list[dict[str, object]],
        transport: str = "tcp",
        **policy: object,
    ) -> dict[str, object]:
        defaults = {
            "probe_interval_seconds": 3600,
            "connect_timeout_seconds": 0.25,
            "failure_threshold": 2,
            "recovery_success_threshold": 2,
            "switch_improvement_ms": 1,
            "minimum_hold_seconds": 0,
        }
        defaults.update(policy)
        return {
            "mode": mode,
            "listen_host": "127.0.0.1",
            "listen_port": free_loopback_port(),
            "target_host": target[0],
            "target_port": target[1],
            "transport": transport,
            "candidates": candidates,
            "policy": defaults,
        }

    async def start_runtime(self, payload: dict[str, object]):
        runtime = relay_module.RelayRuntime(relay_module.parse_config(payload))
        await runtime.start()
        self.runtimes.append(runtime)
        return runtime

    async def tcp_round_trip(self, runtime, payload: bytes) -> bytes:
        reader, writer = await asyncio.open_connection(*runtime.tcp_address)
        try:
            writer.write(payload)
            await writer.drain()
            return await asyncio.wait_for(reader.readexactly(len(payload)), timeout=1)
        finally:
            writer.close()
            with contextlib.suppress(Exception):
                await writer.wait_closed()

    async def udp_round_trip(self, runtime, payload: bytes, timeout: float = 0.5) -> bytes:
        loop = asyncio.get_running_loop()
        protocol = UdpQueueProtocol()
        transport, _ = await loop.create_datagram_endpoint(lambda: protocol, remote_addr=runtime.udp_address)
        try:
            transport.sendto(payload)
            response, _ = await asyncio.wait_for(protocol.queue.get(), timeout=timeout)
            return response
        finally:
            transport.close()

    async def test_single_keeps_current_candidate_until_failure(self) -> None:
        async with TcpEchoServer() as target, FakeSocksServer(451, 5) as primary, FakeSocksServer(453, 1) as backup:
            runtime = await self.start_runtime(self.config("single", target.address, [primary.candidate(1, 10), backup.candidate(1, 20)]))
            self.assertEqual(b"one", await self.tcp_round_trip(runtime, b"one"))
            self.assertEqual(451, runtime.selected_line_id())
            primary.fail_connect = True
            with self.assertRaises((asyncio.IncompleteReadError, ConnectionError, asyncio.TimeoutError)):
                await self.tcp_round_trip(runtime, b"fail")
            self.assertEqual(b"two", await self.tcp_round_trip(runtime, b"two"))
            self.assertEqual(453, runtime.selected_line_id())

    async def test_race_selects_lowest_latency_after_hysteresis(self) -> None:
        async with TcpEchoServer() as target, FakeSocksServer(451, 30) as first, FakeSocksServer(453, 2) as second:
            runtime = await self.start_runtime(self.config("race", target.address, [first.candidate(1, 10), second.candidate(2, 20)], switch_improvement_ms=5))
            self.assertEqual(453, runtime.selected_line_id())
            first.delay_ms, second.delay_ms = 0, 30
            await runtime.probe_once()
            self.assertEqual(451, runtime.selected_line_id())
            self.assertEqual(b"race", await self.tcp_round_trip(runtime, b"race"))

    async def test_race_does_not_flap_inside_minimum_hold(self) -> None:
        async with TcpEchoServer() as target, FakeSocksServer(451, 1) as first, FakeSocksServer(453, 30) as second:
            runtime = await self.start_runtime(self.config("race", target.address, [first.candidate(1, 10), second.candidate(2, 20)], minimum_hold_seconds=60))
            self.assertEqual(451, runtime.selected_line_id())
            first.delay_ms, second.delay_ms = 40, 0
            await runtime.probe_once()
            self.assertEqual(451, runtime.selected_line_id())

    async def test_recovered_candidate_needs_success_threshold(self) -> None:
        async with TcpEchoServer() as target, FakeSocksServer(451, 1) as first, FakeSocksServer(453, 5) as second:
            runtime = await self.start_runtime(self.config("single", target.address, [first.candidate(1, 10), second.candidate(1, 20)]))
            first.fail_connect = True
            await runtime.probe_once()
            await runtime.probe_once()
            self.assertTrue(runtime.candidate_state(451)["quarantined"])
            first.fail_connect = False
            await runtime.probe_once()
            self.assertTrue(runtime.candidate_state(451)["quarantined"])
            await runtime.probe_once()
            self.assertFalse(runtime.candidate_state(451)["quarantined"])

    async def test_existing_tcp_connection_drains_on_config_reload(self) -> None:
        async with TcpEchoServer() as target, FakeSocksServer(451) as first, FakeSocksServer(453) as second:
            runtime = await self.start_runtime(self.config("single", target.address, [first.candidate(1, 10)]))
            reader, writer = await asyncio.open_connection(*runtime.tcp_address)
            writer.write(b"before")
            await writer.drain()
            self.assertEqual(b"before", await reader.readexactly(6))
            reloaded = self.config("single", target.address, [second.candidate(1, 10)])
            reloaded["listen_port"] = runtime.config.listen_port
            await runtime.reload_payload(reloaded)
            writer.write(b"after")
            await writer.drain()
            self.assertEqual(b"after", await reader.readexactly(5))
            self.assertEqual(453, runtime.selected_line_id())
            writer.close()
            await writer.wait_closed()

    async def test_udp_requires_a_reply_not_only_associate_success(self) -> None:
        async with UdpEchoServer() as target, FakeSocksServer(451, udp_reply=False) as handshake_only, FakeSocksServer(453, udp_reply=True) as working:
            runtime = await self.start_runtime(self.config("single", target.address, [handshake_only.candidate(1, 10)], transport="udp"))
            with self.assertRaises(asyncio.TimeoutError):
                await self.udp_round_trip(runtime, b"no-reply", timeout=0.35)
            self.assertIsNone(runtime.selected_line_id("udp"))
            reloaded = self.config("single", target.address, [working.candidate(1, 10)], transport="udp")
            reloaded["listen_port"] = runtime.config.listen_port
            await runtime.reload_payload(reloaded)
            self.assertEqual(b"reply", await self.udp_round_trip(runtime, b"reply", timeout=1))
            self.assertEqual(453, runtime.selected_line_id("udp"))

    async def test_empty_or_invalid_config_fails_closed(self) -> None:
        with self.assertRaises(ValueError):
            relay_module.parse_config({})
        payload = self.config("single", ("127.0.0.1", 80), [{"combination_id": 1, "line_id": 451, "socks_host": "127.0.0.1", "socks_port": 20451, "sort_order": 10}])
        payload["listen_host"] = "0.0.0.0"
        with self.assertRaises(ValueError):
            relay_module.parse_config(payload)
        payload["listen_host"] = "127.0.0.1"
        payload["candidates"] = []
        with self.assertRaises(ValueError):
            relay_module.parse_config(payload)


if __name__ == "__main__":
    unittest.main()
