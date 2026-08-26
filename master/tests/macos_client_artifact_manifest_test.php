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

use Leikwan\Master\Config;
use Leikwan\Master\Controllers\ArtifactController;
use Leikwan\Master\Http\Request;

function failMacArtifact(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function removeMacArtifactFixture(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($path);
}

$root = sys_get_temp_dir() . '/leikwan-macos-artifacts-' . bin2hex(random_bytes(8));
$artifactDirectory = $root . '/artifacts/macos';
$envPath = $root . '/test.env';
mkdir($artifactDirectory, 0700, true);
file_put_contents($envPath, "APP_URL=https://panel.example.test\nARTIFACT_BASE_URL=https://downloads.example.test\nDB_DRIVER=sqlite\nDB_NAME=:memory:\n");

$entries = [
    'macos_client_app_arm64' => ['filename' => 'leikwan-bridge-arm64.dmg', 'architecture' => 'arm64', 'format' => 'dmg'],
    'macos_client_app_x64' => ['filename' => 'leikwan-bridge-x64.dmg', 'architecture' => 'x86_64', 'format' => 'dmg'],
    'macos_client_update_arm64' => ['filename' => 'leikwan-bridge-update-arm64.zip', 'architecture' => 'arm64', 'format' => 'zip'],
    'macos_client_update_x64' => ['filename' => 'leikwan-bridge-update-x64.zip', 'architecture' => 'x86_64', 'format' => 'zip'],
];
$versions = [];
foreach ($entries as $key => $entry) {
    $bytes = 'fixture-' . $key;
    file_put_contents($artifactDirectory . '/' . $entry['filename'], $bytes);
    $versions[$key] = [
        'version' => '2.0.0',
        'path' => 'artifacts/macos/' . $entry['filename'],
        'artifact_url' => '/artifacts/macos/' . $entry['filename'],
        'sha256' => str_repeat('0', 64),
        'size_bytes' => 1,
        'architecture' => $entry['architecture'],
        'format' => $entry['format'],
    ];
}
$versions['macos_client_update_arm64']['artifact_url'] .= '?channel=stable&v=old&sha256=old';

try {
    $controller = new ArtifactController(new Config($envPath), $versions, $root);
    $request = new Request('GET', '/artifacts/client-manifest.json', [], [], [], '');
    $response = $controller->clientManifest($request, []);
    if ($response->status() !== 200) {
        failMacArtifact('client manifest did not return 200');
    }
    $payload = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
    foreach ($entries as $key => $entry) {
        $manifest = $payload['manifest'][$key] ?? null;
        if (!is_array($manifest)) {
            failMacArtifact('missing manifest entry ' . $key);
        }
        $path = $artifactDirectory . '/' . $entry['filename'];
        if (($manifest['sha256'] ?? '') !== hash_file('sha256', $path)
            || (int) ($manifest['size_bytes'] ?? 0) !== filesize($path)) {
            failMacArtifact('manifest bytes do not match file for ' . $key);
        }
        if (($manifest['architecture'] ?? '') !== $entry['architecture']
            || ($manifest['format'] ?? '') !== $entry['format']) {
            failMacArtifact('manifest architecture/format mismatch for ' . $key);
        }
        $url = (string) ($manifest['artifact_url'] ?? '');
        if (!str_starts_with($url, 'https://downloads.example.test/artifacts/macos/')) {
            failMacArtifact('manifest URL is not absolute for ' . $key);
        }
        if (!str_contains($url, 'v=2.0.0') || !str_contains($url, 'sha256=' . hash_file('sha256', $path))) {
            failMacArtifact('manifest URL is not cache-busted for ' . $key);
        }
        if ($key === 'macos_client_update_arm64') {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            if (($query['channel'] ?? '') !== 'stable'
                || ($query['v'] ?? '') !== '2.0.0'
                || ($query['sha256'] ?? '') !== hash_file('sha256', $path)) {
                failMacArtifact('cache-bust replacement did not preserve unrelated query parameters');
            }
        }
    }

    $routeChecks = [
        ['macosClientArm64Dmg', 'application/x-apple-diskimage', 'macos_client_app_arm64'],
        ['macosClientX64Dmg', 'application/x-apple-diskimage', 'macos_client_app_x64'],
        ['macosClientArm64Update', 'application/zip', 'macos_client_update_arm64'],
        ['macosClientX64Update', 'application/zip', 'macos_client_update_x64'],
    ];
    foreach ($routeChecks as [$method, $contentType, $key]) {
        if (!method_exists($controller, $method)) {
            failMacArtifact('missing artifact method ' . $method);
        }
        $routeResponse = $controller->{$method}($request, []);
        if ($routeResponse->status() !== 200
            || ($routeResponse->headers()['Content-Type'] ?? '') !== $contentType
            || $routeResponse->body() !== 'fixture-' . $key) {
            failMacArtifact('artifact route mismatch for ' . $key);
        }
    }

    unlink($artifactDirectory . '/leikwan-bridge-update-x64.zip');
    $missingController = new ArtifactController(new Config($envPath), $versions, $root);
    $missingPayload = json_decode($missingController->clientManifest($request, [])->body(), true, 512, JSON_THROW_ON_ERROR);
    if (isset($missingPayload['manifest']['macos_client_update_x64'])) {
        failMacArtifact('missing artifact was still advertised');
    }
    if ($missingController->macosClientX64Update($request, [])->status() !== 404) {
        failMacArtifact('missing artifact route did not return 404');
    }
} finally {
    removeMacArtifactFixture($root);
}

echo "ok\n";
