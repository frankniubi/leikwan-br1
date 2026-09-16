<?php
declare(strict_types=1);
$root = '/www/wwwroot/v3.leikwanhost.com/leikwan-v3';
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'Leikwan\\Master\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = $root . '/master/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

$config = new \Leikwan\Master\Config($root . '/master/.env');
$db = new \Leikwan\Master\Database\Database($config);
$service = new \Leikwan\Master\Support\KnowledgebaseService($db);
foreach (['en', 'zh-Hans', 'zh-TW'] as $locale) {
    $topic = $service->findPublishedTopicByKey('no-gui-clients', $locale);
    printf("kb %-8s %s | chars=%d%s", $locale, $topic === null ? 'MISSING' : (string) $topic['title'], $topic === null ? 0 : strlen((string) $topic['body_html']), PHP_EOL);
}

$probe = new class($config, $db) extends \Leikwan\Master\Controllers\BaseController {
    /** @param array<string, mixed>|null $client */
    public function overview(?array $client): string
    {
        return $this->renderClientAppDownloadOverview($client);
    }

    /** @param array<string, mixed>|null $client */
    public function headless(?array $client): string
    {
        return $this->renderHeadlessClientDownloadPanel($client);
    }
};
$client = ['id' => 42, 'agent_token' => 'redacted'];
$overview = $probe->overview($client);
$panel = $probe->headless($client);
$guide = '/knowledgebase?thread=no-gui-clients';
printf("download centre: guide_href=%s no_gui_panel=%s zip_link=%s%s",
    str_contains($overview, $guide) ? 'yes' : 'NO',
    str_contains($overview, 'No-GUI Client') ? 'yes' : 'NO',
    str_contains($overview, 'leikwan-bridge-headless-win-x64.zip') ? 'yes' : 'NO',
    PHP_EOL);
printf("standalone panel: guide_href=%s install_command=%s%s",
    str_contains($panel, $guide) ? 'yes' : 'NO',
    str_contains($panel, 'install-windows-headless.ps1') ? 'yes' : 'NO',
    PHP_EOL);
$tenant = (string) file_get_contents($root . '/master/src/Controllers/TenantController.php');
printf("tenant clients page: guide_href=%s%s", str_contains($tenant, $guide) ? 'yes' : 'NO', PHP_EOL);
echo 'KB-VERIFY-OK', PHP_EOL;
