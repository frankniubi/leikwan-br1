<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;

final class UltraInventoryService
{
    public const MAX_INVENTORY_LINES = 256;
    public const MAX_INVENTORY_BYTES = 262144;
    public const ACCESS_CLASSES = ['native_udp', 'wireguard_only', 'wireguard', 'dynamic', 'dynamic_ingress', 'relay_unreachable', 'unknown'];
    public const NATIVE_UDP_STATES = ['verified', 'failed', 'unverified', 'unknown'];
    public const NATIVE_SOCKS5_STATES = ['verified', 'failed', 'unknown'];
    public const DIRECTION_CLASSES = ['intl_to_cn', 'cn_to_intl', 'domestic', 'unknown'];
    public const ROUTE_CLASSES = ['bgp', 'single_telecom', 'single_unicom', 'single_mobile', 'wireguard', 'direct', 'relay', 'generic', 'unknown'];
    public const ISP_CLASSES = ['telecom', 'unicom', 'mobile', 'generic', 'unknown'];
    public const DISPATCH_CLASSES = ['normal', 'low'];
    /**
     * Entries whose relay/provider marker contains one of these are dispatched last
     * (operator instruction 2026-09-11: Tencent-marked entries are low priority;
     * UCloud entries are demoted after the 2026-09-15 provider timeout review).
     * Matching is applied to the provider field and to the source title, because a
     * few Tencent lines only carry the marker in the title.
     */
    public const LOW_PRIORITY_PROVIDER_MARKERS = ['腾讯', 'tencent', 'ucloud', 'u-cloud'];
    /**
     * Zone codes that count as mainland China for direction classification. The
     * list mirrors ultra_zones; add a code here when a new mainland zone is
     * introduced (hong-kong / taiwan stay international, matching the existing
     * intl_to_cn / cn_to_intl semantics).
     */
    public const MAINLAND_ZONE_CODES = [
        'beijing', 'shanghai', 'guangzhou', 'shenzhen', 'fuzhou', 'guiyang',
        'chengdu', 'chongqing', 'hangzhou', 'nanjing', 'wuhan', 'china-mainland',
    ];
    /** Source hosts report the carrier in Chinese; the panel stores a stable code. */
    public const ISP_ALIASES = [
        '电信' => 'telecom', 'telecom' => 'telecom', 'ct' => 'telecom',
        '联通' => 'unicom', 'unicom' => 'unicom', 'cu' => 'unicom',
        '移动' => 'mobile', 'mobile' => 'mobile', 'cmcc' => 'mobile',
        '通用' => 'generic', 'generic' => 'generic', 'bgp' => 'generic',
    ];
    public const PRICING_CLASSES = ['paid', 'mixed', 'free', 'unknown'];

    /** @var callable(): int */
    private $clock;

