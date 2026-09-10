<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$schema = (string) file_get_contents(__DIR__ . '/../migrations/schema.sql');
$migration = (string) file_get_contents(__DIR__ . '/../migrations/20260826_add_plan_forward_rule_quantity.sql');
$admin = (string) file_get_contents(__DIR__ . '/../src/Controllers/AdminController.php');
$provisioning = (string) file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
$tenant = (string) file_get_contents(__DIR__ . '/../src/Controllers/TenantController.php');
$forwardApi = (string) file_get_contents(__DIR__ . '/../src/Controllers/ForwardLineApiController.php');
$legacyApi = (string) file_get_contents(__DIR__ . '/../src/Controllers/ServiceController.php');

foreach ([
    'max_forward_rule_quantity INT NOT NULL DEFAULT 64',
] as $needle) {
    if (!str_contains($schema, $needle)) {
        fail('schema is missing forward rule quantity field: ' . $needle);
    }
}
if (!str_contains($migration, 'ADD COLUMN max_forward_rule_quantity INT NOT NULL DEFAULT 64')) {
    fail('missing forward rule quantity migration');
}

foreach ([
    'name="max_forward_rule_quantity"',
    "'max_forward_rule_quantity' => \$request->input('max_forward_rule_quantity', '64')",
] as $needle) {
    if (!str_contains($admin, $needle)) {
        fail('admin plan form is missing forward rule quantity control: ' . $needle);
    }
}

foreach ([
    "\$maxForwardRuleQuantity = max(1, min(100000, (int) (\$payload['max_forward_rule_quantity'] ?? 64)));",
    'max_forward_rule_quantity = :max_forward_rule_quantity',
    "'max_forward_rule_quantity' => \$maxForwardRuleQuantity",
] as $needle) {
    if (!str_contains($provisioning, $needle)) {
        fail('plan persistence is missing forward rule quantity handling: ' . $needle);
    }
}

foreach ([
    'forwardLimitForService($serviceId)',
    'forward_quota_exceeded',
] as $needle) {
    if (!str_contains($tenant, $needle)) {
        fail('tenant forward creation is missing plan quota enforcement: ' . $needle);
    }
}

foreach ([[$forwardApi, 'v1 API'], [$legacyApi, 'legacy API']] as [$source, $label]) {
    if (!str_contains($source, 'forwardLimitForService($serviceId)')
        || !str_contains($source, '$forwardLimit')) {
        fail($label . ' forward creation is missing plan quota enforcement');
    }
}

foreach ([
    'Client ID',
    'Agent Token',
    "renderCopyButton('Copy Client ID'",
    "renderCopyButton('Copy Agent Token'",
] as $needle) {
    if (!str_contains($tenant, $needle)) {
        fail('tenant clients page is missing Windows credential display: ' . $needle);
    }
}

echo "ok\n";
