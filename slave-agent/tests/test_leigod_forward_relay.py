from __future__ import annotations

import asyncio
import contextlib
import importlib.util
import pathlib
import socket
import struct
import sys
import time
import unittest
from unittest import mock
import json
import tempfile


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]
MODULE_PATH = REPO_ROOT / "slave-agent" / "leigod_forward_relay.py"
SPEC = importlib.util.spec_from_file_location("leigod_forward_relay", MODULE_PATH)
relay_module = importlib.util.module_from_spec(SPEC)
assert SPEC is not None and SPEC.loader is not None
sys.modules[SPEC.name] = relay_module
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


def free_dual_protocol_loopback_port() -> int:
    for _ in range(50):
        with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as tcp_sock:
            tcp_sock.bind(("127.0.0.1", 0))
            port = int(tcp_sock.getsockname()[1])
            with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as udp_sock:
                try:
                    udp_sock.bind(("127.0.0.1", port))
                except OSError:
                    continue
                return port
    raise RuntimeError("failed to allocate a TCP/UDP loopback port")


def bond_hello_header(leg_route_id: int, payload_len: int = 20) -> bytes:
    return (
        b"LBND"
        + bytes([2, 1])
        + b"\x00\x00"
        + struct.pack(">QQII", 0, 0, leg_route_id, payload_len)
    )


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


