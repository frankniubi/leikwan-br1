<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$admin = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
if (!is_string($admin) || $admin === '') {
    fail('failed to read AdminController source');
}

foreach ([
    '<label>Max Client Profiles</label>',
    'name="max_client_profiles"',
    'hard creation cap for independent client profiles',
    "'max_client_profiles' => \$request->input('max_client_profiles', '1')",
] as $needle) {
    if (!str_contains($admin, $needle)) {
        fail('missing admin client-profile-limit control snippet: ' . $needle);
    }
}

echo "ok\n";