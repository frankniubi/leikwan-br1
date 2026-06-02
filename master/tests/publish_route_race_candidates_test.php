<?php
declare(strict_types=1);

// R1 (B3): for a forward bound to a race-ON Forward Route, the panel emits ONE selector candidate
// per enabled route-combo (same sidecar), so the existing slave selector races cnix vs leigod.
// staticSidecarUplinksForForwardNode must return one primary uplink per enabled combo, each tagged
// with its forward_combination_id and a distinct interface_name. enabledRouteCombinationsForForward
// returns [] when race is disabled.

namespace Leikwan\Master\Database {
    final class Database
    {
        public int $raceEnabled = 1;
        /** @var array<int, array<string,mixed>> */
        public array $routeCombos = [];
        /** @var array<string,mixed> */
        public array $sidecarPoint = [];

        public function seed(string $table, array $rows): void {}

        public function fetchOne(string $sql, array $params = []): ?array
        {
            if (str_contains($sql, 'forward_routes') && str_contains($sql, 'race_enabled')) {
                return ['race_enabled' => $this->raceEnabled];
            }
            if (str_contains($sql, 'sidecar_points')) {
                return $this->sidecarPoint;
            }
            if (str_contains($sql, 'forward_combinations') && str_contains($sql, 'WHERE id')) {
                $id = (int) ($params['id'] ?? 0);
                foreach ($this->routeCombos as $c) {
                    if ((int) ($c['id'] ?? 0) === $id) { return $c; }
                }
            }
            return null;
        }

        public function fetchAll(string $sql, array $params = []): array
        {
            if (str_contains($sql, 'forward_route_combinations')) {
                return $this->routeCombos;
            }
            return [];
        }

        public function execute(string $sql, array $params = []): int { return 0; }
        public function insert(string $table, array $row): int { return 1; }
    }
}

namespace {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Leikwan\\Master\\';
        if (!str_starts_with($class, $prefix) || $class === 'Leikwan\\Master\\Database\\Database') {
            return;
        }
        $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    use Leikwan\Master\Config;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\ProvisioningService;

    function fail(string $m): never { fwrite(STDERR, $m . PHP_EOL); exit(1); }
    function call_private(object $o, string $m, mixed ...$a): mixed
    {
        $r = new ReflectionMethod($o, $m); $r->setAccessible(true); return $r->invoke($o, ...$a);
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-route-race-env-');
    file_put_contents($envPath, "DEFAULT_TLS_CERT_REF=default-edge-cert\n");

    $db = new Database();
    // Two combos on the SAME sidecar point: cnix (self_leg=cnix) and leigod (self_leg=leigod).
    $common = [
        'sidecar_point_id' => 4, 'backend_mode' => 'current_mode', 'client_mode' => 'bond',
        'node_group_key' => 'sha', 'bond_ingress_mode' => 'plain_tcp_mux', 'is_active' => 1,
        'runtime_preference' => 'single',
    ];
    $db->routeCombos = [
        ['id' => 10, 'self_leg_outer_transport' => 'cnix', 'leigod_line_id' => null, 'sort_order' => 1] + $common,
        ['id' => 11, 'self_leg_outer_transport' => 'leigod', 'leigod_line_id' => 451, 'sort_order' => 2,
         'leigod_socks_user' => 'wguot', 'leigod_socks_pass' => 's3cret'] + $common,
    ];
    $db->sidecarPoint = [
        'sidecar_host' => '1.2.3.4', 'listen_port' => 9000, 'bond_listen_port' => 9100,
        'mptcp_listen_port' => 9200, 'cf_listen_port' => 443,
        'agent_config_json' => json_encode(['wireguard_public_key' => 'SIDECARPUBKEY=']),
    ];
    $svc = new ProvisioningService(new Config($envPath), $db);

    $forward = ['id' => 1, 'forward_route_id' => 9, 'forward_combination_id' => 10, 'bond_sidecar_point_id' => 4];
    $node = ['id' => 7, 'node_id' => 7, 'node_group_key' => 'sha',
             'agent_config_json' => json_encode(['wireguard_public_key' => 'NODEPUBKEY='])];

    // race ON -> 2 candidate combos.
    $combos = call_private($svc, 'enabledRouteCombinationsForForward', $forward);
    if (count($combos) !== 2) { fail('enabledRouteCombinationsForForward expected 2, got ' . count($combos)); }

    // race OFF -> [].
    $db->raceEnabled = 0;
    if (call_private($svc, 'enabledRouteCombinationsForForward', $forward) !== []) {
        fail('race OFF must yield no route candidates');
    }
    $db->raceEnabled = 1;

    // staticSidecarUplinksForForwardNode returns one primary uplink per combo, tagged + distinct.
    $uplinks = call_private($svc, 'staticSidecarUplinksForForwardNode', $forward, $node);
    if (count($uplinks) !== 2) { fail('expected 2 race uplinks, got ' . count($uplinks)); }
    $ids = array_map(static fn(array $u): int => (int) ($u['forward_combination_id'] ?? 0), $uplinks);
    sort($ids);
    if ($ids !== [10, 11]) { fail('expected forward_combination_id {10,11}, got ' . json_encode($ids)); }
    $ifaces = array_map(static fn(array $u): string => (string) ($u['interface_name'] ?? ''), $uplinks);
    if ($ifaces[0] === $ifaces[1] || $ifaces[0] === '' || $ifaces[1] === '') {
        fail('expected distinct non-empty interface_name per candidate, got ' . json_encode($ifaces));
    }
    // The leigod candidate carries its gost keys (proves per-combo basis built correctly).
    $leigod = array_values(array_filter($uplinks, static fn(array $u): bool => (int) ($u['forward_combination_id'] ?? 0) === 11))[0];
    if (($leigod['outer_transport'] ?? '') !== 'leigod') { fail('combo 11 must be a leigod uplink'); }

    echo "ok\n";
}
