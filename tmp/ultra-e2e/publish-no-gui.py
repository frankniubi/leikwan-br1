#!/usr/bin/env python3
"""Publish the no-GUI surface (macOS 1.0.33 + Windows headless) and the panel code."""
import hashlib, json, os, pathlib, shutil, subprocess, time

ROOT = pathlib.Path("/www/wwwroot/v3.leikwanhost.com/leikwan-v3")
STAGE = pathlib.Path("/root/nogui")
STAMP = time.strftime("%Y%m%dT%H%M%SZ", time.gmtime())
BACKUP = pathlib.Path("/root/leikwan-br1-backups/no-gui-" + STAMP)
BACKUP.mkdir(parents=True, exist_ok=True)

TARGETS = [
    ("artifacts/macos/leikwan-bridge-update-arm64.zip", 0o644),
    ("artifacts/macos/leikwan-bridge-update-x64.zip", 0o644),
    ("artifacts/macos/leikwan-bridge-arm64.dmg", 0o644),
    ("artifacts/macos/leikwan-bridge-x64.dmg", 0o644),
    ("artifacts/windows/leikwan-bridge-headless-win-x64.zip", 0o644),
    ("install/install-macos-headless.sh", 0o755),
    ("install/uninstall-macos-headless.sh", 0o755),
    ("install/install-windows-headless.ps1", 0o644),
    ("install/uninstall-windows-headless.ps1", 0o644),
    ("master/src/Controllers/ArtifactController.php", 0o644),
    ("master/src/Controllers/BaseController.php", 0o644),
    ("master/src/Controllers/TenantController.php", 0o644),
]

def sha(path):
    h = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(1 << 20), b""): h.update(chunk)
    return h.hexdigest()

def backup(relative):
    target = ROOT / relative
    if target.exists():
        shutil.copy2(target, BACKUP / target.name)

# 1. payloads
for relative, mode in TARGETS:
    source = STAGE / pathlib.Path(relative).name
    if not source.is_file():
        raise SystemExit("staged file missing: " + source.name)
    target = ROOT / relative
    backup(relative)
    target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(source, target)
    os.chmod(target, mode)
    print("staged %-56s %s %d" % (relative, sha(target)[:16], target.stat().st_size))

# 2. manifest
backup("artifacts/versions.json")
tmp = ROOT / "artifacts/versions.json.new"
tmp.write_text((STAGE / "versions.json").read_text(encoding="utf-8"), encoding="utf-8")
json.loads(tmp.read_text(encoding="utf-8"))
os.replace(tmp, ROOT / "artifacts/versions.json")
print("manifest published:", sha(ROOT / "artifacts/versions.json")[:16])

# 3. Bootstrap: anchored patch. The panel copy is the authority for every other
#    route, so never replace the whole file.
bootstrap = ROOT / "master/src/Bootstrap.php"
text = bootstrap.read_text(encoding="utf-8")
anchor = "        $this->router->add('GET', '/artifacts/leikwan-bridge-setup.exe', [$artifacts, 'windowsClientApp']);\n"
routes = [
    "        $this->router->add('GET', '/artifacts/windows/leikwan-bridge-headless-win-x64.zip', [$artifacts, 'windowsHeadlessClient']);\n",
    "        $this->router->add('GET', '/artifacts/install-macos-headless.sh', [$artifacts, 'installMacosHeadless']);\n",
    "        $this->router->add('GET', '/artifacts/uninstall-macos-headless.sh', [$artifacts, 'uninstallMacosHeadless']);\n",
    "        $this->router->add('GET', '/artifacts/install-windows-headless.ps1', [$artifacts, 'installWindowsHeadless']);\n",
    "        $this->router->add('GET', '/artifacts/uninstall-windows-headless.ps1', [$artifacts, 'uninstallWindowsHeadless']);\n",
]
if all(route in text for route in routes):
    print("bootstrap already patched")
else:
    if anchor not in text:
        raise SystemExit("bootstrap anchor not found")
    backup("master/src/Bootstrap.php")
    text = text.replace(anchor, anchor + "".join(routes), 1)
    scratch = ROOT / "master/src/Bootstrap.php.new"
    scratch.write_text(text, encoding="utf-8")
    lint = subprocess.run(["php", "-l", str(scratch)], capture_output=True, text=True)
    if lint.returncode != 0:
        raise SystemExit("patched Bootstrap.php does not lint: " + lint.stdout + lint.stderr)
    shutil.copy2(bootstrap, BACKUP / "Bootstrap.php.before")
    os.replace(scratch, bootstrap)
    os.chmod(bootstrap, 0o644)
    print("bootstrap patched with 5 routes")

# 4. lint every PHP file we touched
for relative in ("master/src/Bootstrap.php", "master/src/Controllers/ArtifactController.php",
                 "master/src/Controllers/BaseController.php", "master/src/Controllers/TenantController.php"):
    lint = subprocess.run(["php", "-l", str(ROOT / relative)], capture_output=True, text=True)
    if lint.returncode != 0:
        raise SystemExit("lint failed for %s: %s" % (relative, lint.stdout + lint.stderr))
    print("lint ok", relative)

# 5. verify the served bytes
manifest = json.loads((ROOT / "artifacts/versions.json").read_text(encoding="utf-8"))
checks = []
for key in ("macos_client_app_arm64", "macos_client_app_x64", "macos_client_update_arm64",
            "macos_client_update_x64", "windows_headless_client"):
    checks.append((key, manifest[key]["path"], manifest[key]["sha256"]))
for key in ("macos_headless_installer", "macos_headless_uninstaller",
            "windows_headless_installer", "windows_headless_uninstaller"):
    checks.append((key, manifest[key]["path"], manifest[key]["sha256"]))

failures = []
for key, relative, expected in checks:
    served_url = "/" + relative
    if relative.startswith("install/"):
        served_url = manifest[key]["install_url"]
    if relative.startswith("artifacts/windows/"):
        served_url = manifest[key]["artifact_url"]
    body = subprocess.run(
        ["curl", "-sS", "-A", "LeikwanBridge/1.0", "https://v3.leikwanhost.com" + served_url],
        capture_output=True).stdout
    served = hashlib.sha256(body).hexdigest()
    ok = served == expected
    print("%-32s served=%s manifest=%s %s" % (key, served[:16], expected[:16], "OK" if ok else "MISMATCH"))
    if not ok:
        failures.append(key)

served_manifest = json.loads(subprocess.run(
    ["curl", "-sS", "-A", "LeikwanBridge/1.0", "https://v3.leikwanhost.com/artifacts/versions.json"],
    capture_output=True).stdout.decode("utf-8"))
print("served manifest mac app:", served_manifest["macos_client_app_arm64"]["version"],
      "headless:", served_manifest["windows_headless_client"]["version"])
print("backup:", BACKUP)
if failures:
    raise SystemExit("FAILED: " + ", ".join(failures))
print("PUBLISH-OK")
