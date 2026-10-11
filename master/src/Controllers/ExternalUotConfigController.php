<?php
declare(strict_types=1);

namespace Leikwan\Master\Controllers;

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;
use Throwable;
use Leikwan\Master\Support\ExternalUoTPool;

/**
 * Per-host configuration for the operator-owned external UoT (UDP over TCP)
 * sidecar that install-external-uot.sh installs as /etc/leikwan-external-uot.json.
 *
 * The document is derived from the live database: every forward whose Ultra
 * combination has external_uot_enabled = 1 and whose external_uot_sidecar_target
 * points at the requesting host, together with that combination's
 * external_uot_token.  A freshly installed sidecar host can therefore be
 * configured without an operator-supplied bundle, which is what the Ultra
 * runtime needs so its external_uot UDP carrier stops depending on a failed
 * native UDP candidate.
 *
 * The body is wire compatible with tools/uot-sidecar's config parser
 * (listen_host, listen_port, the knob names and forwards.<id>.token), so it can
 * be handed to the installer with --config <url> verbatim.  Panel-only metadata
 * lives under the "panel" key, which the sidecar ignores.
 */
final class ExternalUotConfigController extends BaseController
{
    public const DEFAULT_LISTEN_HOST = '0.0.0.0';
    public const DEFAULT_LISTEN_PORT = 7443;
    public const DEFAULT_QUEUE_PACKETS = 256;
    public const DEFAULT_KEEPALIVE_SECONDS = 10;
    public const DEFAULT_SESSION_GRACE_SECONDS = 30;
    public const DEFAULT_FRAME_PAYLOAD_MAX = 65507;
    /**
     * The pool sync agent (sidecar-agent/uot_pool_sync.py) attests every
     * binding with a HELLO to this target plus one DNS round trip, so pool
     * bindings also admit it; nothing else beyond the forward's own target.
     */
    public const POOL_HEALTH_PROBE_TARGETS = ['1.1.1.1:53'];

    public function __construct(Config $config)
    {
        parent::__construct($config, new Database($config));
    }

    /** Dedicated pool credentials; never accept a requested host as authorization. */
    public function poolNode(Request $request, array $params): Response
    {
        $pool = new ExternalUoTPool($this->config);
        $id = (string) ($params['id'] ?? '');
        $node = $pool->authenticate($id, (string) $request->bearerToken());
        if ($node === null) {
            return $this->json(['error' => 'unauthorized'], 401)->withHeaders(['Cache-Control' => 'no-store']);
        }
        $combinations = $pool->combinationsForNode($node, $this->db->fetchAll(
            'SELECT * FROM forward_combinations WHERE external_uot_enabled = 1 ORDER BY id ASC'
        ));
        [$host, $port] = self::splitTarget($node['target']);
        $forwardIds = $this->forwardIdsByCombination($combinations);
        $payload = self::buildPayload(
            null,
            $combinations,
            $forwardIds,
            $port,
            $host,
            null,
            $this->forwardTargets($forwardIds),
            self::POOL_HEALTH_PROBE_TARGETS
        );
        unset($payload['panel']);
        $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if ($request->method() === 'POST') {
            $body = $request->body();
            if (!is_bool($body['healthy'] ?? null) || !is_string($body['config_hash'] ?? null)
                || !hash_equals($hash, $body['config_hash'])) {
                return $this->json(['error' => 'pool_config_mismatch'], 409)->withHeaders(['Cache-Control' => 'no-store']);
            }
            $pool->heartbeat($id, $body['healthy'], $hash);
            return $this->json(['ok' => true])->withHeaders(['Cache-Control' => 'no-store']);
        }
        return $this->json(['config' => $payload, 'config_hash' => $hash])->withHeaders(['Cache-Control' => 'no-store']);
    }

