#!/usr/bin/env python3
import argparse
import hashlib
import json
from pathlib import Path


REQUIRED_FILES = (
    "LeikwanBridge.Mac.dll",
    "LeikwanBridge.Core.dll",
    "LeikwanBridge.Mac.deps.json",
    "LeikwanBridge.Mac.runtimeconfig.json",
)


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def verify(publish: Path, receipt_path: Path, rid: str, version: str) -> None:
    receipt = json.loads(receipt_path.read_text(encoding="utf-8-sig"))
    if receipt.get("schema") != 1:
        raise ValueError("unsupported receipt schema")
    if receipt.get("rid") != rid:
        raise ValueError("receipt RID mismatch")
    if receipt.get("version") != version:
        raise ValueError("receipt version mismatch")
    if receipt.get("probe_exit_code") != 2:
        raise ValueError("managed probe exit must be 2")
    expected = receipt.get("files")
    if not isinstance(expected, dict) or set(expected) != set(REQUIRED_FILES):
        raise ValueError("receipt file set mismatch")
    for name in REQUIRED_FILES:
        path = publish / name
        if not path.is_file():
            raise ValueError(f"managed file missing: {name}")
        if sha256(path) != str(expected[name]).lower():
            raise ValueError(f"managed file hash mismatch: {name}")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("publish", type=Path)
    parser.add_argument("receipt", type=Path)
    parser.add_argument("--rid", required=True)
    parser.add_argument("--version", required=True)
    args = parser.parse_args()
    try:
        verify(args.publish.resolve(), args.receipt.resolve(), args.rid, args.version)
    except (OSError, ValueError, KeyError, TypeError, json.JSONDecodeError) as error:
        parser.error(str(error))
    print("managed load receipt verified")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
