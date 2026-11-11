<?php

declare(strict_types=1);

/**
 * Regression: a WHMCS owner change (createOrUpdateServiceFromWhmcs on an
 * existing service with a different owner) must delete the previous owner's
 * forwards and rotate the service client_token. Without this, the old owner's
 * client keeps pulling live config for a service it no longer owns.
 *
 * Run: php tests/whmcs_owner_change_cleanup_test.php
 */

namespace Leikwan\Master {
    final class Config
    {
        public function get(string $key, ?string $default = null): ?string
        {
            return $default;
        }

        public function getInt(string $key, int $default = 0): int
        {
            return $default;
        }

        public function getBool(string $key, bool $default = false): bool
        {
            return $default;
        }
    }
}

namespace Leikwan\Master\Database {
    final class Database
    {
        private array $tables = [];
        private array $autoIds = [];
        public array $queuedTasks = [];

        public function seed(string $table, array $rows): void
        {
            $this->tables[$table] = array_values($rows);
            $maxId = 0;
            foreach ($rows as $row) {
                $maxId = max($maxId, (int) ($row['id'] ?? 0));
            }
            $this->autoIds[$table] = $maxId + 1;
        }

        public function rows(string $table): array
        {
            return $this->tables[$table] ?? [];
        }

        public function fetchOne(string $sql, array $params = []): ?array
        {
            $normalized = $this->normalizeSql($sql);
            if (str_contains($normalized, 'FROM services s') && str_contains($normalized, 'INNER JOIN users u ON u.id = s.user_id')) {
                foreach ($this->rows('services') as $row) {
                    if ((int) $row['id'] === (int) $params['id']) {
                        return $row;
                    }
                }
                return null;
            }
            if (str_contains($normalized, 'SELECT * FROM forwards WHERE id = :id LIMIT 1')) {
                foreach ($this->rows('forwards') as $row) {
                    if ((int) $row['id'] === (int) $params['id']) {
                        return $row;
                    }
                }
                return null;
            }
            if (str_contains($normalized, 'SELECT * FROM services WHERE id = :id LIMIT 1')) {
                foreach ($this->rows('services') as $row) {
                    if ((int) $row['id'] === (int) $params['id']) {
                        return $row;
                    }
                }
                return null;
            }
            if (str_contains($normalized, 'SELECT * FROM users WHERE id = :id LIMIT 1')) {
                foreach ($this->rows('users') as $row) {
                    if ((int) $row['id'] === (int) $params['id']) {
                        return $row;
                    }
                }
                return null;
            }
            if (str_contains($normalized, 'SELECT * FROM services WHERE whmcs_service_id = :id LIMIT 1')) {
                foreach ($this->rows('services') as $row) {
                    if ((int) $row['whmcs_service_id'] === (int) $params['id']) {
                        return $row;
                    }
                }
                return null;
            }
            if (str_contains($normalized, 'SELECT * FROM users WHERE whmcs_client_id = :client_id LIMIT 1')) {
                foreach ($this->rows('users') as $row) {
                    if ((int) ($row['whmcs_client_id'] ?? 0) === (int) $params['client_id']) {
                        return $row;
                    }
                }
                return null;
            }
            if (str_contains($normalized, 'SELECT * FROM users WHERE email = :email LIMIT 1')) {
                foreach ($this->rows('users') as $row) {
                    if (strcasecmp((string) ($row['email'] ?? ''), (string) $params['email']) === 0) {
                        return $row;
                    }
                }
                return null;
            }
            if (str_contains($normalized, 'SELECT * FROM service_plans WHERE id = :id LIMIT 1')) {
                foreach ($this->rows('service_plans') as $row) {
                    if ((int) $row['id'] === (int) $params['id']) {
                        return $row;
                    }
                }
                return null;
            }
            return null;
        }

