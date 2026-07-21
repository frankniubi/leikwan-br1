<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    if ($relative === false) {
        return;
    }
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

use Leikwan\Master\Config;
use Leikwan\Master\Controllers\ArtifactController;
use Leikwan\Master\Http\Request;

function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }

$responseSource = file_get_contents(__DIR__ . '/../src/Http/Response.php');
$artifactSource = file_get_contents(__DIR__ . '/../src/Controllers/ArtifactController.php');
$nginxSource = file_get_contents(__DIR__ . '/../../deploy/nginx/master.conf');
$installerSource = file_get_contents(__DIR__ . '/../../install/install-client-agent.sh');
$updateHelperSource = file_get_contents(__DIR__ . '/../../install/client-agent-update.sh');
$clientAgentSource = file_get_contents(__DIR__ . '/../../client-agent/client_agent.py');

if ($responseSource === false || $artifactSource === false || $nginxSource === false || $installerSource === false || $updateHelperSource === false || $clientAgentSource === false) {
    fail('failed to read artifact sources');
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
    'nginx artifact alias' => 'location ^~ /artifacts/files/',
    'nginx artifact anti-shadow route' => 'location ^~ /artifacts/',
    'nginx artifact anti-shadow fallback' => 'try_files /__leikwan_no_static_artifact_shadow__ /index.php?$query_string;',
    'installer sha short-circuit' => 'download_skip',
    'update download diagnostics' => 'last_download_bytes',
    'heartbeat update state' => '"agent_update_state": self.load_update_state()',
] as $label => $snippet) {
    if (!str_contains($responseSource . $artifactSource . $nginxSource . $installerSource . $updateHelperSource . $clientAgentSource, $snippet)) {
        fail('missing ' . $label . ': ' . $snippet);
    }
}

if (!str_contains($artifactSource, 'private function serveRepoFile(string $relativePath, string $contentType, Request $request')) {
    fail('serveRepoFile must require Request for conditional/range handling');
}
if (str_contains($artifactSource, '?Request $request = null')) {
    fail('serveRepoFile must not make Request optional because that bypasses conditional/range handling');
}
if (!str_contains($artifactSource, 'return $this->serveRepoFile(self::WSTUNNEL_ARTIFACT_DIR . \'/\' . $filename, $contentType, $request')) {
    fail('wstunnel package route must use serveRepoFile for cache/range handling');
}

$envPath = tempnam(sys_get_temp_dir(), 'lw-artifact-cache-');
if ($envPath === false) { fail('failed to create temp env'); }
file_put_contents($envPath, "DB_DRIVER=sqlite\nDB_NAME=:memory:\n");
$controller = new ArtifactController(new Config($envPath));

$first = $controller->clientAgent(new Request('GET', '/artifacts/client-agent.py', [], [], [], ''), []);
if ($first->status() !== 200) fail('expected 200 from client-agent artifact, got ' . $first->status());
$headers = $first->headers();
$etag = $headers['ETag'] ?? '';
if (!preg_match('/^"[a-f0-9]{64}"$/', $etag)) fail('expected strong sha256 ETag, got ' . var_export($etag, true));
if (($headers['Accept-Ranges'] ?? '') !== 'bytes') fail('expected Accept-Ranges: bytes');
if (($headers['X-Leikwan-Artifact-Response'] ?? '') !== 'artifact_response') fail('expected artifact observability header');
if ($first->body() === '') fail('expected non-empty artifact body');

$notModified = $controller->clientAgent(new Request('GET', '/artifacts/client-agent.py', [], [], ['If-None-Match' => $etag], ''), []);
if ($notModified->status() !== 304) fail('expected 304 for If-None-Match, got ' . $notModified->status());
if ($notModified->body() !== '') fail('304 response must be empty');

$range = $controller->clientAgent(new Request('GET', '/artifacts/client-agent.py', [], [], ['Range' => 'bytes=0-9'], ''), []);
if ($range->status() !== 206) fail('expected 206 for byte range, got ' . $range->status());
$rangeHeaders = $range->headers();
if (($rangeHeaders['Content-Range'] ?? '') !== 'bytes 0-9/' . strlen($first->body())) fail('unexpected Content-Range: ' . var_export($rangeHeaders['Content-Range'] ?? null, true));
if ((int)($rangeHeaders['Content-Length'] ?? 0) !== 10 || strlen($range->body()) !== 10) fail('range body length mismatch');
if ($range->body() !== substr($first->body(), 0, 10)) fail('range body bytes mismatch');

$suffix = $controller->clientAgent(new Request('GET', '/artifacts/client-agent.py', [], [], ['Range' => 'bytes=-10'], ''), []);
if ($suffix->status() !== 206) fail('expected 206 for suffix byte range, got ' . $suffix->status());
$suffixHeaders = $suffix->headers();
$body = $first->body();
$expectedSuffix = substr($body, -10);
if (($suffixHeaders['Content-Range'] ?? '') !== 'bytes ' . (strlen($body) - 10) . '-' . (strlen($body) - 1) . '/' . strlen($body)) fail('unexpected suffix Content-Range: ' . var_export($suffixHeaders['Content-Range'] ?? null, true));
if ((int)($suffixHeaders['Content-Length'] ?? 0) !== 10 || strlen($suffix->body()) !== 10) fail('suffix range body length mismatch');
if ($suffix->body() !== $expectedSuffix) fail('suffix range body bytes mismatch');

$oversizedSuffix = $controller->clientAgent(new Request('GET', '/artifacts/client-agent.py', [], [], ['Range' => 'bytes=-' . (strlen($body) + 10)], ''), []);
if ($oversizedSuffix->status() !== 206) fail('expected 206 for oversized suffix byte range, got ' . $oversizedSuffix->status());
$oversizedHeaders = $oversizedSuffix->headers();
if (($oversizedHeaders['Content-Range'] ?? '') !== 'bytes 0-' . (strlen($body) - 1) . '/' . strlen($body)) fail('unexpected oversized suffix Content-Range: ' . var_export($oversizedHeaders['Content-Range'] ?? null, true));
if ((int)($oversizedHeaders['Content-Length'] ?? 0) !== strlen($body) || strlen($oversizedSuffix->body()) !== strlen($body)) fail('oversized suffix range body length mismatch');
if ($oversizedSuffix->body() !== $body) fail('oversized suffix range body bytes mismatch');

foreach ([
    'start at eof' => 'bytes=' . strlen($body) . '-',
    'start after eof' => 'bytes=' . (strlen($body) + 10) . '-' . (strlen($body) + 20),
    'inverted range' => 'bytes=10-9',
    'zero suffix' => 'bytes=-0',
] as $label => $rangeHeader) {
    $unsatisfiable = $controller->clientAgent(new Request('GET', '/artifacts/client-agent.py', [], [], ['Range' => $rangeHeader], ''), []);
    if ($unsatisfiable->status() !== 416) fail('expected 416 for unsatisfiable range ' . $label . ', got ' . $unsatisfiable->status());
    $unsatisfiableHeaders = $unsatisfiable->headers();
    if (($unsatisfiableHeaders['Content-Range'] ?? '') !== 'bytes */' . strlen($body)) fail('unexpected 416 Content-Range for ' . $label . ': ' . var_export($unsatisfiableHeaders['Content-Range'] ?? null, true));
    if (($unsatisfiableHeaders['Content-Length'] ?? '') !== '0' || $unsatisfiable->body() !== '') fail('416 response must be empty for ' . $label);
}

echo "ok\n";
