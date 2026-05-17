<?php
declare(strict_types=1);

$php = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
if ($php === false) {
    fwrite(STDERR, "failed to read ProvisioningService.php\n");
    exit(1);
}

if (strpos($php, "'target_sidecar_port' => (int) (\$config['bond_sidecar_cf_listen_port'] ?? \$dialPort)") !== false) {
    fwrite(STDERR, "self leg still targets cf_listen_port instead of websocket backend target\n");
    exit(1);
}

if (strpos($php, "'target_sidecar_bond_port' => (int) (\$config['bond_sidecar_port'] ?? \$config['bond_remote_port'] ?? 0)") === false) {
    fwrite(STDERR, "expected target_sidecar_bond_port publish field missing\n");
    exit(1);
}

echo "ok\n";
