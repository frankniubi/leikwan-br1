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

if (!preg_match('/UPDATE forward_combinations\s+SET(.*?)WHERE id = :id/s', $source, $m)) {
    fail('UPDATE forward_combinations block not found');
}
if (strpos($m[1], 'self_leg_cf_transport = :self_leg_cf_transport') === false) {
    fail('UPDATE missing self_leg_cf_transport');
}
foreach ([
    "['cf_direct', 'cf_wg']",
    "'self_leg_cf_transport' => \$selfLegCfTransport",
] as $snippet) {
    if (strpos($source, $snippet) === false) {
        fail('missing snippet: ' . $snippet);
    }
}

echo "ok
";
