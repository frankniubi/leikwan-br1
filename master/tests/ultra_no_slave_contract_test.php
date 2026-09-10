<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
$mode = file_get_contents(__DIR__ . '/../src/Support/UltraMode.php');
if (!is_string($source) || !is_string($mode)) {
    fwrite(STDERR, "failed to read Ultra sources\n");
    exit(1);
}

$required = [
    "public const CONTROL_PLANE_GROUP = 'ultra-control-plane';" => $mode,
    '$ultraRuntime = UltraMode::isBackendMode($backendMode);' => $source,
    '$endpointCount = $ultraRuntime ? 0 :' => $source,
    'if ($nodes === [] && !$ultraRuntime)' => $source,
    'if ($ultraRuntime) {' => $source,
    '$desiredNodes = [];' => $source,
    "if (\$config['endpoints'] === [] && !UltraMode::isBackendMode(\$config['backend_mode'] ?? ''))" => $source,
    "\$config['candidate_pool'] = UltraMode::isBackendMode(\$config['backend_mode'] ?? '')" => $source,
];

foreach ($required as $needle => $haystack) {
    if (!str_contains($haystack, $needle)) {
        fwrite(STDERR, 'missing Ultra no-slave contract: ' . $needle . PHP_EOL);
        exit(1);
    }
}

echo "ok\n";
