#!/usr/bin/env python3
import argparse
import asyncio
import contextlib
import signal
import socket
from typing import Iterable, Optional, Tuple


Address = Tuple[str, int]
MAX_TARGETS = 8
DEFAULT_IDLE_TIMEOUT = 30.0


def parse_host_port(value: str) -> Address:
    raw = str(value or "").strip()
    if raw.startswith("["):
        closing = raw.find("]")
        if closing <= 1 or closing + 1 >= len(raw) or raw[closing + 1] != ":":
            raise ValueError("address must be [HOST]:PORT")
        host = raw[1:closing].strip()
        port_raw = raw[closing + 2 :]
    else:
        if ":" not in raw:
            raise ValueError("address must be HOST:PORT")
        host, port_raw = raw.rsplit(":", 1)
        host = host.strip()
    if not host or any(character.isspace() for character in host):
        raise ValueError("host is empty or malformed")
    try:
        port = int(port_raw)
    except (TypeError, ValueError) as exc:
        raise ValueError("port must be an integer") from exc
    if port < 1 or port > 65535:
        raise ValueError("port is outside 1..65535")
    return host, port


def parse_targets(value: str) -> list[Address]:
    raw_items = str(value or "").split(",")
    if not raw_items or any(not item.strip() for item in raw_items):
        raise ValueError("at least one non-empty target is required")
    parsed = [parse_host_port(item) for item in raw_items]
    unique: list[Address] = []
    seen: set[Address] = set()
    for target in parsed:
        if target in seen:
            continue
        seen.add(target)
        unique.append(target)
    if not unique:
        raise ValueError("at least one target is required")
    return unique[:MAX_TARGETS]


def clamp_repeat(value: int) -> int:
    return min(3, max(1, int(value)))


class IngressProtocol(asyncio.DatagramProtocol):
    def __init__(self, proxy: "UdpFanoutProxy") -> None:
        self.proxy = proxy

    def datagram_received(self, data: bytes, addr: Address) -> None:
        self.proxy.dispatch(data, addr)

    def error_received(self, exc: Exception) -> None:
        return


class UpstreamProtocol(asyncio.DatagramProtocol):
    def __init__(self, flow: "UdpFlow") -> None:
        self.flow = flow

    def datagram_received(self, data: bytes, addr: Address) -> None:
        self.flow.reply(data, addr)

    def error_received(self, exc: Exception) -> None:
        return


class UdpFlow:
    def __init__(self, proxy: "UdpFanoutProxy", client: Address) -> None:
        self.proxy = proxy
        self.client = client
        self.transport: Optional[asyncio.DatagramTransport] = None
        self.pinned_target: Optional[Address] = None
        self.seen_replies: set[bytes] = set()
        self.expiry_handle: Optional[asyncio.TimerHandle] = None
        self.closed = False

    def refresh_expiry(self) -> None:
        if self.expiry_handle is not None:
            self.expiry_handle.cancel()
        self.expiry_handle = asyncio.get_running_loop().call_later(
            self.proxy.idle_timeout,
            self.proxy.expire_flow,
            self.client,
            self,
        )

    def send(self, data: bytes) -> None:
        if self.closed or self.transport is None:
            return
        self.seen_replies.clear()
        self.refresh_expiry()
        for target in self.proxy.resolved_targets:
            for _ in range(self.proxy.repeat):
                self.transport.sendto(data, target)

    def reply(self, data: bytes, addr: Address) -> None:
        normalized = str(addr[0]), int(addr[1])
        if normalized not in self.proxy.target_set:
            return
        if self.pinned_target is None:
            self.pinned_target = normalized
        if normalized != self.pinned_target or data in self.seen_replies:
            return
        self.seen_replies.add(data)
        self.refresh_expiry()
        if self.proxy.ingress_transport is not None:
            self.proxy.ingress_transport.sendto(data, self.client)

    def close(self) -> None:
        if self.closed:
            return
        self.closed = True
        if self.expiry_handle is not None:
            self.expiry_handle.cancel()
            self.expiry_handle = None
        if self.transport is not None:
            self.transport.close()
            self.transport = None


