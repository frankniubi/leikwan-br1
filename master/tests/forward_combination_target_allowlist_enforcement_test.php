<?php
declare(strict_types=1);

namespace Leikwan\Master {
    final class Config
    {
        public function __construct(string $envPath = '') {}
        public function get(string $key, mixed $default = null): mixed { return $default; }
        public function getInt(string $key, int $default = 0): int { return $default; }
    }
}

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<string, mixed>|null */
        public ?array $storedCombination = null;
        /** @var list<array<string, mixed>> */
        public array $forwards = [];

        public function fetchOne(string $sql, array $params = []): ?array
        {
            if (str_contains($sql, 'SELECT allowed_target_cidrs FROM forward_combinations')) {
                return $this->storedCombination;
            }
            return null;
        }

        public function fetchAll(string $sql, array $params = []): array
        {
            if (str_contains($sql, 'FROM forwards') && str_contains($sql, 'forward_combination_id = :id')) {
                return array_values(array_filter(
                    $this->forwards,
                    static fn(array $row): bool => (int) ($row['forward_combination_id'] ?? 0) === (int) ($params['id'] ?? 0)
                        && (int) ($row['enabled'] ?? 0) === 1
                ));
            }
            return [];
        }
    }
}

namespace {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Leikwan\\Master\\';
        if (!str_starts_with($class, $prefix) || in_array($class, ['Leikwan\\Master\\Database\\Database', 'Leikwan\\Master\\Config'], true)) {
            return;
        }
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    use Leikwan\Master\Config;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\ApiException;
    use Leikwan\Master\Support\ProvisioningService;

    $failures = 0;
    $check = static function (bool $condition, string $label) use (&$failures): void {
        if (!$condition) {
            $failures++;
            fwrite(STDERR, "FAIL: {$label}\n");
        }
    };
    $db = new Database();
    $service = new ProvisioningService(new Config(), $db);
    $call = static function (string $method, array $args) use ($service): mixed {
        $reflection = new \ReflectionMethod(ProvisioningService::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($service, $args);
    };
    $errorOf = static function (callable $fn): ?ApiException {
        try {
            $fn();
        } catch (ApiException $exception) {
            return $exception;
        }
        return null;
    };

    $restricted = ['id' => 77, 'allowed_target_cidrs' => "163.53.52.0/22\n211.161.96.0/20"];

    // Enforcement on forward create/update.
    $check($errorOf(fn() => $call('assertForwardTargetAllowedByCombination', [null, 'example.com', false])) === null, 'no combination: no restriction');
    $check($errorOf(fn() => $call('assertForwardTargetAllowedByCombination', [['id' => 1, 'allowed_target_cidrs' => null], 'example.com', true])) === null, 'unrestricted combination accepts hostnames and native');
    $check($errorOf(fn() => $call('assertForwardTargetAllowedByCombination', [$restricted, '163.53.55.221', false])) === null, 'restricted combination accepts an IP inside the list');
    $outside = $errorOf(fn() => $call('assertForwardTargetAllowedByCombination', [$restricted, '8.8.8.8', false]));
    $check($outside !== null && $outside->getMessage() === 'forward_target_not_allowed' && $outside->statusCode() === 422, 'restricted combination refuses an outside IP with 422 forward_target_not_allowed');
    $check($errorOf(fn() => $call('assertForwardTargetAllowedByCombination', [$restricted, 'ix.example.cn', false]))?->getMessage() === 'forward_target_not_allowed', 'restricted combination refuses hostnames');
    $check($errorOf(fn() => $call('assertForwardTargetAllowedByCombination', [$restricted, '163.53.55.221', true]))?->getMessage() === 'forward_target_not_allowed', 'restricted combination refuses native outbound');

    // Combination save: omitted field keeps the stored list, explicit empty clears it.
    $db->storedCombination = ['allowed_target_cidrs' => "163.53.52.0/22"];
    $check($call('normalizeCombinationTargetAllowlist', [[], 77]) === '163.53.52.0/22', 'omitted field keeps stored list on edit');
    $check($call('normalizeCombinationTargetAllowlist', [[], 0]) === null, 'omitted field on create stays unrestricted');
    $check($call('normalizeCombinationTargetAllowlist', [['allowed_target_cidrs' => ''], 77]) === null, 'explicit empty field clears the list');
    $check($call('normalizeCombinationTargetAllowlist', [['allowed_target_cidrs' => "163.53.55.0/22\n211.161.96.0/20"], 0]) === "163.53.52.0/22\n211.161.96.0/20", 'create stores canonical list');

    // A new list must admit every enabled forward already on the combination.
    $db->forwards = [
        ['id' => 501, 'forward_combination_id' => 77, 'enabled' => 1, 'target_host' => '163.53.55.221', 'native_outbound_enabled' => 0],
        ['id' => 502, 'forward_combination_id' => 77, 'enabled' => 0, 'target_host' => '8.8.8.8', 'native_outbound_enabled' => 0],
        ['id' => 503, 'forward_combination_id' => 78, 'enabled' => 1, 'target_host' => '8.8.8.8', 'native_outbound_enabled' => 0],
    ];
    $check($call('normalizeCombinationTargetAllowlist', [['allowed_target_cidrs' => '163.53.52.0/22'], 77]) === '163.53.52.0/22', 'list admitting all enabled forwards saves (disabled and other-combination rows ignored)');
    $db->forwards[] = ['id' => 504, 'forward_combination_id' => 77, 'enabled' => 1, 'target_host' => '8.8.4.4', 'native_outbound_enabled' => 0];
    $db->forwards[] = ['id' => 505, 'forward_combination_id' => 77, 'enabled' => 1, 'target_host' => '127.0.0.1', 'native_outbound_enabled' => 1];
    $conflict = $errorOf(fn() => $call('normalizeCombinationTargetAllowlist', [['allowed_target_cidrs' => '163.53.52.0/22'], 77]));
    $check($conflict !== null && $conflict->getMessage() === 'forward_target_allowlist_conflict', 'outside enabled forward blocks the save');
    $check($conflict !== null && ($conflict->details()['forward_ids'] ?? null) === [504, 505], 'conflict lists the outside and native forward ids');
    $check($conflict !== null && str_contains((string) ($conflict->details()['message'] ?? ''), '#504'), 'conflict message names the forwards for the admin flash');
    $invalid = $errorOf(fn() => $call('normalizeCombinationTargetAllowlist', [['allowed_target_cidrs' => 'not-a-cidr'], 0]));
    $check($invalid !== null && $invalid->getMessage() === 'invalid_forward_target_allowlist', 'invalid CIDR rejected on save');

    if ($failures > 0) {
        fwrite(STDERR, "{$failures} check(s) failed\n");
        exit(1);
    }
    echo "forward_combination_target_allowlist_enforcement_test: ok\n";
}
