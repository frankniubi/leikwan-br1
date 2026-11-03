#!/usr/bin/env python3
"""Byte-level parity harness for the Go external UoT sidecar."""

from __future__ import annotations

import asyncio
import json
import logging
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "sidecar-agent"))

import leikwan_external_uot as uot  # noqa: E402

FIXTURE_SECRET = "fixture-secret"
WILDCARD_SECRET = "wild-secret"
STATIC_TOKEN = "static-fixture-token"
FORWARD_ID = 170
WILDCARD_FORWARD_ID = 181
STATIC_FORWARD_ID = 171
SID = bytes(range(16))
SID_TWO = bytes(range(16, 32))

def binary_path() -> Path:
    override = os.environ.get("UOT_SIDECAR_GO_BIN", "").strip()
    candidates = [Path(override)] if override else []
    for name in ("uot-sidecar_windows_amd64.exe", "uot-sidecar_linux_amd64"):
        candidates.append(ROOT / "artifacts" / "uot-sidecar" / name)
    for candidate in candidates:
        if candidate.is_file():
            return candidate
    raise unittest.SkipTest("build the Go sidecar first: scripts/build-uot-sidecar.ps1 -All")


def build_config(target: str, **overrides) -> dict:
    config = {
        "listen_host": "127.0.0.1",
        "listen_port": 0,
        "forwards": {
            str(FORWARD_ID): {"targets": [target], "secret": FIXTURE_SECRET},
            str(WILDCARD_FORWARD_ID): {"targets": "*", "secret": WILDCARD_SECRET},
            str(STATIC_FORWARD_ID): {"targets": [target], "token": STATIC_TOKEN},
        },
        "max_frame_bytes": uot.HEADER_BYTES + uot.MAX_DATAGRAM,
        "frame_payload_max": uot.MAX_DATAGRAM,
        "queue_packets": 256,
        "keepalive_seconds": 10.0,
        "idle_timeout_seconds": 90.0,
        "session_grace_seconds": 30.0,
        "session_limit": 64,
        "udp_connect_timeout_seconds": 5.0,
    }
    config.update(overrides)
    return config

def hello_payload(secret: str, target: str, forward_id: int = FORWARD_ID, **extra) -> bytes:
    payload = {
        "token": uot.make_hmac_token(secret, forward_id, target),
        "target": target,
        "forward_id": forward_id,
    }
    payload.update(extra)
    return json.dumps(payload, separators=(",", ":")).encode("utf-8")


class EchoProtocol(asyncio.DatagramProtocol):
    """Loopback target: b"E"+"data" echoes data, b"S"+uint16be n answers n bytes."""

    def __init__(self) -> None:
        self.transport = None
        self.peers = []
        self.received = []

    def connection_made(self, transport) -> None:
        self.transport = transport

    def datagram_received(self, data: bytes, addr) -> None:
        self.peers.append(addr)
        self.received.append(bytes(data))
        if len(data) >= 3 and data[0:1] == b"S":
            self.transport.sendto(bytes(int.from_bytes(data[1:3], "big")), addr)
        elif data[0:1] == b"E":
            self.transport.sendto(data[1:], addr)
        else:
            self.transport.sendto(data, addr)

class GoSidecarProcess:
    """Runs the Go binary against a temp config and captures its log lines."""

    def __init__(self, config: dict) -> None:
        self.binary = binary_path()
        self.config = config
        self.process = None
        self.log_lines = []
        self.address = ("", 0)
        self._tempdir = None
        self._log_task = None

    async def __aenter__(self):
        self._tempdir = tempfile.TemporaryDirectory(prefix="uot-sidecar-go-")
        config_path = Path(self._tempdir.name) / "leikwan-external-uot.json"
        config_path.write_text(json.dumps(self.config), encoding="utf-8")
        self.process = await asyncio.create_subprocess_exec(
            str(self.binary), "--config", str(config_path), "--log-level", "INFO",
            stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.PIPE,
        )
        ready = asyncio.get_running_loop().create_future()
        self._log_task = asyncio.create_task(self._pump_logs(ready))
        try:
            await asyncio.wait_for(asyncio.shield(ready), 10.0)
        except asyncio.TimeoutError:
            await self.__aexit__(None, None, None)
            raise AssertionError("Go sidecar never reported its listening address")
        return self

    async def _pump_logs(self, ready) -> None:
        while True:
            raw = await self.process.stderr.readline()
            if not raw:
                break
            line = raw.decode("utf-8", "replace").rstrip()
            self.log_lines.append(line)
            if "external UoT listening on " in line and not ready.done():
                host_port = line.split("external UoT listening on ", 1)[1].strip()
                host, _, port = host_port.rpartition(":")
                self.address = (host, int(port))
                ready.set_result(True)

    async def __aexit__(self, *exc_info):
        if self.process is not None and self.process.returncode is None:
            self.process.terminate()
            try:
                await asyncio.wait_for(self.process.wait(), 5.0)
            except asyncio.TimeoutError:
                self.process.kill()
                await self.process.wait()
        if self._log_task is not None:
            self._log_task.cancel()
            await asyncio.gather(self._log_task, return_exceptions=True)
        if self._tempdir is not None:
            self._tempdir.cleanup()


