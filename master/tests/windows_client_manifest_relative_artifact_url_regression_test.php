<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$repoRoot = dirname(__DIR__, 2);
$artifactSource = file_get_contents($repoRoot . '/master/src/Controllers/ArtifactController.php');

if (!is_string($artifactSource) || $artifactSource === '') {
    fail('failed to read ArtifactController source');
}

$requiredSnippets = [
    "public function clientManifest(Request \$request, array \$params): Response",
    "\$manifest = \$this->manifestFor(",
    "array_merge(['windows_client_app'], \$macKeys)",
    'private function normalizeClientAppArtifactUrls(array $manifest, array $keys): array',
    '$this->absoluteArtifactUrl($artifactUrl)',
    "\$pairs[] = 'v=' . rawurlencode(\$version);",
    "\$pairs[] = 'sha256=' . rawurlencode(\$sha256);",
];

foreach ($requiredSnippets as $snippet) {
    if (!str_contains($artifactSource, $snippet)) {
        fail('expected ArtifactController to contain snippet: ' . $snippet);
    }
}

echo "ok\n";
