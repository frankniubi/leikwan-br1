<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;

/**
 * Hosted user nodes and their forward pairings, scoped to one client
 * (clients.id). Every trusted device of the client sees the same nodes and the
 * same forward -> node pairing.
 *
 * Ownership is always "the given client owns this row": every query is keyed
 * by client_id, and a node or forward of another client is reported as not
 * found, never as forbidden.
 *
 * Pairing semantics:
 *  - explicit: a row in client_hosted_node_pairings (set in the console or
 *    from a device);
 *  - target_match: no explicit row, and exactly one hosted node has the same
 *    server:port as the forward's target. The forward already carries bytes to
 *    that address, so that node is the only one that can work through it.
 * Explicit beats target_match. Pairings of forwards the client no longer has,
 * or of deleted nodes, are dropped from every listing.
 */
final class HostedNodeService
{
    public const MAX_NODES_PER_CLIENT = 32;
    public const SOURCE_CONSOLE = 'console';
    public const SOURCE_DEVICE = 'device';

    public function __construct(private Database $db, private HostedNodeVault $vault)
    {
    }

    public static function fromConfig(Config $config, Database $db): self
    {
        return new self($db, HostedNodeVault::fromConfig($config));
    }

    public function available(): bool
    {
        return $this->vault->available();
    }

