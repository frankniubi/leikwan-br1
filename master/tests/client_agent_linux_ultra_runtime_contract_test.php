<?php
declare(strict_types=1);

function failLinuxUltraRuntime(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
if ($source === false) {
    failLinuxUltraRuntime('failed to read ClientAgentController');
}

foreach ([
    "\$ultraClientRuntime = \$this->artifactMetadata('ultra_client_runtime');",
    "'ultra_client_runtime' => \$ultraClientRuntime",
    "'desired_ultra_runtime_version' => \$desiredUltraRuntimeVersion,",
    "'desired_ultra_runtime_sha256' => \$desiredUltraRuntimeSha256,",
    "\$desiredUltraRuntimeSha256 = (string) (\$ultraClientRuntime['binary_sha256'] ?? \$ultraClientRuntime['sha256'] ?? '')",
] as $snippet) {
    if (!str_contains($source, $snippet)) {
        failLinuxUltraRuntime('missing Linux Ultra runtime contract: ' . $snippet);
    }
}

if (!str_contains($source, "'ultra_client_runtime' => \$ultraClientRuntime,\n            'leikwan_bond_linux_amd64'")) {
    failLinuxUltraRuntime('config_revision must include Ultra runtime metadata');
}

foreach ([
    "'desired_ultra_runtime_version' => \$desiredUltraRuntimeVersion,",
    "'desired_ultra_runtime_sha256' => \$desiredUltraRuntimeSha256,",
] as $snapshotField) {
    if (substr_count($source, $snapshotField) < 2) {
        failLinuxUltraRuntime('desired Ultra runtime fields must be returned by both snapshot and pull response');
    }
}

echo "ok\n";
