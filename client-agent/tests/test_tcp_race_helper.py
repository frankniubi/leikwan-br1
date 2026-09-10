import asyncio
import importlib.util
import pathlib
import socket
import sys
import unittest


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


def load_module(name: str, relative_path: str):
    path = REPO_ROOT / relative_path
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    sys.modules[name] = module
    spec.loader.exec_module(module)
    return module


tcp_race = load_module("leikwan_tcp_race_under_test", "client-agent/leikwan_tcp_race.py")
install_runtime = load_module(
    "install_forward_runtime_tcp_race_under_test",
    "client-agent/install_forward_runtime.py",
)


def unused_tcp_port() -> int:
    sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    sock.bind(("127.0.0.1", 0))
    port = int(sock.getsockname()[1])
    sock.close()
    return port


async def open_recording_echo_server(received: list[bytes], greeting: bytes = b""):
    async def handler(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
        try:
            if greeting:
                writer.write(greeting)
                await writer.drain()
            data = await reader.read()
            if data:
                received.append(data)
                writer.write(data)
                await writer.drain()
        finally:
            writer.close()
            await writer.wait_closed()

    return await asyncio.start_server(handler, "127.0.0.1", 0)


class TcpRaceHelperTests(unittest.IsolatedAsyncioTestCase):
    async def test_first_success_copies_bidirectionally(self) -> None:
        received: list[bytes] = []
        backend = await open_recording_echo_server(received, greeting=b"ready\n")
        backend_port = int(backend.sockets[0].getsockname()[1])
        proxy = tcp_race.TcpRaceProxy(
            ("127.0.0.1", 0),
            [("127.0.0.1", unused_tcp_port()), ("127.0.0.1", backend_port)],
            connect_timeout=0.25,
        )
        await proxy.start()
        try:
            reader, writer = await asyncio.open_connection(*proxy.bound_address)
            self.assertEqual(b"ready\n", await asyncio.wait_for(reader.readline(), 1.0))
            writer.write(b"one-copy")
            await writer.drain()
            writer.write_eof()
            self.assertEqual(b"one-copy", await asyncio.wait_for(reader.readexactly(8), 1.0))
            writer.close()
            await writer.wait_closed()
            self.assertEqual([b"one-copy"], received)
        finally:
            await proxy.close()
            backend.close()
            await backend.wait_closed()

    async def test_race_never_duplicates_application_bytes(self) -> None:
        first_received: list[bytes] = []
        second_received: list[bytes] = []
        first = await open_recording_echo_server(first_received)
        second = await open_recording_echo_server(second_received)
        first_port = int(first.sockets[0].getsockname()[1])
        second_port = int(second.sockets[0].getsockname()[1])
        proxy = tcp_race.TcpRaceProxy(
            ("127.0.0.1", 0),
            [("127.0.0.1", first_port), ("127.0.0.1", second_port)],
        )
        await proxy.start()
        try:
            reader, writer = await asyncio.open_connection(*proxy.bound_address)
            writer.write(b"not-duplicated")
            await writer.drain()
            writer.write_eof()
            self.assertEqual(
                b"not-duplicated",
                await asyncio.wait_for(reader.readexactly(len(b"not-duplicated")), 1.0),
            )
            writer.close()
            await writer.wait_closed()
            await asyncio.sleep(0.05)
            self.assertEqual([b"not-duplicated"], first_received + second_received)
        finally:
            await proxy.close()
            first.close()
            second.close()
            await first.wait_closed()
            await second.wait_closed()

    async def test_failed_target_is_quarantined(self) -> None:
        proxy = tcp_race.TcpRaceProxy(
            ("127.0.0.1", 0),
            [("127.0.0.1", unused_tcp_port())],
            connect_timeout=0.1,
            quarantine_seconds=5.0,
        )
        with self.assertRaises(ConnectionError):
            await proxy.connect_winner()
        self.assertEqual(1, proxy.targets[0].attempts)
        self.assertGreater(proxy.targets[0].quarantine_until, asyncio.get_running_loop().time())
        with self.assertRaises(ConnectionError):
            await proxy.connect_winner()
        self.assertEqual(1, proxy.targets[0].attempts)

    def test_target_parser_is_bounded_and_fails_closed(self) -> None:
        parsed = tcp_race.parse_targets(
            ",".join(f"127.0.0.1:{20000 + index}" for index in range(10))
        )
        self.assertEqual(8, len(parsed))
        for value in ("", "127.0.0.1", "127.0.0.1:0", "127.0.0.1:not-a-port"):
            with self.subTest(value=value), self.assertRaises(ValueError):
                tcp_race.parse_targets(value)


def sample_forward(*, mode: str, speed_limit_mbps: int | None = None) -> dict:
    payload = {
        "forward_id": 71,
        "listen_port": 11071,
        "target": "162.245.221.78:80",
        "sni": "edge.example.test",
        "path_prefix": "route/71",
        "transport": "tcp+udp",
        "mode": mode,
        "endpoints": [
            {
                "endpoint_id": 11,
                "host": "203.0.113.11",
                "port": 443,
                "runtime_target": "127.0.0.1:48011",
            },
            {
                "endpoint_id": 12,
                "host": "203.0.113.12",
                "port": 443,
                "runtime_target": "127.0.0.1:48012",
            },
        ],
    }
    if speed_limit_mbps is not None:
        payload["speed_limit_mbps"] = speed_limit_mbps
    return payload


class EndpointRuntimeTargetTests(unittest.TestCase):
    def test_race_uses_each_endpoint_runtime_target_for_tcp_and_udp(self) -> None:
        rendered = install_runtime.render_forward_runtime(sample_forward(mode="race"), {})
        script = rendered["start_script"]
        self.assertEqual(2, script.count(":127.0.0.1:48011"))
        self.assertEqual(2, script.count(":127.0.0.1:48012"))
        self.assertNotIn(":162.245.221.78:80", script)

    def test_single_uses_endpoint_runtime_target_without_rate_limit(self) -> None:
        forward = sample_forward(mode="single")
        forward["endpoints"] = forward["endpoints"][:1]
        rendered = install_runtime.render_forward_runtime(forward, {})
        unit = rendered["service_unit"]
        self.assertEqual(2, unit.count(":127.0.0.1:48011"))
        self.assertNotIn(":162.245.221.78:80", unit)

    def test_single_uses_endpoint_runtime_target_with_rate_limit(self) -> None:
        forward = sample_forward(mode="single", speed_limit_mbps=20)
        forward["endpoints"] = forward["endpoints"][:1]
        rendered = install_runtime.render_forward_runtime(forward, {})
        script = rendered["start_script"]
        self.assertEqual(2, script.count(":127.0.0.1:48011"))
        self.assertNotIn(":162.245.221.78:80", script)

    def test_runtime_honors_configured_wstunnel_binary_for_single_and_race(self) -> None:
        custom = "/opt/leikwan/bin/wstunnel"
        for mode in ("single", "race"):
            with self.subTest(mode=mode):
                forward = sample_forward(mode=mode)
                if mode == "single":
                    forward["endpoints"] = forward["endpoints"][:1]
                rendered = install_runtime.render_forward_runtime(forward, {"wstunnel_bin": custom})
                generated = str(rendered.get("service_unit") or "") + str(rendered.get("start_script") or "")
                self.assertIn(f"{custom} client", generated)
                self.assertNotIn("/usr/local/bin/wstunnel client", generated)

    def test_runtime_rejects_unit_paths_with_whitespace(self) -> None:
        forward = sample_forward(mode="single")
        forward["endpoints"] = forward["endpoints"][:1]
        with self.assertRaises(ValueError):
            install_runtime.render_forward_runtime(
                forward,
                {"wstunnel_bin": "/opt/Lei Kwan/wstunnel"},
            )


if __name__ == "__main__":
    unittest.main()
