set -u
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 || exit 1
printf '=== nginx/vhost ===\n'
grep -R "v3.leikwanhost\|server_name\|root .*leikwan" -n /www/server/panel/vhost/nginx /etc/nginx/sites-enabled /etc/nginx/conf.d 2>/dev/null | head -80 || true
printf '=== panel app urls ===\n'
sed -n '1,120p' master/.env | grep -E 'APP_URL|AGENT_API_URL|ARTIFACT_BASE_URL' || true
printf '=== local host headers ===\n'
python3 - <<'PY'
import urllib.request
for host in ['v3.leikwanhost.com','www.v3.leikwanhost.com','panel.example.com']:
    url='http://127.0.0.1/artifacts/sidecar-agent.py'
    req=urllib.request.Request(url, headers={'Host':host})
    try:
        r=urllib.request.urlopen(req, timeout=10); data=r.read(80); print(host, r.status, r.geturl(), data)
    except Exception as e: print(host, repr(e))
PY
