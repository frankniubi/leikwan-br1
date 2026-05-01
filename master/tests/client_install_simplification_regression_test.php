<?php
declare(strict_types=1);

function fail(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$repoRoot = dirname(__DIR__, 2);
$baseSource = file_get_contents($repoRoot . '/master/src/Controllers/BaseController.php');
$tenantSource = file_get_contents($repoRoot . '/master/src/Controllers/TenantController.php');
$adminSource = file_get_contents($repoRoot . '/master/src/Controllers/AdminController.php');
$bootstrapSource = file_get_contents($repoRoot . '/master/src/Bootstrap.php');
$artifactSource = file_get_contents($repoRoot . '/master/src/Controllers/ArtifactController.php');

foreach ([
    'BaseController' => $baseSource,
    'TenantController' => $tenantSource,
    'AdminController' => $adminSource,
    'Bootstrap' => $bootstrapSource,
    'ArtifactController' => $artifactSource,
] as $label => $source) {
    if (!is_string($source) || $source === '') {
        fail('failed to read source for ' . $label);
    }
}

$requiredBaseSnippets = [
    'App Download',
    'Client ID',
    'Token',
    'Windows App',
    'Android App',
    'Linux Install',
];

foreach ($requiredBaseSnippets as $snippet) {
    if (!str_contains($baseSource, $snippet)) {
        fail('expected BaseController to contain snippet: ' . $snippet);
    }
}

$requiredPageSnippets = [
    'Advanced client settings',
    'Client ID + Token',
];

foreach ($requiredPageSnippets as $snippet) {
    if (!str_contains($tenantSource, $snippet)) {
        fail('expected TenantController to contain snippet: ' . $snippet);
    }
    if (!str_contains($adminSource, $snippet)) {
        fail('expected AdminController to contain snippet: ' . $snippet);
    }
}

$forbiddenAdminSnippets = [
    'Remote SSH Install',
    '/admin/nodes/install-remote',
    'Install Slave Remotely',
    'public function installClientRemote(Request $request, array $params): Response',
    'public function installNodeRemote(Request $request, array $params): Response',
];

foreach ($forbiddenAdminSnippets as $snippet) {
    if (str_contains($adminSource, $snippet)) {
        fail('did not expect AdminController to contain snippet: ' . $snippet);
    }
}

if (str_contains($tenantSource, 'public function installClientRemote(Request $request, array $params): Response')) {
    fail('did not expect TenantController to contain installClientRemote');
}

$forbiddenBootstrapSnippets = [
    "\$this->router->add('POST', '/admin/nodes/install-remote', [\$admin, 'installNodeRemote']);",
    "\$this->router->add('GET', '/artifacts/remote-install-slave.sh', [\$artifacts, 'remoteInstallSlave']);",
    "\$this->router->add('GET', '/artifacts/remote-install-client.sh', [\$artifacts, 'remoteInstallClient']);",
    "\$this->router->add('GET', '/artifacts/remote-install-client-agent.sh', [\$artifacts, 'remoteInstallClientAgent']);",
];

foreach ($forbiddenBootstrapSnippets as $snippet) {
    if (str_contains($bootstrapSource, $snippet)) {
        fail('did not expect Bootstrap to contain snippet: ' . $snippet);
    }
}

$forbiddenArtifactSnippets = [
    'public function remoteInstallSlave(Request $request, array $params): Response',
    'public function remoteInstallClient(Request $request, array $params): Response',
    'public function remoteInstallClientAgent(Request $request, array $params): Response',
    "return \$this->serveRepoFile('install/remote-install-slave.sh');",
    "return \$this->serveRepoFile('install/remote-install-client.sh');",
    "return \$this->serveRepoFile('install/remote-install-client-agent.sh');",
];

foreach ($forbiddenArtifactSnippets as $snippet) {
    if (str_contains($artifactSource, $snippet)) {
        fail('did not expect ArtifactController to contain snippet: ' . $snippet);
    }
}

echo "ok\n";
