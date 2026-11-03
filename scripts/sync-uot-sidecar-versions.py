#!/usr/bin/env python3
"""Sync the external-UoT sidecar artifacts into artifacts/versions.json.

The artifact store manifest is the single source of truth the installers verify
against (install/install-bond-sidecar.sh -> download_verified_artifact and
install/install-external-uot.sh -> sha256 check).  This helper keeps the three
entries that the install path depends on in lockstep with the files on disk:

  external_uot_installer     install/install-external-uot.sh
  uot_sidecar_linux_amd64    artifacts/uot-sidecar/uot-sidecar_linux_amd64
  uot_sidecar_linux_arm64    artifacts/uot-sidecar/uot-sidecar_linux_arm64

plus the bond_sidecar_installer entry, whose hash changes whenever the sidecar
installer changes.  The edit is text-surgical so unrelated entries keep their
exact formatting and ordering.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[1]
VERSIONS = REPO / "artifacts" / "versions.json"
SIDECAR_MANIFEST = REPO / "artifacts" / "uot-sidecar" / "manifest.json"
EXTERNAL_UOT_INSTALLER = REPO / "install" / "install-external-uot.sh"
BOND_SIDECAR_INSTALLER = REPO / "install" / "install-bond-sidecar.sh"

PLATFORMS = {
    "linux_amd64": "uot_sidecar_linux_amd64",
    "linux_arm64": "uot_sidecar_linux_arm64",
}


def sha256_of(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1 << 20), b""):
            digest.update(chunk)
    return digest.hexdigest()


def installer_version(path: Path) -> str:
    match = re.search(r'^INSTALLER_VERSION="([^"]+)"', path.read_text(encoding="utf-8"), re.M)
    return match.group(1) if match else "unknown"


def render_entry(key: str, body: dict, indent: str = "  ") -> str:
    lines = [f'{indent}"{key}": {{']
    for name, value in body.items():
        if isinstance(value, bool):
            rendered = "true" if value else "false"
        elif isinstance(value, (int, float)):
            rendered = str(value)
        else:
            rendered = json.dumps(value)
        lines.append(f'{indent}  "{name}": {rendered},')
    lines[-1] = lines[-1][:-1]
    lines.append(f"{indent}}}")
    return "\r\n".join(lines)


def block_span(raw: str, key: str) -> tuple[int, int]:
    start = raw.index(f'"{key}": {{')
    end = raw.index("\n  },", start) + len("\n  },")
    return start, end


def patch_values(raw: str, key: str, values: dict) -> str:
    start, end = block_span(raw, key)
    block = raw[start:end]
    for name, value in values.items():
        pattern = re.compile(rf'(\s*"{name}":\s*)("[^"]*"|[-0-9.eE]+)')
        if not pattern.search(block):
            raise SystemExit(f"{key} has no {name!r} field to update")
        block = pattern.sub(lambda m: m.group(1) + json.dumps(value), block, count=1)
    return raw[:start] + block + raw[end:]


def desired_entries() -> dict:
    manifest = json.loads(SIDECAR_MANIFEST.read_text(encoding="utf-8"))
    entries = {}
    for platform, key in PLATFORMS.items():
        entry = manifest[platform]
        entries[key] = {
            "artifact_url": f"/artifacts/uot-sidecar/{entry['file']}",
            "path": f"artifacts/uot-sidecar/{entry['file']}",
            "sha256": entry["sha256"],
            "size_bytes": entry["size"],
            "version": entry["version"],
        }
    entries["external_uot_installer"] = {
        "install_url": "/artifacts/install-external-uot.sh",
        "artifact_url": "/artifacts/install-external-uot.sh",
        "path": "install/install-external-uot.sh",
        "sha256": sha256_of(EXTERNAL_UOT_INSTALLER),
        "size_bytes": EXTERNAL_UOT_INSTALLER.stat().st_size,
        "version": installer_version(EXTERNAL_UOT_INSTALLER),
    }
    return entries


def apply_updates(raw: str, entries: dict) -> str:
    for key, body in entries.items():
        if f'"{key}": {{' in raw:
            values = {name: value for name, value in body.items() if name != "path"}
            raw = patch_values(raw, key, values)
        else:
            anchor = raw.index("\n  },", raw.index('"bond_sidecar_uninstaller": {')) + len("\n  },")
            raw = raw[:anchor] + "\r\n" + render_entry(key, body) + "," + raw[anchor:]
    bond_values = {
        "sha256": sha256_of(BOND_SIDECAR_INSTALLER),
        "size_bytes": BOND_SIDECAR_INSTALLER.stat().st_size,
        "version": installer_version(BOND_SIDECAR_INSTALLER),
    }
    start, end = block_span(raw, "bond_sidecar_installer")
    block = raw[start:end]
    for name, value in bond_values.items():
        block = re.sub(rf'(\s*"{name}":\s*)("[^"]*"|[-0-9.eE]+)', lambda m: m.group(1) + json.dumps(value), block, count=1)
    return raw[:start] + block + raw[end:]


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--check", action="store_true", help="report drift without writing")
    args = parser.parse_args()

    raw = VERSIONS.read_text(encoding="utf-8")
    entries = desired_entries()
    updated = apply_updates(raw, entries)
    json.loads(updated)  # fail loudly rather than corrupt the manifest

    if updated == raw:
        print("versions.json already in sync")
        return 0
    if args.check:
        print("versions.json is out of sync with the sidecar artifacts:", file=sys.stderr)
        for key, body in entries.items():
            print(f"  {key}: sha256={body['sha256']} size={body['size_bytes']} version={body['version']}", file=sys.stderr)
        return 1

    VERSIONS.write_bytes(updated.encode("utf-8"))
    print(f"updated {VERSIONS.relative_to(REPO)}:")
    for key, body in entries.items():
        print(f"  {key}: sha256={body['sha256']} size={body['size_bytes']} version={body['version']}")
    print(f"  bond_sidecar_installer: sha256={sha256_of(BOND_SIDECAR_INSTALLER)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