    public function __construct(
        private Config $config,
        private Database $db,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /**
     * @param array<int, array{zone_code:string,aliases:array<int,string>}> $zones
     */
    public static function classifyText(string $text, array $zones): string
    {
        $text = self::lower(trim($text));
        if ($text === '') {
            return 'unknown';
        }
        $bestLength = 0;
        $bestCodes = [];
        foreach ($zones as $zone) {
            $code = strtolower(trim((string) ($zone['zone_code'] ?? '')));
            if ($code === '' || $code === 'unknown') {
                continue;
            }
            foreach (is_array($zone['aliases'] ?? null) ? $zone['aliases'] : [] as $rawAlias) {
                $alias = self::lower(trim((string) $rawAlias));
                if ($alias === '' || !str_contains($text, $alias)) {
                    continue;
                }
                $length = function_exists('mb_strlen') ? mb_strlen($alias, 'UTF-8') : strlen($alias);
                if ($length > $bestLength) {
                    $bestLength = $length;
                    $bestCodes = [$code => true];
                } elseif ($length === $bestLength) {
                    $bestCodes[$code] = true;
                }
            }
        }
        return count($bestCodes) === 1 ? (string) array_key_first($bestCodes) : 'unknown';
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, array<int, array{zone_code:string,aliases:array<int,string>}>> $zonesByRole
     * @return array<string, mixed>|null
     */
    public static function normalizeInventoryLine(array $raw, array $zonesByRole, int $now): ?array
    {
        $lineId = (int) ($raw['line_id'] ?? 0);
        $configSha = strtolower(trim((string) ($raw['config_sha256'] ?? '')));
        if ($lineId < 1 || $lineId > 45535 || preg_match('/^[a-f0-9]{64}$/', $configSha) !== 1) {
            return null;
        }
        $title = self::cleanText($raw['title'] ?? '', 255);
        $family = self::cleanText($raw['family_key'] ?? '', 255);
        $classificationText = trim($title . ' ' . $family);
        $catalogIngress = self::cleanText($raw['catalog_ingress'] ?? '', 191);
        $catalogDestination = self::cleanText($raw['catalog_destination'] ?? '', 191);
        $discoveryIp = trim((string) ($raw['discovery_ip'] ?? ''));
        if ($discoveryIp !== '' && filter_var($discoveryIp, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $rawAccess = self::normalizeEnum($raw['access_class'] ?? 'unknown', self::ACCESS_CLASSES);
        $rawNativeState = array_key_exists('native_udp_state', $raw)
            ? self::normalizeEnum($raw['native_udp_state'], self::NATIVE_UDP_STATES)
            : 'unknown';
        $rawNativeSocks5State = array_key_exists('native_socks5_state', $raw)
            ? self::normalizeEnum($raw['native_socks5_state'], self::NATIVE_SOCKS5_STATES)
            : 'unknown';
        $auditHash = strtolower(trim((string) ($raw['audit_hash'] ?? '')));
        if ($auditHash !== '' && preg_match('/^[a-f0-9]{64}$/', $auditHash) !== 1) {
            $auditHash = null;
        }
        $timestamp = gmdate('Y-m-d H:i:s', $now);
        return [
            'line_id' => $lineId,
            'source_title' => $title !== '' ? $title : null,
            'source_family_key' => $family !== '' ? $family : null,
            'game_id' => self::nullableUnsigned($raw['game_id'] ?? null),
            'area_id' => self::nullableUnsigned($raw['area_id'] ?? null),
            'line_type' => self::nullableUnsigned($raw['line_type'] ?? null),
            'in_line_type' => self::nullableUnsigned($raw['in_line_type'] ?? null),
            'isp' => self::normalizeIsp($raw['isp'] ?? null),
            'provider' => self::cleanText($raw['provider'] ?? '', 64) ?: null,
            // A source may declare the dispatch priority: a line whose relay association
            // drops big datagrams is dispatched last, exactly like the Tencent ones. An
            // unknown/absent declaration falls back to the provider/title rule.
            'dispatch_class' => self::dispatchClassWithDeclaration($raw),
            'discovery_ip' => $discoveryIp !== '' ? $discoveryIp : null,
            'catalog_ingress_label' => $catalogIngress !== '' ? $catalogIngress : null,
            'catalog_destination_label' => $catalogDestination !== '' ? $catalogDestination : null,
            'config_sha256' => $configSha,
            'access_class' => $rawAccess,
            'native_udp_state' => $rawNativeState,
            'native_socks5_state' => $rawNativeSocks5State,
            'route_class' => self::normalizeEnum($raw['route_class'] ?? 'unknown', self::ROUTE_CLASSES),
            'pricing_class' => self::normalizeEnum($raw['pricing_class'] ?? 'unknown', self::PRICING_CLASSES),
            'pricing_evidence' => self::cleanText($raw['pricing_evidence'] ?? '', 255) ?: null,
            'audit_at' => self::normalizeAuditTimestamp($raw['audit_at'] ?? $raw['audited_at'] ?? null),
            'audit_hash' => $auditHash !== '' ? $auditHash : null,
            'auto_ingress_zone' => ($autoIngress = self::classifyText($catalogIngress !== '' ? $catalogIngress : $classificationText, $zonesByRole['ingress'] ?? [])),
            'auto_destination_zone' => ($autoDestination = self::classifyText($catalogDestination !== '' ? $catalogDestination : $classificationText, $zonesByRole['destination'] ?? [])),
            'direction_class' => self::deriveDirection($autoIngress, $autoDestination),
            'last_discovered_at' => $timestamp,
            'is_source_present' => 1,
            'is_enabled' => $rawAccess === 'native_udp' && $rawNativeState === 'verified' ? 1 : 0,
            'sort_order' => $lineId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    /** @param array<string, mixed> $payload */
    public function acceptInventory(string $sourceId, array $payload): int
    {
        $sourceId = trim($sourceId);
        $fingerprint = strtolower(trim((string) ($payload['inventory_fingerprint'] ?? '')));
        $rawLines = is_array($payload['lines'] ?? null) ? array_values($payload['lines']) : [];
        if (preg_match('/^[A-Za-z0-9._:-]{1,191}$/', $sourceId) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1
            || count($rawLines) > self::MAX_INVENTORY_LINES) {
            throw new ApiException('ultra_inventory_invalid', 422);
        }
        $zones = [
            'ingress' => $this->activeZones('ingress'),
            'destination' => $this->activeZones('destination'),
        ];
        $now = $this->now();
        $normalized = [];
        foreach ($rawLines as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $line = self::normalizeInventoryLine($raw, $zones, $now);
            if ($line !== null) {
                $normalized[(int) $line['line_id']] = $line;
            }
        }
        ksort($normalized, SORT_NUMERIC);
        $agentVersion = self::cleanText($payload['agent_version'] ?? '', 64);
        $complete = ($payload['complete'] ?? false) === true;
        $timestamp = gmdate('Y-m-d H:i:s', $now);

        $this->db->transaction(function (Database $db) use ($sourceId, $fingerprint, $agentVersion, $complete, $timestamp, $normalized): void {
            $db->execute(
                'INSERT INTO ultra_sources
                    (source_id, agent_version, inventory_fingerprint, inventory_count, last_seen_at, last_inventory_at, created_at, updated_at)
                 VALUES
                    (:source_id, :agent_version, :inventory_fingerprint, :inventory_count, :last_seen_at, :last_inventory_at, :created_at, :updated_at)
                 ON DUPLICATE KEY UPDATE
                    agent_version = VALUES(agent_version),
                    inventory_fingerprint = VALUES(inventory_fingerprint),
                    inventory_count = VALUES(inventory_count),
                    last_seen_at = VALUES(last_seen_at),
                    last_inventory_at = VALUES(last_inventory_at),
                    last_error_code = NULL,
                    updated_at = VALUES(updated_at)',
                [
                    'source_id' => $sourceId,
                    'agent_version' => $agentVersion !== '' ? $agentVersion : null,
                    'inventory_fingerprint' => $fingerprint,
                    'inventory_count' => count($normalized),
                    'last_seen_at' => $timestamp,
                    'last_inventory_at' => $timestamp,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]
            );
            if ($complete) {
                $db->execute(
                    'UPDATE ultra_lines SET is_source_present = 0, updated_at = :updated_at WHERE source_id = :source_id',
                    ['source_id' => $sourceId, 'updated_at' => $timestamp]
                );
            }
            foreach ($normalized as $line) {
                $db->execute(
                    'INSERT INTO ultra_lines
                        (source_id, line_id, source_title, source_family_key, game_id, area_id, line_type,
                         in_line_type, isp, provider, dispatch_class,
                         discovery_ip, catalog_ingress_label, catalog_destination_label, config_sha256,
                         access_class, native_udp_state, native_socks5_state, route_class, pricing_class, pricing_evidence, audit_at, audit_hash,
                         auto_ingress_zone, auto_destination_zone, direction_class,
                         last_discovered_at, is_source_present, is_enabled, sort_order, created_at, updated_at)
                     VALUES
                        (:source_id, :line_id, :source_title, :source_family_key, :game_id, :area_id, :line_type,
                         :in_line_type, :isp, :provider, :dispatch_class,
                         :discovery_ip, :catalog_ingress_label, :catalog_destination_label, :config_sha256,
                         :access_class, :native_udp_state, :native_socks5_state, :route_class, :pricing_class, :pricing_evidence, :audit_at, :audit_hash,
                         :auto_ingress_zone, :auto_destination_zone, :direction_class,
                         :last_discovered_at, :is_source_present, :is_enabled, :sort_order, :created_at, :updated_at)
                     ON DUPLICATE KEY UPDATE
                        source_title = VALUES(source_title),
                        source_family_key = VALUES(source_family_key),
                        game_id = VALUES(game_id),
                        area_id = VALUES(area_id),
                        line_type = VALUES(line_type),
                        in_line_type = VALUES(in_line_type),
                        isp = VALUES(isp),
                        provider = VALUES(provider),
                        dispatch_class = VALUES(dispatch_class),
                        discovery_ip = VALUES(discovery_ip),
                        catalog_ingress_label = VALUES(catalog_ingress_label),
                        catalog_destination_label = VALUES(catalog_destination_label),
                        config_sha256 = VALUES(config_sha256),
                        access_class = VALUES(access_class),
                        native_udp_state = VALUES(native_udp_state),
                        is_enabled = CASE
                            WHEN VALUES(native_socks5_state) = \'unknown\'
                             AND ultra_lines.native_socks5_state = \'verified\'
                             AND ultra_lines.config_sha256 = VALUES(config_sha256)
                            THEN ultra_lines.is_enabled
                            ELSE VALUES(is_enabled)
                        END,
                        native_socks5_state = CASE
                            WHEN VALUES(native_socks5_state) = \'unknown\'
                             AND ultra_lines.native_socks5_state = \'verified\'
                             AND ultra_lines.config_sha256 = VALUES(config_sha256)
                            THEN ultra_lines.native_socks5_state
                            ELSE VALUES(native_socks5_state)
                        END,
                        route_class = VALUES(route_class),
                        pricing_class = VALUES(pricing_class),
                        pricing_evidence = VALUES(pricing_evidence),
                        audit_at = VALUES(audit_at),
                        audit_hash = VALUES(audit_hash),
                        auto_ingress_zone = VALUES(auto_ingress_zone),
                        auto_destination_zone = VALUES(auto_destination_zone),
                        direction_class = CASE
                            WHEN ultra_lines.direction_class = \'unknown\' THEN VALUES(direction_class)
                            ELSE ultra_lines.direction_class
                        END,
                        last_discovered_at = VALUES(last_discovered_at),
                        is_source_present = 1,
                        updated_at = VALUES(updated_at)',
                    ['source_id' => $sourceId] + $line
                );
            }
        });
        return count($normalized);
    }

    /** @param array<string, mixed> $status */
    public function markSourceContact(string $sourceId, string $kind, array $status = []): void
    {
        $sourceId = trim($sourceId);
        if (preg_match('/^[A-Za-z0-9._:-]{1,191}$/', $sourceId) !== 1) {
            return;
        }
        $timestamp = gmdate('Y-m-d H:i:s', $this->now());
        $field = match ($kind) {
            'jobs' => 'last_jobs_at',
            'materials' => 'last_material_upload_at',
            'inventory' => 'last_inventory_at',
            default => null,
        };
        $this->db->execute(
            'INSERT INTO ultra_sources (source_id, last_seen_at, created_at, updated_at)
             VALUES (:source_id, :last_seen_at, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at), updated_at = VALUES(updated_at)',
            ['source_id' => $sourceId, 'last_seen_at' => $timestamp, 'created_at' => $timestamp, 'updated_at' => $timestamp]
        );
        $updates = ['last_seen_at = :last_seen_at', 'updated_at = :updated_at'];
        $params = ['source_id' => $sourceId, 'last_seen_at' => $timestamp, 'updated_at' => $timestamp];
        if ($field !== null) {
            $updates[] = $field . ' = :' . $field;
            $params[$field] = $timestamp;
        }
        if (array_key_exists('requested', $status)) {
            $updates[] = 'last_requested_line_count = :requested';
            $params['requested'] = max(0, min(self::MAX_INVENTORY_LINES, (int) $status['requested']));
        }
        if (array_key_exists('accepted', $status)) {
            $updates[] = 'last_accepted_line_count = :accepted';
            $params['accepted'] = max(0, min(self::MAX_INVENTORY_LINES, (int) $status['accepted']));
        }
        $this->db->execute(
            'UPDATE ultra_sources SET ' . implode(', ', $updates) . ' WHERE source_id = :source_id',
            $params
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function activeZones(string $role): array
    {
        if (!in_array($role, ['ingress', 'destination'], true)) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT zone_code, label, aliases_json, sort_order
             FROM ultra_zones
             WHERE zone_role = :zone_role AND is_active = 1
             ORDER BY sort_order ASC, id ASC',
            ['zone_role' => $role]
        );
        return array_map(static function (array $row): array {
            $aliases = is_string($row['aliases_json'] ?? null) ? json_decode((string) $row['aliases_json'], true) : [];
            $row['aliases'] = array_values(array_filter(
                is_array($aliases) ? array_map('strval', $aliases) : [],
                static fn(string $value): bool => trim($value) !== ''
            ));
            return $row;
        }, $rows);
    }

    /** @return array<int, array<string, mixed>> */
    public function listLinesForAdmin(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);
        $where = $this->truthy($filters['include_absent'] ?? false)
            ? ['1 = 1']
            : ['ul.is_source_present = 1'];
        $params = [];
        foreach (['ingress' => 'COALESCE(admin_ingress_zone, auto_ingress_zone)', 'destination' => 'COALESCE(admin_destination_zone, auto_destination_zone)'] as $key => $column) {
            $value = strtolower(trim((string) ($filters[$key] ?? '')));
            if (preg_match('/^[a-z0-9-]{1,32}$/', $value) === 1) {
                $where[] = $column . ' = :' . $key;
                $params[$key] = $value;
            }
        }
        foreach (['catalog_ingress' => 'catalog_ingress_label', 'catalog_destination' => 'catalog_destination_label'] as $key => $column) {
            $value = self::cleanText($filters[$key] ?? '', 191);
            if ($value !== '') {
                $where[] = $column . ' = :' . $key;
                $params[$key] = $value;
            }
        }
        $dispatchFilter = self::normalizeEnum($filters['dispatch_class'] ?? '', self::DISPATCH_CLASSES);
        if ($dispatchFilter !== 'unknown' && $dispatchFilter !== '') {
            $where[] = 'ul.dispatch_class = :dispatch_class';
            $params['dispatch_class'] = $dispatchFilter;
        }
        $ispFilter = self::normalizeIsp($filters['isp'] ?? null);
        if ($ispFilter !== 'unknown' || self::cleanText($filters['isp'] ?? '', 32) !== '') {
            $where[] = 'ul.isp = :isp';
            $params['isp'] = $ispFilter;
        }
        foreach (['access_class', 'native_udp_state', 'native_socks5_state', 'direction_class', 'route_class', 'pricing_class'] as $key) {
            $value = self::cleanText($filters[$key] ?? '', 32);
            if ($value !== '' && self::normalizeEnum($value, match ($key) {
                'access_class' => self::ACCESS_CLASSES,
                'native_udp_state' => self::NATIVE_UDP_STATES,
                'native_socks5_state' => self::NATIVE_SOCKS5_STATES,
                'direction_class' => self::DIRECTION_CLASSES,
                'route_class' => self::ROUTE_CLASSES,
                default => self::PRICING_CLASSES,
            }) === $value) {
                $where[] = 'ul.' . $key . ' = :' . $key;
                $params[$key] = strtolower($value);
            }
        }
        $materialRequired = self::materialRequiredSql();
        return $this->db->fetchAll(
            'SELECT ul.*,
                    COALESCE(ul.admin_ingress_zone, ul.auto_ingress_zone) AS effective_ingress_zone,
                    COALESCE(ul.admin_destination_zone, ul.auto_destination_zone) AS effective_destination_zone,
                    CASE WHEN ' . $materialRequired . ' THEN 1 ELSE 0 END AS material_required
             FROM ultra_lines ul
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY ul.sort_order ASC, ul.line_id ASC
             LIMIT ' . $limit . ' OFFSET ' . $offset,
            $params
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function listSourcesForAdmin(): array
    {
        return $this->db->fetchAll('SELECT * FROM ultra_sources ORDER BY priority ASC, source_id ASC');
    }

    public static function materialState(
        ?string $expiresAt,
        int $now,
        int $expiringWindowSeconds = 600,
        bool $required = true,
    ): string
    {
        if (!$required) {
            return 'not_required';
        }
        $expiresAt = trim((string) $expiresAt);
        if ($expiresAt === '') {
            return 'not_acquired';
        }
        $expiresTimestamp = strtotime($expiresAt . ' UTC');
        if ($expiresTimestamp === false || $expiresTimestamp <= $now) {
            return 'expired';
        }
        return $expiresTimestamp <= $now + max(60, $expiringWindowSeconds) ? 'expiring' : 'ready';
    }

    public static function materialStateLabel(string $state): string
    {
        return match ($state) {
            'not_required' => 'not required',
            'not_acquired' => 'not acquired',
            default => $state,
        };
    }

    public static function materialStateBadgeClass(string $state): string
    {
        return match ($state) {
            'ready' => 'success',
            'expiring' => 'info',
            'not_required' => 'info',
            'not_acquired' => 'info',
            default => 'danger',
        };
    }

    /** @param array<int, string> $relayIps */
    public function safeMaterialUpdate(string $sourceId, int $lineId, array $relayIps, string $acquiredAt, string $expiresAt): void
    {
        $ips = [];
        foreach (array_slice($relayIps, 0, 8) as $rawIp) {
            $ip = trim((string) $rawIp);
            if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                $ips[$ip] = $ip;
            }
        }
        $this->db->execute(
            'UPDATE ultra_lines
             SET relay_ips_json = :relay_ips_json,
                 last_material_acquired_at = :acquired_at,
                 last_material_expires_at = :expires_at,
                 updated_at = :updated_at
             WHERE source_id = :source_id AND line_id = :line_id',
            [
                'source_id' => $sourceId,
                'line_id' => $lineId,
                'relay_ips_json' => json_encode(array_values($ips), JSON_UNESCAPED_SLASHES) ?: '[]',
                'acquired_at' => $this->mysqlTimestamp($acquiredAt),
                'expires_at' => $this->mysqlTimestamp($expiresAt),
                'updated_at' => gmdate('Y-m-d H:i:s', $this->now()),
            ]
        );
    }

    /** @param array<string, mixed> $payload */
    public function updateLinePolicy(int $id, array $payload): void
    {
        $ingress = $this->validZoneOverride($payload['admin_ingress_zone'] ?? null, 'ingress');
        $destination = $this->validZoneOverride($payload['admin_destination_zone'] ?? null, 'destination');
        $directionClass = self::normalizeEnum($payload['direction_class'] ?? 'unknown', self::DIRECTION_CLASSES);
        $label = self::cleanText($payload['admin_label'] ?? '', 191);
        $notes = self::cleanText($payload['notes'] ?? '', 2000);
        $this->db->execute(
            'UPDATE ultra_lines
             SET admin_ingress_zone = :admin_ingress_zone,
                 admin_destination_zone = :admin_destination_zone,
                 direction_class = :direction_class,
                 admin_label = :admin_label,
                 is_enabled = :is_enabled,
                 sort_order = :sort_order,
                 notes = :notes,
                 updated_at = :updated_at
             WHERE id = :id',
            [
                'id' => $id,
                'admin_ingress_zone' => $ingress,
                'admin_destination_zone' => $destination,
                'direction_class' => $directionClass,
                'admin_label' => $label !== '' ? $label : null,
                'is_enabled' => $this->truthy($payload['is_enabled'] ?? false) ? 1 : 0,
                'sort_order' => max(-100000, min(100000, (int) ($payload['sort_order'] ?? 100))),
                'notes' => $notes !== '' ? $notes : null,
                'updated_at' => gmdate('Y-m-d H:i:s', $this->now()),
            ]
        );
    }

    /** @return array<string, int> */
    public function summaryCounts(): array
    {
        $now = $this->now();
        $materialRequired = self::materialRequiredSql();
        $row = $this->db->fetchOne(
            'SELECT SUM(CASE WHEN ul.is_source_present = 1 THEN 1 ELSE 0 END) AS discovered,
                    SUM(CASE WHEN ul.is_source_present = 0 THEN 1 ELSE 0 END) AS unavailable,
                    SUM(CASE WHEN ul.is_source_present = 1 AND NOT (' . $materialRequired . ') THEN 1 ELSE 0 END) AS not_required,
                    SUM(CASE WHEN ul.is_source_present = 1 AND (' . $materialRequired . ') AND ul.last_material_expires_at IS NULL THEN 1 ELSE 0 END) AS not_acquired,
                    SUM(CASE WHEN ul.is_source_present = 1 AND (' . $materialRequired . ') AND ul.last_material_expires_at IS NOT NULL AND ul.last_material_expires_at <= :now_expired THEN 1 ELSE 0 END) AS expired,
                    SUM(CASE WHEN ul.is_source_present = 1 AND (' . $materialRequired . ') AND ul.last_material_expires_at > :expiring_ready THEN 1 ELSE 0 END) AS ready,
                    SUM(CASE WHEN ul.is_source_present = 1 AND (' . $materialRequired . ') AND ul.last_material_expires_at > :now AND ul.last_material_expires_at <= :expiring THEN 1 ELSE 0 END) AS expiring
             FROM ultra_lines ul',
            [
                'now_expired' => gmdate('Y-m-d H:i:s', $now),
                'now' => gmdate('Y-m-d H:i:s', $now),
                'expiring_ready' => gmdate('Y-m-d H:i:s', $now + 600),
                'expiring' => gmdate('Y-m-d H:i:s', $now + 600),
            ]
        ) ?? [];
        return [
            'discovered' => max(0, (int) ($row['discovered'] ?? 0)),
            'unavailable' => max(0, (int) ($row['unavailable'] ?? 0)),
            'not_required' => max(0, (int) ($row['not_required'] ?? 0)),
            'not_acquired' => max(0, (int) ($row['not_acquired'] ?? 0)),
            'expired' => max(0, (int) ($row['expired'] ?? 0)),
            'ready' => max(0, (int) ($row['ready'] ?? 0)),
            'expiring' => max(0, (int) ($row['expiring'] ?? 0)),
        ];
    }

    private static function materialRequiredSql(): string
    {
        return "EXISTS (
            SELECT 1
            FROM forward_combination_ultra_lines required_fcul
            INNER JOIN forward_combinations required_fc
                    ON required_fc.id = required_fcul.forward_combination_id
                   AND required_fc.backend_mode = 'ultra'
                   AND required_fc.is_active = 1
            INNER JOIN forwards required_f
                    ON required_f.forward_combination_id = required_fc.id
                   AND required_f.backend_mode = 'ultra'
                   AND required_f.enabled = 1
            INNER JOIN services required_s
                    ON required_s.id = required_f.service_id
                   AND required_s.status = 'active'
            WHERE required_fcul.ultra_line_id = ul.id
              AND required_fcul.enabled = 1
              AND ul.is_source_present = 1
              AND ul.is_enabled = 1
        )";
    }

    private function validZoneOverride(mixed $value, string $role): ?string
    {
        $code = strtolower(trim((string) $value));
        if ($code === '') {
            return null;
        }
        foreach ($this->activeZones($role) as $zone) {
            if (hash_equals((string) ($zone['zone_code'] ?? ''), $code)) {
                return $code;
            }
        }
        throw new ApiException('ultra_zone_invalid', 422, ['zone_role' => $role, 'zone_code' => $code]);
    }

    private function mysqlTimestamp(string $value): ?string
    {
        $timestamp = strtotime($value);
        return $timestamp === false ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }

    private static function nullableUnsigned(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $number = (int) $value;
        return $number >= 0 && $number <= 4294967295 ? $number : null;
    }

    private static function cleanText(mixed $value, int $maxLength): string
    {
        $text = trim((string) $value);
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) === 1) {
            return '';
        }
        return function_exists('mb_substr') ? mb_substr($text, 0, $maxLength, 'UTF-8') : substr($text, 0, $maxLength);
    }

    private static function normalizeEnum(mixed $value, array $allowed): string
    {
        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, $allowed, true) ? $normalized : 'unknown';
    }

    private static function normalizeAuditTimestamp(mixed $value): ?string
    {
        $text = self::cleanText($value, 64);
        if ($text === '') {
            return null;
        }
        $timestamp = strtotime($text);
        return $timestamp === false ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Source hosts report the carrier as 电信/联通/移动/通用; the catalog stores a
     * stable code so filters and route_class derivation do not depend on the
     * source host's locale.
     */
    /**
     * Dispatch priority of a catalog entry. "low" entries are ordered after every
     * "normal" entry in the client-facing candidate list, so a client only falls
     * back to them when nothing better is available.
     *
     * @param array<string, mixed> $raw
     */
    public static function dispatchClass(array $raw): string
    {
        $haystack = self::lower(self::cleanText($raw['provider'] ?? '', 64) . '|' . self::cleanText($raw['title'] ?? '', 255));
        foreach (self::LOW_PRIORITY_PROVIDER_MARKERS as $marker) {
            if ($marker !== '' && str_contains($haystack, self::lower($marker))) {
                return 'low';
            }
        }
        return 'normal';
    }

    /** @param array<string, mixed> $raw */
    private static function dispatchClassWithDeclaration(array $raw): string
    {
        // A provider marker is authoritative so an old explicit "normal" value
        // cannot undo an operator safety demotion on the next inventory refresh.
        if (self::dispatchClass($raw) === 'low') {
            return 'low';
        }
        $declared = self::normalizeEnum($raw['dispatch_class'] ?? '', self::DISPATCH_CLASSES);
        return $declared !== 'unknown' ? $declared : 'normal';
    }

    /** Direction of a route from its resolved ingress/destination zones. */
    public static function deriveDirection(string $ingressZone, string $destinationZone): string
    {
        $ingress = strtolower(trim($ingressZone));
        $destination = strtolower(trim($destinationZone));
        if ($ingress === '' || $destination === '' || $ingress === 'unknown' || $destination === 'unknown') {
            return 'unknown';
        }
        $fromMainland = in_array($ingress, self::MAINLAND_ZONE_CODES, true);
        $toMainland = in_array($destination, self::MAINLAND_ZONE_CODES, true);
        if ($fromMainland && $toMainland) {
            return 'domestic';
        }
        if ($fromMainland) {
            return 'cn_to_intl';
        }
        if ($toMainland) {
            return 'intl_to_cn';
        }
        return 'unknown';
    }

    public static function normalizeIsp(mixed $value): string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return 'unknown';
        }
        $mapped = self::ISP_ALIASES[$raw] ?? self::ISP_ALIASES[self::lower($raw)] ?? '';
        return in_array($mapped, self::ISP_CLASSES, true) ? $mapped : 'unknown';
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function truthy(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }
}
