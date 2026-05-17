#!/usr/bin/env bash
set -euo pipefail
SSH=(-T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12 -o ConnectionAttempts=1)
PANEL=10.0.10.196; PPASS='Frank1212.'
CLIENT=114.80.33.50; CPASS='Frank20021212.'
SLAVE=47.116.104.15; SPASS='U3I+sVL^%IXyqMX0'
SIDE=185.220.238.143; SIDEPASS='GK@Sp7z%T!s4*w5P'

echo '=== panel state ==='
sshpass -p "$PPASS" ssh "${SSH[@]}" root@$PANEL 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php' <<'PHP'
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
function rows($pdo,$sql,$args=[]){$st=$pdo->prepare($sql);$st->execute($args);return $st->fetchAll(PDO::FETCH_ASSOC);} 
foreach(rows($pdo,'SELECT f.id,f.line_client_id,f.client_mode,f.backend_mode,f.listen_port,f.path_prefix,f.target_host,f.target_port,f.bond_sidecar_point_id,f.bond_remote_host,f.bond_remote_port,fc.runtime_preference,fc.allow_private_self_leg,fc.self_leg_scope,fc.self_leg_role,fc.helper_group_cap,sp.id spid,sp.name spname,sp.sidecar_host,sp.bond_listen_port,sp.mptcp_listen_port,sp.cf_listen_port,sp.agent_version spver,sp.last_heartbeat_at sphb,sp.last_status spstatus,c.agent_version cver,c.last_sync_at csync,c.agent_config_json cconfig,sp.agent_config_json spconfig FROM forwards f LEFT JOIN forward_combinations fc ON fc.id=f.forward_combination_id LEFT JOIN sidecar_points sp ON sp.id=f.bond_sidecar_point_id LEFT JOIN clients c ON c.id=f.line_client_id WHERE f.id=47') as $r){echo json_encode($r,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";}
?>
PHP

echo '=== client state ==='
sshpass -p "$CPASS" ssh "${SSH[@]}" root@$CLIENT 'bash -s' <<'REMOTE'
set +e
echo host=$(hostname) date=$(date -Is)
echo agent=$(systemctl is-active leikwan-client-agent.service 2>/dev/null || true)/$(systemctl is-enabled leikwan-client-agent.service 2>/dev/null || true)
for f in /usr/local/bin/leikwan-client-agent.py /usr/local/bin/install_forward_runtime.py /usr/local/bin/leikwan-mptcp-client.py; do [ -e "$f" ] && sha256sum "$f"; done
python3 -m py_compile /usr/local/bin/leikwan-client-agent.py /usr/local/bin/install_forward_runtime.py /usr/local/bin/leikwan-mptcp-client.py 2>&1 || true
ls -l /etc/wireguard/lw-self-47.conf /etc/leikwan/mptcp-forward-47.json 2>/dev/null || true
if [ -f /etc/leikwan/mptcp-forward-47.json ]; then python3 - <<'PY'
import json
p='/etc/leikwan/mptcp-forward-47.json'
d=json.load(open(p))
print(json.dumps({k:d.get(k) for k in ['forward_id','listen_port','remote_host','remote_port','relay_host','relay_port','bond_mode','direct_legs','underlays','mptcp_endpoints']}, indent=2))
PY
fi
REMOTE

echo '=== sidecar state via slave ==='
sshpass -p "$SPASS" ssh "${SSH[@]}" root@$SLAVE "SIDE=$SIDE SIDEPASS='$SIDEPASS' bash -s" <<'OUTER'
set +e
sshpass -p "$SIDEPASS" ssh -T -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o PubkeyAcceptedAlgorithms=+ssh-rsa -o HostkeyAlgorithms=+ssh-rsa -o ConnectTimeout=12 -o ConnectionAttempts=1 root@$SIDE 'bash -s' <<'REMOTE'
set +e
echo host=$(hostname) date=$(date -Is)
for f in /usr/local/bin/leikwan-sidecar-agent.py /etc/wireguard/lw-self-47.conf /var/lib/leikwan-sidecar-5/wireguard/self-leg-clients/lw-self-47.conf; do [ -e "$f" ] && ls -l "$f" && sha256sum "$f"; done
python3 -m py_compile /usr/local/bin/leikwan-sidecar-agent.py 2>&1 || true
echo sidecar_agent=$(systemctl is-active leikwan-bond-sidecar-5-agent.service 2>/dev/null || systemctl is-active leikwan-sidecar-agent.service 2>/dev/null || true)
for u in wg-quick@lw-self-47.service leikwan-bond-sidecar-5-agent.service leikwan-sidecar-agent.service leikwan-mptcp-sidecar-5.service; do echo "$u $(systemctl is-active $u 2>/dev/null || true)/$(systemctl is-enabled $u 2>/dev/null || true)"; done
wg show lw-self-47 2>/dev/null || true
REMOTE
OUTER
