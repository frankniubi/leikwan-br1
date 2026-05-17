#!/usr/bin/env bash
set -euo pipefail
cd /mnt/c/Users/frankniubi/Downloads/leikwan-br1
PANEL=root@10.0.10.196
PANEL_PASS='Frank1212.'
SLAVE_HOST='47.116.11.144'
SLAVE_PASS='@XwDQ6H5mgMXbrGD'
SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o PreferredAuthentications=password -o PubkeyAuthentication=no -o ConnectTimeout=10)

echo '=== local source checks ==='
python3 -m py_compile slave-agent/slave_agent.py
sha256sum slave-agent/slave_agent.py master/src/Support/ProvisioningService.php

echo '=== sync panel ProvisioningService + slave agent artifact ==='
sshpass -p "$PANEL_PASS" scp "${SSH_OPTS[@]}" master/src/Support/ProvisioningService.php "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Support/ProvisioningService.php" >/dev/null
sshpass -p "$PANEL_PASS" scp "${SSH_OPTS[@]}" slave-agent/slave_agent.py "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/slave-agent/slave_agent.py" >/dev/null
sshpass -p "$PANEL_PASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'set -euo pipefail
  cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
  php -l master/src/Support/ProvisioningService.php
  python3 -m py_compile slave-agent/slave_agent.py
  sha256sum master/src/Support/ProvisioningService.php slave-agent/slave_agent.py
  grep -n "AGENT_VERSION" slave-agent/slave_agent.py | head -1
'

echo '=== sync slave agent to endpoint 316 and restart ==='
sshpass -p "$SLAVE_PASS" scp "${SSH_OPTS[@]}" slave-agent/slave_agent.py root@$SLAVE_HOST:/usr/local/bin/leikwan-slave-agent.py.tmp >/dev/null
sshpass -p "$SLAVE_PASS" ssh "${SSH_OPTS[@]}" root@$SLAVE_HOST 'set -euo pipefail
  install -m 0755 /usr/local/bin/leikwan-slave-agent.py.tmp /usr/local/bin/leikwan-slave-agent.py
  rm -f /usr/local/bin/leikwan-slave-agent.py.tmp
  python3 -m py_compile /usr/local/bin/leikwan-slave-agent.py
  sha256sum /usr/local/bin/leikwan-slave-agent.py
  grep -n "AGENT_VERSION" /usr/local/bin/leikwan-slave-agent.py | head -1
  systemctl stop leikwan-slave-update-*.service 2>/dev/null || true
  systemctl reset-failed leikwan-slave-update-*.service 2>/dev/null || true
  systemctl restart leikwan-slave-agent.service
  sleep 2
  systemctl is-active leikwan-slave-agent.service
  systemctl status leikwan-slave-agent.service --no-pager -l | sed -n "1,18p"
'

echo '=== queue republish for node owning endpoint 316 ==='
cat >/tmp/codex_republish_node316.php <<'PHP'
<?php
declare(strict_types=1);
require_once __DIR__ . '/master/src/Config.php';
require_once __DIR__ . '/master/src/Database/Database.php';
require_once __DIR__ . '/master/src/Support/ProvisioningService.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/master/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) require_once $path;
});
use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\ProvisioningService;
$config = new Config(__DIR__ . '/master/.env');
$db = new Database($config);
$pdo = $db->pdo();
$row = $pdo->query("SELECT se.id AS endpoint_id,se.node_id,n.public_ip,n.name,f.id AS forward_id FROM service_endpoints se INNER JOIN nodes n ON n.id=se.node_id INNER JOIN forwards f ON f.id=se.forward_id WHERE se.id=316 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$row) { fwrite(STDERR, "endpoint 316 not found\n"); exit(1); }
echo "endpoint316=" . json_encode($row, JSON_UNESCAPED_SLASHES) . "\n";
$prov = new ProvisioningService($config, $db);
$prov->republishActiveEndpointsForNode((int)$row['node_id']);
$count = $pdo->query("SELECT COUNT(*) FROM provision_tasks WHERE node_id=".(int)$row['node_id']." AND status='pending'")->fetchColumn();
echo "pending_for_node=".$count."\n";
$rows = $pdo->query("SELECT id,node_id,action,service_id,forward_id,endpoint_id,status,created_at,LEFT(payload_json,500) payload_json FROM provision_tasks WHERE node_id=".(int)$row['node_id']." AND (forward_id=47 OR endpoint_id=316) ORDER BY id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) echo json_encode($r, JSON_UNESCAPED_SLASHES)."\n";
PHP
sshpass -p "$PANEL_PASS" scp "${SSH_OPTS[@]}" /tmp/codex_republish_node316.php "$PANEL:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/codex_republish_node316.php" >/dev/null
sshpass -p "$PANEL_PASS" ssh "${SSH_OPTS[@]}" "$PANEL" 'set -euo pipefail
  cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
  php codex_republish_node316.php
  rm -f codex_republish_node316.php
'

echo '=== wait agent consume publish and inspect endpoint 316 runtime ==='
sleep 8
sshpass -p "$SLAVE_PASS" ssh "${SSH_OPTS[@]}" root@$SLAVE_HOST 'set +e
  svc=wstunnel-svc-2-316
  echo date=$(date -Is)
  grep -n "AGENT_VERSION" /usr/local/bin/leikwan-slave-agent.py | head -1
  systemctl is-active leikwan-slave-agent.service
  systemctl is-active "$svc.service"
  echo --unit--
  sed -n "1,80p" "/etc/systemd/system/$svc.service"
  echo --metadata--
  python3 - <<PY
import json, pathlib
p=pathlib.Path("/var/lib/leikwan-slave/services/wstunnel-svc-2-316.json")
d=json.loads(p.read_text())
for k in ["service_name","endpoint_id","backend_port","target","relay_target","ingress_protocol","backend_protocol","backend_mode","path_prefix","route_key","selected_cf_ip","cf_mode"]:
    print(f"{k}={d.get(k)!r}")
PY
  echo --route--
  python3 - <<PY
import json
p="/var/lib/leikwan-slave/listeners/routes-7000.json"
d=json.load(open(p))
for r in d.get("routes",[]):
  if r.get("route_key")=="07289d1f5ed25399" or r.get("path_prefix")=="07289d1f5ed25399": print(r)
PY
  echo --process--
  ps -eo pid,etimes,cmd | grep -E "$svc|leikwan-ingress mux|leikwan-ingress backend|wstunnel client|wstunnel server" | grep -v grep | sed -n "1,120p"
  echo --logs--
  journalctl -u leikwan-slave-agent.service -u "$svc.service" --since "-2 min" --no-pager -o short-iso | tail -120
'
