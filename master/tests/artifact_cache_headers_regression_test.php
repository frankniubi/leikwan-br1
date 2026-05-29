<?php
declare(strict_types=1);

$responseSource = file_get_contents(__DIR__ . '/../src/Http/Response.php');
$artifactSource = file_get_contents(__DIR__ . '/../src/Controllers/ArtifactController.php');
$nginxSource = file_get_contents(__DIR__ . '/../../deploy/nginx/master.conf');
$installerSource = file_get_contents(__DIR__ . '/../../install/install-client-agent.sh');
$updateHelperSource = file_get_contents(__DIR__ . '/../../install/client-agent-update.sh');
$clientAgentSource = file_get_contents(__DIR__ . '/../../client-agent/client_agent.py');

if ($responseSource === false || $artifactSource === false || $nginxSource === false || $installerSource === false || $updateHelperSource === false || $clientAgentSource === false) {
    fwrite(STDERR, "failed to read artifact sources\n");
    exit(1);
}

foreach ([
    'response header mutation' => 'public function withHeaders',
    'response status accessor' => 'public function status',
    'response headers accessor' => 'public function headers',
    'conditional etag' => 'If-None-Match',
    'conditional last modified' => 'If-Modified-Since',
    'range support' => 'Content-Range',
    'accept ranges' => 'Accept-Ranges',
    'immutable artifacts' => 'immutable',
    'artifact observability' => 'artifact_response',
    'nginx artifact alias' => 'location /artifacts/files/',
    'installer sha short-circuit' => 'download_skip',
    'update download diagnostics' => 'last_download_bytes',
    'heartbeat update state' => '"agent_update_state": self.load_update_state()',
] as $label => $snippet) {
    if (!str_contains($responseSource . $artifactSource . $nginxSource . $installerSource . $updateHelperSource . $clientAgentSource, $snippet)) {
        fwrite(STDERR, "missing {$label}: {$snippet}\n");
        exit(1);
    }
}

if (!str_contains($artifactSource, 'private function serveRepoFile(string $relativePath, string $contentType = \'text/plain; charset=utf-8\', ?Request $request = null)')) {
    fwrite(STDERR, "serveRepoFile must accept Request for conditional/range handling\n");
    exit(1);
}

echo "ok\n";
