<?php
declare(strict_types=1);

// Regression guard: leigod_mode is a DIRECT-ingress Forward Mode (like current_mode). It must NEVER
// inherit Cloudflare ingress or the self-leg CF primary leg. The realization keys CF off backend_mode
// (=== 'cf_mode' / str_starts_with 'cf'), so a non-cf 'leigod_mode' naturally takes the direct path.
// This source-grep test locks that invariant so a future refactor can't silently route leigod_mode
// through CF (e.g. by lumping 'leigod_mode' into a cf comparison).

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$svc = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php') ?: '';
if ($svc === '') {
    fail('could not read ProvisioningService.php');
}

// 1. saveForwardCombination derives the leigod self-leg AND clears all CF coupling for leigod_mode.
foreach ([
    "if (\$backendMode === 'leigod_mode') {",
    "\$selfLegOuterTransport = 'leigod';",
    "\$allowPrivateSelfLeg = false;",
    "\$cfLocationGroupId = 0;",
    "\$leigodUotImpl = 'lguot';",
] as $snippet) {
    if (!str_contains($svc, $snippet)) {
        fail('leigod_mode derivation missing CF-decoupling snippet: ' . $snippet);
    }
}

// 2. The self-leg CF primary leg stays gated on backend_mode === 'cf_mode'. leigod_mode != cf_mode,
//    so publishedPrivateSelfLegCandidates returns null for it (no CF self-leg drag).
if (!str_contains($svc, "(string) (\$config['backend_mode'] ?? '') === 'cf_mode'")) {
    fail('self-leg CF gate no longer keyed on cf_mode');
}

// 3. The CF predicate must NOT reference leigod -- staticSidecarCombinationUsesCf keys off the
//    backend_mode/connection_type/client_mode 'cf' prefix only, so leigod_mode can never trip it.
$cfStart = strpos($svc, 'private function staticSidecarCombinationUsesCf');
$cfEnd = strpos($svc, 'private function staticSidecarCombinationUsesLeigod');
if ($cfStart === false || $cfEnd === false || $cfEnd <= $cfStart) {
    fail('could not isolate staticSidecarCombinationUsesCf body');
}
$cfBody = substr($svc, $cfStart, $cfEnd - $cfStart);
if (!str_contains($cfBody, "str_starts_with(\$value, 'cf')")) {
    fail('staticSidecarCombinationUsesCf no longer keys off the cf prefix');
}
if (stripos($cfBody, 'leigod') !== false) {
    fail('staticSidecarCombinationUsesCf must not reference leigod (would route leigod_mode through CF)');
}

// 4. leigod_mode/cf exclusivity stays in realization (a leigod self-leg suppresses the cf uplink).
if (!str_contains($svc, '$cfMode = $leigodMode ? false : $this->staticSidecarCombinationUsesCf($combination);')) {
    fail('leigod-over-cf exclusivity removed from realization');
}

// 5. Realization default carrier stays 'gost' (leigod_mode opts into lguot via the persisted column,
//    not by changing the realization default -- keeps provisioning_leigod_uot_impl_test happy).
if (!str_contains($svc, "strtolower((string) (\$combination['leigod_uot_impl'] ?? 'gost')) === 'lguot' ? 'lguot' : 'gost'")) {
    fail('realization leigod_uot_impl default changed off gost');
}

echo "OK\n";
exit(0);
