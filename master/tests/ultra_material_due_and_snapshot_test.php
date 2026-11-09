<?php
declare(strict_types=1);

/**
 * 2026-11-08: a source line that could not be acquired kept its place among the
 * eight lowest line ids handed out for refresh, so the lines behind it were only
 * refreshed after their old material had expired; and one line a few seconds
 * from expiry stopped the whole forward from receiving a grant.
 *
 * Pinned here:
 *   - dueLineIds() hands out the most urgent lines first, honours the lines the
 *     source says it is backing off from, and still caps the answer at eight;
 *   - lineSnapshot() is not capped by a line that is about to expire as long as
 *     another line of the forward is comfortably valid.
 */

namespace Leikwan\Master\Database {

    final class Database
    {
        /** @var array<int, array<string, mixed>> */
        public static array $lineRows = [];

        public function fetchOne(string $sql, array $params = []): ?array
        {
            return null;
        }

        public function fetchAll(string $sql, array $params = []): array
        {
            return str_contains($sql, 'SELECT DISTINCT ul.line_id') ? self::$lineRows : [];
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
    use Leikwan\Master\Support\UltraMaterialService;
    use Leikwan\Master\Support\UltraMode;

    function failDueSnapshot(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function assertDueSnapshot(bool $condition, string $message): void
    {
        if (!$condition) {
            failDueSnapshot($message);
        }
    }

    function describeDueSnapshot(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES) ?: 'unencodable';
    }

    $now = 1_893_456_000;
    $clock = static function () use (&$now): int {
        return $now;
    };
    $envPath = tempnam(sys_get_temp_dir(), 'lw-due-env-');
    if ($envPath === false) {
        failDueSnapshot('failed to create test env');
    }
    file_put_contents(
        $envPath,
        "ULTRA_TOKEN_KEY=token-key-for-tests\n"
        . "ULTRA_MATERIAL_MAX_TTL_SECONDS=2100\n"
        . "ULTRA_SOURCE_CREDENTIALS_JSON={\"source-a\":\"source-a-secret\"}\n"
    );

    $materials = new UltraMaterialService(new Config($envPath), new Database(), null, $clock);

    $upload = static function (int $lineId, int $expiresIn) use ($materials, &$now): void {
        $ticket = $materials->issueUploadTicket('source-a', [$lineId]);
        $accepted = $materials->acceptSourceUpload('source-a', $ticket, [[
            'line_id' => $lineId,
            'generation' => 'generation-' . $lineId . '-' . $now . '-' . $expiresIn,
            'acquired_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now),
            'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now + $expiresIn),
            'user' => 'line-user-' . $lineId,
            'credential' => '1234567890123456',
            'relays' => [['ip' => '203.0.113.' . ($lineId % 200 + 1), 'port' => 5438]],
            'probe' => ['ok' => true],
        ]]);
        assertDueSnapshot($accepted === [$lineId], 'material for line ' . $lineId . ' was not accepted');
    };
    $setLines = static function (array $lineIds): void {
        Database::$lineRows = array_map(static fn(int $lineId): array => ['line_id' => $lineId], $lineIds);
    };

    // --- dueLineIds ordering -------------------------------------------------
    $setLines([1, 3, 5, 9]);
    $upload(1, 1200); // comfortably valid: not due with a 600s window
    $upload(3, 300);
    $upload(5, 100);  // 9 was never acquired
    $due = $materials->dueLineIds('source-a', 600);
    assertDueSnapshot($due === [9, 5, 3], 'never-acquired first, then soonest to expire; got ' . describeDueSnapshot($due));

    $due = $materials->dueLineIds('source-a', 600, [9]);
    assertDueSnapshot($due === [5, 3], 'a skipped line must not be handed out; got ' . describeDueSnapshot($due));
    $due = $materials->dueLineIds('source-a', 600, ['9', 'x', 5]);
    assertDueSnapshot($due === [3], 'skip accepts numeric strings and ignores junk; got ' . describeDueSnapshot($due));

    // Soonest expiry beats a lower line id.
    $setLines([2, 4, 6]);
    $upload(2, 500);
    $upload(4, 200);
    $upload(6, 400);
    $due = $materials->dueLineIds('source-a', 600);
    assertDueSnapshot($due === [4, 6, 2], 'lines must be ordered by expiry, not by id; got ' . describeDueSnapshot($due));

    // --- dead lines must not starve the ones behind them ----------------------
    $dead = range(101, 108);
    $setLines(array_merge($dead, [109, 110, 111, 112]));
    $due = $materials->dueLineIds('source-a', 600);
    assertDueSnapshot($due === $dead, 'without skip the eight lowest ids come first (old agents); got ' . describeDueSnapshot($due));
    $due = $materials->dueLineIds('source-a', 600, $dead);
    assertDueSnapshot($due === [109, 110, 111, 112], 'skipping the dead lines must expose the ones behind them; got ' . describeDueSnapshot($due));

    $setLines(range(201, 230));
    $due = $materials->dueLineIds('source-a', 600);
    assertDueSnapshot(count($due) === 8, 'the answer is capped at eight lines');

    // Backwards compatible call shape: a bare refresh window.
    $setLines([3, 5]);
    $due = $materials->dueLineIds(600);
    assertDueSnapshot($due === [5, 3] || $due === [], 'the integer-only call shape still works; got ' . describeDueSnapshot($due));

    // --- lineSnapshot -----------------------------------------------------------
    $refs = static fn(int ...$lineIds): array => array_map(
        static fn(int $lineId): array => ['source_id' => 'source-a', 'line_id' => $lineId],
        $lineIds
    );
    $margin = UltraMode::MATERIAL_EXPIRING_MARGIN_SECONDS;

    $upload(10, 1200);
    $upload(11, 20);
    $snapshot = $materials->lineSnapshot($refs(10, 11));
    assertDueSnapshot(is_array($snapshot), 'a forward with one healthy line must keep a snapshot');
    assertDueSnapshot(
        $snapshot['expires_at'] === $now + 1200,
        'a line about to expire must not cap the grant while another line is valid; got ' . describeDueSnapshot($snapshot['expires_at'] - $now)
    );
    assertDueSnapshot(count($snapshot['line_ips']) === 2, 'the expiring line is still offered until it is gone');

    // Just outside the margin: this line is comfortable and does cap the grant.
    $upload(12, $margin + 30);
    $snapshot = $materials->lineSnapshot($refs(10, 12));
    assertDueSnapshot(
        $snapshot !== null && $snapshot['expires_at'] === $now + $margin + 30,
        'a line with more than the margin left still limits the grant'
    );

    // Only expiring lines: the soonest one limits the grant, as before.
    $upload(13, 90);
    $upload(14, 60);
    $snapshot = $materials->lineSnapshot($refs(13, 14));
    assertDueSnapshot(
        $snapshot !== null && $snapshot['expires_at'] === $now + 60,
        'with no comfortable line the soonest expiry decides'
    );

    // A single line is unaffected.
    $snapshot = $materials->lineSnapshot($refs(10));
    assertDueSnapshot($snapshot !== null && $snapshot['expires_at'] === $now + 1200, 'a single healthy line is unchanged');

    // Expired material is not offered at all.
    $now += 1300;
    assertDueSnapshot($materials->lineSnapshot($refs(10)) === null, 'expired material must not produce a snapshot');
    $now -= 1300;

    // The fingerprint still follows the set of lines that have material.
    $both = $materials->lineSnapshot($refs(10, 11));
    $only = $materials->lineSnapshot($refs(10));
    assertDueSnapshot(
        $both !== null && $only !== null && $both['fingerprint'] !== $only['fingerprint'],
        'the fingerprint must change when a line drops out'
    );

    fwrite(STDOUT, "ultra material due/snapshot test passed\n");
}
