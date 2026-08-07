from __future__ import annotations

import asyncio
import base64
import contextlib
import hashlib
import hmac
import importlib.util
import json
import os
import pathlib
import signal
import socket
import struct
import subprocess
import sys
import tempfile
import time
import unittest


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]
BOND_BINARY = pathlib.Path(
    os.environ.get(
        "LEIKWAN_BOND_TEST_BINARY",
        REPO_ROOT / "artifacts" / "wstunnel" / "leikwan-bond_linux_amd64",
    )
)
BASE_SPEC = importlib.util.spec_from_file_location(
    "fixed_bond_integration_helpers",
    REPO_ROOT / "slave-agent" / "tests" / "test_leigod_fixed_bond_integration.py",
)
assert BASE_SPEC is not None and BASE_SPEC.loader is not None
base = importlib.util.module_from_spec(BASE_SPEC)
sys.modules[BASE_SPEC.name] = base
BASE_SPEC.loader.exec_module(base)


def integration_log(message: str) -> None:
    if os.environ.get("LEIGOD_INTEGRATION_DEBUG"):
        print(message, flush=True)


def signed_token(secret: str, host: str, port: int) -> str:
    now = int(time.time())
    payload = json.dumps(
        {
            "target_host": host,
            "target_port": port,
            "target_protocol": "tcp",
            "service_id": 2,
            "forward_id": 47,
            "issued_at": now,
            "expires_at": now + 300,
        },
        separators=(",", ":"),
    ).encode()

    def encode(value: bytes) -> str:
        return base64.urlsafe_b64encode(value).decode().rstrip("=")

    return encode(payload) + "." + encode(hmac.new(secret.encode(), payload, hashlib.sha256).digest())


async def throttled_copy(
    reader: asyncio.StreamReader,
    writer: asyncio.StreamWriter,
    bytes_per_second: int | None,
    counter: list[int],
) -> None:
    try:
        while data := await reader.read(16_384):
            writer.write(data)
            await writer.drain()
            counter[0] += len(data)
            if bytes_per_second:
                await asyncio.sleep(len(data) / bytes_per_second)
    except (asyncio.IncompleteReadError, ConnectionError, OSError):
        pass
    finally:
        with contextlib.suppress(Exception):
            if writer.can_write_eof():
                writer.write_eof()


