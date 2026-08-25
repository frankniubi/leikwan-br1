<?php
declare(strict_types=1);

$source = (string) file_get_contents(__DIR__ . '/../src/Controllers/BaseController.php');
if ($source === '') {
    fwrite(STDERR, "failed to read BaseController\n");
    exit(1);
}

foreach ([
    'string $successLabel = \'Copied\'',
    'string $errorLabel = \'Copy failed\'',
    'data-copy-success="\' . $this->escape($successLabel)',
    'data-copy-error="\' . $this->escape($errorLabel)',
] as $required) {
    if (!str_contains($source, $required)) {
        fwrite(STDERR, 'missing localized copy-button contract: ' . $required . "\n");
        exit(1);
    }
}

echo "ok\n";
