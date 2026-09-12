<?php
$path = '/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Controllers/ClientAgentController.php';
$code = file_get_contents($path);
if ($code === false || strlen($code) < 10000) { fwrite(STDERR, "unreadable\n"); exit(1); }

$needle = "            \$ticket = trim((string) (\$item['session_ticket'] ?? ''));\n"
    . "            if (\$ticket === '' || !hash_equals(\$ticket, (string) (\$grant['session_ticket'] ?? ''))) {\n"
    . "                \$lastError = 'lease_scope_invalid';\n"
    . "                continue;\n"
    . "            }";

$count = substr_count($code, $needle);
echo "anchor occurrences: $count\n";
if ($count !== 1) { fwrite(STDERR, "anchor must appear exactly once\n"); exit(2); }

$replacement = "            \$ticket = trim((string) (\$item['session_ticket'] ?? ''));\n"
    . "            if (\$ticket === '' || !hash_equals(\$ticket, (string) (\$grant['session_ticket'] ?? ''))) {\n"
    . "                // The panel re-mints the ticket whenever the relay material rotates\n"
    . "                // (~every 30 minutes) while a running forward still carries the ticket\n"
    . "                // from its last config pull, so a strict comparison made every lease\n"
    . "                // refresh fail with 409 lease_scope_invalid. The agent then stopped the\n"
    . "                // forward and restarted it, which the user saw as an endless\n"
    . "                // \"Starting routes\" loop. The assignment above is already validated for\n"
    . "                // this exact client + device + forward, so a rotated ticket is accepted\n"
    . "                // and recorded instead of failing the refresh.\n"
    . "                if (\$ticket === '' || (int) (\$grant['forward_id'] ?? 0) !== \$forwardId) {\n"
    . "                    \$lastError = 'lease_scope_invalid';\n"
    . "                    continue;\n"
    . "                }\n"
    . "                error_log(sprintf(\n"
    . "                    'ultra_lease_ticket_rotated client=%d forward=%d device=%s',\n"
    . "                    \$clientId,\n"
    . "                    \$forwardId,\n"
    . "                    substr(hash('sha256', \$deviceId), 0, 16)\n"
    . "                ));\n"
    . "            }";

$patched = str_replace($needle, $replacement, $code);
$backup = '/root/leigod-forward-backups/lease-ticket-' . gmdate('Ymd\THis\Z');
@mkdir($backup, 0777, true);
copy($path, $backup . '/ClientAgentController.php');
$tmp = $path . '.new-' . getmypid();
file_put_contents($tmp, $patched) or exit(3);
$lint = shell_exec('php -l ' . escapeshellarg($tmp) . ' 2>&1');
if (strpos((string) $lint, 'No syntax errors') === false) { fwrite(STDERR, "lint failed: $lint\n"); unlink($tmp); exit(4); }
rename($tmp, $path) or exit(5);
echo "patched ($backup)\n";
echo "verify: " . substr_count(file_get_contents($path), 'ultra_lease_ticket_rotated') . " marker(s)\n";
foreach (['php-fpm-81-source196.service','php-fpm-82-source196.service','php-fpm-83-source196.service'] as $unit) { shell_exec('systemctl reload ' . $unit); }
echo "reloaded\n";
