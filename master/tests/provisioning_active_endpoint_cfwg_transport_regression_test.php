<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../src/Support/ProvisioningService.php');
if ($source === false) {
    fwrite(STDERR, "failed to read ProvisioningService.php
");
    exit(1);
}

$methodPos = strpos($source, 'public function activeEndpointPublishPayload');
if ($methodPos === false) {
    fwrite(STDERR, "activeEndpointPublishPayload missing
");
    exit(1);
}
$method = substr($source, $methodPos, 2600);
$republishPos = strpos($source, 'public function republishActiveEndpoint');
if ($republishPos === false) {
    fwrite(STDERR, "republishActiveEndpoint missing\n");
    exit(1);
}
$republishMethod = substr($source, $republishPos, 3600);
foreach ([
    'combo.allow_private_self_leg AS forward_combination_allow_private_self_leg',
    'combo.self_leg_cf_transport AS forward_combination_self_leg_cf_transport',
] as $snippet) {
    if (strpos($method, $snippet) === false) {
        fwrite(STDERR, 'active endpoint republish query missing snippet: ' . $snippet . "
");
        exit(1);
    }
}

foreach ([
    '$combinationCfTransport = strtolower(trim((string) ($forward[\'forward_combination_self_leg_cf_transport\'] ?? \'\')));',
    '$selfLegCfTransport = $combinationCfTransport !== \'\' && $combinationCfTransport !== \'cf_direct\'',
] as $snippet) {
    if (strpos($source, $snippet) === false) {
        fwrite(STDERR, 'effectiveMptcpTransport must prefer combo cf_wg over forward cf_direct: ' . $snippet . "
");
        exit(1);
    }
}

if (strpos($source, '\'transport\' => $this->effectiveMptcpTransport($forward)') === false) {
    fwrite(STDERR, "publishTaskPayloadForForward must use effectiveMptcpTransport for MPTCP transport_mode
");
    exit(1);
}

echo "ok
";
