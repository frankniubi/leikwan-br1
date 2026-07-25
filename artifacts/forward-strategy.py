#!/usr/bin/env python3
import argparse
import json
import os
import pathlib
import tempfile


VALID_STRATEGIES = ("speed_max", "latency_best", "random")
DEFAULT_STRATEGY = "latency_best"


class ForwardStrategyStore:
    def __init__(self, state_dir: pathlib.Path, panel_id: str = "", client_id: int = 0):
        self.state_dir = pathlib.Path(state_dir)
        self.panel_id = str(panel_id)
        self.client_id = int(client_id)
        self.strategy_dir = self.state_dir / "forward-strategies"

    def runtime_path(self, forward_id: int) -> pathlib.Path:
        normalized_id = int(forward_id)
        if normalized_id <= 0:
            raise ValueError("forward_id must be positive")
        return self.strategy_dir / f"{normalized_id}.strategy"

    def get(self, forward_id: int) -> str:
        try:
            value = self.runtime_path(forward_id).read_text(encoding="utf-8").strip().lower()
        except OSError:
            return DEFAULT_STRATEGY
        return value if value in VALID_STRATEGIES else DEFAULT_STRATEGY

    def set(self, forward_id: int, strategy: str) -> str:
        normalized = str(strategy).strip().lower()
        if normalized not in VALID_STRATEGIES:
            raise ValueError(f"unsupported strategy: {strategy}")

        destination = self.runtime_path(forward_id)
        destination.parent.mkdir(parents=True, exist_ok=True)
        fd, temporary_name = tempfile.mkstemp(
            prefix=f".{destination.name}.",
            dir=destination.parent,
            text=True,
        )
        try:
            with os.fdopen(fd, "w", encoding="utf-8", newline="\n") as handle:
                handle.write(normalized + "\n")
                handle.flush()
                os.fsync(handle.fileno())
            os.replace(temporary_name, destination)
        finally:
            try:
                os.unlink(temporary_name)
            except FileNotFoundError:
                pass
        return normalized

    def list(self) -> dict[int, str]:
        if not self.strategy_dir.is_dir():
            return {}
        result = {}
        for path in sorted(self.strategy_dir.glob("*.strategy")):
            try:
                forward_id = int(path.stem)
            except ValueError:
                continue
            if forward_id > 0:
                result[forward_id] = self.get(forward_id)
        return result


def main() -> int:
    parser = argparse.ArgumentParser(prog="leikwan-client-strategy")
    parser.add_argument("--state-dir", default="/var/lib/leikwan-client-agent")
    subparsers = parser.add_subparsers(dest="command", required=True)

    get_parser = subparsers.add_parser("get")
    get_parser.add_argument("--forward-id", type=int, required=True)
    set_parser = subparsers.add_parser("set")
    set_parser.add_argument("--forward-id", type=int, required=True)
    set_parser.add_argument("--strategy", choices=VALID_STRATEGIES, required=True)
    subparsers.add_parser("list")

    args = parser.parse_args()
    store = ForwardStrategyStore(pathlib.Path(args.state_dir))
    if args.command == "get":
        print(store.get(args.forward_id))
    elif args.command == "set":
        print(store.set(args.forward_id, args.strategy))
    else:
        print(json.dumps(store.list(), sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
