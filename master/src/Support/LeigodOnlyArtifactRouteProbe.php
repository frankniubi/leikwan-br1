<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Bootstrap;
use Leikwan\Master\Config;
use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;

final class LeigodOnlyArtifactRouteProbe
{
    public function __construct(private Config|string|null $configOrEnvPath = null)
    {
    }

    /** @return array{status:int,headers:array<string,string>,body:string,sha256:string,size_bytes:int} */
    public function probe(string $path): array
    {
        $envPath = $this->envPath();
        $bootstrap = new Bootstrap($envPath);
        $response = $bootstrap->handle(new Request('GET', $path, [], [], [], ''));
        return $this->responsePayload($response);
    }

    private function envPath(): string
    {
        if ($this->configOrEnvPath instanceof Config) {
            $ref = new \ReflectionClass($this->configOrEnvPath);
            if ($ref->hasProperty('values')) {
                return $this->tempEnv();
            }
        }
        if (is_string($this->configOrEnvPath) && $this->configOrEnvPath !== '') {
            return $this->configOrEnvPath;
        }
        return $this->tempEnv();
    }

    private function tempEnv(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lw-artifact-probe-env-');
        if ($path === false) {
            throw new \RuntimeException('failed to create artifact probe env');
        }
        file_put_contents($path, "DB_DRIVER=sqlite\nDB_NAME=:memory:\nAUTO_RUN_MIGRATIONS=false\n");
        return $path;
    }

    /** @return array{status:int,headers:array<string,string>,body:string,sha256:string,size_bytes:int} */
    private function responsePayload(Response $response): array
    {
        $ref = new \ReflectionObject($response);
        $statusProp = $ref->getProperty('status');
        $statusProp->setAccessible(true);
        $headersProp = $ref->getProperty('headers');
        $headersProp->setAccessible(true);
        $bodyProp = $ref->getProperty('body');
        $bodyProp->setAccessible(true);
        $body = (string) $bodyProp->getValue($response);
        /** @var array<string,string> $headers */
        $headers = (array) $headersProp->getValue($response);
        $declaredEtag = trim((string) ($headers['ETag'] ?? ''), " \t\"");
        $declaredSize = filter_var($headers['Content-Length'] ?? null, FILTER_VALIDATE_INT);
        $sha256 = preg_match('/^[a-f0-9]{64}$/i', $declaredEtag) === 1
            ? strtolower($declaredEtag)
            : hash('sha256', $body);
        $sizeBytes = is_int($declaredSize) && $declaredSize >= 0
            ? $declaredSize
            : strlen($body);
        return [
            'status' => (int) $statusProp->getValue($response),
            'headers' => $headers,
            'body' => $body,
            'sha256' => $sha256,
            'size_bytes' => $sizeBytes,
        ];
    }
}
