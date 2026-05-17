<?php
declare(strict_types=1);

$php = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
if ($php === false) {
    fwrite(STDERR, "failed to read ProvisioningService.php\n");
    exit(1);
}

if (!str_contains($php, "\$publishesBondRuntime = \$clientMode === 'bond' || \$runtimePreference === 'mptcp_preferred_bond_fallback'")) {
    fwrite(STDERR, "expected mptcp_preferred_bond_fallback payload to publish bond runtime config\n");
    exit(1);
}

if (!str_contains($php, "\$bondConfig = \$publishesBondRuntime")) {
    fwrite(STDERR, "expected bond config to be built from publishesBondRuntime gate\n");
    exit(1);
}

if (!str_contains($php, "if (\$publishesBondRuntime && \$bondIngressMode === 'plain_tcp_mux')")) {
    fwrite(STDERR, "expected fallback bond endpoints to carry plain_tcp_mux auth metadata\n");
    exit(1);
}

echo "ok\n";
