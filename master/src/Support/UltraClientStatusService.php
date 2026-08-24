<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;

final class UltraClientStatusService
{
    public const MAX_STATUSES = 32;
    public const MAX_STATUS_BYTES = 65536;

    /** @param callable():int|null $clock */
    public function __construct(
        private Config $config,
        private Database $db,
        private $clock = null,
    ) {
        $this->clock ??= static fn(): int => time();
    }

    /** @param array<int, mixed> $statuses */
    public function ingest(int $clientId, string $deviceId, array $statuses): int
    {
        $deviceId = trim($deviceId);
        if ($clientId <= 0 || $deviceId === '' || strlen($deviceId) > 191) {
            return 0;
        }
        $assignments = $this->assignmentsForClients([$clientId]);
        $allowed = $assignments[$clientId] ?? [];
        $lineLabels = $this->lineLabelsForAssignments([$clientId => $allowed]);
        $normalized = [];
        foreach (array_slice(array_values($statuses), 0, self::MAX_STATUSES) as $status) {
            if (!is_array($status)) {
                continue;
            }
            $forwardId = (int) ($status['forward_id'] ?? 0);
            if (!isset($allowed[$forwardId]) || isset($normalized[$forwardId])) {
                continue;
            }
            $entry = $this->normalizeStatus($status, $forwardId, $lineLabels[$forwardId] ?? []);
            if ($entry !== null) {
                $normalized[$forwardId] = $entry;
            }
        }
        $encoded = json_encode(array_values($normalized), JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || strlen($encoded) > self::MAX_STATUS_BYTES) {
            return 0;
        }
        $now = gmdate('Y-m-d H:i:s', $this->now());
        $updated = $this->db->execute(
            'UPDATE client_device_sessions
             SET ultra_status_json = :ultra_status_json,
                 ultra_status_updated_at = :ultra_status_updated_at,
                 updated_at = :updated_at
             WHERE client_id = :client_id AND device_id = :device_id',
            [
                'client_id' => $clientId,
                'device_id' => $deviceId,
                'ultra_status_json' => $encoded,
                'ultra_status_updated_at' => $now,
                'updated_at' => $now,
            ]
        );
        return $updated > 0 ? count($normalized) : 0;
    }

