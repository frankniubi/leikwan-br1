<?php
declare(strict_types=1);

/**
 * A policy generation is a pure function of the request, so a generation the
 * lease plane has already refused used to be re-minted on every pull for ever:
 * the panel kept handing the client a dead generation, UltraLeaseService kept
 * answering policy_generation_invalid, and the forward stayed enabled with no
 * lease and no way back. Reproduced here through the real broker.
 */

namespace Leikwan\Master\Database {

    final class Database
    {
        /** @var array<int, array<string, mixed>> rows the revocation index returns */
        public static array $revokedRows = [];

        public function fetchOne(string $sql, array $params = []): ?array
        {
            return null;
        }

        public function fetchAll(string $sql, array $params = []): array
        {
            if (str_contains($sql, 'FROM ultra_client_leases')) {
                return self::$revokedRows;
            }
            return [];
        }

        public function insert(string $table, array $data): int
        {
            return 1;
        }

        public function execute(string $sql, array $params = []): int
        {
            return 0;
        }

        public function transaction(callable $callback)
        {
            return $callback($this);
        }
    }
}

namespace {

    spl_autoload_register(static function (string $class): void {
        $prefix = 'Leikwan\\Master\\';
        if (!str_starts_with($class, $prefix) || $class === 'Leikwan\\Master\\Database\\Database') {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', (string) $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    use Leikwan\Master\Config;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\LeigodTokenBroker;
    use Leikwan\Master\Support\UltraMaterialService;

    function failRevokedGeneration(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function assertRevokedGeneration(bool $condition, string $message): void
    {
        if (!$condition) {
            failRevokedGeneration($message);
        }
    }

    $now = 1_893_456_000;
    $envPath = tempnam(sys_get_temp_dir(), 'lw-revoked-env-');
    if ($envPath === false) {
        failRevokedGeneration('failed to create test env');
    }
    file_put_contents(
        $envPath,
        "ULTRA_TOKEN_KEY=token-key-for-tests\n"
        . "ULTRA_MAX_TTL_SECONDS=1800\n"
        . "ULTRA_REFRESH_SKEW_SECONDS=600\n"
        . "ULTRA_MATERIAL_MAX_TTL_SECONDS=2100\n"
        . "ULTRA_SOURCE_CREDENTIALS_JSON={\"source-a\":\"source-a-secret\"}\n"
    );

    $config = new Config($envPath);
    $db = new Database();
    $materials = new UltraMaterialService($config, $db, null, static fn(): int => $now);

    $ticket = $materials->issueUploadTicket('source-a', [453]);
    $accepted = $materials->acceptSourceUpload('source-a', $ticket, [[
        'line_id' => 453,
        'generation' => 'material-generation-1',
        'acquired_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now),
        'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now + 1200),
        'user' => 'line-user',
        'credential' => '1234567890123456',
        'relays' => [['ip' => '203.0.113.20', 'port' => 5438]],
        'probe' => ['ok' => true],
    ]]);
    assertRevokedGeneration($accepted === [453], 'source material was not accepted');

    $broker = new LeigodTokenBroker($config, null, $materials, static fn(): int => $now);

    $forward = [
        'forward_id' => 42,
        'service_id' => 5,
        'service_status' => 'active',
        'backend_mode' => 'ultra',
        'target' => '198.51.100.10:443',
        'transport' => 'tcp+udp',
        'speed_limit_mbps' => 20,
        '_ultra_line_ids' => [453],
        '_ultra_lines' => [[
            'ultra_line_id' => 10,
            'line_id' => 453,
            'source_id' => 'source-a',
            'ingress_zone' => 'shanghai',
            'destination_zone' => 'tokyo',
            'sort_order' => 10,
        ]],
        '_ultra_line_direction' => 'Shanghai to Japan',
        '_ultra_destination_zone' => 'tokyo',
        '_ultra_allowed_ingress_zones' => ['shanghai', 'beijing'],
        '_ultra_fallback_policy' => 'detected_then_any',
    ];

    $generationOf = static function (array $grants): string {
        $grant = $grants['42'] ?? null;
        assertRevokedGeneration(is_array($grant), 'ultra forward did not receive a grant');
        return (string) ($grant['policy_generation'] ?? '');
    };

    // 1. No revocation history: the generation every existing client holds.
    Database::$revokedRows = [];
    $baseGeneration = $generationOf($broker->grantsForClientForwards(7, 'device-a', [$forward], true));
    assertRevokedGeneration(
        preg_match('/^[a-f0-9]{64}$/', $baseGeneration) === 1,
        'base policy generation is not a sha256 hex digest'
    );

    // 2. Revocation history that does NOT touch this forward's generation must
    //    not rotate it: a deploy of this rule cannot disturb healthy forwards.
    Database::$revokedRows = [[
        'forward_id' => 42,
        'policy_generation' => str_repeat('a', 64),
        'epoch' => 900,
    ]];
    $untouchedGeneration = $generationOf($broker->grantsForClientForwards(7, 'device-a', [$forward], true));
    assertRevokedGeneration(
        $untouchedGeneration === $baseGeneration,
        'a revocation of another generation rotated a healthy forward'
    );

    // 3. The forward's own generation is revoked: the panel must hand out a
    //    generation the lease plane will honour rather than re-mint the dead one.
    //    The cached grant from step 1 holds exactly the dead generation, so this
    //    also proves the cached grant is not blindly reused.
    Database::$revokedRows = [[
        'forward_id' => 42,
        'policy_generation' => $baseGeneration,
        'epoch' => 901,
    ]];
    $healedGeneration = $generationOf($broker->grantsForClientForwards(7, 'device-a', [$forward], true));
    assertRevokedGeneration(
        $healedGeneration !== $baseGeneration,
        'the revoked generation was handed out again'
    );
    assertRevokedGeneration(
        preg_match('/^[a-f0-9]{64}$/', $healedGeneration) === 1,
        'healed policy generation is not a sha256 hex digest'
    );

    // 4. Stable while the revocation set is unchanged, so the fix cannot make
    //    clients churn generations on every poll.
    $healedAgain = $generationOf($broker->grantsForClientForwards(7, 'device-a', [$forward], true));
    assertRevokedGeneration(
        $healedAgain === $healedGeneration,
        'the healed generation was not stable across pulls'
    );

    // 5. Revoking the healed generation as well must escape again, not loop.
    Database::$revokedRows = [
        ['forward_id' => 42, 'policy_generation' => $baseGeneration, 'epoch' => 901],
        ['forward_id' => 42, 'policy_generation' => $healedGeneration, 'epoch' => 1200],
    ];
    $escapedGeneration = $generationOf($broker->grantsForClientForwards(7, 'device-a', [$forward], true));
    assertRevokedGeneration(
        $escapedGeneration !== $baseGeneration && $escapedGeneration !== $healedGeneration,
        'a second revocation reproduced an already revoked generation'
    );

    // 6. A forward with no revocation history at all is untouched.
    Database::$revokedRows = [];
    $afterEverything = $generationOf($broker->grantsForClientForwards(7, 'device-b', [$forward], true));
    assertRevokedGeneration(
        $afterEverything === $baseGeneration,
        'a fresh client received a rotated generation for a clean forward'
    );

    fwrite(STDOUT, 'ok' . PHP_EOL);
    exit(0);
}
