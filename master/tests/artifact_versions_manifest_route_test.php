<?php
declare(strict_types=1);

namespace {

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

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    $repoRoot = dirname(__DIR__, 2);

    $envPath = tempnam(sys_get_temp_dir(), 'lw-versions-env-');
    if ($envPath === false) {
        fail('failed to create temp env file');
    }
    file_put_contents($envPath, "DB_DRIVER=sqlite\nDB_NAME=:memory:\n");
    $config = new Config($envPath);

    $controller = new ArtifactController($config);

    if (!method_exists($controller, 'versionsManifest')) {
        fail('ArtifactController must expose versionsManifest() for GET /artifacts/versions.json');
    }

    $request = new Request('GET', '/artifacts/versions.json', [], [], [], '');
    $response = $controller->versionsManifest($request, []);

    $reflect = new \ReflectionObject($response);
    $statusProp = $reflect->getProperty('status');
    $statusProp->setAccessible(true);
    $headersProp = $reflect->getProperty('headers');
    $headersProp->setAccessible(true);
    $bodyProp = $reflect->getProperty('body');
    $bodyProp->setAccessible(true);

    $status = (int) $statusProp->getValue($response);
    /** @var array<string, string> $headers */
    $headers = (array) $headersProp->getValue($response);
    $body = (string) $bodyProp->getValue($response);

    if ($status !== 200) {
        fail('expected 200 from versions.json route, got ' . $status);
    }

    $contentType = '';
    foreach ($headers as $name => $value) {
        if (strtolower((string) $name) === 'content-type') {
            $contentType = (string) $value;
        }
    }
    if (stripos($contentType, 'application/json') === false) {
        fail('expected application/json Content-Type, got ' . var_export($contentType, true));
    }

    $manifest = json_decode($body, true);
    if (!is_array($manifest)) {
        fail('versions.json route body must be valid JSON object');
    }

    // Authoritative sha must equal sha256 recomputed from the actual served file.
    $slaveAgentPath = $repoRoot . '/slave-agent/slave_agent.py';
    if (!is_file($slaveAgentPath)) {
        fail('slave-agent/slave_agent.py missing in repo');
    }
    $expectedSha = hash_file('sha256', $slaveAgentPath);

    $servedSha = (string) ($manifest['slave_agent']['sha256'] ?? '');
    if ($servedSha !== $expectedSha) {
        fail('served slave_agent.sha256 (' . $servedSha . ') must equal sha256(slave_agent.py) (' . $expectedSha . ')');
    }

    // ... and must equal what BaseController::artifactMetadata advertises.
    $metaController = new class($config) extends \Leikwan\Master\Controllers\BaseController {
        public function __construct(Config $config)
        {
            parent::__construct($config, new \Leikwan\Master\Database\Database($config));
        }

        /** @return array<string, mixed> */
        public function publicArtifactMetadata(string $key): array
        {
            return $this->artifactMetadata($key);
        }
    };
    $advertisedSha = (string) ($metaController->publicArtifactMetadata('slave_agent')['sha256'] ?? '');
    if ($servedSha !== $advertisedSha) {
        fail('served slave_agent.sha256 (' . $servedSha . ') must equal artifactMetadata sha (' . $advertisedSha . ')');
    }

    // size_bytes must also be recomputed from the real file.
    $expectedSize = filesize($slaveAgentPath);
    if ((int) ($manifest['slave_agent']['size_bytes'] ?? -1) !== (int) $expectedSize) {
        fail('served slave_agent.size_bytes must equal actual file size');
    }

    // Bootstrap must register the route next to slave-agent.py.
    $bootstrap = (string) file_get_contents($repoRoot . '/master/src/Bootstrap.php');
    if (!str_contains($bootstrap, "\$this->router->add('GET', '/artifacts/versions.json', [\$artifacts, 'versionsManifest']);")) {
        fail('Bootstrap must register GET /artifacts/versions.json -> versionsManifest');
    }

    echo "ok\n";
}
