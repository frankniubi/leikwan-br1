<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Config;
use Leikwan\Master\Controllers\BaseController;
use Leikwan\Master\Database\Database;

final class LeigodOnlyArtifactMetadataProbe
{
    public function __construct(private ?Config $config = null)
    {
    }

    /**
     * @param list<string> $keys
     * @return array<string, array<string, mixed>>
     */
    public function metadataForActivation(array $keys): array
    {
        $config = $this->config ?? new Config($this->tempEnv());
        $controller = new class($config) extends BaseController {
            public function __construct(Config $config)
            {
                parent::__construct($config, new Database($config));
            }
        };
        return $controller->artifactMetadataForActivation($keys);
    }

    private function tempEnv(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lw-artifact-meta-env-');
        if ($path === false) {
            throw new \RuntimeException('failed to create artifact metadata env');
        }
        file_put_contents($path, "DB_DRIVER=sqlite\nDB_NAME=:memory:\nAUTO_RUN_MIGRATIONS=false\n");
        return $path;
    }
}
