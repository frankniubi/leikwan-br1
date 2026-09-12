<?php
// Two small fixes on the live lease endpoint:
//   1. the rotated-ticket log line referenced an undefined $clientId and always
//      printed client=0;
//   2. a forward whose lease the lease service refused was skipped silently -
//      the combined 200 response still carried the other forwards' leases, so
//      the client only ever saw "no lease for this forward" with no reason.
$path = '/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Controllers/ClientAgentController.php';
$code = file_get_contents($path);
if ($code === false || strlen($code) < 10000) { fwrite(STDERR, "unreadable\n"); exit(1); }

$fixes = [
    [
        "                    \$clientId,\n",
        "                    (int) (\$client['id'] ?? 0),\n",
    ],
    [
        "                \$lastError = (string) (\$result['error'] ?? \$lastError);\n",
        "                \$lastError = (string) (\$result['error'] ?? \$lastError);\n"
        . "                error_log(sprintf(\n"
        . "                    'ultra_lease_forward_refused client=%d forward=%d device=%s error=%s',\n"
        . "                    (int) (\$client['id'] ?? 0),\n"
        . "                    \$forwardId,\n"
        . "                    substr(hash('sha256', \$deviceId), 0, 16),\n"
        . "                    \$lastError\n"
        . "                ));\n",
    ],
];

$patched = $code;
foreach ($fixes as $index => $fix) {
    [$needle, $replacement] = $fix;
    $count = substr_count($patched, $needle);
    printf("fix %d anchor occurrences: %d\n", $index + 1, $count);
    if ($count !== 1) { fwrite(STDERR, "fix " . ($index + 1) . " anchor must appear exactly once\n"); exit(2); }
    $patched = str_replace($needle, $replacement, $patched);
}

$backup = '/root/leigod-forward-backups/lease-logging-' . gmdate('Ymd\THis\Z');
@mkdir($backup, 0777, true);
copy($path, $backup . '/ClientAgentController.php');
$tmp = $path . '.new-' . getmypid();
file_put_contents($tmp, $patched) or exit(3);
$lint = shell_exec('php -l ' . escapeshellarg($tmp) . ' 2>&1');
if (strpos((string) $lint, 'No syntax errors') === false) { fwrite(STDERR, "lint failed: $lint\n"); unlink($tmp); exit(4); }
rename($tmp, $path) or exit(5);
echo "patched ($backup)\n";
$final = file_get_contents($path);
echo "markers: refused=" . substr_count($final, 'ultra_lease_forward_refused') . " undefinedClientId=" . substr_count($final, "                    \$clientId,") . "\n";
foreach (['php-fpm-81-source196.service','php-fpm-82-source196.service','php-fpm-83-source196.service'] as $unit) { shell_exec('systemctl reload ' . $unit); }
echo "reloaded\n";