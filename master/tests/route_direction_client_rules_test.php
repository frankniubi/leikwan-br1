<?php
declare(strict_types=1);

use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\ClientRuleValidator;
use Leikwan\Master\Support\ProvisioningService;
use Leikwan\Master\Support\RouteDirectionService;

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        fail($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertThrows(callable $action, string $code, string $label): void
{
    try {
        $action();
    } catch (ApiException $exception) {
        assertSame($code, $exception->getMessage(), $label);
        return;
    }
    fail($label . ': expected ' . $code);
}

require __DIR__ . '/route_rule_test_helpers.php';

[$config, $db] = makeTestDb('route-direction-client-rules');
installRouteRuleTestSchema($db);
$now = gmdate('Y-m-d H:i:s');
$db->insert('service_plans', ['name' => 'Pro', 'product_code' => 'pro', 'max_client_rules_per_service_scope' => 2, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
$db->insert('services', ['user_id' => 9, 'plan_id' => 1, 'name' => 'Service A', 'whmcs_service_id' => 9001, 'status' => 'active', 'client_token' => 'tok', 'created_at' => $now, 'updated_at' => $now]);
$db->insert('services', ['user_id' => 10, 'plan_id' => 1, 'name' => 'Other user', 'whmcs_service_id' => 9002, 'status' => 'active', 'client_token' => 'tok2', 'created_at' => $now, 'updated_at' => $now]);
$capable = json_encode(['supports_route_direction_rules' => true, 'supports_exact_destination_rules' => true]);
$db->insert('clients', ['owner_user_id' => 9, 'group_id' => null, 'agent_token' => 'agent', 'agent_config_json' => $capable, 'name' => 'Client A', 'display_host' => 'client-a', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
$db->insert('clients', ['owner_user_id' => 10, 'group_id' => null, 'agent_token' => 'agent2', 'agent_config_json' => $capable, 'name' => 'Client B', 'display_host' => 'client-b', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
$db->insert('forwards', ['service_id' => 1, 'listen_port' => 11001, 'line_client_id' => 1, 'line_client_group_id' => null, 'enabled' => 1, 'route_rule_helper_only' => 1, 'created_at' => $now, 'updated_at' => $now]);

// ---------------------------------------------------------------- validator
$validator = new ClientRuleValidator($config, $db);
assertSame('93.184.216.34', $validator->normalizeDestinationIp(' 93.184.216.34 '), 'ipv4 normalization');
foreach (['01.2.3.4', '1.2.3', 'example.com', 'http://93.184.216.34'] as $ip) {
    assertThrows(fn() => $validator->normalizeDestinationIp($ip), 'invalid_destination_ip', 'invalid ip ' . $ip);
}
assertThrows(fn() => $validator->normalizeDestinationIp('2001:db8::1'), 'unsupported_destination_address_family', 'ipv6 rejected');
foreach (['10.0.0.1', '127.0.0.1', '169.254.1.1', '224.0.0.1', '192.168.1.1', '172.16.0.1', '100.64.0.1', '198.51.100.7', '203.0.113.9'] as $blocked) {
    assertThrows(fn() => $validator->normalizeDestinationIp($blocked), 'destination_blocked_by_policy', 'blocked ' . $blocked);
}
assertSame(443, $validator->normalizeDestinationPort('443'), 'port normalization');
foreach ([0, 65536, 'abc', '443.1', ''] as $port) {
    assertThrows(fn() => $validator->normalizeDestinationPort($port), 'invalid_destination_port', 'invalid port ' . var_export($port, true));
}
assertSame('93.184.216.34:443:tcp_udp', $validator->activeDuplicateKey('93.184.216.34', 443, true), 'duplicate key');
assertSame(null, $validator->activeDuplicateKey('93.184.216.34', 443, false), 'disabled rule has no duplicate key');

$base = [
    'service_active' => true, 'sync_version_matches' => true, 'direction_exists' => true, 'direction_allowed' => true,
    'direction_status' => 'available', 'enabled' => true, 'duplicate_enabled_exists' => false,
    'saved_count_after_write' => 1, 'max_rules' => 2, 'destination_ip' => '93.184.216.34', 'destination_port' => 443,
];
foreach ([
    ['service_not_active', ['service_active' => false, 'sync_version_matches' => false]],
    ['version_conflict', ['sync_version_matches' => false, 'destination_ip' => 'bad']],
    ['invalid_destination_ip', ['destination_ip' => 'bad', 'direction_allowed' => false]],
    ['direction_not_allowed', ['direction_allowed' => false, 'direction_status' => 'maintenance']],
    ['direction_unavailable', ['direction_status' => 'maintenance', 'duplicate_enabled_exists' => true]],
    ['duplicate_destination', ['duplicate_enabled_exists' => true, 'saved_count_after_write' => 9]],
    ['rule_quota_exceeded', ['saved_count_after_write' => 3]],
] as [$expected, $override]) {
    assertThrows(fn() => $validator->validateWrite($override + $base), $expected, 'precedence ' . $expected);
}

// ---------------------------------------------------------------- directions + entitlements
$service = new RouteDirectionService($config, $db, $validator);
$saved = $service->saveDirection([
    'id' => ' DIR_SH_JP_1 ',
    'label' => '沪日1',
    'status' => 'available',
    'admin_notes' => 'operator-only note',
    'sort_order' => 10,
    'realization' => [
        'binding_state' => 'ready', 'forward_id' => 1, 'local_endpoint_host' => '127.0.0.1',
        'local_tcp_port' => 11001, 'local_udp_port' => 11001, 'tcp_ready' => true, 'udp_ready' => true,
        'internal_mapping' => ['internal_line_label' => 'line-a'],
    ],
]);
assertSame('dir_sh_jp_1', $saved['id'], 'direction id normalized');
assertSame('沪日1', $saved['label'], 'direction label kept');
$userJson = (string) json_encode($service->listDirections(false), JSON_UNESCAPED_UNICODE);
foreach (['internal_line_label', 'forward_id', 'local_tcp_port', 'admin_notes'] as $forbidden) {
    if (str_contains($userJson, $forbidden)) {
        fail('user direction payload leaked ' . $forbidden);
    }
}
assertSame(1, (int) ($service->getDirection('dir_sh_jp_1', true)['realization']['forward_id'] ?? 0), 'admin payload has realization');
assertThrows(fn() => $service->saveDirection(['id' => 'bad id', 'label' => 'x']), 'invalid_route_direction_id', 'bad direction id');
assertThrows(fn() => $service->saveDirection([
    'id' => 'dir_bad_ports', 'label' => 'x',
    'realization' => ['binding_state' => 'ready', 'forward_id' => 1, 'local_tcp_port' => 1, 'local_udp_port' => 2],
]), 'realization_ports_must_match', 'diverging ready ports rejected');
$service->saveDirection(['id' => 'dir_hk_1', 'label' => '港1', 'status' => 'maintenance']);
$service->savePlanEntitlements(1, ['dir_sh_jp_1', 'dir_hk_1'], 2);
assertSame(['dir_hk_1', 'dir_sh_jp_1'], $service->allowedDirectionIdsForPlan(1), 'plan entitlements');
assertSame('svc_scope_1', $service->serviceScopeForServiceId(1), 'scope id');
assertSame(1, $service->serviceIdForScope('svc_scope_1'), 'scope round trip');
$entitlement = $service->entitlementForScope('svc_scope_1');
assertSame(2, $entitlement['max_rules'], 'max rules');
assertSame(2, $entitlement['remaining_rules'], 'remaining rules');

// ---------------------------------------------------------------- CRUD through ProvisioningService
$provisioning = new ProvisioningService($config, $db);
$created = $provisioning->createClientRuleForAgent(1, [
    'service_scope_id' => 'svc_scope_1', 'destination_ip' => '93.184.216.34', 'destination_port' => 443,
    'direction_id' => 'dir_sh_jp_1', 'enabled' => true,
]);
assertSame('active', $created['effective_state'], 'created rule is active');
assertSame(1, $created['sync_version'], 'created sync_version');
assertThrows(fn() => $provisioning->createClientRuleForAgent(1, [
    'service_scope_id' => 'svc_scope_1', 'destination_ip' => '93.184.216.34', 'destination_port' => 443,
    'direction_id' => 'dir_sh_jp_1', 'enabled' => true,
]), 'duplicate_destination', 'duplicate rejected');
assertThrows(fn() => $provisioning->createClientRuleForAgent(1, [
    'service_scope_id' => 'svc_scope_1', 'destination_ip' => '93.184.216.35', 'destination_port' => 443,
    'direction_id' => 'dir_hk_1', 'enabled' => true,
]), 'direction_unavailable', 'unavailable direction rejected');
assertThrows(fn() => $provisioning->createClientRuleForAgent(1, [
    'service_scope_id' => 'svc_scope_1', 'destination_ip' => '93.184.216.35', 'destination_port' => 443,
    'direction_id' => 'dir_nope', 'enabled' => true,
]), 'direction_not_allowed', 'unknown direction rejected');
assertThrows(fn() => $provisioning->createClientRuleForAgent(1, [
    'service_scope_id' => 'svc_scope_2', 'destination_ip' => '93.184.216.35', 'destination_port' => 443,
    'direction_id' => 'dir_sh_jp_1', 'enabled' => true,
]), 'service_not_active', 'foreign service scope rejected');
$second = $provisioning->createClientRuleForAgent(1, [
    'service_scope_id' => 'svc_scope_1', 'destination_ip' => '93.184.216.36', 'destination_port' => 80,
    'direction_id' => 'dir_sh_jp_1', 'enabled' => true,
]);
assertThrows(fn() => $provisioning->createClientRuleForAgent(1, [
    'service_scope_id' => 'svc_scope_1', 'destination_ip' => '93.184.216.37', 'destination_port' => 80,
    'direction_id' => 'dir_sh_jp_1', 'enabled' => true,
]), 'rule_quota_exceeded', 'quota enforced');

$updated = $provisioning->updateClientRuleForAgent(1, $created['id'], ['sync_version' => 1, 'enabled' => false]);
assertSame(2, $updated['sync_version'], 'update bumps sync_version');
assertSame('disabled', $updated['effective_state'], 'disabled state');
assertThrows(fn() => $provisioning->updateClientRuleForAgent(1, $created['id'], ['sync_version' => 1, 'enabled' => true]), 'version_conflict', 'stale version rejected');
assertThrows(fn() => $provisioning->updateClientRuleForAgent(2, $created['id'], ['sync_version' => 2]), 'rule_not_found', 'other client cannot edit');
// Disabled rules do not hold the destination: a new enabled rule for it is accepted after a delete.
$deleted = $provisioning->tombstoneClientRuleForAgent(1, $second['id']);
assertSame(false, $deleted['enabled'], 'tombstone disables');
if ($deleted['tombstoned_at'] === '') {
    fail('tombstone sets tombstoned_at');
}
assertThrows(fn() => $provisioning->tombstoneClientRuleForAgent(1, $second['id']), 'rule_not_found', 'double delete is not found');
assertThrows(fn() => $provisioning->tombstoneClientRuleForAgent(2, $created['id']), 'rule_not_found', 'other client cannot delete');

// ---------------------------------------------------------------- pull contract (Android PanelModels.kt)
$helperForward = ['forward_id' => 1, 'service_id' => 1, 'listen_port' => 11001, 'route_rule_helper_only' => true];
$capabilities = $provisioning->clientRouteRuleCapabilities(['agent_config_json' => $capable]);
assertSame(['route_direction_rules' => true, 'exact_destination_rules' => true], $capabilities, 'capabilities from agent config');
assertSame([], $provisioning->filterRouteRuleHelperForwardsForPull([$helperForward], ['route_direction_rules' => false, 'exact_destination_rules' => false]), 'legacy clients lose helper forwards');
$filtered = $provisioning->filterRouteRuleHelperForwardsForPull([$helperForward], $capabilities);
assertSame(false, $filtered[0]['selectable'] ?? null, 'helper forward non-selectable');

$pull = $provisioning->routeDirectionConfigPullForClient(1, $capabilities, $filtered);
assertSame(['route_directions', 'route_rule_entitlements', 'client_rules', 'direction_runtime_bindings', 'capabilities'], array_keys($pull), 'pull keys match Android @SerializedName');
assertSame(['dir_sh_jp_1', 'dir_hk_1'], array_column($pull['route_directions'], 'id'), 'directions sorted by sort_order');
assertSame(1, count($pull['client_rules']), 'tombstoned rules are not pulled');
assertSame(['id', 'service_scope_id', 'destination_ip', 'destination_port', 'protocol', 'direction_id', 'enabled', 'effective_state', 'sync_version'], array_keys($pull['client_rules'][0]), 'client rule keys');
assertSame(['service_scope_id', 'service_id', 'subscription_id', 'max_rules', 'remaining_rules', 'allowed_direction_ids', 'over_quota'], array_keys($pull['route_rule_entitlements'][0]), 'entitlement keys');
assertSame(1, $pull['route_rule_entitlements'][0]['remaining_rules'], 'remaining after one live rule');
$binding = $pull['direction_runtime_bindings'][0];
assertSame('ready', $binding['binding_state'], 'ready binding');
assertSame('svc_scope_1:dir_sh_jp_1', $binding['client_runtime_binding_id'], 'binding id');
assertSame(['host' => '127.0.0.1', 'tcp_port' => 11001, 'udp_port' => 11001], $binding['local_endpoint'], 'local endpoint');
assertSame(['type' => 'forward_ref', 'forward_id' => 1, 'selectable' => false, 'transport' => 'tcp_udp'], $binding['runtime_ref'], 'runtime ref');
$json = (string) json_encode($pull, JSON_UNESCAPED_UNICODE);
foreach (['local_tcp_port', 'local_udp_port', 'internal_mapping', 'admin_notes'] as $forbidden) {
    if (str_contains($json, $forbidden)) {
        fail('pull payload leaked ' . $forbidden);
    }
}
assertSame([], $provisioning->routeDirectionConfigPullForClient(1, ['route_direction_rules' => false, 'exact_destination_rules' => true], []), 'no capability, no keys');

// The revision digest moves with any rule write, and stays '' for legacy clients.
$before = $service->revisionDigestForClient(1, $capabilities, $filtered);
$provisioning->updateClientRuleForAgent(1, $created['id'], ['sync_version' => 2, 'enabled' => true]);
$after = $service->revisionDigestForClient(1, $capabilities, $filtered);
if ($before === '' || $before === $after) {
    fail('route-rule revision digest must change on a rule write');
}
assertSame('', $service->revisionDigestForClient(1, [], $filtered), 'legacy digest empty');

echo "ok\n";
