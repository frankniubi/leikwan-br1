<?php
declare(strict_types=1);

function failLinuxUltraPolicy(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
if ($source === false) {
    failLinuxUltraPolicy('failed to read ClientAgentController');
}

if (!str_contains($source, "str_starts_with(\$platform, 'linux')")) {
    failLinuxUltraPolicy('Linux pull requests must select the Ultra v2 policy path');
}

if (!str_contains($source, "str_starts_with(\$platform, 'windows')")
    || !str_contains($source, "str_starts_with(\$platform, 'linux')")) {
    failLinuxUltraPolicy('Windows and Linux platform gates must both select Ultra v2');
}

echo "ok\n";
