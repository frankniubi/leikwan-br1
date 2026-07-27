<?php
declare(strict_types=1);

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Support\ProvisioningService;

/** @return array<int, array{line_id:int,label:string}> */
function flexibleLeigodSeedInitialLines(): array
{
    return [
        ['line_id' => 451, 'label' => 'Shanghai to Korea'],
        ['line_id' => 453, 'label' => 'Shanghai to Japan'],
        ['line_id' => 456, 'label' => 'Beijing to Russia'],
        ['line_id' => 455, 'label' => 'Beijing to Germany'],
        ['line_id' => 447, 'label' => 'Guangzhou to Hong Kong'],
    ];
}

/**
 * @param array<int, string> $argv
 * @return array<string, mixed>
 */
function flexibleLeigodSeedParseArguments(array $argv): array
{
    $options = [
        'apply' => false,
        'activate' => false,
        'help' => false,
        'node_group' => 'shanghai-alive',
        'tcp_probe_host' => '162.245.221.78',
        'tcp_probe_port' => 80,
        'udp_probe_host' => null,
        'udp_probe_port' => null,
        'udp_probe_method' => null,
    ];
    $valueOptions = [
        'node-group' => 'node_group',
        'tcp-probe-host' => 'tcp_probe_host',
        'tcp-probe-port' => 'tcp_probe_port',
        'udp-probe-host' => 'udp_probe_host',
        'udp-probe-port' => 'udp_probe_port',
        'udp-probe-method' => 'udp_probe_method',
    ];

    for ($index = 1, $count = count($argv); $index < $count; $index++) {
        $argument = (string) $argv[$index];
        if ($argument === '--apply') {
            $options['apply'] = true;
            continue;
        }
        if ($argument === '--activate') {
            $options['activate'] = true;
            continue;
        }
        if ($argument === '--help' || $argument === '-h') {
            $options['help'] = true;
            continue;
        }
        if (!str_starts_with($argument, '--')) {
            throw new InvalidArgumentException('unknown_argument');
        }

        $pair = explode('=', substr($argument, 2), 2);
        $name = $pair[0];
        if (!isset($valueOptions[$name])) {
            throw new InvalidArgumentException('unknown_option_' . str_replace('-', '_', $name));
        }
        if (count($pair) === 2) {
            $value = $pair[1];
        } else {
            $index++;
            if ($index >= $count || str_starts_with((string) $argv[$index], '--')) {
                throw new InvalidArgumentException('missing_option_value_' . str_replace('-', '_', $name));
            }
            $value = (string) $argv[$index];
        }
        $options[$valueOptions[$name]] = $value;
    }

    $options['node_group'] = trim((string) $options['node_group']);
    if ($options['node_group'] === '' || strlen($options['node_group']) > 191
        || preg_match('/^[A-Za-z0-9._:-]+$/', $options['node_group']) !== 1) {
        throw new InvalidArgumentException('invalid_node_group');
    }

    $options['tcp_probe_host'] = flexibleLeigodSeedValidateProbeHost(
        (string) $options['tcp_probe_host'],
        'tcp'
    );
    $options['tcp_probe_port'] = flexibleLeigodSeedValidatePort($options['tcp_probe_port'], 'tcp');

    $udpHost = trim((string) ($options['udp_probe_host'] ?? ''));
    $udpPort = trim((string) ($options['udp_probe_port'] ?? ''));
    $udpMethod = strtolower(trim((string) ($options['udp_probe_method'] ?? '')));
    $hasUdp = $udpHost !== '' || $udpPort !== '' || $udpMethod !== '';
    if ($hasUdp) {
        $options['udp_probe_host'] = flexibleLeigodSeedValidateProbeHost($udpHost, 'udp');
        $options['udp_probe_port'] = flexibleLeigodSeedValidatePort($udpPort, 'udp');
        if (!in_array($udpMethod, ['dns', 'echo'], true)) {
            throw new InvalidArgumentException('invalid_udp_probe_method');
        }
        $options['udp_probe_method'] = $udpMethod;
    } else {
        $options['udp_probe_host'] = null;
        $options['udp_probe_port'] = null;
        $options['udp_probe_method'] = null;
    }

    return $options;
}

function flexibleLeigodSeedValidateProbeHost(string $host, string $transport): string
{
    $host = trim($host);
    if ($host === '' || strlen($host) > 255 || preg_match('/[\x00-\x20\x7f]/', $host) === 1) {
        throw new InvalidArgumentException('invalid_' . $transport . '_probe_host');
    }
    return $host;
}

