#!/usr/bin/env python3
"""One runtime source identity across desktop, mobile and router builds.

No git-history dependency: sanitized CI snapshots and local release worktrees
with identical sources produce the same fingerprint. Does not publish anything.
"""
import argparse
import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def identity(root=ROOT):
    release = json.loads((root / "artifacts/runtime-release.json").read_text())
    files = [p for base in ("tools/ultra-client", "opengfw/analyzer")
             for p in (root / base).rglob("*")
             if p.is_file() and (p.suffix == ".go" and not p.name.endswith("_test.go")
                                or p.name in ("go.mod", "go.sum"))]
    digest = hashlib.sha256()
    for path in sorted(files, key=lambda p: p.relative_to(root).as_posix()):
        name = path.relative_to(root).as_posix().encode()
        body = path.read_bytes().replace(b"\r\n", b"\n")
        digest.update(len(name).to_bytes(4, "big") + name)
        digest.update(len(body).to_bytes(8, "big") + body)
    sha = digest.hexdigest()
    return {"version": release["version"], "source_sha256": sha,
            "fingerprint": release["version"] + "." + sha[:12]}

if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--field", choices=("version", "source_sha256", "fingerprint"))
    args = parser.parse_args()
    result = identity()
    print(result[args.field] if args.field else json.dumps(result, sort_keys=True))
