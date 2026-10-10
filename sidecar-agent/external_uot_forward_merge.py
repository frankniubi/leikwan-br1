#!/usr/bin/env python3
"""Merge panel-rendered forward entries into a standalone external UoT sidecar.

The panel pushes the document master/cli/external-uot-standalone-sync.php
renders for this host on stdin, through an SSH key whose authorized_keys entry
forces this command. Only forwards.<id> entries are touched:

* a forward the sidecar does not know yet is added;
* a known forward whose token or targets changed is replaced;
* a forward the sidecar knows but the document omits is kept, because a
  standalone host's forward set has historically been wider than one render.

Listener and knob settings are never changed. An entry without a bound target
is refused: targets [] means allow-any to the sidecar. When something changed
the config is written atomically (0600) and the running sidecar is hot-reloaded
with SIGHUP, which keeps existing sessions. One JSON summary line goes to stdout.
"""
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile

CONFIG = Path(os.environ.get("LEIKWAN_EXTERNAL_UOT_CONFIG", "/etc/leikwan-external-uot.json"))
SERVICE = "leikwan-external-uot.service"
MAX_INPUT_BYTES = 4 * 1024 * 1024
MAX_FORWARDS = 20000
FORWARD_ID = re.compile(r"^[1-9][0-9]{0,9}$")
TARGET = re.compile(r"^(\[[0-9A-Fa-f:]+\]|[A-Za-z0-9._-]{1,253}):[0-9]{1,5}$")


def atomic_write(path, data):
    path = Path(path)
    fd, temporary = tempfile.mkstemp(prefix=path.name + ".", dir=path.parent)
    try:
        with os.fdopen(fd, "wb") as stream:
            os.fchmod(stream.fileno(), 0o600)
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def valid_entry(entry):
    if not isinstance(entry, dict):
        return False
    token = entry.get("token")
    if not isinstance(token, str) or not token or len(token) > 8192 or re.search(r"\s", token):
        return False
    targets = entry.get("targets")
    if not isinstance(targets, list) or not targets or len(targets) > 64:
        return False
    for target in targets:
        if not isinstance(target, str) or not TARGET.match(target):
            return False
        if not 1 <= int(target.rsplit(":", 1)[1]) <= 65535:
            return False
    return True


def desired_forwards(document):
    if not isinstance(document, dict) or not isinstance(document.get("forwards"), dict):
        raise ValueError("document has no forwards map")
    forwards = document["forwards"]
    if len(forwards) > MAX_FORWARDS:
        raise ValueError("too many forwards")
    result = {}
    for key, entry in forwards.items():
        if not FORWARD_ID.match(str(key)):
            raise ValueError("invalid forward id")
        if not valid_entry(entry):
            raise ValueError("invalid entry for forward " + str(key))
        result[str(key)] = {"token": entry["token"], "targets": list(entry["targets"])}
    return result


def merge(current, desired):
    """Return (merged config, added ids, updated ids). current is not mutated."""
    merged = json.loads(json.dumps(current))
    existing = merged.setdefault("forwards", {})
    if not isinstance(existing, dict):
        raise ValueError("sidecar config forwards is not a map")
    added, updated = [], []
    for key in sorted(desired, key=int):
        entry = desired[key]
        old = existing.get(key)
        if old is None:
            existing[key] = entry
            added.append(int(key))
            continue
        if not isinstance(old, dict):
            raise ValueError("sidecar config entry " + key + " is not a map")
        if old.get("token") != entry["token"] or old.get("targets") != entry["targets"]:
            replacement = dict(old)
            replacement.update(entry)
            existing[key] = replacement
            updated.append(int(key))
    return merged, added, updated


def reload_sidecar():
    subprocess.run(["systemctl", "kill", "--kill-whom=main", "--signal=HUP", SERVICE],
                   check=True, timeout=10)


def main(stdin=sys.stdin.buffer, stdout=sys.stdout, config=CONFIG, reload=reload_sidecar):
    raw = stdin.read(MAX_INPUT_BYTES + 1)
    if len(raw) > MAX_INPUT_BYTES:
        raise ValueError("input too large")
    desired = desired_forwards(json.loads(raw))
    old_bytes = Path(config).read_bytes()
    current = json.loads(old_bytes)
    merged, added, updated = merge(current, desired)
    if added or updated:
        atomic_write(str(config) + ".last-good", old_bytes)
        atomic_write(config, (json.dumps(merged, indent=2) + "\n").encode())
        reload()
    stdout.write(json.dumps({
        "ok": True,
        "changed": bool(added or updated),
        "added": added,
        "updated": updated,
        "forwards": len(merged["forwards"]),
    }) + "\n")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as exc:  # one parseable line for the panel log
        sys.stdout.write(json.dumps({"ok": False, "error": type(exc).__name__ + ": " + str(exc)}) + "\n")
        sys.exit(1)
