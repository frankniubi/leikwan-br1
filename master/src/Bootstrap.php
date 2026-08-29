<?php
declare(strict_types=1);

namespace Leikwan\Master;

use Leikwan\Master\Controllers\AdminController;
use Leikwan\Master\Controllers\ArtifactController;
use Leikwan\Master\Controllers\AuthController;
use Leikwan\Master\Controllers\ClientAgentController;
use Leikwan\Master\Controllers\HealthController;
use Leikwan\Master\Controllers\ForwardLineApiController;
use Leikwan\Master\Controllers\NodeController;
use Leikwan\Master\Controllers\OidcController;
use Leikwan\Master\Controllers\ServiceController;
use Leikwan\Master\Controllers\SidecarAgentController;
use Leikwan\Master\Controllers\TenantController;
use Leikwan\Master\Controllers\UltraSourceController;
use Leikwan\Master\Controllers\WhmcsController;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;
use Leikwan\Master\Support\LeigodOnlyAdminRolloutStatusService;
use Leikwan\Master\Support\LeigodOnlyArtifactConsistencyService;
use Leikwan\Master\Support\LeigodOnlyArtifactMetadataProbe;
use Leikwan\Master\Support\LeigodOnlyArtifactRouteProbe;
use Leikwan\Master\Support\LeigodOnlyArtifactSourceBuildMap;
use Leikwan\Master\Support\LeigodOnlyRolloutLockService;
use Leikwan\Master\Support\LeigodOnlyRuntimeContractService;
use Leikwan\Master\Support\MigrationRunner;
use Leikwan\Master\Support\OidcClient;
use Leikwan\Master\Support\OidcIdentityService;
use Leikwan\Master\Support\OidcSessionStore;
use Leikwan\Master\Support\ProvisioningService;
use Leikwan\Master\Support\WhmcsClient;
use Leikwan\Master\Support\WhmcsIdentityService;
use Leikwan\Master\Support\AppSettings;
use Leikwan\Master\Support\AccessPolicyService;
use Leikwan\Master\Support\UltraClientStatusService;
use Leikwan\Master\Support\UltraLeaseService;
use Leikwan\Master\Support\UltraMaterialService;
use Leikwan\Master\Support\UltraInventoryService;
use Leikwan\Master\Support\UltraMode;

final class Bootstrap
{
    private Config $config;
    private Database $db;
    private Router $router;
    private ProvisioningService $provisioning;
    private WhmcsClient $whmcsClient;
    private MigrationRunner $migrationRunner;
    /** @var array<string, mixed> */
    private array $services;

