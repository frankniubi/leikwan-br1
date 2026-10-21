#!/usr/bin/env python3
"""Merge panel-rendered forward entries into a standalone external UoT sidecar.

The panel pushes the document master/cli/external-uot-standalone-sync.php
renders for this host on stdin, through an SSH key whose authorized_keys entry
forces this command. Only forwards.<id> entries are touched:

* a forward the sidecar does not know yet is added;
* a known forward whose secret, token, targets or rate limit changed is
  replaced by the rendered entry, so a withdrawn static token or rate limit
  does not linger;
* a forward listed in the document's "remove" array is deleted, which revokes
  it (the sidecar closes its live sessions on reload); unknown ids are ignored;
* a forward the sidecar knows but the document neither renders nor removes is
  kept, because a standalone host's forward set has historically been wider
  than one render.

An entry needs an HMAC "secret" and/or a static "token" plus a non-empty
"targets" list, and may carry "rate_limit_mbps" (0 or absent = unlimited).
Only those four fields are stored. An entry without a bound target is refused:
targets [] means allow-any to the sidecar. An id that is both rendered and
removed is refused. Listener and knob settings are never changed. When
something changed the config is written atomically (0600) and the running
sidecar is hot-reloaded with SIGHUP. One JSON summary line goes to stdout.

When the forced command is invoked as "usage" (SSH_ORIGINAL_COMMAND=usage) the
sidecar's per-forward UDP usage file is printed verbatim instead, or "{}" when
it is missing or not a JSON object. That mode never reads stdin or reloads.
"""
import json
import math
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile

CONFIG = Path(os.environ.get("LEIKWAN_EXTERNAL_UOT_CONFIG", "/etc/leikwan-external-uot.json"))
USAGE = Path(os.environ.get("LEIKWAN_EXTERNAL_UOT_USAGE", "/var/lib/leikwan-external-uot/usage.json"))
SERVICE = "leikwan-external-uot.service"
MAX_INPUT_BYTES = 4 * 1024 * 1024
MAX_USAGE_BYTES = 64 * 1024 * 1024
MAX_FORWARDS = 20000
MAX_RATE_LIMIT_MBPS = 100000
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


def valid_credential(value):
    return isinstance(value, str) and value != "" and len(value) <= 8192 and not re.search(r"\s", value)


def valid_rate_limit(value):
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        return False
    return math.isfinite(value) and 0 <= value <= MAX_RATE_LIMIT_MBPS


def valid_entry(entry):
    if not isinstance(entry, dict):
        return False
    credentials = [entry[name] for name in ("secret", "token") if name in entry]
    if not credentials or not all(valid_credential(value) for value in credentials):
        return False
    if "rate_limit_mbps" in entry and not valid_rate_limit(entry["rate_limit_mbps"]):
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


def stored_entry(entry):
    """The validated entry reduced to the fields the sidecar is meant to see."""
    result = {}
    for name in ("token", "secret"):
        if name in entry:
            result[name] = entry[name]
    result["targets"] = list(entry["targets"])
    if "rate_limit_mbps" in entry:
        result["rate_limit_mbps"] = entry["rate_limit_mbps"]
    return result


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
        result[str(key)] = stored_entry(entry)
    return result


def removed_forwards(document, desired):
    """The forward ids the document revokes; an id may not be rendered too."""
    remove = document.get("remove", [])
    if not isinstance(remove, list) or len(remove) > MAX_FORWARDS:
        raise ValueError("remove is not a list of forward ids")
    result = set()
    for item in remove:
        if isinstance(item, bool) or not isinstance(item, (int, str)) or not FORWARD_ID.match(str(item)):
            raise ValueError("invalid forward id in remove")
        if str(item) in desired:
            raise ValueError("forward " + str(item) + " is both rendered and removed")
        result.add(str(item))
    return result


def merge(current, desired, remove=()):
    """Return (merged config, added ids, updated ids, removed ids).

    current is not mutated.
    """
    merged = json.loads(json.dumps(current))
    existing = merged.setdefault("forwards", {})
    if not isinstance(existing, dict):
        raise ValueError("sidecar config forwards is not a map")
    added, updated, removed = [], [], []
    for key in sorted(desired, key=int):
        entry = desired[key]
        old = existing.get(key)
        if old is None:
            existing[key] = entry
            added.append(int(key))
            continue
        if not isinstance(old, dict):
            raise ValueError("sidecar config entry " + key + " is not a map")
        if old != entry:
            existing[key] = entry
            updated.append(int(key))
    for key in sorted(remove, key=int):
        if key in existing:
            del existing[key]
            removed.append(int(key))
    return merged, added, updated, removed


def reload_sidecar():
    subprocess.run(["systemctl", "kill", "--kill-whom=main", "--signal=HUP", SERVICE],
                   check=True, timeout=10)


def print_usage(stdout, usage):
    """Write the usage document verbatim, or {} when it is absent or malformed."""
    try:
        with open(usage, "rb") as stream:
            raw = stream.read(MAX_USAGE_BYTES + 1)
        text = raw.decode("utf-8")
        if len(raw) > MAX_USAGE_BYTES or not isinstance(json.loads(text), dict):
            text = "{}"
    except (OSError, ValueError):
        text = "{}"
    stdout.write(text if text.endswith("\n") else text + "\n")
    return 0


def main(stdin=sys.stdin.buffer, stdout=sys.stdout, config=CONFIG, reload=reload_sidecar,
         environ=os.environ, usage=USAGE):
    if environ.get("SSH_ORIGINAL_COMMAND", "").strip() == "usage":
        return print_usage(stdout, usage)
    raw = stdin.read(MAX_INPUT_BYTES + 1)
    if len(raw) > MAX_INPUT_BYTES:
        raise ValueError("input too large")
    document = json.loads(raw)
    desired = desired_forwards(document)
    remove = removed_forwards(document, desired)
    old_bytes = Path(config).read_bytes()
    current = json.loads(old_bytes)
    merged, added, updated, removed = merge(current, desired, remove)
    changed = bool(added or updated or removed)
    if changed:
        atomic_write(str(config) + ".last-good", old_bytes)
        atomic_write(config, (json.dumps(merged, indent=2) + "\n").encode())
        reload()
    stdout.write(json.dumps({
        "ok": True,
        "changed": changed,
        "added": added,
        "updated": updated,
        "removed": len(removed),
        "forwards": len(merged["forwards"]),
    }) + "\n")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as exc:  # one parseable line for the panel log
        sys.stdout.write(json.dumps({"ok": False, "error": type(exc).__name__ + ": " + str(exc)}) + "\n")
        sys.exit(1)
