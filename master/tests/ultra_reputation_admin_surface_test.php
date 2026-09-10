<?php
declare(strict_types=1);

$source = (string) file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
foreach (['UltraCandidateReputationService', "'reputation'", 'candidateReputation', 'quarantine_until', 'independent_observers'] as $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, 'missing Ultra reputation admin surface: ' . $needle . PHP_EOL);
        exit(1);
    }
}
echo "ok\n";
