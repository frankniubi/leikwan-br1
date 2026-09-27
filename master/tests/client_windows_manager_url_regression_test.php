<?php
declare(strict_types=1);

namespace Leikwan\Master\Database {
    final class Database
    {
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
    use Leikwan\Master\Controllers\BaseController;
    use Leikwan\Master\Database\Database;

    function fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    final class BaseControllerProbe extends BaseController
    {
        public function managerUrlForClient(array $client): string
        {
            return $this->clientWindowsManagerUrlForClient($client);
        }

        public function linuxInstallCommandForClient(array $client): string
        {
            return $this->clientAgentInstallCommand($client);
        }
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-base-controller-');
    if ($envPath === false) {
        fail('failed to create temp env');
    }
    file_put_contents($envPath, "AGENT_API_URL=https://panel.example.com\nARTIFACT_BASE_URL=https://artifacts.example.com\n");

    $controller = new BaseControllerProbe(new Config($envPath), new Database());

    $url = $controller->managerUrlForClient([
        'id' => 42,
        'agent_token' => 'tok-42',
    ]);

    if (!str_contains($url, '/artifacts/manage-client.ps1?')) {
        fail('expected client-specific manager url to include query string');
    }
    if (!str_contains($url, 'client_id=42')) {
        fail('expected client-specific manager url to include client_id');
    }
    if (!str_contains($url, 'agent_token=tok-42')) {
        fail('expected client-specific manager url to include agent_token');
    }

    $fallback = $controller->managerUrlForClient([
        'id' => 0,
        'agent_token' => '',
    ]);

    if ($fallback !== 'https://artifacts.example.com/artifacts/manage-client.ps1') {
        fail('expected fallback manager url without query string');
    }

    $linuxCommand = $controller->linuxInstallCommandForClient([
        'id' => 42,
        'agent_token' => 'tok-42',
    ]);
    if (!str_contains($linuxCommand, 'artifacts/install-client-agent.sh')) {
        fail('expected Linux installer download to use artifact base URL');
    }
    if (!str_starts_with($linuxCommand, "curl -fsSL 'https://artifacts.example.com/artifacts/install-client-agent.sh' | bash -s --")) {
        fail('expected Linux installer command to use the concise artifact bootstrap');
    }
    if (!str_contains($linuxCommand, "--master 'https://panel.example.com' --artifact-base 'https://artifacts.example.com'")) {
        fail('expected Linux installer command to pass separately quoted panel and artifact base URLs');
    }
    if (!str_contains($linuxCommand, '--client-id 42')) {
        fail('expected Linux installer command to pass the client id');
    }
    if (!str_contains($linuxCommand, "--agent-token 'tok-42'")) {
        fail('expected Linux installer command to quote the agent token');
    }
    if (strlen($linuxCommand) >= 500) {
        fail('expected Linux installer command to stay concise');
    }
    foreach (['apt-get', 'sha256sum -c', 'manifest=$(mktemp)', 'required Debian command'] as $forbidden) {
        if (str_contains($linuxCommand, $forbidden)) {
            fail('did not expect long dependency or manifest preflight in the displayed command: ' . $forbidden);
        }
    }

    $sameEnvPath = tempnam(sys_get_temp_dir(), 'lw-base-controller-same-');
    if ($sameEnvPath === false) {
        fail('failed to create same-base temp env');
    }
    file_put_contents($sameEnvPath, "AGENT_API_URL=https://panel.example.com\nARTIFACT_BASE_URL=https://panel.example.com\n");
    $sameController = new BaseControllerProbe(new Config($sameEnvPath), new Database());
    $sameBaseCommand = $sameController->linuxInstallCommandForClient([
        'id' => 42,
        'agent_token' => 'tok-42',
    ]);
    if (str_contains($sameBaseCommand, '--artifact-base')) {
        fail('expected the concise command to omit a redundant artifact base');
    }
    if (!str_contains($sameBaseCommand, "--master 'https://panel.example.com' --client-id 42")) {
        fail('expected the concise command to keep master and client id arguments');
    }

    // The production Cloudflare base retries through the Aliyun CDN, so a
    // mainland-only host can still install; the script is saved before it runs.
    $cfEnvPath = tempnam(sys_get_temp_dir(), 'lw-base-controller-cf-');
    if ($cfEnvPath === false) {
        fail('failed to create Cloudflare temp env');
    }
    file_put_contents($cfEnvPath, "APP_URL=https://v3.leikwanhost.com\nARTIFACT_BASE_URL=https://v3.leikwanhost.com\n");
    $cfController = new BaseControllerProbe(new Config($cfEnvPath), new Database());
    $cfCommand = $cfController->linuxInstallCommandForClient([
        'id' => 42,
        'agent_token' => 'tok-42',
    ]);
    $expected = 'f="$(mktemp)" && { curl -fsSL --connect-timeout 10 \'https://v3.leikwanhost.com/artifacts/install-client-agent.sh\' -o "$f"'
        . ' || curl -fsSL --connect-timeout 10 \'https://api-leikwanbridge.nbwly.com/artifacts/install-client-agent.sh\' -o "$f"; }'
        . ' && bash "$f" --master \'https://v3.leikwanhost.com\' --client-id 42 --agent-token \'tok-42\'; rm -f "$f"';
    if ($cfCommand !== $expected) {
        fail('expected the Cloudflare install command to fall back to the Aliyun CDN: ' . $cfCommand);
    }
    if (strlen($cfCommand) >= 500) {
        fail('expected the fallback install command to stay concise');
    }

    echo "ok\n";
}