class UdpFanoutProxy:
    def __init__(
        self,
        listen: Address,
        targets: Iterable[Address],
        *,
        repeat: int = 1,
        idle_timeout: float = DEFAULT_IDLE_TIMEOUT,
    ) -> None:
        target_list = list(targets)[:MAX_TARGETS]
        if not target_list:
            raise ValueError("at least one target is required")
        self.listen = listen
        self.targets = target_list
        self.repeat = clamp_repeat(repeat)
        self.idle_timeout = min(300.0, max(0.01, float(idle_timeout)))
        self.ingress_transport: Optional[asyncio.DatagramTransport] = None
        self.bound_address: Address = listen
        self.resolved_targets: list[Address] = []
        self.target_set: set[Address] = set()
        self.target_family = socket.AF_INET
        self.flows: dict[Address, UdpFlow] = {}
        self._tasks: set[asyncio.Task] = set()
        self._flow_lock = asyncio.Lock()

    async def _resolve_targets(self) -> None:
        loop = asyncio.get_running_loop()
        resolved: list[tuple[int, Address]] = []
        for host, port in self.targets:
            results = await loop.getaddrinfo(
                host,
                port,
                family=socket.AF_UNSPEC,
                type=socket.SOCK_DGRAM,
            )
            if not results:
                raise ValueError(f"target cannot be resolved: {host}:{port}")
            family, _, _, _, sockaddr = results[0]
            resolved.append((family, (str(sockaddr[0]), int(sockaddr[1]))))
        families = {family for family, _ in resolved}
        if len(families) != 1:
            raise ValueError("all UDP targets must use one address family")
        self.target_family = resolved[0][0]
        self.resolved_targets = []
        seen: set[Address] = set()
        for _, address in resolved:
            if address in seen:
                continue
            seen.add(address)
            self.resolved_targets.append(address)
        self.target_set = set(self.resolved_targets)

    async def start(self) -> None:
        if self.ingress_transport is not None:
            return
        await self._resolve_targets()
        loop = asyncio.get_running_loop()
        transport, _ = await loop.create_datagram_endpoint(
            lambda: IngressProtocol(self),
            local_addr=self.listen,
        )
        self.ingress_transport = transport
        sockname = transport.get_extra_info("sockname")
        self.bound_address = str(sockname[0]), int(sockname[1])

    async def close(self) -> None:
        for flow in list(self.flows.values()):
            flow.close()
        self.flows.clear()
        tasks = list(self._tasks)
        for task in tasks:
            task.cancel()
        if tasks:
            await asyncio.gather(*tasks, return_exceptions=True)
        self._tasks.clear()
        if self.ingress_transport is not None:
            self.ingress_transport.close()
            self.ingress_transport = None

    def dispatch(self, data: bytes, client: Address) -> None:
        task = asyncio.create_task(self._dispatch(bytes(data), client))
        self._tasks.add(task)
        task.add_done_callback(self._tasks.discard)

    async def _dispatch(self, data: bytes, client: Address) -> None:
        normalized_client = str(client[0]), int(client[1])
        async with self._flow_lock:
            flow = self.flows.get(normalized_client)
            if flow is None or flow.closed:
                flow = UdpFlow(self, normalized_client)
                local_addr: Address
                if self.target_family == socket.AF_INET6:
                    local_addr = "::", 0
                else:
                    local_addr = "0.0.0.0", 0
                transport, _ = await asyncio.get_running_loop().create_datagram_endpoint(
                    lambda: UpstreamProtocol(flow),
                    local_addr=local_addr,
                    family=self.target_family,
                )
                flow.transport = transport
                self.flows[normalized_client] = flow
            flow.send(data)

    def expire_flow(self, client: Address, expected: UdpFlow) -> None:
        current = self.flows.get(client)
        if current is not expected:
            return
        self.flows.pop(client, None)
        expected.close()


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Fan UDP datagrams out to bounded targets")
    parser.add_argument("--listen", required=True)
    parser.add_argument("--targets", required=True)
    parser.add_argument("--repeat", type=int, default=1)
    return parser


async def run(args: argparse.Namespace) -> None:
    proxy = UdpFanoutProxy(
        parse_host_port(args.listen),
        parse_targets(args.targets),
        repeat=clamp_repeat(args.repeat),
    )
    await proxy.start()
    stop = asyncio.Event()
    loop = asyncio.get_running_loop()
    for sig in (signal.SIGINT, signal.SIGTERM):
        with contextlib.suppress(NotImplementedError):
            loop.add_signal_handler(sig, stop.set)
    try:
        await stop.wait()
    finally:
        await proxy.close()


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()
    try:
        asyncio.run(run(args))
    except ValueError as exc:
        parser.error(str(exc))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
