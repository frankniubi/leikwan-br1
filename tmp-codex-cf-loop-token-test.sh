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
$issued=intdiv(time(),3600)*3600; $expires=$issued+7*24*3600;
$payload=json_encode(['target_host'=>$target,'target_port'=>$port,'target_protocol'=>'tcp','service_id'=>(int)$row['service_id'],'forward_id'=>(int)$row['id'],'issued_at'=>$issued,'expires_at'=>$expires], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
$sig=hash_hmac('sha256',$payload,(string)$row['token_secret'],true);
$enc=function($s){return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');};
echo $enc($payload).'.'.$enc($sig)."\n";
PHP
sshpass -p "$PASS_PANEL" scp "${SSH_OPTS[@]}" /tmp/codex_make_f47_token.php root@10.0.10.196:/tmp/codex_make_f47_token.php >/dev/null
TOK8899=$(sshpass -p "$PASS_PANEL" ssh "${SSH_OPTS[@]}" root@10.0.10.196 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php /tmp/codex_make_f47_token.php 127.0.0.1 8899')
cat >/tmp/codex_cf_loop_token_client.sh <<'RS'
set +euo pipefail
SNI=nrt1.nbwly.com
PATHP=07289d1f5ed25399
IP=162.159.39.4
TOKEN='__TOKEN__'
freeport(){ python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()'; }
lp=$(freeport); bp=$(freeport)
wlog=/tmp/codex-cfloop-wst.log; blog=/tmp/codex-cfloop-bond.log; ilog=/tmp/codex-cfloop-iperf.log
rm -f "$wlog" "$blog" "$ilog"
/usr/local/bin/wstunnel client -L tcp://127.0.0.1:${lp}:127.0.0.1:8899 --tls-sni-override "$SNI" --http-headers "Host: $SNI" --http-upgrade-path-prefix "$PATHP" wss://${IP}:2053 >/dev/null 2>"$wlog" & wpid=$!
sleep 0.8
/usr/local/bin/leikwan-bond client --protocol tcp --listen 127.0.0.1:${bp} --legs "tcp://127.0.0.1:${lp}?priority=10&display_host=self_leg&role=primary" --session-token "$TOKEN" --chunk-size 131072 --max-window-bytes 16777216 --retransmit-ms 300 --connect-timeout-ms 3000 --join-timeout-ms 400 >"$blog" 2>&1 & bpid=$!
sleep 2
python3 - "$bp" <<'PY'
import socket, sys, time
bp=int(sys.argv[1])
try:
    s=socket.create_connection(('127.0.0.1',bp), timeout=4)
    s.settimeout(2)
    s.sendall(b'PING')
    try: data=s.recv(16); print('recv', data)
    except Exception as e: print('recv_exc', type(e).__name__)
    s.close()
except Exception as e:
    print('connect_err', repr(e))
PY
echo '---bond---'; grep -E 'established|recv_reset|failed|upstream|invalid|early eof|no bond legs|recursive' "$blog" | tail -60 || true
echo '---wst---'; grep -E 'Invalid status code|failed|ERROR|WARN' "$wlog" | tail -30 || true
kill "$bpid" "$wpid" 2>/dev/null; wait "$bpid" "$wpid" 2>/dev/null
RS
python3 - <<PY
from pathlib import Path
p=Path('/tmp/codex_cf_loop_token_client.sh')
s=p.read_text().replace('__TOKEN__', '''$TOK8899''')
p.write_text(s)
PY
sshpass -p "$PASS_CLIENT" scp "${SSH_OPTS[@]}" /tmp/codex_cf_loop_token_client.sh root@114.80.33.50:/tmp/codex_cf_loop_token_client.sh >/dev/null
sshpass -p "$PASS_CLIENT" ssh "${SSH_OPTS[@]}" root@114.80.33.50 'bash /tmp/codex_cf_loop_token_client.sh'
