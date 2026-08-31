<?php
declare(strict_types=1);

function debugContractFail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$store = (string) file_get_contents(__DIR__ . '/../src/Support/DebugCollectionStore.php');
$controller = (string) file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
$bootstrap = (string) file_get_contents(__DIR__ . '/../src/Bootstrap.php');
$migration = (string) file_get_contents(__DIR__ . '/../migrations/20260831_add_debug_collection.sql');
$admin = (string) file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
$tenant = (string) file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');

foreach ([
    'public const BYTE_LIMIT = 104857600',
    'public const MAX_CHUNK_BYTES = 262144',
    'function append',
    'hash_equals',
    'debug_collection_expires_at',
    'debug_collection_quota_exceeded',
    'client_debug_chunks',
    'transaction(function',
] as $needle) {
    if (!str_contains($store, $needle)) {
        debugContractFail('store missing contract: ' . $needle);
    }
}
foreach (['debug_collection_uuid', 'debug_collection_bytes', 'debug_collection_next_sequence', 'client_debug_chunks'] as $needle) {
    if (!str_contains($migration, $needle)) {
        debugContractFail('migration missing field/table: ' . $needle);
    }
}
foreach ([
    'Auth::requireClientAgent($request, $this->db)',
    'enforceClientRequest($client, $request, \'pull\')',
    'DebugCollectionStore',
    "'/api/client/debug/log'",
    "'debug_collection'",
] as $needle) {
    if (!str_contains($controller . $bootstrap, $needle)) {
        debugContractFail('controller route/auth contract missing: ' . $needle);
    }
}
foreach ([$admin, $tenant] as $source) {
    if (!str_contains($source, '->create($clientId)')) {
        debugContractFail('diagnostic request does not create a collection');
    }
}

echo "ok\n";
