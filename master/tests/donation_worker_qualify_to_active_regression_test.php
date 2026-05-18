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
echo "ok
";
