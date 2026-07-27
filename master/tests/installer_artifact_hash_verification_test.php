<?php
declare(strict_types=1);

function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
$root = dirname(__DIR__, 2);
$sources = [
    'install-slave' => file_get_contents($root . '/install/install-slave.sh'),
    'install-client-agent' => file_get_contents($root . '/install/install-client-agent.sh'),
    'install-bond-sidecar' => file_get_contents($root . '/install/install-bond-sidecar.sh'),
];
foreach ($sources as $name => $source) {
    if ($source === false) fail('failed to read ' . $name);
    foreach (['download_atomic', 'curl -fsSL', 'chmod 0755'] as $snippet) {
        if (!str_contains($source, $snippet)) fail($name . ' missing download verification support snippet: ' . $snippet);
    }
}
$slave = $sources['install-slave'];
foreach (['/artifacts/versions.json', 'check_binary_current', '_vers_sha256', 'gost_linux_amd64', 'leigod_linux_amd64', 'lguot_linux_amd64'] as $snippet) {
    if (!str_contains($slave, $snippet)) fail('install-slave missing artifact hash gate: ' . $snippet);
}
if (!str_contains($slave, 'skipping download')) fail('installer must short-circuit matching sha downloads');

$clientInstaller = $sources['install-client-agent'];
foreach ([
    'proxy_url="${HTTPS_PROXY:-${https_proxy:-${HTTP_PROXY:-${http_proxy:-}}}}"',
    'curl -fsSL --proxy "$proxy_url" "$url" -o "$path"',
    'if [[ ! -f "$0" ]]; then',
] as $snippet) {
    if (!str_contains($clientInstaller, $snippet)) {
        fail('install-client-agent missing proxied one-click support: ' . $snippet);
    }
}

echo "ok\n";