    /** @param array<string, mixed> $services */
    public function __construct(string $envPath, array $services = [])
    {
        $this->services = $services;
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

    /** @return array<string, mixed> */
    private function leigodOnlyAdminServices(): array
    {
        if (array_key_exists('leigod_only_services', $this->services)) {
            $services = $this->services['leigod_only_services'];
            return is_array($services) ? $services : [];
        }

        $artifactGate = new LeigodOnlyArtifactConsistencyService(
            new LeigodOnlyArtifactRouteProbe($this->config),
            new LeigodOnlyArtifactMetadataProbe($this->config),
            new LeigodOnlyArtifactSourceBuildMap(dirname(__DIR__, 2)),
        );

        return [
            'status' => new LeigodOnlyAdminRolloutStatusService(['status', 'lock', 'artifact_gate', 'runtime_contract']),
            'lock' => new LeigodOnlyRolloutLockService($this->db),
            'artifact_gate' => $artifactGate,
            'runtime_contract' => new LeigodOnlyRuntimeContractService(),
        ];
    }

    private function registerRoutes(): void
    {
        $health = new HealthController($this->config, $this->db);
        $oidcClient = new OidcClient($this->config);
        $oidcSessionStore = new OidcSessionStore();
        $oidcIdentityService = new OidcIdentityService($this->db);
        $whmcsIdentityService = new WhmcsIdentityService($this->config, $this->db);
        $auth = new AuthController($this->config, $this->db, $oidcClient);
        $oidc = new OidcController(
            $this->config,
            $this->db,
            $oidcClient,
            $oidcSessionStore,
            $oidcIdentityService,
            $whmcsIdentityService,
        );
        $ultraInventory = new UltraInventoryService($this->config, $this->db);
        $ultraClientStatus = new UltraClientStatusService($this->config, $this->db);
        $accessPolicy = isset($this->services['access_policy']) && $this->services['access_policy'] instanceof AccessPolicyService
            ? $this->services['access_policy']
            : new AccessPolicyService($this->db);
        $admin = new AdminController(
            $this->config,
            $this->db,
            $this->provisioning,
            $this->leigodOnlyAdminServices(),
            $ultraInventory,
            $ultraClientStatus,
            $accessPolicy,
        );
        $whmcs = new WhmcsController($this->config, $this->db, $this->provisioning);
        $services = new ServiceController($this->config, $this->db, $this->provisioning);
        $forwardApi = new ForwardLineApiController($this->config, $this->db, $this->provisioning);
        $nodes = new NodeController($this->config, $this->db, $this->provisioning);
        $ultraMaterials = new UltraMaterialService($this->config, $this->db, new AppSettings($this->db));
        $ultraLeases = new UltraLeaseService($this->config, $this->db, $ultraMaterials);
        $clientAgent = new ClientAgentController(
            $this->config,
            $this->db,
            $this->provisioning,
            null,
            $ultraMaterials,
            $ultraClientStatus,
            $ultraLeases,
            accessPolicy: $accessPolicy,
        );
        $ultraSource = new UltraSourceController($this->config, $this->db, $ultraMaterials, $ultraInventory);
        $sidecarAgent = new SidecarAgentController($this->config, $this->db, $this->provisioning);
        $tenant = new TenantController($this->config, $this->db, $this->provisioning, $accessPolicy);
        $artifacts = new ArtifactController($this->config);

        $this->router->add('GET', '/', [$auth, 'home']);
        $this->router->add('GET', '/knowledgebase', [$auth, 'knowledgebasePage']);
        $this->router->add('GET', '/login', [$auth, 'loginPage']);
        $this->router->add('GET', '/auth/oidc/start', [$oidc, 'start']);
        $this->router->add('GET', '/auth/oidc/callback', [$oidc, 'callback']);
        $this->router->add('GET', '/register', [$auth, 'registerPage']);
        $this->router->add('GET', '/forgot-password', [$auth, 'forgotPasswordPage']);
        $this->router->add('POST', '/forgot-password', [$auth, 'requestPasswordReset']);
        $this->router->add('GET', '/reset-password', [$auth, 'resetPasswordPage']);
        $this->router->add('POST', '/reset-password', [$auth, 'resetPassword']);
        $this->router->add('GET', '/buy-binding/verify', [$auth, 'buyBindingVerifyPage']);
        $this->router->add('POST', '/register', [$auth, 'register']);
        $this->router->add('GET', '/verify-email', [$auth, 'verifyEmail']);
        $this->router->add('POST', '/login', [$auth, 'login']);
        $this->router->add('POST', '/login/buy', [$auth, 'buyLogin']);
        $this->router->add('POST', '/tenant/billing/bind', [$auth, 'requestBuyBinding']);
        $this->router->add('POST', '/buy-binding/verify', [$auth, 'verifyBuyBinding']);
        $this->router->add('POST', '/logout', [$auth, 'logout']);
        $this->router->add('GET', '/admin', [$admin, 'dashboard']);
        $this->router->add('POST', '/admin/republish/all', [$admin, 'republishAllForwards']);
        $this->router->add('GET', '/admin/access-control', [$admin, 'accessControlPage']);
        $this->router->add('POST', '/admin/access-control/rules', [$admin, 'saveAccessBlacklistRule']);
        $this->router->add('POST', '/admin/access-control/rules/{id}/toggle', [$admin, 'toggleAccessBlacklistRule']);
        $this->router->add('POST', '/admin/access-control/devices/release', [$admin, 'releaseDeviceBlock']);
        $this->router->add('POST', '/admin/access-control/accounts/release', [$admin, 'releaseAccountBan']);
        $this->router->add('GET', '/admin/leigod-only-rollout', [$admin, 'leigodOnlyRolloutPage']);
        $this->router->add('POST', '/admin/leigod-only-rollout/dry-run', [$admin, 'leigodOnlyActivationDryRun']);
        $this->router->add('POST', '/admin/leigod-only-rollout/activate', [$admin, 'leigodOnlyActivationApply']);
        $this->router->add('POST', '/admin/leigod-only-rollout/rollback', [$admin, 'leigodOnlyRollback']);
        $this->router->add('POST', '/admin/provision-tasks/purge-stale', [$admin, 'purgeStaleProvisionTasks']);
        $this->router->add('POST', '/admin/provision-queue/repair', [$admin, 'repairProvisionQueue']);
        $this->router->add('POST', '/admin/provision-queue/republish-endpoint', [$admin, 'republishProvisionEndpoint']);
        $this->router->add('GET', '/admin/diagnostics', [$admin, 'diagnosticsPage']);
        $this->router->add('GET', '/admin/tenants', [$admin, 'tenantsPage']);
        $this->router->add('GET', '/admin/registration', [$admin, 'tenantRegistrationPage']);
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
        $this->router->add('POST', '/admin/republish/client/{id}', [$admin, 'republishForClient']);
        $this->router->add('GET', '/admin/cf-location-groups', [$admin, 'cfLocationGroupsPage']);
        $this->router->add('POST', '/admin/cf-location-groups', [$admin, 'saveCfLocationGroup']);
        $this->router->add('GET', '/admin/leigod-lines', [$admin, 'leigodLinesPage']);
        $this->router->add('POST', '/admin/leigod-lines', [$admin, 'saveLeigodLine']);
        $this->router->add('POST', '/admin/leigod-lines/{id}/delete', [$admin, 'deleteLeigodLine']);
        $this->router->add('GET', '/admin/forward-combinations', [$admin, 'forwardCombinationsPage']);
        $this->router->add('POST', '/admin/forward-combinations', [$admin, 'saveForwardCombination']);
        $this->router->add('GET', '/admin/ultra', [$admin, 'ultraOperationsPage']);
        $this->router->add('POST', '/admin/ultra/lines/{id}', [$admin, 'saveUltraLinePolicy']);
        $this->router->add('GET', '/admin/forward-routes', [$admin, 'forwardRoutesPage']);
        $this->router->add('POST', '/admin/forward-routes', [$admin, 'saveForwardRoute']);
        $this->router->add('POST', '/admin/forward-combinations/{id}/donations/{donationId}/revoke', [$admin, 'revokeDonation']);
        $this->router->add('GET', '/admin/knowledgebase', [$admin, 'knowledgebaseTopicsPage']);
        $this->router->add('GET', '/admin/knowledgebase/topics/new', [$admin, 'knowledgebaseTopicEditorPage']);
        $this->router->add('GET', '/admin/knowledgebase/topics/{id}/edit', [$admin, 'knowledgebaseTopicEditorPage']);
        $this->router->add('POST', '/admin/knowledgebase/topics/save', [$admin, 'saveKnowledgebaseTopic']);
        $this->router->add('POST', '/admin/knowledgebase/topics/{id}/delete', [$admin, 'deleteKnowledgebaseTopic']);
        $this->router->add('GET', '/admin/knowledgebase/topics/{id}/articles/{locale}/edit', [$admin, 'knowledgebaseArticleEditorPage']);
        $this->router->add('POST', '/admin/knowledgebase/articles/save', [$admin, 'saveKnowledgebaseArticle']);
        $this->router->add('POST', '/admin/knowledgebase/articles/preview', [$admin, 'previewKnowledgebaseArticle']);
        $this->router->add('POST', '/admin/knowledgebase/articles/{id}/delete', [$admin, 'deleteKnowledgebaseArticle']);
        $this->router->add('POST', '/admin/knowledgebase/images', [$admin, 'uploadKnowledgebaseImage']);
        $this->router->add('GET', '/admin-transport-endpoints', [$admin, 'sidecarsPage']);
        $this->router->add('POST', '/admin-transport-endpoints', [$admin, 'saveSidecarPoint']);
        $this->router->add('POST', '/admin-transport-endpoints/{id}/delete', [$admin, 'deleteSidecarPoint']);
        $this->router->add('POST', '/admin-transport-endpoints/{id}/request-update', [$admin, 'requestSidecarUpdate']);
        $this->router->add('POST', '/admin/clients/{id}/diagnose', [$admin, 'requestClientDiagnostics']);
        $this->router->add('POST', '/admin/clients/{id}/request-update', [$admin, 'requestClientUpdate']);
        $this->router->add('GET', '/admin/node-groups', [$admin, 'nodeGroupsPage']);
        $this->router->add('POST', '/admin/node-groups', [$admin, 'saveNodeGroup']);
        $this->router->add('GET', '/admin/chains', [$admin, 'chainsPage']);
        $this->router->add('POST', '/admin/chains', [$admin, 'saveChain']);
        $this->router->add('GET', '/admin/nodes', [$admin, 'nodesPage']);
        $this->router->add('POST', '/admin/nodes/{id}/manage', [$admin, 'saveNodeManagement']);
        $this->router->add('POST', '/admin/nodes/{id}/request-update', [$admin, 'requestNodeUpdate']);
        $this->router->add('POST', '/admin/nodes/{id}/delete', [$admin, 'deleteNode']);
        $this->router->add('POST', '/admin/nodes/request-update-selected', [$admin, 'requestSelectedNodeUpdates']);
        $this->router->add('POST', '/admin/nodes/request-update-group', [$admin, 'requestNodeGroupUpdate']);
        $this->router->add('POST', '/admin/edge-defaults', [$admin, 'saveEdgeDefaults']);
        $this->router->add('GET', '/admin/user-groups', [$admin, 'userSlaveGroupsPage']);
        $this->router->add('POST', '/admin/user-groups/{id}/delete', [$admin, 'deleteUserSlaveGroup']);
        $this->router->add('GET', '/tenant', [$tenant, 'introPage']);
        $this->router->add('GET', '/tenant/getting-started', [$tenant, 'gettingStartedPage']);
        $this->router->add('GET', '/tenant/account', [$tenant, 'billingPage']);
        $this->router->add('GET', '/tenant/billing', [$tenant, 'billingPage']);
        $this->router->add('POST', '/tenant/account/transfer', [$tenant, 'transferBalance']);
        $this->router->add('POST', '/tenant/billing/transfer', [$tenant, 'transferBalance']);
        $this->router->add('POST', '/tenant/account/reactivate', [$tenant, 'reactivateForwards']);
        $this->router->add('POST', '/tenant/account/bind', [$auth, 'requestBuyBinding']);
        $this->router->add('GET', '/tenant/plans', [$tenant, 'plansPage']);
        $this->router->add('POST', '/tenant/plans/purchase', [$tenant, 'purchasePackage']);
        $this->router->add('POST', '/tenant/billing/purchase', [$tenant, 'purchasePackage']);
        $this->router->add('GET', '/tenant/forwards', [$tenant, 'dashboard']);
        $this->router->add('GET', '/tenant/security', [$tenant, 'accessSecurityPage']);
        $this->router->add('POST', '/tenant/security/main-province', [$tenant, 'changeMainProvince']);
        $this->router->add('POST', '/tenant/security/kick-all', [$tenant, 'kickAllDevices']);
        $this->router->add('POST', '/tenant/security/devices/restore', [$tenant, 'restoreDevice']);
        $this->router->add('GET', '/tenant/clients', [$tenant, 'clientsPage']);
        $this->router->add('POST', '/tenant/clients/reset-token', [$tenant, 'resetDefaultClientToken']);
        $this->router->add('POST', '/tenant/clients/kick', [$tenant, 'kickClientDevice']);
        $this->router->add('POST', '/tenant/clients/ban', [$tenant, 'banClientDevice']);
        $this->router->add('POST', '/tenant/clients/unban', [$tenant, 'unbanClientDevice']);
        $this->router->add('POST', '/tenant/clients/android-enrollment-code', [$tenant, 'createAndroidEnrollmentCode']);
        $this->router->add('POST', '/tenant/clients', [$tenant, 'saveClient']);
        $this->router->add('GET', '/tenant-transport-endpoints', [$tenant, 'sidecarsPage']);
        $this->router->add('POST', '/tenant-transport-endpoints', [$tenant, 'saveSidecarPoint']);
        $this->router->add('POST', '/tenant-transport-endpoints/{id}/delete', [$tenant, 'deleteSidecarPoint']);
        $this->router->add('POST', '/tenant-transport-endpoints/{id}/request-update', [$tenant, 'requestSidecarUpdate']);
        $this->router->add('POST', '/tenant/clients/{id}/delete', [$tenant, 'deleteClient']);
        $this->router->add('POST', '/tenant/clients/{id}/diagnose', [$tenant, 'requestClientDiagnostics']);
        $this->router->add('POST', '/tenant/clients/{id}/request-update', [$tenant, 'requestClientUpdate']);
        $this->router->add('GET', '/tenant/services/{id}', [$tenant, 'serviceDetail']);
        $this->router->add('GET', '/tenant/services/{id}/create-forward', [$tenant, 'quickCreateForward']);
        $this->router->add('POST', '/tenant/services/{id}/create-forward', [$tenant, 'quickCreateForward']);
        $this->router->add('GET', '/tenant/services/{id}/forward/{fid}/edit', [$tenant, 'editForward']);
        $this->router->add('POST', '/tenant/services/{id}/forward/{fid}/edit', [$tenant, 'saveForwardEdit']);
        $this->router->add('POST', '/tenant/services/{id}/forward/{fid}/toggle', [$tenant, 'toggleForward']);
        $this->router->add('GET', '/tenant/services/{id}/forward-wizard', [$tenant, 'forwardWizardRedirect']);
        $this->router->add('POST', '/tenant/services/{id}/forward-wizard', [$tenant, 'forwardWizardRedirect']);
        $this->router->add('POST', '/tenant/services/{id}/enrollment-token', [$tenant, 'generateEnrollmentToken']);
        $this->router->add('POST', '/tenant/nodes/{id}/remove', [$tenant, 'removeUserNode']);
        $this->router->add('GET', '/tenant/slave-groups', [$tenant, 'slaveGroupsPage']);
        $this->router->add('POST', '/tenant/slave-groups', [$tenant, 'saveSlaveGroup']);
        $this->router->add('POST', '/tenant/slave-groups/join', [$tenant, 'joinSlaveGroup']);
        $this->router->add('POST', '/tenant/slave-groups/{id}/leave', [$tenant, 'leaveSlaveGroup']);
        $this->router->add('POST', '/tenant/slave-groups/{id}/kick', [$tenant, 'kickSlaveGroupMember']);
        $this->router->add('POST', '/tenant/nodes/{id}/sharing', [$tenant, 'updateNodeSharing']);
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

        $this->router->add('GET', '/api/v1/user/services/{id}/forward-options', [$forwardApi, 'options']);
        $this->router->add('GET', '/api/v1/user/services/{id}/forwards', [$forwardApi, 'index']);
        $this->router->add('POST', '/api/v1/user/services/{id}/forwards', [$forwardApi, 'create']);
        $this->router->add('GET', '/api/v1/user/forwards/{id}', [$forwardApi, 'show']);
        $this->router->add('PATCH', '/api/v1/user/forwards/{id}', [$forwardApi, 'patch']);
        $this->router->add('DELETE', '/api/v1/user/forwards/{id}', [$forwardApi, 'delete']);

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
        $this->router->add('POST', '/api/client/select', [$clientAgent, 'selectDesktopClient']);
        $this->router->add('POST', '/api/client/access/location/challenge', [$clientAgent, 'locationChallenge']);
        $this->router->add('POST', '/api/client/access/location/report', [$clientAgent, 'locationReport']);
        $this->router->add('GET', '/api/client/access/policy', [$clientAgent, 'accessPolicyStatus']);
        $this->router->add('POST', '/api/client/devices/challenge', [$clientAgent, 'deviceChallenge']);
        $this->router->add('POST', '/api/client/devices/enroll', [$clientAgent, 'enrollDevice']);
        $this->router->add('POST', '/api/client/devices/identity/enroll', [$clientAgent, 'deviceIdentityEnroll']);
        $this->router->add('POST', '/api/client/devices/renew/challenge', [$clientAgent, 'deviceRenewalChallenge']);
        $this->router->add('POST', '/api/client/devices/renew', [$clientAgent, 'renewDevice']);
        $this->router->add('POST', '/api/client/devices/ack', [$clientAgent, 'acknowledgeDevice']);
        $this->router->add('POST', '/api/client/devices/code/challenge', [$clientAgent, 'enrollmentCodeChallenge']);
        $this->router->add('POST', '/api/client/devices/code/enroll', [$clientAgent, 'enrollmentCodeEnroll']);
        $this->router->add('POST', '/api/client/devices/code/identity-enroll', [$clientAgent, 'enrollmentCodeIdentityEnroll']);
        $this->router->add('GET', '/api/client/configs/pull', [$clientAgent, 'pullConfigs']);
        $this->router->add('POST', '/api/client/ultra/materials', [$clientAgent, 'resolveUltraMaterials']);
        $this->router->add('POST', UltraMode::LEASE_ENDPOINT, [$clientAgent, 'resolveUltraLease']);
        $this->router->add('POST', '/api/client/heartbeat', [$clientAgent, 'heartbeat']);
        $this->router->add('POST', '/api/client/diagnostics/result', [$clientAgent, 'diagnosticsResult']);
        $this->router->add('GET', '/api/ultra/source/jobs', [$ultraSource, 'jobs']);
        $this->router->add('POST', '/api/ultra/source/materials', [$ultraSource, 'upload']);
        $this->router->add('POST', '/api/ultra/source/inventory', [$ultraSource, 'inventory']);
        $this->router->add('POST', '/api/sidecar/heartbeat', [$sidecarAgent, 'heartbeat']);

        $this->router->add('GET', '/artifacts/versions.json', [$artifacts, 'versionsManifest']);
        $this->router->add('GET', '/artifacts/slave-agent.py', [$artifacts, 'slaveAgent']);
        $this->router->add('GET', '/artifacts/leikwan-preconnect-relay.py', [$artifacts, 'slavePreconnectRelay']);
        $this->router->add('GET', '/artifacts/leikwan-leigod-forward-relay.py', [$artifacts, 'leigodForwardRelay']);
        $this->router->add('GET', '/artifacts/slave-manifest.json', [$artifacts, 'slaveManifest']);
        $this->router->add('GET', '/artifacts/sidecar-manifest.json', [$artifacts, 'sidecarManifest']);
        $this->router->add('GET', '/artifacts/client-manifest.json', [$artifacts, 'clientManifest']);
        $this->router->add('GET', '/artifacts/client-agent.py', [$artifacts, 'clientAgent']);
        $this->router->add('GET', '/artifacts/cfwg-latency-watchdog.py', [$artifacts, 'cfwgLatencyWatchdog']);
        $this->router->add('GET', '/artifacts/sidecar-agent.py', [$artifacts, 'sidecarAgent']);
        $this->router->add('GET', '/artifacts/gost/gost_linux_amd64', [$artifacts, 'gostBinary']);
        $this->router->add('GET', '/artifacts/leigod/leigod-linux-amd64', [$artifacts, 'leigodBinary']);
        $this->router->add('GET', '/artifacts/lguot/lguot_linux_amd64', [$artifacts, 'lguotBinary']);
        $this->router->add('GET', '/artifacts/install-forward-runtime.py', [$artifacts, 'installForwardRuntime']);
        $this->router->add('GET', '/artifacts/ultra/ultra-client_linux_amd64', [$artifacts, 'ultraClient']);
        $this->router->add('GET', '/artifacts/ultra/ultra-client_windows_amd64.exe', [$artifacts, 'ultraClientWindows']);
        $this->router->add('GET', '/artifacts/forward-strategy.py', [$artifacts, 'forwardStrategyHelper']);
        $this->router->add('GET', '/artifacts/leikwan-tcp-race.py', [$artifacts, 'tcpRaceRuntime']);
        $this->router->add('GET', '/artifacts/leikwan-udp-fanout.py', [$artifacts, 'udpFanoutRuntime']);
        $this->router->add('GET', '/artifacts/leikwan-mptcp-client.py', [$artifacts, 'mptcpClientRuntime']);
        $this->router->add('GET', '/artifacts/leikwan-uot.py', [$artifacts, 'uotRuntime']);
        $this->router->add('GET', '/artifacts/leikwan-uot-bond.py', [$artifacts, 'uotBondRuntime']);
        $this->router->add('GET', '/artifacts/leikwan-mptcp-sidecar.py', [$artifacts, 'mptcpSidecarRuntime']);
        $this->router->add('GET', '/artifacts/leikwan-udp-uot-merge.py', [$artifacts, 'udpUotMergeRuntime']);
        $this->router->add('GET', '/artifacts/leikwan-wss-mux.py', [$artifacts, 'wssMuxRuntime']);
        $this->router->add('GET', '/artifacts/leikwan-rate-limit.py', [$artifacts, 'clientRateLimitHelper']);
        $this->router->add('GET', '/artifacts/leikwan-tcp-accel.py', [$artifacts, 'tcpAccelRuntime']);
        $this->router->add('GET', '/artifacts/leikwan-tcp-accel-bridge.py', [$artifacts, 'tcpAccelBridgeRuntime']);
        $this->router->add('GET', '/artifacts/api-default.html', [$artifacts, 'apiDefaultPage']);
        $this->router->add('GET', '/artifacts/install-slave.sh', [$artifacts, 'installSlave']);
        $this->router->add('GET', '/artifacts/uninstall-slave.sh', [$artifacts, 'uninstallSlave']);
        $this->router->add('GET', '/artifacts/dedupe-slave.sh', [$artifacts, 'dedupeSlave']);
        $this->router->add('GET', '/artifacts/install-client.sh', [$artifacts, 'installClient']);
        $this->router->add('GET', '/artifacts/install-bond-sidecar.sh', [$artifacts, 'installBondSidecar']);
        $this->router->add('GET', '/artifacts/install-tcp-accel.sh', [$artifacts, 'installTcpAccel']);
        $this->router->add('GET', '/artifacts/install-mptcp-sidecar.sh', [$artifacts, 'installBondSidecar']);
        $this->router->add('GET', '/artifacts/uninstall-bond-sidecar.sh', [$artifacts, 'uninstallBondSidecar']);
        $this->router->add('GET', '/artifacts/uninstall-mptcp-sidecar.sh', [$artifacts, 'uninstallBondSidecar']);
        $this->router->add('GET', '/artifacts/slave-agent-update.sh', [$artifacts, 'slaveAgentUpdate']);
        $this->router->add('GET', '/artifacts/uninstall-client.sh', [$artifacts, 'uninstallClient']);
        $this->router->add('GET', '/artifacts/install-client.ps1', [$artifacts, 'installClientPowerShell']);
        $this->router->add('GET', '/artifacts/manage-client.ps1', [$artifacts, 'manageClientPowerShell']);
        $this->router->add('GET', '/artifacts/install-client-agent.sh', [$artifacts, 'installClientAgent']);
        $this->router->add('GET', '/artifacts/client-agent-update.sh', [$artifacts, 'clientAgentUpdate']);
        $this->router->add('GET', '/artifacts/sidecar-agent-update.sh', [$artifacts, 'sidecarAgentUpdate']);
        $this->router->add('GET', '/artifacts/uninstall-client-agent.sh', [$artifacts, 'uninstallClientAgent']);
        $this->router->add('GET', '/artifacts/leikwan-android-client.apk', [$artifacts, 'androidClientApk']);
        $this->router->add('GET', '/artifacts/leikwan-bridge-setup.exe', [$artifacts, 'windowsClientApp']);
        $this->router->add('GET', '/artifacts/macos/leikwan-bridge-arm64.dmg', [$artifacts, 'macosClientArm64Dmg']);
        $this->router->add('GET', '/artifacts/macos/leikwan-bridge-x64.dmg', [$artifacts, 'macosClientX64Dmg']);
        $this->router->add('GET', '/artifacts/macos/leikwan-bridge-update-arm64.zip', [$artifacts, 'macosClientArm64Update']);
        $this->router->add('GET', '/artifacts/macos/leikwan-bridge-update-x64.zip', [$artifacts, 'macosClientX64Update']);
        $this->router->add('GET', '/artifacts/leikwan-wg-watchdog.sh', [$artifacts, 'wgWatchdog']);
        $this->router->add('GET', '/artifacts/wstunnel/{filename}', [$artifacts, 'wstunnelPackage']);
        $this->router->add('GET', '/artifacts/client.sh', [$artifacts, 'legacyClient']);
    }
}