        public function fetchAll(string $sql, array $params = []): array
        {
            $normalized = $this->normalizeSql($sql);
            if (str_contains($normalized, 'FROM forwards f') && str_contains($normalized, 'c.owner_user_id IS NULL OR c.owner_user_id <> :new_user_id')) {
                $stale = [];
                $clientsById = [];
                foreach ($this->rows('clients') as $client) {
                    $clientsById[(int) $client['id']] = $client;
                }
                foreach ($this->rows('forwards') as $forward) {
                    if ((int) $forward['service_id'] !== (int) $params['service_id']) {
                        continue;
                    }
                    $lineClientId = (int) ($forward['line_client_id'] ?? 0);
                    $ownerUserId = (int) ($clientsById[$lineClientId]['owner_user_id'] ?? 0);
                    if ($lineClientId === 0 || $ownerUserId !== (int) $params['new_user_id']) {
                        $stale[] = $forward;
                    }
                }
                return $stale;
            }
            return [];
        }

        public function insert(string $table, array $data): int
        {
            $id = $this->autoIds[$table] ?? 1;
            $data['id'] = $id;
            $this->tables[$table][] = $data;
            $this->autoIds[$table] = $id + 1;
            return $id;
        }

        public function execute(string $sql, array $params = []): int
        {
            $normalized = $this->normalizeSql($sql);
            if (str_contains($normalized, 'UPDATE services SET user_id = :user_id')) {
                foreach ($this->tables['services'] as $index => $row) {
                    if ((int) $row['id'] === (int) $params['id']) {
                        foreach (['user_id', 'plan_id', 'name', 'status', 'expire_at', 'bandwidth_limit_mbps', 'traffic_limit_gb', 'billing_mode', 'billing_currency', 'monthly_base_fee', 'monthly_included_traffic_gb', 'traffic_price_per_gb', 'billing_cycle_started_at', 'billing_cycle_traffic_used_bytes'] as $field) {
                            if (array_key_exists($field, $params)) {
                                $this->tables['services'][$index][$field] = $params[$field];
                            }
                        }
                        $this->tables['services'][$index]['updated_at'] = $params['updated_at'];
                        return 1;
                    }
                }
                return 0;
            }
            if (str_contains($normalized, 'UPDATE services SET client_token = :token')) {
                foreach ($this->tables['services'] as $index => $row) {
                    if ((int) $row['id'] === (int) $params['id']) {
                        $this->tables['services'][$index]['client_token'] = $params['token'];
                        return 1;
                    }
                }
                return 0;
            }
            if (str_contains($normalized, 'UPDATE forwards') && str_contains($normalized, 'user_deleted_at = :user_deleted_at')) {
                $count = 0;
                foreach ($this->tables['forwards'] as $index => $row) {
                    if ((int) $row['id'] === (int) $params['id']) {
                        $this->tables['forwards'][$index]['enabled'] = 0;
                        $this->tables['forwards'][$index]['user_deleted_at'] = $params['user_deleted_at'];
                        ++$count;
                    }
                }
                return $count;
            }
            if (str_contains($normalized, 'FROM service_endpoints') && str_contains($normalized, 'health_status')) {
                $this->queuedTasks[] = $params;
                return 0;
            }
            return 0;
        }

        public function transaction(callable $callback)
        {
            return $callback($this);
        }

        private function normalizeSql(string $sql): string
        {
            return preg_replace('/\s+/', ' ', $sql);
        }
    }
}

namespace WhmcsOwnerChangeCleanupTest {
    use Leikwan\Master\Config;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\ProvisioningService;

    require_once __DIR__ . '/../src/Support/ApiException.php';
    require_once __DIR__ . '/../src/Support/ProvisioningService.php';

    function fail(string $message): never
    {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }

