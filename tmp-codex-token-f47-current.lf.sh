#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
PANEL=root@10.0.10.196
PPASS='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=12)
cat >/tmp/codex_decode_f47_token.php <<'PHP'
<?php
require 'master/src/Config.php'; require 'master/src/Database/Database.php';
use Leikwan\Master\Config; use Leikwan\Master\Database\Database;
$pdo=(new Database(new Config('master/.env')))->pdo();
$row=$pdo->query("SELECT f.id,f.target_host,f.target_port,f.backend_mode,sp.sidecar_host,sp.bond_listen_port,sp.token_secret FROM forwards f LEFT JOIN sidecar_points sp ON sp.id=f.bond_sidecar_point_id WHERE f.id=47")->fetch(PDO::FETCH_ASSOC);
$payload=['target_host'=>$row['target_host'],'target_port'=>(int)$row['target_port'],'target_protocol'=>'tcp','service_id'=>2,'forward_id'=>47,'issued_at'=>time()-30,'expires_at'=>time()+3600];
if (($row['backend_mode'] ?? '')==='cf_mode' && in_array($payload['target_host'], [$row['sidecar_host'] ?? '', $row['bond_remote_host'] ?? ''], true)) { $payload['target_host']='127.0.0.1'; }
$raw=json_encode($payload, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
$b64=function($s){return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');};
$tok=$b64($raw).'.'.$b64(hash_hmac('sha256',$raw,(string)$row['token_secret'],true));
echo json_encode(['row'=>$row,'payload'=>$payload,'token'=>$tok], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
PHP
sshpass -p "$PPASS" scp "${SSH_OPTS[@]}" /tmp/codex_decode_f47_token.php "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/codex_decode_f47_token.php" >/dev/null
sshpass -p "$PPASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 && php codex_decode_f47_token.php && rm -f codex_decode_f47_token.php'
