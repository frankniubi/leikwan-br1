<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;

final class LeigodHybridRuntimeService
{
    private const PARALLEL_ROUTE_BASE = 1_000_000;
    private const PARALLEL_ROUTE_MULTIPLIER = 10;

    /** @var callable(array<string,mixed>,string,string):string */
    private $tokenBuilder;
    /** @var array<string, mixed> */
    private array $pathdArtifact;
    private LeigodLineCatalogService $catalog;

    /**
     * @param callable(array<string,mixed>,string,string):string $tokenBuilder
     * @param array<string, mixed>|null $pathdArtifact
     */
    public function __construct(
        private Config $config,
        private Database $db,
        callable $tokenBuilder,
        ?array $pathdArtifact = null,
        ?LeigodLineCatalogService $catalog = null,
    ) {
        $this->tokenBuilder = $tokenBuilder;
        $this->pathdArtifact = $pathdArtifact ?? $this->manifestArtifact();
        $this->catalog = $catalog ?? new LeigodLineCatalogService($config, $db);
    }

    /**
     * @param array<string, mixed> $forward
     * @param array<string, mixed> $client
     * @param array<string, mixed> $deviceSession
     * @param array<int, array<string, mixed>> $endpoints
     * @return array<string, mixed>|null
     */
    public function forForward(array $forward, array $client, array $deviceSession, array $endpoints): ?array
    {
        if (!$this->eligibleRequest($forward, $client, $deviceSession)) {
            return null;
        }
        $clientId = (int) ($client['id'] ?? 0);
        $deviceId = trim((string) ($deviceSession['device_id'] ?? ''));
        $approvedCatalogIds = $this->preflightClientCatalogIds($forward, $client, $deviceSession, $endpoints);
        if ($approvedCatalogIds === []) {
            return null;
        }
        $localLeases = $this->localLeases($clientId, $deviceId, $approvedCatalogIds);
        if ($localLeases === []) {
            return null;
        }

        $seenPhysical = [];
        $seenRoutes = [];
        foreach ($localLeases as $lease) {
            $physicalLineId = (int) $lease['physical_line_id'];
            if (isset($seenPhysical[$physicalLineId])) {
                return null;
            }
            $seenPhysical[$physicalLineId] = true;
            foreach ($lease['routes'] as $route) {
                $routeId = (int) $route['route_id'];
                if ($routeId <= 0 || isset($seenRoutes[$routeId])) {
                    return null;
                }
                $seenRoutes[$routeId] = true;
            }
        }

        $slaveLegs = [];
        $executionGroups = ['client:' . $clientId . ':' . $deviceId => true];
        foreach ($endpoints as $endpoint) {
            if (!is_array($endpoint)) {
                continue;
            }
            $endpointId = (int) ($endpoint['endpoint_id'] ?? $endpoint['id'] ?? 0);
            $nodeId = (int) ($endpoint['node_id'] ?? 0);
            if ($endpointId <= 0) {
                continue;
            }
            if ($nodeId <= 0) {
                $storedEndpoint = $this->db->fetchOne(
                    'SELECT id, node_id FROM service_endpoints WHERE id = :endpoint_id LIMIT 1',
                    ['endpoint_id' => $endpointId]
                );
                $nodeId = (int) ($storedEndpoint['node_id'] ?? 0);
            }
            $host = trim((string) ($endpoint['host'] ?? $endpoint['public_host'] ?? ''));
            $port = (int) ($endpoint['port'] ?? $endpoint['public_port'] ?? 0);
            $ingressAuthToken = trim((string) ($endpoint['ingress_auth_token'] ?? ''));
            if ($nodeId <= 0 || $host === '' || $port < 1 || $port > 65535 || $ingressAuthToken === '') {
                continue;
            }
            foreach ($this->catalog->candidatesForForwardOnNode($forward, $nodeId) as $candidate) {
                $physicalLineId = (int) ($candidate['line_id'] ?? 0);
                if ($physicalLineId <= 0 || isset($seenPhysical[$physicalLineId])) {
                    return null;
                }
                $seenPhysical[$physicalLineId] = true;
                $executionGroups['node:' . $nodeId] = true;
                $maxLanes = max(1, min(4, (int) ($candidate['max_parallel_lanes'] ?? 1)));
                for ($lane = 1; $lane <= $maxLanes; $lane++) {
                    $routeId = $this->routeId($physicalLineId, $lane);
                    if (isset($seenRoutes[$routeId])) {
                        return null;
                    }
                    $seenRoutes[$routeId] = true;
                    $slaveLegs[] = [
                        'execution_group' => 'node:' . $nodeId,
                        'endpoint_id' => $endpointId,
                        'node_id' => $nodeId,
                        'host' => $host,
                        'port' => $port,
                        'ingress_auth_token' => $ingressAuthToken,
                        'catalog_sort_order' => (int) ($candidate['catalog_sort_order'] ?? $candidate['sort_order'] ?? 100),
                        'physical_line_id' => $physicalLineId,
                        'lease_id' => (string) ($candidate['lease_id'] ?? ''),
                        'lease_generation' => (int) ($candidate['lease_generation'] ?? 0),
                        'lane' => $lane,
                        'route_id' => $routeId,
                    ];
                }
            }
        }
        $localOnlyParallel = count($localLeases) === 1
            && count($localLeases[0]['routes'] ?? []) >= 2;
        if (($slaveLegs === [] || count($executionGroups) < 2) && !$localOnlyParallel) {
            return null;
        }
        usort($slaveLegs, static fn(array $left, array $right): int => [
            (string) $left['execution_group'],
            (int) $left['catalog_sort_order'],
            (int) $left['physical_line_id'],
            (int) $left['lane'],
        ] <=> [
            (string) $right['execution_group'],
            (int) $right['catalog_sort_order'],
            (int) $right['physical_line_id'],
            (int) $right['lane'],
        ]);

        $profile = $this->egressProfile((int) ($forward['forward_route_id'] ?? 0));
        $secret = $this->egressSecret($profile);
        if ($profile === null || $secret === null) {
            return null;
        }
        $token = ($this->tokenBuilder)($forward, $secret, 'tcp');
        if (!$this->tokenMatchesForward($token, $secret, $forward)) {
            return null;
        }

        $runtime = [
            'engine' => 'linux_pathd',
            'placement' => 'linux_hybrid',
            'protocol' => 'tcp',
            'target' => [
                'host' => trim((string) ($forward['target_host'] ?? '')),
                'port' => (int) ($forward['target_port'] ?? 0),
            ],
            'egress' => [
                'profile_id' => (int) ($profile['id'] ?? 0),
                'host' => trim((string) ($profile['host'] ?? '')),
                'port' => (int) ($profile['tcp_port'] ?? 0),
                'session_token' => $token,
            ],
            'local_leases' => $localLeases,
            'slave_legs' => $slaveLegs,
            'policy' => [
                'adaptive_line_admission' => true,
                'throughput_min_mbps' => max(1.0, min(10000.0, (float) ($this->config->get('LEIGOD_HYBRID_THROUGHPUT_MIN_MBPS', '10') ?? '10'))),
                'latency_first' => true,
                'interactive_max_bytes' => max(1024, min(1_048_576, $this->config->getInt('LEIGOD_HYBRID_INTERACTIVE_MAX_BYTES', 65536))),
            ],
        ];
        $runtime['contract_hash'] = hash('sha256', $this->canonicalJson($runtime));
        return $runtime;
    }

