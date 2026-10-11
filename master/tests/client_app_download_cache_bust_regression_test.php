<?php
declare(strict_types=1);

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

function failDownloadCacheBust(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$manifest = json_decode((string) file_get_contents(__DIR__ . '/../../artifacts/versions.json'), true);
if (!is_array($manifest)) {
    failDownloadCacheBust('failed to parse artifact manifest');
}

$envPath = tempnam(sys_get_temp_dir(), 'leikwan-download-cache-');
if ($envPath === false) {
    failDownloadCacheBust('failed to create temporary config');
}
file_put_contents($envPath, "APP_URL=https://panel.example.test\nARTIFACT_BASE_URL=https://downloads.example.test\nDB_DRIVER=sqlite\nDB_NAME=:memory:\n");

try {
    $config = new \Leikwan\Master\Config($envPath);
    $db = new \Leikwan\Master\Database\Database($config);
    $controller = new class($config, $db) extends \Leikwan\Master\Controllers\BaseController {
        /** @return array<string, string> */
        public function links(): array
        {
            return [
                'windows' => $this->windowsClientAppUrl(),
                'android' => $this->androidClientApkUrl(),
                'mac_arm64' => $this->macosClientArm64AppUrl(),
                'mac_x64' => $this->macosClientX64AppUrl(),
            ];
        }

        public function overview(): string
        {
            return $this->renderClientAppDownloadOverview();
        }
    };

    $keys = [
        'windows' => 'windows_client_app',
        'android' => 'android_client_apk',
        'mac_arm64' => 'macos_client_app_arm64',
        'mac_x64' => 'macos_client_app_x64',
    ];
    foreach ($controller->links() as $name => $url) {
        $entry = $manifest[$keys[$name]] ?? null;
        if (!is_array($entry)) {
            failDownloadCacheBust('missing manifest entry for ' . $name);
        }
        $expectedVersion = rawurlencode((string) ($entry['version'] ?? ''));
        $expectedHash = rawurlencode((string) ($entry['sha256'] ?? ''));
        if (!str_starts_with($url, 'https://downloads.example.test/')) {
            failDownloadCacheBust('non-canonical download base for ' . $name . ': ' . $url);
        }
        if ($expectedVersion === '' || $expectedHash === ''
            || !str_contains($url, 'v=' . $expectedVersion)
            || !str_contains($url, 'sha256=' . $expectedHash)) {
            failDownloadCacheBust('download link is not cache-busted for ' . $name . ': ' . $url);
        }
    }
    $overview = $controller->overview();
    foreach ([
        'Windows App ' . ($manifest['windows_client_app']['version'] ?? ''),
        'Android App ' . ($manifest['android_client_apk']['version'] ?? ''),
        'macOS App - Apple Silicon ' . ($manifest['macos_client_app_arm64']['version'] ?? ''),
    ] as $label) {
        if (!str_contains($overview, $label)) {
            failDownloadCacheBust('download surface is missing version label: ' . $label);
        }
    }
    if (str_contains($overview, 'macOS App - Intel') || str_contains($overview, 'Download Intel')) {
        failDownloadCacheBust('unverified Intel macOS artifact is still displayed');
    }
} finally {
    unlink($envPath);
}

echo "ok\n";
