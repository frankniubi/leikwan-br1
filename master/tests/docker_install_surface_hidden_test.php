<?php
declare(strict_types=1);

// Exercise the final HTML composition with isolated platform fixtures. This
// keeps Docker commands out of the response rather than merely hiding via CSS.
$source = (string) file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
$start = strpos($source, '$body = \'<div class="tenant-client-install">\'');
$end = strpos($source, ". '</div>';", $start === false ? 0 : $start);
if ($start === false || $end === false) {
    throw new RuntimeException('client install body not found');
}
$expression = substr($source, $start, $end + strlen(". '</div>';") - $start);
$probe = new class {
    public function platformIcon(string $platform): string { return $platform; }
    public function escape(string $value): string { return htmlspecialchars($value); }
    public function clientAppReleaseLabel(string $label, string $key): string { return $label; }
    public function renderCopyButton(...$args): string { return 'copy'; }
    public function renderCommandLink(...$args): string { return 'download'; }
    public function androidClientApkUrl(): string { return '/android.apk'; }
};
$render = function () use ($expression): string {
    $installCommand = 'linux-install';
    $resetForm = '';
    $windowsSection = '<section>Windows-fixture</section>';
    $macosSection = '<section>macOS-fixture</section>';
    $dockerSection = '<section>Docker-forbidden /artifacts/install-docker-client.sh docker-compose.yml</section>';
    $onlineCount = 0;
    $onlineLimit = 1;
    $credentialPanel = '';
    $onlineList = '';
    eval($expression);
    return $body;
};
$html = $render->call($probe);
foreach (['Docker-forbidden', 'install-docker-client.sh', 'docker-compose.yml'] as $forbidden) {
    if (str_contains($html, $forbidden)) {
        throw new RuntimeException('Docker surface remains visible: ' . $forbidden);
    }
}
foreach (['Linux (x86_64 / arm64)', 'Windows-fixture', 'macOS-fixture', 'Android (Unstable)'] as $required) {
    if (!str_contains($html, $required)) {
        throw new RuntimeException('Other platform removed: ' . $required);
    }
}
echo "PASS: Docker HTML omitted; Linux, Windows, macOS, Android retained\n";
