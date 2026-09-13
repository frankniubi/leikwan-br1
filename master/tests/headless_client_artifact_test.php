<?php
declare(strict_types=1);

// Contract for the windowless (no-GUI) client surface: the published payloads
// must exist and match the manifest, the installers must be served, and no
// installer may ever carry a credential.

function failHeadless(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$root = dirname(__DIR__, 2);
$versions = json_decode((string) file_get_contents($root . '/artifacts/versions.json'), true);
if (!is_array($versions)) {
    failHeadless('versions.json is not readable');
}

$artifacts = [
    'windows_headless_client' => ['artifacts/windows/leikwan-bridge-headless-win-x64.zip', 'artifact_url'],
    'macos_headless_installer' => ['install/install-macos-headless.sh', 'install_url'],
    'macos_headless_uninstaller' => ['install/uninstall-macos-headless.sh', 'install_url'],
    'windows_headless_installer' => ['install/install-windows-headless.ps1', 'install_url'],
    'windows_headless_uninstaller' => ['install/uninstall-windows-headless.ps1', 'install_url'],
];
foreach ($artifacts as $key => [$relative, $urlField]) {
    $entry = $versions[$key] ?? null;
    if (!is_array($entry)) {
        failHeadless('manifest entry missing: ' . $key);
    }
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        failHeadless('published file missing: ' . $relative);
    }
    if (($entry['path'] ?? '') !== $relative) {
        failHeadless('manifest path mismatch: ' . $key);
    }
    if (($entry['sha256'] ?? '') !== hash_file('sha256', $path)) {
        failHeadless('manifest hash mismatch: ' . $key);
    }
    if ((int) ($entry['size_bytes'] ?? 0) !== filesize($path)) {
        failHeadless('manifest size mismatch: ' . $key);
    }
    if (!is_string($entry[$urlField] ?? null) || !str_starts_with((string) $entry[$urlField], '/artifacts/')) {
        failHeadless('manifest url missing: ' . $key);
    }
    if (($entry['version'] ?? '') === '') {
        failHeadless('manifest version missing: ' . $key);
    }
}

if (($versions['windows_headless_client']['format'] ?? '') !== 'zip') {
    failHeadless('windows headless payload must be a zip');
}

$bootstrap = (string) file_get_contents($root . '/master/src/Bootstrap.php');
foreach ([
    "'/artifacts/windows/leikwan-bridge-headless-win-x64.zip'",
    "'/artifacts/install-macos-headless.sh'",
    "'/artifacts/uninstall-macos-headless.sh'",
    "'/artifacts/install-windows-headless.ps1'",
    "'/artifacts/uninstall-windows-headless.ps1'",
] as $route) {
    if (!str_contains($bootstrap, $route)) {
        failHeadless('missing Bootstrap route ' . $route);
    }
}

$controller = (string) file_get_contents($root . '/master/src/Controllers/ArtifactController.php');
foreach ([
    'public function windowsHeadlessClient',
    'public function installMacosHeadless',
    'public function uninstallMacosHeadless',
    'public function installWindowsHeadless',
    'public function uninstallWindowsHeadless',
] as $method) {
    if (!str_contains($controller, $method)) {
        failHeadless('missing ArtifactController surface ' . $method);
    }
}

$macInstaller = (string) file_get_contents($root . '/install/install-macos-headless.sh');
foreach (['--headless', 'com.leikwan.bridge.headless', '/Library/LaunchDaemons', 'chmod 600', 'SuccessfulExit', '--agent-token-file'] as $needle) {
    if (!str_contains($macInstaller, $needle)) {
        failHeadless('macOS headless installer missing: ' . $needle);
    }
}
if (str_contains($macInstaller, 'agent-token=') || str_contains($macInstaller, 'Authorization:')) {
    failHeadless('macOS headless installer embeds credential material');
}

$winInstaller = (string) file_get_contents($root . '/install/install-windows-headless.ps1');
foreach (['--install-service', '--agent-token-file', 'icacls.exe', 'LocalSystem', 'headless-status.json'] as $needle) {
    if (!str_contains($winInstaller, $needle)) {
        failHeadless('Windows headless installer missing: ' . $needle);
    }
}
foreach (['agent-token=', 'PlainText', 'ConvertTo-SecureString'] as $forbidden) {
    if (str_contains($winInstaller, $forbidden)) {
        failHeadless('Windows headless installer leaks credentials: ' . $forbidden);
    }
}

