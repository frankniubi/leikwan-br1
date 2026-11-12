<?php
declare(strict_types=1);

// The Windows headless one-liner on the tenant console must work when pasted
// into a fresh Windows 11 PowerShell: the published zip's top folder is
// leikwan-bridge-headless-win-x64 (the old command cd'd into a folder that does
// not exist), and client editions default to the Restricted execution policy.

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

    final class WindowsHeadlessCommandProbe extends BaseController
    {
        public function command(array $client): string
        {
            return $this->windowsHeadlessInstallCommand($client);
        }
    }

    $envPath = tempnam(sys_get_temp_dir(), 'lw-win-headless-');
    if ($envPath === false) {
        fail('failed to create temp env');
    }
    file_put_contents($envPath, "AGENT_API_URL=https://panel.example.com\nARTIFACT_BASE_URL=https://artifacts.example.com\n");

    $controller = new WindowsHeadlessCommandProbe(new Config($envPath), new Database());
    $command = $controller->command(['id' => 42, 'agent_token' => 'tok_abc123']);
    @unlink($envPath);

    if (str_contains($command, '\\leikwan-bridge-headless"')) {
        fail('command still cds into a fixed leikwan-bridge-headless folder: ' . $command);
    }
    if (!str_contains($command, '-Recurse -Filter install-windows-headless.ps1')) {
        fail('command must locate install-windows-headless.ps1 inside the extracted zip: ' . $command);
    }
    if (!str_contains($command, 'Set-ExecutionPolicy Bypass -Scope Process -Force; & $s ')) {
        fail('command must relax the execution policy for this process and invoke the installer with &: ' . $command);
    }
    if (str_contains($command, '-File $s')) {
        fail('powershell -File leaves $PSScriptRoot empty in the installer parameter defaults: ' . $command);
    }
    if (preg_match("#iwr 'https://artifacts\\.example\\.com/artifacts/windows/leikwan-bridge-headless-win-x64\\.zip(\\?[^']*)?' -OutFile \\\$z -UseBasicParsing#", $command) !== 1) {
        fail('command must download the published headless zip: ' . $command);
    }
    if (!str_ends_with($command, "-Panel https://panel.example.com -ClientId 42 -AgentToken 'tok_abc123'")) {
        fail('command must pass the panel, client id and agent token: ' . $command);
    }

    echo "windows headless install command ok\n";
}