    $db = new Database();
    $db->seed('service_plans', [[
        'id' => 15,
        'name' => 'plan',
        'product_code' => 'bridge',
        'default_bandwidth_limit_mbps' => 1000,
        'default_traffic_limit_gb' => 1000,
        'billing_mode' => 'monthly_bundle',
        'billing_currency' => 'CNY',
        'monthly_base_fee' => 0,
        'monthly_included_traffic_gb' => 1000,
        'traffic_price_per_gb' => 0,
        'is_active' => 1,
    ]]);
    $db->seed('users', [
        ['id' => 92, 'name' => 'old owner', 'email' => 'old@example.com', 'role' => 'tenant', 'status' => 'active', 'whmcs_client_id' => 664],
        ['id' => 100, 'name' => 'new owner', 'email' => 'new@example.com', 'role' => 'tenant', 'status' => 'active', 'whmcs_client_id' => 663],
    ]);
    $db->seed('services', [[
        'id' => 60,
        'user_id' => 92,
        'plan_id' => 15,
        'name' => 'Bridge #1007',
        'whmcs_service_id' => 1007,
        'status' => 'active',
        'expire_at' => '2099-01-01 00:00:00',
        'bandwidth_limit_mbps' => 1000,
        'traffic_limit_gb' => 1000,
        'traffic_used_bytes' => 123,
        'billing_mode' => 'monthly_bundle',
        'billing_currency' => 'CNY',
        'monthly_base_fee' => 0,
        'monthly_included_traffic_gb' => 1000,
        'traffic_price_per_gb' => 0,
        'billing_cycle_started_at' => '2026-11-10 00:00:00',
        'billing_cycle_traffic_used_bytes' => 123,
        'client_token' => 'oldtokenoldtokenoldtokenoldtokenoldtoken',
        'created_at' => '2026-11-10 00:00:00',
        'updated_at' => '2026-11-10 00:00:00',
    ]]);
    $db->seed('clients', [
        ['id' => 69, 'owner_user_id' => 92, 'is_default_for_owner' => 1, 'status' => 'active'],
        ['id' => 78, 'owner_user_id' => 100, 'is_default_for_owner' => 1, 'status' => 'active'],
    ]);
    $db->seed('forwards', [
        // line client owned by the OLD user -> must be deleted
        ['id' => 378, 'service_id' => 60, 'line_client_id' => 69, 'enabled' => 1],
        // no line client at all -> must also be deleted (orphan)
        ['id' => 379, 'service_id' => 60, 'line_client_id' => null, 'enabled' => 1],
    ]);

    $service = new ProvisioningService(new Config(), $db);
    $summary = $service->createOrUpdateServiceFromWhmcs([
        'whmcs_service_id' => 1007,
        'whmcs_client_id' => 663,
        'user_email' => 'new@example.com',
        'user_name' => 'new owner',
        'plan_id' => 15,
        'service_name' => 'Bridge #1007',
        'expire_at' => '2099-01-01 00:00:00',
        'status' => 'active',
        'bandwidth_limit_mbps' => 1000,
        'traffic_limit_gb' => 1000,
    ]);

    $updated = $db->fetchOne('SELECT * FROM services WHERE whmcs_service_id = :id LIMIT 1', ['id' => 1007]);
    if ((int) $updated['user_id'] !== 100) {
        fail('service user_id should move to the new owner');
    }
    if (strlen((string) $updated['client_token']) !== 48 || $updated['client_token'] === 'oldtokenoldtokenoldtokenoldtokenoldtoken') {
        fail('service client_token should be rotated after an owner change');
    }
    $forwards = array_values(array_filter($db->rows('forwards'), static fn (array $row): bool => (int) $row['service_id'] === 60));
    foreach ($forwards as $forward) {
        if ((int) $forward['enabled'] !== 0 || ($forward['user_deleted_at'] ?? '') === '') {
            fail('forward ' . $forward['id'] . ' from the previous owner should be deleted');
        }
    }
    if (count($forwards) !== 2) {
        fail('both stale forwards should still exist as deleted rows');
    }
    if ((int) ($summary['id'] ?? 0) !== 60) {
        fail('service summary should be returned');
    }

    // Idempotence: re-running provisioning for the same owner must not rotate again.
    $tokenAfterFirst = $updated['client_token'];
    $service->createOrUpdateServiceFromWhmcs([
        'whmcs_service_id' => 1007,
        'whmcs_client_id' => 663,
        'user_email' => 'new@example.com',
        'user_name' => 'new owner',
        'plan_id' => 15,
        'service_name' => 'Bridge #1007',
        'expire_at' => '2099-01-01 00:00:00',
        'status' => 'active',
        'bandwidth_limit_mbps' => 1000,
        'traffic_limit_gb' => 1000,
    ]);
    $again = $db->fetchOne('SELECT * FROM services WHERE whmcs_service_id = :id LIMIT 1', ['id' => 1007]);
    if ($again['client_token'] !== $tokenAfterFirst) {
        fail('same-owner reprovision must not rotate the client token');
    }

    echo "ok\n";
}