class ThrottledRouteSocksServer:
    def __init__(self, line_id: int, reverse_bytes_per_second: int | None = None) -> None:
        self.line_id = line_id
        self.reverse_bytes_per_second = reverse_bytes_per_second
        self.server: asyncio.AbstractServer | None = None
        self.observed_routes: list[int] = []
        self.reverse_bytes = [0]
        self._writers: set[asyncio.StreamWriter] = set()

    async def __aenter__(self) -> "ThrottledRouteSocksServer":
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
        return {
            "combination_id": combination_id,
            "line_id": self.line_id,
            "leg_route_id": self.line_id,
            "socks_host": self.address[0],
            "socks_port": self.address[1],
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
            host, port = await base.read_socks_address(reader, atyp)
            if version != 5 or command != 1:
                return
            remote_reader, remote_writer = await asyncio.open_connection(host, port)
            writer.write(b"\x05\x00\x00\x01\x7f\x00\x00\x01\x00\x00")
            await writer.drain()
            header = await reader.readexactly(32)
            self.observed_routes.append(struct.unpack(">I", header[24:28])[0])
            remote_writer.write(header)
            await remote_writer.drain()
            await asyncio.gather(
                throttled_copy(reader, remote_writer, None, [0]),
                throttled_copy(
                    remote_reader,
                    writer,
                    self.reverse_bytes_per_second,
                    self.reverse_bytes,
                ),
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


class ReverseBulkTarget:
    def __init__(self) -> None:
        self.server: asyncio.AbstractServer | None = None
        self.connection_count = 0
        self._writers: set[asyncio.StreamWriter] = set()

    async def __aenter__(self) -> "ReverseBulkTarget":
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
        self.connection_count += 1
        self._writers.add(writer)
        try:
            while line := await reader.readline():
                if line == b"PING\n":
                    writer.write(b"PONG\n")
                    await writer.drain()
                    continue
                if line.startswith(b"REVERSE "):
                    total = int(line.split()[1])
                    chunk = bytes(range(256)) * 256
                    remaining = total
                    while remaining > 0:
                        payload = chunk[: min(len(chunk), remaining)]
                        writer.write(payload)
                        await writer.drain()
                        remaining -= len(payload)
        except (ConnectionError, ValueError):
            pass
        finally:
            self._writers.discard(writer)
            writer.close()
            with contextlib.suppress(Exception):
                await writer.wait_closed()


@unittest.skipUnless(os.name == "posix", "Linux artifact integration runs under WSL/Linux")
class ServerSideAdaptiveBondIntegrationTests(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self) -> None:
        if not BOND_BINARY.is_file() or not os.access(BOND_BINARY, os.X_OK):
            self.skipTest("Linux bond release artifact is missing or not executable")
        self.processes: list[subprocess.Popen[bytes]] = []
        self.runtimes: list[object] = []

    async def asyncTearDown(self) -> None:
        await self.cleanup_resources()

    async def cleanup_resources(self) -> None:
        for process in reversed(self.processes):
            if process.poll() is None:
                with contextlib.suppress(ProcessLookupError):
                    os.killpg(process.pid, signal.SIGTERM)
                try:
                    await asyncio.to_thread(process.wait, 1)
                except subprocess.TimeoutExpired:
                    with contextlib.suppress(ProcessLookupError):
                        os.killpg(process.pid, signal.SIGKILL)
                    with contextlib.suppress(subprocess.TimeoutExpired):
                        await asyncio.to_thread(process.wait, 1)
            else:
                process.wait()
        self.processes.clear()
        for runtime in reversed(self.runtimes):
            with contextlib.suppress(asyncio.TimeoutError):
                await asyncio.wait_for(runtime.close(), timeout=2)
        self.runtimes.clear()

    def launch(self, args: list[str], snapshot_path: pathlib.Path) -> subprocess.Popen[bytes]:
        env = dict(os.environ)
        env.update(
            {
                "LEIKWAN_BOND_OBSERVER_ID": "44",
                "LEIKWAN_BOND_FORWARD_ID": "47",
                "LEIKWAN_BOND_SNAPSHOT_PATH": str(snapshot_path),
                "RUST_LOG": os.environ.get("RUST_LOG", "warn"),
            }
        )
        process = subprocess.Popen(
            [str(BOND_BINARY), *args],
            stdout=None if os.environ.get("LEIGOD_INTEGRATION_INHERIT_LOG") else subprocess.DEVNULL,
            stderr=None if os.environ.get("LEIGOD_INTEGRATION_INHERIT_LOG") else subprocess.DEVNULL,
            env=env,
            start_new_session=True,
        )
        self.processes.append(process)
        return process

    async def wait_for_port(self, port: int, process: subprocess.Popen[bytes]) -> None:
        deadline = asyncio.get_running_loop().time() + 5
        while asyncio.get_running_loop().time() < deadline:
            if process.poll() is not None:
                self.fail(f"bond process exited before listening: {process.returncode}")
            try:
                _reader, writer = await asyncio.open_connection("127.0.0.1", port)
            except OSError:
                await asyncio.sleep(0.02)
                continue
            writer.close()
            await writer.wait_closed()
            return
        self.fail(f"bond process did not listen on {port}")

    async def wait_for_snapshot_quarantine(self, path: pathlib.Path, route_id: int) -> dict:
        deadline = asyncio.get_running_loop().time() + 8
        last: dict = {}
        while asyncio.get_running_loop().time() < deadline:
            try:
                last = json.loads(path.read_text(encoding="utf-8"))
            except (OSError, json.JSONDecodeError):
                await asyncio.sleep(0.05)
                continue
            leg = next(
                (row for row in last.get("legs", []) if int(row.get("leg_route_id", 0)) == route_id),
                None,
            )
            if isinstance(leg, dict) and leg.get("tcp", {}).get("bulk_admission") == "quarantined":
                return leg
            await asyncio.sleep(0.05)
        self.fail(f"slow route was not quarantined; last snapshot={last}")

    async def test_one_application_connection_gets_reverse_bulk_while_slow_line_is_quarantined(self) -> None:
        async with (
            ReverseBulkTarget() as target,
            ThrottledRouteSocksServer(451) as fast,
            ThrottledRouteSocksServer(455, reverse_bytes_per_second=1_000_000) as slow,
        ):
            with tempfile.TemporaryDirectory() as temp_dir:
                try:
                    await self.run_reverse_case(target, fast, slow, pathlib.Path(temp_dir))
                finally:
                    await self.cleanup_resources()

    async def run_reverse_case(
        self,
        target: ReverseBulkTarget,
        fast: ThrottledRouteSocksServer,
        slow: ThrottledRouteSocksServer,
        root: pathlib.Path,
    ) -> None:
        secret = "integration-secret"
        token = signed_token(secret, *target.address)
        server_port = base.free_loopback_port()
        relay_port = base.free_loopback_port()
        frontend_port = base.free_loopback_port()
        server_snapshot = root / "server-snapshot.json"
        client_snapshot = root / "client-snapshot.json"
        adaptive = [
            "--adaptive-line-admission",
            "--throughput-min-mbps",
            "15",
            "--throughput-relative-floor",
            "0.5",
            "--throughput-min-sample-bytes",
            "262144",
            "--throughput-min-sample-ms",
            "250",
            "--throughput-bad-windows",
            "2",
            "--throughput-recovery-windows",
            "2",
            "--throughput-quarantine-ms",
            "60000",
            "--throughput-probe-interval-ms",
            "30000",
            "--throughput-probe-bytes",
            "262144",
        ]
        server = self.launch(
            [
                "server",
                "--listen",
                f"127.0.0.1:{server_port}",
                "--token-secret",
                secret,
                *adaptive,
            ],
            server_snapshot,
        )
        integration_log(f"integration: server pid={server.pid} port={server_port}")
        await self.wait_for_port(server_port, server)

        relay = base.relay_module.RelayRuntime(
            base.relay_module.parse_config(
                {
                    "mode": "bond",
                    "listen_host": "127.0.0.1",
                    "listen_port": relay_port,
                    "target_host": "127.0.0.1",
                    "target_port": server_port,
                    "transport": "tcp",
                    "candidates": [fast.candidate(1, 10), slow.candidate(2, 20)],
                    "policy": {"connect_timeout_seconds": 1},
                }
            )
        )
        await relay.start()
        self.runtimes.append(relay)
        integration_log(f"integration: relay port={relay.tcp_address[1]}")

        client = self.launch(
            [
                "client",
                "--protocol",
                "tcp",
                "--listen",
                f"127.0.0.1:{frontend_port}",
                "--legs",
                f"tcp://127.0.0.1:{relay.tcp_address[1]}?leg_route_id=451,"
                f"tcp://127.0.0.1:{relay.tcp_address[1]}?leg_route_id=455",
                "--session-token",
                token,
                "--preferred-initial-legs",
                "2",
                "--minimum-initial-legs",
                "2",
                "--initial-leg-wait-ms",
                "3000",
                "--connect-timeout-ms",
                "1000",
                "--retransmit-ms",
                "5000",
                "--leg-reconnect-backoff-ms",
                "5000",
                "--leg-reconnect-backoff-max-ms",
                "60000",
                *adaptive,
            ],
            client_snapshot,
        )
        integration_log(f"integration: client pid={client.pid} port={frontend_port}")
        deadline = asyncio.get_running_loop().time() + 5
        while asyncio.get_running_loop().time() < deadline:
            if fast.observed_routes and slow.observed_routes:
                break
            if client.poll() is not None:
                self.fail(f"bond client exited before routes were ready: {client.returncode}")
            await asyncio.sleep(0.02)
        self.assertEqual([451], fast.observed_routes)
        self.assertEqual([455], slow.observed_routes)
        integration_log("integration: both routes ready")

        reader, writer = await asyncio.open_connection("127.0.0.1", frontend_port)
        writer.write(b"PING\n")
        await writer.drain()
        self.assertEqual(b"PONG\n", await asyncio.wait_for(reader.readline(), timeout=5))
        integration_log("integration: ping ok")
        reverse_bytes = 8 * 1024 * 1024
        writer.write(f"REVERSE {reverse_bytes}\n".encode())
        await writer.drain()
        received = await asyncio.wait_for(reader.readexactly(reverse_bytes), timeout=20)
        integration_log("integration: reverse payload received")
        self.assertEqual(bytes(range(256)), received[:256])
        self.assertEqual(reverse_bytes, len(received))
        slow_leg = await self.wait_for_snapshot_quarantine(server_snapshot, 455)
        fast_snapshot = json.loads(server_snapshot.read_text(encoding="utf-8"))
        fast_leg = next(row for row in fast_snapshot["legs"] if row["leg_route_id"] == 451)
        self.assertEqual("admitted", fast_leg["tcp"]["bulk_admission"])
        self.assertGreaterEqual(slow_leg["tcp"]["qualifying_samples"], 2)
        before_fast = fast.reverse_bytes[0]
        before_slow = slow.reverse_bytes[0]
        follow_up_bytes = 2 * 1024 * 1024
        writer.write(f"REVERSE {follow_up_bytes}\n".encode())
        await writer.drain()
        follow_up = await asyncio.wait_for(reader.readexactly(follow_up_bytes), timeout=10)
        self.assertEqual(follow_up_bytes, len(follow_up))
        delta_fast = fast.reverse_bytes[0] - before_fast
        delta_slow = slow.reverse_bytes[0] - before_slow
        if os.environ.get("LEIGOD_INTEGRATION_DEBUG"):
            print(
                "integration: route_bytes=",
                {"451": fast.reverse_bytes[0], "455": slow.reverse_bytes[0]},
                "follow_up_delta=",
                {"451": delta_fast, "455": delta_slow},
                "snapshot=",
                json.dumps({"fast": fast_leg, "slow": slow_leg}, sort_keys=True),
                flush=True,
            )
        self.assertLess(delta_slow, delta_fast)
        self.assertEqual(1, target.connection_count)
        writer.close()
        await writer.wait_closed()


if __name__ == "__main__":
    unittest.main()
