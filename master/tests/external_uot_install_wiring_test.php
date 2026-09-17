<?php
declare(strict_types=1);

/**
 * Guard test: a fresh sidecar install must end up carrying its external UoT
 * config, because the Ultra runtime's external_uot carrier is what keeps the
 * client from falling back to a bare UDP candidate that fails.
 *
 * Before this wiring an operator had to pass --external-uot-config by hand, so
 * fresh builds silently came up without the UoT sidecar.  These assertions pin
 * the places that now do it automatically.
 */

$root = dirname(__DIR__, 2);
$failures = 0;
$checks = 0;

function check(bool $condition, string $label): void
{
    global $failures, $checks;
    $checks++;
    if ($condition) {
        return;
    }
    $failures++;
    fwrite(STDERR, 'FAIL ' . $label . PHP_EOL);
}

$read = static function (string $relative) use ($root): string {
    $path = $root . '/' . $relative;
    return is_file($path) ? (string) file_get_contents($path) : '';
};

$base = $read('master/src/Controllers/BaseController.php');
$bond = $read('install/install-bond-sidecar.sh');
$external = $read('install/install-external-uot.sh');
$clientInstaller = $read('install/install-client-agent.sh');
$clientUpdate = $read('install/client-agent-update.sh');
$sidecarUpdate = $read('install/sidecar-agent-update.sh');
$bootstrap = $read('master/src/Bootstrap.php');
$controller = $read('master/src/Controllers/ExternalUotConfigController.php');

check($base !== '', 'BaseController.php readable');
check($bond !== '', 'install-bond-sidecar.sh readable');
check($external !== '', 'install-external-uot.sh readable');
check($clientInstaller !== '', 'install-client-agent.sh readable');

check(str_contains($base, "' --external-uot-config '"), 'install command passes --external-uot-config');
check(str_contains($base, '/external-uot-config?host='), 'install command targets the host-specific config URL');
check(str_contains($base, "' --require-external-uot'"), 'install command requires the external UoT sidecar');
check(str_contains($base, 'shellQuote('), 'install command shell-quotes the config URL');

check(str_contains($bond, 'EXTERNAL_UOT_CONFIG="$ARTIFACT_BASE/external-uot-config"'), 'bond installer defaults the config URL');
check(str_contains($bond, '--require-external-uot'), 'bond installer exposes --require-external-uot');
check(str_contains($bond, '--skip-external-uot'), 'bond installer still allows skipping external UoT');
check(str_contains($bond, 'if ! "$BIN_DIR/install-external-uot.sh"'), 'bond installer guards a failed external UoT step');
check(str_contains($bond, 'external UoT sidecar install failed and --require-external-uot was set'), 'a failed external UoT step is fatal only with --require-external-uot');
check(str_contains($bond, 'warning: external UoT sidecar install failed; continuing without it'), 'a failed external UoT step warns and continues otherwise');
check(str_contains($bond, 'downloaded artifact is empty or unavailable'), 'bond installer rejects empty verified artifacts');
check(str_contains($bond, 'sidecar-agent version probe failed'), 'bond installer probes the selected agent binary');

check(str_contains($external, '--config) CONFIG_SOURCE="$2"'), 'external installer accepts --config');
check(str_contains($external, '--check-config'), 'external installer validates the config before installing');
check(str_contains($external, '--strict'), 'external installer exposes a strict read-only audit mode');
check(str_contains($external, 'health: PASS'), 'strict audit emits a machine-readable health result');
check(str_contains($external, '[[ "$enabled" == "enabled" ]]'), 'strict audit requires a forwarded sidecar unit to be enabled');
check(str_contains($external, '[[ "$enabled" != "enabled" ]]'), 'strict audit rejects an enabled empty sidecar unit');
check(str_contains($external, 'BIN_NAME="leikwan-external-uot-go"'), 'external installer uses the canonical Go binary path');
check(str_contains($external, 'LEGACY_BIN_NAME="leikwan-external-uot"'), 'external installer retains the legacy binary name for compatibility');
check(str_contains($external, 'existing_bin_path'), 'external installer audits legacy and canonical binary paths');
check(str_contains($external, 'Always validate the effective config'), 'upgrades validate a preserved config before service changes');
check(str_contains($external, 'existing config failed validation with downloaded sidecar'), 'external installer protects the current config before replacement');
check(str_contains($clientInstaller, 'AGENT_NOTIFY_ACCESS="all"'), 'frozen client agent allows sd_notify from the PyInstaller child');
check(str_contains($clientInstaller, 'NotifyAccess=$AGENT_NOTIFY_ACCESS'), 'client unit uses the runtime-specific notify access policy');
check(str_contains($clientUpdate, 'downloaded ${AGENT_BINARY_KEY} is empty'), 'client updater rejects an empty frozen binary');
check(str_contains($sidecarUpdate, 'downloaded ${AGENT_BINARY_KEY} is empty'), 'sidecar updater rejects an empty frozen binary');

check(str_contains($controller, 'remoteAddress()'), 'config endpoint resolves the requesting address');
check(str_contains($bootstrap, "'/external-uot-config'"), 'bootstrap registers the config endpoint');
check(str_contains($bootstrap, "'/artifacts/external-uot-config'"), 'bootstrap registers the artifacts alias');

if ($failures > 0) {
    fwrite(STDERR, 'external_uot_install_wiring_test: ' . $failures . ' of ' . $checks . ' checks failed' . PHP_EOL);
    exit(1);
}
echo 'external_uot_install_wiring_test: ' . $checks . ' checks passed' . PHP_EOL;