    /**
     * Decrypted nodes of the client, oldest first. Rows that no longer open
     * (key rotated, tampered) are skipped rather than failing the listing.
     *
     * @return list<array{id:int,name:string,protocol:string,version:int,source:string,created_at:string,updated_at:string,node:array<string,mixed>}>
     */
    public function nodesForClient(int $clientId): array
    {
        if ($clientId <= 0) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT id, name, protocol, sealed_config, version, source, created_at, updated_at
             FROM client_hosted_nodes WHERE client_id = :client_id ORDER BY id ASC',
            ['client_id' => $clientId]
        );
        $nodes = [];
        foreach ($rows as $row) {
            $node = $this->decodeRow($clientId, $row);
            if ($node !== null) {
                $nodes[] = $node;
            }
        }
        return $nodes;
    }

    /** @return array<string, mixed>|null */
    public function findNode(int $clientId, int $nodeId): ?array
    {
        if ($clientId <= 0 || $nodeId <= 0) {
            return null;
        }
        $row = $this->db->fetchOne(
            'SELECT id, name, protocol, sealed_config, version, source, created_at, updated_at
             FROM client_hosted_nodes WHERE id = :id AND client_id = :client_id LIMIT 1',
            ['id' => $nodeId, 'client_id' => $clientId]
        );
        return is_array($row) ? $this->decodeRow($clientId, $row) : null;
    }

    /**
     * Store a node for the client. Uploading the same connection again
     * (same protocol/server/port/settings) returns the existing node instead
     * of creating a duplicate, so a device can retry an upload safely.
     *
     * @param array<string, mixed> $input share link ('uri') or structured fields
     * @return array{node:array<string,mixed>,created:bool}
     */
    public function create(int $clientId, array $input, string $source, ?int $userId = null): array
    {
        $this->assertAvailable($clientId);
        $node = HostedNodeSpec::normalize($input);
        $fingerprint = $this->vault->fingerprint($clientId, $node);
        $source = $this->normalizeSource($source);

        return $this->db->transaction(function () use ($clientId, $node, $fingerprint, $source, $userId): array {
            $existing = $this->db->fetchOne(
                'SELECT id FROM client_hosted_nodes WHERE client_id = :client_id AND fingerprint = :fingerprint LIMIT 1',
                ['client_id' => $clientId, 'fingerprint' => $fingerprint]
            );
            if (is_array($existing)) {
                $found = $this->findNode($clientId, (int) $existing['id']);
                if ($found !== null) {
                    return ['node' => $found, 'created' => false];
                }
            }
            $count = $this->db->fetchOne(
                'SELECT COUNT(*) AS total FROM client_hosted_nodes WHERE client_id = :client_id',
                ['client_id' => $clientId]
            );
            if ((int) ($count['total'] ?? 0) >= self::MAX_NODES_PER_CLIENT) {
                throw new ApiException('hosted_node_limit_reached', 409, ['limit' => self::MAX_NODES_PER_CLIENT]);
            }
            $now = gmdate('Y-m-d H:i:s');
            $id = $this->db->insert('client_hosted_nodes', [
                'client_id' => $clientId,
                'name' => $node['name'],
                'protocol' => $node['protocol'],
                'sealed_config' => $this->vault->seal($clientId, $node),
                'fingerprint' => $fingerprint,
                'version' => 1,
                'source' => $source,
                'created_by_user_id' => $userId !== null && $userId > 0 ? $userId : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $created = $this->findNode($clientId, $id);
            if ($created === null) {
                throw new ApiException('hosted_nodes_unavailable', 503);
            }
            return ['node' => $created, 'created' => true];
        });
    }

    /**
     * Replace a node's connection settings and/or name. Pairings follow the
     * node id, so every device picks up the change on its next sync.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(int $clientId, int $nodeId, array $input): array
    {
        $this->assertAvailable($clientId);
        $current = $this->findNode($clientId, $nodeId);
        if ($current === null) {
            throw new ApiException('hosted_node_not_found', 404);
        }
        $hasConnection = trim((string) ($input['uri'] ?? '')) !== '' || trim((string) ($input['protocol'] ?? '')) !== '';
        if ($hasConnection) {
            $node = HostedNodeSpec::normalize($input);
        } else {
            $node = $current['node'];
            $node['name'] = (string) ($input['name'] ?? $node['name']);
            $node = HostedNodeSpec::validate($node);
        }
        $fingerprint = $this->vault->fingerprint($clientId, $node);
        $clash = $this->db->fetchOne(
            'SELECT id FROM client_hosted_nodes WHERE client_id = :client_id AND fingerprint = :fingerprint AND id <> :id LIMIT 1',
            ['client_id' => $clientId, 'fingerprint' => $fingerprint, 'id' => $nodeId]
        );
        if (is_array($clash)) {
            throw new ApiException('hosted_node_duplicate', 409);
        }
        $this->db->execute(
            'UPDATE client_hosted_nodes
             SET name = :name, protocol = :protocol, sealed_config = :sealed_config, fingerprint = :fingerprint,
                 version = version + 1, updated_at = :updated_at
             WHERE id = :id AND client_id = :client_id',
            [
                'name' => $node['name'],
                'protocol' => $node['protocol'],
                'sealed_config' => $this->vault->seal($clientId, $node),
                'fingerprint' => $fingerprint,
                'updated_at' => gmdate('Y-m-d H:i:s'),
                'id' => $nodeId,
                'client_id' => $clientId,
            ]
        );
        return $this->findNode($clientId, $nodeId) ?? throw new ApiException('hosted_node_not_found', 404);
    }

    public function delete(int $clientId, int $nodeId): bool
    {
        if ($clientId <= 0 || $nodeId <= 0) {
            return false;
        }
        return (bool) $this->db->transaction(function () use ($clientId, $nodeId): bool {
            $deleted = $this->db->execute(
                'DELETE FROM client_hosted_nodes WHERE id = :id AND client_id = :client_id',
                ['id' => $nodeId, 'client_id' => $clientId]
            );
            $this->db->execute(
                'DELETE FROM client_hosted_node_pairings WHERE client_id = :client_id AND node_id = :node_id',
                ['client_id' => $clientId, 'node_id' => $nodeId]
            );
            return $deleted > 0;
        });
    }

    /**
     * Pair a forward of the client with one of its hosted nodes, or clear the
     * explicit pairing with $nodeId = null (target matching then applies).
     *
     * @param array<string, mixed> $client
     */
    public function setPairing(array $client, int $forwardId, ?int $nodeId, string $source): void
    {
        $clientId = (int) ($client['id'] ?? 0);
        if ($clientId <= 0 || $forwardId <= 0 || !$this->forwardBelongsToClient($client, $forwardId)) {
            throw new ApiException('hosted_node_forward_not_found', 404);
        }
        if ($nodeId !== null) {
            $exists = $this->db->fetchOne(
                'SELECT id FROM client_hosted_nodes WHERE id = :id AND client_id = :client_id LIMIT 1',
                ['id' => $nodeId, 'client_id' => $clientId]
            );
            if (!is_array($exists)) {
                throw new ApiException('hosted_node_not_found', 404);
            }
        }
        $source = $this->normalizeSource($source);
        $this->db->transaction(function () use ($clientId, $forwardId, $nodeId, $source): void {
            $this->db->execute(
                'DELETE FROM client_hosted_node_pairings WHERE client_id = :client_id AND forward_id = :forward_id',
                ['client_id' => $clientId, 'forward_id' => $forwardId]
            );
            if ($nodeId !== null) {
                $this->db->insert('client_hosted_node_pairings', [
                    'client_id' => $clientId,
                    'forward_id' => $forwardId,
                    'node_id' => $nodeId,
                    'source' => $source,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            }
        });
    }

    /**
     * Enabled, not user-deleted forwards that are delivered to this client
     * (same assignment rule as ProvisioningService::assignedForwardConfigsForClient).
     *
     * @param array<string, mixed> $client
     * @return list<array{id:int,service_id:int,listen_port:int,target_host:string,target_port:int}>
     */
    public function forwardsForClient(array $client): array
    {
        $clientId = (int) ($client['id'] ?? 0);
        if ($clientId <= 0) {
            return [];
        }
        $groupId = (int) ($client['group_id'] ?? 0);
        $sql = 'SELECT id, service_id, listen_port, target_host, target_port,
                       line_client_id, line_client_group_id, line_client_group_ids_json
                FROM forwards
                WHERE enabled = 1 AND user_deleted_at IS NULL AND (line_client_id = :client_id';
        $params = ['client_id' => $clientId];
        if ($groupId > 0) {
            $sql .= ' OR line_client_group_id = :group_id OR line_client_group_ids_json IS NOT NULL';
            $params['group_id'] = $groupId;
        }
        $sql .= ') ORDER BY id ASC';
        $forwards = [];
        foreach ($this->db->fetchAll($sql, $params) as $row) {
            if (!$this->rowBelongsToClient($row, $clientId, $groupId)) {
                continue;
            }
            $forwards[] = [
                'id' => (int) $row['id'],
                'service_id' => (int) ($row['service_id'] ?? 0),
                'listen_port' => (int) ($row['listen_port'] ?? 0),
                'target_host' => (string) ($row['target_host'] ?? ''),
                'target_port' => (int) ($row['target_port'] ?? 0),
            ];
        }
        return $forwards;
    }

    /** @param array<string, mixed> $client */
    public function forwardBelongsToClient(array $client, int $forwardId): bool
    {
        $clientId = (int) ($client['id'] ?? 0);
        $row = $this->db->fetchOne(
            'SELECT id, line_client_id, line_client_group_id, line_client_group_ids_json
             FROM forwards WHERE id = :id AND user_deleted_at IS NULL LIMIT 1',
            ['id' => $forwardId]
        );
        return is_array($row) && $this->rowBelongsToClient($row, $clientId, (int) ($client['group_id'] ?? 0));
    }

    /**
     * @param array<string, mixed> $client
     * @param list<array<string, mixed>>|null $nodes
     * @param list<array<string, mixed>>|null $forwards
     * @return list<array{forward_id:int,node_id:int,source:string}>
     */
    public function effectivePairings(array $client, ?array $nodes = null, ?array $forwards = null): array
    {
        $clientId = (int) ($client['id'] ?? 0);
        $nodes ??= $this->nodesForClient($clientId);
        if ($nodes === []) {
            return [];
        }
        $forwards ??= $this->forwardsForClient($client);
        $nodeIds = [];
        $byEndpoint = [];
        foreach ($nodes as $node) {
            $nodeIds[(int) $node['id']] = true;
            $key = self::endpointKey((string) $node['node']['server'], (int) $node['node']['port']);
            $byEndpoint[$key][] = (int) $node['id'];
        }
        $explicit = [];
        foreach ($this->db->fetchAll(
            'SELECT forward_id, node_id FROM client_hosted_node_pairings WHERE client_id = :client_id',
            ['client_id' => $clientId]
        ) as $row) {
            $explicit[(int) $row['forward_id']] = (int) $row['node_id'];
        }

        $pairings = [];
        foreach ($forwards as $forward) {
            $forwardId = (int) $forward['id'];
            $explicitNode = $explicit[$forwardId] ?? 0;
            if ($explicitNode > 0 && isset($nodeIds[$explicitNode])) {
                $pairings[] = ['forward_id' => $forwardId, 'node_id' => $explicitNode, 'source' => 'explicit'];
                continue;
            }
            $matches = $byEndpoint[self::endpointKey((string) $forward['target_host'], (int) $forward['target_port'])] ?? [];
            if (count($matches) === 1) {
                $pairings[] = ['forward_id' => $forwardId, 'node_id' => $matches[0], 'source' => 'target_match'];
            }
        }
        return $pairings;
    }

    /**
     * What a trusted device of the client receives: full node definitions
     * (credentials included, plus the canonical share link) and the effective
     * pairings. Callers must only hand this to the owning client.
     *
     * @param array<string, mixed> $client
     * @return array{revision:string,nodes:list<array<string,mixed>>,pairings:list<array<string,mixed>>}
     */
    public function clientListing(array $client): array
    {
        $clientId = (int) ($client['id'] ?? 0);
        $nodes = $this->nodesForClient($clientId);
        $pairings = $nodes === [] ? [] : $this->effectivePairings($client, $nodes);
        $payload = [];
        foreach ($nodes as $node) {
            $canonical = $node['node'];
            $payload[] = [
                'id' => $node['id'],
                'name' => $node['name'],
                'protocol' => $node['protocol'],
                'server' => $canonical['server'],
                'port' => $canonical['port'],
                'settings' => $canonical['settings'],
                'uri' => HostedNodeSpec::toShareUri($canonical),
                'version' => $node['version'],
                'source' => $node['source'],
                'updated_at' => $node['updated_at'],
            ];
        }
        return [
            'revision' => self::revisionFor($nodes, $pairings),
            'nodes' => $payload,
            'pairings' => $pairings,
        ];
    }

    /**
     * Cheap change marker for the config pull: '' when the client has no
     * hosted nodes (so clients without the feature see no change at all),
     * otherwise a digest of node ids/versions and the effective pairings.
     *
     * @param array<string, mixed> $client
     */
    public function revisionForClient(array $client): string
    {
        $clientId = (int) ($client['id'] ?? 0);
        if ($clientId <= 0) {
            return '';
        }
        $any = $this->db->fetchOne(
            'SELECT id FROM client_hosted_nodes WHERE client_id = :client_id LIMIT 1',
            ['client_id' => $clientId]
        );
        if (!is_array($any)) {
            return '';
        }
        $nodes = $this->nodesForClient($clientId);
        return self::revisionFor($nodes, $this->effectivePairings($client, $nodes));
    }

    /**
     * Non-secret node list for the tenant console.
     *
     * @return list<array<string, mixed>>
     */
    public function consoleNodes(int $clientId): array
    {
        $list = [];
        foreach ($this->nodesForClient($clientId) as $node) {
            $list[] = ['id' => $node['id'], 'source' => $node['source'], 'updated_at' => $node['updated_at']]
                + HostedNodeSpec::publicSummary($node['node']);
        }
        return $list;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param list<array<string, mixed>> $pairings
     */
    private static function revisionFor(array $nodes, array $pairings): string
    {
        if ($nodes === []) {
            return '';
        }
        $projection = [];
        foreach ($nodes as $node) {
            $projection[] = [(int) $node['id'], (int) $node['version']];
        }
        return hash('sha256', (string) json_encode(['nodes' => $projection, 'pairings' => $pairings], JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function decodeRow(int $clientId, array $row): ?array
    {
        $opened = $this->vault->open($clientId, (string) ($row['sealed_config'] ?? ''));
        if ($opened === null) {
            return null;
        }
        try {
            $node = HostedNodeSpec::validate($opened);
        } catch (ApiException) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'name' => (string) ($row['name'] ?? $node['name']),
            'protocol' => $node['protocol'],
            'version' => max(1, (int) ($row['version'] ?? 1)),
            'source' => (string) ($row['source'] ?? self::SOURCE_CONSOLE),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'node' => $node,
        ];
    }

    /** @param array<string, mixed> $row */
    private function rowBelongsToClient(array $row, int $clientId, int $groupId): bool
    {
        if ($clientId > 0 && (int) ($row['line_client_id'] ?? 0) === $clientId) {
            return true;
        }
        if ($groupId <= 0) {
            return false;
        }
        if ((int) ($row['line_client_group_id'] ?? 0) === $groupId) {
            return true;
        }
        $raw = $row['line_client_group_ids_json'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $id) {
                    if ((int) $id === $groupId) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    private static function endpointKey(string $host, int $port): string
    {
        $host = strtolower(trim($host));
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $host = (string) inet_ntop((string) inet_pton($host));
        }
        return $host . '|' . $port;
    }

    private function normalizeSource(string $source): string
    {
        return $source === self::SOURCE_DEVICE ? self::SOURCE_DEVICE : self::SOURCE_CONSOLE;
    }

    private function assertAvailable(int $clientId): void
    {
        if ($clientId <= 0) {
            throw new ApiException('hosted_node_client_invalid', 422);
        }
        if (!$this->vault->available()) {
            throw new ApiException('hosted_nodes_unavailable', 503);
        }
    }
}
