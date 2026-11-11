<?php
declare(strict_types=1);

// Operator CLI for Route Directions until the admin console page ships.
//
//   php master/scripts/route_directions.php list
//   php master/scripts/route_directions.php save --id dir_sh_jp_1 --label 沪日1 [--status available]
//       [--sort 10] [--forward 408 --port 12001]      (port = the shared TCP+UDP local listener)
//   php master/scripts/route_directions.php entitle --plan 7 --directions dir_sh_jp_1,dir_hk_1 [--max-rules 5]
//
// A direction is only "ready" for devices with --forward and --port; without them it is
// listed but its rules stay runtime_missing (devices reject that traffic).

use Leikwan\Master\Bootstrap;
use Leikwan\Master\Support\ApiException;

require_once __DIR__ . '/../src/Bootstrap.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

$command = $argv[1] ?? '';
$args = [];
for ($i = 2; $i < $argc; $i += 2) {
    $key = $argv[$i] ?? '';
    if (str_starts_with($key, '--')) {
        $args[substr($key, 2)] = (string) ($argv[$i + 1] ?? '');
    }
}

$usage = "Usage: php master/scripts/route_directions.php list | save --id dir_x --label L [--status available] [--sort 100] [--forward ID --port N] | entitle --plan ID --directions a,b [--max-rules 5]\n";
if (!in_array($command, ['list', 'save', 'entitle'], true)) {
    fwrite(STDERR, $usage);
    exit(1);
}

$bootstrap = new Bootstrap(__DIR__ . '/../.env');
$directions = $bootstrap->provisioning()->routeDirections();

try {
    if ($command === 'list') {
        foreach ($directions->listDirections(true) as $direction) {
            $realization = is_array($direction['realization'] ?? null) ? $direction['realization'] : [];
            printf(
                "%s\t%s\t%s\tforward=%s port=%s state=%s rules=%d\n",
                $direction['id'],
                $direction['label'],
                $direction['status'],
                (string) ($realization['forward_id'] ?? '-'),
                (string) ($realization['local_tcp_port'] ?? '-'),
                (string) ($realization['binding_state'] ?? 'none'),
                (int) ($direction['usage']['rules'] ?? 0)
            );
        }
        exit(0);
    }

    if ($command === 'save') {
        $payload = [
            'id' => $args['id'] ?? '',
            'label' => $args['label'] ?? '',
            'status' => $args['status'] ?? 'available',
            'sort_order' => (int) ($args['sort'] ?? 100),
        ];
        $forwardId = (int) ($args['forward'] ?? 0);
        $port = (int) ($args['port'] ?? 0);
        if ($forwardId > 0 || $port > 0) {
            if ($forwardId <= 0 || $port < 1 || $port > 65535) {
                fwrite(STDERR, "--forward and --port must be given together (port 1-65535)\n");
                exit(1);
            }
            $payload['realization'] = [
                'binding_state' => 'ready',
                'forward_id' => $forwardId,
                'local_endpoint_host' => '127.0.0.1',
                'local_tcp_port' => $port,
                'local_udp_port' => $port,
                'tcp_ready' => true,
                'udp_ready' => true,
                'helper_only' => true,
            ];
        }
        $saved = $directions->saveDirection($payload);
        echo 'saved ' . $saved['id'] . ' (' . $saved['label'] . ', ' . $saved['status'] . ")\n";
        exit(0);
    }

    $planId = (int) ($args['plan'] ?? 0);
    $ids = array_values(array_filter(array_map('trim', explode(',', $args['directions'] ?? ''))));
    if ($planId <= 0) {
        fwrite(STDERR, $usage);
        exit(1);
    }
    $directions->savePlanEntitlements($planId, $ids, (int) ($args['max-rules'] ?? 5));
    echo "plan {$planId} -> " . implode(',', $directions->allowedDirectionIdsForPlan($planId)) . "\n";
} catch (ApiException $exception) {
    fwrite(STDERR, 'error: ' . $exception->getMessage() . "\n");
    exit(1);
}
