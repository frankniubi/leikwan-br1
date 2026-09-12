<?php
// Report which forward a lease request lost, and why, alongside the leases that
// did succeed. The endpoint answers 200 as soon as any forward got a lease, so a
// single refused forward (rotated policy generation, moved relay, ...) reached
// the client as "no lease for this forward" with no reason at all.
$path = '/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Controllers/ClientAgentController.php';
$code = file_get_contents($path);
if ($code === false || strlen($code) < 10000) { fwrite(STDERR, "unreadable\n"); exit(1); }

$fixes = [
    [
        "        \$leases = [];\n"
        . "        \$lastError = 'lease_scope_invalid';\n",
        "        \$leases = [];\n"
        . "        \$failures = [];\n"
        . "        \$lastError = 'lease_scope_invalid';\n",
    ],
    [
        "                \$lastError = 'policy_unavailable';\n"
        . "                continue;\n",
        "                \$lastError = 'policy_unavailable';\n"
        . "                \$failures[\$forwardId] = \$lastError;\n"
        . "                continue;\n",
    ],
    [
        "                    \$lastError\n"
        . "                ));\n",
        "                    \$lastError\n"
        . "                ));\n"
        . "                \$failures[\$forwardId] = \$lastError;\n",
    ],
    [
        "            return \$this->json(['ok' => false, 'error' => \$lastError], 409)->withHeaders([",
        "            return \$this->json(['ok' => false, 'error' => \$lastError, 'failures' => \$failures], 409)->withHeaders([",
    ],
    [
        "        return \$this->json(['ok' => true, 'leases' => \$leases])->withHeaders([",
        "        return \$this->json(['ok' => true, 'leases' => \$leases, 'failures' => \$failures])->withHeaders([",
    ],
];

$patched = $code;
foreach ($fixes as $index => $fix) {
    [$needle, $replacement] = $fix;
    $count = substr_count($patched, $needle);
    printf("fix %d anchor occurrences: %d\n", $index + 1, $count);
    if ($count !== 1) { fwrite(STDERR, "fix " . ($index + 1) . " anchor must appear exactly once - nothing written\n"); exit(2); }
    $patched = str_replace($needle, $replacement, $patched);
}

$backup = '/root/leigod-forward-backups/lease-failures-' . gmdate('Ymd\THis\Z');
@mkdir($backup, 0777, true);
copy($path, $backup . '/ClientAgentController.php');
$tmp = $path . '.new-' . getmypid();
file_put_contents($tmp, $patched) or exit(3);
$lint = shell_exec('php -l ' . escapeshellarg($tmp) . ' 2>&1');
if (strpos((string) $lint, 'No syntax errors') === false) { fwrite(STDERR, "lint failed: $lint\n"); unlink($tmp); exit(4); }
rename($tmp, $path) or exit(5);
echo "patched ($backup)\n";
$final = file_get_contents($path);
echo "markers: failuresMap=" . substr_count($final, "\$failures[\$forwardId] = \$lastError;") . " responseFailures=" . substr_count($final, "'failures' => \$failures") . "\n";
foreach (['php-fpm-81-source196.service','php-fpm-82-source196.service','php-fpm-83-source196.service'] as $unit) { shell_exec('systemctl reload ' . $unit); }
echo "reloaded\n";