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
if (!str_contains($clientInstaller, 'if [[ ! -f "$0" ]]; then')) {
    fail('install-client-agent missing piped one-click support');
}
// The installer proxy (--proxy / proxy.env) is retired: the panel is reached
// directly through its CDNs, so downloads never use a proxy and the agent unit
// carries no proxy EnvironmentFile.
foreach ([
    'echo "--proxy is no longer supported; the agent reaches the panel directly"',
    'LEGACY_PROXY_ENV_FILE="$CONFIG_DIR/proxy.env"',
    'rm -f "$LEGACY_PROXY_ENV_FILE"',
    'mark_install_changed "proxy.env removed"',
    'cmd = ["curl", "--noproxy", "*", "-fsSL", url]',
] as $snippet) {
    if (!str_contains($clientInstaller, $snippet)) {
        fail('install-client-agent missing retired-proxy handling: ' . $snippet);
    }
}
foreach ([
    'EnvironmentFile=',
    'curl -fsSL --proxy',
    'PROXY_MODE',
    'install_proxy_env',
    'export HTTP_PROXY',
    'export HTTPS_PROXY',
] as $snippet) {
    if (str_contains($clientInstaller, $snippet)) {
        fail('install-client-agent still carries the retired installer proxy: ' . $snippet);
    }
}
$clientUpdateHelper = file_get_contents($root . '/install/client-agent-update.sh');
if (!is_string($clientUpdateHelper)) fail('failed to read client-agent-update');
if (str_contains($clientUpdateHelper, 'load_agent_proxy_env') || str_contains($clientUpdateHelper, 'done < "$proxy_file"')) {
    fail('client-agent-update must not load the retired proxy.env');
}
foreach ([
    'unset HTTP_PROXY HTTPS_PROXY http_proxy https_proxy ALL_PROXY all_proxy',
    'cmd = ["curl", "--noproxy", "*", "--connect-timeout", "15"',
] as $snippet) {
    if (!str_contains($clientUpdateHelper, $snippet)) {
        fail('client-agent-update missing direct panel download: ' . $snippet);
    }
}
foreach ([
    'INSTALLER_SHA256="$(_vers_sha256 client_agent_installer)"',
    'missing client_agent_installer sha256 in versions manifest',
] as $snippet) {
    if (!str_contains($clientInstaller, $snippet)) {
        fail('install-client-agent missing piped installer hash fallback: ' . $snippet);
    }
}

$clientUninstaller = file_get_contents($root . '/install/uninstall-client-agent.sh');
if (!is_string($clientUninstaller)) fail('failed to read uninstall-client-agent');
foreach ([
    'STATE_DIR="/var/lib/leikwan-client-agent"',
    'rm -rf "$CONFIG_DIR" "$STATE_DIR"',
] as $snippet) {
    if (!str_contains($clientUninstaller, $snippet)) {
        fail('uninstall-client-agent missing clean purge support: ' . $snippet);
    }
}

echo "ok\n";
