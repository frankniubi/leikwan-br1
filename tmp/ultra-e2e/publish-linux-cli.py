#!/usr/bin/env python3
"""Publish the Linux leikwan CLI: the wrapper, the refreshed installer, and the
new artifacts route."""
import hashlib, json, os, pathlib, shutil, subprocess, time

ROOT = pathlib.Path("/www/wwwroot/v3.leikwanhost.com/leikwan-v3")
STAGE = pathlib.Path("/root/linuxcli")
STAMP = time.strftime("%Y%m%dT%H%M%SZ", time.gmtime())
BACKUP = pathlib.Path("/root/leikwan-br1-backups/linux-cli-" + STAMP)
BACKUP.mkdir(parents=True, exist_ok=True)

def sha(path):
    h = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(1 << 20), b""): h.update(chunk)
    return h.hexdigest()

TARGETS = [
    ("install/leikwan-linux.sh", 0o755),
    ("install/install-client-agent.sh", 0o755),
    ("master/src/Controllers/ArtifactController.php", 0o644),
]
for relative, mode in TARGETS:
    source = STAGE / pathlib.Path(relative).name
    if not source.is_file():
        raise SystemExit("staged file missing: " + source.name)
    target = ROOT / relative
    if target.exists():
        shutil.copy2(target, BACKUP / target.name)
    shutil.copy2(source, target)
    os.chmod(target, mode)
    print("staged %-46s %s %d" % (relative, sha(target)[:16], target.stat().st_size))

# Bootstrap: anchored patch (the panel copy owns every other route)
bootstrap = ROOT / "master/src/Bootstrap.php"
text = bootstrap.read_text(encoding="utf-8")
anchor = "        $this->router->add('GET', '/artifacts/install-client-agent.sh', [$artifacts, 'installClientAgent']);\n"
route = "        $this->router->add('GET', '/artifacts/leikwan-linux.sh', [$artifacts, 'leikwanLinuxCli']);\n"
if route in text:
    print("bootstrap already patched")
else:
    if anchor not in text:
        raise SystemExit("bootstrap anchor not found")
    shutil.copy2(bootstrap, BACKUP / "Bootstrap.php")
    scratch = ROOT / "master/src/Bootstrap.php.new"
    scratch.write_text(text.replace(anchor, anchor + route, 1), encoding="utf-8")
    lint = subprocess.run(["php", "-l", str(scratch)], capture_output=True, text=True)
    if lint.returncode != 0:
        raise SystemExit("patched Bootstrap.php does not lint: " + lint.stdout + lint.stderr)
    os.replace(scratch, bootstrap)
    os.chmod(bootstrap, 0o644)
    print("bootstrap patched with the leikwan-linux route")

shutil.copy2(ROOT / "artifacts/versions.json", BACKUP / "versions.json.prev")
tmp = ROOT / "artifacts/versions.json.new"
tmp.write_text((STAGE / "versions.json").read_text(encoding="utf-8"), encoding="utf-8")
json.loads(tmp.read_text(encoding="utf-8"))
os.replace(tmp, ROOT / "artifacts/versions.json")
print("manifest published:", sha(ROOT / "artifacts/versions.json")[:16])

for relative in ("master/src/Bootstrap.php", "master/src/Controllers/ArtifactController.php"):
    lint = subprocess.run(["php", "-l", str(ROOT / relative)], capture_output=True, text=True)
    if lint.returncode != 0:
        raise SystemExit("lint failed: " + relative)
    print("lint ok", relative)

manifest = json.loads((ROOT / "artifacts/versions.json").read_text(encoding="utf-8"))
failures = []
for key, url in (("leikwan_cli_linux", "/artifacts/leikwan-linux.sh"),
                 ("client_agent_installer", "/artifacts/install-client-agent.sh")):
    body = subprocess.run(["curl", "-sS", "-A", "LeikwanBridge/1.0", "https://v3.leikwanhost.com" + url],
                          capture_output=True).stdout
    served = hashlib.sha256(body).hexdigest()
    ok = served == manifest[key]["sha256"]
    print("%-24s served=%s manifest=%s %s" % (key, served[:16], manifest[key]["sha256"][:16], "OK" if ok else "MISMATCH"))
    if not ok:
        failures.append(key)
print("backup:", BACKUP)
if failures:
    raise SystemExit("FAILED: " + ", ".join(failures))
print("LINUX-CLI-PUBLISH-OK")
