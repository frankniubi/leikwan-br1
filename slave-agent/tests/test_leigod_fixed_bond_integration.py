from __future__ import annotations

import asyncio
import contextlib
import importlib.util
import json
import os
import pathlib
import socket
import struct
import subprocess
import sys
import tempfile
import unittest
import signal


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]
BOND_BINARY = REPO_ROOT / "artifacts" / "wstunnel" / "leikwan-bond_linux_amd64"
HELLO_ACK = b"LBND" + bytes([2, 2]) + bytes(2) + struct.pack(">QQII", 0, 0, 0, 0)
RELAY_SPEC = importlib.util.spec_from_file_location(
    "leigod_forward_relay_process_integration", REPO_ROOT / "slave-agent" / "leigod_forward_relay.py"
)
assert RELAY_SPEC is not None and RELAY_SPEC.loader is not None
relay_module = importlib.util.module_from_spec(RELAY_SPEC)
sys.modules[RELAY_SPEC.name] = relay_module
RELAY_SPEC.loader.exec_module(relay_module)


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
        raise RuntimeError("unsupported SOCKS address type")
    port = struct.unpack(">H", await reader.readexactly(2))[0]
    return host, port


async def copy_stream(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
    try:
        while data := await reader.read(65536):
            writer.write(data)
            await writer.drain()
    except (asyncio.IncompleteReadError, ConnectionError):
        pass
    finally:
        with contextlib.suppress(Exception):
            if writer.can_write_eof():
                writer.write_eof()


class BondHelloTarget:
    def __init__(self) -> None:
        self.server: asyncio.AbstractServer | None = None
        self.observed_routes: list[int] = []
        self._writers: set[asyncio.StreamWriter] = set()

    async def __aenter__(self) -> "BondHelloTarget":
        self.server = await asyncio.start_server(self._handle, "127.0.0.1", 0)
        return self

    async def __aexit__(self, *_args: object) -> None:
        assert self.server is not None
        self.server.close()
        await self.server.wait_closed()
        for writer in list(self._writers):
            writer.close()
            with contextlib.suppress(Exception):
                await writer.wait_closed()

    @property
    def address(self) -> tuple[str, int]:
        assert self.server is not None
        host, port = self.server.sockets[0].getsockname()[:2]
        return str(host), int(port)

    async def _handle(self, reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
        self._writers.add(writer)
        try:
            header = await reader.readexactly(32)
            if header[:6] != b"LBND\x02\x01":
                return
            route_id = struct.unpack(">I", header[24:28])[0]
            payload_len = struct.unpack(">I", header[28:32])[0]
            await reader.readexactly(payload_len)
            self.observed_routes.append(route_id)
            writer.write(HELLO_ACK)
            await writer.drain()
            await reader.read()
        except (asyncio.IncompleteReadError, ConnectionError):
            pass
        finally:
            self._writers.discard(writer)
            writer.close()
            with contextlib.suppress(Exception):
                await writer.wait_closed()


class RouteRecordingSocksServer:
    def __init__(self, line_id: int) -> None:
        self.line_id = line_id
        self.connect_delay_ms = 0
        self.observed_routes: list[int] = []
        self.connect_count = 0
        self.server: asyncio.AbstractServer | None = None
        self._writers: set[asyncio.StreamWriter] = set()

    async def __aenter__(self) -> "RouteRecordingSocksServer":
        self.server = await asyncio.start_server(self._handle, "127.0.0.1", 0)
        return self

    async def __aexit__(self, *_args: object) -> None:
        assert self.server is not None
        self.server.close()
        await self.server.wait_closed()
        for writer in list(self._writers):
            writer.close()
            with contextlib.suppress(Exception):
                await writer.wait_closed()

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
            "leg_route_id": self.line_id,
            "socks_host": host,
            "socks_port": port,
            "sort_order": sort_order,
        }

    async def _handle(self, reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
        remote_writer: asyncio.StreamWriter | None = None
        self._writers.add(writer)
        try:
            version, method_count = await reader.readexactly(2)
            methods = await reader.readexactly(method_count)
            if version != 5 or 0 not in methods:
                return
            writer.write(b"\x05\x00")
            await writer.drain()
            version, command, _reserved, atyp = await reader.readexactly(4)
            host, port = await read_socks_address(reader, atyp)
            if version != 5 or command != 1:
                return
            self.connect_count += 1
            if self.connect_delay_ms:
                await asyncio.sleep(self.connect_delay_ms / 1000)
            remote_reader, remote_writer = await asyncio.open_connection(host, port)
            writer.write(b"\x05\x00\x00\x01\x7f\x00\x00\x01\x00\x00")
            await writer.drain()

            header = await reader.readexactly(32)
            self.observed_routes.append(struct.unpack(">I", header[24:28])[0])
            remote_writer.write(header)
            await remote_writer.drain()
            await asyncio.gather(
                copy_stream(reader, remote_writer),
                copy_stream(remote_reader, writer),
            )
        except (asyncio.IncompleteReadError, ConnectionError, OSError):
            pass
        finally:
            self._writers.discard(writer)
            if remote_writer is not None:
                remote_writer.close()
                with contextlib.suppress(Exception):
                    await remote_writer.wait_closed()
            writer.close()
            with contextlib.suppress(Exception):
                await writer.wait_closed()


@unittest.skipUnless(os.name == "posix", "Linux artifact integration runs under WSL/Linux")
class FixedBondProcessIntegrationTests(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self) -> None:
        if not BOND_BINARY.is_file() or not os.access(BOND_BINARY, os.X_OK):
            self.skipTest("Linux bond release artifact is missing or not executable")
        self.processes: list[subprocess.Popen[bytes]] = []
        self.runtimes: list[object] = []

    async def asyncTearDown(self) -> None:
        for process in reversed(self.processes):
            await self.stop_process(process)
        for runtime in reversed(self.runtimes):
            await runtime.close()

    async def stop_process(self, process: subprocess.Popen[bytes]) -> tuple[bytes, bytes]:
        if process.poll() is None:
            with contextlib.suppress(ProcessLookupError):
                os.kill(process.pid, signal.SIGKILL)
            # WSL can delay asyncio's child-watcher notification for an ELF
            # process launched from a mounted Windows path; do not block test
            # cleanup on that notification.
            await asyncio.sleep(0.1)
        return b"", b""

    async def wait_for_routes(
        self,
        first: RouteRecordingSocksServer,
        second: RouteRecordingSocksServer,
        process: subprocess.Popen[bytes],
        timeout: float = 5.0,
    ) -> None:
        deadline = asyncio.get_running_loop().time() + timeout
        while asyncio.get_running_loop().time() < deadline:
            if first.observed_routes and second.observed_routes:
                return
            if process.poll() is not None:
                self.fail(f"bond client exited before both routes were ready: {process.returncode}")
            await asyncio.sleep(0.02)
        self.fail("bond client did not establish both route-tagged legs")

    async def launch_bond_client(self, relay_port: int, routes: tuple[int, int]) -> subprocess.Popen[bytes]:
        process = subprocess.Popen(
            [
                str(BOND_BINARY),
                "client",
                "--protocol",
                "tcp",
                "--listen",
                f"127.0.0.1:{free_loopback_port()}",
                "--legs",
                ",".join(f"tcp://127.0.0.1:{relay_port}?leg_route_id={route_id}" for route_id in routes),
                "--session-token",
                "local-integration-token",
                "--preferred-initial-legs",
                "2",
                "--minimum-initial-legs",
                "2",
                "--initial-leg-wait-ms",
                "3000",
                "--connect-timeout-ms",
                "1000",
                "--leg-reconnect-backoff-ms",
                "5000",
                "--leg-reconnect-backoff-max-ms",
                "60000",
            ],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            start_new_session=True,
        )
        self.processes.append(process)
        return process

    async def test_fixed_routes_survive_delayed_reversed_order_and_reject_unknown_routes(self) -> None:
        async with BondHelloTarget() as target, RouteRecordingSocksServer(451) as first, RouteRecordingSocksServer(453) as second:
            with tempfile.TemporaryDirectory() as temp_dir:
                relay_port = free_loopback_port()
                state_file = pathlib.Path(temp_dir) / "relay-state.json"
                runtime = relay_module.RelayRuntime(
                    relay_module.parse_config(
                        {
                            "mode": "bond",
                            "listen_host": "127.0.0.1",
                            "listen_port": relay_port,
                            "target_host": target.address[0],
                            "target_port": target.address[1],
                            "transport": "tcp",
                            "candidates": [first.candidate(1, 10), second.candidate(2, 20)],
                            "policy": {"connect_timeout_seconds": 1},
                        }
                    ),
                    state_file=state_file,
                )
                await runtime.start()
                self.runtimes.append(runtime)

                client = await self.launch_bond_client(runtime.tcp_address[1], (451, 453))
                await self.wait_for_routes(first, second, client)
                await self.stop_process(client)
                self.assertEqual([451], first.observed_routes)
                self.assertEqual([453], second.observed_routes)
                self.assertEqual({451, 453}, set(target.observed_routes))

                first.observed_routes.clear()
                second.observed_routes.clear()
                target.observed_routes.clear()
                first.connect_delay_ms = 200
                delayed = await self.launch_bond_client(runtime.tcp_address[1], (453, 451))
                await self.wait_for_routes(first, second, delayed)
                await self.stop_process(delayed)
                self.assertEqual([451], first.observed_routes)
                self.assertEqual([453], second.observed_routes)
                self.assertEqual({451, 453}, set(target.observed_routes))

                before_connects = first.connect_count + second.connect_count
                for route_id in (0, 999):
                    reader, writer = await asyncio.open_connection("127.0.0.1", relay_port)
                    try:
                        header = b"LBND" + bytes([2, 1]) + bytes(2) + struct.pack(">QQII", 0, 0, route_id, 20)
                        writer.write(header + bytes(20))
                        await writer.drain()
                        self.assertEqual(b"", await asyncio.wait_for(reader.read(), timeout=1))
                    finally:
                        writer.close()
                        await writer.wait_closed()
                self.assertEqual(before_connects, first.connect_count + second.connect_count)

                await asyncio.sleep(0.05)
                state = json.loads(state_file.read_text(encoding="utf-8"))
                counters = {row["leg_route_id"]: row["bond_route"] for row in state["candidates"]}
                self.assertGreaterEqual(counters[451]["connect_successes"], 2)
                self.assertGreaterEqual(counters[453]["connect_successes"], 2)
                await self.stop_process(client)


if __name__ == "__main__":
    unittest.main()
