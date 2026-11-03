<?php
declare(strict_types=1);

/**
 * Acquiring a line invalidates the credential clients hold for it, so the
 * source uploads each line as soon as it has it. One upload ticket must
 * therefore admit several uploads, each of its lines at most once, and a
 * full replay must still be refused.
 */

namespace Leikwan\Master\Database {

    final class Database
    {
        public function fetchOne(string $sql, array $params = []): ?array
        {
            return null;
        }

        public function fetchAll(string $sql, array $params = []): array
        {
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
    use Leikwan\Master\Support\ApiException;
    use Leikwan\Master\Support\UltraMaterialService;

    function failPerLine(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function assertPerLine(bool $condition, string $message): void
    {
        if (!$condition) {
            failPerLine($message);
        }
    }

    $now = 1_893_456_000;
    $envPath = tempnam(sys_get_temp_dir(), 'lw-per-line-env-');
    if ($envPath === false) {
        failPerLine('failed to create test env');
    }
    file_put_contents(
        $envPath,
        "ULTRA_TOKEN_KEY=token-key-for-tests\n"
        . "ULTRA_MATERIAL_MAX_TTL_SECONDS=2100\n"
        . "ULTRA_SOURCE_CREDENTIALS_JSON={\"source-a\":\"source-a-secret\"}\n"
    );

    $material = static fn(int $lineId): array => [
        'line_id' => $lineId,
        'generation' => 'material-generation-' . $lineId,
        'acquired_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now),
        'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', $now + 1200),
        'user' => 'line-user-' . $lineId,
        'credential' => '1234567890123456',
        'relays' => [['ip' => '203.0.113.20', 'port' => 5438]],
        'probe' => ['ok' => true],
    ];
    $expectReplay = static function (callable $upload, string $message): void {
        try {
            $upload();
        } catch (ApiException $exception) {
            assertPerLine($exception->getMessage() === 'ultra_source_ticket_replayed', $message . ': ' . $exception->getMessage());
            return;
        }
        failPerLine($message . ': no exception');
    };

    $materials = new UltraMaterialService(new Config($envPath), new Database(), null, static fn(): int => $now);
    $ticket = $materials->issueUploadTicket('source-a', [453, 325, 9]);

    // Each line may be uploaded on its own under the same ticket.
    assertPerLine($materials->acceptSourceUpload('source-a', $ticket, [$material(325)]) === [325], 'first line was not accepted');
    assertPerLine($materials->acceptSourceUpload('source-a', $ticket, [$material(9)]) === [9], 'second line under the same ticket was not accepted');

    // Uploading a line twice under one ticket is a replay.
    $expectReplay(
        static fn() => $materials->acceptSourceUpload('source-a', $ticket, [$material(325)]),
        'a line uploaded twice under one ticket was not refused'
    );

    // A mixed batch keeps the new line and skips the replayed one.
    assertPerLine(
        $materials->acceptSourceUpload('source-a', $ticket, [$material(9), $material(453)]) === [453],
        'a mixed batch did not keep only the new line'
    );

    // Every line of the ticket is now spent: a full replay is refused.
    $expectReplay(
        static fn() => $materials->acceptSourceUpload('source-a', $ticket, [$material(325), $material(9), $material(453)]),
        'a full replay was not refused'
    );

    // Lines outside the ticket are still ignored.
    $other = $materials->issueUploadTicket('source-a', [453]);
    assertPerLine($materials->acceptSourceUpload('source-a', $other, [$material(700)]) === [], 'a line outside the ticket was accepted');

    // A ticket covers due lines plus review lines (up to 16).
    $wide = $materials->issueUploadTicket('source-a', range(1, 16));
    assertPerLine($materials->acceptSourceUpload('source-a', $wide, [$material(16)]) === [16], 'the 16th ticket line was dropped');

    fwrite(STDOUT, "ultra source per-line upload test passed\n");
}
