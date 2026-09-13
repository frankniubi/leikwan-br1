#!/usr/bin/env python3
"""Verify every runtime artifact the Linux installers select is published.

Both installers resolve their runtime keys and paths from an arch matrix and
verify each download against artifacts/versions.json, so an arch entry that
names an unpublished key - or a path that no longer matches the manifest - only
shows up as a failed install on that architecture. Catch it here instead.
"""
import json
import re
import sys
import urllib.request
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
SCRIPTS = ["install/install-client.sh", "install/install-client-agent.sh"]
PAIR = re.compile(r'^\s*[A-Z0-9_]*(?:RUNTIME_)?(KEY|PATH)="([^"]*)"')


def extract_pairs(text: str):
    """Yield (key, path) for every KEY= assignment immediately followed by its PATH=."""
    pending = {}
    pairs = []
    for raw in text.splitlines():
        match = PAIR.match(raw)
        if not match:
            continue
        kind, value = match.group(1), match.group(2)
        if kind == "KEY":
            name = raw.strip().split("=", 1)[0]
            pending[name[: -len("KEY")]] = value
        else:
            name = raw.strip().split("=", 1)[0]
            prefix = name[: -len("PATH")]
            if prefix in pending:
                pairs.append((pending.pop(prefix), value))
    return pairs


def load_manifest():
    local = REPO / "artifacts" / "versions.json"
    if local.is_file():
        return json.loads(local.read_text(encoding="utf-8"))
    url = "https://v3.leikwanhost.com/artifacts/versions.json"
    with urllib.request.urlopen(url, timeout=30) as response:
        return json.loads(response.read().decode("utf-8"))


def main() -> int:
    manifest = load_manifest()
    failures = []
    checked = 0
    for relative in SCRIPTS:
        path = REPO / relative
        text = path.read_text(encoding="utf-8")
        pairs = extract_pairs(text)
        if not pairs:
            failures.append(f"{relative}: no runtime key/path pairs found")
            continue
        print(f"--- {relative}: {len(pairs)} artifact reference(s)")
        for key, artifact_path in pairs:
            if key == "" or artifact_path == "":
                print(f"    skip  {key or artifact_path!r} (arch does not publish it)")
                continue
            checked += 1
            entry = manifest.get(key)
            if not isinstance(entry, dict):
                failures.append(f"{relative}: {key} is not published in versions.json")
                print(f"    MISS  {key}")
                continue
            sha = str(entry.get("sha256", ""))
            if not re.fullmatch(r"[a-f0-9]{64}", sha):
                failures.append(f"{relative}: {key} has no usable sha256 in versions.json")
                print(f"    NOSHA {key}")
                continue
            published = str(entry.get("path", ""))
            if published != artifact_path:
                failures.append(
                    f"{relative}: {key} path mismatch - script {artifact_path!r} vs manifest {published!r}"
                )
                print(f"    PATH  {key}")
                continue
            print(f"    ok    {key} -> {artifact_path} ({sha[:16]})")
    print()
    if failures:
        print("FAILED:")
        for failure in failures:
            print(f"  - {failure}")
        return 1
    print(f"OK: {checked} artifact reference(s) resolve to published, hash-verified paths")
    return 0


if __name__ == "__main__":
    sys.exit(main())