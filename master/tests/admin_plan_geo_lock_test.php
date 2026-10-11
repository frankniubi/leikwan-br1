<?php
declare(strict_types=1);

function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
function assertContains(string $source, string $needle, string $message): void { if (!str_contains($source, $needle)) fail($message . ': ' . $needle); }

$admin = (string) file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
$provisioning = (string) file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
$schema = (string) file_get_contents(__DIR__ . '/../migrations/schema.sql');

assertContains($admin, 'name="geo_lock_enabled"', 'plan form must expose geo lock switch');
assertContains($admin, 'geo_lock_enabled', 'admin plan controller must pass geo lock field');
assertContains($provisioning, 'geo_lock_enabled', 'provisioning savePlan must persist geo lock field');
assertContains($provisioning, 'geo_lock_enabled = :geo_lock_enabled', 'plan UPDATE must persist geo lock field');
assertContains($provisioning, "in_array(\$geoLockValue, ['1', 'true', 'yes', 'on'], true)", 'geo lock switch must parse false string as disabled');
assertContains($schema, 'geo_lock_enabled TINYINT(1) NOT NULL DEFAULT 0', 'schema geo lock default');

// No released client answers the location check yet: the switches stay closed
// until ACCESS_POLICY_LOCATION_CLIENTS_READY is set.
assertContains($admin, "getBool('ACCESS_POLICY_LOCATION_CLIENTS_READY', false)", 'geo lock must default to unavailable');
assertContains($admin, "(\$this->locationPolicyAvailable() ? '' : ' disabled')", 'plan form must disable the geo lock switch while unavailable');
assertContains($admin, "'geo_lock_enabled' => \$this->locationPolicyAvailable() ? \$request->input('geo_lock_enabled', '0') : '0'", 'plan save must not enable geo lock while unavailable');
assertContains($admin, "!\$this->locationPolicyAvailable() && \$this->isGeographicRuleType(", 'geographic blacklist rules must be refused while unavailable');
assertContains($admin, "if (\$active && !\$this->locationPolicyAvailable())", 'geographic blacklist rules must not be re-activated while unavailable');
assertContains((string) file_get_contents(__DIR__ . '/../.env.example'), 'ACCESS_POLICY_LOCATION_CLIENTS_READY=0', 'env example must document the location switch');

require_once __DIR__ . '/../src/Support/AccessPolicyStore.php';
$store = (string) file_get_contents(__DIR__ . '/../src/Support/AccessPolicyStore.php');
$start = strpos($store, 'function hasActiveGeographicRules');
$sql = $start === false ? '' : substr($store, $start, 700);
foreach (\Leikwan\Master\Support\AccessPolicyStore::GEOGRAPHIC_ENTITY_TYPES as $type) {
    assertContains($sql, "'" . $type . "'", 'GEOGRAPHIC_ENTITY_TYPES must match hasActiveGeographicRules');
}
if (preg_match_all("/'([a-z_]+)'/", substr($sql, 0, (int) strpos($sql, 'LIMIT 1')), $matches) > 0
    && array_diff($matches[1], \Leikwan\Master\Support\AccessPolicyStore::GEOGRAPHIC_ENTITY_TYPES) !== []) {
    fail('hasActiveGeographicRules lists a type missing from GEOGRAPHIC_ENTITY_TYPES');
}

echo "ok\n";
