<?php
declare(strict_types=1);

// Behavioral regression: the CFWG latency watchdog policy the panel publishes to a client agent
// must default its watched forward_ids to the EMPTY list when CFWG_LATENCY_WATCHDOG_FORWARD_IDS is
// unset/empty. The agent (client-agent/cfwg_latency_watchdog.py :: configured_forward_ids) treats an
// empty forward_ids as "watch ALL active CFWG forwards" — its `if configured and forward_id not in
// configured` pin is skipped when the set is empty. The old default pinned the literal forward 47
// only, silently leaving every other CFWG forward unwatched.
//
// This drives the REAL ClientAgentController emission path (authoritativeAgentConfig, the method
// that serializes the cfwg_latency_watchdog block sent to the client) plus the default policy
// resolver, and asserts on the returned VALUES — never on controller source text:
//   (a) unset/empty env  => forward_ids === [] (NOT [47]); enabled stays true,
//   (b) explicit env list => forward_ids honored verbatim (trimmed + deduped),
//   (c) a client that explicitly stored its own forward_ids has them preserved, not clobbered.

namespace Leikwan\Master\Database {

    final class Database
    {
        /**
         * @param array<string, mixed> $params
         * @return array<string, mixed>|null
         */
        public function fetchOne(string $sql, array $params = []): ?array
        {
            return null;
        }

        /**
         * @param array<string, mixed> $params
         * @return array<int, array<string, mixed>>
         */
        public function fetchAll(string $sql, array $params = []): array
        {
            return [];
        }

        /**
         * @param array<string, mixed> $data
         */
        public function insert(string $table, array $data): int
        {
            return 1;
        }

        /**
         * @param array<string, mixed> $params
         */
        public function execute(string $sql, array $params = []): int
        {
            return 0;
        }

        /**
         * @return mixed
         */
        public function transaction(callable $callback)
        {
            return $callback($this);
        }
    }
}

namespace {

    spl_autoload_register(static function (string $class): void {
        $prefix = 'Leikwan\\Master\\';
        if (!str_starts_with($class, $prefix) || $class === 'Leikwan\\Master\\Database\\Database') {
            return;
        }

        $relative = substr($class, strlen($prefix));
        if ($relative === false) {
            return;
        }

        $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    use Leikwan\Master\Config;
    use Leikwan\Master\Controllers\ClientAgentController;
    use Leikwan\Master\Database\Database;
    use Leikwan\Master\Support\ProvisioningService;

    function fail_client_cfwg_default(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    function call_private(object $instance, string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($instance, $method);
        $ref->setAccessible(true);
        return $ref->invoke($instance, ...$args);
    }

    /**
     * Build a ClientAgentController whose Config sees exactly the supplied watchdog env (or none),
     * isolating the test from any inherited process environment.
     *
     * @return array{0: ClientAgentController}
     */
    function build_client_controller(?string $forwardIdsEnv): array
    {
        // Config::get() consults getenv() first; clear any inherited value so the env FILE is
        // authoritative for this scenario.
        putenv('CFWG_LATENCY_WATCHDOG_FORWARD_IDS');

        $envPath = tempnam(sys_get_temp_dir(), 'lw-cfwg-default-');
        if ($envPath === false) {
            fail_client_cfwg_default('failed to create temp env file');
        }
        $env = "AGENT_API_URL=https://panel.example.com\nARTIFACT_BASE_URL=https://artifacts.example.com\nDEFAULT_TLS_CERT_REF=default-edge-cert\n";
        if ($forwardIdsEnv !== null) {
            $env .= 'CFWG_LATENCY_WATCHDOG_FORWARD_IDS=' . $forwardIdsEnv . "\n";
        }
        file_put_contents($envPath, $env);

        $config = new Config($envPath);
        $db = new Database();
        $provisioning = new ProvisioningService($config, $db);
        return [new ClientAgentController($config, $db, $provisioning)];
    }

    /**
     * Resolve the cfwg_latency_watchdog block the panel would publish for a client whose stored
     * agent config is $storedAgentConfig (associative array, or null for a brand-new client).
     *
     * @param array<string, mixed>|null $storedAgentConfig
     * @return array<string, mixed>
     */
    function published_watchdog(ClientAgentController $controller, ?array $storedAgentConfig): array
    {
        $client = $storedAgentConfig === null
            ? []
            : ['agent_config_json' => json_encode($storedAgentConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        $authoritative = call_private($controller, 'authoritativeAgentConfig', $client);
        if (!is_array($authoritative['cfwg_latency_watchdog'] ?? null)) {
            fail_client_cfwg_default('expected authoritativeAgentConfig to publish a cfwg_latency_watchdog block');
        }
        return $authoritative['cfwg_latency_watchdog'];
    }

    // (a) unset/empty env, brand-new client (no stored override): forward_ids must default empty so
    // the agent watches ALL active CFWG forwards, and must NOT pin the legacy forward 47.
    [$emptyController] = build_client_controller(null);
    $emptyWatchdog = published_watchdog($emptyController, null);
    $emptyForwardIds = $emptyWatchdog['forward_ids'] ?? null;
    if (!is_array($emptyForwardIds)) {
        fail_client_cfwg_default('cfwg watchdog forward_ids must be an array, got ' . var_export($emptyForwardIds, true));
    }
    if ($emptyForwardIds !== []) {
        fail_client_cfwg_default(
            'cfwg watchdog forward_ids must default to the empty list (watch all active forwards), got '
            . var_export($emptyForwardIds, true)
        );
    }
    if (in_array(47, $emptyForwardIds, true)) {
        fail_client_cfwg_default('cfwg watchdog must not pin the legacy forward 47 by default');
    }
    if (($emptyWatchdog['enabled'] ?? null) !== true) {
        fail_client_cfwg_default('cfwg watchdog must remain enabled by default');
    }

    // Explicitly-empty env string must behave identically to unset.
    [$blankController] = build_client_controller('');
    $blankForwardIds = published_watchdog($blankController, null)['forward_ids'] ?? null;
    if ($blankForwardIds !== []) {
        fail_client_cfwg_default(
            'an explicitly empty CFWG_LATENCY_WATCHDOG_FORWARD_IDS must resolve to the empty list, got '
            . var_export($blankForwardIds, true)
        );
    }

    // (b) explicit env list is honored verbatim: trimmed, positive-only, and de-duplicated in order.
    [$explicitController] = build_client_controller('12, 34,34 ,56,0,-3');
    $explicitForwardIds = published_watchdog($explicitController, null)['forward_ids'] ?? null;
    if ($explicitForwardIds !== [12, 34, 56]) {
        fail_client_cfwg_default(
            'an explicit CFWG_LATENCY_WATCHDOG_FORWARD_IDS list must be parsed/deduped and honored, expected [12,34,56] got '
            . var_export($explicitForwardIds, true)
        );
    }

    // (c) a client that explicitly stored its own forward_ids keeps them — the default never
    // clobbers a populated per-client list.
    [$storedController] = build_client_controller(null);
    $storedWatchdog = published_watchdog($storedController, [
        'cfwg_latency_watchdog' => [
            'enabled' => true,
            'forward_ids' => [101, 202],
        ],
    ]);
    if (($storedWatchdog['forward_ids'] ?? null) !== [101, 202]) {
        fail_client_cfwg_default(
            'a client that explicitly stored forward_ids must keep them, got '
            . var_export($storedWatchdog['forward_ids'] ?? null, true)
        );
    }

    echo "ok\n";
}
