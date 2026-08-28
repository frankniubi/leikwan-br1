<?php
declare(strict_types=1);

function failMacosUltraPolicy(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
if ($source === false) {
    failMacosUltraPolicy('failed to read ClientAgentController');
}

if (!str_contains($source, "str_starts_with(\$platform, 'macos')")) {
    failMacosUltraPolicy('macOS pull requests must select the Ultra v2 policy path');
}

if (!str_contains($source, "str_starts_with(\$platform, 'windows')")
    || !str_contains($source, "str_starts_with(\$platform, 'linux')")
    || !str_contains($source, "str_starts_with(\$platform, 'macos')")) {
    failMacosUltraPolicy('desktop platform gates must all select Ultra v2');
}

echo "ok\n";
