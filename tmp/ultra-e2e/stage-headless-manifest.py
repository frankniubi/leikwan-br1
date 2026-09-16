#!/usr/bin/env python3
"""Stage the macOS 1.0.33 artifacts, the Windows headless payload, and the new
manifest entries for the no-GUI surface."""
import hashlib, json, pathlib, shutil

ROOT = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1")
VERSIONS = ROOT / "artifacts/versions.json"
APP_VERSION = "1.0.33"
HEADLESS_VERSION = "0.1.20.47"

def sha(path):
    h = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(1 << 20), b""): h.update(chunk)
    return h.hexdigest()

def atomic_copy(source, destination):
    if destination.is_file() and destination.stat().st_size == source.stat().st_size:
        import hashlib as _h
        def _sha(path):
            h = _h.sha256()
            with open(path, "rb") as fh:
                for chunk in iter(lambda: fh.read(1 << 20), b""): h.update(chunk)
            return h.hexdigest()
        if _sha(source) == _sha(destination):
            return  # already staged: never rewrite a large artifact under a scanner

    """Copy through a scratch file: some Windows filter drivers refuse an
    in-place truncating open of an artifact that was just downloaded."""
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
    src = ROOT / f".build/mac-1033/out/leikwan-bridge-update-{arch}.zip"
    dest = ROOT / f"artifacts/macos/leikwan-bridge-update-{arch}.zip"
    atomic_copy(src, dest)
    entry = doc[f"macos_client_update_{arch}"]
    entry["version"] = APP_VERSION
    entry["sha256"] = sha(dest)
    entry["size_bytes"] = dest.stat().st_size
    entry.setdefault("runtime_sha256", {})["bin/ultra-client"] = runtime_hashes[arch]
    print("update %-5s %s %d" % (arch, entry["sha256"][:16], entry["size_bytes"]))

    dmg_src = ROOT / f".build/mac-1033/out/leikwan-bridge-{arch}.dmg"
    if not dmg_src.is_file():
        raise SystemExit("DMG missing for " + arch + "; run the macOS DMG step first")
    dmg_dest = ROOT / f"artifacts/macos/leikwan-bridge-{arch}.dmg"
    atomic_copy(dmg_src, dmg_dest)
    app_entry = doc[f"macos_client_app_{arch}"]
    app_entry["version"] = APP_VERSION
    app_entry["sha256"] = sha(dmg_dest)
    app_entry["size_bytes"] = dmg_dest.stat().st_size
    print("dmg    %-5s %s %d" % (arch, app_entry["sha256"][:16], app_entry["size_bytes"]))

entries = {
    "windows_headless_client": {
        "artifact_url": "/artifacts/windows/leikwan-bridge-headless-win-x64.zip",
        "path": "artifacts/windows/leikwan-bridge-headless-win-x64.zip",
        "format": "zip",
        "version": HEADLESS_VERSION,
        "architecture": "x86_64",
        "platform": "windows",
    },
    "macos_headless_installer": {
        "install_url": "/artifacts/install-macos-headless.sh",
        "path": "install/install-macos-headless.sh",
        "version": APP_VERSION,
    },
    "macos_headless_uninstaller": {
        "install_url": "/artifacts/uninstall-macos-headless.sh",
        "path": "install/uninstall-macos-headless.sh",
        "version": APP_VERSION,
    },
    "windows_headless_installer": {
        "install_url": "/artifacts/install-windows-headless.ps1",
        "path": "install/install-windows-headless.ps1",
        "version": HEADLESS_VERSION,
    },
    "windows_headless_uninstaller": {
        "install_url": "/artifacts/uninstall-windows-headless.ps1",
        "path": "install/uninstall-windows-headless.ps1",
        "version": HEADLESS_VERSION,
    },
}
for key, entry in entries.items():
    path = ROOT / entry["path"]
    if not path.is_file():
        raise SystemExit("missing artifact for %s: %s" % (key, entry["path"]))
    entry["sha256"] = sha(path)
    entry["size_bytes"] = path.stat().st_size
    doc[key] = entry
    print("%-30s %s %d" % (key, entry["sha256"][:16], entry["size_bytes"]))

payload = json.dumps(doc, indent=4, ensure_ascii=False).encode("utf-8") + b"\n"
scratch = VERSIONS.with_suffix(".json.stage")
scratch.write_bytes(payload)
json.loads(scratch.read_text(encoding="utf-8"))
scratch.replace(VERSIONS)
print("manifest updated")
