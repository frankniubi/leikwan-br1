<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;

/**
 * Owns the short-lived, private debug-log collection contract.
 *
 * Collection metadata is kept on the client row so a config pull can expose
 * it without creating a second authenticated lookup. Chunks are immutable and
 * keyed by (client, collection, sequence); the byte counter is updated while
 * the client row is locked in a transaction.
 */
final class DebugCollectionStore
{
    public const BYTE_LIMIT = 104857600; // 100 MiB
    public const MAX_CHUNK_BYTES = 262144; // 256 KiB decoded
    public const DEFAULT_TTL_SECONDS = 1800;

    public function __construct(
        private Database $db,
        private Config $config,
    ) {
    }

    /** @return array{uuid:string,expires_at:string,status:string,byte_limit:int,bytes_received:int} */
    public function create(int $clientId, ?int $ttlSeconds = null): array
    {
        if ($clientId <= 0) {
            throw new ApiException('client_not_found', 404);
        }
        $configuredTtl = $ttlSeconds ?? $this->config->getInt('CLIENT_DEBUG_COLLECTION_TTL_SECONDS', self::DEFAULT_TTL_SECONDS);
        $ttl = max(60, min(86400, $configuredTtl));
        $uuid = $this->uuid4();
        $expiresTs = time() + $ttl;
        $expiresAt = gmdate('Y-m-d H:i:s', $expiresTs);
        $now = gmdate('Y-m-d H:i:s');

        $this->db->execute(
            'UPDATE clients
             SET debug_collection_uuid = :uuid,
                 debug_collection_expires_at = :expires_at,
                 debug_collection_status = :status,
                 debug_collection_bytes = 0,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $clientId,
                'uuid' => $uuid,
                'expires_at' => $expiresAt,
                'status' => 'active',
                'updated_at' => $now,
            ]
        );

