<?php
declare(strict_types=1);

/**
 * Reports knowledgebase articles and threads that name real server addresses.
 *
 * Saving through KnowledgebaseService already refuses such content; this
 * audit covers rows written before the guard existed or by seed migrations.
 * Read-only. Exits 1 when anything is found.
 *
 *   php scripts/audit_knowledgebase_addresses.php
 */

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

require_once $baseDir . '/src/Bootstrap.php';

use Leikwan\Master\Bootstrap;
use Leikwan\Master\Support\KnowledgebaseAddressGuard;

$app = new Bootstrap($baseDir . '/.env');
$db = $app->db();
$guard = new KnowledgebaseAddressGuard($db);

$rows = [];
foreach ($db->fetchAll(
    'SELECT a.id, t.topic_key, a.locale, a.status, a.title, a.excerpt, a.body_html
     FROM knowledgebase_articles a
     JOIN knowledgebase_topics t ON t.id = a.topic_id
     ORDER BY a.id'
) as $row) {
    $rows[] = ['article ' . $row['id'] . ' ' . $row['topic_key'] . ' ' . $row['locale'] . ' (' . $row['status'] . ')', $row];
}
foreach ($db->fetchAll('SELECT id, slug, status, title, excerpt, body_html FROM knowledgebase_threads ORDER BY id') as $row) {
    $rows[] = ['thread ' . $row['id'] . ' ' . $row['slug'] . ' (' . $row['status'] . ')', $row];
}

$found = 0;
foreach ($rows as [$label, $row]) {
    $leaks = $guard->findLeaks((string) $row['title'], (string) $row['excerpt'], (string) $row['body_html']);
    if ($leaks !== []) {
        $found++;
        echo $label . ': ' . implode(', ', $leaks) . "\n";
    }
}

echo 'checked ' . count($rows) . ' rows, ' . $found . " with real addresses\n";
exit($found > 0 ? 1 : 0);
