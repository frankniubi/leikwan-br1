<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$source = file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
foreach ([
    'name="runtime_preference"',
    'name="allow_private_self_leg"',
    'name="self_leg_scope"',
    'name="self_leg_role"',
    'name="helper_group_cap"',
    'Max Legs Used',
    'Default 4 for bond, CF Bond and MPTCP',
    'name="helper_escalation_policy"',
    'name="self_leg_cf_transport"',
    'cf_wg (WG over CF IP)',
    'Only effective when backend_mode = cf_mode',
    'helper_node_group_keys_text',

    'Public donation',
    'name="desired_latency_ms"',
    'name="allow_public_self_leg_donation"',
    'name="donation_qualify_window_seconds"',
    'name="donation_revoke_window_seconds"',
    'name="donation_min_open_ports"',
    'Donation gate is 75% of this value',
    'slave -&gt; sidecar -&gt; endpoint RTT',
] as $snippet) {
    if (!str_contains($source, $snippet)) {
        fail('missing admin snippet: ' . $snippet);
    }
}

echo "ok\n";
