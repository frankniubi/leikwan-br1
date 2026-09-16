import json, pathlib, subprocess
ROOT = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1")
php = r"C:\Program Files\PHP\current\php.exe"
topic_key = "getting-started-five-minute-overview"
dump = subprocess.run([php, "-r",
    "$t = require '" + str(ROOT / "master/resources/knowledgebase/topics.php").replace("\\", "/") + "';"
    " echo json_encode($t['" + topic_key + "']);"], capture_output=True, text=True)
topic = json.loads(dump.stdout)
def s(v): return "'" + v.replace("'", "''") + "'"
stmts = ["-- Guarded refresh: %s (user-language, ultra-only)." % topic_key]
for locale, article in topic["articles"].items():
    body = (ROOT / "master/resources/knowledgebase" / article["body"]).read_text(encoding="utf-8")
    stmts.append(
        "UPDATE knowledgebase_articles SET title=%s, excerpt=%s, body_html=%s, status='published',"
        " updated_at=CURRENT_TIMESTAMP WHERE topic_id IN (SELECT id FROM knowledgebase_topics WHERE topic_key='%s')"
        " AND locale=%s;" % (s(article["title"]), s(article["excerpt"]), s(body), topic_key, s(locale)))
out = ROOT / ".build/kb-landing-refresh.sql"
out.write_text("\n\n".join(stmts) + "\n", encoding="utf-8")
print("wrote", out.name, out.stat().st_size, "bytes")
