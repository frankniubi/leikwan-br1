<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use JsonException;

final class ForwardLineApiContract
{
    private const TRANSPORTS = ['tcp', 'udp', 'tcp+udp'];

    /** @param array<string, mixed> $payload */
    public static function normalizeCreate(array $payload): array
    {
        self::rejectUnknown($payload, ['route_id', 'listen_port', 'transport', 'target']);
        self::requireKeys($payload, ['route_id', 'listen_port', 'transport', 'target']);

        $routeId = self::positiveInt($payload['route_id'], 'route_id');
        $listenPort = self::optionalPort($payload['listen_port'], 'listen_port', true);
        $transport = self::transport($payload['transport']);
        $target = self::normalizeTarget(is_array($payload['target'] ?? null) ? $payload['target'] : null);

        return [
            'forward_route_id' => $routeId,
            'listen_port' => $listenPort,
            'transport_mode' => $transport,
            'target_source' => $target['source'],
            'target_host' => $target['host'],
            'target_port' => $target['port'],
            'target_service_id' => $target['service_id'],
        ];
    }

    /** @param array<string, mixed> $payload */
    public static function normalizePatch(array $payload): array
    {
        self::rejectUnknown($payload, ['route_id', 'listen_port', 'transport', 'target']);
        if ($payload === []) {
            self::invalid(['patch' => 'required']);
        }

        $changes = [];
        if (array_key_exists('route_id', $payload)) {
            $changes['forward_route_id'] = self::positiveInt($payload['route_id'], 'route_id');
        }
        if (array_key_exists('listen_port', $payload)) {
            $changes['listen_port'] = self::optionalPort($payload['listen_port'], 'listen_port', true);
        }
        if (array_key_exists('transport', $payload)) {
            $changes['transport_mode'] = self::transport($payload['transport']);
        }
        if (array_key_exists('target', $payload)) {
            if (!is_array($payload['target'])) {
                self::invalid(['target' => 'object_required']);
            }
            self::rejectUnknown($payload['target'], ['source', 'host', 'port', 'service_id'], 'target.');
            if ($payload['target'] === []) {
                self::invalid(['target' => 'non_empty_object_required']);
            }
            if (array_key_exists('source', $payload['target'])) {
                $source = $payload['target']['source'];
                if (!is_string($source) || !in_array($source, ['manual', 'service_ip'], true)) {
                    self::invalid(['target.source' => 'invalid']);
                }
                $changes['target_source'] = $source;
            }
            if (array_key_exists('host', $payload['target'])) {
                if (!is_string($payload['target']['host'])) {
                    self::invalid(['target.host' => 'string_required']);
                }
                $changes['target_host'] = self::validateTargetHost($payload['target']['host']);
            }
            if (array_key_exists('port', $payload['target'])) {
                $changes['target_port'] = self::requiredPort($payload['target']['port'], 'target.port');
            }
            if (array_key_exists('service_id', $payload['target'])) {
                $changes['target_service_id'] = self::positiveInt($payload['target']['service_id'], 'target.service_id');
            }
        }

        if ($changes === []) {
            self::invalid(['patch' => 'non_empty']);
        }

        return ['changes' => $changes];
    }

    /** @param array<string, mixed> $payload */
    public static function normalizeLegacyCreate(array $payload): array
    {
        $normalized = self::normalizeLegacy($payload, true);
        if (!array_key_exists('forward_route_id', $normalized)) {
            self::invalid(['forward_route_id' => 'required']);
        }
        if (!array_key_exists('target_host', $normalized) || !array_key_exists('target_port', $normalized)) {
            self::invalid(['target' => 'required']);
        }
        return $normalized;
    }

    /** @param array<string, mixed> $payload */
    public static function normalizeLegacyUpdate(array $payload): array
    {
        return self::normalizeLegacy($payload, false);
    }

    public static function validateTargetHost(string $host): string
    {
        if ($host === '' || strlen($host) > 253 || preg_match('/[^\x21-\x7e]/', $host) === 1) {
            self::invalid(['target.host' => 'invalid']);
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $host;
        }

        $label = '[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?';
        if (preg_match('/\A(?=.{1,253}\z)' . $label . '(?:\.' . $label . ')*\z/D', $host) !== 1) {
            self::invalid(['target.host' => 'invalid']);
        }

        return $host;
    }

