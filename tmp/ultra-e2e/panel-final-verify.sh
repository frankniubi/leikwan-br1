php /root/kbheadless/verify2.php 2>&1 | tail -8
echo "--- served versions ---"
curl -sS -A LeikwanBridge/1.0 https://v3.leikwanhost.com/artifacts/versions.json > /tmp/v.json
python3 - <<'PY'
import json
d = json.load(open('/tmp/v.json'))
print('mac app     ', d['macos_client_app_arm64']['version'])
print('mac update  ', d['macos_client_update_arm64']['version'])
print('headless win', d['windows_headless_client']['version'], d['windows_headless_client']['sha256'][:16])
print('mac cli ver ', d['macos_headless_installer']['version'])
PY
echo "--- knowledgebase CLI text ---"
python3 - <<'PY'
import pathlib, subprocess
env = {}
for line in pathlib.Path("/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/.env").read_text().splitlines():
    line = line.strip()
    if line and not line.startswith("#") and "=" in line:
        k, v = line.split("=", 1); env[k.strip()] = v.strip().strip('"').strip("'")
args = ["mysql", "-h", env.get("DB_HOST", "127.0.0.1"), "-P", str(env.get("DB_PORT", "3306")),
        "-u", env.get("DB_USER", "root"), "-p" + env.get("DB_PASSWORD", ""), env.get("DB_NAME", "")]
out = subprocess.run(args + ["-e", "SELECT a.locale, a.body_html LIKE '%leikwan update%' AS has_cli, CHAR_LENGTH(a.body_html) len FROM knowledgebase_articles a JOIN knowledgebase_topics t ON t.id=a.topic_id WHERE t.topic_key='no-gui-clients' ORDER BY a.locale;"], capture_output=True, text=True).stdout
print(out.strip())
PY
