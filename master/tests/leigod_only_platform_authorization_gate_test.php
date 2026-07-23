<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$repoRoot = dirname(__DIR__, 2);
$platformPath = $repoRoot . '/master/src/Support/LeigodOnlyPlatformFamilyGate.php';
$authorizationPath = $repoRoot . '/master/src/Support/LeigodOnlyAuthorizationGate.php';
foreach ([$platformPath, $authorizationPath] as $path) {
    if (!is_file($path)) {
        fail('expected support gate file to exist: ' . $path);
    }
    require_once $path;
}

$platformGate = \Leikwan\Master\Support\LeigodOnlyPlatformFamilyGate::class;
$authorizationGate = \Leikwan\Master\Support\LeigodOnlyAuthorizationGate::class;

foreach (['linux-bond', 'windows-bond'] as $family) {
    if (!$platformGate::isSupported($family)) {
        fail('expected platform family to be supported: ' . $family);
    }
    $platformGate::assertSupported($family, 'supported_platform_case');
}

$probeCount = 0;
$runPlatformProbe = static function (string $family) use ($platformGate, &$probeCount): void {
    $platformGate::assertSupported($family, 'before_artifact_probe');
    $probeCount++;
};
try {
    $runPlatformProbe('android-bond');
} catch (\InvalidArgumentException $exception) {
    $message = $exception->getMessage();
    if (!str_contains($message, 'unsupported_platform_family:android-bond') || !str_contains($message, 'before_artifact_probe')) {
        fail('android-bond rejection message was not deterministic: ' . $message);
    }
}
if ($probeCount !== 0) {
    fail('android-bond must fail before artifact probes or other side effects');
}

foreach (['freebsd-bond', 'linux', ''] as $family) {
    try {
        $platformGate::assertSupported($family, 'unsupported_platform_case');
    } catch (\InvalidArgumentException $exception) {
        if (!str_contains($exception->getMessage(), 'unsupported_platform_family:' . $family)) {
            fail('unsupported platform message missing family: ' . $exception->getMessage());
        }
        continue;
    }
    fail('expected unsupported platform to throw: ' . $family);
}

foreach ([
    ['action' => 'planning'],
    ['action' => 'dry_run'],
    ['outward_action' => 'status'],
    ['actions' => ['status', 'dry_run']],
    ['metadata' => ['planned_action' => 'status']],
    ['action' => 'artifact_probe'],
    ['operation' => 'preflight_check'],
    ['command' => 'prepare_plan'],
] as $context) {
    $authorizationGate::assertNoOutwardAction($context);
}

foreach ([
    ['action' => 'deploy'],
    ['outward_action' => 'artifact_publication'],
    ['action' => 'static_mirror_synchronization'],
    ['action' => 'cleanup_execution'],
    ['action' => 'remote_operation'],
    ['action' => 'commit'],
    ['action' => 'push'],
    ['action' => 'pr'],
    ['action' => 'release'],
    ['action' => 'production-deploy'],
    ['action' => 'artifact publication'],
    ['action' => 'static mirror synchronization'],
    ['action' => 'remote operation'],
    ['action' => 'pull request'],
    ['outward_action' => 'status', 'action' => 'deploy'],
    ['outward_action' => 'status', 'next_action' => 'deploy'],
    ['actions' => ['status', 'push']],
    ['metadata' => ['requested_action' => 'artifact publication']],
    ['deploy' => true],
    ['publish_artifacts' => true],
    ['plan' => ['steps' => [['planned_action' => 'static mirror synchronization']]]],
    ['operation_flags' => ['cleanup' => true]],
    ['operation' => 'remote operation'],
    ['requested_operation' => ['type' => 'status', 'operation_type' => 'cleanup-execution']],
    ['pending_action' => ['command' => 'release']],
] as $context) {
    try {
        $authorizationGate::assertNoOutwardAction($context);
    } catch (\InvalidArgumentException $exception) {
        if (!str_contains($exception->getMessage(), 'leigod_only_outward_action_forbidden')) {
            fail('outward action rejection message missing guard marker: ' . $exception->getMessage());
        }
        continue;
    }
    fail('expected outward action to be forbidden without a separate authorization boundary');
}

foreach (['planning', 'dry_run', 'status'] as $action) {
    $authorizationGate::assertSeparateAuthorization($action, []);
}

foreach (['deploy', 'artifact_publication', 'static_mirror_synchronization', 'cleanup_execution', 'remote_operation', 'commit', 'push', 'pr', 'release', 'artifact publication', 'static mirror synchronization', 'remote operation', 'pull request'] as $action) {
    try {
        $authorizationGate::assertSeparateAuthorization($action, []);
    } catch (\InvalidArgumentException $exception) {
        if (!str_contains($exception->getMessage(), 'missing_separate_authorization:' . $action)) {
            fail('missing authorization message was not deterministic: ' . $exception->getMessage());
        }
        continue;
    }
    fail('expected missing separate authorization for ' . $action);
}

$authorizationGate::assertSeparateAuthorization('deploy', ['external_authorization_ref' => 'AUTH-20260719-1']);
$authorizationGate::assertSeparateAuthorization('push', ['reference' => 'AUTH-20260719-2']);

try {
    $authorizationGate::assertSeparateAuthorization('cleanup_execution', ['external_authorization_ref' => '']);
} catch (\InvalidArgumentException $exception) {
    if (str_contains($exception->getMessage(), 'missing_separate_authorization:cleanup_execution')) {
        echo "ok\n";
        return;
    }
    fail('empty authorization reference message was not deterministic: ' . $exception->getMessage());
}
fail('expected empty external authorization reference to be rejected');
