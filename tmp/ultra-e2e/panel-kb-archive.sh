cd /root/kbheadless
python3 - <<'PY'
import pathlib, subprocess
env = {}
for line in pathlib.Path("/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/.env").read_text().splitlines():
    line = line.strip()
    if line and not line.startswith("#") and "=" in line:
        k, v = line.split("=", 1); env[k.strip()] = v.strip().strip('"').strip("'")
args = ["mysql", "-h", env.get("DB_HOST", "127.0.0.1"), "-P", str(env.get("DB_PORT", "3306")),
        "-u", env.get("DB_USER", "root"), "-p" + env.get("DB_PASSWORD", ""), env.get("DB_NAME", "")]

def q(sql):
    return subprocess.run(args + ["-e", sql], capture_output=True, text=True).stdout.strip()

retired = "'connection-modes-single-race-bond','bond-sidecar-setup'"
before = q("SELECT t.topic_key, a.locale, a.status FROM knowledgebase_topics t JOIN knowledgebase_articles a ON a.topic_id=t.id WHERE t.topic_key IN (%s) ORDER BY t.topic_key, a.locale;" % retired)
pathlib.Path("/root/leikwan-br1-backups/kb-archive-before.txt").write_text(before)
print("before:\n" + before)
print("published before:", q("SELECT COUNT(DISTINCT t.id) FROM knowledgebase_topics t JOIN knowledgebase_articles a ON a.topic_id=t.id WHERE a.status='published';"))
print("update rc:", subprocess.run(args + ["-e", "UPDATE knowledgebase_articles a JOIN knowledgebase_topics t ON t.id=a.topic_id SET a.status='draft', a.updated_at=CURRENT_TIMESTAMP WHERE t.topic_key IN (%s);" % retired], capture_output=True, text=True).returncode)
print("after:\n" + q("SELECT t.topic_key, a.locale, a.status FROM knowledgebase_topics t JOIN knowledgebase_articles a ON a.topic_id=t.id WHERE t.topic_key IN (%s) ORDER BY t.topic_key, a.locale;" % retired))
print("published after:", q("SELECT COUNT(DISTINCT t.id) FROM knowledgebase_topics t JOIN knowledgebase_articles a ON a.topic_id=t.id WHERE a.status='published';"))
print("still listed:\n" + q("SELECT topic_key FROM knowledgebase_topics t JOIN knowledgebase_articles a ON a.topic_id=t.id WHERE a.status='published' GROUP BY t.id ORDER BY t.sort_order;"))
PY
