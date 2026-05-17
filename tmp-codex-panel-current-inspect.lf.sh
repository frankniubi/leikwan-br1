#!/usr/bin/env bash
set -euo pipefail
PANEL='root@10.0.10.196'
PASS='Frank1212.'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10)
sshpass -p "$PASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'set +e
  cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3 || exit 1
  echo "=== panel time/git ==="; hostname; date -Is; php -v | head -1
  echo "=== wss mux hash snippet ==="; ls -l sidecar-agent/leikwan_wss_mux.py; sha256sum sidecar-agent/leikwan_wss_mux.py; grep -n "backend_port\|backend_target\|target" sidecar-agent/leikwan_wss_mux.py | head -80
  echo "=== sidecar db state likely ===";
  php -r '\''require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $kernel=$app->make(Illuminate\Contracts\Console\Kernel::class); $kernel->bootstrap(); $db=$app->make("db")->connection()->getPdo(); foreach(["sidecar_points","nodes","forwards","forward_combinations"] as $t){echo "---$t\n"; try{$rows=$db->query("SELECT * FROM `$t` LIMIT 3")->fetchAll(PDO::FETCH_ASSOC); foreach($rows as $r){foreach($r as $k=>$v){if(preg_match("/(password|secret|token|private_key)/i",$k))$r[$k]="<redacted>";} echo json_encode($r, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";}}catch(Throwable $e){echo $e->getMessage()."\n";}}'\''
' 
