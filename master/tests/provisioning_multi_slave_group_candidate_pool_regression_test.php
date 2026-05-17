<?php
declare(strict_types=1);

$php = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
if ($php === false) {
    fwrite(STDERR, "failed to read ProvisioningService.php\n");
    exit(1);
}
if (strpos($php, 'forward_combination_helper_node_group_keys') === false) {
    fwrite(STDERR, "helper group key usage missing\n");
    exit(1);
}
if (strpos($php, "'origin_group_key' =>") === false) {
    fwrite(STDERR, "candidate pool origin_group_key missing\n");
    exit(1);
}
if (strpos($php, "'origin_group_order' =>") === false) {
    fwrite(STDERR, "candidate pool origin_group_order missing\n");
    exit(1);
}
echo "ok\n";
