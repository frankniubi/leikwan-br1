#!/usr/bin/env python3
"""Stage the Linux leikwan CLI artifact and refresh the installer hash."""
import hashlib, json, pathlib

ROOT = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1")
VERSIONS = ROOT / "artifacts/versions.json"

def sha(path):
    h = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(1 << 20), b""): h.update(chunk)
    return h.hexdigest()

doc = json.loads(VERSIONS.read_text(encoding="utf-8"))
cli = ROOT / "install/leikwan-linux.sh"
doc["leikwan_cli_linux"] = {
    "install_url": "/artifacts/leikwan-linux.sh",
    "path": "install/leikwan-linux.sh",
    "version": doc["client_agent"]["version"],
    "sha256": sha(cli),
    "size_bytes": cli.stat().st_size,
}
installer = ROOT / "install/install-client-agent.sh"
entry = doc["client_agent_installer"]
before = str(entry.get("sha256"))[:16]
entry["sha256"] = sha(installer)
entry["size_bytes"] = installer.stat().st_size
print("client_agent_installer %s -> %s (%d bytes)" % (before, entry["sha256"][:16], entry["size_bytes"]))
print("leikwan_cli_linux %s %d bytes" % (doc["leikwan_cli_linux"]["sha256"][:16], doc["leikwan_cli_linux"]["size_bytes"]))

payload = json.dumps(doc, indent=4, ensure_ascii=False).encode("utf-8") + b"\n"
scratch = VERSIONS.with_suffix(".json.stage")
scratch.write_bytes(payload)
json.loads(scratch.read_text(encoding="utf-8"))
scratch.replace(VERSIONS)
print("manifest updated")
