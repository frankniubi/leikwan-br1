<?php
declare(strict_types=1);

function failAndroidUltraContract(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertAndroidUltraContract(bool $condition, string $message): void
{
    if (!$condition) {
        failAndroidUltraContract($message);
    }
}

$controller = (string) file_get_contents(__DIR__ . '/../src/Controllers/ClientAgentController.php');
$leaseService = (string) file_get_contents(__DIR__ . '/../src/Support/UltraLeaseService.php');
$bootstrap = (string) file_get_contents(__DIR__ . '/../src/Bootstrap.php');

assertAndroidUltraContract(
    str_contains($controller, '$this->enforceClientRequest($client, $request, \'lease\')'),
    'Ultra lease endpoint must require an active client device session'
);
assertAndroidUltraContract(
    str_contains($controller, '$this->isAndroidRequest($request)'),
    'trusted Android requests must receive policy-v2 Ultra grants'
);
assertAndroidUltraContract(
    str_contains($controller, "str_starts_with(\$platform, 'windows')")
        && str_contains($controller, "str_starts_with(\$platform, 'linux')"),
    'desktop policy-v2 grant compatibility regressed'
);
assertAndroidUltraContract(
    str_contains($bootstrap, "UltraMode::LEASE_ENDPOINT, [\$clientAgent, 'resolveUltraLease']"),
    'Ultra lease route is not registered'
);
assertAndroidUltraContract(
    str_contains($leaseService, 'directIpCidrsForCandidate')
        && str_contains($leaseService, "'direct_ip_cidrs'"),
    'lease output must carry validated direct provider CIDRs'
);
assertAndroidUltraContract(
    str_contains($leaseService, 'candidateDirectCidrsAreValid')
        && str_contains($leaseService, 'normalizeDirectIpCidr'),
    'lease provider CIDRs are not validated narrowly'
);
assertAndroidUltraContract(
    !str_contains($leaseService, "'gateway'"),
    'Android Ultra contract introduced gateway semantics'
);

echo "ok\n";
