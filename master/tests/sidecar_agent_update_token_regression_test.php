<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$repoRoot = dirname(__DIR__, 2);
$source = file_get_contents($repoRoot . '/master/src/Controllers/SidecarAgentController.php');

if (!is_string($source) || $source === '') {
    fail('failed to read SidecarAgentController source');
}

if (!str_contains($source, '$updateRequestToken = trim((string) ($point[\'agent_update_request_token\'] ?? \'\'));')) {
    fail('expected SidecarAgentController to read explicit sidecar agent_update_request_token');
}

if (str_contains($source, 'autoUpdateTokenForSha256((string) ($point[\'agent_version\'] ?? \'\')')) {
    fail('sidecar controller should not derive bond sha update tokens from point.agent_version');
}

if (!str_contains($source, "'desired_bond_sha256' => \$desiredBondSha256")) {
    fail('expected SidecarAgentController to still expose desired_bond_sha256 in management payload');
}

echo "ok\n";
