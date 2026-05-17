<?php
declare(strict_types=1);

$php = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
if ($php === false) {
    fwrite(STDERR, "failed to read ProvisioningService.php\n");
    exit(1);
}

if (strpos($php, 'selectedSelfLegCfIp(') !== false) {
    fwrite(STDERR, "panel should not choose one self-leg CF IP anymore\n");
    exit(1);
}

foreach ([
    "'dial_connect_ip' => (string) (\$cfTarget['dial_connect_ip'] ?? '')",
    "'self_leg_cf_candidates' => array_values(array_filter(array_map(",
    "'cf_sni_host' => \$cfTarget['cf_sni_host']",
] as $snippet) {
    if (strpos($php, $snippet) === false) {
        fwrite(STDERR, "expected self-leg candidate-set contract snippet missing: {$snippet}\n");
        exit(1);
    }
}

echo "ok\n";
