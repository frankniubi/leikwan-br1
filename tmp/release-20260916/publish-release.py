import hashlib
import json
import os
import pathlib
import shutil
import time


ROOT = pathlib.Path("/www/wwwroot/v3.leikwanhost.com/leikwan-v3")
STAGE = pathlib.Path("/root/ultra-release-20260916")
TARGETS = [
    ("artifacts/ultra/ultra-client_linux_amd64", 0o755),
    ("artifacts/ultra/ultra-client_linux_arm64", 0o755),
    ("artifacts/ultra/ultra-client_macos_arm64", 0o755),
    ("artifacts/ultra/ultra-client_macos_amd64", 0o755),
    ("artifacts/ultra/ultra-client_windows_amd64.exe", 0o644),
    ("artifacts/windows/leikwan-bridge-headless-win-x64.zip", 0o644),
    ("artifacts/macos/leikwan-bridge-update-arm64.zip", 0o644),
    ("artifacts/macos/leikwan-bridge-update-x64.zip", 0o644),
    ("artifacts/macos/leikwan-bridge-arm64.dmg", 0o644),
    ("artifacts/macos/leikwan-bridge-x64.dmg", 0o644),
    ("install/install-macos-headless.sh", 0o755),
    ("install/uninstall-macos-headless.sh", 0o755),
    ("install/install-windows-headless.ps1", 0o644),
    ("install/uninstall-windows-headless.ps1", 0o644),
]
MANIFEST_RELATIVE = "artifacts/versions.json"


def sha(path: pathlib.Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1 << 20), b""):
            digest.update(chunk)
    return digest.hexdigest()


def stage_path(relative: str) -> pathlib.Path:
    return STAGE / relative


def target_path(relative: str) -> pathlib.Path:
    return ROOT / relative


manifest = json.loads((STAGE / MANIFEST_RELATIVE).read_text(encoding="utf-8"))
checks = [(relative, mode) for relative, mode in TARGETS]
checks.append((MANIFEST_RELATIVE, 0o644))
for relative, _ in checks:
    if not stage_path(relative).is_file():
        raise SystemExit(f"staged file missing: {relative}")
for key, entry in manifest.items():
    if isinstance(entry, dict) and entry.get("path") in {relative for relative, _ in TARGETS}:
        staged = stage_path(entry["path"])
        if sha(staged) != entry.get("sha256") or staged.stat().st_size != entry.get("size_bytes"):
            raise SystemExit(f"manifest mismatch in staging: {key}")

stamp = time.strftime("%Y%m%dT%H%M%SZ", time.gmtime())
backup = pathlib.Path("/root/leikwan-br1-backups/ultra-active-candidate-20260916-" + stamp)
backup.mkdir(parents=True, exist_ok=False)
(backup / "release.json").write_text(
    json.dumps({"version": "2026.09.16.1.e0e4a71f", "targets": [relative for relative, _ in checks]}, indent=2) + "\n",
    encoding="utf-8",
)

before = {}
for relative, _ in checks:
    target = target_path(relative)
    if not target.is_file():
        raise SystemExit(f"live target missing: {relative}")
    backup_target = backup / relative
    backup_target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(target, backup_target)
    before[relative] = {"sha256": sha(target), "size_bytes": target.stat().st_size}

changed = []
try:
    for relative, mode in TARGETS + [(MANIFEST_RELATIVE, 0o644)]:
        source = stage_path(relative)
        target = target_path(relative)
        scratch = target.with_name(target.name + ".release-20260916.new")
        if scratch.exists():
            scratch.unlink()
        shutil.copy2(source, scratch)
        os.chmod(scratch, mode)
        os.replace(scratch, target)
        changed.append(relative)
except Exception:
    for relative in reversed(changed):
        target = target_path(relative)
        scratch = target.with_name(target.name + ".rollback-20260916.new")
        shutil.copy2(backup / relative, scratch)
        os.replace(scratch, target)
    raise

for relative, _ in checks:
    target = target_path(relative)
    if sha(target) != sha(stage_path(relative)) or target.stat().st_size != stage_path(relative).stat().st_size:
        raise SystemExit(f"post-publish mismatch: {relative}")

(backup / "before.json").write_text(json.dumps(before, indent=2) + "\n", encoding="utf-8")
print("PUBLISH-OK")
print("version: 2026.09.16.1.e0e4a71f")
print("targets:", len(checks))
print("backup:", backup)
for relative, _ in checks:
    target = target_path(relative)
    print(f"{relative} {sha(target)[:16]} {target.stat().st_size}")
