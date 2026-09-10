<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Controllers/SidecarAgentController.php');
if (!is_string($source) || $source === '') {
    fail('failed to read SidecarAgentController source');
}

if (!str_contains($source, 'f.bond_remote_host')) {
    fail('expected sidecar cf_runtime query to include f.bond_remote_host');
}

if (!str_contains($source, 'f.bond_remote_port')) {
    fail('expected sidecar cf_runtime query to include f.bond_remote_port');
}

if (!str_contains($source, "(string) (\$row['bond_remote_host'] ?? '')")) {
    fail('expected sidecar cf_runtime route target to prefer bond_remote_host');
}

if (!str_contains($source, "(int) (\$row['bond_remote_port'] ?? 0)")) {
    fail('expected sidecar cf_runtime route target to prefer bond_remote_port');
}

if (str_contains($source, "\$target = (string) (\$row['target_host'] ?? '') . ':' . (int) (\$row['target_port'] ?? 0);")) {
    fail('sidecar cf_runtime should not publish target_host:target_port directly');
}

echo "ok\n";
