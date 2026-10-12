<?php
declare(strict_types=1);

function failMacDownloadPage(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

$base = (string) file_get_contents(__DIR__ . '/../src/Controllers/BaseController.php');
$tenant = (string) file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
if ($base === '' || $tenant === '') {
    failMacDownloadPage('failed to read controller sources');
}
$manifest = json_decode((string) file_get_contents(__DIR__ . '/../../artifacts/versions.json'), true);
$expectedVersion = is_array($manifest)
    ? trim((string) ($manifest['macos_client_app_arm64']['version'] ?? ''))
    : '';
if ($expectedVersion === '') {
    failMacDownloadPage('macOS artifact version is missing from the manifest');
}

foreach ([
    'macosClientArm64AppUrl(): string',
    'macosClientArtifactsAvailable(): bool',
    'macosClientReleaseVersion(): string',
    'macosClientReleaseNotice(): string',
    'renderMacClientDownloadPanels(): string',
    'renderMacClientInstallSection(): string',
    'Release announcement / 发布声明',
    'macOS 客户端',
] as $required) {
    if (!str_contains($base, $required)) {
        failMacDownloadPage('BaseController missing ' . $required);
    }
}

// Intel macOS is retired: no Intel download helper exists any more.
if (str_contains($base, 'macosClientX64AppUrl')) {
    failMacDownloadPage('BaseController still offers the retired Intel macOS download');
}

$start = strpos($tenant, 'public function clientsPage(Request $request, array $params): Response');
$end = strpos($tenant, 'public function resetDefaultClientToken(Request $request, array $params): Response', $start ?: 0);
if (!is_int($start) || !is_int($end) || $end <= $start) {
    failMacDownloadPage('failed to isolate tenant clientsPage');
}
$clientsPage = substr($tenant, $start, $end - $start);
// The GUI section from the shared helper is merged with the macOS no-GUI
// (launchd) block into one card and rendered as $macosSection.
foreach (['$macosGuiSectionRaw = $this->renderMacClientInstallSection();', '. $macosSection'] as $required) {
    if (!str_contains($clientsPage, $required)) {
        failMacDownloadPage('tenant download page missing ' . $required);
    }
}
foreach (['agent_token=', 'client_id=', 'token=', 'forward_id='] as $forbidden) {
    if (str_contains(strtolower($clientsPage), $forbidden)) {
        failMacDownloadPage('tenant Mac download links expose sensitive query material: ' . $forbidden);
    }
}

if (!str_contains($base, '$this->renderMacClientDownloadPanels()')) {
    failMacDownloadPage('admin App Download surface does not render macOS panels');
}

$envPath = tempnam(sys_get_temp_dir(), 'leikwan-mac-download-');
if ($envPath === false) {
    failMacDownloadPage('failed to create test config');
}
file_put_contents($envPath, "APP_URL=https://panel.example.test\nARTIFACT_BASE_URL=https://downloads.example.test\nDB_DRIVER=sqlite\nDB_NAME=:memory:\n");

try {
    $config = new \Leikwan\Master\Config($envPath);
    $db = new \Leikwan\Master\Database\Database($config);
    $controller = new class($config, $db) extends \Leikwan\Master\Controllers\BaseController {
        public function installSection(): string
        {
            return $this->renderMacClientInstallSection();
        }

        public function downloadPanels(): string
        {
            return $this->renderMacClientDownloadPanels();
        }
    };
    $installSection = $controller->installSection();
    $downloadPanels = $controller->downloadPanels();
    foreach (['Release announcement / 发布声明', 'macOS 客户端 ' . $expectedVersion, 'Apple Silicon'] as $required) {
        if (!str_contains($installSection, $required) || !str_contains($downloadPanels, $required)) {
            failMacDownloadPage('rendered Mac download surface missing ' . $required);
        }
    }
    if (str_contains($installSection . $downloadPanels, 'Intel')
        || str_contains($installSection . $downloadPanels, 'macos_client_app_x64')) {
        failMacDownloadPage('unverified Intel macOS artifact is still displayed');
    }
    foreach (['client_id=', 'agent_token=', 'forward_id='] as $forbidden) {
        if (str_contains(strtolower($installSection . $downloadPanels), $forbidden)) {
            failMacDownloadPage('rendered Mac download links expose sensitive query material: ' . $forbidden);
        }
    }
} finally {
    unlink($envPath);
}

echo "ok\n";
