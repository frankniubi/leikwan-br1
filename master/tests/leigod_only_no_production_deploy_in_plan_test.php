<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$repoRoot = dirname(__DIR__, 2);

/**
 * @return string
 */
function source_slice(string $source, ?string $startNeedle = null, ?string $endNeedle = null): string
{
    if ($startNeedle === null) {
        return $source;
    }
    $start = strpos($source, $startNeedle);
    if ($start === false) {
        fail('slice start not found: ' . $startNeedle);
    }
    $end = $endNeedle === null ? false : strpos($source, $endNeedle, $start + strlen($startNeedle));
    return $end === false ? substr($source, $start) : substr($source, $start, $end - $start);
}

/** @return array<string,string> */
function scanned_task40_surfaces(string $repoRoot): array
{
    $paths = [
        'master/cli/leigod-only-activation.php' => [null, null],
        'master/src/Controllers/AdminController.php' => ['public function leigodOnlyRolloutPage', 'public function republishProvisionEndpoint'],
        'master/src/Support/ProvisioningService.php' => ['private function reconcileForwardEndpoints', 'private function desiredEndpointPayloadFingerprint'],
        'master/src/Support/LeigodOnlyAuthorizationGate.php' => [null, null],
        'master/src/Support/LeigodOnlyActivationPlanner.php' => [null, null],
        'master/src/Support/LeigodStandaloneBondActivationGateService.php' => [null, null],
        'master/src/Support/LeigodOnlyRollbackService.php' => [null, null],
        'master/src/Support/LeigodOnlyLegacyRetirementActivationService.php' => [null, null],
        'master/src/Support/LeigodOnlyNormalizationActivationService.php' => [null, null],
        'master/src/Support/LeigodOnlyFencedPublishExecutor.php' => [null, null],
        'master/src/Support/LeigodOnlyArtifactPointerService.php' => [null, null],
    ];

    $surfaces = [];
    foreach ($paths as $relative => [$start, $end]) {
        $path = $repoRoot . '/' . $relative;
        if (!is_file($path)) {
            fail('missing scanned surface: ' . $relative);
        }
        $source = (string) file_get_contents($path);
        $surfaces[$relative] = source_slice($source, $start, $end);
    }
    return $surfaces;
}

$surfaces = scanned_task40_surfaces($repoRoot);

$forbiddenExecutablePatterns = [
    'plink command' => '/(?<![A-Za-z0-9_])plink(?![A-Za-z0-9_])/i',
    'pscp command' => '/(?<![A-Za-z0-9_])pscp(?![A-Za-z0-9_])/i',
    'scp command' => '/(?<![A-Za-z0-9_])scp(?![A-Za-z0-9_])/i',
    'ssh command' => '/(?<![A-Za-z0-9_])ssh(?![A-Za-z0-9_])/i',
    'rsync command' => '/(?<![A-Za-z0-9_])rsync(?![A-Za-z0-9_])/i',
    'systemctl mutator' => '/\bsystemctl\s+(restart|stop|disable|enable)\b/i',
    'wg-quick teardown' => '/\bwg-quick\s+down\b/i',
    'ip link delete' => '/\bip\s+link\s+(delete|del)\b/i',
    'curl remote URL' => '/\bcurl\s+https?:\/\//i',
    'shell_exec call' => '/\bshell_exec\s*\(/i',
    'exec call' => '/\bexec\s*\(/i',
    'system call' => '/\bsystem\s*\(/i',
    'proc_open call' => '/\bproc_open\s*\(/i',
    'git commit' => '/\bgit\s+commit\b/i',
    'git push' => '/\bgit\s+push\b/i',
    'gh pr create' => '/\bgh\s+pr\s+create\b/i',
    'gh pr merge' => '/\bgh\s+pr\s+merge\b/i',
    'artifact publication write' => '~\b(file_put_contents|fopen|copy|rename)\s*\([^;]*(artifacts|versions\.json)~is',
    'static mirror write' => '~\b(file_put_contents|fopen|copy|rename)\s*\([^;]*(master/public/artifacts|public[\\\\/]artifacts)~is',
];

