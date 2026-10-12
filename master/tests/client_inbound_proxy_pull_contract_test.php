<?php
declare(strict_types=1);

// The config pull tells desktop clients whether their forward listeners may
// accept connections from other machines (clients.allow_inbound_proxy).
// Desktop clients bind 127.0.0.1 unless this is true, so a forward never
// becomes an open proxy on the LAN by default.

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$root = __DIR__ . '/..';
$source = (string) file_get_contents($root . '/src/Controllers/ClientAgentController.php');
$schema = (string) file_get_contents($root . '/migrations/schema.sql');
if ($source === '' || $schema === '') {
    fail('failed to read sources');
}

$pull = substr($source, (int) strpos($source, 'public function pullConfigs('));
$pull = substr($pull, 0, (int) strpos($pull, "\n    }\n") ?: strlen($pull));
foreach ([
    "'allow_inbound_proxy' => (bool) (\$snapshot['allow_inbound_proxy'] ?? false),",
] as $needle) {
    if (!str_contains($pull, $needle)) {
        fail('pull client block must publish: ' . $needle);
    }
}

foreach ([
    "\$allowInboundProxy = !empty(\$client['allow_inbound_proxy']);",
    // Only enabled clients see their config revision change (no churn for everyone else).
    "if (\$allowInboundProxy) {\n            \$revisionExtra['allow_inbound_proxy'] = true;\n        }",
    "'allow_inbound_proxy' => \$allowInboundProxy,",
] as $needle) {
    if (!str_contains($source, $needle)) {
        fail('config snapshot missing: ' . $needle);
    }
}

// The flag comes from the per-client column, which defaults to off.
if (preg_match('/allow_inbound_proxy\s+TINYINT\(1\)\s+NOT NULL\s+DEFAULT 0/i', $schema) !== 1) {
    fail('clients.allow_inbound_proxy must default to 0');
}

echo "ok\n";