    /**
     * Performs the allocation preflight without requiring a local client lease.
     *
     * @param array<string, mixed> $forward
     * @param array<string, mixed> $client
     * @param array<string, mixed> $deviceSession
     * @param array<int, array<string, mixed>> $endpoints
     * @return array<int, int>
     */
    public function preflightClientCatalogIds(
        array $forward,
        array $client,
        array $deviceSession,
        array $endpoints,
    ): array {
        if (!$this->eligibleRequest($forward, $client, $deviceSession)) {
            return [];
        }
        $profile = $this->egressProfile((int) ($forward['forward_route_id'] ?? 0));
        if ($profile === null || $this->egressSecret($profile) === null) {
            return [];
        }
        $clientCatalogIds = $this->catalog->clientExecutionCatalogIdsForForward($forward);
        if ($clientCatalogIds === []) {
            return [];
        }
        $localParallelCatalogIds = $this->localParallelCatalogIds($clientCatalogIds);
        $combinationId = (int) ($forward['forward_combination_id'] ?? 0);
        $eligibleNodeIds = [];
        foreach ($this->catalog->eligibleNodesForCombination($combinationId, 'tcp') as $node) {
            $nodeId = (int) ($node['id'] ?? $node['node_id'] ?? 0);
            if ($nodeId > 0 && is_array($node['candidates'] ?? null) && $node['candidates'] !== []) {
                $eligibleNodeIds[$nodeId] = true;
            }
        }
        // A client-owned line may be the only execution authority. In that case
        // there is no node candidate to advertise, but the client lane contract
        // still needs to be allocated before the next pull can render pathd.
        if ($eligibleNodeIds === []) {
            return $localParallelCatalogIds;
        }
        $hasSlaveExecutionGroup = false;
        foreach ($endpoints as $endpoint) {
            if (!is_array($endpoint)) {
                continue;
            }
            $nodeId = (int) ($endpoint['node_id'] ?? 0);
            $host = trim((string) ($endpoint['host'] ?? $endpoint['public_host'] ?? ''));
            $port = (int) ($endpoint['port'] ?? $endpoint['public_port'] ?? 0);
            $ingressAuthToken = trim((string) ($endpoint['ingress_auth_token'] ?? ''));
            if (isset($eligibleNodeIds[$nodeId])
                && (int) ($endpoint['endpoint_id'] ?? $endpoint['id'] ?? 0) > 0
                && $host !== ''
                && $port >= 1
                && $port <= 65535
                && $ingressAuthToken !== '') {
                $hasSlaveExecutionGroup = true;
                break;
            }
        }
        if (!$hasSlaveExecutionGroup) {
            return $localParallelCatalogIds;
        }
        return $clientCatalogIds;
    }

