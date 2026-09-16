#!/usr/bin/env python3
"""Generate the guarded knowledgebase refresh for the no-GUI guide (CLI section)."""
import hashlib, pathlib

ROOT = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1")
manifest = None
import re
text = (ROOT / "master/resources/knowledgebase/topics.php").read_text(encoding="utf-8")
# The manifest is PHP; evaluate it the same way the seed script does.
import subprocess, json, sys
php = r"C:\Program Files\PHP\current\php.exe"
dump = subprocess.run([php, "-r",
    "$t = require '" + str(ROOT / "master/resources/knowledgebase/topics.php").replace("\\", "/") + "';"
    " echo json_encode($t['no-gui-clients']);"],
    capture_output=True, text=True)
if dump.returncode != 0:
    raise SystemExit("could not read the manifest: " + dump.stderr)
topic = json.loads(dump.stdout)

def sql_string(value):
    return "'" + value.replace("'", "''") + "'"

statements = ["-- Guarded refresh of the no-GUI guide (adds the leikwan CLI section)."]
for locale, article in topic["articles"].items():
    body = (ROOT / "master/resources/knowledgebase" / article["body"]).read_text(encoding="utf-8")
    statements.append(
        "UPDATE knowledgebase_articles\n"
        "SET title = %s,\n    excerpt = %s,\n    body_html = %s,\n    status = 'published',\n"
        "    published_at = COALESCE(published_at, CURRENT_TIMESTAMP),\n    updated_at = CURRENT_TIMESTAMP\n"
        "WHERE topic_id IN (SELECT id FROM knowledgebase_topics WHERE topic_key = 'no-gui-clients')\n"
        "  AND locale = %s;" % (
            sql_string(article["title"]), sql_string(article["excerpt"]), sql_string(body), sql_string(locale)))
out = ROOT / ".build/kb-1034-refresh.sql"
out.write_text("\n\n".join(statements) + "\n", encoding="utf-8")
print("wrote", out, out.stat().st_size, "bytes")