        return [
            'uuid' => $uuid,
            'expires_at' => gmdate('c', $expiresTs),
            'status' => 'active',
            'byte_limit' => self::BYTE_LIMIT,
            'bytes_received' => 0,
        ];
    }

    /** @return array{uuid:string,expires_at:string,status:string,byte_limit:int,bytes_received:int}|null */
    public function metadata(array $client): ?array
    {
        $uuid = trim((string) ($client['debug_collection_uuid'] ?? ''));
        if ($uuid === '') {
            return null;
        }
        $expiresRaw = trim((string) ($client['debug_collection_expires_at'] ?? ''));
        $expiresTs = $expiresRaw !== '' ? strtotime($expiresRaw . ' UTC') : false;
        $status = strtolower(trim((string) ($client['debug_collection_status'] ?? 'closed')));
        if ($status === 'active' && ($expiresTs === false || $expiresTs <= time())) {
            $status = 'expired';
            $this->db->execute(
                'UPDATE clients SET debug_collection_status = :status, updated_at = :updated_at
                 WHERE id = :id AND debug_collection_uuid = :uuid AND debug_collection_status = :active',
                [
                    'status' => 'expired',
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                    'id' => (int) ($client['id'] ?? 0),
                    'uuid' => $uuid,
                    'active' => 'active',
                ]
            );
        }
        return [
            'uuid' => $uuid,
            'expires_at' => $expiresTs !== false ? gmdate('c', $expiresTs) : '',
            'status' => $status,
            'byte_limit' => self::BYTE_LIMIT,
            'bytes_received' => max(0, (int) ($client['debug_collection_bytes'] ?? 0)),
        ];
    }

    /**
     * Append one authenticated chunk. The operation is idempotent for an
     * identical (uuid, sequence, hash, length) retry and rejects conflicts.
     *
     * @return array{accepted:bool,duplicate:bool,bytes_received:int,byte_limit:int}
     */
    public function append(array $client, string $uuid, int $sequence, string $sha256, string $encodedChunk): array
    {
        $clientId = (int) ($client['id'] ?? 0);
        $uuid = strtolower(trim($uuid));
        $sha256 = strtolower(trim($sha256));
        if ($clientId <= 0 || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid)) {
            throw new ApiException('debug_collection_invalid', 422);
        }
        if ($sequence < 0 || $sequence > 1000000000 || !preg_match('/^[0-9a-f]{64}$/', $sha256)) {
            throw new ApiException('debug_chunk_invalid', 422);
        }
        $maxEncodedBytes = (int) (ceil(self::MAX_CHUNK_BYTES / 3) * 4) + 16;
        if (!is_string($encodedChunk) || strlen($encodedChunk) > $maxEncodedBytes) {
            throw new ApiException('debug_chunk_too_large', 413);
        }
        $bytes = base64_decode($encodedChunk, true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_CHUNK_BYTES) {
            throw new ApiException('debug_chunk_invalid', 422);
        }
        $length = strlen($bytes);
        if (!hash_equals($sha256, hash('sha256', $bytes))) {
            throw new ApiException('debug_chunk_hash_mismatch', 422);
        }

        $root = $this->storageRoot();
        $directory = $root . DIRECTORY_SEPARATOR . (string) $clientId . DIRECTORY_SEPARATOR . $uuid;
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new ApiException('debug_storage_unavailable', 503);
        }
        @chmod($root, 0700);
        @chmod($directory, 0700);
        $filename = $sequence . '-' . $sha256 . '-' . bin2hex(random_bytes(8)) . '.logchunk';
        $path = $directory . DIRECTORY_SEPARATOR . $filename;
        if (file_put_contents($path, $bytes, LOCK_EX) !== $length) {
            @unlink($path);
            throw new ApiException('debug_storage_unavailable', 503);
        }
        @chmod($path, 0600);

        try {
            $result = $this->db->transaction(function (Database $db) use ($clientId, $uuid, $sequence, $sha256, $length, $path): array {
                $lockSuffix = (string) $this->config->get('DB_DRIVER', 'mysql') === 'sqlite' ? '' : ' FOR UPDATE';
                $collection = $db->fetchOne(
                    'SELECT id, debug_collection_uuid, debug_collection_expires_at, debug_collection_status, debug_collection_bytes
                     FROM clients WHERE id = :id AND debug_collection_uuid = :uuid LIMIT 1' . $lockSuffix,
                    ['id' => $clientId, 'uuid' => $uuid]
                );
                if ($collection === null) {
                    throw new ApiException('debug_collection_not_found', 404);
                }
                $expiresTs = strtotime((string) ($collection['debug_collection_expires_at'] ?? '') . ' UTC');
                if (strtolower((string) ($collection['debug_collection_status'] ?? '')) !== 'active'
                    || $expiresTs === false
                    || $expiresTs <= time()) {
                    $db->execute(
                        'UPDATE clients SET debug_collection_status = :status, updated_at = :updated_at WHERE id = :id AND debug_collection_uuid = :uuid',
                        ['status' => 'expired', 'updated_at' => gmdate('Y-m-d H:i:s'), 'id' => $clientId, 'uuid' => $uuid]
                    );
                    throw new ApiException('debug_collection_expired', 410);
                }

                $existing = $db->fetchOne(
                    'SELECT chunk_sha256, byte_length FROM client_debug_chunks
                     WHERE client_id = :client_id AND collection_uuid = :uuid AND sequence = :sequence LIMIT 1',
                    ['client_id' => $clientId, 'uuid' => $uuid, 'sequence' => $sequence]
                );
                if ($existing !== null) {
                    if (hash_equals($sha256, strtolower((string) ($existing['chunk_sha256'] ?? '')))
                        && (int) ($existing['byte_length'] ?? -1) === $length) {
                        return [
                            'accepted' => false,
                            'duplicate' => true,
                            'bytes_received' => max(0, (int) ($collection['debug_collection_bytes'] ?? 0)),
                            'byte_limit' => self::BYTE_LIMIT,
                        ];
                    }
                    throw new ApiException('debug_chunk_replay', 409);
                }

                $received = max(0, (int) ($collection['debug_collection_bytes'] ?? 0));
                if ($received > self::BYTE_LIMIT - $length) {
                    throw new ApiException('debug_collection_quota_exceeded', 413, [
                        'byte_limit' => self::BYTE_LIMIT,
                        'bytes_received' => $received,
                    ]);
                }
                $db->insert('client_debug_chunks', [
                    'client_id' => $clientId,
                    'collection_uuid' => $uuid,
                    'sequence' => $sequence,
                    'chunk_sha256' => $sha256,
                    'byte_length' => $length,
                    'storage_path' => $path,
                    'created_at' => gmdate('Y-m-d H:i:s'),
                ]);
                $next = $sequence + 1;
                $db->execute(
                    'UPDATE clients
                     SET debug_collection_bytes = debug_collection_bytes + :bytes,
                         debug_collection_next_sequence = CASE WHEN debug_collection_next_sequence < :next_sequence_check THEN :next_sequence_value ELSE debug_collection_next_sequence END,
                         updated_at = :updated_at
                     WHERE id = :id AND debug_collection_uuid = :uuid',
                    [
                        'bytes' => $length,
                        'next_sequence_check' => $next,
                        'next_sequence_value' => $next,
                        'updated_at' => gmdate('Y-m-d H:i:s'),
                        'id' => $clientId,
                        'uuid' => $uuid,
                    ]
                );
                return [
                    'accepted' => true,
                    'duplicate' => false,
                    'bytes_received' => $received + $length,
                    'byte_limit' => self::BYTE_LIMIT,
                ];
            });
            if (($result['duplicate'] ?? false) === true) {
                @unlink($path);
            }
            return $result;
        } catch (\Throwable $throwable) {
            @unlink($path);
            throw $throwable;
        }
    }

    private function storageRoot(): string
    {
        $configured = trim((string) ($this->config->get('CLIENT_DEBUG_LOG_DIR', '') ?? ''));
        $root = $configured !== '' ? $configured : dirname(__DIR__, 2) . '/storage/client-debug';
        if ($root === '' || str_contains($root, "\0")) {
            throw new ApiException('debug_storage_unavailable', 503);
        }
        return rtrim($root, '/\\');
    }

    private function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