    /** @param array<string, mixed> $normalized */
    public static function requestHash(array $normalized): string
    {
        try {
            return hash('sha256', json_encode(self::canonicalize($normalized), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } catch (JsonException $exception) {
            throw new \RuntimeException('request hash failed', 0, $exception);
        }
    }

    /** @param array<string, mixed> $forward */
    public static function etag(array $forward): string
    {
        return sprintf('"forward-%d-r%d"', max(1, (int) ($forward['id'] ?? 0)), max(1, (int) ($forward['api_revision'] ?? 1)));
    }

    /** @param array<string, mixed> $forward */
    public static function matchesEtag(?string $ifMatch, array $forward): bool
    {
        return is_string($ifMatch) && hash_equals(self::etag($forward), $ifMatch);
    }

    /** @param array<string, mixed> $forward */
    public static function presentForward(array $forward): array
    {
        $endpoints = is_array($forward['endpoints'] ?? null) ? $forward['endpoints'] : [];
        $endpointCount = max(0, (int) ($forward['endpoint_count'] ?? count($endpoints)));
        $activeEndpointCount = array_key_exists('active_endpoint_count', $forward)
            ? max(0, (int) $forward['active_endpoint_count'])
            : count(array_filter($endpoints, static fn(mixed $endpoint): bool => is_array($endpoint) && (string) ($endpoint['health_status'] ?? '') === 'active'));
        $transport = (string) ($forward['transport'] ?? $forward['transport_mode'] ?? 'tcp');
        if (!in_array($transport, self::TRANSPORTS, true)) {
            $transport = 'tcp';
        }

        return [
            'id' => (int) ($forward['id'] ?? 0),
            'service_id' => (int) ($forward['service_id'] ?? 0),
            'route' => [
                'id' => (int) ($forward['forward_route_id'] ?? $forward['route_id'] ?? 0),
                'label' => (string) ($forward['route_label'] ?? $forward['tenant_label'] ?? $forward['label'] ?? ''),
            ],
            'listen_port' => (int) ($forward['listen_port'] ?? 0),
            'transport' => $transport,
            'target' => [
                'source' => (string) ($forward['target_source'] ?? 'manual'),
                'host' => (string) ($forward['target_host'] ?? ''),
                'port' => (int) ($forward['target_port'] ?? 0),
                'service_id' => isset($forward['target_service_id']) ? (int) $forward['target_service_id'] : null,
            ],
            'ingress' => [
                'host' => (string) ($forward['ingress_host'] ?? $forward['public_host'] ?? $forward['cdn_host'] ?? ''),
                'port' => (int) ($forward['ingress_port'] ?? $forward['public_port'] ?? 0),
            ],
            'state' => !empty($forward['enabled']) ? 'active' : 'deleted',
            'endpoint_count' => $endpointCount,
            'active_endpoint_count' => $activeEndpointCount,
            'revision' => max(1, (int) ($forward['api_revision'] ?? 1)),
            'created_at' => (string) ($forward['created_at'] ?? ''),
            'updated_at' => (string) ($forward['updated_at'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $route */
    public static function presentRoute(array $route): array
    {
        $availability = is_array($route['availability'] ?? null) ? $route['availability'] : [];
        $transports = [];
        foreach (self::TRANSPORTS as $transport) {
            $source = $availability[$transport] ?? ($transport === 'tcp+udp' ? ($availability['both'] ?? null) : null);
            $source = is_array($source) ? $source : [];
            $reason = (string) ($source['unavailable_reason'] ?? '');
            if ($reason !== '' && !in_array($reason, ['route_unavailable', 'no_healthy_nodes', 'transport_unavailable', 'not_configured'], true)) {
                $reason = 'route_unavailable';
            }
            $transports[$transport] = [
                'available' => !empty($source['available']),
                'healthy_node_count' => max(0, (int) ($source['healthy_node_count'] ?? 0)),
                'unavailable_reason' => $reason !== '' ? $reason : null,
            ];
        }

        return [
            'id' => (int) ($route['id'] ?? 0),
            'label' => (string) ($route['tenant_label'] ?? $route['label'] ?? ''),
            'description' => isset($route['tenant_description']) ? (string) $route['tenant_description'] : null,
            'badge' => isset($route['tenant_badge']) ? (string) $route['tenant_badge'] : null,
            'transports' => $transports,
        ];
    }

    /** @param array<string, mixed> $summary */
    public static function presentService(array $summary): array
    {
        $features = is_array($summary['effective_features'] ?? null) ? $summary['effective_features'] : [];
        $forwards = is_array($summary['forwards'] ?? null) ? $summary['forwards'] : [];
        return [
            'id' => (int) ($summary['id'] ?? 0),
            'name' => (string) ($summary['name'] ?? ''),
            'status' => (string) ($summary['status'] ?? ''),
            'plan' => [
                'id' => isset($summary['plan_id']) ? (int) $summary['plan_id'] : null,
                'name' => isset($summary['plan_name']) ? (string) $summary['plan_name'] : null,
            ],
            'capabilities' => [
                'allow_manage_forwards' => !empty($features['allow_manage_forwards']),
                'allow_delete_forwards' => !empty($features['allow_delete_forwards']),
            ],
            'active_forward_count' => array_key_exists('active_forward_count', $summary)
                ? max(0, (int) $summary['active_forward_count'])
                : count($forwards),
            'forward_limit' => max(1, (int) ($summary['forward_limit'] ?? 64)),
        ];
    }

    /** @param array<string, mixed> $payload */
    private static function normalizeLegacy(array $payload, bool $create): array
    {
        self::rejectUnknown($payload, [
            'forward_route_id', 'route_id', 'listen_port', 'target_host', 'target_port',
            'target_source', 'target_service_id', 'transport_mode',
        ]);
        if (array_key_exists('forward_route_id', $payload) && array_key_exists('route_id', $payload)) {
            self::invalid(['route_id' => 'duplicate']);
        }
        if (!$create && $payload === []) {
            self::invalid(['patch' => 'non_empty']);
        }

        $normalized = [];
        if (array_key_exists('forward_route_id', $payload) || array_key_exists('route_id', $payload)) {
            $normalized['forward_route_id'] = self::positiveInt(
                $payload['forward_route_id'] ?? $payload['route_id'],
                'forward_route_id'
            );
        }
        if (array_key_exists('listen_port', $payload)) {
            $normalized['listen_port'] = self::optionalPort($payload['listen_port'], 'listen_port', true);
        }
        if (array_key_exists('target_host', $payload)) {
            if (!is_string($payload['target_host'])) {
                self::invalid(['target.host' => 'string_required']);
            }
            $normalized['target_host'] = self::validateTargetHost($payload['target_host']);
        }
        if (array_key_exists('target_port', $payload)) {
            $normalized['target_port'] = self::requiredPort($payload['target_port'], 'target.port');
        }
        if (array_key_exists('target_source', $payload)) {
            if (!is_string($payload['target_source']) || !in_array($payload['target_source'], ['manual', 'service_ip'], true)) {
                self::invalid(['target.source' => 'invalid']);
            }
            $normalized['target_source'] = $payload['target_source'];
        }
        if (array_key_exists('target_service_id', $payload)) {
            $normalized['target_service_id'] = self::positiveInt($payload['target_service_id'], 'target.service_id');
        }
        if (array_key_exists('transport_mode', $payload)) {
            $normalized['transport_mode'] = self::transport($payload['transport_mode']);
        }
        return $normalized;
    }

    /** @param array<string, mixed>|null $target */
    private static function normalizeTarget(?array $target): array
    {
        if ($target === null) {
            self::invalid(['target' => 'object_required']);
        }
        self::rejectUnknown($target, ['source', 'host', 'port', 'service_id'], 'target.');
        self::requireKeys($target, ['source', 'port']);
        if (!is_string($target['source']) || !in_array($target['source'], ['manual', 'service_ip'], true)) {
            self::invalid(['target.source' => 'invalid']);
        }
        $source = $target['source'];
        $host = null;
        $serviceId = null;
        if ($source === 'manual') {
            self::requireKeys($target, ['host']);
            if (!is_string($target['host'])) {
                self::invalid(['target.host' => 'string_required']);
            }
            $host = self::validateTargetHost($target['host']);
            if (array_key_exists('service_id', $target)) {
                self::invalid(['target.service_id' => 'forbidden_for_manual']);
            }
        } else {
            self::requireKeys($target, ['service_id']);
            $serviceId = self::positiveInt($target['service_id'], 'target.service_id');
            if (array_key_exists('host', $target)) {
                self::invalid(['target.host' => 'forbidden_for_service_ip']);
            }
        }
        return [
            'source' => $source,
            'host' => $host,
            'port' => self::requiredPort($target['port'], 'target.port'),
            'service_id' => $serviceId,
        ];
    }

    /** @param array<string, mixed> $payload @param list<string> $allowed */
    private static function rejectUnknown(array $payload, array $allowed, string $prefix = ''): void
    {
        $unknown = [];
        foreach (array_keys($payload) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                $unknown[] = $prefix . (string) $key;
            }
        }
        if ($unknown !== []) {
            self::throwApi('unknown_fields', ['fields' => $unknown]);
        }
    }

    /** @param array<string, mixed> $payload @param list<string> $keys */
    private static function requireKeys(array $payload, array $keys): void
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $payload)) {
                self::invalid([$key => 'required']);
            }
        }
    }

    private static function positiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) || $value <= 0) {
            self::invalid([$field => 'positive_integer_required']);
        }
        return $value;
    }

    private static function requiredPort(mixed $value, string $field): int
    {
        if (!is_int($value) || $value < 1 || $value > 65535) {
            self::invalid([$field => 'out_of_range']);
        }
        return $value;
    }

    private static function optionalPort(mixed $value, string $field, bool $allowNull): int
    {
        if ($allowNull && $value === null) {
            return 0;
        }
        return self::requiredPort($value, $field);
    }

    private static function transport(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::TRANSPORTS, true)) {
            self::invalid(['transport' => 'invalid']);
        }
        return $value;
    }

    /** @param array<string, mixed> $fields */
    private static function invalid(array $fields): never
    {
        self::throwApi('validation_failed', ['fields' => $fields]);
    }

    /** @param array<string, mixed> $details */
    private static function throwApi(string $code, array $details): never
    {
        throw new ApiException($code, $code === 'unknown_fields' ? 422 : 422, $details);
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'canonicalize'], $value);
        }
        ksort($value);
        foreach ($value as $key => $child) {
            $value[$key] = self::canonicalize($child);
        }
        return $value;
    }
}