class ReferenceSidecarProcess:
    """Runs the Python reference implementation in-process."""

    def __init__(self, config: dict) -> None:
        self.raw_config = config
        self.server = None
        self.address = ("", 0)

    async def __aenter__(self):
        with tempfile.TemporaryDirectory(prefix="uot-sidecar-py-") as tempdir:
            config_path = Path(tempdir) / "leikwan-external-uot.json"
            config_path.write_text(json.dumps(self.raw_config), encoding="utf-8")
            config = uot.load_config(config_path)
        self.server = uot.ExternalUoTServer(config)
        await self.server.start()
        self.address = self.server.address
        return self

    async def __aexit__(self, *exc_info):
        if self.server is not None:
            await self.server.close()
            self.server = None

async def open_carrier(address):
    return await asyncio.wait_for(asyncio.open_connection(address[0], address[1]), 5.0)


async def exchange_hello(address, session_id: bytes, payload: bytes):
    reader, writer = await open_carrier(address)
    try:
        await uot.write_frame(writer, uot.Frame(uot.HELLO, session_id, payload=payload), uot.DEFAULT_MAX_FRAME_BYTES)
        frame = await asyncio.wait_for(uot.read_frame(reader, uot.DEFAULT_MAX_FRAME_BYTES), 10.0)
        return frame.msg_type, frame.session_id, frame.payload
    finally:
        writer.close()
        try:
            await writer.wait_closed()
        except (ConnectionError, OSError):
            pass