    /**
     * GET /external-uot-config
     *
     * Renders the sidecar document for exactly one sidecar point, which must
     * prove it is that point with its own agent_token (Authorization: Bearer,
     * or ?agent_token= for operator use). The document carries every
     * forward's sidecar token, so it is never selected by point_id, host, ip
     * or the requesting address: that used to hand any anonymous caller the
     * tokens of whichever host it named. listen_port overrides the port that is
     * otherwise taken from the combination's external_uot_sidecar_target.
     *
     * @param array<string, mixed> $params
     */
    public function sidecarConfig(Request $request, array $params): Response
    {
        $query = $request->query();
        $agentToken = trim((string) ($request->bearerToken() ?? ''));
        $tokenSource = 'bearer';
        if ($agentToken === '') {
            $agentToken = trim((string) ($query['agent_token'] ?? ''));
            $tokenSource = 'query';
        }
        $requestedPort = (int) ($query['listen_port'] ?? 0);
        if ($agentToken === '' || strlen($agentToken) > 191) {
            return $this->json(['error' => 'unauthorized'], 401)->withHeaders(['Cache-Control' => 'no-store']);
        }

        try {
            $point = $this->resolvePoint($agentToken);
            if ($point === null) {
                return $this->json(['error' => 'unauthorized'], 401)->withHeaders(['Cache-Control' => 'no-store']);
            }
            $combinations = $this->db->fetchAll(
                'SELECT id, external_uot_enabled, external_uot_sidecar_target, external_uot_token, '
                . 'external_uot_queue_packets, external_uot_keepalive_seconds, '
                . 'external_uot_reconnect_grace_seconds, external_uot_frame_payload_max '
                . 'FROM forward_combinations WHERE external_uot_enabled = 1 ORDER BY id ASC'
            );
            $forwards = $this->forwardIdsByCombination($combinations);
            $targets = $this->forwardTargets($forwards);
        } catch (Throwable $exception) {
            error_log('external-uot-config: ' . $exception->getMessage());

            return $this->json([
                'error' => 'external_uot_config_unavailable',
                'detail' => 'the panel could not render the external UoT sidecar config; see the panel log',
            ], 503)->withHeaders(['Cache-Control' => 'no-store']);
        }

        $payload = self::buildPayload(
            $point,
            $combinations,
            $forwards,
            $requestedPort,
            '',
            null,
            $targets
        );
        $payload['panel']['request'] = [
            'agent_token' => $tokenSource,
            'listen_port' => $requestedPort > 0 ? $requestedPort : null,
        ];

        return $this->json($payload)->withHeaders(['Cache-Control' => 'no-store']);
    }

    /**
     * Operator-side render for a sidecar host that is not a sidecar point
     * (the standalone external UoT hosts). Used by
     * master/cli/external-uot-tokens.php on the panel host itself; it is never
     * routed, because the document carries every forward's sidecar token.
     *
     * @return array<string, mixed>
     */
    public function renderForHost(string $host, int $listenPort = 0): array
    {
        $combinations = $this->db->fetchAll(
            'SELECT id, external_uot_enabled, external_uot_sidecar_target, external_uot_token, '
            . 'external_uot_queue_packets, external_uot_keepalive_seconds, '
            . 'external_uot_reconnect_grace_seconds, external_uot_frame_payload_max '
            . 'FROM forward_combinations WHERE external_uot_enabled = 1 ORDER BY id ASC'
        );
        $forwards = $this->forwardIdsByCombination($combinations);

        return self::buildPayload(null, $combinations, $forwards, $listenPort, $host, null, $this->forwardTargets($forwards));
    }

