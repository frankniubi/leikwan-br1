<?php
declare(strict_types=1);

namespace Leikwan\Master\Controllers;

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;
use Throwable;

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

    public function __construct(Config $config)
    {
        parent::__construct($config, new Database($config));
    }

    /**
     * GET /external-uot-config
     *
     * The host is selected by point_id, agent_token (both exact) or by host / ip
     * (the sidecar_points.sidecar_host value).  listen_port overrides the port
     * that is otherwise taken from the combination's external_uot_sidecar_target.
     *
     * @param array<string, mixed> $params
     */
    public function sidecarConfig(Request $request, array $params): Response
    {
        $query = $request->query();
        $pointId = (int) ($query['point_id'] ?? 0);
        $agentToken = trim((string) ($query['agent_token'] ?? ''));
        $host = trim((string) ($query['host'] ?? ''));
        $ip = trim((string) ($query['ip'] ?? ''));
        $requestedPort = (int) ($query['listen_port'] ?? 0);

        // A bare config URL (no host/ip/point_id) resolves against the
        // requesting address, so a fresh sidecar install configures itself
        // from the panel without extra operator input.
        $remoteAddress = trim((string) ($request->remoteAddress() ?? ''));
        $hostCandidate = $host !== '' ? $host : ($ip !== '' ? $ip : $remoteAddress);

        try {
            $point = $this->resolvePoint($pointId, $agentToken, $hostCandidate);
            $combinations = $this->db->fetchAll(
                'SELECT id, external_uot_enabled, external_uot_sidecar_target, external_uot_token, '
                . 'external_uot_queue_packets, external_uot_keepalive_seconds, '
                . 'external_uot_reconnect_grace_seconds, external_uot_frame_payload_max '
                . 'FROM forward_combinations WHERE external_uot_enabled = 1 ORDER BY id ASC'
            );
            $forwards = $this->forwardIdsByCombination($combinations);
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
            $hostCandidate
        );
        $payload['panel']['request'] = [
            'point_id' => $pointId > 0 ? $pointId : null,
            'agent_token' => $agentToken !== '' ? 'present' : null,
            'host' => $host !== '' ? $host : null,
            'ip' => $ip !== '' ? $ip : null,
            'host_source' => $host !== '' ? 'query-host' : ($ip !== '' ? 'query-ip' : ($remoteAddress !== '' ? 'remote-address' : 'none')),
            'remote_address' => $remoteAddress !== '' ? $remoteAddress : null,
            'listen_port' => $requestedPort > 0 ? $requestedPort : null,
        ];

        return $this->json($payload)->withHeaders(['Cache-Control' => 'no-store']);
    }

    /**
     * @param array<string, mixed>|null $point
     * @param list<array<string, mixed>> $combinations
     * @param array<int, list<int>> $forwardIdsByCombination
     * @return array<string, mixed>
     */
    public static function buildPayload(
        ?array $point,
        array $combinations,
        array $forwardIdsByCombination,
        int $requestedPort = 0,
        string $requestedHost = '',
        ?string $now = null
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
                $forwards[$forwardId] = ['token' => $combination['token'], 'targets' => []];
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
     * @return array<string, mixed>|null
     */
    private function resolvePoint(int $pointId, string $agentToken, string $hostOrIp): ?array
    {
        $columns = 'id, name, sidecar_host, is_active, last_status';
        if ($pointId > 0) {
            return $this->db->fetchOne(
                'SELECT ' . $columns . ' FROM sidecar_points WHERE id = ? LIMIT 1',
                [$pointId]
            );
        }
        if ($agentToken !== '') {
            return $this->db->fetchOne(
                'SELECT ' . $columns . ' FROM sidecar_points WHERE agent_token = ? LIMIT 1',
                [$agentToken]
            );
        }
        $hostOrIp = trim($hostOrIp);
        if ($hostOrIp !== '') {
            return $this->db->fetchOne(
                'SELECT ' . $columns . ' FROM sidecar_points WHERE LOWER(sidecar_host) = ? LIMIT 1',
                [self::normalizeHost($hostOrIp)]
            );
        }

        return null;
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
