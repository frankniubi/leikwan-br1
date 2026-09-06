<?php
declare(strict_types=1);

function failUltraLeaseEndpoint(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertUltraLeaseEndpoint(bool $condition, string $message): void
{
    if (!$condition) {
        failUltraLeaseEndpoint($message);
    }
}

$bootstrap = (string) file_get_contents(__DIR__ . '/../src/Bootstrap.php');
$controller = (string) file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
$lease = (string) file_get_contents(__DIR__ . '/../src/Support/UltraLeaseService.php');

assertUltraLeaseEndpoint(str_contains($bootstrap, 'UltraMode::LEASE_ENDPOINT'), 'v2 lease route missing');
assertUltraLeaseEndpoint(str_contains($bootstrap, 'new UltraLeaseService'), 'v2 lease service is not wired');
assertUltraLeaseEndpoint(str_contains($controller, 'public function resolveUltraLease'), 'v2 lease controller missing');
assertUltraLeaseEndpoint(str_contains($controller, 'resolveUltraMaterials'), 'legacy material compatibility boundary missing');
assertUltraLeaseEndpoint(str_contains($controller, "'Cache-Control' => 'no-store"), 'lease endpoint lacks no-store response');
assertUltraLeaseEndpoint(str_contains($controller, "'session_ticket'"), 'lease endpoint does not bind opaque ticket');
assertUltraLeaseEndpoint(str_contains($controller, "'policy_generation'"), 'lease endpoint does not bind policy generation');
assertUltraLeaseEndpoint(str_contains($lease, "'target_digest'"), 'lease service does not bind target digest');
assertUltraLeaseEndpoint(str_contains($lease, "'nonce'"), 'lease service does not bind replay nonce');
assertUltraLeaseEndpoint(str_contains($lease, 'consumeNonce'), 'lease replay guard missing');
assertUltraLeaseEndpoint(str_contains($lease, 'markConsumed'), 'lease audit consumption missing');
assertUltraLeaseEndpoint(str_contains($lease, 'revokeGeneration'), 'lease generation revocation missing');
assertUltraLeaseEndpoint(str_contains($controller, "'ultra_actions'"), 'heartbeat does not return Ultra policy actions');
assertUltraLeaseEndpoint(str_contains($controller, 'recordUltraUsage'), 'heartbeat does not account Ultra usage');
assertUltraLeaseEndpoint(str_contains($controller, "'ultra_usage_acknowledged' => \$ultraUsageAcknowledged"), 'heartbeat does not expose usage acknowledgement');
assertUltraLeaseEndpoint(str_contains($controller, '$ultraUsageAcknowledged = false;'), 'heartbeat does not reject failed usage batches');
assertUltraLeaseEndpoint(str_contains($controller, 'scrubUltraClientForward'), 'Ultra forwards are not scrubbed before grant handling');
assertUltraLeaseEndpoint(str_contains($controller, 'redactUltraClientDisplayText'), 'Ultra client display text is not provider-neutralized');
assertUltraLeaseEndpoint(str_contains($controller, 'Leigod|雷神'), 'Ultra provider branding redaction pattern is missing');
assertUltraLeaseEndpoint(str_contains($controller, "'leases' => " . '$leases'), 'v2 lease payload missing');
foreach (['line_client_id', 'line_client_name', 'client_token', '_ultra_line_ids', '_ultra_lines'] as $forbidden) {
    assertUltraLeaseEndpoint(str_contains($controller, "'$forbidden'"), 'Ultra client scrub boundary missing: ' . $forbidden);
}

echo "ok\n";
