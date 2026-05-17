set -euo pipefail
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
printf '=== current sidecar config via heartbeat token ===\n'
php -r '
require "master/src/Config.php"; require "master/src/Database/Database.php";
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config("master/.env")))->pdo();
$r=$pdo->query("SELECT agent_token FROM sidecar_points WHERE id=5")->fetch(PDO::FETCH_ASSOC); echo $r["agent_token"],"\n";
' > /tmp/sidecar5token
TOKEN=$(cat /tmp/sidecar5token)
python3 - "$TOKEN" <<'PY'
import json, urllib.request, sys
payload={'status':'ok','version':'2026.05.13.1','supports_mptcp':True,'wireguard_public_key':'x','wireguard_listen_port':14969,'wireguard_fallback_tcp_port':24969}
req=urllib.request.Request('http://127.0.0.1/api/sidecar/heartbeat', data=json.dumps(payload).encode(), method='POST', headers={'Host':'v3.leikwanhost.com','Content-Type':'application/json','Accept':'application/json','Authorization':'Bearer '+sys.argv[1]})
try:
 data=urllib.request.urlopen(req, timeout=20).read().decode(); print(data[:5000])
except Exception as e:
 print('ERR',repr(e));
 if hasattr(e,'read'): print(e.read().decode(errors='ignore'))
PY
