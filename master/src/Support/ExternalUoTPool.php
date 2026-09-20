<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Config;
use RuntimeException;

/** Opt-in server-side pool. Client wire format remains a single sidecar_target. */
final class ExternalUoTPool
{
    private array $nodes = [];

    public function __construct(private Config $config)
    {
        $raw = trim((string) $config->get('EXTERNAL_UOT_POOL_JSON', ''));
        if ($raw === '') {
            return;
        }
        $nodes = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($nodes) || !array_is_list($nodes) || count($nodes) > 128) {
            throw new RuntimeException('invalid external UoT pool');
        }
        $targets = [];
        foreach ($nodes as $node) {
            if (!is_array($node) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/', (string) ($node['id'] ?? ''))
                || !self::validTarget((string) ($node['target'] ?? ''))
                || strlen((string) ($node['secret'] ?? '')) < 32
                || !is_array($node['origins'] ?? null) || $node['origins'] === []) {
                throw new RuntimeException('invalid external UoT pool node');
            }
            foreach ($node['origins'] as $origin) {
                if (!is_string($origin) || !self::validTarget($origin)) {
                    throw new RuntimeException('invalid external UoT pool origin');
                }
            }
            if (isset($this->nodes[$node['id']]) || isset($targets[$node['target']])) {
                throw new RuntimeException('duplicate external UoT pool node');
            }
            $targets[$node['target']] = true;
            $this->nodes[$node['id']] = $node;
        }
    }

    private static function validTarget(string $target): bool
    {
        return preg_match('/^(?:\[[0-9a-fA-F:]+\]|[a-zA-Z0-9._-]+):([0-9]{1,5})$/', $target, $m) === 1
            && (int) $m[1] > 0 && (int) $m[1] <= 65535;
    }

    public function authenticate(string $id, string $secret): ?array
    {
        $node = $this->nodes[$id] ?? null;
        return $node !== null && $secret !== '' && hash_equals($node['secret'], $secret) ? $node : null;
    }

    private function path(string $id): string
    {
        $dir = (string) $this->config->get('EXTERNAL_UOT_POOL_STATE_DIR', dirname(__DIR__, 2) . '/storage/uot-pool');
        return rtrim($dir, '/\\') . '/' . hash('sha256', $id) . '.json';
    }

    public function heartbeat(string $id, bool $healthy, string $configHash, ?int $now = null): void
    {
        if (!isset($this->nodes[$id]) || preg_match('/^[a-f0-9]{64}$/', $configHash) !== 1) {
            throw new RuntimeException('invalid pool heartbeat');
        }
        $path = $this->path($id);
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
            throw new RuntimeException('pool state directory unavailable');
        }
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('pool state unavailable');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('pool state lock unavailable');
            }
            $data = json_encode(['at' => $now ?? time(), 'healthy' => $healthy, 'config_hash' => $configHash,
                'target' => $this->nodes[$id]['target']], JSON_THROW_ON_ERROR);
            if (!ftruncate($handle, 0) || fwrite($handle, $data) !== strlen($data) || !fflush($handle)) {
                throw new RuntimeException('pool state write failed');
            }
        } finally {
            fclose($handle);
        }
    }

    private function healthy(array $node, int $now): bool
    {
        if (($node['enabled'] ?? true) !== true) {
            return false;
        }
        $handle = @fopen($this->path($node['id']), 'r');
        if ($handle === false) {
            return false;
        }
        try {
            if (!flock($handle, LOCK_SH)) {
                return false;
            }
            $state = json_decode(stream_get_contents($handle), true);
        } finally {
            fclose($handle);
        }
        $age = $now - (int) ($state['at'] ?? 0);
        return ($state['healthy'] ?? false) === true && ($state['target'] ?? '') === $node['target']
            && $age >= 0 && $age <= max(10, min(300, $this->config->getInt('EXTERNAL_UOT_POOL_TTL_SECONDS', 45)));
    }

    /** Rendezvous hashing minimizes reassignment; never fall back to native UDP. */
    public function assign(int $clientId, string $deviceId, array $forwards, ?int $now = null): array
    {
        if ($this->nodes === []) {
            return $forwards;
        }
        $healthy = [];
        foreach ($this->nodes as $id => $node) {
            $healthy[$id] = $this->healthy($node, $now ?? time());
        }
        foreach ($forwards as &$forward) {
            $external = $forward['external_uot'] ?? null;
            if (!is_array($external) || ($external['enabled'] ?? false) !== true) {
                continue;
            }
            $origin = (string) ($external['sidecar_target'] ?? '');
            $managed = false;
            $winner = null;
            $best = '';
            foreach ($this->nodes as $id => $node) {
                if (!in_array($origin, $node['origins'], true)) {
                    continue;
                }
                $managed = true;
                if (!$healthy[$id] || (($external['delivery_mode'] ?? 'raw') === 'reliable_datagram_v1'
                    && ($node['reliable'] ?? false) !== true)) {
                    continue;
                }
                $score = hash('sha256', json_encode([$clientId, $deviceId, $forward['id'] ?? $forward['forward_id'] ?? 0, $id]));
                if ($winner === null || strcmp($score, $best) > 0) {
                    $winner = $node;
                    $best = $score;
                }
            }
            if (!$managed) {
                // No node lists this origin, so the pool cannot manage the
                // forward and leaves its original sidecar_target in place. The
                // forward then fails later at HELLO admission with the opaque
                // "forward is not allowed". Say so here instead, and name the
                // origin, so a new forward that the operator forgot to add to
                // every node's origins is not a silent gap.
                error_log(sprintf(
                    'external UoT pool has no node for origin %s (forward %s, client %d)',
                    $origin,
                    (string) ($forward['id'] ?? $forward['forward_id'] ?? '?'),
                    $clientId
                ));
                continue;
            }
            if ($winner === null) {
                throw new ApiException('external_uot_pool_unavailable', 503);
            }
            if ($winner !== null) {
                $forward['external_uot']['sidecar_target'] = $winner['target'];
            }
        }
        unset($forward);
        return $forwards;
    }

    /** Provision every candidate, including standby nodes, before admitting traffic. */
    public function combinationsForNode(array $node, array $combinations): array
    {
        $result = [];
        foreach ($combinations as $combination) {
            if (in_array($combination['external_uot_sidecar_target'] ?? '', $node['origins'], true)) {
                $combination['external_uot_sidecar_target'] = $node['target'];
                $result[] = $combination;
            }
        }
        return $result;
    }
}
