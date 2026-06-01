<?php
declare(strict_types=1);

// Regression: staticSidecarUplinksForForwardNode() must support a per-sidecar-point
// direct-TCP gate (SIDECAR_DIRECT_TCP_POINT_IDS, keyed on bond_sidecar_point_id) so
// every forward on a UDP-hostile sidecar point (e.g. HK/CNIX point 4) uses direct-TCP
// to the bond server (no WireGuard) automatically -- existing AND future forwards.

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$src = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
if (!is_string($src) || $src === '') {
    fail('cannot read ProvisioningService.php');
}

$start = strpos($src, 'private function staticSidecarUplinksForForwardNode');
if ($start === false) {
    fail('staticSidecarUplinksForForwardNode() not found');
}
$end = strpos($src, 'private function ', $start + 50);
$method = substr($src, $start, $end !== false ? $end - $start : 800);

if (strpos($method, 'SIDECAR_DIRECT_TCP_POINT_IDS') === false) {
    fail('missing per-sidecar-point direct-TCP gate (SIDECAR_DIRECT_TCP_POINT_IDS)');
}
if (strpos($method, "bond_sidecar_point_id") === false) {
    fail('per-point gate must key on bond_sidecar_point_id');
}
if (strpos($method, 'SIDECAR_DIRECT_TCP_FORWARD_IDS') === false) {
    fail('per-forward direct-TCP gate unexpectedly removed');
}

echo "ok: staticSidecarUplinksForForwardNode honors per-point + per-forward direct-TCP gates\n";