    /**
     * @param array<string, mixed>|null $point
     * @param list<array<string, mixed>> $combinations
     * @param array<int, list<int>> $forwardIdsByCombination
     * @param array<int, string>|null $forwardTargets forward_id => "host:port".
     *        When given, every forward is bound to its own target instead of
     *        the sidecar's allow-any default; a forward without a usable target
     *        is skipped rather than published unbound.
     * @param list<string> $extraTargets also admitted by every bound forward
     *        (the pool health probe).
     * @return array<string, mixed>
     */
    public static function buildPayload(
        ?array $point,
        array $combinations,
        array $forwardIdsByCombination,
        int $requestedPort = 0,
        string $requestedHost = '',
        ?string $now = null,
        ?array $forwardTargets = null,
        array $extraTargets = []
    ): array {
        $matchHost = self::normalizeHost((string) ($point['sidecar_host'] ?? $requestedHost));

        $enabled = [];
        $skipped = [];
        foreach ($combinations as $combination) {
            if ((int) ($combination['external_uot_enabled'] ?? 0) !== 1) {
                continue;
            }
            $combinationId = (int) ($combination['id'] ?? 0);
            $target = trim((string) ($combination['external_uot_sidecar_target'] ?? ''));
            $parsed = self::splitTarget($target);
            if ($parsed === null) {
                $skipped[] = ['id' => $combinationId, 'target' => $target, 'reason' => 'unparsable-target'];
                continue;
            }
            [$targetHost, $targetPort] = $parsed;
            if ($matchHost === '' || self::normalizeHost($targetHost) !== $matchHost) {
                continue;
            }
            $enabled[] = [
                'id' => $combinationId,
                'target' => self::normalizeHost($targetHost) . ':' . $targetPort,
                'port' => $targetPort,
                'token' => (string) ($combination['external_uot_token'] ?? ''),
                'queue_packets' => (int) ($combination['external_uot_queue_packets'] ?? 0),
                'keepalive_seconds' => (int) ($combination['external_uot_keepalive_seconds'] ?? 0),
                'session_grace_seconds' => (int) ($combination['external_uot_reconnect_grace_seconds'] ?? 0),
                'frame_payload_max' => (int) ($combination['external_uot_frame_payload_max'] ?? 0),
            ];
        }
        usort($enabled, static function (array $left, array $right): int {
            return $left['id'] <=> $right['id'];
        });

        $effectivePort = $requestedPort;
        $portSource = 'request';
        if ($effectivePort < 1 || $effectivePort > 65535) {
            $effectivePort = self::defaultPortFor($enabled);
            $portSource = $enabled === [] ? 'default' : 'target';
        }

        $forwards = [];
        $combinationsSummary = [];
        $knobs = null;
        foreach ($enabled as $combination) {
            if ($combination['port'] !== $effectivePort) {
                $skipped[] = ['id' => $combination['id'], 'target' => $combination['target'], 'reason' => 'port-mismatch'];
                continue;
            }
            $combinationForwards = $forwardIdsByCombination[$combination['id']] ?? [];
            if ($combinationForwards === []) {
                $skipped[] = ['id' => $combination['id'], 'target' => $combination['target'], 'reason' => 'no-forwards'];
                continue;
            }
            if ($combination['token'] === '') {
                $skipped[] = ['id' => $combination['id'], 'target' => $combination['target'], 'reason' => 'empty-token'];
                continue;
            }
            if ($knobs === null) {
                $knobs = $combination;
            }
            $ids = array_values(array_unique(array_map('intval', $combinationForwards)));
            sort($ids);
            foreach ($ids as $forwardId) {
                $bound = [];
                if ($forwardTargets !== null) {
                    $target = self::normalizeForwardTarget((string) ($forwardTargets[$forwardId] ?? ''));
                    if ($target === null) {
                        $skipped[] = ['id' => $combination['id'], 'forward_id' => $forwardId, 'reason' => 'no-target'];
                        continue;
                    }
                    $bound = [$target];
                    foreach ($extraTargets as $extra) {
                        $extra = self::normalizeForwardTarget($extra);
                        if ($extra !== null && !in_array($extra, $bound, true)) {
                            $bound[] = $extra;
                        }
                    }
                }
                $forwards[$forwardId] = ['token' => $combination['token'], 'targets' => $bound];
            }
            $combinationsSummary[] = [
                'id' => $combination['id'],
                'target' => $combination['target'],
                'forwards' => $ids,
            ];
        }
        ksort($forwards);

        $matchedPoint = null;
        if ($point !== null) {
            $matchedPoint = [
                'id' => (int) ($point['id'] ?? 0),
                'name' => (string) ($point['name'] ?? ''),
                'sidecar_host' => (string) ($point['sidecar_host'] ?? ''),
            ];
        }

        return [
            'listen_host' => self::DEFAULT_LISTEN_HOST,
            'listen_port' => $effectivePort,
            'forwards' => (object) $forwards,
            'queue_packets' => self::knob($knobs, 'queue_packets', self::DEFAULT_QUEUE_PACKETS),
            'keepalive_seconds' => self::knob($knobs, 'keepalive_seconds', self::DEFAULT_KEEPALIVE_SECONDS),
            'session_grace_seconds' => self::knob($knobs, 'session_grace_seconds', self::DEFAULT_SESSION_GRACE_SECONDS),
            'frame_payload_max' => self::knob($knobs, 'frame_payload_max', self::DEFAULT_FRAME_PAYLOAD_MAX),
            'panel' => [
                'generated_at' => $now ?? gmdate('Y-m-d\TH:i:s\Z'),
                'matched_point' => $matchedPoint,
                'match' => [
                    'host' => $matchHost,
                    'listen_port' => $effectivePort,
                    'listen_port_source' => $portSource,
                ],
                'combinations' => $combinationsSummary,
                'skipped' => $skipped,
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $combinations
     * @return array<int, list<int>>
     */
    private function forwardIdsByCombination(array $combinations): array
    {
        $combinationIds = [];
        foreach ($combinations as $combination) {
            $combinationId = (int) ($combination['id'] ?? 0);
            if ($combinationId > 0) {
                $combinationIds[] = $combinationId;
            }
        }
        if ($combinationIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($combinationIds), '?'));
        $rows = $this->db->fetchAll(
            'SELECT id, forward_combination_id FROM forwards WHERE forward_combination_id IN ('
            . $placeholders . ') ORDER BY id ASC',
            $combinationIds
        );
        $grouped = [];
        foreach ($rows as $row) {
            $combinationId = (int) ($row['forward_combination_id'] ?? 0);
            $forwardId = (int) ($row['id'] ?? 0);
            if ($combinationId <= 0 || $forwardId <= 0) {
                continue;
            }
            $grouped[$combinationId][] = $forwardId;
        }

        return $grouped;
    }

    /**
     * forward_id => "host:port" for every forward the document will publish.
     *
     * @param array<int, list<int>> $forwardIdsByCombination
     * @return array<int, string>
     */
    private function forwardTargets(array $forwardIdsByCombination): array
    {
        $ids = [];
        foreach ($forwardIdsByCombination as $forwardIds) {
            foreach ($forwardIds as $forwardId) {
                $ids[(int) $forwardId] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        $ids = array_keys($ids);
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $rows = $this->db->fetchAll(
            'SELECT id, target_host, target_port FROM forwards WHERE id IN (' . $placeholders . ') ORDER BY id ASC',
            $ids
        );
        $targets = [];
        foreach ($rows as $row) {
            $host = trim((string) ($row['target_host'] ?? ''));
            $port = (int) ($row['target_port'] ?? 0);
            if ((int) ($row['id'] ?? 0) > 0 && $host !== '' && $port > 0) {
                $targets[(int) $row['id']] = (str_contains($host, ':') ? '[' . trim($host, '[]') . ']' : $host) . ':' . $port;
            }
        }

        return $targets;
    }

    /**
     * Mirror of the sidecar's normalizeTarget (tools/uot-sidecar/protocol.go):
     * lower-cased host, canonical IP text, IPv6 in brackets, port 1-65535.
     */
    public static function normalizeForwardTarget(string $target): ?string
    {
        $parsed = self::splitTarget($target);
        if ($parsed === null) {
            return null;
        }
        [$host, $port] = $parsed;
        $host = strtolower(trim($host));
        if ($host === '' || preg_match('/\s/', $host) === 1) {
            return null;
        }
        $packed = @inet_pton($host);
        if ($packed !== false) {
            $host = (string) inet_ntop($packed);
        }

        return (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $port;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolvePoint(string $agentToken): ?array
    {
        // Security: this endpoint returns the fleet-wide external-UoT config —
        // every matching combination's external_uot_token and the tenant target
        // bound to it. Only operator/platform sidecar points (owner_user_id IS
        // NULL) may authenticate here. Without this a tenant could create a
        // sidecar point whose sidecar_host equals the operator external-UoT host
        // and harvest other tenants' tokens and backend targets.
        $point = $this->db->fetchOne(
            'SELECT id, name, sidecar_host, agent_token, is_active, last_status FROM sidecar_points WHERE agent_token = ? AND owner_user_id IS NULL LIMIT 1',
            [$agentToken]
        );
        if (!is_array($point) || !hash_equals((string) ($point['agent_token'] ?? ''), $agentToken)) {
            return null;
        }
        unset($point['agent_token']);

        return $point;
    }

    /**
     * @param list<array<string, mixed>> $enabled
     */
    private static function defaultPortFor(array $enabled): int
    {
        if ($enabled === []) {
            return self::DEFAULT_LISTEN_PORT;
        }
        foreach ($enabled as $combination) {
            if ((int) $combination['port'] === self::DEFAULT_LISTEN_PORT) {
                return self::DEFAULT_LISTEN_PORT;
            }
        }

        return (int) $enabled[0]['port'];
    }

    /**
     * @param array<string, mixed>|null $combination
     */
    private static function knob(?array $combination, string $key, int $default): int
    {
        if ($combination === null) {
            return $default;
        }
        $value = (int) ($combination[$key] ?? 0);

        return $value > 0 ? $value : $default;
    }

    private static function normalizeHost(string $host): string
    {
        return strtolower(trim($host));
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    private static function splitTarget(string $target): ?array
    {
        $target = trim($target);
        if ($target === '') {
            return null;
        }
        $host = $target;
        $port = 0;
        $position = strrpos($target, ':');
        if ($position !== false) {
            $host = substr($target, 0, $position);
            $portText = substr($target, $position + 1);
            $port = ctype_digit($portText) ? (int) $portText : 0;
        }
        $host = trim($host, '[]');
        if ($host === '' || $port < 1 || $port > 65535) {
            return null;
        }

        return [$host, $port];
    }
}