class SidecarParityTests(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self) -> None:
        loop = asyncio.get_running_loop()
        self.echo_transport, self.echo = await loop.create_datagram_endpoint(
            EchoProtocol, local_addr=("127.0.0.1", 0)
        )
        self.captured_logs = []
        self._capture = CaptureHandler(self.captured_logs)
        logging.getLogger().addHandler(self._capture)
        logging.getLogger().setLevel(logging.INFO)
        self.target = "127.0.0.1:{0}".format(self.echo_transport.get_extra_info("sockname")[1])

    async def asyncTearDown(self) -> None:
        self.echo_transport.close()

    async def start_pair(self, **overrides):
        config = build_config(self.target, **overrides)
        go = await GoSidecarProcess(config).__aenter__()
        reference = await ReferenceSidecarProcess(config).__aenter__()
        self.addAsyncCleanup(go.__aexit__, None, None, None)
        self.addAsyncCleanup(reference.__aexit__, None, None, None)
        return go, reference
    async def test_ack_bytes_match_reference(self) -> None:
        go, reference = await self.start_pair()
        cases = [
            ("plain", lambda: hello_payload(FIXTURE_SECRET, self.target)),
            ("negotiated 300", lambda: hello_payload(FIXTURE_SECRET, self.target, frame_payload_max=300)),
            ("wildcard", lambda: hello_payload(WILDCARD_SECRET, "203.0.113.9:5353", forward_id=WILDCARD_FORWARD_ID)),
            ("unknown forward", lambda: hello_payload(FIXTURE_SECRET, self.target, forward_id=999)),
            (
                "static token",
                lambda: json.dumps(
                    {"token": STATIC_TOKEN, "target": self.target, "forward_id": STATIC_FORWARD_ID},
                    separators=(",", ":"),
                ).encode("utf-8"),
            ),
            (
                "static token mismatch",
                lambda: json.dumps(
                    {"token": "not-the-token", "target": self.target, "forward_id": STATIC_FORWARD_ID},
                    separators=(",", ":"),
                ).encode("utf-8"),
            ),
            ("bad token", lambda: hello_payload("wrong-secret", self.target)),
            ("foreign target", lambda: hello_payload(FIXTURE_SECRET, "198.51.100.7:9000")),
            ("malformed target", lambda: json.dumps({"token": "x", "target": "nope", "forward_id": FORWARD_ID}).encode()),
            ("missing token", lambda: json.dumps({"target": self.target, "forward_id": FORWARD_ID}).encode()),
            ("tiny max", lambda: hello_payload(FIXTURE_SECRET, self.target, frame_payload_max=10)),
            ("non object", lambda: b"[]"),
            ("broken json", lambda: b"token"),
        ]
        for name, factory in cases:
            payload = factory()
            go_result = await exchange_hello(go.address, SID, payload)
            reference_result = await exchange_hello(reference.address, SID, payload)
            self.assertEqual(go_result[0], reference_result[0], name + " msg_type")
            self.assertEqual(go_result[1], reference_result[1], name + " session id")
            self.assertEqual(go_result[2], reference_result[2], name + " payload")
            self.assertIn("ok", json.loads(go_result[2]), name)
    async def test_reattach_marker_matches_reference(self) -> None:
        go, reference = await self.start_pair()
        for sidecar in (go, reference):
            first = await exchange_hello(sidecar.address, SID_TWO, hello_payload(FIXTURE_SECRET, self.target))
            second = await exchange_hello(sidecar.address, SID_TWO, hello_payload(FIXTURE_SECRET, self.target))
            self.assertEqual(json.loads(first[2])["session"], "OKN")
            self.assertEqual(json.loads(second[2])["session"], "OKR")
            self.assertEqual(first[2].replace(b"OKN", b"OKR"), second[2])

    async def test_echo_and_replay_suppression_match_reference(self) -> None:
        go, reference = await self.start_pair()
        for sidecar in (go, reference):
            reader, writer = await open_carrier(sidecar.address)
            await uot.write_frame(
                writer,
                uot.Frame(uot.HELLO, SID, payload=hello_payload(FIXTURE_SECRET, self.target)),
                uot.DEFAULT_MAX_FRAME_BYTES,
            )
            ack = await asyncio.wait_for(uot.read_frame(reader, uot.DEFAULT_MAX_FRAME_BYTES), 10.0)
            self.assertTrue(json.loads(ack.payload)["ok"])
            await uot.write_frame(writer, uot.Frame(uot.DATA, SID, seq=1, payload=b"Eone"), uot.DEFAULT_MAX_FRAME_BYTES)
            await uot.write_frame(writer, uot.Frame(uot.DATA, SID, seq=1, payload=b"Ereplay"), uot.DEFAULT_MAX_FRAME_BYTES)
            await uot.write_frame(writer, uot.Frame(uot.DATA, SID, seq=2, payload=b"Etwo"), uot.DEFAULT_MAX_FRAME_BYTES)
            payloads = []
            while len(payloads) < 2:
                frame = await asyncio.wait_for(uot.read_frame(reader, uot.DEFAULT_MAX_FRAME_BYTES), 10.0)
                if frame.msg_type == uot.KEEPALIVE:
                    continue
                payloads.append(frame.payload)
            self.assertEqual(payloads, [b"one", b"two"])
            writer.close()
            try:
                await writer.wait_closed()
            except (ConnectionError, OSError):
                pass
        self.assertNotIn(b"replay", self.echo.received)
    async def test_udp_source_port_survives_reattach(self) -> None:
        go, _reference = await self.start_pair()
        reader, writer = await open_carrier(go.address)
        await uot.write_frame(
            writer,
            uot.Frame(uot.HELLO, SID, payload=hello_payload(FIXTURE_SECRET, self.target)),
            uot.DEFAULT_MAX_FRAME_BYTES,
        )
        await asyncio.wait_for(uot.read_frame(reader, uot.DEFAULT_MAX_FRAME_BYTES), 10.0)
        await uot.write_frame(writer, uot.Frame(uot.DATA, SID, seq=1, payload=b"Efirst"), uot.DEFAULT_MAX_FRAME_BYTES)
        await asyncio.wait_for(uot.read_frame(reader, uot.DEFAULT_MAX_FRAME_BYTES), 10.0)
        port_before = self.echo.peers[-1][1]
        writer.close()
        try:
            await writer.wait_closed()
        except (ConnectionError, OSError):
            pass
        reader_two, writer_two = await open_carrier(go.address)
        await uot.write_frame(
            writer_two,
            uot.Frame(uot.HELLO, SID, payload=hello_payload(FIXTURE_SECRET, self.target)),
            uot.DEFAULT_MAX_FRAME_BYTES,
        )
        ack = await asyncio.wait_for(uot.read_frame(reader_two, uot.DEFAULT_MAX_FRAME_BYTES), 10.0)
        self.assertEqual(json.loads(ack.payload)["session"], "OKR")
        await uot.write_frame(writer_two, uot.Frame(uot.DATA, SID, seq=2, payload=b"Eagain"), uot.DEFAULT_MAX_FRAME_BYTES)
        await asyncio.wait_for(uot.read_frame(reader_two, uot.DEFAULT_MAX_FRAME_BYTES), 10.0)
        self.assertEqual(self.echo.peers[-1][1], port_before)
        writer_two.close()
        try:
            await writer_two.wait_closed()
        except (ConnectionError, OSError):
            pass
    async def test_bulk_datagrams_are_delivered_in_order(self) -> None:
        go, _reference = await self.start_pair()
        reader, writer = await open_carrier(go.address)
        await uot.write_frame(
            writer,
            uot.Frame(uot.HELLO, SID, payload=hello_payload(FIXTURE_SECRET, self.target)),
            uot.DEFAULT_MAX_FRAME_BYTES,
        )
        await asyncio.wait_for(uot.read_frame(reader, uot.DEFAULT_MAX_FRAME_BYTES), 10.0)
        sizes = [1, 64, 512, 1024, uot.DEFAULT_FRAME_PAYLOAD, 200]
        expected = []
        for index in range(200):
            # NOTE: the first byte stays out of the echo harness's command
            # bytes ('E' and 'S') so every datagram is echoed back verbatim.
            body = bytes([65 + (index % 3)]) * sizes[index % len(sizes)]
            expected.append(body)
        received = []

        async def drain_replies() -> None:
            while len(received) < len(expected):
                frame = await asyncio.wait_for(uot.read_frame(reader, uot.DEFAULT_MAX_FRAME_BYTES), 20.0)
                if frame.msg_type == uot.KEEPALIVE:
                    continue
                received.append(frame.payload)

        reader_task = asyncio.create_task(drain_replies())
        try:
            for start in range(0, len(expected), 16):
                batch = expected[start:start + 16]
                for index, body in enumerate(batch, start=start):
                    await uot.write_frame(writer, uot.Frame(uot.DATA, SID, seq=index + 1, payload=body), uot.DEFAULT_MAX_FRAME_BYTES)
                target_count = start + len(batch)
                deadline = asyncio.get_running_loop().time() + 20.0
                while len(received) < target_count and asyncio.get_running_loop().time() < deadline:
                    await asyncio.sleep(0.005)
                if len(received) < target_count:
                    self.fail("carrier returned {0} of {1} datagrams before the window stalled".format(len(received), len(expected)))
            await asyncio.wait_for(asyncio.shield(reader_task), 15.0)
        finally:
            if not reader_task.done():
                reader_task.cancel()
        self.assertEqual(received, expected)
        writer.close()
        try:
            await writer.wait_closed()
        except (ConnectionError, OSError):
            pass
    async def test_rejection_log_line_is_unchanged(self) -> None:
        go, reference = await self.start_pair()
        expected = "external UoT connection rejected: authentication or target binding failed"
        await exchange_hello(go.address, SID, hello_payload(FIXTURE_SECRET, "198.51.100.7:9000"))
        await exchange_hello(reference.address, SID, hello_payload(FIXTURE_SECRET, "198.51.100.7:9000"))
        await asyncio.sleep(0.3)
        self.assertTrue(any(expected in line for line in go.log_lines), go.log_lines)
        self.assertTrue(any(expected in record.getMessage() for record in self.captured_logs), self.captured_logs)

    async def test_oversize_payloads_are_dropped_by_both(self) -> None:
        go, reference = await self.start_pair()
        limit = uot.DEFAULT_FRAME_PAYLOAD
        for sidecar in (go, reference):
            reader, writer = await open_carrier(sidecar.address)
            await uot.write_frame(
                writer,
                uot.Frame(uot.HELLO, SID, payload=hello_payload(FIXTURE_SECRET, self.target)),
                uot.DEFAULT_MAX_FRAME_BYTES,
            )
            ack = await asyncio.wait_for(uot.read_frame(reader, uot.DEFAULT_MAX_FRAME_BYTES), 10.0)
            self.assertEqual(json.loads(ack.payload)["frame_payload_max"], limit)
            await uot.write_frame(writer, uot.Frame(uot.DATA, SID, seq=1, payload=b"a" * limit), uot.DEFAULT_MAX_FRAME_BYTES)
            await uot.write_frame(writer, uot.Frame(uot.DATA, SID, seq=2, payload=b"b" * (limit + 1)), uot.DEFAULT_MAX_FRAME_BYTES)
            frame = await asyncio.wait_for(uot.read_frame(reader, uot.DEFAULT_MAX_FRAME_BYTES), 10.0)
            self.assertEqual(len(frame.payload), limit)
            with self.assertRaises(asyncio.TimeoutError):
                await asyncio.wait_for(uot.read_frame(reader, uot.DEFAULT_MAX_FRAME_BYTES), 1.5)
            writer.close()
            try:
                await writer.wait_closed()
            except (ConnectionError, OSError):
                pass
        self.assertNotIn(b"b" * 8, self.echo.received)

    async def test_idle_keepalive_and_close_match_reference(self) -> None:
        go, reference = await self.start_pair(idle_timeout_seconds=2.0, keepalive_seconds=0.2)
        for sidecar in (go, reference):
            reader, writer = await open_carrier(sidecar.address)
            await uot.write_frame(
                writer,
                uot.Frame(uot.HELLO, SID, payload=hello_payload(FIXTURE_SECRET, self.target)),
                uot.DEFAULT_MAX_FRAME_BYTES,
            )
            await asyncio.wait_for(uot.read_frame(reader, uot.DEFAULT_MAX_FRAME_BYTES), 10.0)
            frame = await asyncio.wait_for(uot.read_frame(reader, uot.DEFAULT_MAX_FRAME_BYTES), 5.0)
            self.assertEqual(frame.msg_type, uot.KEEPALIVE)
            await uot.write_frame(writer, uot.Frame(uot.CLOSE, SID, seq=1), uot.DEFAULT_MAX_FRAME_BYTES)
            with self.assertRaises((asyncio.IncompleteReadError, ConnectionError, asyncio.TimeoutError)):
                await asyncio.wait_for(uot.read_frame(reader, uot.DEFAULT_MAX_FRAME_BYTES), 3.0)
            writer.close()
            try:
                await writer.wait_closed()
            except (ConnectionError, OSError):
                pass