    /** @param array<int, int> $catalogIds @return array<int, int> */
    private function localParallelCatalogIds(array $catalogIds): array
    {
        $placeholders = [];
        $params = [];
        foreach (array_values(array_unique(array_map('intval', $catalogIds))) as $index => $catalogId) {
            if ($catalogId <= 0) {
                continue;
            }
            $key = 'local_parallel_catalog_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $catalogId;
        }
        if ($placeholders === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT /* hybrid_local_parallel_catalog_ids */ id AS catalog_id
             FROM leigod_lines
             WHERE id IN (' . implode(', ', $placeholders) . ')
               AND is_active = 1
               AND allow_client_execution = 1
               AND max_parallel_lanes BETWEEN 2 AND 4',
            $params
        );
        $eligible = [];
        foreach ($rows as $row) {
            $catalogId = (int) ($row['catalog_id'] ?? 0);
            if ($catalogId > 0) {
                $eligible[$catalogId] = true;
            }
        }
        return array_values(array_filter(
            $catalogIds,
            static fn(int $catalogId): bool => isset($eligible[$catalogId])
        ));
    }

    /** @param array<string, mixed> $client @param array<string, mixed> $session */
    public function supportsClientDevice(array $client, array $session): bool
    {
        if (!$this->config->getBool('LEIGOD_LINUX_HYBRID_ENABLED', false)
            || !$this->config->getBool('LEIGOD_EXECUTION_LEASES_ENABLED', false)
            || (int) ($client['id'] ?? 0) <= 0
            || trim((string) ($session['device_id'] ?? '')) === '') {
            return false;
        }
        $platform = strtolower(trim((string) ($session['platform'] ?? '')));
        if (!str_starts_with($platform, 'linux')) {
            return false;
        }
        $expectedVersion = trim((string) ($this->pathdArtifact['version'] ?? ''));
        $expectedSha = strtolower(trim((string) ($this->pathdArtifact['sha256'] ?? $this->pathdArtifact['binary_sha256'] ?? '')));
        $capability = $this->pathdCapability($session, $client);
        return $expectedVersion !== ''
            && preg_match('/^[a-f0-9]{64}$/', $expectedSha) === 1
            && hash_equals($expectedVersion, (string) ($capability['version'] ?? ''))
            && hash_equals($expectedSha, strtolower((string) ($capability['sha256'] ?? '')));
    }