    /** @return array<int, array<string, mixed>> */
    public function sessionsForAdmin(array $filters, int $limit, int $offset): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $since = gmdate('Y-m-d H:i:s', $this->now() - 86400);
        $sessions = $this->db->fetchAll(
            'SELECT cds.client_id, cds.device_id, cds.device_name, cds.platform, cds.last_ip,
                    cds.last_seen_at, cds.last_heartbeat_at, cds.ultra_status_json, cds.ultra_status_updated_at,
                    c.name AS client_name, c.group_id, u.name AS user_name, u.email AS user_email
             FROM client_device_sessions cds
             INNER JOIN clients c ON c.id = cds.client_id
             LEFT JOIN users u ON u.id = c.owner_user_id
             WHERE cds.last_seen_at >= :since
               AND cds.ultra_status_json IS NOT NULL
             ORDER BY cds.last_seen_at DESC, cds.id DESC
             LIMIT 200',
            ['since' => $since]
        );
        $clientIds = [];
        foreach ($sessions as $session) {
            $clientId = (int) ($session['client_id'] ?? 0);
            if ($clientId > 0) {
                $clientIds[$clientId] = $clientId;
            }
        }
        $assignments = $this->assignmentsForClients(array_values($clientIds));
        $lineLabels = $this->lineLabelsForAssignments($assignments);
        $stateFilter = strtolower(trim((string) ($filters['state'] ?? '')));
        $zoneFilter = strtolower(trim((string) ($filters['destination_zone'] ?? '')));
        $search = strtolower(trim((string) ($filters['search'] ?? '')));
        $forwardSearch = strtolower(trim((string) ($filters['forward'] ?? '')));
        $rows = [];
        foreach ($sessions as $session) {
            $clientId = (int) ($session['client_id'] ?? 0);
            $lastSeen = strtotime((string) ($session['last_seen_at'] ?? '')) ?: 0;
            $state = $this->sessionState($lastSeen);
            if ($stateFilter !== '' && $stateFilter !== strtolower($state)) {
                continue;
            }
            $haystack = strtolower(implode(' ', [
                (string) ($session['user_name'] ?? ''),
                (string) ($session['user_email'] ?? ''),
                (string) ($session['client_name'] ?? ''),
                (string) ($session['device_id'] ?? ''),
                (string) ($session['device_name'] ?? ''),
                (string) ($session['last_ip'] ?? ''),
            ]));
            if ($search !== '' && !str_contains($haystack, $search)) {
                continue;
            }
            $decoded = is_string($session['ultra_status_json'] ?? null)
                ? json_decode((string) $session['ultra_status_json'], true)
                : [];
            foreach (array_slice(is_array($decoded) ? $decoded : [], 0, self::MAX_STATUSES) as $status) {
                if (!is_array($status)) {
                    continue;
                }
                $forwardId = (int) ($status['forward_id'] ?? 0);
                $assignment = $assignments[$clientId][$forwardId] ?? null;
                if (!is_array($assignment)) {
                    continue;
                }
                $destinationZone = strtolower(trim((string) ($assignment['ultra_destination_zone'] ?? 'unknown')));
                if ($zoneFilter !== '' && $destinationZone !== $zoneFilter) {
                    continue;
                }
                $forwardHaystack = strtolower($forwardId . ' ' . (string) ($assignment['tenant_label'] ?? '') . ' ' . (string) ($assignment['name'] ?? ''));
                if ($forwardSearch !== '' && !str_contains($forwardHaystack, $forwardSearch)) {
                    continue;
                }
                $tcp = is_array($status['latest_tcp'] ?? null) ? $status['latest_tcp'] : [];
                $udp = is_array($status['active_udp'] ?? null) ? $status['active_udp'] : [];
                $tcpIp = self::ip($tcp['relay_ip'] ?? '');
                $udpIp = self::ip($udp['relay_ip'] ?? '');
                $forwardLineLabels = $lineLabels[$forwardId] ?? [];
                if (!isset($forwardLineLabels[$tcpIp])) {
                    $tcpIp = '';
                }
                if (!isset($forwardLineLabels[$udpIp])) {
                    $udpIp = '';
                }
                $rows[] = [
                    'state' => $state,
                    'user_name' => self::text($session['user_name'] ?? '', 191),
                    'user_email' => self::text($session['user_email'] ?? '', 191),
                    'client_id' => $clientId,
                    'client_name' => self::text($session['client_name'] ?? '', 191),
                    'device_id' => self::text($session['device_id'] ?? '', 191),
                    'device_name' => self::text($session['device_name'] ?? '', 191),
                    'client_ip' => self::ip($session['last_ip'] ?? ''),
                    'forward_id' => $forwardId,
                    'forward_label' => self::text($assignment['tenant_label'] ?? $assignment['name'] ?? '', 191),
                    'destination' => self::text($assignment['target_host'] ?? '', 255) . ':' . (int) ($assignment['target_port'] ?? 0),
                    'destination_zone' => $destinationZone,
                    'detected_ingress_zone' => self::zone($status['detected_ingress_zone'] ?? 'unknown'),
                    'latest_tcp_line' => self::lineLabelForRelay($forwardLineLabels, $tcpIp),
                    'latest_tcp_ip' => $tcpIp,
                    'active_udp_line' => self::lineLabelForRelay($forwardLineLabels, $udpIp),
                    'active_udp_ip' => $udpIp,
                    'last_seen_at' => (string) ($session['last_seen_at'] ?? ''),
                    'status_updated_at' => (string) ($session['ultra_status_updated_at'] ?? ''),
                    'error_code' => self::text($status['error_code'] ?? '', 64),
                ];
            }
        }
        return array_slice($rows, $offset, $limit);
    }

    /** @return array{online:int,stale:int,offline:int} */
    public function statusCounts(): array
    {
        $now = $this->now();
        $rows = $this->db->fetchAll(
            'SELECT last_seen_at FROM client_device_sessions
             WHERE last_seen_at >= :since AND ultra_status_json IS NOT NULL
             ORDER BY last_seen_at DESC LIMIT 1000',
            ['since' => gmdate('Y-m-d H:i:s', $now - 86400)]
        );
        $counts = ['online' => 0, 'stale' => 0, 'offline' => 0];
        foreach ($rows as $row) {
            $state = strtolower($this->sessionState(strtotime((string) ($row['last_seen_at'] ?? '')) ?: 0));
            if (isset($counts[$state])) {
                $counts[$state]++;
            }
        }
        return $counts;
    }

    /** @param array<int, int> $clientIds @return array<int, array<int, array<string, mixed>>> */
    private function assignmentsForClients(array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }
        $clients = $this->db->fetchAll(
            'SELECT id, group_id FROM clients WHERE id IN (' . implode(',', array_map('intval', $clientIds)) . ')'
        );
        $rows = $this->db->fetchAll(
            'SELECT f.id, f.line_client_id, f.line_client_group_id, f.line_client_group_ids_json,
                    f.target_host, f.target_port, fc.name, fc.tenant_label, fc.ultra_destination_zone
             FROM forwards f
             INNER JOIN services s ON s.id = f.service_id AND s.status = :active_status
             INNER JOIN forward_combinations fc ON fc.id = f.forward_combination_id AND fc.backend_mode = :backend_mode
             WHERE f.enabled = 1
             ORDER BY f.id ASC',
            ['active_status' => 'active', 'backend_mode' => UltraMode::BACKEND_MODE]
        );
        $result = [];
        foreach ($clients as $client) {
            $clientId = (int) ($client['id'] ?? 0);
            $groupId = (int) ($client['group_id'] ?? 0);
            foreach ($rows as $row) {
                $groups = is_string($row['line_client_group_ids_json'] ?? null)
                    ? json_decode((string) $row['line_client_group_ids_json'], true)
                    : [];
                $assigned = (int) ($row['line_client_id'] ?? 0) === $clientId
                    || ($groupId > 0 && (int) ($row['line_client_group_id'] ?? 0) === $groupId)
                    || ($groupId > 0 && is_array($groups) && in_array($groupId, array_map('intval', $groups), true));
                if ($assigned) {
                    $result[$clientId][(int) $row['id']] = $row;
                }
            }
        }
        return $result;
    }

    /**
     * Resolve an observed relay only within the line memberships approved for
     * that forward. The client continues to report an opaque entry reference.
     *
     * @param array<int, array<int, array<string, mixed>>> $assignments
     * @return array<int, array<string, string>>
     */
    private function lineLabelsForAssignments(array $assignments): array
    {
        $forwardIds = [];
        foreach ($assignments as $clientAssignments) {
            foreach (array_keys($clientAssignments) as $forwardId) {
                $forwardId = (int) $forwardId;
                if ($forwardId > 0) {
                    $forwardIds[$forwardId] = $forwardId;
                }
            }
        }
        if ($forwardIds === []) {
            return [];
        }

        $rows = $this->db->fetchAll(
            'SELECT f.id AS forward_id, ul.line_id, ul.admin_label, ul.source_title, ul.relay_ips_json
             FROM forward_combination_ultra_lines fcul
             INNER JOIN forwards f ON f.forward_combination_id = fcul.forward_combination_id
             INNER JOIN ultra_lines ul ON ul.id = fcul.ultra_line_id
             WHERE f.id IN (' . implode(',', array_values($forwardIds)) . ')
               AND fcul.enabled = 1
               AND ul.is_source_present = 1
               AND ul.is_enabled = 1
             ORDER BY f.id ASC, fcul.sort_order ASC, fcul.id ASC'
        );
        $labels = [];
        foreach ($rows as $row) {
            $forwardId = (int) ($row['forward_id'] ?? 0);
            $lineId = (int) ($row['line_id'] ?? 0);
            if (!isset($forwardIds[$forwardId]) || $lineId <= 0) {
                continue;
            }
            $title = trim((string) (($row['admin_label'] ?? '') ?: ($row['source_title'] ?? '')));
            $label = self::text('#' . $lineId . ($title !== '' ? ' ' . $title : ''), 128);
            $relayIps = is_string($row['relay_ips_json'] ?? null)
                ? json_decode((string) $row['relay_ips_json'], true)
                : [];
            foreach (array_slice(is_array($relayIps) ? $relayIps : [], 0, 8) as $rawIp) {
                $ip = self::ip($rawIp);
                if ($ip === '') {
                    continue;
                }
                $existing = $labels[$forwardId][$ip] ?? '';
                if ($existing === '') {
                    $labels[$forwardId][$ip] = $label;
                } elseif (!str_contains(' / ' . $existing . ' / ', ' / ' . $label . ' / ')) {
                    $labels[$forwardId][$ip] = self::text($existing . ' / ' . $label, 128);
                }
            }
        }
        return $labels;
    }

    /** @param array<string, string> $labels */
    private static function lineLabelForRelay(array $labels, string $relayIp): string
    {
        if ($relayIp !== '' && trim((string) ($labels[$relayIp] ?? '')) !== '') {
            return self::text($labels[$relayIp], 128);
        }
        return '';
    }

    /**
     * @param array<string, mixed> $status
     * @param array<string, string> $allowedRelays
     * @return array<string, mixed>|null
     */
    private function normalizeStatus(array $status, int $forwardId, array $allowedRelays): ?array
    {
        $state = strtolower(trim((string) ($status['state'] ?? 'ready')));
        if (!in_array($state, ['ready', 'degraded', 'error', 'stopped'], true)) {
            $state = 'error';
        }
        return [
            'forward_id' => $forwardId,
            'state' => $state,
            'detected_ingress_zone' => self::zone($status['detected_ingress_zone'] ?? 'unknown'),
            'latest_tcp' => $this->normalizeSelection($status['latest_tcp'] ?? null, $allowedRelays),
            'active_udp' => $this->normalizeSelection($status['active_udp'] ?? null, $allowedRelays),
            'updated_at' => self::text($status['updated_at'] ?? '', 40),
            'error_code' => self::text($status['error_code'] ?? '', 64),
        ];
    }

    /** @param array<string, string> $allowedRelays @return array<string, mixed>|null */
    private function normalizeSelection(mixed $selection, array $allowedRelays): ?array
    {
        if (!is_array($selection)) {
            return null;
        }
        $reference = trim((string) ($selection['entry_ref'] ?? ''));
        $relayIp = self::ip($selection['relay_ip'] ?? '');
        if (preg_match('/^[A-Za-z0-9_-]{16,128}$/', $reference) !== 1
            || $relayIp === ''
            || !isset($allowedRelays[$relayIp])) {
            return null;
        }
        return [
            'entry_ref' => $reference,
            'relay_ip' => $relayIp,
            'rtt_ms' => max(0, min(60000, (int) ($selection['rtt_ms'] ?? 0))),
            'selected_at' => self::text($selection['selected_at'] ?? '', 40),
        ];
    }

    private function sessionState(int $lastSeen): string
    {
        $age = max(0, $this->now() - $lastSeen);
        return $age < 180 ? 'Online' : ($age <= 900 ? 'Stale' : 'Offline');
    }

    private static function text(mixed $value, int $limit): string
    {
        $text = trim((string) $value);
        return function_exists('mb_substr') ? mb_substr($text, 0, $limit, 'UTF-8') : substr($text, 0, $limit);
    }

    private static function ip(mixed $value): string
    {
        $ip = trim((string) $value);
        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
    }

    private static function zone(mixed $value): string
    {
        $zone = strtolower(trim((string) $value));
        return preg_match('/^[a-z0-9-]{1,32}$/', $zone) === 1 ? $zone : 'unknown';
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }
}
