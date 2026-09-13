<?php
declare(strict_types=1);

/**
 * The Windows update-helper line is parsed twice: once by the shell it is
 * pasted into, once by the child powershell that parses the -Command payload.
 * Both of these shipped-broken variants are pinned here.
 *
 * 1. "$h = Join-Path $env:TEMP ..." inside the outer double quotes is expanded
 *    by a PowerShell *parent* before the child ever runs, so the child received
 *    " = Join-Path C:\...\Temp leikwan-bridge-repair.cmd; ..." and the line
 *    could never work in PowerShell at all.
 * 2. The artifact URL always carries "?v=<version>&sha256=<hash>". Unquoted,
 *    the child's parser met a bare & and refused the whole line with
 *    "The ampersand (&) character is not allowed" - the error operators hit.
 *
 * The rule that satisfies cmd, Windows PowerShell and pwsh: no $ in the payload,
 * and the URL wrapped in a single-quoted PowerShell literal.
 */

namespace {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'Leikwan\\Master\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });

    use Leikwan\Master\Config;
    use Leikwan\Master\Controllers\BaseController;
    use Leikwan\Master\Database\Database;

    $failures = [];
    $checks = 0;
    function check(string $label, bool $ok, string $detail = ''): void
    {
        global $failures, $checks;
        $checks++;
        if (!$ok) {
            $failures[] = $label . ($detail !== '' ? ' -- ' . $detail : '');
        }
    }

    $url = 'https://v3.leikwanhost.com/artifacts/leikwan-bridge-repair.cmd?v=2026.09.13.1&sha256=deadbeef';

    $envPath = tempnam(sys_get_temp_dir(), 'lw-helper-env-');
    file_put_contents($envPath, "DB_DRIVER=sqlite\nDB_NAME=:memory:\n");
    $config = new Config($envPath);

    $controller = new class($config, $url) extends BaseController {
        private string $stubUrl;
        public function __construct(Config $config, string $stubUrl)
        {
            parent::__construct($config, new Database($config));
            $this->stubUrl = $stubUrl;
        }
        public function command(): string { return $this->windowsUpdateHelperCommand(); }
        protected function windowsUpdateHelperUrl(): string { return $this->stubUrl; }
    };

    $command = $controller->command();

    check('no $ in the payload (a PowerShell parent would expand it)', !str_contains($command, '$'));
    check('outer argument is a single double-quoted string', str_starts_with($command, 'powershell ') && str_ends_with($command, '"'));
    check('the URL is a single-quoted literal', str_contains($command, "'" . $url . "'"));

    $firstQuote = strpos($command, '"');
    $lastQuote = strrpos($command, '"');
    $payload = substr($command, $firstQuote + 1, $lastQuote - $firstQuote - 1);
    check('payload keeps the full query string', str_contains($payload, '?v=2026.09.13.1&sha256=deadbeef'));

    // Every ampersand must sit inside a single-quoted literal, otherwise the
    // child parser stops at it.
    $outside = preg_replace("/'[^']*'/", '', $payload) ?? '';
    check('no bare ampersand outside quotes', !str_contains($outside, '&'), $outside);

    check('helper is fetched then started elevated', str_contains($payload, 'Invoke-WebRequest')
        && str_contains($payload, '-Verb RunAs'));

    // The temp path has to come from the child, never from the parent.
    check('temp path resolved inside the child', str_contains($payload, '[System.IO.Path]::GetTempPath()'));

    // A URL containing a single quote must not be able to break out of the literal.
    $hostile = new class($config, "https://x/y.cmd?a=1&b='; Start-Process calc; '") extends BaseController {
        private string $stubUrl;
        public function __construct(Config $config, string $stubUrl)
        {
            parent::__construct($config, new Database($config));
            $this->stubUrl = $stubUrl;
        }
        public function command(): string { return $this->windowsUpdateHelperCommand(); }
        protected function windowsUpdateHelperUrl(): string { return $this->stubUrl; }
    };
    $hostileCommand = $hostile->command();
    check(
        'single quotes in the URL are escaped by doubling',
        str_contains($hostileCommand, "a=1&b=''; Start-Process calc; ''")
    );

    @unlink($envPath);

    if ($failures !== []) {
        fwrite(STDERR, "[windows-update-helper-quoting] FAILED\n");
        foreach ($failures as $failure) {
            fwrite(STDERR, '  - ' . $failure . "\n");
        }
        exit(1);
    }
    echo "[windows-update-helper-quoting] ok ({$checks} checks)\n";
}
