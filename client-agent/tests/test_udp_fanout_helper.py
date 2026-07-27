import asyncio
import importlib.util
import pathlib
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


udp_fanout = load_module("leikwan_udp_fanout_under_test", "client-agent/leikwan_udp_fanout.py")


class QueueProtocol(asyncio.DatagramProtocol):
    def __init__(self) -> None:
        self.queue: asyncio.Queue[tuple[bytes, tuple[str, int]]] = asyncio.Queue()

    def datagram_received(self, data: bytes, addr: tuple[str, int]) -> None:
        self.queue.put_nowait((data, addr))


class ReplyProtocol(asyncio.DatagramProtocol):
    def __init__(self, prefix: bytes = b"", delay: float = 0.0) -> None:
        self.prefix = prefix
        self.delay = delay
        self.received: list[bytes] = []
        self.transport: asyncio.DatagramTransport | None = None

    def connection_made(self, transport) -> None:
        self.transport = transport

    def datagram_received(self, data: bytes, addr: tuple[str, int]) -> None:
        self.received.append(data)
        assert self.transport is not None
        payload = self.prefix + data
        if self.delay:
            asyncio.get_running_loop().call_later(
                self.delay, self.transport.sendto, payload, addr
            )
        else:
            self.transport.sendto(payload, addr)


async def open_responder(prefix: bytes = b"", delay: float = 0.0):
    loop = asyncio.get_running_loop()
    transport, protocol = await loop.create_datagram_endpoint(
        lambda: ReplyProtocol(prefix, delay),
        local_addr=("127.0.0.1", 0),
    )
    return transport, protocol, transport.get_extra_info("sockname")


async def open_client():
    loop = asyncio.get_running_loop()
    return await loop.create_datagram_endpoint(
        QueueProtocol,
        local_addr=("127.0.0.1", 0),
    )


class UdpFanoutHelperTests(unittest.IsolatedAsyncioTestCase):
    async def test_fanout_repeat_is_bounded_and_equivalent_replies_are_deduplicated(self) -> None:
        first_transport, first, first_addr = await open_responder()
        second_transport, second, second_addr = await open_responder()
        proxy = udp_fanout.UdpFanoutProxy(
            ("127.0.0.1", 0),
            [first_addr, second_addr],
            repeat=2,
            idle_timeout=1.0,
        )
        await proxy.start()
        client_transport, client = await open_client()
        try:
            client_transport.sendto(b"payload", proxy.bound_address)
            reply, _ = await asyncio.wait_for(client.queue.get(), 1.0)
            self.assertEqual(b"payload", reply)
            with self.assertRaises(asyncio.TimeoutError):
                await asyncio.wait_for(client.queue.get(), 0.15)
            self.assertEqual([b"payload", b"payload"], first.received)
            self.assertEqual([b"payload", b"payload"], second.received)
        finally:
            client_transport.close()
            await proxy.close()
            first_transport.close()
            second_transport.close()

    async def test_first_reply_pins_the_response_source_for_the_flow(self) -> None:
        slow_transport, _, slow_addr = await open_responder(b"slow:", delay=0.08)
        fast_transport, _, fast_addr = await open_responder(b"fast:", delay=0.01)
        proxy = udp_fanout.UdpFanoutProxy(
            ("127.0.0.1", 0),
            [slow_addr, fast_addr],
            repeat=1,
            idle_timeout=1.0,
        )
        await proxy.start()
        client_transport, client = await open_client()
        try:
            client_transport.sendto(b"one", proxy.bound_address)
            first_reply, _ = await asyncio.wait_for(client.queue.get(), 1.0)
            self.assertEqual(b"fast:one", first_reply)
            await asyncio.sleep(0.1)
            self.assertTrue(client.queue.empty())

            client_transport.sendto(b"two", proxy.bound_address)
            second_reply, _ = await asyncio.wait_for(client.queue.get(), 1.0)
            self.assertEqual(b"fast:two", second_reply)
            await asyncio.sleep(0.1)
            self.assertTrue(client.queue.empty())
        finally:
            client_transport.close()
            await proxy.close()
            slow_transport.close()
            fast_transport.close()

    async def test_idle_flow_expires(self) -> None:
        responder_transport, _, responder_addr = await open_responder()
        proxy = udp_fanout.UdpFanoutProxy(
            ("127.0.0.1", 0),
            [responder_addr],
            repeat=1,
            idle_timeout=0.05,
        )
        await proxy.start()
        client_transport, client = await open_client()
        try:
            client_transport.sendto(b"one", proxy.bound_address)
            await asyncio.wait_for(client.queue.get(), 1.0)
            self.assertEqual(1, len(proxy.flows))
            await asyncio.sleep(0.12)
            self.assertEqual(0, len(proxy.flows))
        finally:
            client_transport.close()
            await proxy.close()
            responder_transport.close()

    def test_targets_and_repeat_are_bounded_and_empty_targets_fail_closed(self) -> None:
        with self.assertRaises(ValueError):
            udp_fanout.parse_targets("")
        parsed = udp_fanout.parse_targets(
            ",".join(f"127.0.0.1:{21000 + index}" for index in range(10))
        )
        self.assertEqual(8, len(parsed))
        self.assertEqual(1, udp_fanout.clamp_repeat(-10))
        self.assertEqual(2, udp_fanout.clamp_repeat(2))
        self.assertEqual(3, udp_fanout.clamp_repeat(99))


if __name__ == "__main__":
    unittest.main()
