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
$bootstrap = $read('master/src/Bootstrap.php');
$controller = $read('master/src/Controllers/ExternalUotConfigController.php');

check($base !== '', 'BaseController.php readable');
check($bond !== '', 'install-bond-sidecar.sh readable');
check($external !== '', 'install-external-uot.sh readable');

check(str_contains($base, "' --external-uot-config '"), 'install command passes --external-uot-config');
check(str_contains($base, '/external-uot-config?host='), 'install command targets the host-specific config URL');
check(str_contains($base, "' --require-external-uot'"), 'install command requires the external UoT sidecar');
check(str_contains($base, 'shellQuote('), 'install command shell-quotes the config URL');

check(str_contains($bond, 'EXTERNAL_UOT_CONFIG="$ARTIFACT_BASE/external-uot-config"'), 'bond installer defaults the config URL');
check(str_contains($bond, '--require-external-uot'), 'bond installer exposes --require-external-uot');
check(str_contains($bond, '--skip-external-uot'), 'bond installer still allows skipping external UoT');

check(str_contains($external, '--config) CONFIG_SOURCE="$2"'), 'external installer accepts --config');
check(str_contains($external, '--check-config'), 'external installer validates the config before installing');

check(str_contains($controller, 'remoteAddress()'), 'config endpoint resolves the requesting address');
check(str_contains($bootstrap, "'/external-uot-config'"), 'bootstrap registers the config endpoint');
check(str_contains($bootstrap, "'/artifacts/external-uot-config'"), 'bootstrap registers the artifacts alias');

if ($failures > 0) {
    fwrite(STDERR, 'external_uot_install_wiring_test: ' . $failures . ' of ' . $checks . ' checks failed' . PHP_EOL);
    exit(1);
}
echo 'external_uot_install_wiring_test: ' . $checks . ' checks passed' . PHP_EOL;