    /** @param array<string, mixed> $forward @param array<string, mixed> $client @param array<string, mixed> $session */
    private function eligibleRequest(array $forward, array $client, array $session): bool
    {
        if (!$this->supportsClientDevice($client, $session)) {
            return false;
        }
        $backend = strtolower(trim((string) ($forward['backend_mode'] ?? '')));
        $mode = strtolower(trim((string) ($forward['client_mode'] ?? $forward['mode'] ?? '')));
        $transport = strtolower(trim((string) ($forward['transport_mode'] ?? $forward['transport'] ?? '')));
        if ($backend !== 'leigod_mode'
            || !in_array($mode, ['bond', 'bonding', 'aggregate', 'aggregated'], true)
            || $transport !== 'tcp'
            || (int) ($forward['id'] ?? $forward['forward_id'] ?? 0) <= 0
            || trim((string) ($forward['target_host'] ?? '')) === ''
            || (int) ($forward['target_port'] ?? 0) < 1
            || (int) ($forward['target_port'] ?? 0) > 65535) {
            return false;
        }
        return true;
    }

    /** @return array<int, array<string, mixed>> */
    /** @param array<int, int> $approvedCatalogIds */
    private function localLeases(int $clientId, string $deviceId, array $approvedCatalogIds): array
    {
        $placeholders = [];
        $catalogParams = [];
        foreach (array_values(array_unique(array_map('intval', $approvedCatalogIds))) as $index => $catalogId) {
            if ($catalogId <= 0) {
                continue;
            }
            $key = 'hybrid_catalog_' . $index;
            $placeholders[] = ':' . $key;
            $catalogParams[$key] = $catalogId;
        }
        if ($placeholders === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT /* hybrid_local_leases */
                    ll.id AS catalog_id,
                    ll.line_id AS physical_line_id,
                    ll.sort_order AS catalog_sort_order,
                    ll.config_sha256,
                    lel.lease_id,
                    lel.generation AS lease_generation,
                    lel.state AS lease_state,
                    lel.max_parallel_lanes,
                    lel.expires_at AS lease_expires_at
             FROM leigod_execution_leases lel
             INNER JOIN leigod_lines ll ON ll.id = lel.leigod_line_id
             WHERE lel.owner_type = :owner_type
               AND lel.owner_id = :owner_id
               AND lel.owner_device_id = :owner_device_id
               AND lel.state = :active
               AND lel.expires_at > UTC_TIMESTAMP()
               AND ll.is_active = 1
               AND ll.allow_client_execution = 1
               AND ll.id IN (' . implode(', ', $placeholders) . ')
             ORDER BY ll.sort_order ASC, ll.id ASC',
             [
                 'owner_type' => 'client_device',
                'owner_id' => $clientId,
                'owner_device_id' => $deviceId,
                 'active' => 'active',
             ] + $catalogParams
        );
        $leases = [];
        foreach ($rows as $row) {
            $physicalLineId = (int) ($row['physical_line_id'] ?? 0);
            $maxLanes = max(1, min(4, (int) ($row['max_parallel_lanes'] ?? 1)));
            if ($physicalLineId < 1 || $physicalLineId > 45535
                || (string) ($row['lease_state'] ?? '') !== 'active'
                || !$this->futureTimestamp((string) ($row['lease_expires_at'] ?? ''))
                || !$this->validLease((string) ($row['lease_id'] ?? ''), (int) ($row['lease_generation'] ?? 0))) {
                return [];
            }
            $routes = [];
            for ($lane = 1; $lane <= $maxLanes; $lane++) {
                $routes[] = ['lane' => $lane, 'route_id' => $this->routeId($physicalLineId, $lane)];
            }
            $leases[] = [
                'execution_group' => 'client:' . $clientId . ':' . $deviceId,
                'catalog_id' => (int) ($row['catalog_id'] ?? 0),
                'catalog_sort_order' => (int) ($row['catalog_sort_order'] ?? 100),
                'physical_line_id' => $physicalLineId,
                'lease_id' => strtolower((string) $row['lease_id']),
                'lease_generation' => (int) $row['lease_generation'],
                'max_lanes' => $maxLanes,
                'routes' => $routes,
            ];
        }
        usort($leases, static fn(array $left, array $right): int => [
            (string) $left['execution_group'],
            (int) $left['catalog_sort_order'],
            (int) $left['physical_line_id'],
        ] <=> [
            (string) $right['execution_group'],
            (int) $right['catalog_sort_order'],
            (int) $right['physical_line_id'],
        ]);
        return $leases;
    }

