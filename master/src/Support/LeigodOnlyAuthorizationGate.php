<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use InvalidArgumentException;

final class LeigodOnlyAuthorizationGate
{
    /** @var list<string> */
    private const SAFE_ACTIONS = ['planning', 'plan', 'dry_run', 'status', 'status_check', 'check'];

    /** @var list<string> */
    private const OUTWARD_ACTIONS = [
        'deploy',
        'deployment',
        'production_deploy',
        'artifact_publication',
        'publish_artifacts',
        'artifact_publish',
        'static_mirror_synchronization',
        'static_mirror_sync',
        'mirror_sync',
        'cleanup_execution',
        'cleanup',
        'remote_operation',
        'remote_ops',
        'commit',
        'push',
        'pr',
        'pull_request',
        'release',
    ];

    /**
     * @param array<string, mixed> $context
     */
    public static function assertNoOutwardAction(array $context): void
    {
        foreach (self::contextActions($context) as $action) {
            if (self::isOutwardAction($action)) {
                throw new InvalidArgumentException(
                    'leigod_only_outward_action_forbidden action=' . $action
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function assertSeparateAuthorization(string $action, array $context): void
    {
        $normalized = self::normalizeAction($action);
        if (self::isSafeAction($normalized)) {
            return;
        }

        if (!self::isOutwardAction($normalized)) {
            return;
        }

        $reference = $context['external_authorization_ref'] ?? $context['reference'] ?? null;
        if (!is_string($reference) || trim($reference) === '') {
            throw new InvalidArgumentException('missing_separate_authorization:' . $action);
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return list<string>
     */
    private static function contextActions(array $context): array
    {
        return self::collectContextActions($context, false);
    }

    /**
     * @return list<string>
     */
    private static function collectContextActions(mixed $value, bool $insideActionBearingField): array
    {
        if (is_object($value)) {
            return self::collectContextActions((array) $value, $insideActionBearingField);
        }

        if (is_array($value)) {
            $actions = [];
            foreach ($value as $key => $item) {
                if (is_string($key)) {
                    $normalizedKey = self::normalizeAction($key);
                    if (self::isOutwardAction($normalizedKey)) {
                        $actions[] = $normalizedKey;
                    }
                }

                $itemIsActionBearing = self::isActionBearingKey($key);
                $childIsActionBearing = $insideActionBearingField || $itemIsActionBearing;
                array_push($actions, ...self::collectContextActions($item, $childIsActionBearing));
            }
            return $actions;
        }

        if (!$insideActionBearingField || !is_scalar($value)) {
            return [];
        }

        return [self::normalizeAction((string) $value)];
    }

    private static function isActionBearingKey(int|string $key): bool
    {
        if (is_int($key)) {
            return false;
        }

        $normalized = self::normalizeAction($key);
        $parts = array_filter(explode('_', $normalized), static fn (string $part): bool => $part !== '');
        foreach ($parts as $part) {
            if (in_array($part, ['action', 'actions', 'operation', 'operations', 'command', 'commands'], true)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeAction(string $action): string
    {
        return preg_replace('/[\s\-]+/', '_', strtolower(trim($action))) ?? strtolower(trim($action));
    }

    private static function isSafeAction(string $action): bool
    {
        return in_array($action, self::SAFE_ACTIONS, true);
    }

    private static function isOutwardAction(string $action): bool
    {
        if (in_array($action, self::OUTWARD_ACTIONS, true)) {
            return true;
        }

        $parts = array_values(array_filter(explode('_', $action), static fn (string $part): bool => $part !== ''));
        foreach (self::OUTWARD_ACTIONS as $marker) {
            if (strlen($marker) <= 2) {
                if (in_array($marker, $parts, true)) {
                    return true;
                }
                continue;
            }

            if (str_contains($action, $marker)) {
                return true;
            }
        }

        return false;
    }
}