// The service must not carry the token on its command line: "sc qc" is readable
// by any authenticated user.
if (!str_contains($winInstaller, 'Set-Content -LiteralPath $tokenPath')) {
    failHeadless('Windows headless installer must store the token in a file');
}

// ---- knowledgebase guide and download-centre links -------------------------

$topics = require $root . '/master/resources/knowledgebase/topics.php';
$guideKey = 'no-gui-clients';
if (!isset($topics[$guideKey]['articles'])) {
    failHeadless('knowledgebase is missing the ' . $guideKey . ' guide');
}
foreach (['en', 'zh-Hans', 'zh-TW'] as $locale) {
    $article = $topics[$guideKey]['articles'][$locale] ?? null;
    if (!is_array($article) || ($article['title'] ?? '') === '' || ($article['excerpt'] ?? '') === '') {
        failHeadless('knowledgebase guide metadata missing for ' . $locale);
    }
    $bodyPath = $root . '/master/resources/knowledgebase/' . (string) $article['body'];
    if (!is_file($bodyPath) || filesize($bodyPath) <= 0) {
        failHeadless('knowledgebase guide body missing for ' . $locale);
    }
}

$seed = (string) file_get_contents($root . '/master/migrations/20260729_seed_multilingual_knowledgebase.sql');
if (substr_count($seed, "'" . $guideKey . "'") < 4) {
    failHeadless('regenerated knowledgebase seed does not carry the no-GUI guide');
}

$guideHref = '/knowledgebase?thread=' . $guideKey;
$tenantPage = (string) file_get_contents($root . '/master/src/Controllers/TenantController.php');
if (!str_contains($tenantPage, $guideHref)) {
    failHeadless('tenant client page must link the no-GUI guide');
}

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = $root . '/master/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

$envPath = tempnam(sys_get_temp_dir(), 'leikwan-headless-');
if ($envPath === false) {
    failHeadless('failed to create the test config');
}
file_put_contents($envPath, "APP_URL=https://panel.example.test\nARTIFACT_BASE_URL=https://panel.example.test\nDB_DRIVER=sqlite\nDB_NAME=:memory:\n");
try {
    $config = new \Leikwan\Master\Config($envPath);
    $db = new \Leikwan\Master\Database\Database($config);
    $probe = new class($config, $db) extends \Leikwan\Master\Controllers\BaseController {
        /** @param array<string, mixed>|null $client */
        public function headlessPanel(?array $client): string
        {
            return $this->renderHeadlessClientDownloadPanel($client);
        }

        /** @param array<string, mixed>|null $client */
        public function downloadOverview(?array $client): string
        {
            return $this->renderClientAppDownloadOverview($client);
        }
    };

    $anonymousPanel = $probe->headlessPanel(null);
    if (!str_contains($anonymousPanel, $guideHref)) {
        failHeadless('no-GUI download panel must link the guide');
    }
    if (str_contains($anonymousPanel, 'agent-token ')) {
        failHeadless('no-GUI panel must not print a command without a client identity');
    }

    $client = ['id' => 42, 'agent_token' => 'test-agent-token'];
    $clientPanel = $probe->headlessPanel($client);
    foreach ([
        $guideHref,
        '/artifacts/windows/leikwan-bridge-headless-win-x64.zip',
        'install-windows-headless.ps1',
        '-ClientId 42',
        'test-agent-token',
    ] as $required) {
        if (!str_contains($clientPanel, $required)) {
            failHeadless('no-GUI panel with a client is missing ' . $required);
        }
    }

    $overview = $probe->downloadOverview($client);
    foreach (['App Download', $guideHref, 'No-GUI Client'] as $required) {
        if (!str_contains($overview, $required)) {
            failHeadless('app download centre is missing ' . $required);
        }
    }
} finally {
    unlink($envPath);
}

echo "ok\n";
