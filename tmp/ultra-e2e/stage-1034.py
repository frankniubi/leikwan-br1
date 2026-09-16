#!/usr/bin/env python3
"""Stage macOS 1.0.34 + Windows headless 0.1.20.48 into the local manifest."""
import hashlib, json, pathlib, shutil

ROOT = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1")
VERSIONS = ROOT / "artifacts/versions.json"
APP_VERSION = "1.0.34"
HEADLESS_VERSION = "0.1.20.48"

def sha(path):
    h = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(1 << 20), b""): h.update(chunk)
    return h.hexdigest()

def atomic_copy(source, destination):
    if destination.is_file() and destination.stat().st_size == source.stat().st_size and sha(source) == sha(destination):
        return
    scratch = destination.with_suffix(destination.suffix + ".incoming")
    with open(source, "rb") as src, open(scratch, "wb") as dst:
        for chunk in iter(lambda: src.read(1 << 20), b""):
            dst.write(chunk)
    scratch.replace(destination)

doc = json.loads(VERSIONS.read_text(encoding="utf-8"))
runtime_hashes = {
    "arm64": doc["ultra_client_runtime_macos_arm64"]["sha256"],
    "x64": doc["ultra_client_runtime_macos_x64"]["sha256"],
}
for arch in ("arm64", "x64"):
    zip_src = ROOT / f".build/mac-1034/out/leikwan-bridge-update-{arch}.zip"
    zip_dest = ROOT / f"artifacts/macos/leikwan-bridge-update-{arch}.zip"
    atomic_copy(zip_src, zip_dest)
    entry = doc[f"macos_client_update_{arch}"]
    entry["version"] = APP_VERSION
    entry["sha256"] = sha(zip_dest)
    entry["size_bytes"] = zip_dest.stat().st_size
    entry.setdefault("runtime_sha256", {})["bin/ultra-client"] = runtime_hashes[arch]
    print("update %-5s %s %d" % (arch, entry["sha256"][:16], entry["size_bytes"]))

    dmg_src = ROOT / f".build/mac-1034/out/leikwan-bridge-{arch}.dmg"
    if not dmg_src.is_file():
        raise SystemExit("DMG missing for " + arch)
    dmg_dest = ROOT / f"artifacts/macos/leikwan-bridge-{arch}.dmg"
    atomic_copy(dmg_src, dmg_dest)
    app_entry = doc[f"macos_client_app_{arch}"]
    app_entry["version"] = APP_VERSION
    app_entry["sha256"] = sha(dmg_dest)
    app_entry["size_bytes"] = dmg_dest.stat().st_size
    print("dmg    %-5s %s %d" % (arch, app_entry["sha256"][:16], app_entry["size_bytes"]))

for key, relative, version in (
    ("windows_headless_client", "artifacts/windows/leikwan-bridge-headless-win-x64.zip", HEADLESS_VERSION),
    ("windows_headless_installer", "install/install-windows-headless.ps1", HEADLESS_VERSION),
    ("macos_headless_installer", "install/install-macos-headless.sh", APP_VERSION),
    ("macos_headless_uninstaller", "install/uninstall-macos-headless.sh", APP_VERSION),
    ("windows_headless_uninstaller", "install/uninstall-windows-headless.ps1", HEADLESS_VERSION),
):
    path = ROOT / relative
    entry = doc[key]
    entry["version"] = version
    entry["sha256"] = sha(path)
    entry["size_bytes"] = path.stat().st_size
    print("%-30s %s %d" % (key, entry["sha256"][:16], entry["size_bytes"]))

payload = json.dumps(doc, indent=4, ensure_ascii=False).encode("utf-8") + b"\n"
scratch = VERSIONS.with_suffix(".json.stage")
scratch.write_bytes(payload)
json.loads(scratch.read_text(encoding="utf-8"))
scratch.replace(VERSIONS)
print("manifest updated")
