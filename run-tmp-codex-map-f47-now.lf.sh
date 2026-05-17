#!/usr/bin/env bash
set -euo pipefail
CLIENT=114.80.33.50
CPASS='Frank20021212.'
PANEL=10.0.10.196
PPASS='Frank1212.'
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10 -o ConnectionAttempts=1)
echo "=== client map $(date -Is) ==="
sshpass -p "$CPASS" ssh "${SSH[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
echo "host=$(hostname) now=$(date -Is)"
echo "agent=$(systemctl is-active leikwan-client-agent.service 2>/dev/null || true)/$(systemctl is-enabled leikwan-client-agent.service 2>/dev/null || true)"
for u in leikwan-client-47.service leikwan-client-47-onegood.service; do echo "$u $(systemctl is-active $u 2>/dev/null || true)/$(systemctl is-enabled $u 2>/dev/null || true) pid=$(systemctl show -p MainPID --value $u 2>/dev/null || true)"; done
echo '-- listeners --'; ss -ltnp | grep -E ':(11035|22740|21739|21740|39559|32501)\b' || true
echo '-- onegood token decoded --'
python3 - <<'PY'
import base64,json,re,subprocess
try: ps=subprocess.check_output(['ps','-eo','pid,args'],text=True,errors='ignore')
except Exception as e: print('ps_err',e); ps=''
for line in ps.splitlines():
    if 'leikwan-bond client' in line and ':11035' in line:
        print(line[:1000])
        m=re.search(r'--session-token\s+(\S+)', line)
        if m:
            raw=m.group(1).split('.')[0]; raw += '='*((4-len(raw)%4)%4)
            print(json.dumps(json.loads(base64.urlsafe_b64decode(raw)), indent=2))
PY
echo '-- mptcp f47 summary --'
python3 - <<'PY'
import json, pathlib
p=pathlib.Path('/etc/leikwan/mptcp-forward-47.json')
if p.exists():
 d=json.loads(p.read_text())
 print(json.dumps({k:d.get(k) for k in ['forward_id','listen_port','bond_mode','remote_host','remote_port','relay_host','relay_port','direct_legs']}, indent=2))
 eps=d.get('endpoints') or []
 print('endpoints',len(eps))
 for e in eps: print(json.dumps({k:e.get(k) for k in ['endpoint_id','display_host','host','port','route_key','auth_token','relay_host','relay_port','one_leg_rtt_ms','latency_ms']}, ensure_ascii=False))
else: print('missing')
PY
echo '-- recent onegood counts 10m --'
for pat in 'invalid bond frame magic' 'failed to read bond frame header' 'timed out waiting for bond hello-ack' 'bond client TCP transport session established' 'entering cooldown' 'local_read_failed' 'local_write_failed' 'Invalid status code: 520'; do c=$(journalctl -u leikwan-client-47-onegood.service --since '-10 min' --no-pager 2>/dev/null | grep -c "$pat" || true); echo "$pat=$c"; done
REMOTE

echo "=== panel f47/sidecar5 $(date -Is) ==="
sshpass -p "$PPASS" ssh "${SSH[@]}" root@$PANEL 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php' <<'PHP'
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
function rows($pdo,$sql,$args=[]){$st=$pdo->prepare($sql);$st->execute($args);return $st->fetchAll(PDO::FETCH_ASSOC);} 
foreach(['sidecar_points','forwards','services','forward_combinations','bond_leg_reputation','nodes'] as $t){try{$pdo->query('SELECT 1 FROM `'.$t.'` LIMIT 1'); echo "table:$t ok\n";}catch(Throwable $e){echo "table:$t ERR ".$e->getMessage()."\n";}}
echo "-- sidecar5 --\n"; foreach(rows($pdo,'SELECT id,name,sidecar_host,listen_port,bond_listen_port,mptcp_listen_port,cf_listen_port,bind_host,agent_version,agent_config_json,last_heartbeat_at,last_status,last_error,is_active FROM sidecar_points WHERE id=5') as $r){echo json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";}
echo "-- forward47 --\n"; foreach(rows($pdo,'SELECT f.id,f.service_id,f.client_id,f.forward_combination_id,f.path_prefix,f.target_host,f.target_port,f.transport_mode,f.bond_sidecar_point_id,f.bond_remote_host,f.bond_remote_port,f.backend_mode,f.client_mode,f.enabled,s.status service_status FROM forwards f LEFT JOIN services s ON s.id=f.service_id WHERE f.id=47') as $r){echo json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";}
echo "-- cf forwards point5 --\n"; foreach(rows($pdo,'SELECT id,path_prefix,target_host,target_port,transport_mode,bond_remote_host,bond_remote_port,backend_mode,client_mode,enabled FROM forwards WHERE bond_sidecar_point_id=5 ORDER BY id') as $r){echo json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";}
echo "-- possible route/auth tables --\n"; foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $tr){$t=$tr[0]; if(stripos($t,'publish')!==false||stripos($t,'candidate')!==false||stripos($t,'runtime')!==false||stripos($t,'endpoint')!==false||stripos($t,'bond')!==false||stripos($t,'node')!==false) echo $t."\n";}
?>
PHP