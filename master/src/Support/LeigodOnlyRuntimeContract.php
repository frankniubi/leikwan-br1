<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use InvalidArgumentException;

/**
 * Validates the active LeiGod-only runtime/fence payload.
 *
 * Callers must pass only the active runtime contract payload they intend to
 * assert or persist. This validator intentionally rejects any legacy runtime,
 * fallback, or transport marker anywhere in the supplied payload, including
 * nested keys and nested values. That whole-payload strictness is part of the
 * Task 40 activation contract: a caller that needs to carry historical or
 * diagnostic legacy data must keep it outside the payload passed here.
 */
final class LeigodOnlyRuntimeContract
{
    public const BACKEND_MODE = 'leigod_mode';
    public const CLIENT_MODE = 'bond';
    public const RUNTIME_PREFERENCE = 'single';
    public const BOND_INGRESS_MODE = 'plain_tcp_mux';
    public const ACTIVE_FAMILY = 'standalone_bond';

    /** @var array<string, string> */
    private const REQUIRED_VALUES = [
        'backend_mode' => self::BACKEND_MODE,
        'client_mode' => self::CLIENT_MODE,
        'runtime_preference' => self::RUNTIME_PREFERENCE,
        'bond_ingress_mode' => self::BOND_INGRESS_MODE,
        'active_family' => self::ACTIVE_FAMILY,
    ];

    /** @var list<string> */
    private const FORBIDDEN_MARKERS = [
        'current_mode',
        'cf_mode',
        'native_mptcp',
        'leikwan-mptcp-relay',
        'platform_default',
        'mptcp_preferred_bond_fallback',
        'bond_fallback',
        'cloudflare',
        'wss',
        'classic_sidecar',
        'classic sidecar',
    ];

    /**
     * @param array<string, mixed> $row Active runtime/fence payload only; legacy markers anywhere in keys or values are rejected by design.
     * @return array<string, string>
     */
    public static function validate(array $row, string $context): array
    {
        $marker = self::findForbiddenMarker($row);
        if ($marker !== null) {
            throw self::violation($context, '<row>', 'forbidden_marker', $marker);
        }

        $normalized = [];
        foreach (self::REQUIRED_VALUES as $field => $expected) {
            if (!array_key_exists($field, $row)) {
                throw self::violation($context, $field, 'missing', $expected);
            }
            $actual = is_scalar($row[$field]) ? trim((string) $row[$field]) : '';
            if ($actual !== $expected) {
                throw self::violation($context, $field, 'mismatch', $actual === '' ? '<empty>' : $actual);
            }
            $normalized[$field] = $actual;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $row Active runtime/fence payload only; legacy markers anywhere in keys or values are rejected by design.
     */
    public static function assertActiveContract(array $row, string $context): void
    {
        self::validate($row, $context);
    }

    private static function findForbiddenMarker(mixed $value): ?string
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $marker = self::findForbiddenMarker($key);
                if ($marker !== null) {
                    return $marker;
                }
                $marker = self::findForbiddenMarker($item);
                if ($marker !== null) {
                    return $marker;
                }
            }
            return null;
        }

        if (is_object($value)) {
            return self::findForbiddenMarker((array) $value);
        }

        if (!is_scalar($value)) {
            return null;
        }

        $text = strtolower((string) $value);
        foreach (self::FORBIDDEN_MARKERS as $marker) {
            if (str_contains($text, strtolower($marker))) {
                return $marker;
            }
        }

        return null;
    }

    private static function violation(string $context, string $field, string $reason, string $value): InvalidArgumentException
    {
        return new InvalidArgumentException(
            'leigod_only_runtime_contract_violation context=' . $context
            . ' field=' . $field
            . ' reason=' . $reason
            . ' value=' . $value
        );
    }
}
