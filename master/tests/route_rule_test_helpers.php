<?php
declare(strict_types=1);

/**
 * Shared sqlite helpers for the route-direction / client-rule service tests.
 * Included by each test file after its own fail()/assertSame() harness.
 */

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;

function makeTestDb(string $name): array
{
    $envPath = tempnam(sys_get_temp_dir(), 'lw-' . $name . '-env-');
    $dbPath = tempnam(sys_get_temp_dir(), 'lw-' . $name . '-db-');
    if ($envPath === false || $dbPath === false) {
        fail('failed to allocate temp files');
    }
    file_put_contents($envPath, "DB_DRIVER=sqlite\nDB_NAME={$dbPath}\nAUTO_RUN_MIGRATIONS=false\n");
    $config = new Config($envPath);
    $db = new Database($config);
    return [$config, $db, $envPath, $dbPath];
}

function installRouteRuleTestSchema(Database $db): void
{
    $pdo = $db->pdo();
    $pdo->exec('CREATE TABLE service_plans (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, product_code TEXT NOT NULL, max_client_rules_per_service_scope INTEGER NOT NULL DEFAULT 5, is_active INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE services (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, plan_id INTEGER NULL, name TEXT NOT NULL, whmcs_service_id INTEGER NOT NULL, status TEXT NOT NULL DEFAULT "active", client_token TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE clients (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_user_id INTEGER NULL, group_id INTEGER NULL, agent_token TEXT NULL, agent_config_json TEXT NULL, name TEXT NOT NULL, display_host TEXT NOT NULL, status TEXT NOT NULL DEFAULT "active", created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE forwards (id INTEGER PRIMARY KEY AUTOINCREMENT, service_id INTEGER NOT NULL, listen_port INTEGER NOT NULL, line_client_id INTEGER NULL, line_client_group_id INTEGER NULL, enabled INTEGER NOT NULL DEFAULT 1, route_rule_helper_only INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE route_directions (id TEXT PRIMARY KEY, label TEXT NOT NULL, status TEXT NOT NULL, admin_notes TEXT NULL, sort_order INTEGER NOT NULL DEFAULT 100, internal_mapping_json TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE direction_realizations (id INTEGER PRIMARY KEY AUTOINCREMENT, direction_id TEXT NOT NULL, is_primary INTEGER NOT NULL DEFAULT 1, binding_state TEXT NOT NULL DEFAULT "missing", forward_id INTEGER NULL, local_endpoint_host TEXT NOT NULL DEFAULT "127.0.0.1", local_tcp_port INTEGER NULL, local_udp_port INTEGER NULL, tcp_ready INTEGER NOT NULL DEFAULT 0, udp_ready INTEGER NOT NULL DEFAULT 0, helper_only INTEGER NOT NULL DEFAULT 1, internal_mapping_json TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE service_plan_route_directions (id INTEGER PRIMARY KEY AUTOINCREMENT, plan_id INTEGER NOT NULL, direction_id TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE(plan_id, direction_id))');
    $pdo->exec('CREATE TABLE client_rules (id TEXT PRIMARY KEY, service_scope_id TEXT NOT NULL, service_id INTEGER NOT NULL, subscription_id INTEGER NULL, destination_ip TEXT NOT NULL, destination_port INTEGER NOT NULL, protocol TEXT NOT NULL DEFAULT "tcp_udp", direction_id TEXT NOT NULL, enabled INTEGER NOT NULL DEFAULT 1, effective_state TEXT NOT NULL DEFAULT "runtime_missing", sync_version INTEGER NOT NULL DEFAULT 1, active_duplicate_key TEXT NULL, tombstoned_at TEXT NULL, tombstoned_by TEXT NULL, notes TEXT NULL, created_by_client_id INTEGER NULL, updated_by_client_id INTEGER NULL, updated_by_admin_id INTEGER NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE(service_scope_id, active_duplicate_key))');
    $pdo->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, action TEXT NOT NULL, context_json TEXT NULL, created_at TEXT NOT NULL)');
}
