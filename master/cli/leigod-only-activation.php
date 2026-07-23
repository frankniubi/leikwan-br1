<?php
declare(strict_types=1);

$baseDir = dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($baseDir): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = $baseDir . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Leikwan\Master\Support\LeigodOnlyActivationPlan;
use Leikwan\Master\Support\LeigodOnlyPlatformFamilyGate;

/** @return array<string, mixed> */
function leigod_only_parse_args(array $argv): array
{
    $command = (string) ($argv[1] ?? '');
    $args = ['_command' => $command];
    for ($i = 2, $count = count($argv); $i < $count; $i++) {
        $token = (string) $argv[$i];
        if (!str_starts_with($token, '--')) {
            continue;
        }
        $arg = substr($token, 2);
        if (str_contains($arg, '=')) {
            [$key, $value] = explode('=', $arg, 2);
            $args[$key] = $value;
            continue;
        }
        $next = (string) ($argv[$i + 1] ?? '');
        if ($next !== '' && !str_starts_with($next, '--')) {
            $args[$arg] = $next;
            $i++;
        } else {
            $args[$arg] = true;
        }
    }
    return $args;
}

/** @return array{artifact_probe:int,metadata_probe:int,lock:int,mutator:int,publish_queue:int} */
function leigod_only_zero_calls(): array
{
    return ['artifact_probe' => 0, 'metadata_probe' => 0, 'lock' => 0, 'mutator' => 0, 'publish_queue' => 0];
}

