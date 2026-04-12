<?php
declare(strict_types=1);

namespace Leikwan\Master;

use Leikwan\Master\Controllers\AdminController;
use Leikwan\Master\Controllers\ArtifactController;
use Leikwan\Master\Controllers\AuthController;
use Leikwan\Master\Controllers\ClientAgentController;
use Leikwan\Master\Controllers\HealthController;
use Leikwan\Master\Controllers\NodeController;
use Leikwan\Master\Controllers\ServiceController;
use Leikwan\Master\Controllers\SidecarAgentController;
use Leikwan\Master\Controllers\TenantController;
use Leikwan\Master\Controllers\WhmcsController;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;
use Leikwan\Master\Support\MigrationRunner;
use Leikwan\Master\Support\ProvisioningService;
use Leikwan\Master\Support\WhmcsClient;

final class Bootstrap
{
    private Config $config;
    private Database $db;
    private Router $router;
    private ProvisioningService $provisioning;
    private WhmcsClient $whmcsClient;
    private MigrationRunner $migrationRunner;

    public function __construct(string $envPath)
    {
        $this->config = new Config($envPath);
        $this->db = new Database($this->config);
        $this->router = new Router();
        $this->migrationRunner = new MigrationRunner($this->config, $this->db);
        if ($this->config->getBool('AUTO_RUN_MIGRATIONS', true)) {
            $this->migrationRunner->applyPending();
        }
        $this->provisioning = new ProvisioningService($this->config, $this->db);
        $this->whmcsClient = new WhmcsClient($this->config);
        $this->registerRoutes();
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function db(): Database
    {
        return $this->db;
    }

    public function provisioning(): ProvisioningService
    {
        return $this->provisioning;
    }

    public function whmcsClient(): WhmcsClient
    {
        return $this->whmcsClient;
    }

    public function migrationRunner(): MigrationRunner
    {
        return $this->migrationRunner;
    }

    public function handle(Request $request): Response
    {
        return $this->router->dispatch($request);
    }

    private function registerRoutes(): void
    {
        $health = new HealthController($this->config, $this->db);
        $auth = new AuthController($this->config, $this->db);
        $admin = new AdminController($this->config, $this->db, $this->provisioning);
        $whmcs = new WhmcsController($this->config, $this->db, $this->provisioning);
        $services = new ServiceController($this->config, $this->db, $this->provisioning);
        $nodes = new NodeController($this->config, $this->db, $this->provisioning);
        $clientAgent = new ClientAgentController($this->config, $this->db, $this->provisioning);
        $sidecarAgent = new SidecarAgentController($this->config, $this->db, $this->provisioning);
        $tenant = new TenantController($this->config, $this->db, $this->provisioning);
        $artifacts = new ArtifactController($this->config);

        $this->router->add('GET', '/', [$auth, 'home']);
        $this->router->add('GET', '/knowledgebase', [$auth, 'knowledgebasePage']);
        $this->router->add('GET', '/login', [$auth, 'loginPage']);
        $this->router->add('GET', '/register', [$auth, 'registerPage']);
        $this->router->add('GET', '/forgot-password', [$auth, 'forgotPasswordPage']);
        $this->router->add('POST', '/forgot-password', [$auth, 'requestPasswordReset']);
        $this->router->add('GET', '/reset-password', [$auth, 'resetPasswordPage']);
        $this->router->add('POST', '/reset-password', [$auth, 'resetPassword']);
        $this->router->add('GET', '/buy-binding/verify', [$auth, 'buyBindingVerifyPage']);
        $this->router->add('POST', '/register', [$auth, 'register']);
        $this->router->add('GET', '/verify-email', [$auth, 'verifyEmail']);
        $this->router->add('POST', '/login', [$auth, 'login']);
        $this->router->add('POST', '/tenant/billing/bind', [$auth, 'requestBuyBinding']);
        $this->router->add('POST', '/buy-binding/verify', [$auth, 'verifyBuyBinding']);
        $this->router->add('POST', '/logout', [$auth, 'logout']);
        $this->router->add('GET', '/admin', [$admin, 'dashboard']);
        $this->router->add('GET', '/admin/tenants', [$admin, 'tenantsPage']);
        $this->router->add('POST', '/admin/tenants', [$admin, 'saveTenant']);
        $this->router->add('POST', '/admin/tenant-registration/settings', [$admin, 'saveTenantRegistrationSettings']);
        $this->router->add('POST', '/admin/tenant-registration/codes', [$admin, 'createTenantRegistrationCode']);
        $this->router->add('POST', '/admin/tenant-registration/codes/{id}/toggle', [$admin, 'toggleTenantRegistrationCode']);
        $this->router->add('POST', '/admin/tenants/{id}/delete', [$admin, 'deleteTenant']);
        $this->router->add('GET', '/admin/services', [$admin, 'servicesPage']);
        $this->router->add('POST', '/admin/services', [$admin, 'createService']);
        $this->router->add('GET', '/admin/plans', [$admin, 'plansPage']);
        $this->router->add('POST', '/admin/plans', [$admin, 'savePlan']);
        $this->router->add('POST', '/admin/plans/{id}/delete', [$admin, 'deletePlan']);
        $this->router->add('GET', '/admin/client-groups', [$admin, 'clientGroupsPage']);
        $this->router->add('POST', '/admin/client-groups', [$admin, 'saveClientGroup']);
        $this->router->add('GET', '/admin/credentials', [$admin, 'credentialsPage']);
        $this->router->add('POST', '/admin/credentials', [$admin, 'saveCredentialProfile']);
        $this->router->add('POST', '/admin/credentials/ssl-certificates', [$admin, 'saveSslCertificate']);
        $this->router->add('GET', '/admin/clients', [$admin, 'clientsPage']);
        $this->router->add('POST', '/admin/clients', [$admin, 'saveClient']);
        $this->router->add('POST', '/admin/clients/{id}/delete', [$admin, 'deleteClient']);
        $this->router->add('GET', '/admin/sidecars', [$admin, 'sidecarsPage']);
        $this->router->add('POST', '/admin/sidecars', [$admin, 'saveSidecarPoint']);
        $this->router->add('POST', '/admin/sidecars/{id}/delete', [$admin, 'deleteSidecarPoint']);
        $this->router->add('POST', '/admin/sidecars/{id}/request-update', [$admin, 'requestSidecarUpdate']);
        $this->router->add('POST', '/admin/clients/{id}/diagnose', [$admin, 'requestClientDiagnostics']);
        $this->router->add('POST', '/admin/clients/{id}/request-update', [$admin, 'requestClientUpdate']);
        $this->router->add('GET', '/admin/node-groups', [$admin, 'nodeGroupsPage']);
        $this->router->add('POST', '/admin/node-groups', [$admin, 'saveNodeGroup']);
        $this->router->add('GET', '/admin/chains', [$admin, 'chainsPage']);
        $this->router->add('POST', '/admin/chains', [$admin, 'saveChain']);
        $this->router->add('GET', '/admin/nodes', [$admin, 'nodesPage']);
        $this->router->add('POST', '/admin/nodes/install-remote', [$admin, 'installNodeRemote']);
        $this->router->add('POST', '/admin/nodes/{id}/manage', [$admin, 'saveNodeManagement']);
        $this->router->add('POST', '/admin/nodes/{id}/request-update', [$admin, 'requestNodeUpdate']);
        $this->router->add('POST', '/admin/nodes/{id}/delete', [$admin, 'deleteNode']);
        $this->router->add('POST', '/admin/nodes/request-update-selected', [$admin, 'requestSelectedNodeUpdates']);
        $this->router->add('POST', '/admin/nodes/request-update-group', [$admin, 'requestNodeGroupUpdate']);
        $this->router->add('POST', '/admin/edge-defaults', [$admin, 'saveEdgeDefaults']);
        $this->router->add('GET', '/tenant', [$tenant, 'introPage']);
        $this->router->add('GET', '/tenant/account', [$tenant, 'billingPage']);
        $this->router->add('GET', '/tenant/billing', [$tenant, 'billingPage']);
        $this->router->add('POST', '/tenant/account/transfer', [$tenant, 'transferBalance']);
        $this->router->add('POST', '/tenant/billing/transfer', [$tenant, 'transferBalance']);
        $this->router->add('POST', '/tenant/account/bind', [$auth, 'requestBuyBinding']);
        $this->router->add('GET', '/tenant/plans', [$tenant, 'plansPage']);
        $this->router->add('POST', '/tenant/plans/purchase', [$tenant, 'purchasePackage']);
        $this->router->add('POST', '/tenant/billing/purchase', [$tenant, 'purchasePackage']);
        $this->router->add('GET', '/tenant/forwards', [$tenant, 'dashboard']);
        $this->router->add('GET', '/tenant/clients', [$tenant, 'clientsPage']);
        $this->router->add('POST', '/tenant/clients', [$tenant, 'saveClient']);
        $this->router->add('GET', '/tenant/sidecars', [$tenant, 'sidecarsPage']);
        $this->router->add('POST', '/tenant/sidecars', [$tenant, 'saveSidecarPoint']);
        $this->router->add('POST', '/tenant/sidecars/{id}/delete', [$tenant, 'deleteSidecarPoint']);
        $this->router->add('POST', '/tenant/sidecars/{id}/request-update', [$tenant, 'requestSidecarUpdate']);
        $this->router->add('POST', '/tenant/clients/{id}/delete', [$tenant, 'deleteClient']);
        $this->router->add('POST', '/tenant/clients/{id}/diagnose', [$tenant, 'requestClientDiagnostics']);
        $this->router->add('POST', '/tenant/clients/{id}/request-update', [$tenant, 'requestClientUpdate']);
        $this->router->add('GET', '/tenant/services/{id}', [$tenant, 'serviceDetail']);
        $this->router->add('GET', '/tenant/services/{id}/forward-wizard', [$tenant, 'forwardWizard']);
        $this->router->add('POST', '/tenant/services/{id}/forward-wizard', [$tenant, 'forwardWizard']);
        $this->router->add('POST', '/tenant/services/{id}/forwards', [$tenant, 'createForward']);
        $this->router->add('POST', '/tenant/services/{id}/flexible-options', [$tenant, 'updateFlexibleOptions']);
        $this->router->add('POST', '/tenant/services/{id}/reset-traffic', [$tenant, 'resetTraffic']);
        $this->router->add('POST', '/tenant/forwards/{id}/delete', [$tenant, 'deleteForward']);
        $this->router->add('POST', '/tenant/services/{id}/regenerate-client-token', [$tenant, 'regenerateClientToken']);
        $this->router->add('GET', '/api/health', [$health, 'ping']);

        $this->router->add('POST', '/api/whmcs/provision', [$whmcs, 'provision']);
        $this->router->add('POST', '/api/whmcs/renew', [$whmcs, 'renew']);
        $this->router->add('POST', '/api/whmcs/suspend', [$whmcs, 'suspend']);
        $this->router->add('POST', '/api/whmcs/terminate', [$whmcs, 'terminate']);

        $this->router->add('GET', '/api/user/services', [$services, 'listServices']);
        $this->router->add('GET', '/api/user/services/{id}', [$services, 'showService']);
        $this->router->add('POST', '/api/user/services/{id}/forwards', [$services, 'createForward']);
        $this->router->add('PUT', '/api/user/forwards/{id}', [$services, 'updateForward']);
        $this->router->add('DELETE', '/api/user/forwards/{id}', [$services, 'deleteForward']);
        $this->router->add('GET', '/api/user/forwards/{id}/client-config', [$services, 'clientConfig']);
        $this->router->add('POST', '/api/user/services/{id}/regenerate-client-token', [$services, 'regenerateClientToken']);

        $this->router->add('POST', '/api/node/register', [$nodes, 'register']);
        $this->router->add('POST', '/api/node/heartbeat', [$nodes, 'heartbeat']);
        $this->router->add('POST', '/api/node/report-usage', [$nodes, 'reportUsage']);
        $this->router->add('GET', '/api/node/self/duplicates', [$nodes, 'selfDuplicates']);
        $this->router->add('POST', '/api/node/self/deduplicate', [$nodes, 'deduplicate']);
        $this->router->add('GET', '/api/node/tasks/pull', [$nodes, 'pullTasks']);
        $this->router->add('POST', '/api/node/tasks/{id}/ack', [$nodes, 'ackTask']);
        $this->router->add('POST', '/api/node/tasks/{id}/result', [$nodes, 'taskResult']);
        $this->router->add('GET', '/api/admin/nodes', [$nodes, 'listNodes']);
        $this->router->add('GET', '/api/admin/services', [$services, 'listAllServices']);
        $this->router->add('POST', '/api/client/login', [$clientAgent, 'desktopLogin']);
        $this->router->add('GET', '/api/client/configs/pull', [$clientAgent, 'pullConfigs']);
        $this->router->add('POST', '/api/client/heartbeat', [$clientAgent, 'heartbeat']);
        $this->router->add('POST', '/api/client/diagnostics/result', [$clientAgent, 'diagnosticsResult']);
        $this->router->add('POST', '/api/sidecar/heartbeat', [$sidecarAgent, 'heartbeat']);

        $this->router->add('GET', '/artifacts/slave-agent.py', [$artifacts, 'slaveAgent']);
        $this->router->add('GET', '/artifacts/slave-manifest.json', [$artifacts, 'slaveManifest']);
        $this->router->add('GET', '/artifacts/sidecar-manifest.json', [$artifacts, 'sidecarManifest']);
        $this->router->add('GET', '/artifacts/client-manifest.json', [$artifacts, 'clientManifest']);
        $this->router->add('GET', '/artifacts/client-agent.py', [$artifacts, 'clientAgent']);
        $this->router->add('GET', '/artifacts/sidecar-agent.py', [$artifacts, 'sidecarAgent']);
        $this->router->add('GET', '/artifacts/install-forward-runtime.py', [$artifacts, 'installForwardRuntime']);
        $this->router->add('GET', '/artifacts/leikwan-mptcp-client.py', [$artifacts, 'mptcpClientRuntime']);
        $this->router->add('GET', '/artifacts/leikwan-uot.py', [$artifacts, 'uotRuntime']);
        $this->router->add('GET', '/artifacts/leikwan-mptcp-sidecar.py', [$artifacts, 'mptcpSidecarRuntime']);
        $this->router->add('GET', '/artifacts/leikwan-rate-limit.py', [$artifacts, 'clientRateLimitHelper']);
        $this->router->add('GET', '/artifacts/api-default.html', [$artifacts, 'apiDefaultPage']);
        $this->router->add('GET', '/artifacts/install-slave.sh', [$artifacts, 'installSlave']);
        $this->router->add('GET', '/artifacts/uninstall-slave.sh', [$artifacts, 'uninstallSlave']);
        $this->router->add('GET', '/artifacts/dedupe-slave.sh', [$artifacts, 'dedupeSlave']);
        $this->router->add('GET', '/artifacts/install-client.sh', [$artifacts, 'installClient']);
        $this->router->add('GET', '/artifacts/install-bond-sidecar.sh', [$artifacts, 'installBondSidecar']);
        $this->router->add('GET', '/artifacts/install-mptcp-sidecar.sh', [$artifacts, 'installBondSidecar']);
        $this->router->add('GET', '/artifacts/uninstall-bond-sidecar.sh', [$artifacts, 'uninstallBondSidecar']);
        $this->router->add('GET', '/artifacts/uninstall-mptcp-sidecar.sh', [$artifacts, 'uninstallBondSidecar']);
        $this->router->add('GET', '/artifacts/uninstall-client.sh', [$artifacts, 'uninstallClient']);
        $this->router->add('GET', '/artifacts/install-client.ps1', [$artifacts, 'installClientPowerShell']);
        $this->router->add('GET', '/artifacts/manage-client.ps1', [$artifacts, 'manageClientPowerShell']);
        $this->router->add('GET', '/artifacts/install-client-agent.sh', [$artifacts, 'installClientAgent']);
        $this->router->add('GET', '/artifacts/uninstall-client-agent.sh', [$artifacts, 'uninstallClientAgent']);
        $this->router->add('GET', '/artifacts/leikwan-android-client.apk', [$artifacts, 'androidClientApk']);
        $this->router->add('GET', '/artifacts/remote-install-slave.sh', [$artifacts, 'remoteInstallSlave']);
        $this->router->add('GET', '/artifacts/remote-install-client.sh', [$artifacts, 'remoteInstallClient']);
        $this->router->add('GET', '/artifacts/remote-install-client-agent.sh', [$artifacts, 'remoteInstallClientAgent']);
        $this->router->add('GET', '/artifacts/wstunnel/{filename}', [$artifacts, 'wstunnelPackage']);
        $this->router->add('GET', '/artifacts/client.sh', [$artifacts, 'legacyClient']);
    }
}