function flexibleLeigodSeedValidatePort(mixed $port, string $transport): int
{
    $portText = trim((string) $port);
    if (preg_match('/^[0-9]+$/', $portText) !== 1) {
        throw new InvalidArgumentException('invalid_' . $transport . '_probe_port');
    }
    $normalized = (int) $portText;
    if ($normalized < 1 || $normalized > 65535) {
        throw new InvalidArgumentException('invalid_' . $transport . '_probe_port');
    }
    return $normalized;
}

/**
 * Validate every configuration before opening a write path. The returned plan
 * contains hashes and metadata only; decoded TOML bytes never leave this scope.
 *
 * @return array<int, array<string, mixed>>
 */
function flexibleLeigodSeedValidatedLines(mixed $config, array $options): array
{
    $validated = [];
    foreach (flexibleLeigodSeedInitialLines() as $index => $line) {
        $lineId = $line['line_id'];
        $encoded = trim((string) ($config->get('LEIGOD_LINE_TOML_' . $lineId, '') ?? ''));
        if ($encoded === '') {
            throw new RuntimeException('leigod_line_' . $lineId . '_config_missing');
        }
        $toml = base64_decode($encoded, true);
        if (!is_string($toml) || $toml === '') {
            throw new RuntimeException('leigod_line_' . $lineId . '_config_invalid');
        }

        $expectedHash = strtolower(trim((string) ($config->get('LEIGOD_LINE_SHA256_' . $lineId, '') ?? '')));
        if (preg_match('/^[a-f0-9]{64}$/', $expectedHash) !== 1) {
            throw new RuntimeException('leigod_line_' . $lineId . '_expected_hash_missing');
        }
        $actualHash = hash('sha256', $toml);
        if (!hash_equals($expectedHash, $actualHash)) {
            throw new RuntimeException('leigod_line_' . $lineId . '_hash_mismatch');
        }

        $validated[] = [
            'line_id' => $lineId,
            'label' => $line['label'],
            'config_sha256' => $expectedHash,
            'tcp_probe_host' => $options['tcp_probe_host'],
            'tcp_probe_port' => $options['tcp_probe_port'],
            'udp_probe_host' => $options['udp_probe_host'],
            'udp_probe_port' => $options['udp_probe_port'],
            'udp_probe_method' => $options['udp_probe_method'],
            'sort_order' => ($index + 1) * 10,
        ];
    }
    return $validated;
}

function flexibleLeigodSeedCombinationName(int $lineId): string
{
    return 'flex-leigod-line-' . $lineId;
}

function flexibleLeigodSeedSingleRouteName(int $lineId): string
{
    return 'flex-leigod-single-' . $lineId;
}

/** @param array<string, mixed> $params */
function flexibleLeigodSeedFindByName(mixed $db, string $table, string $name, array $params = []): ?array
{
    if (!in_array($table, ['forward_combinations', 'forward_routes'], true)) {
        throw new InvalidArgumentException('invalid_seed_table');
    }
    $row = $db->fetchOne(
        'SELECT * FROM ' . $table . ' WHERE name = :name ORDER BY id ASC LIMIT 1',
        ['name' => $name] + $params
    );
    return is_array($row) ? $row : null;
}