foreach ($surfaces as $relative => $source) {
    foreach ($forbiddenExecutablePatterns as $label => $pattern) {
        if (preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE) === 1) {
            $line = substr_count(substr($source, 0, (int) $match[0][1]), "\n") + 1;
            fail('forbidden executable surface: ' . $relative . ':' . $line . ' ' . $label . ' matched ' . $match[0][0]);
        }
    }
}

$authorizationSource = $surfaces['master/src/Support/LeigodOnlyAuthorizationGate.php'];
foreach (['deploy', 'artifact_publication', 'static_mirror_synchronization', 'cleanup_execution', 'remote_operation', 'commit', 'push', 'pr', 'release'] as $action) {
    if (!str_contains($authorizationSource, "'" . $action . "'")) {
        fail('authorization gate missing outward action marker: ' . $action);
    }
}
if (!str_contains($authorizationSource, 'public static function assertSeparateAuthorization')) {
    fail('authorization gate must expose separate-authorization boundary');
}

$task40Sources = $surfaces;
unset($task40Sources['master/src/Support/ProvisioningService.php']);
unset($task40Sources['master/src/Support/LeigodOnlyFencedPublishExecutor.php']);
foreach ($task40Sources as $relative => $source) {
    if (str_contains($source, 'reconcileForwardEndpoints(')) {
        fail('Task 40 path must not call generic reconcileForwardEndpoints directly: ' . $relative);
    }
}

$provisioningSource = $surfaces['master/src/Support/ProvisioningService.php'];
if (!str_contains($provisioningSource, 'private function reconcileForwardEndpoints(int $forwardId): void')) {
    fail('generic reconcileForwardEndpoints must remain private and not first-class callable');
}

$executorSource = $surfaces['master/src/Support/LeigodOnlyFencedPublishExecutor.php'];
if (!str_contains($executorSource, 'publishFenced')) {
    fail('fenced executor must publish only through publishFenced wrapper');
}
if (str_contains($executorSource, 'reconcileForwardEndpoints(')) {
    fail('fenced executor must not call generic reconcileForwardEndpoints directly');
}
foreach (['owner_token', 'fencing_token', 'publish_fence_token', 'desired_state_hash', 'desired_generation', 'forward_id', 'job_hash'] as $field) {
    if (!str_contains($executorSource, $field)) {
        fail('fenced executor missing first-class publish predicate field: ' . $field);
    }
}

$legacyRetirementSource = $surfaces['master/src/Support/LeigodOnlyLegacyRetirementActivationService.php'];
if (!str_contains($legacyRetirementSource, 'assertNoActiveTransaction')) {
    fail('retirement activation must prove cleanup lease/result handling is outside an active DB transaction');
}
foreach (['leaseAdapter->issue', 'cleanup_lease_token', 'cleanup_result_token', 'max_result_bytes'] as $snippet) {
    if (!str_contains($legacyRetirementSource, $snippet)) {
        fail('retirement cleanup proof guard missing bounded fake-lease snippet: ' . $snippet);
    }
}

$cliSource = $surfaces['master/cli/leigod-only-activation.php'];
foreach (['platform-family', 'unsupported_platform_family', 'confirm-plan-hash', 'confirm-batch-hash', 'owner-token', 'fencing-token'] as $snippet) {
    if (!str_contains($cliSource, $snippet)) {
        fail('CLI guard surface missing required non-deployment guard: ' . $snippet);
    }
}

$adminSource = $surfaces['master/src/Controllers/AdminController.php'];
foreach (['requireCsrf($request)', 'confirm_plan_hash', 'confirm_batch_hash', 'owner_token', 'fencing_token', 'LeigodOnlyPlatformFamilyGate::assertSupported'] as $snippet) {
    if (!str_contains($adminSource, $snippet)) {
        fail('admin Task 40 guard slice missing required guard: ' . $snippet);
    }
}

echo "ok\n";
