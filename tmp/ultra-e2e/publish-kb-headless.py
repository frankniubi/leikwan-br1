#!/usr/bin/env python3
"""Publish the no-GUI knowledgebase guide and the download-centre links."""
import hashlib, json, os, pathlib, shutil, subprocess, time

ROOT = pathlib.Path("/www/wwwroot/v3.leikwanhost.com/leikwan-v3")
STAGE = pathlib.Path("/root/kbheadless")
STAMP = time.strftime("%Y%m%dT%H%M%SZ", time.gmtime())
BACKUP = pathlib.Path("/root/leikwan-br1-backups/kb-headless-" + STAMP)
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

before_topics = query("SELECT COUNT(*) FROM knowledgebase_topics;").strip().splitlines()[-1]
before_articles = query("SELECT COUNT(*) FROM knowledgebase_articles;").strip().splitlines()[-1]
print("before: topics=%s articles=%s" % (before_topics, before_articles))

# 1. controller surface
for relative in ("master/src/Controllers/BaseController.php", "master/src/Controllers/TenantController.php"):
    target = ROOT / relative
    source = STAGE / pathlib.Path(relative).name
    if not source.is_file():
        raise SystemExit("staged file missing: " + source.name)
    shutil.copy2(target, BACKUP / target.name)
    shutil.copy2(source, target)
    os.chmod(target, 0o644)
    print("staged", relative)

# 2. knowledgebase seed (idempotent: existing topics and localized articles win)
seed = STAGE / "20260729_seed_multilingual_knowledgebase.sql"
if not seed.is_file():
    raise SystemExit("staged seed missing")
existing_seed = ROOT / "master/migrations/20260729_seed_multilingual_knowledgebase.sql"
if existing_seed.is_file():
    shutil.copy2(existing_seed, BACKUP / "seed.prev.sql")
shutil.copy2(seed, ROOT / "master/migrations/20260729_seed_multilingual_knowledgebase.sql")
result = subprocess.run(mysql + ["--default-character-set=utf8mb4"], stdin=seed.open("rb"), capture_output=True)
if result.returncode != 0:
    raise SystemExit("seed failed: " + result.stderr.decode("utf-8", "replace")[-800:])
print("seed applied")

after_topics = query("SELECT COUNT(*) FROM knowledgebase_topics;").strip().splitlines()[-1]
after_articles = query("SELECT COUNT(*) FROM knowledgebase_articles;").strip().splitlines()[-1]
print("after:  topics=%s articles=%s" % (after_topics, after_articles))
print(query("SELECT t.topic_key, a.locale, a.status, CHAR_LENGTH(a.body_html) AS body_len "
            "FROM knowledgebase_topics t JOIN knowledgebase_articles a ON a.topic_id = t.id "
            "WHERE t.topic_key = 'no-gui-clients' ORDER BY a.locale;"))

for relative in ("master/src/Controllers/BaseController.php", "master/src/Controllers/TenantController.php"):
    lint = subprocess.run(["php", "-l", str(ROOT / relative)], capture_output=True, text=True)
    if lint.returncode != 0:
        raise SystemExit("lint failed: " + relative + " " + lint.stdout + lint.stderr)
    print("lint ok", relative)

print("backup:", BACKUP)
print("KB-PUBLISH-OK")