/**
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function flexibleLeigodSeedRun(array $options, mixed $config, mixed $db, mixed $provisioning): array
{
    $apply = (bool) ($options['apply'] ?? false);
    $activate = (bool) ($options['activate'] ?? false);
    if ($activate && !$apply) {
        throw new RuntimeException('activate_requires_apply');
    }

    $validatedLines = flexibleLeigodSeedValidatedLines($config, $options);
    $existingLines = [];
    foreach ($provisioning->listLeigodLines() as $line) {
        $lineId = (int) ($line['line_id'] ?? 0);
        if ($lineId > 0 && !isset($existingLines[$lineId])) {
            $existingLines[$lineId] = $line;
        }
    }

    $existingCombinations = [];
    $existingRoutes = [];
    foreach ($validatedLines as $line) {
        $lineId = (int) $line['line_id'];
        $combinationName = flexibleLeigodSeedCombinationName($lineId);
        $singleRouteName = flexibleLeigodSeedSingleRouteName($lineId);
        $existingCombinations[$combinationName] = flexibleLeigodSeedFindByName(
            $db,
            'forward_combinations',
            $combinationName
        );
        $existingRoutes[$singleRouteName] = flexibleLeigodSeedFindByName(
            $db,
            'forward_routes',
            $singleRouteName
        );
    }
    $raceRouteName = 'flex-leigod-race-all-directions';
    $existingRoutes[$raceRouteName] = flexibleLeigodSeedFindByName($db, 'forward_routes', $raceRouteName);

    $summary = [
        'ok' => true,
        'mode' => $apply ? 'apply' : 'dry-run',
        'activate' => $activate,
        'readiness' => ['ready' => count($validatedLines), 'missing' => 0, 'hash_mismatch' => 0],
        'desired' => [
            'catalog_rows' => count($validatedLines),
            'combinations' => count($validatedLines),
            'single_routes' => count($validatedLines),
            'race_routes' => 1,
        ],
        'changes' => [
            'catalog_rows' => flexibleLeigodSeedChangeCounts($validatedLines, $existingLines, 'line_id'),
            'combinations' => flexibleLeigodSeedChangeCountsByPresence($existingCombinations),
            'routes' => flexibleLeigodSeedChangeCountsByPresence($existingRoutes),
        ],
        'writes' => 0,
    ];
    if (!$apply) {
        return $summary;
    }

    $savedLines = [];
    foreach ($validatedLines as $line) {
        $lineId = (int) $line['line_id'];
        $existing = $existingLines[$lineId] ?? null;
        $payload = $line + [
            'notes' => 'Managed by seed-flexible-leigod-routes.php',
            'is_active' => 1,
        ];
        if (is_array($existing) && (int) ($existing['id'] ?? 0) > 0) {
            $payload['id'] = (int) $existing['id'];
        }
        $saved = $provisioning->saveLeigodLine($payload);
        if ((int) ($saved['id'] ?? 0) <= 0 || (string) ($saved['configuration_state'] ?? '') !== 'ready') {
            throw new RuntimeException('leigod_line_' . $lineId . '_not_ready_after_save');
        }
        $savedLines[$lineId] = $saved;
        $summary['writes']++;
    }

    $savedCombinations = [];
    foreach ($validatedLines as $line) {
        $lineId = (int) $line['line_id'];
        $name = flexibleLeigodSeedCombinationName($lineId);
        $existing = $existingCombinations[$name] ?? null;
        $catalogId = (int) ($savedLines[$lineId]['id'] ?? 0);
        $payload = [
            'name' => $name,
            'tenant_label' => (string) $line['label'],
            'tenant_description' => 'Managed route via ' . $line['label'],
            'tenant_badge' => 'Managed TYO',
            'node_group_key' => (string) $options['node_group'],
            'backend_mode' => 'leigod_mode',
            'sidecar_point_id' => 0,
            'cf_location_group_id' => 0,
            'client_mode' => 'single',
            'runtime_preference' => 'single',
            'allow_private_self_leg' => 0,
            'self_leg_scope' => 'none',
            'self_leg_role' => 'none',
            'helper_group_cap' => 1,
            'helper_escalation_policy' => 'disabled',
            'bond_ingress_mode' => 'plain_tcp_mux',
            'leigod_line_id' => $lineId,
            'leigod_lines' => [[
                'leigod_line_id' => $catalogId,
                'enabled' => 1,
                'sort_order' => 10,
            ]],
            'is_active' => 1,
            'sort_order' => (int) $line['sort_order'],
            'notes' => 'Managed by seed-flexible-leigod-routes.php',
        ];
        if (is_array($existing) && (int) ($existing['id'] ?? 0) > 0) {
            $payload['id'] = (int) $existing['id'];
        }
        $saved = $provisioning->saveForwardCombination($payload);
        if ((int) ($saved['id'] ?? 0) <= 0) {
            throw new RuntimeException('combination_' . $lineId . '_save_failed');
        }
        $savedCombinations[$lineId] = $saved;
        $summary['writes']++;
    }

    foreach ($validatedLines as $line) {
        $lineId = (int) $line['line_id'];
        $name = flexibleLeigodSeedSingleRouteName($lineId);
        $existing = $existingRoutes[$name] ?? null;
        $payload = [
            'name' => $name,
            'tenant_label' => (string) $line['label'],
            'tenant_description' => 'Pinned managed direction',
            'tenant_badge' => 'Managed TYO',
            'race_enabled' => 0,
            'is_active' => $activate ? 1 : 0,
            'sort_order' => (int) $line['sort_order'],
            'combinations' => [[
                'forward_combination_id' => (int) $savedCombinations[$lineId]['id'],
                'enabled' => 1,
                'sort_order' => 10,
            ]],
        ];
        if (is_array($existing) && (int) ($existing['id'] ?? 0) > 0) {
            $payload['id'] = (int) $existing['id'];
        }
        $saved = $provisioning->saveForwardRoute($payload);
        if ((int) ($saved['id'] ?? 0) <= 0) {
            throw new RuntimeException('single_route_' . $lineId . '_save_failed');
        }
        $summary['writes']++;
    }

    $raceMemberships = [];
    foreach ($validatedLines as $line) {
        $lineId = (int) $line['line_id'];
        $raceMemberships[] = [
            'forward_combination_id' => (int) $savedCombinations[$lineId]['id'],
            'enabled' => 1,
            'sort_order' => (int) $line['sort_order'],
        ];
    }
    $racePayload = [
        'name' => $raceRouteName,
        'tenant_label' => 'All managed directions',
        'tenant_description' => 'Selects among all healthy managed directions',
        'tenant_badge' => 'Managed TYO',
        'race_enabled' => 1,
        'is_active' => $activate ? 1 : 0,
        'sort_order' => 100,
        'combinations' => $raceMemberships,
    ];
    $existingRace = $existingRoutes[$raceRouteName] ?? null;
    if (is_array($existingRace) && (int) ($existingRace['id'] ?? 0) > 0) {
        $racePayload['id'] = (int) $existingRace['id'];
    }
    $savedRace = $provisioning->saveForwardRoute($racePayload);
    if ((int) ($savedRace['id'] ?? 0) <= 0) {
        throw new RuntimeException('race_route_save_failed');
    }
    $summary['writes']++;

    return $summary;
}

/**
 * @param array<int, array<string, mixed>> $desired
 * @param array<int, array<string, mixed>> $existing
 * @return array{created:int,updated:int}
 */