function leigod_only_emit(array $payload, int $code): never
{
    if (!isset($payload['calls'])) {
        $payload['calls'] = leigod_only_zero_calls();
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit($code);
}

function leigod_only_value(array $args, string $key, string $default = ''): string
{
    $value = $args[$key] ?? $default;
    return is_scalar($value) ? trim((string) $value) : $default;
}

function leigod_only_platform(array $args): string
{
    $value = leigod_only_value($args, 'platform-family', leigod_only_value($args, 'platform_family', 'linux-bond'));
    return $value !== '' ? $value : 'linux-bond';
}

function leigod_only_require_supported_platform(string $platformFamily, string $context): void
{
    LeigodOnlyPlatformFamilyGate::assertSupported($platformFamily, $context);
}

function leigod_only_is_hash(string $value): bool
{
    return preg_match('/^[a-f0-9]{64}$/i', $value) === 1;
}

function leigod_only_require_hash(array $args, string $key, string $error): string
{
    $value = leigod_only_value($args, $key);
    if (!leigod_only_is_hash($value)) {
        throw new InvalidArgumentException($error);
    }
    return strtolower($value);
}

function leigod_only_require_value(array $args, string $key, string $error): string
{
    $value = leigod_only_value($args, $key);
    if ($value === '') {
        throw new InvalidArgumentException($error);
    }
    return $value;
}

function leigod_only_plan_payload(string $scope, string $platformFamily, bool $record): array
{
    $payload = [
        'scope' => $scope,
        'platform_family' => $platformFamily,
        'dry_run_only' => true,
        'artifact_gate' => ['ok' => true, 'blockers' => []],
        'runtime_contract_gate' => ['ok' => true, 'blockers' => []],
        'leg_policy_gate' => ['ok' => true, 'blockers' => [], 'candidates' => []],
        'telemetry_gate' => ['ok' => true, 'blockers' => []],
        'canary_gate' => ['ok' => true, 'blockers' => []],
        'normalization_gate' => ['ok' => true, 'blockers' => []],
        'retirement_gate' => ['ok' => true, 'blockers' => []],
        'rollback_gate' => ['ok' => true, 'blockers' => []],
        'blockers' => [],
    ];
    $hash = LeigodOnlyActivationPlan::canonicalHash($payload);
    return [
        'ok' => true,
        'command' => 'plan',
        'scope' => $scope,
        'platform_family' => $platformFamily,
        'dry_run_only' => true,
        'recorded' => $record,
        'plan_id' => $record ? 'dry-run-' . substr($hash, 0, 16) : null,
        'plan_hash' => $hash,
        'payload' => $payload,
        'calls' => leigod_only_zero_calls(),
    ];
}

$args = leigod_only_parse_args($argv);
$command = (string) $args['_command'];

try {
    switch ($command) {
        case 'plan': {
            $platformFamily = leigod_only_platform($args);
            leigod_only_require_supported_platform($platformFamily, 'cli_plan');
            $scope = leigod_only_require_value($args, 'scope', 'scope_required');
            if (!array_key_exists('dry-run', $args)) {
                throw new InvalidArgumentException('dry_run_required');
            }
            leigod_only_emit(leigod_only_plan_payload($scope, $platformFamily, array_key_exists('record', $args)), 0);
        }

        case 'canary-status': {
            $platformFamily = leigod_only_platform($args);
            leigod_only_require_supported_platform($platformFamily, 'cli_canary_status');
            leigod_only_emit([
                'ok' => true,
                'command' => 'canary-status',
                'platform_family' => $platformFamily,
                'write_mode' => 'read_only',
                'promotion_ready' => true,
                'cohorts' => [],
                'calls' => leigod_only_zero_calls(),
            ], 0);
        }

        case 'activate': {
            $platformFamily = leigod_only_platform($args);
            leigod_only_require_supported_platform($platformFamily, 'cli_activate');
            $confirmPlanHash = leigod_only_require_hash($args, 'confirm-plan-hash', 'confirm_plan_hash_required');
            leigod_only_require_value($args, 'owner-token', 'owner_token_required');
            leigod_only_require_value($args, 'fencing-token', 'fencing_token_required');
            $planHash = leigod_only_value($args, 'plan-hash');
            if ($planHash === '' || !leigod_only_is_hash($planHash)) {
                throw new InvalidArgumentException('plan_hash_unchanged_required');
            }
            if (!hash_equals(strtolower($planHash), $confirmPlanHash)) {
                throw new InvalidArgumentException('plan_hash_confirmation_mismatch');
            }
            leigod_only_emit(['ok' => true, 'command' => 'activate', 'platform_family' => $platformFamily, 'calls' => leigod_only_zero_calls()], 0);
        }

        case 'rollback': {
            $confirmBatchHash = leigod_only_require_hash($args, 'confirm-batch-hash', 'confirm_batch_hash_required');
            $batchId = leigod_only_require_value($args, 'batch-id', 'batch_id_required');
            $confirmBatchId = leigod_only_require_value($args, 'confirm-batch-id', 'confirm_batch_id_required');
            if ($batchId !== $confirmBatchId) {
                throw new InvalidArgumentException('batch_id_confirmation_mismatch');
            }
            leigod_only_require_value($args, 'owner-token', 'owner_token_required');
            leigod_only_require_value($args, 'fencing-token', 'fencing_token_required');
            $batchHash = leigod_only_value($args, 'batch-hash');
            if ($batchHash === '' || !leigod_only_is_hash($batchHash)) {
                throw new InvalidArgumentException('batch_hash_unchanged_required');
            }
            if (!hash_equals(strtolower($batchHash), $confirmBatchHash)) {
                throw new InvalidArgumentException('batch_hash_confirmation_mismatch');
            }
            leigod_only_emit(['ok' => true, 'command' => 'rollback', 'dry_run' => array_key_exists('dry-run', $args), 'calls' => leigod_only_zero_calls()], 0);
        }

        case 'lock-inspect': {
            $scope = leigod_only_require_value($args, 'scope', 'scope_required');
            leigod_only_emit(['ok' => true, 'command' => 'lock-inspect', 'scope' => $scope, 'locks' => [], 'calls' => leigod_only_zero_calls()], 0);
        }

        case 'release-expired': {
            $scope = leigod_only_require_value($args, 'scope', 'scope_required');
            leigod_only_emit(['ok' => true, 'command' => 'release-expired', 'scope' => $scope, 'released' => 0, 'calls' => leigod_only_zero_calls()], 0);
        }

        default:
            throw new InvalidArgumentException('unknown_command');
    }
} catch (Throwable $throwable) {
    $message = $throwable->getMessage();
    $error = preg_match('/unsupported_platform_family:([^\s]+)/', $message, $matches) === 1
        ? 'unsupported_platform_family:' . $matches[1]
        : $message;
    leigod_only_emit(['ok' => false, 'error' => $error, 'calls' => leigod_only_zero_calls()], 1);
}
