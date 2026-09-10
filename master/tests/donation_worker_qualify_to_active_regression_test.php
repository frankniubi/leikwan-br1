<?php
declare(strict_types=1);
function fail(string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); }
$source = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php') ?: '';
$script = file_get_contents(__DIR__ . '/../scripts/evaluate_donations.php') ?: '';
$unit = file_get_contents(__DIR__ . '/../../deploy/systemd/leikwan-donation-worker.service') ?: '';
$timer = file_get_contents(__DIR__ . '/../../deploy/systemd/leikwan-donation-worker.timer') ?: '';
foreach ([
    'public const DONATION_GATE_RATIO = 0.75',
    'donation_qualify_gate_ratio',
    'evaluateDonationStateForCombination(int $combinationId): void',
    'allow_public_self_leg_donation',
    'qualify_started_at',
    "state = :active",
    'republishActiveForwardsForCombination',
] as $snippet) {
    if (!str_contains($source, $snippet)) { fail('missing worker qualify snippet: ' . $snippet); }
}
foreach (['evaluateDonationStateForCombination', 'leikwan-donation-worker'] as $snippet) {
    if (!str_contains($script . $unit . $timer, $snippet)) { fail('missing worker file snippet: ' . $snippet); }
}
foreach (['WorkingDirectory=/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master', 'ExecStart=/usr/bin/php /www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/scripts/evaluate_donations.php'] as $snippet) {
    if (!str_contains($unit, $snippet)) { fail('donation worker unit path mismatch: ' . $snippet); }
}
if (str_contains($unit, '/var/www/leikwan') || str_contains($unit, '/var/www/master')) { fail('donation worker unit uses stale /var/www path'); }
if (str_contains($unit, 'User=www-data') || str_contains($unit, 'Group=www-data')) { fail('donation worker unit should not assume www-data user on panel hosts'); }
echo "ok
";
