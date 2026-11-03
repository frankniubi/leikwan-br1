<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (str_starts_with($class, $prefix)) {
        require_once __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

$config = new Leikwan\Master\Config('/nonexistent');
$db = new Leikwan\Master\Database\Database($config);
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->setPdo($pdo);
$pdo->exec('CREATE TABLE clients (id INTEGER, group_id INTEGER);
CREATE TABLE services (id INTEGER, status TEXT, traffic_limit_gb INTEGER,
 billing_cycle_traffic_used_bytes INTEGER, monthly_included_traffic_gb INTEGER,
 billing_mode TEXT, expire_at TEXT);
CREATE TABLE forwards (id INTEGER, service_id INTEGER, line_client_id INTEGER,
 line_client_group_id INTEGER, line_client_group_ids_json TEXT);
CREATE TABLE ultra_usage_events (client_id INTEGER, device_hash TEXT, forward_id INTEGER,
 policy_generation TEXT, sequence_no INTEGER, bytes_in INTEGER, bytes_out INTEGER, created_at TEXT,
 UNIQUE(client_id,device_hash,forward_id,policy_generation,sequence_no));
INSERT INTO clients VALUES(3,0);
INSERT INTO services VALUES(30,"active",0,0,0,"metered",NULL);
INSERT INTO forwards VALUES(170,30,3,0,NULL);');
$entry = [
    'client_id' => 3, 'device_hash' => hash('sha256', 'device-test'), 'forward_id' => 170,
    'policy_generation' => str_repeat('a', 64), 'sequence_no' => 42,
    'bytes_in' => 978, 'bytes_out' => 206, 'created_at' => gmdate('Y-m-d H:i:s'),
];
$db->insert('ultra_usage_events', $entry);
$service = new Leikwan\Master\Support\ProvisioningService($config, $db);
$usage = ['forward_id' => 170, 'sequence' => 42, 'policy_generation' => str_repeat('a', 64),
    'bytes_in' => 978, 'bytes_out' => 206];
for ($i = 0; $i < 3; $i++) {
    if ($service->recordUltraUsage(3, 'device-test', $usage) !== null) {
        throw new RuntimeException('duplicate retry should be acknowledged without a policy action');
    }
}
if ((int) $pdo->query('SELECT COUNT(*) FROM ultra_usage_events')->fetchColumn() !== 1) {
    throw new RuntimeException('retry was counted twice');
}
$pdo->exec('DROP TABLE ultra_usage_events');
try {
    $service->recordUltraUsage(3, 'device-test', $usage);
    throw new RuntimeException('non-duplicate storage failures must remain retryable');
} catch (PDOException $expected) {
}
echo "PASS duplicate usage retry acknowledged; no double accounting; storage failures propagated\n";
