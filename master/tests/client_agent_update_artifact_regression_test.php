<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$repoRoot = dirname(__DIR__, 2);
$artifactSource = file_get_contents($repoRoot . '/master/src/Controllers/ArtifactController.php');
$bootstrapSource = file_get_contents($repoRoot . '/master/src/Bootstrap.php');

if (!is_string($artifactSource) || $artifactSource === '') {
    fail('failed to read ArtifactController source');
}

if (!is_string($bootstrapSource) || $bootstrapSource === '') {
    fail('failed to read Bootstrap source');
}

$requiredArtifactSnippets = [
    "public function clientAgentUpdate(Request \$request, array \$params): Response",
    "return \$this->serveRepoFile('install/client-agent-update.sh');",
];

foreach ($requiredArtifactSnippets as $snippet) {
    if (!str_contains($artifactSource, $snippet)) {
        fail('expected ArtifactController to contain snippet: ' . $snippet);
    }
}

$requiredBootstrapSnippet = "\$this->router->add('GET', '/artifacts/client-agent-update.sh', [\$artifacts, 'clientAgentUpdate']);";
if (!str_contains($bootstrapSource, $requiredBootstrapSnippet)) {
    fail('expected Bootstrap to register /artifacts/client-agent-update.sh');
}

echo "ok\n";
