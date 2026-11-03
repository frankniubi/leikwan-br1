<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<string, mixed> */
        private array $user = [
            'id' => 1,
            'name' => 'OIDC Tenant',
            'email' => 'tenant@example.test',
            'role' => 'tenant',
            'status' => 'active',
            'email_verified_at' => '2026-09-15 00:00:00',
            'wallet_balance' => '0.00',
            'wallet_currency' => 'CNY',
            'whmcs_client_id' => null,
            'whmcs_user_id' => null,
            'whmcs_email' => null,
            'whmcs_full_name' => null,
            'whmcs_currency_code' => null,
            'whmcs_bound_at' => null,
            'whmcs_last_synced_at' => null,
        ];

        /** @param array<string, mixed> $params @return array<string, mixed>|null */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            $query = strtolower(preg_replace('/\s+/', ' ', $sql) ?? $sql);
            if (str_contains($query, 'from users where id')) {
                return (int) ($params['id'] ?? 0) === 1 ? $this->user : null;
            }
            if (str_contains($query, 'from users where whmcs_client_id')) {
                $clientId = (int) ($params['whmcs_client_id'] ?? 0);
                return (int) ($this->user['whmcs_client_id'] ?? 0) === $clientId && $clientId > 0
                    ? $this->user
                    : null;
            }

            throw new \RuntimeException('unexpected fetchOne: ' . $sql);
        }

        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int
        {
            if (str_starts_with(strtolower(trim($sql)), 'update users')) {
                foreach (['wallet_currency', 'whmcs_client_id', 'whmcs_user_id', 'whmcs_email', 'whmcs_full_name', 'whmcs_currency_code', 'whmcs_bound_at', 'whmcs_last_synced_at', 'updated_at'] as $field) {
                    if (array_key_exists($field, $params)) {
                        $this->user[$field] = $params[$field];
                    }
                }
                return 1;
            }

            throw new \RuntimeException('unexpected execute: ' . $sql);
        }
    }
}

namespace {
    use Leikwan\Master\Config;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\WhmcsClient;
    use Leikwan\Master\Support\WhmcsIdentityService;

    require_once __DIR__ . '/../src/Config.php';
    require_once __DIR__ . '/../src/Support/ApiException.php';
    require_once __DIR__ . '/../src/Support/WhmcsClient.php';
    require_once __DIR__ . '/../src/Support/WhmcsIdentityService.php';

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function assertSame(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            fail($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-whmcs-bind-');
    if ($envPath === false) {
        fail('failed to create config file');
    }
    file_put_contents($envPath, "WHMCS_BASE_URL=https://buy.example.test\nWHMCS_API_IDENTIFIER=test\nWHMCS_API_SECRET=test\n");
    $config = new Config($envPath);

    $productsCalls = 0;
    $whmcs = new WhmcsClient($config, static function (string $action, array $params) use (&$productsCalls): array {
        if ($action === 'GetClientsProducts') {
            $productsCalls++;
            throw new \RuntimeException('product inventory must not be needed for binding');
        }
        if ($action === 'GetClientsDetails') {
            return [
                'result' => 'success',
                'client' => [
                    'id' => 789,
                    'owner_user_id' => 456,
                    'email' => 'tenant@example.test',
                    'firstname' => 'OIDC',
                    'lastname' => 'Tenant',
                    'currency_code' => 'CNY',
                ],
            ];
        }
        throw new \RuntimeException('unexpected WHMCS action ' . $action);
    });

    $db = new Database();
    $service = new WhmcsIdentityService($config, $db, $whmcs);
    $bound = $service->tryDirectBindForUser(1, [
        'email' => 'tenant@example.test',
        'email_verified' => true,
        'name' => 'OIDC Tenant',
        'whmcs_client_id' => 123,
        'whmcs_user_id' => 456,
    ]);
    assertSame(123, (int) ($bound['whmcs_client_id'] ?? 0), 'trusted OIDC client ID must bind without API lookup');
    assertSame(456, (int) ($bound['whmcs_user_id'] ?? 0), 'trusted OIDC owner ID must bind without API lookup');
    assertSame(0, $productsCalls, 'direct binding must not request product inventory');

    $fallbackDb = new Database();
    $fallbackService = new WhmcsIdentityService($config, $fallbackDb, $whmcs);
    $fallback = $fallbackService->tryDirectBindForUser(1);
    assertSame(789, (int) ($fallback['whmcs_client_id'] ?? 0), 'email binding must resolve the WHMCS client');
    assertSame(0, $productsCalls, 'email binding must not request product inventory');

    @unlink($envPath);
    echo "ok\n";
}
