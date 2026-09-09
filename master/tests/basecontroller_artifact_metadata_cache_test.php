<?php
declare(strict_types=1);

$source = (string) file_get_contents(__DIR__ . '/../src/Controllers/BaseController.php');
if ($source === '') {
    fwrite(STDERR, "failed to read BaseController.php\n");
    exit(1);
}

if (!str_contains($source, 'private array $normalizedArtifactMetadataCache = [];')) {
    fwrite(STDERR, "artifact metadata must cache normalized entries per request\n");
    exit(1);
}
if (!str_contains($source, 'array_key_exists($key, $this->normalizedArtifactMetadataCache)')) {
    fwrite(STDERR, "artifact metadata cache must distinguish cached empty entries\n");
    exit(1);
}
if (!str_contains($source, '$this->normalizedArtifactMetadataCache[$key] = $this->normalizeArtifactMetadataEntry($entry);')) {
    fwrite(STDERR, "artifact metadata must store normalized entries after the first lookup\n");
    exit(1);
}

echo "ok\n";
