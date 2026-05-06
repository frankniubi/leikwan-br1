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
    "\$manifest['windows_client_app']['artifact_url'] = (string) ((\$this->versions()['windows_client_app']['artifact_url'] ?? '') ?: \$manifest['windows_client_app']['artifact_url']);",
];

foreach ($requiredSnippets as $snippet) {
    if (!str_contains($artifactSource, $snippet)) {
        fail('expected ArtifactController to contain snippet: ' . $snippet);
    }
}

echo "ok\n";
