#!/usr/bin/env python3
"""Publish macOS 1.0.34, the Windows headless 0.1.20.48 payload, the leikwan CLI
and the refreshed no-GUI knowledgebase guide."""
import hashlib, json, os, pathlib, shutil, subprocess, time

ROOT = pathlib.Path("/www/wwwroot/v3.leikwanhost.com/leikwan-v3")
STAGE = pathlib.Path("/root/cli1034")
STAMP = time.strftime("%Y%m%dT%H%M%SZ", time.gmtime())
BACKUP = pathlib.Path("/root/leikwan-br1-backups/cli-1034-" + STAMP)
BACKUP.mkdir(parents=True, exist_ok=True)

env = {}
for line in (ROOT / "master/.env").read_text(encoding="utf-8").splitlines():
    line = line.strip()
    if line and not line.startswith("#") and "=" in line:
        key, value = line.split("=", 1)
        env[key.strip()] = value.strip().strip('"').strip("'")
mysql = ["mysql", "-h", env.get("DB_HOST", "127.0.0.1"), "-P", str(env.get("DB_PORT", "3306")),
         "-u", env.get("DB_USER", "root"), "-p" + env.get("DB_PASSWORD", ""), env.get("DB_NAME", "")]

def query(sql):
    return subprocess.run(mysql + ["-e", sql], capture_output=True, text=True).stdout

TARGETS = [
    ("artifacts/macos/leikwan-bridge-update-arm64.zip", 0o644),
    ("artifacts/macos/leikwan-bridge-update-x64.zip", 0o644),
    ("artifacts/macos/leikwan-bridge-arm64.dmg", 0o644),
    ("artifacts/macos/leikwan-bridge-x64.dmg", 0o644),
    ("artifacts/windows/leikwan-bridge-headless-win-x64.zip", 0o644),
    ("install/install-macos-headless.sh", 0o755),
    ("install/install-windows-headless.ps1", 0o644),
    ("install/leikwan", 0o755),
    ("install/leikwan.cmd", 0o644),
]

def sha(path):
    h = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(1 << 20), b""): h.update(chunk)
    return h.hexdigest()

for relative, mode in TARGETS:
    source = STAGE / pathlib.Path(relative).name
    if not source.is_file():
        raise SystemExit("staged file missing: " + source.name)
    target = ROOT / relative
    if target.exists():
        shutil.copy2(target, BACKUP / target.name)
    target.parent.mkdir(parents=True, exist_ok=True)
    shutil.copy2(source, target)
    os.chmod(target, mode)
    print("staged %-56s %s %d" % (relative, sha(target)[:16], target.stat().st_size))

shutil.copy2(ROOT / "artifacts/versions.json", BACKUP / "versions.json.prev")
tmp = ROOT / "artifacts/versions.json.new"
tmp.write_text((STAGE / "versions.json").read_text(encoding="utf-8"), encoding="utf-8")
json.loads(tmp.read_text(encoding="utf-8"))
os.replace(tmp, ROOT / "artifacts/versions.json")
print("manifest published:", sha(ROOT / "artifacts/versions.json")[:16])

# Knowledgebase: the guide gained the CLI section, so the published rows need an
# explicit, reversible refresh (the seed only inserts missing articles).
rows = query("SELECT a.locale, a.title, SHA2(a.body_html, 256) FROM knowledgebase_articles a "
             "JOIN knowledgebase_topics t ON t.id = a.topic_id WHERE t.topic_key = 'no-gui-clients';")
(BACKUP / "no-gui-clients.before.txt").write_text(rows, encoding="utf-8")
print("before:", rows.strip().replace("\n", " | "))
refresh = STAGE / "kb-1034-refresh.sql"
result = subprocess.run(mysql + ["--default-character-set=utf8mb4"], stdin=refresh.open("rb"), capture_output=True)
if result.returncode != 0:
    raise SystemExit("knowledgebase refresh failed: " + result.stderr.decode("utf-8", "replace")[-500:])
after = query("SELECT a.locale, a.title, CHAR_LENGTH(a.body_html) FROM knowledgebase_articles a "
              "JOIN knowledgebase_topics t ON t.id = a.topic_id WHERE t.topic_key = 'no-gui-clients' ORDER BY a.locale;")
(BACKUP / "no-gui-clients.after.txt").write_text(after, encoding="utf-8")
print("after:", after.strip().replace("\n", " | "))
if "leikwan status" not in query("SELECT body_html FROM knowledgebase_articles a JOIN knowledgebase_topics t ON t.id = a.topic_id "
                                 "WHERE t.topic_key = 'no-gui-clients' AND a.locale = 'en';"):
    raise SystemExit("knowledgebase refresh did not land the CLI section")

manifest = json.loads((ROOT / "artifacts/versions.json").read_text(encoding="utf-8"))
checks = [
    ("macos_client_app_arm64", "/artifacts/macos/leikwan-bridge-arm64.dmg"),
    ("macos_client_app_x64", "/artifacts/macos/leikwan-bridge-x64.dmg"),
    ("macos_client_update_arm64", "/artifacts/macos/leikwan-bridge-update-arm64.zip"),
    ("macos_client_update_x64", "/artifacts/macos/leikwan-bridge-update-x64.zip"),
    ("windows_headless_client", manifest["windows_headless_client"]["artifact_url"]),
    ("macos_headless_installer", manifest["macos_headless_installer"]["install_url"]),
    ("windows_headless_installer", manifest["windows_headless_installer"]["install_url"]),
]
failures = []
for key, url in checks:
    body = subprocess.run(["curl", "-sS", "-A", "LeikwanBridge/1.0", "https://v3.leikwanhost.com" + url],
                          capture_output=True).stdout
    served = hashlib.sha256(body).hexdigest()
    expected = manifest[key]["sha256"]
    ok = served == expected
    print("%-32s served=%s manifest=%s %s" % (key, served[:16], expected[:16], "OK" if ok else "MISMATCH"))
    if not ok:
        failures.append(key)

print("cli wrapper served:", hashlib.sha256(subprocess.run(
    ["curl", "-sS", "-A", "LeikwanBridge/1.0", "https://v3.leikwanhost.com/artifacts/install-macos-headless.sh"],
    capture_output=True).stdout).hexdigest()[:16])
print("backup:", BACKUP)
if failures:
    raise SystemExit("FAILED: " + ", ".join(failures))
print("CLI-PUBLISH-OK")