async def copy_stream(
    reader: asyncio.StreamReader,
    writer: asyncio.StreamWriter,
    *,
    chunk_size: int = 65536,
    delay_ms: int = 0,
) -> None:
    try:
        while data := await reader.read(chunk_size):
            writer.write(data)
            await writer.drain()
            if delay_ms:
                await asyncio.sleep(delay_ms / 1000)
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
    def __init__(
        self,
        line_id: int,
        delay_ms: int = 0,
        udp_reply: bool = True,
        stream_delay_ms: int = 0,
        stream_chunk_size: int = 65536,
    ) -> None:
        self.line_id = line_id
        self.delay_ms = delay_ms
        self.udp_reply = udp_reply
        self.stream_delay_ms = stream_delay_ms
        self.stream_chunk_size = stream_chunk_size
        self.fail_connect = False
        self.connect_targets: list[tuple[str, int]] = []
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
                self.connect_targets.append((host, port))
                if self.delay_ms:
                    await asyncio.sleep(self.delay_ms / 1000)
                if self.fail_connect:
                    writer.write(b"\x05\x05\x00\x01\x00\x00\x00\x00\x00\x00")
                    await writer.drain()
                    return
                remote_reader, remote_writer = await asyncio.open_connection(host, port)
                writer.write(b"\x05\x00\x00\x01\x7f\x00\x00\x01\x00\x00")
                await writer.drain()
                await asyncio.gather(
                    copy_stream(
                        reader,
                        remote_writer,
                        chunk_size=self.stream_chunk_size,
                        delay_ms=self.stream_delay_ms,
                    ),
                    copy_stream(
                        remote_reader,
                        writer,
                        chunk_size=self.stream_chunk_size,
                        delay_ms=self.stream_delay_ms,
                    ),
                )
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
            "listen_port": (
                free_dual_protocol_loopback_port()
                if "udp" in transport
                else free_loopback_port()
            ),
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

    async def test_bond_routes_fragmented_hello_to_exact_candidate(self) -> None:
        async with TcpEchoServer() as target, FakeSocksServer(451) as first, FakeSocksServer(453) as second:
            first_row = first.candidate(1, 10) | {"leg_route_id": 451}
            second_row = second.candidate(2, 20) | {"leg_route_id": 453}
            runtime = await self.start_runtime(self.config("bond", target.address, [first_row, second_row]))

            reader, writer = await asyncio.open_connection(*runtime.tcp_address)
            header = bond_hello_header(453)
            hello_payload = bytes(20)
            try:
                writer.write(header[:7])
                await writer.drain()
                writer.write(header[7:] + hello_payload + b"payload")
                await writer.drain()
                self.assertEqual(
                    header + hello_payload + b"payload",
                    await reader.readexactly(len(header) + len(hello_payload) + 7),
                )
            finally:
                writer.close()
                with contextlib.suppress(Exception):
                    await writer.wait_closed()

            self.assertEqual([], first.connect_targets)
            self.assertEqual([target.address], second.connect_targets)
            await asyncio.sleep(0.05)
            second_state = runtime.candidate_state(453)
            self.assertEqual(453, second_state["leg_route_id"])
            self.assertEqual(1, second_state["bond_route"]["accepted"])
            self.assertEqual(1, second_state["bond_route"]["connect_successes"])
            self.assertGreaterEqual(second_state["bond_route"]["bytes_up"], 27)
            self.assertGreaterEqual(second_state["bond_route"]["bytes_down"], 59)
            self.assertEqual(0, second_state["bond_route"]["active"])

    async def test_bond_rejects_unroutable_or_invalid_hello_without_socks_connect(self) -> None:
        async with TcpEchoServer() as target, FakeSocksServer(451) as first, FakeSocksServer(453) as second:
            rows = [
                first.candidate(1, 10) | {"leg_route_id": 451},
                second.candidate(2, 20) | {"leg_route_id": 453},
            ]
            runtime = await self.start_runtime(self.config("bond", target.address, rows))
            invalid_headers = {
                "zero route": bond_hello_header(0),
                "unknown route": bond_hello_header(999),
                "wrong magic": b"NOPE" + bond_hello_header(451)[4:],
                "wrong version": bond_hello_header(451)[:4] + b"\x03" + bond_hello_header(451)[5:],
                "wrong kind": bond_hello_header(451)[:5] + b"\x02" + bond_hello_header(451)[6:],
                "short payload": bond_hello_header(451, payload_len=19),
                "oversized payload": bond_hello_header(451, payload_len=relay_module.LBND_MAX_HELLO_PAYLOAD + 1),
            }

            for label, header in invalid_headers.items():
                with self.subTest(label=label):
                    reader, writer = await asyncio.open_connection(*runtime.tcp_address)
                    try:
                        writer.write(header)
                        await writer.drain()
                        self.assertEqual(b"", await asyncio.wait_for(reader.read(), timeout=0.5))
                    finally:
                        writer.close()
                        with contextlib.suppress(Exception):
                            await writer.wait_closed()

            self.assertEqual([], first.connect_targets)
            self.assertEqual([], second.connect_targets)

    async def test_bond_header_timeout_does_not_connect_socks(self) -> None:
        async with TcpEchoServer() as target, FakeSocksServer(451) as first, FakeSocksServer(453) as second:
            rows = [
                first.candidate(1, 10) | {"leg_route_id": 451},
                second.candidate(2, 20) | {"leg_route_id": 453},
            ]
            runtime = await self.start_runtime(self.config("bond", target.address, rows))
            with mock.patch.object(relay_module, "BOND_HEADER_TIMEOUT_SECONDS", 0.05):
                reader, writer = await asyncio.open_connection(*runtime.tcp_address)
                try:
                    writer.write(b"LBND")
                    await writer.drain()
                    self.assertEqual(b"", await asyncio.wait_for(reader.read(), timeout=0.5))
                finally:
                    writer.close()
                    with contextlib.suppress(Exception):
                        await writer.wait_closed()

            self.assertEqual([], first.connect_targets)
            self.assertEqual([], second.connect_targets)

    async def test_bond_exact_route_failure_is_recorded_without_fallback(self) -> None:
        async with TcpEchoServer() as target, FakeSocksServer(451) as first, FakeSocksServer(453) as second:
            first.fail_connect = True
            rows = [
                first.candidate(1, 10) | {"leg_route_id": 451},
                second.candidate(2, 20) | {"leg_route_id": 453},
            ]
            runtime = await self.start_runtime(self.config("bond", target.address, rows))
            reader, writer = await asyncio.open_connection(*runtime.tcp_address)
            try:
                writer.write(bond_hello_header(451) + bytes(20))
                await writer.drain()
                self.assertEqual(b"", await asyncio.wait_for(reader.read(), timeout=0.5))
            finally:
                writer.close()
                with contextlib.suppress(Exception):
                    await writer.wait_closed()

            self.assertEqual([target.address], first.connect_targets)
            self.assertEqual([], second.connect_targets)
            self.assertEqual(1, runtime.candidate_state(451)["tcp_consecutive_failures"])
            failed_state = runtime.candidate_state(451)
            self.assertEqual(1, failed_state["bond_route"]["accepted"])
            self.assertEqual(1, failed_state["bond_route"]["connect_failures"])
            self.assertIn("SOCKS command failed", failed_state["bond_route"]["last_error"])

    async def test_bond_state_file_contains_route_counters_without_runtime_secrets(self) -> None:
        async with TcpEchoServer() as target, FakeSocksServer(451) as first, FakeSocksServer(453) as second:
            rows = [
                first.candidate(1, 10) | {"leg_route_id": 451},
                second.candidate(2, 20) | {"leg_route_id": 453},
            ]
            with tempfile.TemporaryDirectory() as temp_dir:
                state_file = pathlib.Path(temp_dir) / "relay-state.json"
                runtime = relay_module.RelayRuntime(
                    relay_module.parse_config(self.config("bond", target.address, rows)),
                    state_file=state_file,
                )
                await runtime.start()
                self.runtimes.append(runtime)
                reader, writer = await asyncio.open_connection(*runtime.tcp_address)
                header = bond_hello_header(453)
                try:
                    writer.write(header + bytes(20) + b"payload")
                    await writer.drain()
                    await reader.readexactly(len(header) + 27)
                finally:
                    writer.close()
                    with contextlib.suppress(Exception):
                        await writer.wait_closed()
                payload = json.loads(state_file.read_text(encoding="utf-8"))
                route = next(row for row in payload["candidates"] if row["leg_route_id"] == 453)
                self.assertEqual(1, route["bond_route"]["accepted"])
                self.assertNotIn("target_host", json.dumps(payload))
                self.assertNotIn("session_token", json.dumps(payload))
                self.assertNotIn("policy", json.dumps(payload))

    def test_bond_config_requires_multiple_unique_tcp_routes(self) -> None:
        base_row = {
            "combination_id": 1,
            "line_id": 451,
            "leg_route_id": 451,
            "socks_host": "127.0.0.1",
            "socks_port": 20451,
            "sort_order": 10,
        }
        second_row = base_row | {
            "combination_id": 2,
            "line_id": 453,
            "leg_route_id": 453,
            "socks_port": 20453,
        }
        with self.assertRaises(ValueError):
            relay_module.parse_config(self.config("bond", ("127.0.0.1", 80), [base_row]))
        for transport in ("udp", "tcp+udp"):
            with self.subTest(transport=transport), self.assertRaises(ValueError):
                relay_module.parse_config(
                    self.config("bond", ("127.0.0.1", 80), [base_row, second_row], transport=transport)
                )
        with self.assertRaises(ValueError):
            relay_module.parse_config(
                self.config("bond", ("127.0.0.1", 80), [base_row, second_row | {"leg_route_id": 451}])
            )
        with self.assertRaises(ValueError):
            relay_module.parse_config(
                self.config("bond", ("127.0.0.1", 80), [base_row, second_row | {"leg_route_id": 0}])
            )

    def test_bond_config_allows_parallel_lanes_on_one_physical_line(self) -> None:
        first_lane = {
            "combination_id": 1,
            "line_id": 451,
            "leg_route_id": 451,
            "socks_host": "127.0.0.1",
            "socks_port": 20451,
            "sort_order": 10,
        }
        second_lane = first_lane | {"leg_route_id": 1_004_512, "sort_order": 20}

        config = relay_module.parse_config(
            self.config("bond", ("127.0.0.1", 80), [first_lane, second_lane])
        )
        runtime = relay_module.RelayRuntime(config)

        self.assertEqual({451, 1_004_512}, set(runtime._bond_candidates_by_route))
        self.assertEqual([451, 1_004_512], runtime.selector.state(451).snapshot()["leg_route_ids"])

        duplicate_route = second_lane | {"leg_route_id": 451}
        with self.assertRaisesRegex(ValueError, "duplicate bond leg routes"):
            relay_module.parse_config(
                self.config("bond", ("127.0.0.1", 80), [first_lane, duplicate_route])
            )

    async def test_bond_parallel_lanes_share_one_physical_socks_line(self) -> None:
        async with TcpEchoServer() as target, FakeSocksServer(451) as physical_line:
            first_lane = physical_line.candidate(1, 10) | {"leg_route_id": 451}
            second_lane = physical_line.candidate(1, 20) | {"leg_route_id": 1_004_512}
            runtime = await self.start_runtime(
                self.config("bond", target.address, [first_lane, second_lane])
            )

            for route_id in (451, 1_004_512):
                reader, writer = await asyncio.open_connection(*runtime.tcp_address)
                header = bond_hello_header(route_id)
                payload = bytes(20) + f"lane-{route_id}".encode()
                try:
                    writer.write(header + payload)
                    await writer.drain()
                    self.assertEqual(header + payload, await reader.readexactly(len(header) + len(payload)))
                finally:
                    writer.close()
                    with contextlib.suppress(Exception):
                        await writer.wait_closed()

            await asyncio.sleep(0.05)
            state = runtime.candidate_state(451)
            self.assertEqual([451, 1_004_512], state["leg_route_ids"])
            self.assertEqual(2, state["bond_route"]["accepted"])
            self.assertEqual(2, state["bond_route"]["connect_successes"])
            self.assertEqual([target.address, target.address], physical_line.connect_targets)

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

    async def test_race_learns_delivered_throughput_from_real_connections(self) -> None:
        async with (
            TcpEchoServer() as target,
            FakeSocksServer(451, 1, stream_delay_ms=35, stream_chunk_size=1024) as slow,
            FakeSocksServer(453, 30, stream_delay_ms=10, stream_chunk_size=1024) as fast,
        ):
            runtime = await self.start_runtime(
                self.config(
                    "race",
                    target.address,
                    [slow.candidate(1, 10), fast.candidate(2, 20)],
                    selection_signal="throughput_ewma",
                    throughput_min_bytes=1024,
                    throughput_min_seconds=0.05,
                    throughput_ewma_alpha=1.0,
                    throughput_explore_every_connections=20,
                )
            )
            # Connect latency deliberately favors 451; the first two flows warm both routes.
            self.assertEqual(451, runtime.selected_line_id())
            payload = b"throughput" * 820
            self.assertEqual(payload, await self.tcp_round_trip(runtime, payload))
            await asyncio.sleep(0.05)
            slow_state = runtime.candidate_state(451)["throughput"]
            self.assertTrue(slow_state["qualifying_samples"] >= 1)

            self.assertEqual(payload, await self.tcp_round_trip(runtime, payload))
            await asyncio.sleep(0.05)
            fast_state = runtime.candidate_state(453)["throughput"]
            self.assertTrue(fast_state["qualifying_samples"] >= 1)
            self.assertGreater(fast_state["upload_ewma_mbps"], slow_state["upload_ewma_mbps"])
            self.assertGreater(fast_state["download_ewma_mbps"], slow_state["download_ewma_mbps"])

            before = len(fast.connect_targets)
            self.assertEqual(payload, await self.tcp_round_trip(runtime, payload))
            self.assertGreater(len(fast.connect_targets), before)
            self.assertEqual(453, runtime.selected_line_id())

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
            self.assertEqual(451, runtime.selected_line_id())

    async def test_udp_failures_do_not_quarantine_tcp(self) -> None:
        async with TcpEchoServer() as target, FakeSocksServer(451, udp_reply=False) as candidate:
            runtime = await self.start_runtime(
                self.config("single", target.address, [candidate.candidate(1, 10)], transport="tcp+udp")
            )
            for _ in range(2):
                with self.assertRaises(RuntimeError):
                    await runtime.selector.udp_round_trip(b"no-reply")
            state = runtime.candidate_state(451)
            self.assertTrue(state["udp_quarantined"])
            self.assertFalse(state["tcp_quarantined"])
            self.assertEqual(b"tcp-still-works", await self.tcp_round_trip(runtime, b"tcp-still-works"))

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

    def test_race_throughput_policy_parses_exact_bounded_values(self) -> None:
        candidate = {
            "combination_id": 1,
            "line_id": 451,
            "socks_host": "127.0.0.1",
            "socks_port": 20451,
            "sort_order": 10,
        }
        payload = self.config(
            "race",
            ("198.51.100.8", 80),
            [candidate],
            selection_signal="throughput_ewma",
            throughput_min_bytes=1_048_576,
            throughput_min_seconds=2.0,
            throughput_ewma_alpha=0.25,
            throughput_explore_every_connections=20,
            throughput_sample_ttl_seconds=1800,
            throughput_stall_interval_seconds=1.0,
        )
        policy = relay_module.parse_config(payload).policy
        self.assertEqual("throughput_ewma", policy.selection_signal)
        self.assertEqual(1_048_576, policy.throughput_min_bytes)
        self.assertEqual(2.0, policy.throughput_min_seconds)
        self.assertEqual(0.25, policy.throughput_ewma_alpha)
        self.assertEqual(20, policy.throughput_explore_every_connections)
        self.assertEqual(1800.0, policy.throughput_sample_ttl_seconds)
        self.assertEqual(1.0, policy.throughput_stall_interval_seconds)

    def test_race_throughput_policy_rejects_invalid_bounds_and_modes(self) -> None:
        candidate = {
            "combination_id": 1,
            "line_id": 451,
            "socks_host": "127.0.0.1",
            "socks_port": 20451,
            "sort_order": 10,
        }
        invalid_policies = [
            {"selection_signal": "unknown"},
            {"selection_signal": "throughput_ewma", "throughput_min_bytes": 0},
            {"selection_signal": "throughput_ewma", "throughput_min_seconds": 0},
            {"selection_signal": "throughput_ewma", "throughput_ewma_alpha": 0},
            {"selection_signal": "throughput_ewma", "throughput_ewma_alpha": 1.1},
            {"selection_signal": "throughput_ewma", "throughput_explore_every_connections": 1},
            {"selection_signal": "throughput_ewma", "throughput_explore_every_connections": 10001},
            {"selection_signal": "throughput_ewma", "throughput_sample_ttl_seconds": 29},
            {"selection_signal": "throughput_ewma", "throughput_sample_ttl_seconds": 86401},
            {"selection_signal": "throughput_ewma", "throughput_stall_interval_seconds": 0},
        ]
        for policy in invalid_policies:
            with self.subTest(policy=policy), self.assertRaises(ValueError):
                relay_module.parse_config(self.config("race", ("198.51.100.8", 80), [candidate], **policy))
        with self.assertRaises(ValueError):
            relay_module.parse_config(
                self.config(
                    "single",
                    ("198.51.100.8", 80),
                    [candidate],
                    selection_signal="throughput_ewma",
                )
            )

    def test_connection_telemetry_qualifies_directional_flow_and_counts_stalls(self) -> None:
        telemetry = relay_module.ConnectionTelemetry(started_at=100.0, stall_interval_seconds=1.0)
        telemetry.record("upload", 524_288, now=100.2)
        telemetry.record("upload", 524_288, now=101.2)
        telemetry.record("upload", 524_288, now=103.2)
        telemetry.record("download", 2_000_000, now=101.0)

        sample = telemetry.finish(now=104.2, min_bytes=1_048_576, min_seconds=2.0)
        self.assertEqual(1_572_864, sample.upload_bytes)
        self.assertEqual(1, sample.upload_zero_intervals)
        self.assertTrue(sample.upload_qualifies)
        self.assertAlmostEqual(4.0, sample.upload_duration_seconds, delta=0.01)
        self.assertGreater(sample.upload_mbps, 0)
        self.assertTrue(sample.download_qualifies)
        self.assertEqual(2_000_000, sample.download_bytes)

    def test_connection_telemetry_rejects_short_and_incomplete_flows(self) -> None:
        short = relay_module.ConnectionTelemetry(started_at=100.0, stall_interval_seconds=1.0)
        short.record("upload", 1_000_000, now=100.1)
        sample = short.finish(now=100.5, min_bytes=1_048_576, min_seconds=2.0)
        self.assertFalse(sample.upload_qualifies)

        incomplete = relay_module.ConnectionTelemetry(started_at=100.0, stall_interval_seconds=1.0)
        incomplete.record("download", 1_100_000, now=100.1)
        sample = incomplete.finish(now=103.0, min_bytes=1_048_576, min_seconds=2.0)
        self.assertTrue(sample.download_qualifies)
        self.assertFalse(sample.upload_qualifies)

    def test_throughput_score_prefers_balanced_high_delivery_over_latency(self) -> None:
        rows = [
            {"combination_id": 1, "line_id": 451, "socks_host": "127.0.0.1", "socks_port": 20451, "sort_order": 10},
            {"combination_id": 2, "line_id": 447, "socks_host": "127.0.0.1", "socks_port": 20447, "sort_order": 20},
        ]
        payload = self.config(
            "race",
            ("198.51.100.8", 80),
            rows,
            selection_signal="throughput_ewma",
        )
        selector = relay_module.RelayRuntime(relay_module.parse_config(payload)).selector
        slow = selector.state(451)
        fast = selector.state(447)
        slow.tcp_healthy = fast.tcp_healthy = True
        slow.latency_ms, fast.latency_ms = 2.0, 30.0
        slow.upload_ewma_mbps = slow.download_ewma_mbps = 1.0
        slow.upload_stall_ewma = 0.5
        slow.download_stall_ewma = 0.5
        slow.qualifying_samples = 1
        now = time.monotonic()
        slow.sample_updated_at = now
        fast.upload_ewma_mbps = fast.download_ewma_mbps = 100.0
        fast.qualifying_samples = 1
        fast.sample_updated_at = now

        selector._reselect_tcp(now=now)
        self.assertEqual(447, selector.selected_line_id())
        self.assertLess(selector.throughput_score(slow, now=now), selector.throughput_score(fast, now=now))

        one_direction = selector.state(447)
        one_direction.download_ewma_mbps = None
        self.assertAlmostEqual(
            50.0,
            selector.throughput_score(one_direction, now=now),
            delta=0.01,
        )

    def test_throughput_reservations_are_distinct_and_release_unsampled_candidates(self) -> None:
        rows = [
            {"combination_id": index, "line_id": line_id, "socks_host": "127.0.0.1", "socks_port": 20000 + line_id, "sort_order": index * 10}
            for index, line_id in enumerate((447, 451, 453, 455), start=1)
        ]
        payload = self.config(
            "race",
            ("198.51.100.8", 80),
            rows,
            selection_signal="throughput_ewma",
        )
        selector = relay_module.RelayRuntime(relay_module.parse_config(payload)).selector
        for state in selector.states.values():
            state.tcp_healthy = True

        reservations = [selector.reserve_tcp_candidate(now=100.0) for _ in range(4)]
        self.assertEqual({447, 451, 453, 455}, {state.candidate.line_id for state in reservations if state})
        self.assertIsNone(selector.reserve_tcp_candidate(now=100.0))
        selector.release_tcp_candidate(reservations[0], qualifying=False, now=101.0)
        self.assertEqual(447, selector.reserve_tcp_candidate(now=101.0).candidate.line_id)

        for state in selector.states.values():
            state.sampling_in_flight = False
            state.qualifying_samples = 1
            state.sample_updated_at = 102.0
            state.upload_ewma_mbps = state.download_ewma_mbps = float(state.candidate.line_id)
        selector.connection_count = 19
        chosen = selector.reserve_tcp_candidate(now=102.0)
        self.assertIsNotNone(chosen)

    def test_throughput_state_restores_only_fresh_matching_scope(self) -> None:
        rows = [
            {"combination_id": 1, "line_id": 451, "socks_host": "127.0.0.1", "socks_port": 20451, "sort_order": 10},
            {"combination_id": 2, "line_id": 453, "socks_host": "127.0.0.1", "socks_port": 20453, "sort_order": 20},
        ]
        config_payload = self.config(
            "race",
            ("198.51.100.8", 80),
            rows,
            selection_signal="throughput_ewma",
            throughput_sample_ttl_seconds=300,
        )
        with tempfile.TemporaryDirectory() as temp_dir:
            state_file = pathlib.Path(temp_dir) / "relay-state.json"
            original = relay_module.RelayRuntime(relay_module.parse_config(config_payload), state_file=state_file)
            state = original.selector.state(451)
            state.upload_ewma_mbps = 42.5
            state.download_ewma_mbps = 38.0
            state.upload_stall_ewma = 0.1
            state.download_stall_ewma = 0.2
            state.qualifying_samples = 3
            state.sample_updated_at = time.monotonic() - 5
            original._write_state()

            restored = relay_module.RelayRuntime(relay_module.parse_config(config_payload), state_file=state_file)
            restored_throughput = restored.candidate_state(451)["throughput"]
            self.assertEqual(42.5, restored_throughput["upload_ewma_mbps"])
            self.assertEqual(38.0, restored_throughput["download_ewma_mbps"])
            self.assertEqual(3, restored_throughput["qualifying_samples"])

            changed_target = dict(config_payload)
            changed_target["target_host"] = "198.51.100.9"
            different_target = relay_module.RelayRuntime(
                relay_module.parse_config(changed_target), state_file=state_file
            )
            self.assertIsNone(different_target.candidate_state(451)["throughput"]["upload_ewma_mbps"])

            changed_identity = dict(config_payload)
            changed_identity["candidates"] = [dict(rows[0]) | {"combination_id": 99}, rows[1]]
            different_identity = relay_module.RelayRuntime(
                relay_module.parse_config(changed_identity), state_file=state_file
            )
            self.assertIsNone(different_identity.candidate_state(451)["throughput"]["upload_ewma_mbps"])

            expired = json.loads(state_file.read_text(encoding="utf-8"))
            expired_row = next(row for row in expired["candidates"] if row["line_id"] == 451)
            expired_row["throughput"]["sample_updated_at"] = time.time() - 301
            state_file.write_text(json.dumps(expired), encoding="utf-8")
            expired_runtime = relay_module.RelayRuntime(
                relay_module.parse_config(config_payload), state_file=state_file
            )
            self.assertIsNone(expired_runtime.candidate_state(451)["throughput"]["upload_ewma_mbps"])

    def test_throughput_state_ignores_malformed_and_nonfinite_values(self) -> None:
        rows = [
            {"combination_id": 1, "line_id": 451, "socks_host": "127.0.0.1", "socks_port": 20451, "sort_order": 10},
            {"combination_id": 2, "line_id": 453, "socks_host": "127.0.0.1", "socks_port": 20453, "sort_order": 20},
        ]
        config_payload = self.config(
            "race",
            ("198.51.100.8", 80),
            rows,
            selection_signal="throughput_ewma",
        )
        with tempfile.TemporaryDirectory() as temp_dir:
            state_file = pathlib.Path(temp_dir) / "relay-state.json"
            state_file.write_text("{broken", encoding="utf-8")
            malformed = relay_module.RelayRuntime(relay_module.parse_config(config_payload), state_file=state_file)
            self.assertIsNone(malformed.candidate_state(451)["throughput"]["upload_ewma_mbps"])

            state = {
                "scope": {"mode": "race", "target_host": "198.51.100.8", "target_port": 80},
                "candidates": [
                    {
                        "combination_id": 1,
                        "line_id": 451,
                        "leg_route_id": 0,
                        "throughput": {
                            "upload_ewma_mbps": float("nan"),
                            "download_ewma_mbps": 20.0,
                            "upload_stall_ewma": 0.0,
                            "download_stall_ewma": 0.0,
                            "qualifying_samples": 1,
                            "sample_updated_at": time.time(),
                        },
                    }
                ],
            }
            state_file.write_text(json.dumps(state), encoding="utf-8")
            nonfinite = relay_module.RelayRuntime(relay_module.parse_config(config_payload), state_file=state_file)
            self.assertIsNone(nonfinite.candidate_state(451)["throughput"]["upload_ewma_mbps"])

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
