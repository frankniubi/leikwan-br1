from __future__ import annotations

import asyncio
from dataclasses import replace
import json
import os
from pathlib import Path
import sys
import unittest


ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "sidecar-agent"))

import leikwan_external_uot as uot


class EchoProtocol(asyncio.DatagramProtocol):
    def __init__(self) -> None:
        self.transport: asyncio.DatagramTransport | None = None
        self.peers: list[tuple[str, int]] = []

    def connection_made(self, transport: asyncio.BaseTransport) -> None:
        self.transport = transport  # type: ignore[assignment]

    def datagram_received(self, data: bytes, addr: tuple[str, int]) -> None:
        self.peers.append(addr)
        assert self.transport is not None
        self.transport.sendto(data, addr)


class ExternalUoTTests(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self) -> None:
        loop = asyncio.get_running_loop()
        self.echo_protocol = EchoProtocol()
        self.echo_transport, _ = await loop.create_datagram_endpoint(
            lambda: self.echo_protocol,
            local_addr=("127.0.0.1", 0),
        )
        echo_host, echo_port = self.echo_transport.get_extra_info("sockname")[:2]
        self.target = f"{echo_host}:{echo_port}"
        self.binding = uot.ForwardBinding(
            forward_id=17,
            secret=b"test-secret",
            targets=frozenset({self.target}),
        )
        self.config = uot.SidecarConfig(
            listen_host="127.0.0.1",
            listen_port=0,
            forwards={17: self.binding},
            max_frame_bytes=uot.HEADER_BYTES + uot.MAX_DATAGRAM,
            frame_payload_max=uot.MAX_DATAGRAM,
            queue_packets=8,
            keepalive_seconds=0.05,
            idle_timeout_seconds=1.0,
            session_grace_seconds=0.4,
            session_limit=4,
        )
        self.server = uot.ExternalUoTServer(self.config)
        await self.server.start()

    async def asyncTearDown(self) -> None:
        await self.server.close()
        self.echo_transport.close()
        await asyncio.sleep(0)

    async def connect(self, session_id: bytes, target: str | None = None, token: str | None = None, frame_max: int = 4096) -> tuple[asyncio.StreamReader, asyncio.StreamWriter, uot.Frame, dict[str, object]]:
        reader, writer = await asyncio.open_connection(*self.server.address)
        actual_target = target or self.target
        actual_token = token or uot.make_hmac_token(self.binding.secret, self.binding.forward_id, actual_target)
        hello = uot.Frame(
            uot.HELLO,
            session_id,
            payload=json.dumps(
                {
                    "token": actual_token,
                    "forward_id": self.binding.forward_id,
                    "target": actual_target,
                    "frame_payload_max": frame_max,
                },
                separators=(",", ":"),
            ).encode(),
        )
        await uot.write_frame(writer, hello, self.config.max_frame_bytes)
        ack = await uot.read_frame(reader, self.config.max_frame_bytes)
        payload = json.loads(ack.payload.decode())
        return reader, writer, ack, payload

    async def next_udp_payload(self, reader: asyncio.StreamReader, expected: bytes) -> uot.Frame:
        for _ in range(5):
            frame = await asyncio.wait_for(uot.read_frame(reader, self.config.max_frame_bytes), 1.0)
            if frame.msg_type == uot.UDP_DATA:
                if frame.payload == expected:
                    return frame
            elif frame.msg_type == uot.KEEPALIVE:
                continue
        self.fail(f"did not receive UDP payload {expected!r}")

    async def test_async_bidirectional_relay_and_frame_boundaries(self) -> None:
        sid = os.urandom(uot.SESSION_ID_BYTES)
        reader, writer, ack, ack_payload = await self.connect(sid, frame_max=4096)
        self.assertEqual(ack.msg_type, uot.HELLO_ACK)
        self.assertTrue(ack_payload["ok"])
        self.assertEqual(ack_payload["session"], "OKN")
        self.assertEqual(ack_payload["frame_payload_max"], 4096)

        first = b"first" + os.urandom(1400)
        second = b"second" + os.urandom(4000)
        await uot.write_frame(writer, uot.Frame(uot.DATA, sid, seq=1, payload=first), self.config.max_frame_bytes)
        await uot.write_frame(writer, uot.Frame(uot.DATA, sid, seq=2, payload=second), self.config.max_frame_bytes)
        self.assertEqual((await self.next_udp_payload(reader, first)).payload, first)
        self.assertEqual((await self.next_udp_payload(reader, second)).payload, second)
        self.assertEqual(len(self.echo_protocol.peers), 2)

        writer.close()
        await writer.wait_closed()

    async def test_reattach_reuses_target_visible_udp_source_port(self) -> None:
        sid = b"reattach-session"  # exactly 16 bytes
        reader, writer, _ack, payload = await self.connect(sid)
        self.assertEqual(payload["session"], "OKN")
        probe = b"before-reattach"
        await uot.write_frame(writer, uot.Frame(uot.DATA, sid, seq=1, payload=probe), self.config.max_frame_bytes)
        await self.next_udp_payload(reader, probe)
        first_port = self.echo_protocol.peers[-1][1]
        writer.close()
        await writer.wait_closed()
        await asyncio.sleep(0.08)

        reader2, writer2, _ack2, payload2 = await self.connect(sid)
        self.assertEqual(payload2["session"], "OKR")
        probe2 = b"after-reattach"
        await uot.write_frame(writer2, uot.Frame(uot.DATA, sid, seq=2, payload=probe2), self.config.max_frame_bytes)
        await self.next_udp_payload(reader2, probe2)
        self.assertEqual(self.echo_protocol.peers[-1][1], first_port)
        writer2.close()
        await writer2.wait_closed()

    async def test_bad_auth_and_target_binding_are_rejected(self) -> None:
        sid = os.urandom(uot.SESSION_ID_BYTES)
        reader, writer, ack, payload = await self.connect(sid, token="wrong-token")
        self.assertEqual(ack.msg_type, uot.HELLO_ACK)
        self.assertFalse(payload["ok"])
        writer.close()
        await writer.wait_closed()

        rejected_target = "127.0.0.1:9"
        reader2, writer2, ack2, payload2 = await self.connect(sid, target=rejected_target, token=uot.make_hmac_token(self.binding.secret, self.binding.forward_id, rejected_target))
        self.assertEqual(ack2.msg_type, uot.HELLO_ACK)
        self.assertFalse(payload2["ok"])
        writer2.close()
        await writer2.wait_closed()
        self.assertEqual(self.echo_protocol.peers, [])

    async def test_open_target_policy_accepts_any_normalized_target(self) -> None:
        open_binding = uot.ForwardBinding(forward_id=17, secret=b"test-secret", targets=frozenset())
        self.assertTrue(open_binding.allows_target(self.target))
        self.assertTrue(open_binding.allows_target("127.0.0.1:9"))
        self.assertFalse(open_binding.allows_target("127.0.0.1"))
        self.assertFalse(open_binding.allows_target(""))

        wildcard_binding = uot.ForwardBinding(forward_id=17, secret=b"test-secret", targets=frozenset({"*"}))
        self.assertTrue(wildcard_binding.allows_target("127.0.0.1:9"))
        self.assertFalse(wildcard_binding.allows_target("missing-port"))
        self.assertEqual(uot._parse_targets(["*"]), frozenset({"*"}))
        self.assertEqual(uot._parse_targets(["127.0.0.1:9"]), frozenset({"127.0.0.1:9"}))

        self.assertTrue(self.binding.allows_target(self.target))
        self.assertFalse(self.binding.allows_target("127.0.0.1:9"))

    async def test_open_target_policy_relays_to_unlisted_target(self) -> None:
        open_binding = uot.ForwardBinding(forward_id=17, secret=b"test-secret", targets=frozenset())
        config = replace(self.config, forwards={17: open_binding})
        server = uot.ExternalUoTServer(config)
        await server.start()
        try:
            sid = os.urandom(uot.SESSION_ID_BYTES)
            reader, writer = await asyncio.open_connection(*server.address)
            hello = uot.Frame(
                uot.HELLO,
                sid,
                payload=json.dumps(
                    {
                        "token": uot.make_hmac_token(open_binding.secret, open_binding.forward_id, self.target),
                        "forward_id": open_binding.forward_id,
                        "target": self.target,
                        "frame_payload_max": 4096,
                    },
                    separators=(",", ":"),
                ).encode(),
            )
            await uot.write_frame(writer, hello, config.max_frame_bytes)
            ack = await uot.read_frame(reader, config.max_frame_bytes)
            payload = json.loads(ack.payload.decode())
            self.assertEqual(ack.msg_type, uot.HELLO_ACK)
            self.assertTrue(payload["ok"])

            probe = b"open-policy" + os.urandom(64)
            await uot.write_frame(writer, uot.Frame(uot.DATA, sid, seq=1, payload=probe), config.max_frame_bytes)
            echoed = await asyncio.wait_for(uot.read_frame(reader, config.max_frame_bytes), 1.0)
            self.assertEqual(echoed.msg_type, uot.UDP_DATA)
            self.assertEqual(echoed.payload, probe)
            self.assertEqual(len(self.echo_protocol.peers), 1)

            writer.close()
            await writer.wait_closed()
        finally:
            await server.close()

    def test_hmac_binding_and_tuner_are_bounded(self) -> None:
        self.assertTrue(self.binding.verify(uot.make_hmac_token(b"test-secret", 17, self.target), self.target))
        self.assertFalse(self.binding.verify(uot.make_hmac_token(b"test-secret", 17, "127.0.0.1:9"), self.target))
        tuner = uot.AdaptiveTuner(frame_payload_max=4096, queue_packets=64, max_frame_payload=16000)
        tuner.observe_pressure()
        self.assertEqual(tuner.frame_payload_max, 2048)
        self.assertGreaterEqual(tuner.queue_packets, 128)
        for _ in range(8):
            tuner.observe_rtt(10)
        self.assertGreater( tuner.frame_payload_max, 2048)
        self.assertLessEqual(tuner.frame_payload_max, 16000)
        self.assertLessEqual(tuner.queue_packets, tuner.max_queue_packets)

    def test_frame_header_is_36_bytes_and_round_trips(self) -> None:
        sid = b"0123456789abcdef"
        frame = uot.Frame(uot.DATA, sid, seq=9, flags=3, payload=b"payload")
        body = frame.body()
        self.assertEqual(uot.HEADER_BYTES, 36)
        self.assertEqual(len(body), 36 + len(frame.payload))
        self.assertEqual(uot.Frame.from_body(body), frame)


if __name__ == "__main__":
    unittest.main()