function flexibleLeigodSeedChangeCounts(array $desired, array $existing, string $key): array
{
    $created = 0;
    $updated = 0;
    foreach ($desired as $row) {
        if (isset($existing[(int) ($row[$key] ?? 0)])) {
            $updated++;
        } else {
            $created++;
        }
    }
    return ['created' => $created, 'updated' => $updated];
}

/**
 * @param array<string, array<string, mixed>|null> $existing
 * @return array{created:int,updated:int}
 */
function flexibleLeigodSeedChangeCountsByPresence(array $existing): array
{
    $updated = count(array_filter($existing, 'is_array'));
    return ['created' => count($existing) - $updated, 'updated' => $updated];
}

/** @param array<string, mixed> $summary */
function flexibleLeigodSeedSummaryJson(array $summary): string
{
    $json = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('summary_encode_failed');
    }
    return $json;
}

function flexibleLeigodSeedUsage(): string
{
    return implode(PHP_EOL, [
        'Usage: php master/cli/seed-flexible-leigod-routes.php [options]',
        '  --apply                     Write the validated catalog, combinations, and routes',
        '  --activate                  Activate routes; requires --apply',
        '  --node-group=KEY            Ingress node group (default: shanghai-alive)',
        '  --tcp-probe-host=HOST       TCP probe target',
        '  --tcp-probe-port=PORT       TCP probe port',
        '  --udp-probe-host=HOST       Optional UDP data-plane probe target',
        '  --udp-probe-port=PORT       Required with a UDP host',
        '  --udp-probe-method=dns|echo Required with a UDP host',
    ]) . PHP_EOL;
}

if (!defined('FLEXIBLE_LEIGOD_SEED_LIBRARY_ONLY')) {
    $baseDir = dirname(__DIR__);
    spl_autoload_register(static function (string $class) use ($baseDir): void {
        $prefix = 'Leikwan\\Master\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $path = $baseDir . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    try {
        $options = flexibleLeigodSeedParseArguments($argv);
        if ((bool) $options['help']) {
            fwrite(STDOUT, flexibleLeigodSeedUsage());
            exit(0);
        }
        $envPath = $baseDir . '/.env';
        if (!is_file($envPath)) {
            throw new RuntimeException('panel_env_missing');
        }
        $config = new Config($envPath);
        $db = new Database($config);
        $provisioning = new ProvisioningService($config, $db);
        fwrite(STDOUT, flexibleLeigodSeedSummaryJson(
            flexibleLeigodSeedRun($options, $config, $db, $provisioning)
        ) . PHP_EOL);
        exit(0);
    } catch (Throwable $throwable) {
        $message = $throwable->getMessage();
        $error = preg_match('/^[a-z0-9_]+$/', $message) === 1 ? $message : 'seed_failed';
        fwrite(STDERR, json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(1);
    }
}
