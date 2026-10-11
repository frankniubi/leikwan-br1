<?php
declare(strict_types=1);

function failMacInstallSurface(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$repoRoot = dirname(__DIR__, 2);
$artifact = (string) file_get_contents($repoRoot . '/master/src/Controllers/ArtifactController.php');
$bootstrap = (string) file_get_contents($repoRoot . '/master/src/Bootstrap.php');
$base = (string) file_get_contents($repoRoot . '/master/src/Controllers/BaseController.php');
$tenant = (string) file_get_contents($repoRoot . '/master/src/Controllers/TenantController.php');

foreach ([
    "'/artifacts/macos/leikwan-bridge-arm64.dmg'",
    "'/artifacts/macos/leikwan-bridge-x64.dmg'",
    "'/artifacts/macos/leikwan-bridge-update-arm64.zip'",
    "'/artifacts/macos/leikwan-bridge-update-x64.zip'",
] as $route) {
    if (!str_contains($bootstrap, $route)) {
        failMacInstallSurface('missing Bootstrap route ' . $route);
    }
}

foreach ([
    'public function macosClientArm64Dmg',
    'public function macosClientX64Dmg',
    'public function macosClientArm64Update',
    'public function macosClientX64Update',
    'normalizeClientAppArtifactUrls',
] as $snippet) {
    if (!str_contains($artifact, $snippet)) {
        failMacInstallSurface('missing ArtifactController surface ' . $snippet);
    }
}

foreach (['macosClientArm64AppUrl', 'macosClientX64AppUrl'] as $snippet) {
    if (!str_contains($base, $snippet)) {
        failMacInstallSurface('missing BaseController URL helper ' . $snippet);
    }
}

$clientsStart = strpos($tenant, 'public function clientsPage(Request $request, array $params): Response');
$clientsEnd = strpos($tenant, 'public function resetDefaultClientToken(Request $request, array $params): Response');
if (!is_int($clientsStart) || !is_int($clientsEnd) || $clientsEnd <= $clientsStart) {
    failMacInstallSurface('could not isolate tenant client surface');
}
$clients = substr($tenant, $clientsStart, $clientsEnd - $clientsStart);
// The tenant page delegates the GUI download card to the shared helper. Intel
// (x64) downloads stay routable but are deliberately not offered until that
// build is verified (pinned by macos_client_download_page_regression_test).
if (!str_contains($clients, '$this->renderMacClientInstallSection()') || !str_contains($clients, 'macOS')) {
    failMacInstallSurface('tenant client surface does not render the macOS install section');
}
$helperStart = strpos($base, 'protected function renderMacClientInstallSection(): string');
$helperEnd = $helperStart === false ? false : strpos($base, 'protected function ', $helperStart + 20);
if ($helperStart === false || $helperEnd === false) {
    failMacInstallSurface('could not isolate renderMacClientInstallSection');
}
$helper = substr($base, $helperStart, $helperEnd - $helperStart);
foreach (['macOS', 'Apple Silicon', 'Unsigned', 'macosClientArm64AppUrl()'] as $label) {
    if (!str_contains($helper, $label)) {
        failMacInstallSurface('missing macOS install label ' . $label);
    }
}
if (str_contains($helper, 'Intel') || str_contains($helper, 'macosClientX64AppUrl()')) {
    failMacInstallSurface('tenant macOS install section offers the unverified Intel build');
}
foreach (['agent_token=', 'client_id=', 'token='] as $forbidden) {
    if (str_contains(strtolower($clients), $forbidden)) {
        failMacInstallSurface('tenant macOS download surface embeds credential query material');
    }
}

echo "ok\n";
