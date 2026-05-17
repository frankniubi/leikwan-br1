<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
if ($source === false) {
    fail('failed to read ProvisioningService.php');
}

foreach ([
    'public function republishActiveForwardsForCombination(int $combinationId): void',
    '$this->republishActiveForwardsForCombination($id);',
    'f.forward_combination_id = :forward_combination_id',
] as $snippet) {
    if (strpos($source, $snippet) === false) {
        fail('missing snippet: ' . $snippet);
    }
}

echo "ok\n";