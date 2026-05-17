#!/usr/bin/env bash
set -euo pipefail
PASS_PANEL='Frank1212.'
PASS_CLIENT='Frank20021212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10)
cat >/tmp/codex_make_f47_token.php <<'PHP'
<?php
require 'master/src/Config.php';
require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
$target=$argv[1] ?? '127.0.0.1';
$port=(int)($argv[2] ?? 5201);
$row=$pdo->query("SELECT f.id, f.service_id, sp.token_secret FROM forwards f JOIN sidecar_points sp ON sp.id=f.bond_sidecar_point_id WHERE f.id=47 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if(!$row){fwrite(STDERR,"missing f47\n"); exit(2);} 
$issued=intdiv(time(),3600)*3600; $expires=$issued+7*24*3600;
$payload=json_encode(['target_host'=>$target,'target_port'=>$port,'target_protocol'=>'tcp','service_id'=>(int)$row['service_id'],'forward_id'=>(int)$row['id'],'issued_at'=>$issued,'expires_at'=>$expires], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
$sig=hash_hmac('sha256',$payload,(string)$row['token_secret'],true);
$enc=function($s){return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');};
echo $enc($payload).'.'.$enc($sig)."\n";
PHP
sshpass -p "$PASS_PANEL" scp "${SSH_OPTS[@]}" /tmp/codex_make_f47_token.php root@10.0.10.196:/tmp/codex_make_f47_token.php >/dev/null
TOK127=$(sshpass -p "$PASS_PANEL" ssh "${SSH_OPTS[@]}" root@10.0.10.196 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php /tmp/codex_make_f47_token.php 127.0.0.1 5201')
TOK185=$(sshpass -p "$PASS_PANEL" ssh "${SSH_OPTS[@]}" root@10.0.10.196 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php /tmp/codex_make_f47_token.php 185.220.238.143 5201')
cat >/tmp/codex_loopback_token_client.sh <<'RS'
set +euo pipefail
SNI=nrt1.nbwly.com
PATHP=07289d1f5ed25399
IP=162.159.39.6
TOK127='__TOK127__'
TOK185='__TOK185__'
freeport(){ python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()'; }
run_case(){
  local name="$1" token="$2"
  local lp bp
  lp=$(freeport)
  bp=$(freeport)
  local wlog=/tmp/codex-loop-${name}-wst.log blog=/tmp/codex-loop-${name}-bond.log ilog=/tmp/codex-loop-${name}-iperf.log
  rm -f "$wlog" "$blog" "$ilog"
  /usr/local/bin/wstunnel client -L tcp://127.0.0.1:${lp}:127.0.0.1:8899 --tls-sni-override "$SNI" --http-headers "Host: $SNI" --http-upgrade-path-prefix "$PATHP" wss://${IP}:2053 >/dev/null 2>"$wlog" & wpid=$!
  sleep 0.5
  /usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:${bp} --legs "tcp://127.0.0.1:${lp}?priority=10&display_host=self_leg&role=primary" --session-token "$token" --chunk-size 131072 --max-window-bytes 16777216 --retransmit-ms 300 --connect-timeout-ms 3000 --join-timeout-ms 400 >"$blog" 2>&1 & bpid=$!
  sleep 2
  timeout 12 iperf3 -c 127.0.0.1 -p ${bp} -R -b 300M -t 4 >"$ilog" 2>&1
  irc=$?
  echo "===CASE ${name} bp=${bp} lp=${lp} iperf_rc=${irc}==="
  grep -E 'receiver|sender|bits/sec|error|connected' "$ilog" | tail -12 || cat "$ilog"
  echo '---bond---'
  grep -E 'established|invalid_token|failed to connect|upstream target|Connection refused|recv_reset|no bond legs|early eof' "$blog" | tail -20 || true
  echo '---wst---'
  grep -E 'Invalid status code|failed to do websocket|ERROR|WARN' "$wlog" | tail -10 || true
  kill "$bpid" "$wpid" 2>/dev/null; wait "$bpid" "$wpid" 2>/dev/null
}
run_case target127 "$TOK127"
run_case target185 "$TOK185"
RS
python3 - <<PY
from pathlib import Path
p=Path('/tmp/codex_loopback_token_client.sh')
s=p.read_text()
s=s.replace('__TOK127__', '''$TOK127''').replace('__TOK185__', '''$TOK185''')
p.write_text(s)
PY
sshpass -p "$PASS_CLIENT" scp "${SSH_OPTS[@]}" /tmp/codex_loopback_token_client.sh root@114.80.33.50:/tmp/codex_loopback_token_client.sh >/dev/null
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'bash /tmp/codex_loopback_token_client.sh'
