python3 - <<'PY'
import pathlib, subprocess
env = {}
for line in pathlib.Path("/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/.env").read_text().splitlines():
    line = line.strip()
    if line and not line.startswith("#") and "=" in line:
        k, v = line.split("=", 1); env[k.strip()] = v.strip().strip('"').strip("'")
args = ["mysql", "-h", env.get("DB_HOST", "127.0.0.1"), "-P", str(env.get("DB_PORT", "3306")),
        "-u", env.get("DB_USER", "root"), "-p" + env.get("DB_PASSWORD", ""), env.get("DB_NAME", "")]
before = subprocess.run(args + ["-e", "SELECT a.locale, CHAR_LENGTH(a.body_html), a.body_html LIKE '%ultra%' AS has_ultra, a.body_html LIKE '%Single%' AS has_mode FROM knowledgebase_articles a JOIN knowledgebase_topics t ON t.id=a.topic_id WHERE t.topic_key='getting-started-five-minute-overview' ORDER BY a.locale;"], capture_output=True, text=True).stdout
pathlib.Path("/root/leikwan-br1-backups/kb-landing-before.txt").write_text(before)
print("before:\n" + before.strip())
with open("/root/kbheadless/kb-landing-refresh.sql", "rb") as handle:
    rc = subprocess.run(args + ["--default-character-set=utf8mb4"], stdin=handle, capture_output=True)
print("refresh rc:", rc.returncode, rc.stderr.decode()[-200:] if rc.returncode else "")
after = subprocess.run(args + ["-e", "SELECT a.locale, a.status, CHAR_LENGTH(a.body_html), a.body_html LIKE '%ultra%' AS has_ultra, a.body_html LIKE '%Single%' AS has_mode FROM knowledgebase_articles a JOIN knowledgebase_topics t ON t.id=a.topic_id WHERE t.topic_key='getting-started-five-minute-overview' ORDER BY a.locale;"], capture_output=True, text=True).stdout
print("after:\n" + after.strip())
PY