class CaptureHandler(logging.Handler):
    def __init__(self, sink: list) -> None:
        super().__init__()
        self.sink = sink

    def emit(self, record: logging.LogRecord) -> None:
        self.sink.append(record)


class SidecarToolingTests(unittest.TestCase):
    def run_binary(self, *args) -> subprocess.CompletedProcess:
        return subprocess.run(
            [str(binary_path()), *args], capture_output=True, text=True, timeout=60, check=False
        )

    def test_print_token_matches_reference(self) -> None:
        for target in ("203.0.113.10:5201", "127.0.0.1:9001"):
            for forward_id in (170, 181, 9):
                expected = uot.make_hmac_token(FIXTURE_SECRET, forward_id, target)
                with tempfile.TemporaryDirectory(prefix="uot-token-") as tempdir:
                    secret_path = Path(tempdir) / "secret.txt"
                    secret_path.write_text(FIXTURE_SECRET, encoding="utf-8")
                    result = self.run_binary(
                        "--print-token", "--secret-file", str(secret_path),
                        "--forward-id", str(forward_id), "--target", target,
                    )
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(result.stdout.strip(), expected)

    def test_check_config_matches_reference_loader(self) -> None:
        target = "203.0.113.10:5201"
        config = build_config(target, keepalive_seconds=12.5, session_grace_seconds=45.0)
        with tempfile.TemporaryDirectory(prefix="uot-config-") as tempdir:
            config_path = Path(tempdir) / "leikwan-external-uot.json"
            config_path.write_text(json.dumps(config), encoding="utf-8")
            result = self.run_binary("--config", str(config_path), "--check-config")
            reference = uot.load_config(config_path)
        self.assertEqual(result.returncode, 0, result.stderr)
        summary = json.loads(result.stdout)
        for key in ("listen_host", "listen_port", "max_frame_bytes", "frame_payload_max", "queue_packets",
                    "keepalive_seconds", "idle_timeout_seconds", "session_grace_seconds", "session_limit",
                    "udp_connect_timeout_seconds"):
            self.assertEqual(summary[key], getattr(reference, key), key)
        summary_forwards = {int(entry["forward_id"]): entry for entry in summary["forwards"]}
        self.assertEqual(set(summary_forwards), set(reference.forwards))
        for forward_id, binding in reference.forwards.items():
            entry = summary_forwards[forward_id]
            self.assertEqual(set(entry["targets"]), set(binding.targets), str(forward_id))
            self.assertEqual(entry["has_secret"], bool(binding.secret))
            self.assertEqual(entry["has_static_token"], bool(binding.static_token))
        self.assertNotIn(FIXTURE_SECRET, result.stdout)
        self.assertNotIn(WILDCARD_SECRET, result.stdout)


if __name__ == "__main__":
    unittest.main()
