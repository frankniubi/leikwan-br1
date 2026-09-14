#!/usr/bin/env python3
"""Keep the source-host operator note byte-accurate.

docs/handoffs/2026-09-14-ultra-review-probe-operator-note.md embeds the probe and
the agent byte-for-byte and quotes their digests, because the operator has no
other way to obtain the files and no way to trust a truncated paste. Every one of
those bytes is load-bearing: the note's own drift gate and its one-command
verification compare the installed files against the digests printed in it, so a
stale digest does not ship a broken probe - it blocks the deploy.

Run this after any change to either file:

    python3 scripts/refresh-ultra-probe-operator-note.py

It rewrites the note in place and is idempotent: with the sources unchanged it
produces identical bytes. It exits non-zero if it cannot find every place the
digests and bodies live, rather than leaving the note half-updated.
"""
from __future__ import annotations

import hashlib
import pathlib
import re
import sys

NOTE = pathlib.Path(__file__).resolve().parents[1] / "docs" / "handoffs" / "2026-09-14-ultra-review-probe-operator-note.md"
# (repository path, name of the file on the host, path on the host)
SOURCES = (
    ("source-agent/ultra_line_review.py", "ultra_line_review.py", "/opt/ultra-source/runtime/ultra_line_review.py"),
    ("source-agent/ultra_source_agent.py", "ultra-source-agent.py", "/usr/local/bin/ultra-source-agent.py"),
)
TABLE_ROW = {"source-agent/ultra_line_review.py": "| new probe (", "source-agent/ultra_source_agent.py": "| new agent ("}
VERIFY_KEY = {"source-agent/ultra_line_review.py": "want_probe=", "source-agent/ultra_source_agent.py": "want_agent="}


def digests(data: bytes) -> tuple:
    return hashlib.sha256(data).hexdigest(), hashlib.md5(data).hexdigest()


def main() -> int:
    text = NOTE.read_text(encoding="utf-8")
    lines = text.split("\n")
    written = {}
    for repo_path, host_name, _host_path in SOURCES:
        source = pathlib.Path(__file__).resolve().parents[1] / repo_path
        data = source.read_bytes()
        if b"\r" in data:
            print(f"{repo_path}: refusing to embed a file with CRLF line endings", file=sys.stderr)
            return 2
        sha256, md5 = digests(data)
        written[repo_path] = (sha256, md5, len(data))

        start = next((i for i, line in enumerate(lines) if line.startswith("sudo tee ") and host_name in line), None)
        if start is None:
            print(f"{repo_path}: no install heredoc for {host_name} in the note", file=sys.stderr)
            return 2
        end = next((i for i in range(start + 1, len(lines)) if lines[i] == "ULTRA_EOF"), None)
        if end is None:
            print(f"{repo_path}: unterminated heredoc for {host_name}", file=sys.stderr)
            return 2
        body = data.decode("utf-8").rstrip("\n").split("\n")
        lines[start + 1:end] = body

    text = "\n".join(lines)
    for repo_path, _host_name, host_path in SOURCES:
        sha256, md5, size = written[repo_path]
        row_prefix = TABLE_ROW[repo_path]
        row = f"{row_prefix}{host_path}) | {sha256} | {md5} | {size} |"
        text, rows = re.subn(r"^" + re.escape(row_prefix) + r".*$", row.replace("\\", "\\\\"), text, count=1, flags=re.M)
        if rows != 1:
            print(f"{repo_path}: the digest table row was not found or not unique", file=sys.stderr)
            return 2
        key = VERIFY_KEY[repo_path]
        text, keys = re.subn(r"^" + key + r"[0-9a-f]{64}$", key + sha256, text, count=1, flags=re.M)
        if keys != 1:
            print(f"{repo_path}: the verification digest line was not found or not unique", file=sys.stderr)
            return 2
    # The drift gate carries the NEW probe md5 next to the documented baseline.
    text, gates = re.subn(r"NEW=[0-9a-f]{32}", "NEW=" + written["source-agent/ultra_line_review.py"][1], text, count=1)
    if gates != 1:
        print("the drift gate's NEW=<md5> was not found or not unique", file=sys.stderr)
        return 2
    if re.search(r"@@[A-Z_]+@@", text):
        print("the note still carries a placeholder", file=sys.stderr)
        return 2
    NOTE.write_text(text, encoding="utf-8", newline="\n")
    for repo_path, (sha256, md5, size) in written.items():
        print(f"{repo_path}: sha256={sha256} md5={md5} bytes={size}")
    print(f"note updated: {NOTE}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
