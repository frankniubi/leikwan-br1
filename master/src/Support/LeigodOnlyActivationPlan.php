<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use InvalidArgumentException;

final class LeigodOnlyActivationPlan
{
    /** @var array<string, mixed> */
    private array $payload;
    private string $hash;

    /** @param array<string, mixed> $payload */
    private function __construct(array $payload)
    {
        $this->payload = self::normalizePayload($payload);
        $this->hash = hash('sha256', self::canonicalJson($this->payload));
    }

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        return new self($payload);
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    public function hash(): string
    {
        return $this->hash;
    }

    /** @param array<string, mixed> $payload */
    public static function canonicalHash(array $payload): string
    {
        return hash('sha256', self::canonicalJson(self::normalizePayload($payload)));
    }

    public static function canonicalJson(mixed $value): string
    {
        $json = json_encode(self::canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new InvalidArgumentException('activation_plan_payload_not_json_encodable');
        }
        return $json;
    }

    /** @return array<string, mixed> */
    private static function normalizePayload(array $payload): array
    {
        $scope = self::nonEmptyString($payload['scope'] ?? 'global', 'scope');
        $normalized = [
            'scope' => $scope,
            'dry_run_only' => (bool)($payload['dry_run_only'] ?? true),
            'artifact_gate' => self::gate($payload['artifact_gate'] ?? ['ok' => false, 'blockers' => ['artifact_gate_missing']]),
            'runtime_contract_gate' => self::gate($payload['runtime_contract_gate'] ?? ['ok' => false, 'blockers' => ['runtime_contract_gate_missing']]),
            'leg_policy_gate' => self::gate($payload['leg_policy_gate'] ?? ['ok' => false, 'blockers' => ['leg_policy_gate_missing']]),
            'telemetry_gate' => self::gate($payload['telemetry_gate'] ?? ['ok' => false, 'blockers' => ['telemetry_gate_missing']]),
            'canary_gate' => self::gate($payload['canary_gate'] ?? ['ok' => false, 'blockers' => ['canary_gate_missing']]),
            'normalization_gate' => self::gate($payload['normalization_gate'] ?? ['ok' => false, 'blockers' => ['normalization_gate_missing']]),
            'retirement_gate' => self::gate($payload['retirement_gate'] ?? ['ok' => false, 'blockers' => ['retirement_gate_missing']]),
            'rollback_gate' => self::gate($payload['rollback_gate'] ?? ['ok' => false, 'blockers' => ['rollback_gate_missing']]),
            'blockers' => self::stringList($payload['blockers'] ?? []),
        ];

        foreach ($payload as $key => $value) {
            if (!array_key_exists((string)$key, $normalized)) {
                $normalized[(string)$key] = $value;
            }
        }

        $normalized['blockers'] = self::stringList(array_merge(
            $normalized['blockers'],
            self::gateBlockers($normalized['artifact_gate']),
            self::gateBlockers($normalized['runtime_contract_gate']),
            self::gateBlockers($normalized['leg_policy_gate']),
            self::gateBlockers($normalized['telemetry_gate']),
            self::gateBlockers($normalized['canary_gate']),
            self::gateBlockers($normalized['normalization_gate']),
            self::gateBlockers($normalized['retirement_gate']),
            self::gateBlockers($normalized['rollback_gate'])
        ));

        return $normalized;
    }

    /** @return array<string, mixed> */
    private static function gate(mixed $gate): array
    {
        if (!is_array($gate)) {
            return ['ok' => false, 'blockers' => ['malformed_gate']];
        }
        $result = $gate;
        $result['ok'] = (bool)($result['ok'] ?? false);
        $result['blockers'] = self::stringList($result['blockers'] ?? []);
        return $result;
    }

    /** @param array<string, mixed> $gate @return list<string> */
    private static function gateBlockers(array $gate): array
    {
        return self::stringList($gate['blockers'] ?? []);
    }

    /** @return list<string> */
    public static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $items = [];
        foreach ($value as $item) {
            if (is_scalar($item)) {
                $text = trim((string)$item);
                if ($text !== '') {
                    $items[] = $text;
                }
            }
        }
        $items = array_values(array_unique($items));
        sort($items, SORT_STRING);
        return $items;
    }

    public static function nonEmptyString(mixed $value, string $field): string
    {
        if (!is_scalar($value) || trim((string)$value) === '') {
            throw new InvalidArgumentException('missing_' . $field);
        }
        return trim((string)$value);
    }

    public static function canonicalize(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = (array)$value;
        }
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $items = array_map([self::class, 'canonicalize'], $value);
            usort($items, static function (mixed $a, mixed $b): int {
                return strcmp(json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '', json_encode($b, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
            });
            return $items;
        }

        ksort($value, SORT_STRING);
        $result = [];
        foreach ($value as $key => $item) {
            $result[(string)$key] = self::canonicalize($item);
        }
        return $result;
    }
}