    /** @return array<string, mixed>|null */
    private function egressProfile(int $routeId): ?array
    {
        if ($routeId <= 0) {
            return null;
        }
        $profile = $this->db->fetchOne(
            'SELECT lep.*
             FROM forward_routes fr
             INNER JOIN leigod_egress_profiles lep ON lep.id = fr.leigod_egress_profile_id
             WHERE fr.id = :route_id AND fr.is_active = 1 AND lep.is_enabled = 1
             LIMIT 1',
            ['route_id' => $routeId]
        );
        $host = trim((string) ($profile['host'] ?? ''));
        $port = (int) ($profile['tcp_port'] ?? 0);
        return is_array($profile)
            && (int) ($profile['id'] ?? 0) > 0
            && (int) ($profile['is_enabled'] ?? 0) === 1
            && $host !== ''
            && $port >= 1
            && $port <= 65535
            ? $profile
            : null;
    }

    /** @param array<string, mixed>|null $profile */
    private function egressSecret(?array $profile): ?string
    {
        $secretRef = trim((string) ($profile['secret_ref'] ?? ''));
        if (preg_match('/^[A-Z][A-Z0-9_]{2,190}$/', $secretRef) !== 1) {
            return null;
        }
        $secret = (string) ($this->config->get($secretRef, '') ?? '');
        return $secret !== '' ? $secret : null;
    }

    /** @param array<string, mixed> $session @param array<string, mixed> $client @return array<string, mixed> */
    private function pathdCapability(array $session, array $client): array
    {
        foreach ([$session['pathd_capability'] ?? null, $session['capabilities_json'] ?? null, $client['agent_config_json'] ?? null] as $value) {
            if (is_string($value)) {
                $value = json_decode($value, true);
            }
            if (!is_array($value)) {
                continue;
            }
            if (is_array($value['leikwan_pathd'] ?? null)) {
                $value = $value['leikwan_pathd'];
            } elseif (is_array($value['pathd'] ?? null)) {
                $value = $value['pathd'];
            }
            if (isset($value['version']) || isset($value['sha256'])) {
                return $value;
            }
        }
        return [];
    }

    /** @return array<string, mixed> */
    private function manifestArtifact(): array
    {
        $path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'artifacts' . DIRECTORY_SEPARATOR . 'versions.json';
        $manifest = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $entry = is_array($manifest) ? ($manifest['leikwan_pathd_linux_amd64'] ?? null) : null;
        return is_array($entry) ? $entry : [];
    }

    /** @param array<string, mixed> $forward */
    private function tokenMatchesForward(string $token, string $secret, array $forward): bool
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return false;
        }
        $payload = $this->base64UrlDecode($parts[0]);
        $signature = $this->base64UrlDecode($parts[1]);
        if ($payload === null || $signature === null
            || !hash_equals(hash_hmac('sha256', $payload, $secret, true), $signature)) {
            return false;
        }
        $claims = json_decode($payload, true);
        return is_array($claims)
            && hash_equals(trim((string) ($forward['target_host'] ?? '')), (string) ($claims['target_host'] ?? ''))
            && (int) ($claims['target_port'] ?? 0) === (int) ($forward['target_port'] ?? 0)
            && (string) ($claims['target_protocol'] ?? '') === 'tcp'
            && (int) ($claims['forward_id'] ?? 0) === (int) ($forward['id'] ?? $forward['forward_id'] ?? 0);
    }

    private function routeId(int $physicalLineId, int $lane): int
    {
        return $lane === 1
            ? $physicalLineId
            : self::PARALLEL_ROUTE_BASE + ($physicalLineId * self::PARALLEL_ROUTE_MULTIPLIER) + $lane;
    }

    private function validLease(string $leaseId, int $generation): bool
    {
        return $generation > 0 && preg_match(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/',
            strtolower(trim($leaseId))
        ) === 1;
    }

    private function futureTimestamp(string $value): bool
    {
        $value = trim($value);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        return $parsed instanceof \DateTimeImmutable
            && $parsed->format('Y-m-d H:i:s') === $value
            && $parsed->getTimestamp() > time();
    }

    private function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        return is_string($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $value */
    private function canonicalJson(array $value): string
    {
        $normalize = static function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (array_is_list($item)) {
                return array_map($normalize, $item);
            }
            ksort($item, SORT_STRING);
            foreach ($item as $key => $nested) {
                $item[$key] = $normalize($nested);
            }
            return $item;
        };
        return (string) json_encode($normalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
