#!/usr/bin/env python3
import argparse
import asyncio
import contextlib
import signal
from typing import Iterable, Optional, Tuple


Address = Tuple[str, int]
MAX_TARGETS = 8
DEFAULT_CONNECT_TIMEOUT = 1.5
DEFAULT_QUARANTINE_SECONDS = 5.0


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


class TargetState:
    def __init__(self, address: Address) -> None:
        self.address = address
        self.attempts = 0
        self.consecutive_failures = 0
        self.quarantine_until = 0.0


class TcpRaceProxy:
    def __init__(
        self,
        listen: Address,
        targets: Iterable[Address],
        *,
        connect_timeout: float = DEFAULT_CONNECT_TIMEOUT,
        quarantine_seconds: float = DEFAULT_QUARANTINE_SECONDS,
    ) -> None:
        target_list = list(targets)[:MAX_TARGETS]
        if not target_list:
            raise ValueError("at least one target is required")
        self.listen = listen
        self.targets = [TargetState(target) for target in target_list]
        self.connect_timeout = min(5.0, max(0.05, float(connect_timeout)))
        self.quarantine_seconds = min(60.0, max(0.1, float(quarantine_seconds)))
        self.server: Optional[asyncio.AbstractServer] = None
        self.bound_address: Address = listen
        self._client_writers: set[asyncio.StreamWriter] = set()

    async def start(self) -> None:
        if self.server is not None:
            return
        self.server = await asyncio.start_server(
            self._handle_client,
            host=self.listen[0],
            port=self.listen[1],
        )
        socket = self.server.sockets[0]
        sockname = socket.getsockname()
        self.bound_address = str(sockname[0]), int(sockname[1])

    async def close(self) -> None:
        if self.server is not None:
            self.server.close()
            await self.server.wait_closed()
            self.server = None
        writers = list(self._client_writers)
        for writer in writers:
            writer.close()
        if writers:
            await asyncio.gather(
                *(writer.wait_closed() for writer in writers),
                return_exceptions=True,
            )
        self._client_writers.clear()

    async def serve_forever(self) -> None:
        if self.server is None:
            await self.start()
        assert self.server is not None
        await self.server.serve_forever()

    async def _connect_target(
        self, state: TargetState
    ) -> tuple[asyncio.StreamReader, asyncio.StreamWriter]:
        state.attempts += 1
        try:
            connection = await asyncio.wait_for(
                asyncio.open_connection(*state.address),
                timeout=self.connect_timeout,
            )
        except (OSError, asyncio.TimeoutError):
            state.consecutive_failures += 1
            state.quarantine_until = (
                asyncio.get_running_loop().time() + self.quarantine_seconds
            )
            raise
        state.consecutive_failures = 0
        state.quarantine_until = 0.0
        return connection

    async def connect_winner(
        self,
    ) -> tuple[asyncio.StreamReader, asyncio.StreamWriter, TargetState]:
        now = asyncio.get_running_loop().time()
        available = [state for state in self.targets if state.quarantine_until <= now]
        if not available:
            raise ConnectionError("all targets are quarantined")

        tasks = {
            asyncio.create_task(self._connect_target(state)): state
            for state in available
        }
        pending = set(tasks)
        errors: list[BaseException] = []
        try:
            while pending:
                done, pending = await asyncio.wait(
                    pending, return_when=asyncio.FIRST_COMPLETED
                )
                winner = None
                for task in done:
                    try:
                        reader, writer = task.result()
                    except (OSError, asyncio.TimeoutError) as exc:
                        errors.append(exc)
                        continue
                    if winner is None:
                        winner = (reader, writer, tasks[task])
                    else:
                        writer.close()
                        await writer.wait_closed()
                if winner is not None:
                    for task in pending:
                        task.cancel()
                    outcomes = await asyncio.gather(*pending, return_exceptions=True)
                    for outcome in outcomes:
                        if isinstance(outcome, tuple) and len(outcome) == 2:
                            loser_writer = outcome[1]
                            loser_writer.close()
                            await loser_writer.wait_closed()
                    return winner
            raise ConnectionError("no target accepted the connection") from (
                errors[-1] if errors else None
            )
        finally:
            for task in tasks:
                if not task.done():
                    task.cancel()

    @staticmethod
    async def _copy(
        reader: asyncio.StreamReader, writer: asyncio.StreamWriter
    ) -> None:
        try:
            while True:
                data = await reader.read(65536)
                if not data:
                    with contextlib.suppress(OSError, RuntimeError):
                        writer.write_eof()
                    return
                writer.write(data)
                await writer.drain()
        except (ConnectionError, OSError, asyncio.CancelledError):
            return

    async def _handle_client(
        self, client_reader: asyncio.StreamReader, client_writer: asyncio.StreamWriter
    ) -> None:
        self._client_writers.add(client_writer)
        upstream_writer: Optional[asyncio.StreamWriter] = None
        try:
            try:
                upstream_reader, upstream_writer, _ = await self.connect_winner()
            except ConnectionError:
                return
            self._client_writers.add(upstream_writer)
            await asyncio.gather(
                self._copy(client_reader, upstream_writer),
                self._copy(upstream_reader, client_writer),
            )
        finally:
            client_writer.close()
            self._client_writers.discard(client_writer)
            if upstream_writer is not None:
                upstream_writer.close()
                self._client_writers.discard(upstream_writer)
            await asyncio.gather(
                client_writer.wait_closed(),
                *(
                    [upstream_writer.wait_closed()]
                    if upstream_writer is not None
                    else []
                ),
                return_exceptions=True,
            )


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="Race TCP connections to bounded targets")
    parser.add_argument("--listen", required=True)
    parser.add_argument("--targets", required=True)
    return parser


async def run(args: argparse.Namespace) -> None:
    proxy = TcpRaceProxy(parse_host_port(args.listen), parse_targets(args.targets))
    await proxy.start()
    stop = asyncio.Event()
    loop = asyncio.get_running_loop()
    for sig in (signal.SIGINT, signal.SIGTERM):
        with contextlib.suppress(NotImplementedError):
            loop.add_signal_handler(sig, stop.set)
    serve_task = asyncio.create_task(proxy.serve_forever())
    try:
        await stop.wait()
    finally:
        serve_task.cancel()
        await asyncio.gather(serve_task, return_exceptions=True)
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
