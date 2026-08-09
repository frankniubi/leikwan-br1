<?php
declare(strict_types=1);

function screenshotAssetFail(string $message): never
{
    fwrite(STDERR, 'knowledgebase screenshots: ' . $message . PHP_EOL);
    exit(1);
}

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    screenshotAssetFail('could not resolve master root');
}

$relativeAssets = [
    'public/assets/knowledgebase/screenshots/tenant/01-overview.png',
    'public/assets/knowledgebase/screenshots/tenant/02-forward-form.png',
    'public/assets/knowledgebase/screenshots/tenant/03-client-install.png',
    'public/assets/knowledgebase/screenshots/tenant/04-forward-result.png',
    'public/assets/knowledgebase/screenshots/tenant/05-diagnostics.png',
    'public/assets/knowledgebase/screenshots/tenant/06-target-endpoint.png',
];

foreach ($relativeAssets as $relative) {
    if (!str_starts_with($relative, 'public/assets/knowledgebase/screenshots/tenant/')) {
        screenshotAssetFail('asset escaped the tenant screenshot directory: ' . $relative);
    }

    $path = $root . '/' . $relative;
    if (!is_file($path) || filesize($path) <= 0) {
        screenshotAssetFail('missing or empty asset: ' . $relative);
    }

    $info = @getimagesize($path);
    if (!is_array($info) || ($info['mime'] ?? '') !== 'image/png') {
        screenshotAssetFail('asset is not a PNG: ' . $relative);
    }

    $width = (int) ($info[0] ?? 0);
    $height = (int) ($info[1] ?? 0);
    if ($width < 640 || $height < 480 || $width > 2400 || $height > 1800) {
        screenshotAssetFail(sprintf('asset dimensions are outside the stable range: %s (%dx%d)', $relative, $width, $height));
    }
}

echo 'knowledgebase_screenshot_assets_test: OK' . PHP_EOL;
