<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
        /** @var array<int, array<string, mixed>> rows returned for the lease line query */
        public array $rows = [];

        /** @param array<string, mixed> $params */
        public function execute(string $sql, array $params = []): int
        {
            return 0;
        }

        /** @param array<string, mixed> $params */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            return null;
        }

        /** @param array<string, mixed> $params @return array<int, array<string, mixed>> */
        public function fetchAll(string $sql, array $params = []): array
        {
            return $this->rows;
        }
    }
}

namespace {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Leikwan\\Master\\';
        if (!str_starts_with($class, $prefix) || $class === 'Leikwan\\Master\\Database\\Database') {
            return;
        }
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    use Leikwan\Master\Config;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\ProvisioningService;

    /** One enabled member line, shaped like the lease query's row. */
    function leaseRow(int $lineId, string $ingress, string $allowedJson, int $sortOrder): array
    {
        return [
            'forward_id' => 292,
            'target_host' => 'tw.example.net',
            'target_port' => 8443,
            'forward_combination_id' => 53,
            'ultra_destination_zone' => 'taiwan',
            'ultra_allowed_ingress_zones_json' => $allowedJson,
            'ultra_fallback_policy' => 'detected_then_any',
            'external_uot_enabled' => 0,
            'ultra_line_id' => $lineId,
            'sort_order' => $sortOrder,
            'source_id' => 'source-home-171',
            'line_id' => $lineId,
            'access_class' => 'native_udp',
            'native_udp_state' => 'verified',
            'native_socks5_state' => 'unknown',
            'route_class' => 'direct',
            'pricing_class' => 'unknown',
            'pricing_evidence' => '',
            'dispatch_class' => 'normal',
            'ingress_zone' => $ingress,
            'destination_zone' => 'taiwan',
        ];
    }

    function leaseLineIds(array $metadata, string $forwardId): array
    {
        return array_map(static fn(array $line): int => $line['line_id'], $metadata[$forwardId]['lines']);
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-ultra-zone-');
    if ($envPath === false) {
        fwrite(STDERR, "failed to create test config\n");
        exit(1);
    }
    file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\n");
    $eligible = [['forward_id' => 292, 'backend_mode' => 'ultra', 'service_status' => 'active', 'forward_combination_direction' => 'FOC to TPE']];

    // Combination 53 of 2026-10-26: only Fuzhou ingress is allowed. The
    // Fuzhou line is the one the SQL sorts last (dispatch class low), and the
    // two Guangzhou lines were the ones the relay kept failing.
    $fuzhouOnly = '["fuzhou"]';
    $database = new Database();
    $database->rows = [
        leaseRow(120, 'guangzhou', $fuzhouOnly, 10),
        leaseRow(467, 'guangzhou', $fuzhouOnly, 20),
        leaseRow(41, 'fuzhou', $fuzhouOnly, 30),
    ];
    $metadata = (new ProvisioningService(new Config($envPath), $database))->ultraLineMetadataForAssignedForwards($eligible);
    if (leaseLineIds($metadata, '292') !== [41] || ($metadata['292']['line_ids'] ?? null) !== [41]) {
        fwrite(STDERR, "out-of-zone lines were leased to a Fuzhou-only combination\n");
        exit(1);
    }

    // A combination with no allowed-zone list keeps every verified member, as before.
    $database = new Database();
    $database->rows = [
        leaseRow(120, 'guangzhou', '[]', 10),
        leaseRow(41, 'fuzhou', '[]', 30),
    ];
    $metadata = (new ProvisioningService(new Config($envPath), $database))->ultraLineMetadataForAssignedForwards($eligible);
    if (leaseLineIds($metadata, '292') !== [120, 41]) {
        fwrite(STDERR, "combination without an allowed-zone list lost its lines\n");
        exit(1);
    }

    // When no member is inside the allowed zones the forward gets no lines
    // at all rather than lines from another zone.
    $database = new Database();
    $database->rows = [
        leaseRow(120, 'guangzhou', $fuzhouOnly, 10),
        leaseRow(467, 'guangzhou', $fuzhouOnly, 20),
    ];
    $metadata = (new ProvisioningService(new Config($envPath), $database))->ultraLineMetadataForAssignedForwards($eligible);
    if (($metadata['292']['lines'] ?? null) !== [] || ($metadata['292']['line_ids'] ?? null) !== []) {
        fwrite(STDERR, "a forward with no in-zone line still received out-of-zone lines\n");
        exit(1);
    }

    @unlink($envPath);
    echo "ok\n";
}
