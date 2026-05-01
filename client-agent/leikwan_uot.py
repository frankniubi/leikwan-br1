#!/usr/bin/env python3
from __future__ import annotations

import argparse
import asyncio
import logging
import socket
import struct
from typing import Callable

FRAME_HEADER_BYTES = 4
RECONNECT_DELAY_SECONDS = 0.5


def parse_host_port(value: str) -> tuple[str, int]:
    host, separator, port_text = value.rpartition(":")
    if separator == "" or host.strip() == "" or port_text.strip() == "":
        raise argparse.ArgumentTypeError(f"invalid host:port: {value}")
    return host.strip(), int(port_text.strip())


async def udp_reader(sock: socket.socket, queue: asyncio.Queue[bytes]) -> None:
    loop = asyncio.get_running_loop()
    while True:
        payload = await loop.sock_recv(sock, 65535)
        if payload:
            await queue.put(payload)


async def tcp_writer(writer: asyncio.StreamWriter, queue: asyncio.Queue[bytes]) -> None:
    while True:
        payload = await queue.get()
        try:
            writer.write(struct.pack(">I", len(payload)))
            writer.write(payload)
            await writer.drain()
        except (ConnectionResetError, BrokenPipeError, OSError):
            return


async def tcp_reader(
    reader: asyncio.StreamReader,
    udp_sock: socket.socket | None,
    udp_target: tuple[str, int] | None,
    udp_last_peer: list[tuple[str, int] | None],
    udp_sender: Callable[[bytes, tuple[str, int]], None] | None = None,
) -> None:
    while True:
        try:
            header = await reader.readexactly(FRAME_HEADER_BYTES)
        except asyncio.IncompleteReadError:
            return
        except (ConnectionResetError, OSError):
            return
        length = struct.unpack(">I", header)[0]
        try:
            payload = await reader.readexactly(length)
        except asyncio.IncompleteReadError:
            return
        except (ConnectionResetError, OSError):
            return
        target = udp_target or udp_last_peer[0]
        if target is None:
            continue
        if udp_sender is not None:
            udp_sender(payload, target)
            continue
        if udp_sock is None:
            continue
        if udp_target is not None:
            await asyncio.to_thread(udp_sock.send, payload)
            continue
        await asyncio.to_thread(udp_sock.sendto, payload, target)


class UdpCapture(asyncio.DatagramProtocol):
    def __init__(self, queue: asyncio.Queue[bytes], last_peer: list[tuple[str, int] | None]) -> None:
        self.queue = queue
        self.last_peer = last_peer

    def datagram_received(self, data: bytes, addr: tuple[str, int]) -> None:
        self.last_peer[0] = addr
        self.queue.put_nowait(data)


async def run_stream_bridge(tasks: list[asyncio.Task[None]], remote_label: str) -> None:
    done, pending = await asyncio.wait(set(tasks), return_when=asyncio.FIRST_COMPLETED)
    session_error: BaseException | None = None

    for task in done:
        try:
            await task
        except asyncio.CancelledError:
            raise
        except (asyncio.IncompleteReadError, ConnectionResetError, BrokenPipeError, OSError) as exc:
            session_error = exc

    for task in pending:
        task.cancel()
    if pending:
        await asyncio.gather(*pending, return_exceptions=True)

    if session_error is not None:
        if isinstance(session_error, asyncio.IncompleteReadError):
            logging.info("%s closed the session", remote_label)
        else:
            logging.warning("%s session error: %s", remote_label, session_error)
    else:
        logging.info("%s session ended", remote_label)


async def run_client_session(
    queue: asyncio.Queue[bytes],
    last_peer: list[tuple[str, int] | None],
    transport: asyncio.DatagramTransport,
    remote_tcp: tuple[str, int],
) -> None:
    reader, writer = await asyncio.open_connection(*remote_tcp)
    reader_task = asyncio.create_task(
        tcp_reader(reader, None, None, last_peer, udp_sender=lambda payload, target: transport.sendto(payload, target))
    )
    writer_task = asyncio.create_task(tcp_writer(writer, queue))
    try:
        await run_stream_bridge(
            [reader_task, writer_task],
            f"uot client remote {remote_tcp[0]}:{remote_tcp[1]}",
        )
    finally:
        writer.close()
        await writer.wait_closed()


async def run_client(listen_udp: tuple[str, int], remote_tcp: tuple[str, int]) -> None:
    queue: asyncio.Queue[bytes] = asyncio.Queue()
    last_peer: list[tuple[str, int] | None] = [None]
    loop = asyncio.get_running_loop()
    transport, _protocol = await loop.create_datagram_endpoint(
        lambda: UdpCapture(queue, last_peer),
        local_addr=listen_udp,
    )
    try:
        while True:
            try:
                await run_client_session(queue, last_peer, transport, remote_tcp)
            except asyncio.CancelledError:
                raise
            except OSError as exc:
                logging.warning("uot client connect error for %s:%s: %s", remote_tcp[0], remote_tcp[1], exc)
            await asyncio.sleep(RECONNECT_DELAY_SECONDS)
    finally:
        transport.close()


async def handle_server_connection(reader: asyncio.StreamReader, writer: asyncio.StreamWriter, udp_target: tuple[str, int]) -> None:
    queue: asyncio.Queue[bytes] = asyncio.Queue()
    udp_sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    udp_sock.setblocking(False)
    await asyncio.get_running_loop().sock_connect(udp_sock, udp_target)
    try:
        await run_stream_bridge(
            [
                asyncio.create_task(udp_reader(udp_sock, queue)),
                asyncio.create_task(tcp_writer(writer, queue)),
                asyncio.create_task(tcp_reader(reader, udp_sock, udp_target, [udp_target])),
            ],
            f"uot server peer for {udp_target[0]}:{udp_target[1]}",
        )
    finally:
        udp_sock.close()
        writer.close()
        await writer.wait_closed()


async def run_server(listen_tcp: tuple[str, int], target_udp: tuple[str, int]) -> None:
    async def handler(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
        await handle_server_connection(reader, writer, target_udp)

    server = await asyncio.start_server(handler, host=listen_tcp[0], port=listen_tcp[1])
    async with server:
        await server.serve_forever()


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Leikwan UoT bridge")
    parser.add_argument("mode", choices=["client", "server"])
    parser.add_argument("--listen-udp", type=parse_host_port)
    parser.add_argument("--listen-tcp", type=parse_host_port)
    parser.add_argument("--target-udp", type=parse_host_port)
    parser.add_argument("--remote-tcp", type=parse_host_port)
    parser.add_argument("--log-level", default="INFO")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    logging.basicConfig(
        level=getattr(logging, str(args.log_level).upper(), logging.INFO),
        format="%(asctime)s %(levelname)s %(message)s",
    )
    if args.mode == "client":
        if args.listen_udp is None or args.remote_tcp is None:
            raise SystemExit("client mode requires --listen-udp and --remote-tcp")
        asyncio.run(run_client(args.listen_udp, args.remote_tcp))
        return 0
    if args.listen_tcp is None or args.target_udp is None:
        raise SystemExit("server mode requires --listen-tcp and --target-udp")
    asyncio.run(run_server(args.listen_tcp, args.target_udp))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
