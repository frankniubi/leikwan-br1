<?php
declare(strict_types=1);
spl_autoload_register(static function (string $class): void {
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen('Leikwan\\Master\\'))) . '.php';
    if (is_file($path)) { require_once $path; }
});
use Leikwan\Master\Support\CliOptions;
function checkCli(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}

// The sub-command form that getopt() silently ignored.
$parsed = CliOptions::parse(['--host', '103.246.219.39', '--listen-port=7443'], ['host', 'listen-port'], ['apply']);
checkCli($parsed === ['host' => '103.246.219.39', 'listen-port' => '7443'], 'value options not parsed');
checkCli(CliOptions::parse(['--apply'], ['combination'], ['apply']) === ['apply' => true], 'flag not parsed');
checkCli(CliOptions::parse([], ['host']) === [], 'empty args');

foreach ([
    [['--nope'], 'unknown option'],
    [['--host'], 'missing value'],
    [['--host', '--apply'], 'value swallowed an option'],
    [['--apply=1'], 'flag with value'],
    [['stray'], 'positional argument'],
] as [$args, $label]) {
    try {
        CliOptions::parse($args, ['host'], ['apply']);
        throw new RuntimeException('accepted: ' . $label);
    } catch (InvalidArgumentException) {
    }
}

// Both CLIs parse with CliOptions, not getopt().
foreach (['external-uot-tokens.php', 'external-uot-standalone-sync.php'] as $cli) {
    $source = (string) file_get_contents(__DIR__ . '/../cli/' . $cli);
    checkCli(str_contains($source, 'CliOptions::parse('), "$cli does not use CliOptions");
    checkCli(!str_contains($source, 'getopt('), "$cli still uses getopt");
}
$sync = (string) file_get_contents(__DIR__ . '/../cli/external-uot-standalone-sync.php');
checkCli(str_contains($sync, 'StrictHostKeyChecking=yes') && str_contains($sync, 'BatchMode=yes'), 'sync ssh must pin host keys and never prompt');
checkCli(str_contains($sync, "\$forwards === []"), 'sync must never push an empty forward set');
// renderForHost() returns forwards as an object (buildPayload casts it); the
// first deploy skipped every host because the sync only accepted arrays.
$controller = (string) file_get_contents(__DIR__ . '/../src/Controllers/ExternalUotConfigController.php');
checkCli(str_contains($controller, "'forwards' => (object) \$forwards"), 'buildPayload forwards shape changed; re-check the sync');
checkCli(str_contains($sync, 'get_object_vars($forwards)'), 'sync must accept the object forwards map');
checkCli(str_contains($sync, "['forwards' => (object) \$forwards]"), 'sync must always send forwards as a JSON map');

echo "cli_options_test: ok\n";
