<?php
declare(strict_types=1);

namespace Leikwan\Master\Controllers;

use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;
use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\AccessPolicyService;
use Leikwan\Master\Support\BondLegReputationService;
use Leikwan\Master\Support\LeigodActivationGateContext;
use Leikwan\Master\Support\LeigodNormalizationActivationContext;
use Leikwan\Master\Support\LeigodOnlyActivationPlan;
use Leikwan\Master\Support\LeigodOnlyPlatformFamilyGate;
use Leikwan\Master\Support\LeigodRetirementActivationContext;
use Leikwan\Master\Support\ProvisioningService;
use Leikwan\Master\Support\PrecisionGridUi;
use Leikwan\Master\Support\SessionAuth;
use Leikwan\Master\Support\KnowledgebaseHtmlSanitizer;
use Leikwan\Master\Support\KnowledgebaseService;
use Leikwan\Master\Support\LocaleResolver;
use Leikwan\Master\Support\MermaidSourcePolicy;
use Leikwan\Master\Support\SslCertificateService;
use Leikwan\Master\Support\TenantRegistrationService;
use Leikwan\Master\Support\UltraClientStatusService;
use Leikwan\Master\Support\UltraInventoryService;
use Leikwan\Master\Support\UltraMode;

final class AdminController extends BaseController
{
    private ?SslCertificateService $sslCertificateService = null;
    private AccessPolicyService $accessPolicy;
    /** @var array<string, mixed> */
    private array $leigodOnlyServices;

    /** @param array<string, mixed> $leigodOnlyServices */
    public function __construct(
        \Leikwan\Master\Config $config,
        \Leikwan\Master\Database\Database $db,
        private ?ProvisioningService $provisioning = null,
        array $leigodOnlyServices = [],
        private ?UltraInventoryService $ultraInventory = null,
        private ?UltraClientStatusService $ultraClientStatus = null,
        ?AccessPolicyService $accessPolicy = null,
    ) {
        $this->leigodOnlyServices = $leigodOnlyServices;
        parent::__construct($config, $db);
        $this->accessPolicy = $accessPolicy ?? new AccessPolicyService($db);
    }

    public function dashboard(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }
        $csrf = $this->escape(SessionAuth::csrfToken());

        $cards = [
            ['label' => 'Tenants', 'value' => (int) ($this->db->fetchOne('SELECT COUNT(*) AS total FROM users WHERE role = :role', ['role' => 'tenant'])['total'] ?? 0)],
            ['label' => 'Services', 'value' => (int) ($this->db->fetchOne('SELECT COUNT(*) AS total FROM services')['total'] ?? 0)],
            ['label' => 'Plans', 'value' => count($this->provisioning?->listPlans() ?? [])],
            ['label' => 'Slave Groups', 'value' => count($this->provisioning?->listNodeGroups() ?? [])],
            ['label' => 'Nodes', 'value' => (int) ($this->db->fetchOne('SELECT COUNT(*) AS total FROM nodes')['total'] ?? 0)],
            ['label' => 'Queued Tasks', 'value' => (int) ($this->db->fetchOne('SELECT COUNT(*) AS total FROM provision_tasks WHERE status = :pending OR status = :running', ['pending' => 'pending', 'running' => 'running'])['total'] ?? 0)],
            ['label' => 'SSL Tasks', 'value' => (int) ($this->db->fetchOne('SELECT COUNT(*) AS total FROM ssl_certificate_tasks WHERE status = :pending OR status = :running', ['pending' => 'pending', 'running' => 'running'])['total'] ?? 0)],
        ];

        $cardHtml = '';
        foreach ($cards as $card) {
            $cardHtml .= '<section class="card"><div class="muted">' . $this->escape($card['label']) . '</div><div class="stats">' . $card['value'] . '</div></section>';
        }
        $reputationService = new BondLegReputationService($this->db);
        $queueHealth = $this->taskQueueHealth();
        $recentFailedTasks = $this->recentFailedProvisionTasks();
        $worstLegs = $reputationService->latestGlobalRows(10);

        $body = '
            <div class="hero">
              <div>
                <h2>Admin Overview</h2>
                <p class="muted">Use the left navigation to manage tenants, plans, credentials, clients, slave groups and slave nodes.</p>
                <div class="actions" style="margin-top:16px;">
                  <form class="inline" method="post" action="/admin/republish/all">
                    <input type="hidden" name="csrf_token" value="' . $csrf . '">
                    <button type="submit" class="button danger" onclick="return confirm(\'Republish all forwards?\');">Republish All Forwards</button>
                  </form>
                  <a class="button secondary" href="/admin/diagnostics">Diagnostics</a>
                </div>
              </div>
            </div>
            <div class="grid three">' . $cardHtml . '</div>
            ' . $this->renderTaskQueueHealthSection($queueHealth, $csrf) . '
            ' . $this->renderRecentFailedTasksSection($recentFailedTasks) . '
            ' . $this->renderWorstLegsSection($worstLegs) . '
            <div class="grid two" style="margin-top:20px;">
              <section class="card">
                <h3>First Forward Quick Start</h3>
                <ol class="list">
                  <li>Create one slave group in <code>Slave Groups</code>, for example <code>hk-edge</code>.</li>
                  <li>Install one online slave into that group from <code>Slave Nodes</code>.</li>
                  <li>Create one client in <code>Clients</code> and set its default route mode to <code>slave_group</code> plus the same default slave group.</li>
                  <li>Bind the plan to the same slave group and client group, then activate one tenant service on that plan.</li>
                  <li>Ask the tenant to open <code>Guided Setup</code> and keep these defaults for the first rule: <code>slave_group</code>, <code>single</code>, <code>tcp</code>. The panel now assigns the endpoint set automatically.</li>
                </ol>
              </section>
              <section class="card">
                <h3>When To Use Advanced Options</h3>
                <ul class="list">
                  <li>Use <code>forward_chain</code> only when you already want a deliberate multi-hop route.</li>
                  <li>Use <code>race / failover</code> when you want multiple slave endpoints for redundancy and per-connection distribution.</li>
                  <li>Do not expect <code>race / failover</code> to provide true single-stream <code>本地汇聚</code> yet.</li>
                </ul>
              </section>
            </div>';

        return $this->adminPage('Admin Overview', $body, $viewer, 'overview');
    }

    public function republishAllForwards(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $result = $this->provisioning?->republishAllForwardsWithResult() ?? ['republished' => 0, 'errors' => ['provisioning unavailable']];
            $republished = (int) ($result['republished'] ?? 0);
            $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
            if ($errors !== []) {
                SessionAuth::flash('error', 'Republish finished with issues: ' . implode('; ', array_map('strval', $errors)));
            } else {
                SessionAuth::flash('success', 'Republished ' . $republished . ' forward(s).');
            }
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to republish forwards: ' . $this->displayThrowableMessage($throwable));
        }

        return Response::redirect('/admin');
    }

    public function diagnosticsPage(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        $clients = $this->db->fetchAll(
            'SELECT id, name, display_host, agent_version, last_sync_at, last_diag_status, last_diag_run_at, last_diag_error, last_diag_result_json
             FROM clients
             ORDER BY updated_at DESC, id DESC
             LIMIT 50'
        );
        $nodes = $this->db->fetchAll(
            'SELECT id, name, public_ip, region, status, agent_version, current_load, last_heartbeat_at
             FROM nodes
             ORDER BY last_heartbeat_at DESC, id DESC
             LIMIT 50'
        );
        $sidecars = $this->provisioning?->listSidecarPoints() ?? [];
        $reputationService = new BondLegReputationService($this->db);
        $reputationRows = $reputationService->latestGlobalRows(50);
        $reputationEvents = $reputationService->recentEvents([], 50);

        $okClients = 0;
        $errorClients = 0;
        $requestedClients = 0;
        foreach ($clients as $client) {
            $status = strtolower(trim((string) ($client['last_diag_status'] ?? '')));
            if ($status === 'ok') {
                $okClients++;
            } elseif ($status === 'error') {
                $errorClients++;
            } elseif ($status === 'requested') {
                $requestedClients++;
            }
        }

        $onlineNodes = 0;
        foreach ($nodes as $node) {
            if ((string) ($node['status'] ?? '') === 'active') {
                $onlineNodes++;
            }
        }

        $onlineSidecars = 0;
        foreach ($sidecars as $point) {
            if (($this->sidecarPointStatusInfo($point)['label'] ?? '') === 'online') {
                $onlineSidecars++;
            }
        }

        $clientRows = '';
        foreach ($clients as $client) {
            $clientRows .= '<tr>
                <td>' . (int) ($client['id'] ?? 0) . '</td>
                <td>' . $this->escape((string) ($client['name'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($client['display_host'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($client['agent_version'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($client['last_sync_at'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($client['last_diag_status'] ?? 'not run')) . '</td>
                <td>' . $this->escape((string) ($client['last_diag_run_at'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($client['last_diag_error'] ?? '')) . '</td>
                <td>' . $this->renderClientDiagnosticsHtml($client) . '</td>
            </tr>';
        }

        $nodeRows = '';
        foreach ($nodes as $node) {
            $nodeRows .= '<tr>
                <td>' . (int) ($node['id'] ?? 0) . '</td>
                <td>' . $this->escape((string) ($node['name'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($node['public_ip'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($node['region'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($node['status'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($node['agent_version'] ?? '')) . '</td>
                <td>' . (int) ($node['current_load'] ?? 0) . '</td>
                <td>' . $this->escape((string) ($node['last_heartbeat_at'] ?? '')) . '</td>
            </tr>';
        }

        $sidecarRows = '';
        foreach ($sidecars as $point) {
            $status = $this->sidecarPointStatusInfo($point);
            $sidecarRows .= '<tr>
                <td>' . (int) ($point['id'] ?? 0) . '</td>
                <td>' . $this->escape((string) ($point['name'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($point['sidecar_host'] ?? '')) . '</td>
                <td>' . $this->escape($this->sidecarPortSummary($point)) . '</td>
                <td>' . $this->escape((string) ($point['agent_version'] ?? '')) . '</td>
                <td>' . $this->escape($status['label']) . '</td>
                <td>' . $this->escape((string) ($point['last_heartbeat_at'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($point['last_error'] ?? '')) . '</td>
            </tr>';
        }

        $body = '
            <div class="hero">
              <div>
                <h2>Diagnostics</h2>
                <p class="muted">Quick health snapshot for clients and slave nodes, plus backend transport status, when a line feels wrong and you want the fastest facts first.</p>
              </div>
            </div>
            <div class="grid three">
              <section class="card"><div class="muted">Clients OK</div><div class="stats">' . $okClients . '</div></section>
              <section class="card"><div class="muted">Clients Requested</div><div class="stats">' . $requestedClients . '</div></section>
              <section class="card"><div class="muted">Clients Error</div><div class="stats">' . $errorClients . '</div></section>
              <section class="card"><div class="muted">Nodes Online</div><div class="stats">' . $onlineNodes . '</div></section>
              <section class="card"><div class="muted">Sidecars Online</div><div class="stats">' . $onlineSidecars . '</div></section>
              <section class="card"><div class="muted">Queued Tasks</div><div class="stats">' . (int) ($this->db->fetchOne('SELECT COUNT(*) AS total FROM provision_tasks WHERE status = :pending OR status = :running', ['pending' => 'pending', 'running' => 'running'])['total'] ?? 0) . '</div></section>
            </div>
            <div class="grid one" style="margin-top:20px;">
              <section class="card">
                <h3>Client Diagnostics</h3>
                <div class="table-wrap">
                  <table>
                    <thead><tr><th>ID</th><th>Name</th><th>Host</th><th>Agent</th><th>Last Sync</th><th>Diag Status</th><th>Last Run</th><th>Error</th><th>Details</th></tr></thead>
                    <tbody>' . $clientRows . '</tbody>
                  </table>
                </div>
              </section>
              <section class="card">
                <h3>Slave Health</h3>
                <div class="table-wrap">
                  <table>
                    <thead><tr><th>ID</th><th>Name</th><th>Public IP</th><th>Region</th><th>Status</th><th>Agent</th><th>Load</th><th>Heartbeat</th></tr></thead>
                    <tbody>' . $nodeRows . '</tbody>
                  </table>
                </div>
              </section>
              <section class="card">
                <h3>Transport Health</h3>
                <div class="table-wrap">
                  <table>
                    <thead><tr><th>ID</th><th>Name</th><th>Host</th><th>Ports</th><th>Agent</th><th>Status</th><th>Heartbeat</th><th>Error</th></tr></thead>
                    <tbody>' . $sidecarRows . '</tbody>
                  </table>
                </div>
              </section>
              ' . $this->renderBondLegReputationDiagnosticsSection($reputationRows, $reputationEvents) . '
            </div>';

        return $this->adminPage('Diagnostics', $body, $viewer, 'overview');
    }


    public function leigodOnlyRolloutPage(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        $status = $this->callLeigodOnlyService('status', 'rolloutStatus', ['scope' => (string) $request->input('scope', 'global')]) ?? [];
        $sections = [
            'Artifact consistency',
            'Runtime contract',
            'Leg policy',
            'Upload gate',
            'Download gate',
            'Canary cohorts',
            'Normalization activation',
            'Legacy retirement activation',
            'Rollback preview',
            'Audit events',
        ];
        $sectionHtml = '';
        foreach ($sections as $section) {
            $sectionHtml .= '<section class="card"><h3>' . $this->escape($section) . '</h3><p class="muted">Read-only rollout status.</p></section>';
        }
        $statusJson = $this->escape(json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
        $body = '<div class="hero"><div><h2>LeiGod-only rollout</h2><p class="muted">Read-only control surface for activation readiness, canary status, guarded activation, and rollback preview.</p></div></div>'
            . '<div class="grid two" style="margin-top:20px;">' . $sectionHtml . '</div>'
            . '<section class="card" style="margin-top:20px;"><h3>Status payload</h3><pre>' . $statusJson . '</pre></section>';
        return $this->adminPage('LeiGod-only rollout', $body, $viewer, 'overview');
    }

    public function leigodOnlyActivationDryRun(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::json(401, ['error' => 'authentication_required']);
        }
        try {
            $this->requireCsrf($request);
            $platformFamily = $this->platformFamilyFromRequest($request);
            LeigodOnlyPlatformFamilyGate::assertSupported($platformFamily, 'admin_dry_run');
            $result = $this->callRequiredLeigodOnlyService('planner', 'plan', [
                'scope' => $this->scopeFromRequest($request),
                'platform_family' => $platformFamily,
                'write_mode' => 'dry_run',
                'dry_run_only' => true,
            ]);
            return Response::json(200, $this->normalizeLeigodOnlyActivationPlanResponse($result));
        } catch (ApiException $exception) {
            return Response::json($exception->statusCode() === 419 ? 403 : $exception->statusCode(), ['error' => $exception->getMessage()]);
        } catch (\Throwable $throwable) {
            return Response::json(422, ['error' => $this->displayThrowableMessage($throwable)]);
        }
    }

    public function leigodOnlyActivationApply(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::json(401, ['error' => 'authentication_required']);
        }
        try {
            $this->requireCsrf($request);
            $confirmPlanHash = $this->requiredHashInput($request, 'confirm_plan_hash');
            $scope = $this->scopeFromRequest($request);
            $planHash = $this->requiredHashInput($request, 'plan_hash');
            if (!hash_equals($planHash, $confirmPlanHash)) {
                throw new \InvalidArgumentException('plan_hash_confirmation_mismatch');
            }
            $planId = $this->requiredStringInput($request, 'plan_id');
            $ownerToken = $this->requiredStringInput($request, 'owner_token');
            $fencingToken = $this->requiredStringInput($request, 'fencing_token');
            $platformFamily = $this->platformFamilyFromRequest($request);
            LeigodOnlyPlatformFamilyGate::assertSupported($platformFamily, 'admin_activate');
            $artifactSnapshotHash = $this->requiredHashInput($request, 'artifact_snapshot_hash');
            $activationPayload = $this->leigodOnlyActivationPayloadFromRequest($request, $scope, $planId, $planHash, $ownerToken, $fencingToken, $platformFamily, $artifactSnapshotHash);
            $runtimeContract = $this->runtimeContractRowFromActivationPayload($request, $activationPayload);
            $activationShape = $this->activationShapeFromPayload($activationPayload);
            $lockKey = $this->leigodOnlyActivationLockKey($activationPayload, $activationShape);
            $this->callRequiredLeigodOnlyService('lock', 'requireHeld', [$scope, $lockKey, $ownerToken, $fencingToken]);
            $artifactGate = $this->callRequiredLeigodOnlyService('artifact_gate', 'validateForActivation', $this->leigodOnlyArtifactGateContext($activationPayload, $scope, $platformFamily, $artifactSnapshotHash));
            $this->assertSuccessfulLeigodOnlyResult($artifactGate, 'artifact_gate');
            $runtimeGate = $this->callRequiredLeigodOnlyService('runtime_contract', 'assertActiveContract', [$runtimeContract, 'admin_activate']);
            $this->assertSuccessfulLeigodOnlyResult($runtimeGate, 'runtime_contract_gate', true);
            $result = $this->callRequiredLeigodOnlyActivationService($activationPayload);
            $this->assertSuccessfulLeigodOnlyResult($result, 'activation_result');
            return Response::json(200, ['ok' => true, 'result' => $result]);
        } catch (ApiException $exception) {
            return Response::json($exception->statusCode() === 419 ? 403 : $exception->statusCode(), ['error' => $exception->getMessage()]);
        } catch (\Throwable $throwable) {
            return Response::json(422, ['error' => $this->displayThrowableMessage($throwable)]);
        }
    }

    public function leigodOnlyRollback(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::json(401, ['error' => 'authentication_required']);
        }
        try {
            $this->requireCsrf($request);
            $confirmBatchHash = $this->requiredHashInput($request, 'confirm_batch_hash');
            $batchId = $this->requiredPositiveDecimalInput($request, 'batch_id');
            $confirmBatchId = $this->requiredPositiveDecimalInput($request, 'confirm_batch_id');
            if ($batchId !== $confirmBatchId) {
                throw new \InvalidArgumentException('batch_id_confirmation_mismatch');
            }
            $scope = $this->scopeFromRequest($request);
            $batchHash = $this->requiredHashInput($request, 'batch_hash');
            if (!hash_equals($batchHash, $confirmBatchHash)) {
                throw new \InvalidArgumentException('batch_hash_confirmation_mismatch');
            }
            $ownerToken = $this->requiredStringInput($request, 'owner_token');
            $fencingToken = $this->requiredStringInput($request, 'fencing_token');
            $platformFamily = $this->platformFamilyFromRequest($request);
            LeigodOnlyPlatformFamilyGate::assertSupported($platformFamily, 'admin_rollback');
            $this->callRequiredLeigodOnlyService('lock', 'requireHeld', [$scope, 'activation-batch:' . $batchId, $ownerToken, $fencingToken]);
            $result = $this->callRequiredLeigodOnlyService('rollback', 'apply', [$batchId, $batchHash, $ownerToken, $fencingToken]);
            $this->assertSuccessfulLeigodOnlyResult($result, 'rollback_result');
            return Response::json(200, ['ok' => true, 'result' => $result]);
        } catch (ApiException $exception) {
            return Response::json($exception->statusCode() === 419 ? 403 : $exception->statusCode(), ['error' => $exception->getMessage()]);
        } catch (\Throwable $throwable) {
            return Response::json(422, ['error' => $this->displayThrowableMessage($throwable)]);
        }
    }

    private function requireCsrf(Request $request): void
    {
        SessionAuth::validateCsrf($request);
    }

    private function scopeFromRequest(Request $request): string
    {
        return $this->requiredStringInput($request, 'scope');
    }

    private function platformFamilyFromRequest(Request $request): string
    {
        $platformFamily = trim((string) $request->input('platform_family', 'linux-bond'));
        return $platformFamily !== '' ? $platformFamily : 'linux-bond';
    }

    private function requiredStringInput(Request $request, string $field): string
    {
        $value = trim((string) $request->input($field, ''));
        if ($value === '') {
            throw new \InvalidArgumentException(str_replace('-', '_', $field) . '_required');
        }
        return $value;
    }

    private function requiredPositiveDecimalInput(Request $request, string $field): int
    {
        $raw = $request->input($field, null);
        if (!is_scalar($raw) || (string) $raw === '') {
            throw new \InvalidArgumentException(str_replace('-', '_', $field) . '_required');
        }
        $value = (string) $raw;
        if (!preg_match('/^[1-9][0-9]*$/', $value)) {
            throw new \InvalidArgumentException(str_replace('-', '_', $field) . '_invalid');
        }
        return (int) $value;
    }

    private function requiredHashInput(Request $request, string $field): string
    {
        $value = $this->requiredStringInput($request, $field);
        if (!preg_match('/^[a-f0-9]{64}$/i', $value)) {
            throw new \InvalidArgumentException(str_replace('-', '_', $field) . '_required');
        }
        return strtolower($value);
    }

    private function optionalHashInput(Request $request, string $field): ?string
    {
        $value = trim((string) $request->input($field, ''));
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^[a-f0-9]{64}$/i', $value)) {
            throw new \InvalidArgumentException(str_replace('-', '_', $field) . '_required');
        }
        return strtolower($value);
    }

    /** @return array<string, mixed> */
    private function normalizeLeigodOnlyActivationPlanResponse(mixed $result): array
    {
        if ($result instanceof LeigodOnlyActivationPlan) {
            return $this->leigodOnlyActivationPlanResponseFromPayload($result->payload(), $result->hash(), null, []);
        }
        if (!is_array($result)) {
            throw new \InvalidArgumentException('admin_rollout_plan_payload_unavailable');
        }
        $planHash = strtolower(trim((string) ($result['plan_hash'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/i', $planHash)) {
            throw new \InvalidArgumentException('admin_rollout_plan_hash_unavailable');
        }
        $payload = $result['payload'] ?? null;
        if (!is_array($payload) || $payload === []) {
            throw new \InvalidArgumentException('admin_rollout_plan_payload_unavailable');
        }
        return $this->leigodOnlyActivationPlanResponseFromPayload($payload, $planHash, $result['plan_id'] ?? ($payload['plan_id'] ?? null), $result);
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $source @return array<string, mixed> */
    private function leigodOnlyActivationPlanResponseFromPayload(array $payload, string $planHash, mixed $planId, array $source): array
    {
        if ($payload === []) {
            throw new \InvalidArgumentException('admin_rollout_plan_payload_unavailable');
        }
        $response = [
            'ok' => true,
            'plan_hash' => strtolower($planHash),
            'payload' => $payload,
            'blockers' => LeigodOnlyActivationPlan::stringList($payload['blockers'] ?? ($source['blockers'] ?? [])),
            'dry_run_only' => (bool) ($payload['dry_run_only'] ?? ($source['dry_run_only'] ?? true)),
            'scope' => (string) ($payload['scope'] ?? ($source['scope'] ?? 'global')),
        ];
        if (is_scalar($planId) && trim((string) $planId) !== '') {
            $response['plan_id'] = trim((string) $planId);
        }
        if (array_key_exists('recorded', $source)) {
            $response['recorded'] = (bool) $source['recorded'];
        }
        return $response;
    }

    /** @return array<string, mixed> */
    private function leigodOnlyActivationPayloadFromRequest(Request $request, string $scope, string $planId, string $planHash, string $ownerToken, string $fencingToken, string $platformFamily, string $artifactSnapshotHash): array
    {
        $nestedPayload = $this->arrayInput($request, 'activation_payload') ?? [];
        $payload = [];
        foreach ($request->body() as $key => $value) {
            $key = (string) $key;
            if ($key === 'csrf_token' || $key === 'activation_payload') {
                continue;
            }
            $payload[$key] = $value;
        }
        $runtimeContractFields = array_fill_keys(['runtime_row', 'active_runtime_row', 'runtime_contract_row', 'current_runtime_row', 'runtime_contract_json'], true);
        foreach ($nestedPayload as $key => $value) {
            $key = (string) $key;
            if (array_key_exists($key, $payload) && !$this->sameActivationPayloadValue($payload[$key], $value)) {
                if (array_key_exists($key, $runtimeContractFields)) {
                    continue;
                }
                throw new \InvalidArgumentException('activation_payload_conflict:' . $key);
            }
            if (!array_key_exists($key, $payload)) {
                $payload[$key] = $value;
            }
        }
        $payload['scope'] = $scope;
        $payload['plan_id'] = $planId;
        $payload['plan_hash'] = $planHash;
        $payload['owner_token'] = $ownerToken;
        $payload['fencing_token'] = $fencingToken;
        $payload['platform_family'] = $platformFamily;
        $payload['artifact_snapshot_hash'] = $artifactSnapshotHash;
        return $payload;
    }

    private function sameActivationPayloadValue(mixed $left, mixed $right): bool
    {
        if (is_array($left) || is_array($right)) {
            return is_array($left) && is_array($right) && LeigodOnlyActivationPlan::canonicalJson($left) === LeigodOnlyActivationPlan::canonicalJson($right);
        }
        if (is_scalar($left) && is_scalar($right)) {
            return (string) $left === (string) $right;
        }
        return $left === $right;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function leigodOnlyArtifactGateContext(array $payload, string $scope, string $platformFamily, string $artifactSnapshotHash): array
    {
        $context = ['scope' => $scope, 'platform_family' => $platformFamily, 'artifact_snapshot_hash' => $artifactSnapshotHash];
        foreach (['activation_artifact_keys', 'route_only_helper_keys', 'manifest_package', 'static_mirror', 'expected_pins', 'expected_artifact_pins', 'source_build_provenance', 'expected_capabilities', 'capability_reports', 'test_mode'] as $field) {
            if (array_key_exists($field, $payload)) {
                $context[$field] = $payload[$field];
            }
        }
        return $context;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function runtimeContractRowFromActivationPayload(Request $request, array $payload): array
    {
        $rows = [];
        $sources = [];
        $nestedPayload = $this->arrayInput($request, 'activation_payload') ?? [];
        foreach (['runtime_row', 'active_runtime_row', 'runtime_contract_row', 'current_runtime_row', 'runtime_contract_json'] as $field) {
            if (array_key_exists($field, $nestedPayload)) {
                $this->collectRuntimeContractRow($rows, $sources, 'activation_payload.' . $field, $nestedPayload[$field], $field);
            }
            if (array_key_exists($field, $request->body())) {
                $this->collectRuntimeContractRow($rows, $sources, $field, $request->body()[$field], $field);
            }
            if (!array_key_exists($field, $nestedPayload) && !array_key_exists($field, $request->body()) && array_key_exists($field, $payload)) {
                $this->collectRuntimeContractRow($rows, $sources, $field, $payload[$field], $field);
            }
        }
        if ($rows === []) {
            throw new \InvalidArgumentException('runtime_contract_row_required');
        }
        if (count($rows) > 1) {
            throw new \InvalidArgumentException('runtime_contract_row_conflict:' . implode(',', $sources));
        }
        return array_values($rows)[0];
    }

    /** @param array<string, array<string, mixed>> $rows @param list<string> $sources */
    private function collectRuntimeContractRow(array &$rows, array &$sources, string $source, mixed $value, string $field): void
    {
        $row = $this->arrayValue($value, $field);
        if ($row === null || $row === []) {
            return;
        }
        $row = $this->runtimeContractPayloadFromRow($row);
        $canonical = LeigodOnlyActivationPlan::canonicalJson($row);
        if (!array_key_exists($canonical, $rows)) {
            $rows[$canonical] = $row;
            $sources[] = $source;
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function runtimeContractPayloadFromRow(array $row): array
    {
        foreach (['backend_mode', 'client_mode', 'runtime_preference', 'bond_ingress_mode', 'active_family'] as $field) {
            if (!array_key_exists($field, $row)) {
                throw new \InvalidArgumentException('runtime_contract_row_required');
            }
        }
        return $row;
    }

    /** @return array<string, mixed>|null */
    private function arrayInput(Request $request, string $field): ?array
    {
        return $this->arrayValue($request->input($field, null), $field);
    }

    /** @return array<string, mixed>|null */
    private function arrayValue(mixed $value, string $field): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            throw new \InvalidArgumentException(str_replace('-', '_', $field) . '_invalid_json');
        }
        return null;
    }

    private function assertSuccessfulLeigodOnlyResult(mixed $result, string $label, bool $allowNullSuccess = false): void
    {
        if ($result === null && $allowNullSuccess) {
            return;
        }
        if (!is_array($result) || $result === []) {
            throw new \InvalidArgumentException($label . '_unavailable');
        }
        $blockers = $this->strictLeigodOnlyResultBlockers($result, $label);
        if (!array_key_exists('ok', $result) || $result['ok'] !== true || $blockers !== []) {
            throw new \InvalidArgumentException($label . '_failed' . ($blockers !== [] ? ':' . implode(',', $blockers) : ''));
        }
    }

    /** @param array<string, mixed> $result @return list<string> */
    private function strictLeigodOnlyResultBlockers(array $result, string $label): array
    {
        if (!array_key_exists('blockers', $result)) {
            return [];
        }
        if (!is_array($result['blockers'])) {
            throw new \InvalidArgumentException($label . '_failed:malformed_blockers');
        }
        foreach ($result['blockers'] as $blocker) {
            if (!is_scalar($blocker)) {
                throw new \InvalidArgumentException($label . '_failed:malformed_blockers');
            }
        }
        return LeigodOnlyActivationPlan::stringList($result['blockers']);
    }

    /** @param array<string, mixed> $payload */
    private function leigodOnlyActivationLockKey(array $payload, ?string $shape = null): string
    {
        $shape ??= $this->activationShapeFromPayload($payload);
        if ($shape === 'retirement') {
            return 'leigod-only-retirement';
        }
        if ($shape === 'normalization') {
            return 'leigod-only-normalization';
        }
        $forwardId = $this->positiveIntValue($payload['forward_id'] ?? null);
        if ($forwardId > 0) {
            return 'leigod-only-activation:' . $forwardId;
        }
        throw new \InvalidArgumentException('activation_lock_key_required');
    }

    /** @param array<string, mixed> $payload */
    private function activationShapeFromPayload(array $payload): string
    {
        $explicit = strtolower(trim((string) ($payload['activation_type'] ?? $payload['activation_shape'] ?? '')));
        if ($explicit !== '') {
            if (!in_array($explicit, ['standalone', 'normalization', 'retirement'], true)) {
                throw new \InvalidArgumentException('activation_shape_unknown');
            }
            if ($explicit === 'standalone' && ($this->hasRetirementActivationShape($payload) || $this->hasNormalizationActivationShape($payload))) {
                throw new \InvalidArgumentException('activation_shape_ambiguous');
            }
            if ($explicit === 'normalization' && ($this->hasRetirementOnlyActivationMarkers($payload) || $this->positiveIntValue($payload['forward_id'] ?? null) > 0)) {
                throw new \InvalidArgumentException('activation_shape_ambiguous');
            }
            if ($explicit === 'retirement' && $this->positiveIntValue($payload['forward_id'] ?? null) > 0) {
                throw new \InvalidArgumentException('activation_shape_ambiguous');
            }
            return $explicit;
        }

        $matches = [];
        if ($this->hasRetirementActivationShape($payload)) {
            $matches[] = 'retirement';
        }
        if (!$this->hasRetirementOnlyActivationMarkers($payload) && $this->hasNormalizationActivationShape($payload)) {
            $matches[] = 'normalization';
        }
        if ($this->positiveIntValue($payload['forward_id'] ?? null) > 0) {
            $matches[] = 'standalone';
        }
        $matches = array_values(array_unique($matches));
        if ($matches === []) {
            throw new \InvalidArgumentException('activation_lock_key_required');
        }
        if (count($matches) > 1) {
            throw new \InvalidArgumentException('activation_shape_ambiguous');
        }
        return $matches[0];
    }

    private function positiveIntValue(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /** @param array<string, mixed> $payload */
    private function hasRetirementActivationShape(array $payload): bool
    {
        foreach (['batch_hash', 'current_batch_hash', 'retirement_forward_ids', 'cleanup_protocol', 'cleanup_lease_token', 'cleanup_result_token', 'replacement_map'] as $field) {
            if (!array_key_exists($field, $payload)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $payload */
    private function hasRetirementOnlyActivationMarkers(array $payload): bool
    {
        foreach (['batch_hash', 'current_batch_hash', 'retirement_forward_ids', 'cleanup_protocol', 'cleanup_lease_token', 'cleanup_result_token', 'replacement_map', 'runtime_retirements', 'active_keep_set'] as $field) {
            if (array_key_exists($field, $payload)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $payload */
    private function hasNormalizationActivationShape(array $payload): bool
    {
        foreach (['current_plan_hash', 'affected_forward_ids', 'expected_artifact_pins'] as $field) {
            if (!array_key_exists($field, $payload)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string, mixed> $payload */
    private function callRequiredLeigodOnlyActivationService(array $payload): mixed
    {
        $service = $this->leigodOnlyServices['activation'] ?? null;
        if (!is_object($service)) {
            throw new ApiException('leigod_only_service_unavailable:activation.activate', 422);
        }
        $shape = $this->activationShapeFromPayload($payload);
        if (method_exists($service, 'activate')) {
            return $this->invokeLeigodOnlyService($service, 'activate', $payload);
        }
        if ($shape === 'normalization' && method_exists($service, 'apply')) {
            return $this->invokeLeigodOnlyService($service, 'apply', $payload);
        }
        throw new ApiException('leigod_only_service_unavailable:activation.activate', 422);
    }

    private function callLeigodOnlyService(string $serviceKey, string $method, mixed $argument = null): mixed
    {
        $service = $this->leigodOnlyServices[$serviceKey] ?? null;
        if (!is_object($service) || !method_exists($service, $method)) {
            return null;
        }
        return $this->invokeLeigodOnlyService($service, $method, $argument);
    }

    private function callRequiredLeigodOnlyService(string $serviceKey, string $method, mixed $argument = null): mixed
    {
        $service = $this->leigodOnlyServices[$serviceKey] ?? null;
        if (!is_object($service) || !method_exists($service, $method)) {
            throw new ApiException('leigod_only_service_unavailable:' . $serviceKey . '.' . $method, 422);
        }
        return $this->invokeLeigodOnlyService($service, $method, $argument);
    }

    private function invokeLeigodOnlyService(object $service, string $method, mixed $argument): mixed
    {
        $argument = $this->coerceLeigodOnlyServiceArgument($service, $method, $argument);
        return is_array($argument) && array_is_list($argument)
            ? $service->{$method}(...$argument)
            : $service->{$method}($argument ?? []);
    }

    private function coerceLeigodOnlyServiceArgument(object $service, string $method, mixed $argument): mixed
    {
        if (!is_array($argument) || array_is_list($argument) || $argument instanceof LeigodActivationGateContext || $argument instanceof LeigodNormalizationActivationContext || $argument instanceof LeigodRetirementActivationContext) {
            return $argument;
        }
        try {
            $reflection = new \ReflectionMethod($service, $method);
        } catch (\ReflectionException) {
            return $argument;
        }
        $parameters = $reflection->getParameters();
        if (count($parameters) !== 1) {
            return $argument;
        }
        $type = $parameters[0]->getType();
        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return $argument;
        }
        if ($type->getName() === LeigodActivationGateContext::class) {
            return LeigodActivationGateContext::fromArray($argument);
        }
        if ($type->getName() === LeigodNormalizationActivationContext::class) {
            return LeigodNormalizationActivationContext::fromArray($argument);
        }
        if ($type->getName() === LeigodRetirementActivationContext::class) {
            return LeigodRetirementActivationContext::fromArray($argument);
        }
        return $argument;
    }

    public function republishProvisionEndpoint(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $nodeId = (int) $request->input('repair_node_id', 0);
            $forwardId = (int) $request->input('repair_forward_id', 0);
            $endpointId = (int) $request->input('repair_endpoint_id', 0);
            if ($this->provisioning === null || !$this->provisioning->republishActiveEndpoint($nodeId, $forwardId, $endpointId)) {
                throw new ApiException('endpoint_not_found_or_inactive', 404);
            }
            SessionAuth::flash('success', 'Republished endpoint. node=' . $nodeId . ' forward=' . $forwardId . ' endpoint=' . $endpointId);
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to republish endpoint: ' . $this->displayThrowableMessage($throwable));
        }

        return Response::redirect('/admin');
    }

    public function purgeStaleProvisionTasks(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $thresholdMinutes = (int) $request->input('threshold_minutes', 30);
            if (!in_array($thresholdMinutes, [15, 30, 60], true)) {
                $thresholdMinutes = 30;
            }

            $thresholdAt = gmdate('Y-m-d H:i:s', time() - ($thresholdMinutes * 60));
            $preview = $this->db->fetchOne(
                "SELECT COUNT(*) AS total, MIN(updated_at) AS oldest_affected_at
                 FROM provision_tasks
                 WHERE status = 'pending'
                   AND updated_at < :threshold_at",
                ['threshold_at' => $thresholdAt]
            ) ?? [];
            $candidateCount = (int) ($preview['total'] ?? 0);
            $oldestAffectedAt = (string) ($preview['oldest_affected_at'] ?? '');

            if ($candidateCount <= 0) {
                SessionAuth::flash('success', 'Stale pending purge preview found 0 rows. deleted=0 skipped=0');
                return Response::redirect('/admin');
            }

            $deleted = $this->db->execute(
                "DELETE FROM provision_tasks
                 WHERE status = 'pending'
                   AND updated_at < :threshold_at",
                ['threshold_at' => $thresholdAt]
            );
            $skipped = max(0, $candidateCount - $deleted);
            SessionAuth::flash(
                'success',
                'Stale pending purge finished. deleted=' . $deleted . ' skipped=' . $skipped
                . ($oldestAffectedAt !== '' ? ' oldest=' . $oldestAffectedAt : '')
            );
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to purge stale pending tasks: ' . $this->displayThrowableMessage($throwable));
        }

        return Response::redirect('/admin');
    }


    public function repairProvisionQueue(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $nodeId = (int) $request->input('repair_node_id', 2);
            $mode = (string) $request->input('repair_mode', 'reclaim_expired_running');
            if (!in_array($mode, ['reclaim_expired_running', 'collapse_duplicate_pending'], true)) {
                $mode = 'reclaim_expired_running';
            }

            $affected = 0;
            if ($mode === 'reclaim_expired_running') {
                $affected = $this->reclaimExpiredRunningProvisionTasks($nodeId);
                SessionAuth::flash('success', 'Provision queue repair reclaimed expired running task(s). node=' . ($nodeId > 0 ? $nodeId : 'all') . ' affected=' . $affected);
            } else {
                $affected = $this->collapseDuplicatePendingProvisionTasks($nodeId);
                SessionAuth::flash('success', 'Provision queue repair collapsed duplicate pending task(s). node=' . ($nodeId > 0 ? $nodeId : 'all') . ' affected=' . $affected);
            }
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to repair provision queue: ' . $this->displayThrowableMessage($throwable));
        }

        return Response::redirect('/admin');
    }

    private function reclaimExpiredRunningProvisionTasks(int $nodeId): int
    {
        $params = [
            'pending_status' => 'pending',
            'running_status' => 'running',
            'updated_at' => gmdate('Y-m-d H:i:s'),
            'error_message' => 'reclaimed expired running task by admin queue repair',
            'now' => gmdate('Y-m-d H:i:s'),
        ];
        $nodeFilter = '';
        if ($nodeId > 0) {
            $nodeFilter = ' AND node_id = :repair_node_id';
            $params['repair_node_id'] = $nodeId;
        }

        return $this->db->execute(
            'UPDATE provision_tasks
             SET status = :pending_status,
                 lease_expires_at = NULL,
                 attempt_count = GREATEST(attempt_count - 1, 0),
                 updated_at = :updated_at,
                 error_message = :error_message
             WHERE status = :running_status
               AND lease_expires_at IS NOT NULL
               AND lease_expires_at < :now' . $nodeFilter,
            $params
        );
    }

    private function collapseDuplicatePendingProvisionTasks(int $nodeId): int
    {
        $now = gmdate('Y-m-d H:i:s');
        $resultJson = json_encode([
            'note' => 'superseded_by_admin_queue_repair',
            'node_id' => $nodeId > 0 ? $nodeId : null,
            'mode' => 'collapse_duplicate_pending',
            'collapsed_at' => $now,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $innerNodeFilter = '';
        $outerNodeFilter = '';
        $params = [
            'completed_status' => 'completed',
            'result_json' => $resultJson,
            'updated_at' => $now,
            'group_pending_status' => 'pending',
            'task_pending_status' => 'pending',
        ];
        if ($nodeId > 0) {
            $innerNodeFilter = ' AND node_id = :group_node_id';
            $outerNodeFilter = ' AND t.node_id = :task_node_id';
            $params['group_node_id'] = $nodeId;
            $params['task_node_id'] = $nodeId;
        }

        return $this->db->execute(
            'UPDATE provision_tasks
             SET status = :completed_status,
                 result_json = :result_json,
                 error_message = NULL,
                 lease_expires_at = NULL,
                 updated_at = :updated_at
             WHERE id IN (
                 SELECT id FROM (
                     SELECT t.id
                     FROM provision_tasks t
                     INNER JOIN (
                         SELECT node_id, action, service_id, forward_id, endpoint_id, MAX(id) AS keep_id
                         FROM provision_tasks
                         WHERE status = :group_pending_status' . $innerNodeFilter . '
                         GROUP BY node_id, action, service_id, forward_id, endpoint_id
                         HAVING COUNT(*) > 1
                     ) duplicate_groups
                       ON duplicate_groups.node_id = t.node_id
                      AND duplicate_groups.action = t.action
                      AND duplicate_groups.service_id = t.service_id
                      AND duplicate_groups.forward_id = t.forward_id
                      AND duplicate_groups.endpoint_id = t.endpoint_id
                     WHERE t.status = :task_pending_status
                       AND t.id <> duplicate_groups.keep_id' . $outerNodeFilter . '
                 ) duplicate_pending_rows
             )',
            $params
        );
    }

    /**
     * @return array{pending_count:int,running_count:int,failed_count:int,oldest_pending_at:string,oldest_running_at:string}
     */
    private function taskQueueHealth(): array
    {
        $row = $this->db->fetchOne(
            "SELECT
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) AS running_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
                MIN(CASE WHEN status = 'pending' THEN updated_at END) AS oldest_pending_at,
                MIN(CASE WHEN status = 'running' THEN updated_at END) AS oldest_running_at
             FROM provision_tasks"
        ) ?? [];

        return [
            'pending_count' => (int) ($row['pending_count'] ?? 0),
            'running_count' => (int) ($row['running_count'] ?? 0),
            'failed_count' => (int) ($row['failed_count'] ?? 0),
            'oldest_pending_at' => (string) ($row['oldest_pending_at'] ?? ''),
            'oldest_running_at' => (string) ($row['oldest_running_at'] ?? ''),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentFailedProvisionTasks(int $limit = 20): array
    {
        return $this->db->fetchAll(
            'SELECT t.id, t.node_id, t.action, t.forward_id, t.endpoint_id, t.attempt_count, t.updated_at, t.error_message, t.result_json, t.payload_json, n.name AS node_name
             FROM provision_tasks t
             LEFT JOIN nodes n ON n.id = t.node_id
             WHERE t.status = :status
             ORDER BY t.updated_at DESC, t.id DESC
             LIMIT ' . max(1, $limit),
            ['status' => 'failed']
        );
    }

    /**
     * @param array{pending_count:int,running_count:int,failed_count:int,oldest_pending_at:string,oldest_running_at:string} $queueHealth
     */
    private function renderTaskQueueHealthSection(array $queueHealth, string $csrf): string
    {
        return '
            <section class="card" style="margin-top:20px;">
              <div class="hero" style="margin-bottom:14px;">
                <div>
                  <h3>Task Queue Health</h3>
                  <div class="muted">Fast queue triage. This only purges stale rows where status = \'pending\'. Running work is never touched.</div>
                </div>
              </div>
              <div class="grid three">
                <section class="card"><div class="muted">Pending</div><div class="stats">' . (int) $queueHealth['pending_count'] . '</div></section>
                <section class="card"><div class="muted">Running</div><div class="stats">' . (int) $queueHealth['running_count'] . '</div></section>
                <section class="card"><div class="muted">Failed</div><div class="stats">' . (int) $queueHealth['failed_count'] . '</div></section>
              </div>
              <div class="grid two" style="margin-top:14px;">
                <div class="muted">Oldest pending: ' . $this->escape($queueHealth['oldest_pending_at'] !== '' ? $queueHealth['oldest_pending_at'] : 'none') . '</div>
                <div class="muted">Oldest running: ' . $this->escape($queueHealth['oldest_running_at'] !== '' ? $queueHealth['oldest_running_at'] : 'none') . '</div>
              </div>
              <form class="actions" method="post" action="/admin/provision-tasks/purge-stale" style="margin-top:14px;">
                <input type="hidden" name="csrf_token" value="' . $csrf . '">
                <label class="muted" for="purge-threshold">Stale pending purge</label>
                <select id="purge-threshold" name="threshold_minutes">
                  <option value="15">15 min</option>
                  <option value="30" selected>30 min</option>
                  <option value="60">60 min</option>
                </select>
                <button type="submit" class="button danger" onclick="return confirm(\'Delete stale pending tasks only?\');">Purge stale pending</button>
              </form>
              <form class="actions" method="post" action="/admin/provision-queue/repair" style="margin-top:14px;">
                <input type="hidden" name="csrf_token" value="' . $csrf . '">
                <label class="muted" for="repair-node-id">Repair node</label>
                <input id="repair-node-id" name="repair_node_id" type="number" min="0" value="2" style="max-width:90px;">
                <label class="muted" for="repair-mode">Mode</label>
                <select id="repair-mode" name="repair_mode">
                  <option value="reclaim_expired_running" selected>Reclaim expired running</option>
                  <option value="collapse_duplicate_pending">Collapse duplicate pending</option>
                </select>
                <button type="submit" class="button secondary" onclick="return confirm(\'Repair provision queue rows without deleting history?\');">Repair queue</button>
              </form>
              <form class="actions" method="post" action="/admin/provision-queue/republish-endpoint" style="margin-top:14px;">
                <input type="hidden" name="csrf_token" value="' . $csrf . '">
                <label class="muted" for="republish-node-id">Republish node</label>
                <input id="republish-node-id" name="repair_node_id" type="number" min="1" value="2" style="max-width:90px;">
                <label class="muted" for="republish-forward-id">Forward</label>
                <input id="republish-forward-id" name="repair_forward_id" type="number" min="1" value="47" style="max-width:90px;">
                <label class="muted" for="republish-endpoint-id">Endpoint</label>
                <input id="republish-endpoint-id" name="repair_endpoint_id" type="number" min="1" value="456" style="max-width:90px;">
                <button type="submit" class="button secondary" onclick="return confirm(\'Republish this one endpoint only?\');">Republish endpoint</button>
              </form>
            </section>';
    }

    /**
     * @param array<int, array<string, mixed>> $tasks
     */
    private function renderRecentFailedTasksSection(array $tasks): string
    {
        $rows = '';
        foreach ($tasks as $task) {
            $summary = trim((string) ($task['error_message'] ?? ''));
            if ($summary === '') {
                $summary = 'No error message recorded.';
            }
            $details = trim((string) ($task['result_json'] ?? ''));
            $payload = trim((string) ($task['payload_json'] ?? ''));
            $actionHint = 'inspect node heartbeat';
            if (str_contains(strtolower($summary), 'pending')) {
                $actionHint = 'purge stale pending';
            } elseif (str_contains(strtolower((string) ($task['action'] ?? '')), 'publish')) {
                $actionHint = 'republish';
            }

            $rows .= '<tr>
                <td>#' . (int) ($task['id'] ?? 0) . '</td>
                <td>' . $this->escape((string) ($task['node_name'] ?? $task['node_id'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($task['action'] ?? '')) . '</td>
                <td>' . (int) ($task['forward_id'] ?? 0) . '</td>
                <td>' . (int) ($task['endpoint_id'] ?? 0) . '</td>
                <td>' . (int) ($task['attempt_count'] ?? 0) . '</td>
                <td>' . $this->escape((string) ($task['updated_at'] ?? '')) . '</td>
                <td>' . $this->escape($summary) . '</td>
                <td>' . $this->escape($actionHint) . '</td>
                <td><details><summary>Open details</summary><div style="margin-top:10px;"><pre>' . $this->escape($summary . "\n\nresult_json:\n" . $details . "\n\npayload_json:\n" . $payload) . '</pre></div></details></td>
            </tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="10" class="muted">No failed tasks recently.</td></tr>';
        }

        return '
            <section class="card" style="margin-top:20px;">
              <h3>Recent Failed Tasks</h3>
              <div class="table-wrap">
                <table>
                  <thead><tr><th>ID</th><th>Node</th><th>Action</th><th>Forward</th><th>Endpoint</th><th>Attempts</th><th>Updated</th><th>Error</th><th>Hint</th><th>Details</th></tr></thead>
                  <tbody>' . $rows . '</tbody>
                </table>
              </div>
            </section>';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function renderWorstLegsSection(array $rows): string
    {
        $bodyRows = '';
        foreach ($rows as $row) {
            $bodyRows .= '<tr>
                <td>' . $this->escape((string) ($row['observer_type'] ?? '')) . '</td>
                <td>' . (int) ($row['observer_id'] ?? 0) . '</td>
                <td>' . (int) ($row['forward_id'] ?? 0) . '</td>
                <td>' . $this->escape((string) ($row['protocol'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($row['host'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($row['state'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($row['effective_weight'] ?? '0')) . '</td>
                <td>' . $this->escape((string) ($row['updated_at'] ?? '')) . '</td>
            </tr>';
        }
        if ($bodyRows === '') {
            $bodyRows = '<tr><td colspan="8" class="muted">No reputation data yet.</td></tr>';
        }

        return '
            <section class="card" style="margin-top:20px;">
              <h3>Worst Legs Right Now</h3>
              <div class="table-wrap">
                <table>
                  <thead><tr><th>Observer</th><th>ID</th><th>Forward</th><th>Protocol</th><th>Host</th><th>State</th><th>Weight</th><th>Updated</th></tr></thead>
                  <tbody>' . $bodyRows . '</tbody>
                </table>
              </div>
            </section>';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $events
     */
    private function renderBondLegReputationDiagnosticsSection(array $rows, array $events): string
    {
        $currentRows = '';
        foreach ($rows as $row) {
            $currentRows .= '<tr>
                <td>' . $this->escape((string) ($row['observer_type'] ?? '')) . '</td>
                <td>' . (int) ($row['observer_id'] ?? 0) . '</td>
                <td>' . (int) ($row['forward_id'] ?? 0) . '</td>
                <td>' . $this->escape((string) ($row['protocol'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($row['host'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($row['state'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($row['score'] ?? '0')) . '</td>
                <td>' . $this->escape((string) ($row['effective_weight'] ?? '0')) . '</td>
                <td>' . $this->escape((string) ($row['updated_at'] ?? '')) . '</td>
            </tr>';
        }
        if ($currentRows === '') {
            $currentRows = '<tr><td colspan="9" class="muted">No reputation data yet.</td></tr>';
        }

        $eventRows = '';
        foreach ($events as $event) {
            $eventRows .= '<tr>
                <td>' . $this->escape((string) ($event['observer_type'] ?? '')) . '</td>
                <td>' . (int) ($event['observer_id'] ?? 0) . '</td>
                <td>' . (int) ($event['forward_id'] ?? 0) . '</td>
                <td>' . $this->escape((string) ($event['protocol'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($event['host'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($event['from_state'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($event['to_state'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($event['reason'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($event['observed_at'] ?? '')) . '</td>
            </tr>';
        }
        if ($eventRows === '') {
            $eventRows = '<tr><td colspan="9" class="muted">No recent reputation events.</td></tr>';
        }

        return '
              <section class="card">
                <h3>Bond Leg Reputation</h3>
                <div class="muted" style="margin-bottom:12px;">Reference only. Runtime already adapts automatically. This panel view is for visibility, not routing control.</div>
                <div class="table-wrap" style="margin-bottom:16px;">
                  <table>
                    <thead><tr><th>Observer</th><th>ID</th><th>Forward</th><th>Protocol</th><th>Host</th><th>State</th><th>Score</th><th>Weight</th><th>Updated</th></tr></thead>
                    <tbody>' . $currentRows . '</tbody>
                  </table>
                </div>
                <div class="table-wrap">
                  <table>
                    <thead><tr><th>Observer</th><th>ID</th><th>Forward</th><th>Protocol</th><th>Host</th><th>From</th><th>To</th><th>Reason</th><th>Observed</th></tr></thead>
                    <tbody>' . $eventRows . '</tbody>
                  </table>
                </div>
              </section>';
    }

    public function tenantsPage(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        $view = PrecisionGridUi::normalizeView(
            (string) $request->input('view', 'list'),
            ['list', 'create', 'edit'],
            'list'
        );

        $registration = $this->registrationService();
        $registrationSettings = $registration->registrationSettings();
        $inviteCodes = $registration->listInviteCodes();
        $defaults = $this->provisioning?->defaultFeatureFlags() ?? [];
        $editId = (int) $request->input('edit', 0);
        if ($editId > 0) {
            $view = 'edit';
        }
        $editing = $editId > 0 ? $this->db->fetchOne('SELECT * FROM users WHERE id = :id AND role = :role LIMIT 1', ['id' => $editId, 'role' => 'tenant']) : null;
        $editingFlags = $editing !== null ? $this->provisioning?->decodeFeatureFlags((string) ($editing['feature_flags_json'] ?? '')) ?? $defaults : $defaults;
        $tenants = $this->db->fetchAll(
            'SELECT u.id, u.name, u.email, u.status, u.feature_flags_json, u.email_verified_at, COUNT(s.id) AS service_count
             FROM users u
             LEFT JOIN services s ON s.user_id = u.id
             WHERE u.role = :role
             GROUP BY u.id, u.name, u.email, u.status, u.feature_flags_json, u.email_verified_at
             ORDER BY u.updated_at DESC, u.id DESC',
            ['role' => 'tenant']
        );

        $rows = '';
        $mobileRows = '';
        foreach ($tenants as $tenant) {
            $tenantId = (int) $tenant['id'];
            $tenantName = (string) $tenant['name'];
            $tenantEmail = (string) $tenant['email'];
            $tenantStatus = (string) $tenant['status'];
            $verified = !empty($tenant['email_verified_at']);
            $commands = '<a href="/admin/tenants?view=edit&amp;edit=' . $tenantId . '">Edit</a>
                <form class="inline" method="post" action="/admin/tenants/' . $tenantId . '/delete">
                    <input type="hidden" name="csrf_token" value="' . $this->escape(SessionAuth::csrfToken()) . '">
                    <button type="submit" class="danger">Delete</button>
                </form>';
            $rows .= '<tr>
                <td>' . PrecisionGridUi::resourceCell($tenantName, $tenantEmail) . '</td>
                <td>' . PrecisionGridUi::status(ucfirst($tenantStatus), $tenantStatus === 'active' ? 'ok' : 'error') . '</td>
                <td>' . PrecisionGridUi::status($verified ? 'Verified' : 'Not verified', $verified ? 'ok' : 'warning') . '</td>
                <td>' . (int) $tenant['service_count'] . '</td>
                <td>' . PrecisionGridUi::rowMenu($tenantName . ' actions', $commands) . '</td>
            </tr>';
            $mobileRows .= '<article class="pg-mobile-resource-row"><header>'
                . PrecisionGridUi::resourceCell($tenantName, $tenantEmail)
                . PrecisionGridUi::status(ucfirst($tenantStatus), $tenantStatus === 'active' ? 'ok' : 'error')
                . '</header><dl><div><dt>Verification</dt><dd>' . ($verified ? 'Verified' : 'Not verified') . '</dd></div><div><dt>Services</dt><dd>' . (int) $tenant['service_count'] . '</dd></div></dl><footer>'
                . PrecisionGridUi::rowMenu($tenantName . ' actions', $commands) . '</footer></article>';
        }

        $inviteRows = '';
        foreach ($inviteCodes as $code) {
            $isActive = (int) ($code['is_active'] ?? 0) === 1;
            $inviteRows .= '<tr>
                <td><code>' . $this->escape((string) $code['code']) . '</code></td>
                <td>' . $this->escape((string) ($code['label'] ?? '')) . '</td>
                <td>' . (int) ($code['used_count'] ?? 0) . ' / ' . (int) ($code['max_uses'] ?? 0) . '</td>
                <td>' . $this->escape((string) ($code['expires_at'] ?? 'never')) . '</td>
                <td>' . ($isActive ? '<span class="badge success">active</span>' : '<span class="badge danger">inactive</span>') . '</td>
                <td>
                    <form class="inline" method="post" action="/admin/tenant-registration/codes/' . (int) $code['id'] . '/toggle">
                        <input type="hidden" name="csrf_token" value="' . $this->escape(SessionAuth::csrfToken()) . '">
                        <button type="submit" class="button secondary">' . ($isActive ? 'Disable' : 'Enable') . '</button>
                    </form>
                </td>
            </tr>';
        }
        if ($inviteRows === '') {
            $inviteRows = '<tr><td colspan="6" class="muted">No registration codes generated yet.</td></tr>';
        }

        $csrf = $this->escape(SessionAuth::csrfToken());
        $requireCodeChecked = $registrationSettings['require_invite_code'] ? ' checked' : '';
        $tabs = PrecisionGridUi::viewTabs([
            ['key' => 'list', 'label' => 'Tenants', 'href' => '/admin/tenants?view=list'],
            ['key' => 'create', 'label' => 'Create Tenant', 'href' => '/admin/tenants?view=create'],
        ], $view);
        $formView = '
              <section class="card">
                <h3>' . ($editing !== null ? 'Edit Tenant' : 'Create Tenant') . '</h3>
                <form method="post" action="/admin/tenants">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <input type="hidden" name="id" value="' . (int) ($editing['id'] ?? 0) . '">
                  <div class="form-grid">
                    <div class="field"><label>Name</label><input type="text" name="name" value="' . $this->escape((string) ($editing['name'] ?? '')) . '" required></div>
                    <div class="field"><label>Email</label><input type="email" name="email" value="' . $this->escape((string) ($editing['email'] ?? '')) . '" required></div>
                    <div class="field"><label>Password</label><input type="password" name="password" ' . ($editing === null ? 'required' : '') . ' placeholder="' . ($editing !== null ? 'Leave blank to keep current password' : '') . '"></div>
                    <div class="field"><label>Status</label>
                      <select name="status"><option value="active"' . (((string) ($editing['status'] ?? 'active')) === 'active' ? ' selected' : '') . '>active</option><option value="pending_verification"' . (((string) ($editing['status'] ?? '')) === 'pending_verification' ? ' selected' : '') . '>pending_verification</option><option value="suspended"' . (((string) ($editing['status'] ?? '')) === 'suspended' ? ' selected' : '') . '>suspended</option></select>
                    </div>
                  </div>
                  <div class="field" style="margin-top:14px;">
                    <label>Tenant Feature Overrides</label>
                    <div class="stack">' . $this->renderFeatureCheckboxes($defaults, 'feature_flags', $editingFlags) . '</div>
                  </div>
                  <div class="actions"><button class="button" type="submit">Save Tenant</button>' .
                      ($editing !== null ? '<a class="button secondary" href="/admin/tenants">Cancel</a>' : '') .
                  '</div>
                </form>
              </section>';
        $listView = '
              <section class="pg-sheet" data-mobile-resources="true">
                <div class="pg-sheet-toolbar"><strong>Tenant Directory</strong><span class="muted">' . count($tenants) . ' accounts</span></div>
                <div class="pg-sheet-scroll">
                  <table class="pg-sheet-table">
                    <thead><tr><th>Tenant / Email</th><th>Status</th><th>Verification</th><th>Services</th><th><span class="pg-visually-hidden">Actions</span></th></tr></thead>
                    <tbody>' . $rows . '</tbody>
                  </table>
                </div>
                <div class="pg-mobile-resource-list">' . $mobileRows . '</div>
              </section>';
        $registrationView = '
            <div class="grid two" style="margin-top:20px;">
              <section class="card">
                <h3>Tenant Registration Settings</h3>
                <form method="post" action="/admin/tenant-registration/settings">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <label style="display:flex;align-items:center;gap:10px;">
                    <input type="checkbox" name="require_invite_code" value="1"' . $requireCodeChecked . '>
                    <span>Require registration code for tenant self-signup</span>
                  </label>
                  <p class="muted" style="margin-top:12px;">SMTP delivery is read from <code>.env</code>. New self-registered tenants stay in <code>pending_verification</code> until they verify their email.</p>
                  <div class="actions"><button class="button" type="submit">Save Registration Settings</button></div>
                </form>
                <hr style="border:none;border-top:1px solid rgba(19,34,56,0.08);margin:22px 0;">
                <h3>Generate Registration Code</h3>
                <form method="post" action="/admin/tenant-registration/codes">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <div class="form-grid">
                    <div class="field"><label>Label</label><input type="text" name="label" placeholder="April campaign"></div>
                    <div class="field"><label>Max Uses</label><input type="number" name="max_uses" value="1" min="1"></div>
                    <div class="field"><label>Expires At (UTC)</label><input type="text" name="expires_at" placeholder="2026-05-01 00:00:00"></div>
                  </div>
                  <div class="actions"><button class="button" type="submit">Generate Code</button></div>
                </form>
              </section>
              <section class="card">
                <h3>Registration Codes</h3>
                <div class="table-wrap">
                  <table>
                    <thead><tr><th>Code</th><th>Label</th><th>Usage</th><th>Expires</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>' . $inviteRows . '</tbody>
                  </table>
                </div>
              </section>
            </div>';

        $viewBody = match ($view) {
            'create' => $formView . $registrationView,
            'edit' => $formView,
            default => $listView,
        };
        $body = $tabs . $viewBody;

        return $this->adminPage('Tenants', $body, $viewer, 'tenants');
    }

    public function tenantRegistrationPage(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        $registration = $this->registrationService();
        $registrationSettings = $registration->registrationSettings();
        $inviteCodes = $registration->listInviteCodes();

        $inviteRows = '';
        foreach ($inviteCodes as $code) {
            $isActive = (int) ($code['is_active'] ?? 0) === 1;
            $inviteRows .= '<tr>
                <td><code>' . $this->escape((string) $code['code']) . '</code></td>
                <td>' . $this->escape((string) ($code['label'] ?? '')) . '</td>
                <td>' . (int) ($code['used_count'] ?? 0) . ' / ' . (int) ($code['max_uses'] ?? 0) . '</td>
                <td>' . $this->escape((string) ($code['expires_at'] ?? 'never')) . '</td>
                <td>' . ($isActive ? '<span class="badge success">active</span>' : '<span class="badge danger">inactive</span>') . '</td>
                <td>
                    <form class="inline" method="post" action="/admin/tenant-registration/codes/' . (int) $code['id'] . '/toggle">
                        <input type="hidden" name="csrf_token" value="' . $this->escape(SessionAuth::csrfToken()) . '">
                        <button type="submit" class="button secondary">' . ($isActive ? 'Disable' : 'Enable') . '</button>
                    </form>
                </td>
            </tr>';
        }
        if ($inviteRows === '') {
            $inviteRows = '<tr><td colspan="6" class="muted">No registration codes generated yet.</td></tr>';
        }

        $csrf = $this->escape(SessionAuth::csrfToken());
        $requireCodeChecked = $registrationSettings['require_invite_code'] ? ' checked' : '';
        $body = '
            <div class="hero">
              <div>
                <h2>Registration Codes</h2>
                <p class="muted">Manage tenant self-signup policy and invite codes. Tenant account creation remains on the Tenants page.</p>
              </div>
            </div>
            <div class="grid two">
              <section class="card">
                <h3>Tenant Registration Settings</h3>
                <form method="post" action="/admin/tenant-registration/settings">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <label style="display:flex;align-items:center;gap:10px;">
                    <input type="checkbox" name="require_invite_code" value="1"' . $requireCodeChecked . '>
                    <span>Require registration code for tenant self-signup</span>
                  </label>
                  <p class="muted" style="margin-top:12px;">SMTP delivery is read from <code>.env</code>. New self-registered tenants stay in <code>pending_verification</code> until they verify their email.</p>
                  <div class="actions"><button class="button" type="submit">Save Registration Settings</button></div>
                </form>
                <hr style="border:none;border-top:1px solid rgba(19,34,56,0.08);margin:22px 0;">
                <h3>Generate Registration Code</h3>
                <form method="post" action="/admin/tenant-registration/codes">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <div class="form-grid">
                    <div class="field"><label>Label</label><input type="text" name="label" placeholder="April campaign"></div>
                    <div class="field"><label>Max Uses</label><input type="number" name="max_uses" value="1" min="1"></div>
                    <div class="field"><label>Expires At (UTC)</label><input type="text" name="expires_at" placeholder="2026-05-01 00:00:00"></div>
                  </div>
                  <div class="actions"><button class="button" type="submit">Generate Code</button></div>
                </form>
              </section>
              <section class="card">
                <h3>Registration Codes</h3>
                <div class="table-wrap">
                  <table>
                    <thead><tr><th>Code</th><th>Label</th><th>Usage</th><th>Expires</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>' . $inviteRows . '</tbody>
                  </table>
                </div>
              </section>
            </div>';

        return $this->adminPage('Registration Codes', $body, $viewer, 'registration');
    }

    public function plansPage(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        $plans = $this->provisioning?->listPlans() ?? [];
        $forwardRoutes = $this->db->fetchAll(
            'SELECT * FROM forward_routes WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
        );
        $defaults = $this->provisioning?->defaultFeatureFlags() ?? [];
        $editId = (int) $request->input('edit', 0);
        $editing = null;
        foreach ($plans as $plan) {
            if ((int) $plan['id'] === $editId) {
                $editing = $plan;
                break;
            }
        }
        $editingAllowedForwardRouteIds = array_map(
            static fn(array $route): int => (int) ($route['id'] ?? 0),
            $editing['allowed_forward_routes'] ?? []
        );
        $rows = '';
        foreach ($plans as $plan) {
            $allowedForwardRoutes = array_map(
                static fn(array $route): string => trim((string) (($route['tenant_label'] ?? '') !== '' ? $route['tenant_label'] : ($route['name'] ?? ''))),
                $plan['allowed_forward_routes'] ?? []
            );
            $forwardRouteSummary = $allowedForwardRoutes !== []
                ? $this->escape(implode(', ', $allowedForwardRoutes))
                : '<span class="badge danger">0</span>';
            $billingSummary = ((string) ($plan['billing_mode'] ?? 'monthly_bundle') === 'metered')
                ? 'Metered / ' . $this->escape((string) ($plan['billing_currency'] ?? 'CNY')) . ' ' . $this->escape((string) ($plan['monthly_base_fee'] ?? '0.00')) . ' base + ' . $this->escape((string) ($plan['traffic_price_per_gb'] ?? '0.0000')) . ' / GB'
                : 'Bundle / ' . $this->escape((string) ($plan['billing_currency'] ?? 'CNY')) . ' ' . $this->escape((string) ($plan['monthly_base_fee'] ?? '0.00')) . ' + ' . (int) ($plan['monthly_included_traffic_gb'] ?? 0) . ' GB / month';
            $billingSummary .= ' / Reset ' . $this->escape((string) ($plan['billing_currency'] ?? 'CNY')) . ' ' . $this->escape((string) ($plan['traffic_reset_price'] ?? '0.00'));
            $billingSummary .= ' / +' . (int) ($plan['bandwidth_step_mbps'] ?? 0) . ' Mbps => '
                . $this->escape((string) ($plan['billing_currency'] ?? 'CNY')) . ' ' . $this->escape((string) ($plan['bandwidth_step_price'] ?? '0.00'));
            $billingSummary .= ' / Included Clients ' . (int) ($plan['included_client_count'] ?? 0)
                . ' + Extra Client '
                . $this->escape((string) ($plan['billing_currency'] ?? 'CNY')) . ' ' . $this->escape((string) ($plan['extra_client_price'] ?? '0.00'));
            $billingSummary .= ' / Max Client Profiles ' . (int) ($plan['max_client_profiles'] ?? 1);
            $billingSummary .= ' / Max Forward Rules ' . (int) ($plan['max_forward_rule_quantity'] ?? 64);
            $billingSummary .= ' / Devices per Client ' . (int) ($plan['default_client_online_device_limit'] ?? 1);
            $visibilitySummary = (int) ($plan['is_tenant_visible'] ?? 1) === 1
                ? '<span class="badge success">tenant visible</span>'
                : '<span class="badge info">internal test</span>';
            $geoLockSummary = (int) ($plan['geo_lock_enabled'] ?? 0) === 1
                ? '<span class="badge danger">geo lock</span>'
                : '<span class="badge info">geo open</span>';
            $rows .= '<tr>
                <td>' . (int) $plan['id'] . '</td>
                <td>' . $this->escape((string) $plan['name']) . '</td>
                <td>' . $this->escape((string) $plan['product_code']) . '</td>
                <td>' . (int) $plan['default_bandwidth_limit_mbps'] . ' Mbps</td>
                <td>' . (int) $plan['default_traffic_limit_gb'] . ' GB</td>
                <td>' . $billingSummary . '</td>
                <td>' . $forwardRouteSummary . '</td>
                <td>' . $visibilitySummary . '<br>' . $geoLockSummary . '</td>
                <td>' . ((int) $plan['is_active'] === 1 ? '<span class="badge success">active</span>' : '<span class="badge danger">inactive</span>') . '</td>
                <td>
                    <div class="actions">
                        <a class="button ghost" href="/admin/plans?edit=' . (int) $plan['id'] . '">Edit</a>
                        <form class="inline" method="post" action="/admin/plans/' . (int) $plan['id'] . '/delete">
                            <input type="hidden" name="csrf_token" value="' . $this->escape(SessionAuth::csrfToken()) . '">
                            <button type="submit" class="button danger">Delete</button>
                        </form>
                    </div>
                </td>
            </tr>';
        }

        $csrf = $this->escape(SessionAuth::csrfToken());
        $body = '
            <div class="hero">
              <div>
                <h2>Plans</h2>
                <p class="muted">Product entitlements, billing, and tenant routing.</p>
              </div>
            </div>
            <div class="grid two">
              <section class="card">
                <h3>' . ($editing !== null ? 'Edit Plan' : 'Create Plan') . '</h3>
                <form method="post" action="/admin/plans">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <input type="hidden" name="id" value="' . (int) ($editing['id'] ?? 0) . '">
                  <div class="form-grid">
                    <div class="field"><label>Name</label><input type="text" name="name" value="' . $this->escape((string) ($editing['name'] ?? '')) . '" required></div>
                    <div class="field"><label>Product Code</label><input type="text" name="product_code" value="' . $this->escape((string) ($editing['product_code'] ?? '')) . '" required></div>
                    <div class="field"><label>Bandwidth Mbps</label><input type="number" name="default_bandwidth_limit_mbps" value="' . $this->escape((string) ($editing['default_bandwidth_limit_mbps'] ?? 100)) . '" min="1" required></div>
                    <div class="field"><label>Traffic GB</label><input type="number" name="default_traffic_limit_gb" value="' . $this->escape((string) ($editing['default_traffic_limit_gb'] ?? 1000)) . '" min="0" required></div>
                    <div class="field"><label>Status</label>
                      <select name="is_active"><option value="1"' . ((int) ($editing['is_active'] ?? 1) === 1 ? ' selected' : '') . '>active</option><option value="0"' . ((int) ($editing['is_active'] ?? 1) === 0 ? ' selected' : '') . '>inactive</option></select>
                    </div>
                    <div class="field"><label>Description</label><textarea name="description" placeholder="One feature per line for the tenant storefront card.">' . $this->escape((string) ($editing['description'] ?? '')) . '</textarea><p class="muted">Use one line per tenant-visible feature. These lines render directly on the tenant purchase card.</p></div>
                    <div class="field"><label>Tenant Visibility</label>
                      <select name="is_tenant_visible"><option value="1"' . ((int) ($editing['is_tenant_visible'] ?? 1) === 1 ? ' selected' : '') . '>Visible to tenants</option><option value="0"' . ((int) ($editing['is_tenant_visible'] ?? 1) === 0 ? ' selected' : '') . '>Internal test package</option></select>
                      <p class="muted">Hidden plans stay active for internal/admin workflows, but do not appear on the tenant purchase page.</p>
                    </div>
                    <div class="field"><label><input type="checkbox" name="geo_lock_enabled" value="1"' . ((int) ($editing['geo_lock_enabled'] ?? 0) === 1 ? ' checked' : '') . '> 开启地域锁</label>
                      <p class="muted">登录与定期检查将按主省份、邻近省份和特殊省圈规则验证设备位置。</p>
                    </div>
                    <div class="field"><label>Billing Mode</label><select name="billing_mode"><option value="monthly_bundle"' . (((string) ($editing['billing_mode'] ?? 'monthly_bundle')) === 'monthly_bundle' ? ' selected' : '') . '>monthly_bundle</option><option value="metered"' . (((string) ($editing['billing_mode'] ?? 'monthly_bundle')) === 'metered' ? ' selected' : '') . '>metered</option></select></div>
                    <div class="field"><label>Billing Currency</label><input type="text" name="billing_currency" value="' . $this->escape((string) ($editing['billing_currency'] ?? 'CNY')) . '" placeholder="CNY"></div>
                    <div class="field"><label>Monthly Base Fee</label><input type="number" step="0.01" min="0" name="monthly_base_fee" value="' . $this->escape((string) ($editing['monthly_base_fee'] ?? '0.00')) . '"></div>
                    <div class="field"><label>Included Traffic GB / Month</label><input type="number" min="0" name="monthly_included_traffic_gb" value="' . $this->escape((string) ($editing['monthly_included_traffic_gb'] ?? ($editing['default_traffic_limit_gb'] ?? 1000))) . '"><p class="muted">For monthly bundle plans, this is the traffic restored every billing month. For metered plans, usually set this to 0.</p></div>
                    <div class="field"><label>Traffic Price / GB</label><input type="number" step="0.0001" min="0" name="traffic_price_per_gb" value="' . $this->escape((string) ($editing['traffic_price_per_gb'] ?? '0.0000')) . '"><p class="muted">Used by metered plans. Monthly bundle plans usually keep this at 0.</p></div>
                    <div class="field"><label>Traffic Reset Price</label><input type="number" step="0.01" min="0" name="traffic_reset_price" value="' . $this->escape((string) ($editing['traffic_reset_price'] ?? '0.00')) . '"><p class="muted">Charged when the tenant manually resets the current cycle traffic usage for one service.</p></div>
                    <div class="field"><label>Bandwidth Step Mbps</label><input type="number" min="0" name="bandwidth_step_mbps" value="' . $this->escape((string) ($editing['bandwidth_step_mbps'] ?? '0')) . '"><p class="muted">Example: 50 means every extra 50 Mbps can be billed as one step. 0 means no flexible bandwidth addon.</p></div>
                    <div class="field"><label>Bandwidth Step Price</label><input type="number" step="0.01" min="0" name="bandwidth_step_price" value="' . $this->escape((string) ($editing['bandwidth_step_price'] ?? '0.00')) . '"><p class="muted">Monthly surcharge for each extra bandwidth step.</p></div>
                    <div class="field"><label>Included Client Count</label><input type="number" min="0" name="included_client_count" value="' . $this->escape((string) ($editing['included_client_count'] ?? '0')) . '"><p class="muted">How many explicit Clients are included before extra monthly Client charges start.</p></div>
                    <div class="field"><label>Extra Client Price</label><input type="number" step="0.01" min="0" name="extra_client_price" value="' . $this->escape((string) ($editing['extra_client_price'] ?? '0.00')) . '"><p class="muted">Monthly surcharge for each explicit Client above the included count.</p></div>
                    <div class="field"><label>Max Client Profiles</label><input type="number" min="1" name="max_client_profiles" value="' . $this->escape((string) ($editing['max_client_profiles'] ?? '1')) . '" required><p class="muted">hard creation cap for independent client profiles. This is separate from billing included client count.</p></div>
                    <div class="field"><label>Max Forward Rule Quantity</label><input type="number" min="1" name="max_forward_rule_quantity" value="' . $this->escape((string) ($editing['max_forward_rule_quantity'] ?? '64')) . '" required><p class="muted">Maximum active forward rules allowed for each service on this plan.</p></div>
                    <div class="field"><label>Client Device Limit</label><input type="number" min="1" name="default_client_online_device_limit" value="' . $this->escape((string) ($editing['default_client_online_device_limit'] ?? '1')) . '" required><p class="muted">Tenant-visible default entitlement shown on the package card. Per-client enforcement still lives on the client record.</p></div>
                  </div>
                  <div class="field" style="margin-top:14px;">
                    <label>Forward Routes</label>
                    <p class="muted">Routes available to services on this plan.</p>
                    <div class="stack">' . $this->renderForwardRoutePlanCheckboxes($forwardRoutes, $editingAllowedForwardRouteIds) . '</div>
                  </div>
                  <div class="field" style="margin-top:14px;">
                    <label>Plan Feature Flags</label>
                    <div class="stack">' . $this->renderFeatureCheckboxes($defaults, 'feature_flags', $editing['feature_flags'] ?? $defaults) . '</div>
                  </div>
                  <div class="actions"><button class="button" type="submit">Save Plan</button>' .
                      ($editing !== null ? '<a class="button secondary" href="/admin/plans">Cancel</a>' : '') .
                  '</div>
                </form>
              </section>
              <section class="card">
                <h3>Plan List</h3>
                <div class="table-wrap">
                  <table>
                    <thead><tr><th>ID</th><th>Name</th><th>Code</th><th>Bandwidth</th><th>Traffic</th><th>Billing</th><th>Forward Routes</th><th>Visibility / Geo</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>' . $rows . '</tbody>
                  </table>
                </div>
              </section>
            </div>';

        return $this->adminPage('Plans', $body, $viewer, 'plans');
    }

    public function clientGroupsPage(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        $groups = $this->provisioning?->listClientGroups() ?? [];
        $rows = '';
        foreach ($groups as $group) {
            $rows .= '<tr>
                <td>' . (int) $group['id'] . '</td>
                <td>' . $this->escape((string) $group['group_key']) . '</td>
                <td>' . $this->escape((string) $group['name']) . '</td>
                <td>' . $this->escape((string) ($group['description'] ?? '')) . '</td>
                <td>' . (int) ($group['client_count'] ?? 0) . '</td>
            </tr>';
        }

        $csrf = $this->escape(SessionAuth::csrfToken());
        $body = '
            <div class="hero">
              <div>
                <h2>Client Groups</h2>
                <p class="muted">These match Zero Forwarder style server groups. Plans can authorize client groups.</p>
              </div>
            </div>
            <div class="grid two">
              <section class="card">
                <h3>Create Client Group</h3>
                <form method="post" action="/admin/client-groups">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <div class="form-grid">
                    <div class="field"><label>Group Key</label><input type="text" name="group_key" placeholder="cn-bgp" required></div>
                    <div class="field"><label>Name</label><input type="text" name="name" placeholder="CN BGP" required></div>
                  </div>
                  <div class="field" style="margin-top:14px;"><label>Description</label><input type="text" name="description"></div>
                  <div class="actions"><button class="button" type="submit">Save Client Group</button></div>
                </form>
              </section>
              <section class="card">
                <h3>Client Group List</h3>
                <div class="table-wrap">
                  <table>
                    <thead><tr><th>ID</th><th>Group Key</th><th>Name</th><th>Description</th><th>Clients</th></tr></thead>
                    <tbody>' . $rows . '</tbody>
                  </table>
                </div>
              </section>
            </div>';

        return $this->adminPage('Client Groups', $body, $viewer, 'client-groups');
    }

    public function credentialsPage(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        $profiles = $this->db->fetchAll(
            'SELECT c.*,
                    (SELECT COUNT(*) FROM nodes n WHERE n.credential_profile_id = c.id) AS node_count
             FROM credential_profiles c
             ORDER BY c.updated_at DESC, c.id DESC'
        );
        $editId = (int) $request->input('edit', 0);
        $editing = null;
        foreach ($profiles as $profile) {
            if ((int) $profile['id'] === $editId) {
                $editing = $profile;
                break;
            }
        }
        $editingConfig = $editing !== null ? $this->decodeJsonList($editing['config_json'] ?? null) : [];
        $certificates = $this->db->fetchAll(
            'SELECT s.*,
                    (SELECT COUNT(*) FROM nodes n WHERE n.ssl_certificate_id = s.id) AS node_count
             FROM ssl_certificates s
             ORDER BY s.updated_at DESC, s.id DESC'
        );
        $certEditId = (int) $request->input('cert_edit', 0);
        $editingCertificate = null;
        foreach ($certificates as $certificate) {
            if ((int) $certificate['id'] === $certEditId) {
                $editingCertificate = $certificate;
                break;
            }
        }
        $editingCertificateConfig = $editingCertificate !== null ? $this->decodeJsonList($editingCertificate['source_config_json'] ?? null) : [];
        $editingCertificateSourceType = (string) ($editingCertificate['source_type'] ?? 'uploaded');
        $editingCertificateDomains = $editingCertificate !== null ? $this->sslCertificateDomainsSummary($editingCertificate) : '';
        $editingCertificateHasStoredPair = trim((string) ($editingCertificate['cert_pem'] ?? '')) !== ''
            && trim((string) ($editingCertificate['key_pem'] ?? '')) !== '';
        $editingDnsPropagationSeconds = (int) ($editingCertificateConfig['dns_propagation_seconds'] ?? 60);
        if ($editingDnsPropagationSeconds <= 0) {
            $editingDnsPropagationSeconds = 60;
        }
        $sslTasks = $this->db->fetchAll(
            'SELECT t.*, s.name AS certificate_name, u.email AS requested_by_email
             FROM ssl_certificate_tasks t
             INNER JOIN ssl_certificates s ON s.id = t.certificate_id
             LEFT JOIN users u ON u.id = t.requested_by_user_id
             ORDER BY t.id DESC
             LIMIT 20'
        );
        $latestSslTasks = [];
        foreach ($sslTasks as $task) {
            $certificateId = (int) ($task['certificate_id'] ?? 0);
            if ($certificateId > 0 && !isset($latestSslTasks[$certificateId])) {
                $latestSslTasks[$certificateId] = $task;
            }
        }

        $rows = '';
        foreach ($profiles as $profile) {
            $config = $this->decodeJsonList($profile['config_json'] ?? null);
            $key = (string) ($config['cloudflare_global_key'] ?? '');
            $maskedKey = $key !== '' ? str_repeat('*', max(0, strlen($key) - 4)) . substr($key, -4) : '';
            $rows .= '<tr>
                <td>' . (int) $profile['id'] . '</td>
                <td>' . $this->escape((string) $profile['name']) . '</td>
                <td>' . $this->escape((string) $profile['provider']) . '</td>
                <td>' . $this->escape((string) ($config['cloudflare_email'] ?? '')) . '</td>
                <td>' . $this->escape($maskedKey) . '</td>
                <td>' . (int) ($profile['node_count'] ?? 0) . '</td>
                <td><a class="button ghost" href="/admin/credentials?edit=' . (int) $profile['id'] . '">Edit</a></td>
            </tr>';
        }
        $certificateRows = '';
        foreach ($certificates as $certificate) {
            $certificateRows .= '<tr>
                <td>' . (int) $certificate['id'] . '</td>
                <td>' . $this->escape((string) $certificate['name']) . '</td>
                <td>' . $this->escape($this->sslCertificateSourceLabel((string) ($certificate['source_type'] ?? ''))) . '</td>
                <td>' . $this->escape($this->sslCertificateDomainsSummary($certificate)) . '</td>
                <td>' . $this->escape((string) ($certificate['issuer_name'] ?? '')) . '</td>
                <td>' . $this->sslCertificateExpiryHtml($certificate) . '</td>
                <td>' . $this->escape($this->sslCertificateRenewalLabel((string) ($certificate['renewal_mode'] ?? ''))) . '</td>
                <td>' . $this->sslCertificateLatestTaskHtml($latestSslTasks[(int) $certificate['id']] ?? null) . '</td>
                <td>' . (int) ($certificate['node_count'] ?? 0) . '</td>
                <td><a class="button ghost" href="/admin/credentials?cert_edit=' . (int) $certificate['id'] . '">Edit</a></td>
            </tr>';
        }
        $taskRows = '';
        foreach ($sslTasks as $task) {
            $requester = trim((string) ($task['requested_by_email'] ?? ''));
            if ($requester === '') {
                $requester = !empty($task['requested_by_user_id']) ? 'user #' . (int) $task['requested_by_user_id'] : 'system';
            }

            $taskRows .= '<tr>
                <td>' . (int) $task['id'] . '</td>
                <td>' . $this->escape((string) ($task['certificate_name'] ?? '')) . '</td>
                <td>' . $this->escape($this->sslCertificateTaskTypeLabel((string) ($task['task_type'] ?? 'issue'))) . '</td>
                <td>' . $this->sslCertificateTaskStatusHtml((string) ($task['status'] ?? 'pending')) . '</td>
                <td>' . $this->escape($requester) . '</td>
                <td>' . $this->escape((string) (($task['started_at'] ?? '') !== '' ? $task['started_at'] : '-')) . '</td>
                <td>' . $this->escape((string) (($task['finished_at'] ?? '') !== '' ? $task['finished_at'] : '-')) . '</td>
                <td>' . $this->sslCertificateTaskLogHtml($task) . '</td>
            </tr>';
        }
        if ($taskRows === '') {
            $taskRows = '<tr><td colspan="8" class="muted">No SSL certificate tasks yet.</td></tr>';
        }
        $letsencryptCredentialOptions = '<option value="">Select Cloudflare DNS credential</option>';
        foreach ($profiles as $profile) {
            if ((string) ($profile['provider'] ?? '') !== 'cloudflare_global_key') {
                continue;
            }
            $value = (int) $profile['id'];
            $selected = (int) ($editingCertificateConfig['credential_profile_id'] ?? 0) === $value ? ' selected' : '';
            $letsencryptCredentialOptions .= '<option value="' . $value . '"' . $selected . '>' . $this->escape((string) $profile['name']) . '</option>';
        }
        $editingCertificateWildcard = !empty($editingCertificateConfig['wildcard']);
        $editingCertificateIssueNow = $editingCertificate === null;

        $csrf = $this->escape(SessionAuth::csrfToken());
        $body = '
            <div class="hero">
              <div>
                <h2>Credentials</h2>
                <p class="muted">Store reusable DNS credential profiles and reusable SSL certificates here. Slave nodes should reference library entries instead of keeping panel-sync certificate details inline.</p>
              </div>
            </div>
            <div class="grid two">
              <section class="card">
                <h3>' . ($editing !== null ? 'Edit DNS Credential Profile' : 'Create DNS Credential Profile') . '</h3>
                <p class="muted">DNS credential profiles are used when a slave needs DNS-auth Lets Encrypt and wildcard certificates. The current implementation stays focused on Cloudflare Global Key.</p>
                <form method="post" action="/admin/credentials">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <input type="hidden" name="id" value="' . (int) ($editing['id'] ?? 0) . '">
                  <div class="form-grid">
                    <div class="field"><label>Profile Name</label><input type="text" name="name" value="' . $this->escape((string) ($editing['name'] ?? '')) . '" required></div>
                    <div class="field"><label>Provider</label><select name="provider"><option value="cloudflare_global_key" selected>Cloudflare Global Key</option></select></div>
                    <div class="field"><label>Cloudflare Email</label><input type="text" name="cloudflare_email" value="' . $this->escape((string) ($editingConfig['cloudflare_email'] ?? '')) . '" placeholder="ops@example.com" required></div>
                    <div class="field"><label>Cloudflare Global Key</label><input type="password" name="cloudflare_global_key" value="' . $this->escape((string) ($editingConfig['cloudflare_global_key'] ?? '')) . '" placeholder="global api key" required></div>
                  </div>
                  <div class="actions"><button class="button" type="submit">Save Credential Profile</button>' . ($editing !== null ? '<a class="button secondary" href="/admin/credentials">Cancel</a>' : '') . '</div>
                </form>
              </section>
              <section class="card">
                <h3>DNS Credential Profiles</h3>
                <div class="table-wrap">
                  <table>
                    <thead><tr><th>ID</th><th>Name</th><th>Provider</th><th>Email</th><th>Key</th><th>Nodes</th><th>Actions</th></tr></thead>
                    <tbody>' . $rows . '</tbody>
                  </table>
                </div>
              </section>
            </div>
            <div class="grid two" style="margin-top:20px;">
              <section class="card">
                <h3>' . ($editingCertificate !== null ? 'Edit SSL Certificate' : 'Create SSL Certificate') . '</h3>
                <p class="muted">This library is where panel-managed certificate sync now lives. Save returns immediately. If you keep issue enabled, the panel will queue a backend SSL task and you can inspect the worker log below instead of waiting on the browser request.</p>
                <form method="post" action="/admin/credentials/ssl-certificates" enctype="multipart/form-data" data-ssl-certificate-form>
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <input type="hidden" name="id" value="' . (int) ($editingCertificate['id'] ?? 0) . '">
                  <div class="form-grid">
                    <div class="field"><label>Certificate Name</label><input type="text" name="name" value="' . $this->escape((string) ($editingCertificate['name'] ?? '')) . '" placeholder="hk-edge wildcard cert" required></div>
                    <div class="field"><label>Source Type</label><select name="source_type" data-ssl-source-type>
                      <option value="uploaded"' . ($editingCertificateSourceType === 'uploaded' ? ' selected' : '') . '>Uploaded existing PEM pair</option>
                      <option value="panel_letsencrypt"' . ($editingCertificateSourceType === 'panel_letsencrypt' ? ' selected' : '') . '>Panel Let&apos;s Encrypt via CF DNS</option>
                      <option value="panel_filesystem"' . ($editingCertificateSourceType === 'panel_filesystem' ? ' selected' : '') . '>Panel filesystem cert/key paths</option>
                    </select></div>
                    <div class="field" data-ssl-source-section="panel_letsencrypt"' . ($editingCertificateSourceType === 'panel_letsencrypt' ? '' : ' hidden') . '>
                      <label>Requested Domain</label>
                      <input type="text" name="ssl_domain" value="' . $this->escape((string) ($editingCertificateConfig['domain'] ?? '')) . '" placeholder="abc.example.com or example.com">
                      <p class="muted">Single domain: enter the exact hostname, for example <code>abc.example.com</code>. Wildcard + apex: enter the base you want covered. <code>example.com</code> becomes <code>*.example.com + example.com</code>; <code>abc.example.com</code> becomes <code>*.abc.example.com + abc.example.com</code>. Saving with issue enabled below will queue a backend task that asks the panel host to run <code>certbot --dns-cloudflare</code> through Cloudflare DNS.</p>
                    </div>
                    <div class="field" data-ssl-source-section="panel_letsencrypt"' . ($editingCertificateSourceType === 'panel_letsencrypt' ? '' : ' hidden') . '>
                      <label>ACME Email</label>
                      <input type="text" name="ssl_acme_email" value="' . $this->escape((string) ($editingCertificateConfig['email'] ?? '')) . '" placeholder="ops@example.com">
                    </div>
                    <div class="field" data-ssl-source-section="panel_letsencrypt"' . ($editingCertificateSourceType === 'panel_letsencrypt' ? '' : ' hidden') . '>
                      <label>Certificate Coverage</label>
                      <select name="ssl_domain_scope">
                        <option value="single"' . ($editingCertificateWildcard ? '' : ' selected') . '>Single domain</option>
                        <option value="wildcard"' . ($editingCertificateWildcard ? ' selected' : '') . '>Wildcard + apex via CF DNS</option>
                      </select>
                    </div>
                    <div class="field" data-ssl-source-section="panel_letsencrypt"' . ($editingCertificateSourceType === 'panel_letsencrypt' ? '' : ' hidden') . '>
                      <label>Cloudflare DNS Credential</label>
                      <select name="ssl_credential_profile_id">' . $letsencryptCredentialOptions . '</select>
                      <p class="muted">Uses the Cloudflare Global Key profile from the DNS credential library above.</p>
                    </div>
                    <div class="field" data-ssl-source-section="panel_letsencrypt"' . ($editingCertificateSourceType === 'panel_letsencrypt' ? '' : ' hidden') . '>
                      <label>DNS Propagation Seconds</label>
                      <input type="number" name="ssl_dns_propagation_seconds" value="' . $this->escape((string) $editingDnsPropagationSeconds) . '" min="10" step="5">
                      <p class="muted">How long certbot should wait for the Cloudflare TXT record to propagate before validation. Start with <code>60</code> if <code>10</code> is too short.</p>
                    </div>
                    <div class="field" style="grid-column:1/-1;" data-ssl-source-section="panel_letsencrypt"' . ($editingCertificateSourceType === 'panel_letsencrypt' ? '' : ' hidden') . '>
                      <label style="display:flex;align-items:center;gap:10px;">
                        <input type="hidden" name="ssl_issue_now" value="0">
                        <input type="checkbox" name="ssl_issue_now" value="1"' . ($editingCertificateIssueNow ? ' checked' : '') . '>
                        <span>Queue issue or renew task after save</span>
                      </label>
                      <div class="muted">Keep this checked when creating a new certificate. For existing items, you can uncheck it if you only want to update metadata or paths without triggering certbot right now. Save returns immediately; the backend worker handles the task and writes its status and output below. The panel host must already have <code>certbot</code> and the <code>dns-cloudflare</code> plugin installed.</div>
                    </div>
                    <div class="field" data-ssl-source-section="panel_letsencrypt"' . ($editingCertificateSourceType === 'panel_letsencrypt' ? '' : ' hidden') . '>
                      <label>Override Cert Path</label>
                      <input type="text" name="panel_tls_cert_source_path" value="' . $this->escape((string) ($editingCertificateConfig['cert_path'] ?? '')) . '" placeholder="/etc/letsencrypt/live/example.com/fullchain.pem">
                    </div>
                    <div class="field" data-ssl-source-section="panel_letsencrypt"' . ($editingCertificateSourceType === 'panel_letsencrypt' ? '' : ' hidden') . '>
                      <label>Override Key Path</label>
                      <input type="text" name="panel_tls_key_source_path" value="' . $this->escape((string) ($editingCertificateConfig['key_path'] ?? '')) . '" placeholder="/etc/letsencrypt/live/example.com/privkey.pem">
                    </div>
                    <div class="field" data-ssl-source-section="panel_filesystem"' . ($editingCertificateSourceType === 'panel_filesystem' ? '' : ' hidden') . '>
                      <label>Cert Path On Panel Host</label>
                      <input type="text" name="panel_filesystem_cert_path" value="' . $this->escape((string) ($editingCertificateConfig['cert_path'] ?? '')) . '" placeholder="/opt/ssl/fullchain.pem">
                    </div>
                    <div class="field" data-ssl-source-section="panel_filesystem"' . ($editingCertificateSourceType === 'panel_filesystem' ? '' : ' hidden') . '>
                      <label>Key Path On Panel Host</label>
                      <input type="text" name="panel_filesystem_key_path" value="' . $this->escape((string) ($editingCertificateConfig['key_path'] ?? '')) . '" placeholder="/opt/ssl/privkey.pem">
                    </div>
                    <div class="field" data-ssl-source-section="uploaded"' . ($editingCertificateSourceType === 'uploaded' ? '' : ' hidden') . '>
                      <label>Upload Certificate PEM</label>
                      <input type="file" name="ssl_cert_file" accept=".crt,.cer,.pem,.txt">
                    </div>
                    <div class="field" data-ssl-source-section="uploaded"' . ($editingCertificateSourceType === 'uploaded' ? '' : ' hidden') . '>
                      <label>Upload Private Key PEM</label>
                      <input type="file" name="ssl_key_file" accept=".key,.pem,.txt">
                    </div>
                    <div class="field" style="grid-column:1/-1;" data-ssl-source-section="uploaded"' . ($editingCertificateSourceType === 'uploaded' ? '' : ' hidden') . '>
                      ' . ($editingCertificateHasStoredPair
                        ? '<div class="flash success" style="margin:0;">A certificate/private key pair is already stored for this library item. Upload or paste a new pair only when you want to replace it.</div>'
                        : '<div class="muted">Upload both files above, or paste both PEM blocks below. The panel will parse the certificate metadata and push the pair to assigned slaves.</div>') . '
                    </div>
                    <div class="field" data-ssl-source-section="uploaded"' . ($editingCertificateSourceType === 'uploaded' ? '' : ' hidden') . '>
                      <label>Or Paste Certificate PEM</label>
                      <textarea name="ssl_cert_pem" placeholder="-----BEGIN CERTIFICATE-----"></textarea>
                    </div>
                    <div class="field" data-ssl-source-section="uploaded"' . ($editingCertificateSourceType === 'uploaded' ? '' : ' hidden') . '>
                      <label>Or Paste Private Key PEM</label>
                      <textarea name="ssl_key_pem" placeholder="-----BEGIN PRIVATE KEY-----"></textarea>
                    </div>
                  </div>
                  <div class="field" style="margin-top:14px;">
                    <label>Current Metadata</label>
                    <div class="muted">Domains: ' . $this->escape($editingCertificateDomains !== '' ? $editingCertificateDomains : 'Will be extracted when you save the certificate.') . '<br>Issuer: ' . $this->escape((string) ($editingCertificate['issuer_name'] ?? 'Will be extracted when you save.')) . '<br>Expires: ' . $this->escape((string) ($editingCertificate['expires_at'] ?? 'Will be extracted when you save.')) . '</div>
                  </div>
                  <div class="actions"><button class="button" type="submit">Save SSL Certificate</button>' . ($editingCertificate !== null ? '<a class="button secondary" href="/admin/credentials">Cancel</a>' : '') . '</div>
                </form>
                <script>
                  (function () {
                    const form = document.querySelector("[data-ssl-certificate-form]");
                    if (!form) {
                      return;
                    }
                    const select = form.querySelector("[data-ssl-source-type]");
                    if (!select) {
                      return;
                    }
                    const sync = () => {
                      const mode = select.value;
                      form.querySelectorAll("[data-ssl-source-section]").forEach((section) => {
                        section.hidden = section.getAttribute("data-ssl-source-section") !== mode;
                      });
                    };
                    select.addEventListener("change", sync);
                    sync();
                  })();
                </script>
              </section>
              <section class="card">
                <h3>SSL Certificate Library</h3>
                <div class="table-wrap">
                  <table>
                    <thead><tr><th>ID</th><th>Name</th><th>Source</th><th>Domains</th><th>Issuer</th><th>Expires</th><th>Renewal</th><th>Latest Task</th><th>Nodes</th><th>Actions</th></tr></thead>
                    <tbody>' . $certificateRows . '</tbody>
                  </table>
                </div>
              </section>
            </div>
            <section class="card" style="margin-top:20px;">
              <div class="hero" style="margin-bottom:14px;">
                <div>
                  <h3 style="margin-bottom:6px;">Recent SSL Tasks</h3>
                  <p class="muted" style="margin:0;">Refresh this page to see the latest pending, running, completed, or failed backend certificate jobs.</p>
                </div>
              </div>
              <div class="table-wrap">
                <table>
                  <thead><tr><th>ID</th><th>Certificate</th><th>Type</th><th>Status</th><th>Requested By</th><th>Started</th><th>Finished</th><th>Log</th></tr></thead>
                  <tbody>' . $taskRows . '</tbody>
                </table>
              </div>
            </section>';

        return $this->adminPage('Credentials', $body, $viewer, 'credentials');
    }

    public function saveCredentialProfile(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $id = (int) $request->input('id', 0);
            $name = trim((string) $request->input('name', ''));
            $provider = trim((string) $request->input('provider', 'cloudflare_global_key'));
            $cloudflareEmail = trim((string) $request->input('cloudflare_email', ''));
            $cloudflareGlobalKey = trim((string) $request->input('cloudflare_global_key', ''));

            if ($name === '' || $cloudflareEmail === '' || $cloudflareGlobalKey === '') {
                throw new ApiException('invalid_credential_profile', 422, ['message' => 'name, cloudflare email and global key are required']);
            }
            if ($provider !== 'cloudflare_global_key') {
                throw new ApiException('invalid_credential_provider', 422, ['provider' => $provider]);
            }

            $configJson = json_encode([
                'cloudflare_email' => $cloudflareEmail,
                'cloudflare_global_key' => $cloudflareGlobalKey,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($id > 0) {
                $this->db->execute(
                    'UPDATE credential_profiles
                     SET name = :name,
                         provider = :provider,
                         config_json = :config_json,
                         updated_at = :updated_at
                     WHERE id = :id',
                    [
                        'id' => $id,
                        'name' => $name,
                        'provider' => $provider,
                        'config_json' => $configJson,
                        'updated_at' => gmdate('Y-m-d H:i:s'),
                    ]
                );
            } else {
                $this->db->insert('credential_profiles', [
                    'name' => $name,
                    'provider' => $provider,
                    'config_json' => $configJson,
                    'created_at' => gmdate('Y-m-d H:i:s'),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            }

            SessionAuth::flash('success', 'Credential profile saved.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to save credential profile: ' . $throwable->getMessage());
        }

        return Response::redirect('/admin/credentials');
    }

    public function saveSslCertificate(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $id = (int) $request->input('id', 0);
            $name = trim((string) $request->input('name', ''));
            $sourceType = trim((string) $request->input('source_type', 'uploaded'));
            if ($name === '') {
                throw new ApiException('invalid_ssl_certificate', 422, ['message' => 'certificate name is required']);
            }
            if (!in_array($sourceType, ['uploaded', 'panel_letsencrypt', 'panel_filesystem'], true)) {
                throw new ApiException('invalid_ssl_certificate', 422, ['message' => 'invalid source type']);
            }

            $existing = $id > 0 ? $this->db->fetchOne('SELECT * FROM ssl_certificates WHERE id = :id LIMIT 1', ['id' => $id]) : null;
            if ($id > 0 && $existing === null) {
                throw new ApiException('ssl_certificate_not_found', 404, ['id' => $id]);
            }

            $sourcePayload = $this->buildSslCertificatePayload($request, $sourceType, $existing);
            $domainsJson = json_encode($sourcePayload['domains'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $sourceConfigJson = json_encode($sourcePayload['source_config'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $certificateId = $id;

            if ($id > 0) {
                $this->db->execute(
                    'UPDATE ssl_certificates
                     SET name = :name,
                         source_type = :source_type,
                         domains_json = :domains_json,
                         issuer_name = :issuer_name,
                         expires_at = :expires_at,
                         renewal_mode = :renewal_mode,
                         source_config_json = :source_config_json,
                         cert_pem = :cert_pem,
                         key_pem = :key_pem,
                         updated_at = :updated_at
                     WHERE id = :id',
                    [
                        'id' => $id,
                        'name' => $name,
                        'source_type' => $sourceType,
                        'domains_json' => $domainsJson,
                        'issuer_name' => $sourcePayload['issuer_name'],
                        'expires_at' => $sourcePayload['expires_at'],
                        'renewal_mode' => $sourcePayload['renewal_mode'],
                        'source_config_json' => $sourceConfigJson,
                        'cert_pem' => $sourcePayload['cert_pem'],
                        'key_pem' => $sourcePayload['key_pem'],
                        'updated_at' => gmdate('Y-m-d H:i:s'),
                    ]
                );
            } else {
                $certificateId = $this->db->insert('ssl_certificates', [
                    'name' => $name,
                    'source_type' => $sourceType,
                    'domains_json' => $domainsJson,
                    'issuer_name' => $sourcePayload['issuer_name'],
                    'expires_at' => $sourcePayload['expires_at'],
                    'renewal_mode' => $sourcePayload['renewal_mode'],
                    'source_config_json' => $sourceConfigJson,
                    'cert_pem' => $sourcePayload['cert_pem'],
                    'key_pem' => $sourcePayload['key_pem'],
                    'created_at' => gmdate('Y-m-d H:i:s'),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            }

            $successMessage = 'SSL certificate library item saved.';
            if ($sourceType === 'panel_letsencrypt' && !empty($sourcePayload['queue_task'])) {
                $taskInfo = $this->sslCertificates()->queueTask(
                    $certificateId,
                    (string) ($sourcePayload['task_type'] ?? 'issue'),
                    (int) ($viewer['id'] ?? 0) > 0 ? (int) $viewer['id'] : null
                );
                if (!empty($taskInfo['created'])) {
                    $successMessage .= ' Backend SSL task #' . (int) $taskInfo['task_id'] . ' was queued.';
                } else {
                    $successMessage .= ' Existing backend SSL task #' . (int) $taskInfo['task_id'] . ' is still ' . $this->sslCertificateTaskPlainStatusLabel((string) ($taskInfo['status'] ?? 'pending')) . '.';
                }
            } elseif ($sourceType !== 'panel_letsencrypt') {
                $cancelledTasks = $this->sslCertificates()->cancelPendingTasks(
                    $certificateId,
                    'Task cancelled because this certificate no longer uses panel Let\'s Encrypt.'
                );
                if ($cancelledTasks > 0) {
                    $successMessage .= ' Cancelled ' . $cancelledTasks . ' pending SSL task(s) for this certificate.';
                }
            }

            SessionAuth::flash('success', $successMessage);
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to save SSL certificate: ' . $this->displayThrowableMessage($throwable));
        }

        return Response::redirect('/admin/credentials');
    }

    public function clientsPage(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        $view = PrecisionGridUi::normalizeView(
            (string) $request->input('view', 'list'),
            ['list', 'create', 'detail', 'updates'],
            'list'
        );

        $clients = $this->provisioning?->listClients() ?? [];
        $clientGroups = $this->provisioning?->listClientGroups() ?? [];
        $nodeGroups = $this->provisioning?->listNodeGroups() ?? [];
        $chains = $this->provisioning?->listForwardChains() ?? [];
        $tenants = $this->db->fetchAll('SELECT id, name, email FROM users WHERE role = :role ORDER BY name ASC, email ASC', ['role' => 'tenant']);
        $editId = (int) $request->input('edit', 0);
        if ($editId > 0) {
            $view = 'detail';
        }
        $editing = null;
        foreach ($clients as $client) {
            if ((int) $client['id'] === $editId) {
                $editing = $client;
                break;
            }
        }
        $overviewClient = $editing ?? ($clients[0] ?? null);
        $groupOptions = '<option value="">No group</option>';
        foreach ($clientGroups as $group) {
            $selected = $editing !== null && (int) ($editing['group_id'] ?? 0) === (int) $group['id'] ? ' selected' : '';
            $groupOptions .= '<option value="' . (int) $group['id'] . '"' . $selected . '>' . $this->escape((string) $group['name']) . '</option>';
        }
        $nodeGroupOptions = '<option value="">No default slave group</option>';
        foreach ($nodeGroups as $group) {
            $selected = $editing !== null && (string) ($editing['default_node_group_key'] ?? '') === (string) $group['group_key'] ? ' selected' : '';
            $nodeGroupOptions .= '<option value="' . $this->escape((string) $group['group_key']) . '"' . $selected . '>' . $this->escape((string) $group['name']) . '</option>';
        }
        $chainOptions = '<option value="">No default chain</option>';
        foreach ($chains as $chain) {
            $selected = $editing !== null && (int) ($editing['default_forward_chain_id'] ?? 0) === (int) $chain['id'] ? ' selected' : '';
            $chainOptions .= '<option value="' . (int) $chain['id'] . '"' . $selected . '>' . $this->escape((string) $chain['name']) . '</option>';
        }
        $ownerOptions = '<option value="">Shared / admin-provided</option>';
        foreach ($tenants as $tenant) {
            $selected = $editing !== null && (int) ($editing['owner_user_id'] ?? 0) === (int) $tenant['id'] ? ' selected' : '';
            $ownerOptions .= '<option value="' . (int) $tenant['id'] . '"' . $selected . '>' . $this->escape((string) $tenant['name']) . ' (' . $this->escape((string) $tenant['email']) . ')</option>';
        }

        $csrf = $this->escape(SessionAuth::csrfToken());
        $appUrl = $this->escape($this->agentBaseUrl());
        $clientUsageMap = $this->provisioning->clientUsageOverviewMap(array_map(
            static fn(array $client): int => (int) ($client['id'] ?? 0),
            $clients
        ));
        $clientForwardInstallMap = [];
        foreach ($clients as $client) {
            $clientId = (int) ($client['id'] ?? 0);
            $entries = [];
            foreach ($this->provisioning->assignedForwardConfigsForClient($clientId) as $config) {
                if (!is_array($config)) {
                    continue;
                }
                $forwardId = (int) ($config['forward_id'] ?? 0);
                $token = trim((string) ($config['client_token'] ?? ''));
                if ($forwardId <= 0 || $token === '') {
                    continue;
                }
                $entries[$forwardId] = [
                    'forward_id' => $forwardId,
                    'client_token' => $token,
                    'service_name' => (string) ($config['service_name'] ?? ''),
                    'listen_port' => (int) ($config['listen_port'] ?? 0),
                    'target' => (string) ($config['target'] ?? ''),
                ];
            }
            $clientForwardInstallMap[$clientId] = array_values($entries);
        }
        $rows = '';
        $mobileRows = '';
        foreach ($clients as $client) {
            $clientId = (int) $client['id'];
            $clientName = (string) $client['name'];
            $displayHost = (string) $client['display_host'];
            $owner = (string) ($client['owner_email'] ?? 'Shared');
            $groupName = (string) ($client['group_name'] ?? 'No group');
            $status = (string) $client['status'];
            $agentVersion = (string) ($client['agent_version'] ?? 'Not reported');
            $lastSync = (string) ($client['last_sync_at'] ?? 'Never');
            $assignedForwards = $clientForwardInstallMap[(int) ($client['id'] ?? 0)] ?? [];
            $assignedForwardSummary = $this->renderAssignedForwardSummary($assignedForwards);
            $installDetails = $this->renderClientAgentInstallDetails(
                $client,
                $assignedForwards
            );
            $diagItems = $this->renderClientDiagnosticsHtml($client);
            $usagePanel = $this->renderUsageOverviewPanel($clientUsageMap[(int) ($client['id'] ?? 0)] ?? [], 'Client Traffic');
            $commands = '<a href="/admin/clients?view=detail&amp;edit=' . $clientId . '">Edit &amp; inspect</a>
                <form class="inline" method="post" action="/admin/republish/client/' . (int) $client['id'] . '"><input type="hidden" name="csrf_token" value="' . $csrf . '"><button type="submit">Republish</button></form>
                <form class="inline" method="post" action="/admin/clients/' . (int) $client['id'] . '/diagnose"><input type="hidden" name="csrf_token" value="' . $csrf . '"><button type="submit">Run Diagnostics</button></form>
                <form class="inline" method="post" action="/admin/clients/' . $clientId . '/request-update"><input type="hidden" name="csrf_token" value="' . $csrf . '"><button type="submit">Request Update</button></form>
                ' . $assignedForwardSummary . $installDetails . '
                <details><summary>Diagnostics</summary><div class="muted">Status: ' . $this->escape((string) ($client['last_diag_status'] ?? '')) . '</div><div class="muted">Last Run: ' . $this->escape((string) ($client['last_diag_run_at'] ?? '')) . '</div><div class="muted">Error: ' . $this->escape((string) ($client['last_diag_error'] ?? '')) . '</div><div>' . $diagItems . '</div></details>
                <details><summary>Usage Calendar</summary>' . $usagePanel . '</details>
                <form class="inline" method="post" action="/admin/clients/' . $clientId . '/delete" onsubmit="return confirm(\'Delete this client from the database? Active forwards must be removed first.\')"><input type="hidden" name="csrf_token" value="' . $csrf . '"><button type="submit" class="danger">Delete</button></form>';
            $rows .= '<tr>
                <td>' . PrecisionGridUi::resourceCell($clientName, $displayHost) . '</td>
                <td>' . PrecisionGridUi::resourceCell($owner, $groupName) . '</td>
                <td>' . PrecisionGridUi::status(ucfirst($status), $status === 'active' ? 'ok' : 'error') . '</td>
                <td>' . PrecisionGridUi::resourceCell($agentVersion, $lastSync) . '</td>
                <td>' . PrecisionGridUi::rowMenu($clientName . ' actions', $commands) . '</td>
            </tr>';
            $mobileRows .= '<article class="pg-mobile-resource-row"><header>' . PrecisionGridUi::resourceCell($clientName, $displayHost)
                . PrecisionGridUi::status(ucfirst($status), $status === 'active' ? 'ok' : 'error')
                . '</header><dl><div><dt>Owner</dt><dd>' . $this->escape($owner) . '</dd></div><div><dt>Group</dt><dd>' . $this->escape($groupName) . '</dd></div><div><dt>Agent</dt><dd>' . $this->escape($agentVersion) . '</dd></div><div><dt>Last Sync</dt><dd>' . $this->escape($lastSync) . '</dd></div></dl><footer>'
                . PrecisionGridUi::rowMenu($clientName . ' actions', $commands) . '</footer></article>';
        }

        $tabs = PrecisionGridUi::viewTabs([
            ['key' => 'list', 'label' => 'Clients', 'href' => '/admin/clients?view=list'],
            ['key' => 'create', 'label' => 'Create Client', 'href' => '/admin/clients?view=create'],
            ['key' => 'updates', 'label' => 'Updates', 'href' => '/admin/clients?view=updates'],
        ], $view);
        $formView = $this->renderClientAppDownloadOverview($overviewClient) . '
              <section class="card">
                <h3>' . ($editing !== null ? 'Edit Client' : 'Create Client') . '</h3>
                <div class="flash success">
                  <strong>Recommended first setup:</strong> keep default route mode on <code>slave_group</code>, choose one default slave group, and leave forward chain empty.
                </div>
                <p class="muted">These fields define the default routing policy for the client inside the panel. They do not change the app login flow. Installing a client app still only needs <code>Client ID + Token</code>. If you leave owner empty, this client is shared and admin-managed.</p>
                <form method="post" action="/admin/clients">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <input type="hidden" name="id" value="' . (int) ($editing['id'] ?? 0) . '">
                  <div class="form-grid">
                    <div class="field"><label>Name</label><input type="text" name="name" value="' . $this->escape((string) ($editing['name'] ?? '')) . '" required></div>
                    <div class="field"><label>Display Host</label><input type="text" name="display_host" value="' . $this->escape((string) ($editing['display_host'] ?? '')) . '" placeholder="bgp.example.com" required><p class="muted">Tenant-facing display label only. It does not drive slave SNI / CDN routing.</p></div>
                    <div class="field"><label>Owner Tenant</label><select name="owner_user_id">' . $ownerOptions . '</select></div>
                    <div class="field"><label>Client Group</label><select name="group_id">' . $groupOptions . '</select></div>
                    <div class="field"><label>Status</label><select name="status"><option value="active"' . (((string) ($editing['status'] ?? 'active')) === 'active' ? ' selected' : '') . '>active</option><option value="disabled"' . (((string) ($editing['status'] ?? '')) === 'disabled' ? ' selected' : '') . '>disabled</option></select></div>
                  </div>
                  <details style="margin-top:14px;">
                    <summary>Advanced client settings</summary>
                    <div class="form-grid" style="margin-top:14px;">
                      <div class="field"><label>Bind IP</label><input type="text" name="bind_ip" value="' . $this->escape((string) ($editing['bind_ip'] ?? '')) . '" placeholder="optional"></div>
                      <div class="field"><label>NIC Name</label><input type="text" name="nic_name" value="' . $this->escape((string) ($editing['nic_name'] ?? '')) . '" placeholder="eth0"></div>
                      <div class="field"><label>Port Range Start</label><input type="number" name="port_range_start" value="' . $this->escape((string) ($editing['port_range_start'] ?? '')) . '"><p class="muted">Optional. When set together with port range end, the panel auto-assigns forward ports from this range.</p></div>
                      <div class="field"><label>Port Range End</label><input type="number" name="port_range_end" value="' . $this->escape((string) ($editing['port_range_end'] ?? '')) . '"><p class="muted">Optional. Keep the range wide enough for future forwards.</p></div>
                      <div class="field"><label>Traffic Multiplier</label><input type="number" step="0.01" name="traffic_multiplier" value="' . $this->escape((string) ($editing['traffic_multiplier'] ?? '1.00')) . '"></div>
                      <div class="field"><label>Speed Limit Mbps</label><input type="number" name="speed_limit_mbps" value="' . $this->escape((string) ($editing['speed_limit_mbps'] ?? '')) . '"></div>
                      <div class="field"><label>Default Route Mode</label>
                        <select name="default_endpoint_mode">' . $this->renderEndpointModeOptions((string) ($editing['default_endpoint_mode'] ?? 'slave_group')) . '</select>
                      </div>
                      <div class="field"><label>Default Slave Group</label><select name="default_node_group_key">' . $nodeGroupOptions . '</select><p class="muted">Optional. Used as the default route suggestion when a forward is created later.</p></div>
                      <div class="field"><label>Default Forward Chain</label><select name="default_forward_chain_id">' . $chainOptions . '</select><p class="muted">Optional advanced default. Leave empty unless you already use advanced multi-hop routing.</p></div>
                      <div class="field"><label>Max IP Count</label><input type="number" name="max_ip_count" value="' . $this->escape((string) ($editing['max_ip_count'] ?? '')) . '"></div>
                      <div class="field"><label>Max Conn Per IP</label><input type="number" name="max_conn_per_ip" value="' . $this->escape((string) ($editing['max_conn_per_ip'] ?? '')) . '"></div>
                    </div>
                    <div class="grid two" style="margin-top:14px;">
                      <label><input type="checkbox" name="allow_custom_endpoints" value="1"' . (!empty($editing['allow_custom_endpoints']) ? ' checked' : '') . '> Allow custom slaves</label>
                      <label><input type="checkbox" name="force_slave_mode" value="1"' . (!empty($editing['force_slave_mode']) ? ' checked' : '') . '> Force slave mode</label>
                      <label><input type="checkbox" name="allow_forward_chain" value="1"' . ((isset($editing['allow_forward_chain']) ? !empty($editing['allow_forward_chain']) : true) ? ' checked' : '') . '> Allow forward chains</label>
                      <label><input type="checkbox" name="allow_latency_test" value="1"' . ((isset($editing['allow_latency_test']) ? !empty($editing['allow_latency_test']) : true) ? ' checked' : '') . '> Allow latency test</label>
                      <label><input type="checkbox" name="allow_inbound_proxy" value="1"' . (!empty($editing['allow_inbound_proxy']) ? ' checked' : '') . '> Allow inbound proxy</label>
                    </div>
                    <div class="field" style="margin-top:14px;"><label>Protocol Filter JSON</label><textarea name="protocol_filter_json" placeholder="{}">' . $this->escape((string) ($editing['protocol_filter_json'] ?? '')) . '</textarea></div>
                    <div class="field" style="margin-top:14px;"><label>Custom Config JSON</label><textarea name="custom_config_json" placeholder="{}">' . $this->escape((string) ($editing['custom_config_json'] ?? '')) . '</textarea></div>
                    <div class="field" style="margin-top:14px;"><label>Agent Config JSON</label><textarea name="agent_config_json" placeholder="{&quot;poll_interval_seconds&quot;:60}">' . $this->escape((string) ($editing['agent_config_json'] ?? '')) . '</textarea><p class="muted">Merged into the installed client-agent config on the next sync.</p></div>
                    <div class="field" style="margin-top:14px;"><label>Tags JSON</label><textarea name="tags_json" placeholder="[\"vip\"]">' . $this->escape((string) ($editing['tags_json'] ?? '')) . '</textarea></div>
                  </details>
                  <div class="actions"><button class="button" type="submit">Save Client</button>' . ($editing !== null ? '<a class="button secondary" href="/admin/clients">Cancel</a>' : '') . '</div>
                </form>
              </section>';
        $listView = '
              <section class="pg-sheet" data-mobile-resources="true">
                <div class="pg-sheet-toolbar"><strong>Client Directory</strong><span class="muted">' . count($clients) . ' clients</span></div>
                <div class="pg-sheet-scroll">
                  <table class="pg-sheet-table">
                    <thead><tr><th>Client / Host</th><th>Owner / Group</th><th>Status</th><th>Agent / Last Sync</th><th><span class="pg-visually-hidden">Actions</span></th></tr></thead>
                    <tbody>' . $rows . '</tbody>
                  </table>
                </div>
                <div class="pg-mobile-resource-list">' . $mobileRows . '</div>
              </section>';
        $viewBody = match ($view) {
            'create', 'detail' => $formView,
            'updates' => '<div class="flash success">Use each client menu to request updates, republish, or run diagnostics.</div>' . $listView,
            default => $listView,
        };
        $body = $tabs . $viewBody;

        return $this->adminPage('Clients', $body, $viewer, 'clients');
    }


    public function cfLocationGroupsPage(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        $groups = $this->provisioning?->listCfLocationGroups() ?? [];
        $editId = (int) $request->input('edit', 0);
        $editing = null;
        foreach ($groups as $group) {
            if ((int) ($group['id'] ?? 0) === $editId) {
                $editing = $group;
                break;
            }
        }
        $cidrText = '';
        if (is_array($editing['cidrs'] ?? null)) {
            $cidrText = implode(PHP_EOL, array_map(static fn(array $row): string => (string) ($row['cidr'] ?? ''), $editing['cidrs']));
        }

        $rows = '';
        foreach ($groups as $group) {
            $cidrs = is_array($group['cidrs'] ?? null)
                ? implode(', ', array_map(static fn(array $row): string => (string) ($row['cidr'] ?? ''), $group['cidrs']))
                : '';
            $rows .= '<tr>
                <td>' . (int) ($group['id'] ?? 0) . '</td>
                <td>' . $this->escape((string) ($group['name'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($group['region_code'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($group['probe_mode'] ?? 'auto')) . '</td>
                <td>' . (int) ($group['cache_ttl_seconds'] ?? 120) . '</td>
                <td>' . (int) ($group['switch_improvement_ms'] ?? 15) . '</td>
                <td>' . (int) ($group['max_candidates'] ?? 32) . '</td>
                <td>' . $this->escape($cidrs) . '</td>
                <td>' . (((int) ($group['is_active'] ?? 1)) === 1 ? '<span class="badge success">active</span>' : '<span class="badge danger">inactive</span>') . '</td>
                <td><a class="button ghost" href="/admin/cf-location-groups?edit=' . (int) ($group['id'] ?? 0) . '">Edit</a></td>
            </tr>';
        }

        $csrf = $this->escape(SessionAuth::csrfToken());
        $body = '
            <div class="hero">
              <div>
                <h2>Cloudflare Location Groups</h2>
                <p class="muted">Reusable CIDR sets for slave-side best-IP selection in `cf_mode` forward combinations.</p>
              </div>
            </div>
            <div class="grid two">
              <section class="card">
                <h3>' . ($editing !== null ? 'Edit Location Group' : 'Create Location Group') . '</h3>
                <form method="post" action="/admin/cf-location-groups">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <input type="hidden" name="id" value="' . (int) ($editing['id'] ?? 0) . '">
                  <div class="form-grid">
                    <div class="field"><label>Name</label><input type="text" name="name" value="' . $this->escape((string) ($editing['name'] ?? '')) . '" required></div>
                    <div class="field"><label>Region Code</label><input type="text" name="region_code" value="' . $this->escape((string) ($editing['region_code'] ?? '')) . '" placeholder="JP" required></div>
                    <div class="field"><label>Probe Mode</label><select name="probe_mode"><option value="auto"' . (((string) ($editing['probe_mode'] ?? 'auto')) === 'auto' ? ' selected' : '') . '>auto</option><option value="tcp"' . (((string) ($editing['probe_mode'] ?? 'auto')) === 'tcp' ? ' selected' : '') . '>tcp</option><option value="icmp"' . (((string) ($editing['probe_mode'] ?? 'auto')) === 'icmp' ? ' selected' : '') . '>icmp</option></select></div>
                    <div class="field"><label>Cache TTL Seconds</label><input type="number" name="cache_ttl_seconds" value="' . $this->escape((string) ($editing['cache_ttl_seconds'] ?? 120)) . '"></div>
                    <div class="field"><label>Switch Improvement Ms</label><input type="number" name="switch_improvement_ms" value="' . $this->escape((string) ($editing['switch_improvement_ms'] ?? 15)) . '"></div>
                    <div class="field"><label>Max Candidates</label><input type="number" name="max_candidates" value="' . $this->escape((string) ($editing['max_candidates'] ?? 32)) . '"></div>
                    <div class="field"><label>Status</label><select name="is_active"><option value="1"' . (((int) ($editing['is_active'] ?? 1)) === 1 ? ' selected' : '') . '>active</option><option value="0"' . (((int) ($editing['is_active'] ?? 1)) !== 1 ? ' selected' : '') . '>inactive</option></select></div>
                  </div>
                  <div class="field" style="margin-top:14px;"><label>CIDRs</label><textarea name="cidr_lines" placeholder="104.16.0.0/13&#10;172.64.0.0/13" required>' . $this->escape($cidrText) . '</textarea></div>
                  <div class="actions"><button class="button" type="submit">Save Group</button>' . ($editing !== null ? '<a class="button secondary" href="/admin/cf-location-groups">Cancel</a>' : '') . '</div>
                </form>
              </section>
              <section class="card">
                <h3>Location Group List</h3>
                <div class="table-wrap">
                  <table>
                    <thead><tr><th>ID</th><th>Name</th><th>Region</th><th>Probe</th><th>TTL</th><th>Improve</th><th>Candidates</th><th>CIDRs</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>' . $rows . '</tbody>
                  </table>
                </div>
              </section>
            </div>';

        return $this->adminPage('Cloudflare Location Groups', $body, $viewer, 'cf-location-groups');
    }

    public function saveCfLocationGroup(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }
        try {
            SessionAuth::validateCsrf($request);
            $this->provisioning?->saveCfLocationGroup($request->body());
            SessionAuth::flash('success', 'Cloudflare location group saved.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to save Cloudflare location group: ' . $this->displayThrowableMessage($throwable));
        }
        return Response::redirect('/admin/cf-location-groups');
    }

    public function leigodLinesPage(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        $lines = $this->provisioning?->listLeigodLines() ?? [];
        $editId = (int) $request->input('edit', 0);
        $editing = null;
        foreach ($lines as $line) {
            if ((int) ($line['id'] ?? 0) === $editId) {
                $editing = $line;
                break;
            }
        }

        $csrf = $this->escape(SessionAuth::csrfToken());
        $rows = '';
        foreach ($lines as $line) {
            $id = (int) ($line['id'] ?? 0);
            $configurationState = (string) ($line['configuration_state'] ?? 'missing');
            $stateClass = $configurationState === 'ready' ? 'success' : 'danger';
            $lastError = trim((string) ($line['last_error'] ?? ''));
            $rows .= '<tr>'
                . '<td>' . (int) ($line['line_id'] ?? 0) . '</td>'
                . '<td>' . $this->escape((string) ($line['label'] ?? '')) . '</td>'
                . '<td><span class="badge ' . $stateClass . '">' . $this->escape($configurationState) . '</span></td>'
                . '<td>' . (int) ($line['tcp_healthy_nodes'] ?? 0) . '</td>'
                . '<td>' . (int) ($line['udp_healthy_nodes'] ?? 0) . '</td>'
                . '<td>' . $this->escape((string) ($line['last_checked_at'] ?? '')) . '</td>'
                . '<td>' . ($lastError !== '' ? $this->escape($lastError) : '<span class="muted">-</span>') . '</td>'
                . '<td>' . ((int) ($line['is_active'] ?? 0) === 1 ? '<span class="badge success">active</span>' : '<span class="badge danger">inactive</span>') . '</td>'
                . '<td><a class="button ghost" href="/admin/leigod-lines?edit=' . $id . '">Edit</a> '
                . '<form class="inline" method="post" action="/admin/leigod-lines/' . $id . '/delete" onsubmit="return confirm(\'Delete this line?\')">'
                . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                . '<button class="button danger" type="submit">Delete</button></form></td>'
                . '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="9" class="muted">No lines configured.</td></tr>';
        }

        $udpMethod = strtolower((string) ($editing['udp_probe_method'] ?? ''));
        $body = '<div class="grid two">'
            . '<section class="card"><h3>' . ($editing !== null ? 'Edit Managed TYO Line' : 'New Managed TYO Line') . '</h3>'
            . '<form method="post" action="/admin/leigod-lines">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
            . '<input type="hidden" name="id" value="' . (int) ($editing['id'] ?? 0) . '">'
            . '<div class="form-grid">'
            . '<div class="field"><label>Line ID</label><input type="number" name="line_id" min="1" max="45535" value="' . $this->escape((string) ($editing['line_id'] ?? '')) . '" required></div>'
            . '<div class="field"><label>Label</label><input type="text" name="label" maxlength="191" value="' . $this->escape((string) ($editing['label'] ?? '')) . '" required></div>'
            . '<div class="field"><label>TCP Probe Host</label><input type="text" name="tcp_probe_host" value="' . $this->escape((string) ($editing['tcp_probe_host'] ?? '')) . '" required></div>'
            . '<div class="field"><label>TCP Probe Port</label><input type="number" name="tcp_probe_port" min="1" max="65535" value="' . $this->escape((string) ($editing['tcp_probe_port'] ?? '')) . '" required></div>'
            . '<div class="field"><label>UDP Probe Host</label><input type="text" name="udp_probe_host" value="' . $this->escape((string) ($editing['udp_probe_host'] ?? '')) . '"></div>'
            . '<div class="field"><label>UDP Probe Port</label><input type="number" name="udp_probe_port" min="1" max="65535" value="' . $this->escape((string) ($editing['udp_probe_port'] ?? '')) . '"></div>'
            . '<div class="field"><label>UDP Probe Method</label><select name="udp_probe_method">'
            . '<option value=""' . ($udpMethod === '' ? ' selected' : '') . '>disabled</option>'
            . '<option value="dns"' . ($udpMethod === 'dns' ? ' selected' : '') . '>dns</option>'
            . '<option value="echo"' . ($udpMethod === 'echo' ? ' selected' : '') . '>echo</option></select></div>'
            . '<div class="field"><label>Config SHA-256</label><input type="text" name="config_sha256" maxlength="64" pattern="[A-Fa-f0-9]{64}" value="' . $this->escape((string) ($editing['config_sha256'] ?? '')) . '" required></div>'
            . '<div class="field"><label>Status</label><select name="is_active"><option value="1"' . ((int) ($editing['is_active'] ?? 0) === 1 ? ' selected' : '') . '>active</option><option value="0"' . ((int) ($editing['is_active'] ?? 0) !== 1 ? ' selected' : '') . '>inactive</option></select></div>'
            . '<div class="field"><label>Sort Order</label><input type="number" name="sort_order" value="' . $this->escape((string) ($editing['sort_order'] ?? 100)) . '"></div>'
            . '</div>'
            . '<div class="field"><label>Notes</label><textarea name="notes">' . $this->escape((string) ($editing['notes'] ?? '')) . '</textarea></div>'
            . '<div class="actions"><button class="button" type="submit">Save Line</button>'
            . ($editing !== null ? '<a class="button secondary" href="/admin/leigod-lines">Cancel</a>' : '')
            . '</div></form></section>'
            . '<section class="card"><h3>Managed TYO Line Catalog</h3><div class="table-wrap"><table>'
            . '<thead><tr><th>Line</th><th>Label</th><th>Config</th><th>TCP Nodes</th><th>UDP Nodes</th><th>Checked</th><th>Last Error</th><th>Status</th><th>Actions</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div></section></div>';

        return $this->adminPage('Managed TYO Lines', $body, $viewer, 'leigod-lines');
    }

    public function saveLeigodLine(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }
        try {
            SessionAuth::validateCsrf($request);
            $this->provisioning?->saveLeigodLine($request->body());
            SessionAuth::flash('success', 'Managed TYO line saved.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to save Managed TYO line: ' . $this->displayThrowableMessage($throwable));
        }
        return Response::redirect('/admin/leigod-lines');
    }

    public function deleteLeigodLine(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }
        try {
            SessionAuth::validateCsrf($request);
            $this->provisioning?->deleteLeigodLine($this->idParam($params));
            SessionAuth::flash('success', 'Managed TYO line deleted.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to delete Managed TYO line: ' . $this->displayThrowableMessage($throwable));
        }
        return Response::redirect('/admin/leigod-lines');
    }

    public function forwardCombinationsPage(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        $combinations = $this->provisioning?->listForwardCombinations() ?? [];
        $groups = $this->provisioning?->listNodeGroups() ?? [];
        $sidecars = $this->provisioning?->listSidecarPoints() ?? [];
        $cfGroups = $this->provisioning?->listCfLocationGroups() ?? [];
        $leigodLines = $this->provisioning?->listLeigodLines() ?? [];
        $ultraInventory = $this->ultraInventory ?? new UltraInventoryService($this->config, $this->db);
        $ultraIngressZones = $ultraInventory->activeZones('ingress');
        $ultraDestinationZones = $ultraInventory->activeZones('destination');
        $ultraLines = $ultraInventory->listLinesForAdmin([], 200, 0);
        $editId = (int) $request->input('edit', 0);
        $editing = null;
        foreach ($combinations as $combination) {
            if ((int) ($combination['id'] ?? 0) === $editId) {
                $editing = $combination;
                break;
            }
        }
        $selectedBackendMode = strtolower(trim((string) ($editing['backend_mode'] ?? $request->input('backend_mode', 'current_mode'))));
        if (!in_array($selectedBackendMode, ['current_mode', 'cf_mode', 'leigod_mode', UltraMode::BACKEND_MODE], true)) {
            $selectedBackendMode = 'current_mode';
        }

        $candidateNodes = [];
        $activeDonors = [];
        $pinnedNodeIds = [];
        if ($editing !== null) {
            $combinationIdForEdit = (int) ($editing['id'] ?? 0);
            $candidateNodes = $this->provisioning?->candidateNodesForCombination($combinationIdForEdit) ?? [];
            $activeDonors = $this->provisioning?->activeDonorsForCombination($combinationIdForEdit) ?? [];
            $pinnedRows = $this->db->fetchAll(
                'SELECT node_id FROM forward_combination_slaves WHERE forward_combination_id = :forward_combination_id AND selection_kind = :selection_kind AND is_active = 1 ORDER BY sort_order ASC, node_id ASC',
                ['forward_combination_id' => $combinationIdForEdit, 'selection_kind' => 'admin_pin']
            );
            foreach ($pinnedRows as $pinnedRow) {
                $nodeId = (int) ($pinnedRow['node_id'] ?? 0);
                if ($nodeId > 0) {
                    $pinnedNodeIds[] = $nodeId;
                }
            }
        }

        $groupOptions = '';
        foreach ($groups as $group) {
            $selected = (string) ($editing['node_group_key'] ?? '') === (string) ($group['group_key'] ?? '') ? ' selected' : '';
            $groupOptions .= '<option value="' . $this->escape((string) ($group['group_key'] ?? '')) . '"' . $selected . '>' . $this->escape((string) ($group['name'] ?? '')) . '</option>';
        }
        $ultraGroupSelected = UltraMode::isBackendMode($selectedBackendMode)
            || (string) ($editing['node_group_key'] ?? '') === UltraMode::CONTROL_PLANE_GROUP;
        $groupOptions .= '<option value="' . UltraMode::CONTROL_PLANE_GROUP . '" data-ultra-control-plane' . ($ultraGroupSelected ? ' selected' : '') . '>None (Ultra control plane)</option>';
        $editingSidecarPointId = (int) ($editing['sidecar_point_id'] ?? 0);
        $sidecarOptions = '<option value=""' . ($editingSidecarPointId <= 0 ? ' selected' : '') . '>None (dedicated LeiGod forward node)</option>';
        foreach ($sidecars as $point) {
            $selected = $editingSidecarPointId === (int) ($point['id'] ?? 0) ? ' selected' : '';
            $sidecarOptions .= '<option value="' . (int) ($point['id'] ?? 0) . '"' . $selected . '>' . $this->escape((string) ($point['name'] ?? '')) . ' (' . $this->escape((string) ($point['sidecar_host'] ?? '')) . ' ' . $this->escape($this->sidecarPortSummary($point)) . ')</option>';
        }
        $cfOptions = '<option value="">None</option>';
        foreach ($cfGroups as $group) {
            $selected = (int) ($editing['cf_location_group_id'] ?? 0) === (int) ($group['id'] ?? 0) ? ' selected' : '';
            $cfOptions .= '<option value="' . (int) ($group['id'] ?? 0) . '"' . $selected . '>' . $this->escape((string) ($group['name'] ?? '')) . ' [' . $this->escape((string) ($group['region_code'] ?? '')) . ']</option>';
        }

        $selectedUltraDestination = strtolower(trim((string) ($editing['ultra_destination_zone'] ?? $request->input('ultra_destination_zone', ''))));
        $ultraDestinationOptions = '';
        foreach ($ultraDestinationZones as $zone) {
            $zoneCode = strtolower(trim((string) ($zone['zone_code'] ?? '')));
            if ($zoneCode === '' || $zoneCode === 'unknown') {
                continue;
            }
            if ($selectedUltraDestination === '') {
                $selectedUltraDestination = $zoneCode;
            }
            $ultraDestinationOptions .= '<option value="' . $this->escape($zoneCode) . '"'
                . ($selectedUltraDestination === $zoneCode ? ' selected' : '') . '>'
                . $this->escape((string) ($zone['label'] ?? $zoneCode)) . '</option>';
        }
        $selectedIngressZones = [];
        $rawSelectedIngressZones = $editing['ultra_allowed_ingress_zones_json'] ?? null;
        if (is_string($rawSelectedIngressZones)) {
            $decodedIngressZones = json_decode($rawSelectedIngressZones, true);
            $selectedIngressZones = is_array($decodedIngressZones) ? array_map('strval', $decodedIngressZones) : [];
        } elseif (is_array($rawSelectedIngressZones)) {
            $selectedIngressZones = array_map('strval', $rawSelectedIngressZones);
        }
        $ultraIngressChecks = '';
        foreach ($ultraIngressZones as $zone) {
            $zoneCode = strtolower(trim((string) ($zone['zone_code'] ?? '')));
            if ($zoneCode === '' || $zoneCode === 'unknown') {
                continue;
            }
            $checked = $editing === null || in_array($zoneCode, $selectedIngressZones, true);
            $ultraIngressChecks .= '<label class="check-row"><input type="checkbox" name="ultra_allowed_ingress_zones[]" value="'
                . $this->escape($zoneCode) . '"' . ($checked ? ' checked' : '') . '> '
                . $this->escape((string) ($zone['label'] ?? $zoneCode)) . '</label>';
        }
        $selectedUltraLines = [];
        foreach ((array) ($editing['ultra_lines'] ?? []) as $membership) {
            if (is_array($membership) && (int) ($membership['ultra_line_id'] ?? 0) > 0) {
                $selectedUltraLines[(int) $membership['ultra_line_id']] = $membership;
            }
        }
        $ultraMembershipRows = '';
        foreach ($ultraLines as $lineIndex => $line) {
            $ultraLineId = (int) ($line['id'] ?? 0);
            if ($ultraLineId <= 0
                || (int) ($line['is_source_present'] ?? 0) !== 1
                || (int) ($line['is_enabled'] ?? 0) !== 1) {
                continue;
            }
            $membership = $selectedUltraLines[$ultraLineId] ?? null;
            $checked = is_array($membership) && (int) ($membership['enabled'] ?? 1) === 1;
            $sortOrder = (int) ($membership['sort_order'] ?? $line['sort_order'] ?? (($lineIndex + 1) * 10));
            $expiresAt = trim((string) ($line['last_material_expires_at'] ?? ''));
            $materialState = UltraInventoryService::materialState($expiresAt, time());
            $materialStateLabel = UltraInventoryService::materialStateLabel($materialState);
            $materialStateClass = UltraInventoryService::materialStateBadgeClass($materialState);
            $label = trim((string) (($line['admin_label'] ?? '') ?: ($line['source_title'] ?? '')));
            $ultraMembershipRows .= '<tr data-ultra-destination="' . $this->escape((string) ($line['effective_destination_zone'] ?? 'unknown')) . '">'
                . '<td><input type="hidden" name="ultra_lines[' . $lineIndex . '][ultra_line_id]" value="' . $ultraLineId . '">'
                . '<input type="hidden" name="ultra_lines[' . $lineIndex . '][enabled]" value="0">'
                . '<label><input type="checkbox" name="ultra_lines[' . $lineIndex . '][enabled]" value="1"' . ($checked ? ' checked' : '') . '> '
                . $this->escape($label !== '' ? $label : ('Line ' . (int) ($line['line_id'] ?? 0))) . '</label></td>'
                . '<td>' . (int) ($line['line_id'] ?? 0) . '</td>'
                . '<td>' . $this->escape((string) ($line['effective_ingress_zone'] ?? 'unknown')) . '</td>'
                . '<td>' . $this->escape((string) ($line['effective_destination_zone'] ?? 'unknown')) . '</td>'
                . '<td><span class="badge ' . $materialStateClass . '">' . $this->escape($materialStateLabel) . '</span></td>'
                . '<td><input type="number" name="ultra_lines[' . $lineIndex . '][sort_order]" value="' . $sortOrder . '" style="width:90px"></td>'
                . '</tr>';
        }
        if ($ultraMembershipRows === '') {
            $ultraMembershipRows = '<tr><td colspan="6" class="muted">No enabled Ultra inventory lines.</td></tr>';
        }
        $ultraFallback = strtolower(trim((string) ($editing['ultra_fallback_policy'] ?? 'detected_then_any')));
        $ultraField = '<fieldset class="field" data-mode-field="ultra" data-ultra-memberships><legend>Ultra line policy</legend>'
            . '<div class="form-grid"><div class="field"><label>Destination zone</label><select name="ultra_destination_zone">' . $ultraDestinationOptions . '</select></div>'
            . '<div class="field"><label>Fallback policy</label><select name="ultra_fallback_policy">'
            . '<option value="detected_only"' . ($ultraFallback === 'detected_only' ? ' selected' : '') . '>Detected zone only</option>'
            . '<option value="detected_then_any"' . ($ultraFallback === 'detected_then_any' ? ' selected' : '') . '>Detected zone, then any allowed zone</option>'
            . '<option value="any"' . ($ultraFallback === 'any' ? ' selected' : '') . '>Any allowed zone</option></select></div>'
            . '<div class="field"><label>Allowed ingress zones</label><div class="check-list">' . $ultraIngressChecks . '</div></div></div>'
            . '<div class="table-wrap"><table><thead><tr><th>Enabled</th><th>Line ID</th><th>Ingress</th><th>Destination</th><th>Material</th><th>Priority</th></tr></thead><tbody>'
            . $ultraMembershipRows . '</tbody></table></div></fieldset>'
            . '<script>(function(){var s=document.querySelector(\'select[name=ultra_destination_zone]\');if(!s)return;function f(){document.querySelectorAll(\'[data-ultra-destination]\').forEach(function(r){r.style.display=r.getAttribute(\'data-ultra-destination\')===s.value?\'\':\'none\';});}s.addEventListener(\'change\',f);f();})();</script>';

        // LeiGod is a first-class Forward Mode; the ordered catalog memberships replace the legacy scalar.
        $leigodLineId = (int) ($editing['leigod_line_id'] ?? 0);
        $leigodUot = strtolower((string) ($editing['leigod_uot_impl'] ?? 'lguot'));
        $selectedLeigodLines = [];
        foreach ((array) ($editing['leigod_lines'] ?? []) as $membership) {
            if (!is_array($membership)) {
                continue;
            }
            $catalogId = (int) ($membership['leigod_line_id'] ?? 0);
            if ($catalogId > 0) {
                $selectedLeigodLines[$catalogId] = $membership;
            }
        }
        $leigodMembershipRows = '';
        foreach ($leigodLines as $lineIndex => $line) {
            $catalogId = (int) ($line['id'] ?? 0);
            if ($catalogId <= 0) {
                continue;
            }
            $membership = $selectedLeigodLines[$catalogId] ?? null;
            $checked = is_array($membership) && (int) ($membership['enabled'] ?? 1) === 1;
            $sortOrder = (int) ($membership['sort_order'] ?? $line['sort_order'] ?? (($lineIndex + 1) * 10));
            $configurationState = (string) ($line['configuration_state'] ?? 'missing');
            $lineActive = (int) ($line['is_active'] ?? 0) === 1;
            $selectable = $lineActive && $configurationState === 'ready';
            $disabled = !$selectable && !$checked ? ' disabled' : '';
            $leigodMembershipRows .= '<tr>'
                . '<td><input type="hidden" name="leigod_lines[' . $lineIndex . '][leigod_line_id]" value="' . $catalogId . '">'
                . '<input type="hidden" name="leigod_lines[' . $lineIndex . '][enabled]" value="0">'
                . '<label><input type="checkbox" name="leigod_lines[' . $lineIndex . '][enabled]" value="1"' . ($checked ? ' checked' : '') . $disabled . '> '
                . $this->escape((string) ($line['label'] ?? '')) . '</label></td>'
                . '<td>' . (int) ($line['line_id'] ?? 0) . '</td>'
                . '<td><span class="badge ' . ($configurationState === 'ready' ? 'success' : 'danger') . '">' . $this->escape($configurationState) . '</span></td>'
                . '<td><input type="number" name="leigod_lines[' . $lineIndex . '][sort_order]" value="' . $sortOrder . '" style="width:90px"></td>'
                . '</tr>';
        }
        if ($leigodMembershipRows === '') {
            $leigodMembershipRows = '<tr><td colspan="4" class="muted">No catalog lines available.</td></tr>';
        }
        $leigodField = '<input type="hidden" name="leigod_line_id" value="' . $leigodLineId . '">'
            . '<fieldset class="field" data-mode-field="leigod_mode" data-leigod-memberships><legend>Managed TYO line pool</legend>'
            . '<div class="table-wrap"><table><thead><tr><th>Enabled</th><th>Line ID</th><th>Config</th><th>Priority</th></tr></thead><tbody>'
            . $leigodMembershipRows . '</tbody></table></div><p class="muted">Managed TYO transport = direct ingress (like current_mode, no Cloudflare) + an accelerated self-leg.</p></fieldset>'
            . '<div class="field" data-mode-field="leigod_mode"><label>Managed TYO carrier</label>'
            . '<select name="leigod_uot_impl"><option value="lguot"' . ($leigodUot !== 'gost' ? ' selected' : '') . '>lguot (recommended)</option>'
            . '<option value="gost"' . ($leigodUot === 'gost' ? ' selected' : '') . '>gost</option></select>'
            . '<p class="muted">CPU-efficient UDP-over-TCP carrier for the accelerated self-leg (~2.4x gost throughput at 1/3 CPU).</p></div>'
             . '<script>(function(){var bm=document.querySelector(\'select[name=backend_mode]\');if(!bm)return;'
             . 'function sync(){var v=bm.value;'
             . 'document.querySelectorAll(\'[data-mode-field],[data-mode-fields]\').forEach(function(e){var one=e.getAttribute(\'data-mode-field\');var many=(e.getAttribute(\'data-mode-fields\')||\'\').split(/\\s+/);e.style.display=(one===v||many.indexOf(v)!==-1)?\'\':\'none\';});'
             . 'var ngf=document.querySelector(\'[data-node-group-field]\');var ng=ngf?ngf.querySelector(\'select[name=node_group_key]\'):null;var uo=ng?ng.querySelector(\'[data-ultra-control-plane]\'):null;if(ngf&&ng&&uo){uo.disabled=(v!==\'ultra\');if(v===\'ultra\'){uo.disabled=false;ng.value=\'' . UltraMode::CONTROL_PLANE_GROUP . '\';ngf.style.display=\'none\';}else{ngf.style.display=\'\';if(ng.value===\'' . UltraMode::CONTROL_PLANE_GROUP . '\'){var fallback=Array.prototype.find.call(ng.options,function(o){return o.value!==\'' . UltraMode::CONTROL_PLANE_GROUP . '\';});ng.value=fallback?fallback.value:\'\';}}}'
             . 'document.querySelectorAll(\'[data-non-ultra-field]\').forEach(function(e){e.style.display=(v===\'ultra\')?\'none\':\'\';});'
             . 'document.querySelectorAll(\'[data-ultra-single-field]\').forEach(function(e){var s=e.querySelector(\'select\');if(v===\'ultra\'&&s){s.value=\'single\';}e.style.display=(v===\'ultra\')?\'none\':\'\';});'
             . 'var cf=document.querySelector(\'select[name=cf_location_group_id]\');if(cf){var f=cf.closest(\'.field\');if(f){f.style.display=(v===\'cf_mode\')?\'\':\'none\';}}}'
            . 'bm.addEventListener(\'change\',sync);sync();})();</script>';

        $latestHeartbeats = $this->db->fetchAll(
            'SELECT nh.node_id, nh.payload_json, n.name AS node_name
             FROM node_heartbeats nh
             INNER JOIN nodes n ON n.id = nh.node_id
             INNER JOIN (
                 SELECT node_id, MAX(id) AS max_id
                 FROM node_heartbeats
                 GROUP BY node_id
             ) latest ON latest.max_id = nh.id
             ORDER BY n.name ASC'
        );
        $cfStatsRows = '';
        foreach ($latestHeartbeats as $heartbeat) {
            $payload = is_string($heartbeat['payload_json'] ?? null) ? json_decode((string) $heartbeat['payload_json'], true) : [];
            if (!is_array($payload)) {
                continue;
            }
            foreach (($payload['cf_location_stats'] ?? []) as $stat) {
                if (!is_array($stat)) {
                    continue;
                }
                $cfStatsRows .= '<tr>
                    <td>' . $this->escape((string) ($heartbeat['node_name'] ?? '')) . '</td>
                    <td>' . (int) ($stat['location_group_id'] ?? 0) . '</td>
                    <td>' . $this->escape((string) ($stat['selected_ip'] ?? '')) . '</td>
                    <td>' . $this->escape((string) ($stat['last_latency_ms'] ?? '')) . '</td>
                    <td>' . $this->escape((string) ($stat['last_jitter_ms'] ?? '')) . '</td>
                    <td>' . $this->escape((string) ($stat['last_probe_at'] ?? '')) . '</td>
                </tr>';
            }
        }
        if ($cfStatsRows === '') {
            $cfStatsRows = '<tr><td colspan="6" class="muted">No slave has reported CF location stats yet.</td></tr>';
        }

        $rows = '';
        foreach ($combinations as $combination) {
            $rows .= '<tr>
                <td>' . (int) ($combination['id'] ?? 0) . '</td>
                <td>' . $this->escape((string) ($combination['tenant_label'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($combination['tenant_description'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($combination['tenant_badge'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($combination['line_direction'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($combination['backend_mode'] ?? 'current_mode')) . '</td>
                <td>' . $this->escape((string) ($combination['client_mode'] ?? 'single')) . '</td>
                <td>' . (UltraMode::isBackendMode($combination['backend_mode'] ?? '') ? '&ndash;' : $this->escape((string) ($combination['node_group_key'] ?? ''))) . '</td>
                <td>' . $this->escape((string) ($combination['sidecar_point_name'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($combination['cf_location_group_name'] ?? '')) . '</td>
                <td>' . (((int) ($combination['is_active'] ?? 1)) === 1 ? '<span class="badge success">active</span>' : '<span class="badge danger">inactive</span>') . '</td>
                <td><a class="button ghost" href="/admin/forward-combinations?edit=' . (int) ($combination['id'] ?? 0) . '">Edit</a></td>
            </tr>';
        }

        $csrf = $this->escape(SessionAuth::csrfToken());
        $slaveOptions = '';
        foreach ($candidateNodes as $node) {
            $nodeId = (int) ($node['id'] ?? 0);
            if ($nodeId <= 0) {
                continue;
            }
            $label = trim((string) ($node['name'] ?? ('node #' . $nodeId)));
            $groupKey = trim((string) ($node['node_group_key'] ?? $node['group_key'] ?? ''));
            if ($groupKey !== '') {
                $label .= ' [' . $groupKey . ']';
            }
            $host = trim((string) (($node['public_ip'] ?? '') ?: ($node['public_host'] ?? '')));
            if ($host !== '') {
                $label .= ' ' . $host;
            }
            $selected = in_array($nodeId, $pinnedNodeIds, true) ? ' selected' : '';
            $slaveOptions .= '<option value="' . $nodeId . '"' . $selected . '>' . $this->escape($label) . '</option>';
        }
        if ($slaveOptions === '') {
            $slaveOptions = '<option value="" disabled>No eligible slave nodes for the selected helper groups yet</option>';
        }

        $liveDonationRows = '';
        foreach ($activeDonors as $donor) {
            $donorId = (int) ($donor['id'] ?? 0);
            if ($donorId <= 0) {
                continue;
            }
            $comboId = (int) ($donor['forward_combination_id'] ?? ($editing['id'] ?? 0));
            $clientLabel = trim((string) (($donor['client_name'] ?? '') ?: ('client #' . (int) ($donor['client_id'] ?? 0))));
            $endpointSummary = 'redacted endpoint';
            $liveDonationRows .= '<tr>'
                . '<td>' . $this->escape($clientLabel) . '</td>'
                . '<td>' . $this->escape((string) ($donor['state'] ?? '')) . '</td>'
                . '<td>' . $this->escape((string) ($donor['last_measured_latency_ms'] ?? '')) . '</td>'
                . '<td>' . $this->escape($endpointSummary) . '</td>'
                . '<td><form method="post" action="/admin/forward-combinations/' . $comboId . '/donations/' . $donorId . '/revoke" class="inline">'
                . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                . '<button class="button danger" type="submit">Revoke</button></form></td>'
                . '</tr>';
        }
        if ($liveDonationRows === '') {
            $liveDonationRows = '<tr><td colspan="5" class="muted">No active donated client-as-a-slave rows yet.</td></tr>';
        }

        $donationSection = '
                  <div class="card" style="margin-top:16px;" data-non-ultra-field>
                    <h3>Public donation</h3>
                    <p class="muted">Allow opted-in client self-leg capacity to become a public donated slave for this forward combination only. The donation gate is based on slave -&gt; sidecar -&gt; endpoint RTT.</p>
                    <div class="form-grid">
                      <div class="field"><label>Desired latency (ms)</label><input type="number" name="desired_latency_ms" min="0" value="' . $this->escape((string) ($editing['desired_latency_ms'] ?? 0)) . '"><p class="muted">Donation gate is 75% of this value.</p></div>
                      <div class="field"><label>Ideal slave WG inner RTT (ms)</label><input type="number" name="slave_wg_ideal_latency_ms" min="0" value="' . $this->escape((string) ($editing['slave_wg_ideal_latency_ms'] ?? '')) . '"><p class="muted">0/blank uses Desired latency; blank disables if Desired latency is also 0.</p></div>
                      <div class="field"><label>Allow public client-as-a-slave donation</label><select name="allow_public_self_leg_donation"><option value="0"' . (((int) ($editing['allow_public_self_leg_donation'] ?? 0)) === 0 ? ' selected' : '') . '>No</option><option value="1"' . (((int) ($editing['allow_public_self_leg_donation'] ?? 0)) === 1 ? ' selected' : '') . '>Yes</option></select></div>
                      <div class="field"><label>Qualify window (s)</label><input type="number" name="donation_qualify_window_seconds" min="5" value="' . $this->escape((string) ($editing['donation_qualify_window_seconds'] ?? 60)) . '"></div>
                      <div class="field"><label>Revoke window (s)</label><input type="number" name="donation_revoke_window_seconds" min="5" value="' . $this->escape((string) ($editing['donation_revoke_window_seconds'] ?? 30)) . '"></div>
                      <div class="field"><label>Minimum open inbound ports</label><input type="number" name="donation_min_open_ports" min="0" value="' . $this->escape((string) ($editing['donation_min_open_ports'] ?? 1)) . '"></div>
                    </div>
                  </div>';

        $slavePickerSection = '
                  <div class="card" style="margin-top:16px;" data-non-ultra-field>
                    <h3>Slaves</h3>
                    <div class="field"><label>Admin-pinned rented slaves</label><select name="combination_slaves[]" multiple size="10">' . $slaveOptions . '</select><p class="muted">Default 4. The runtime keeps only the fastest N slaves in the active chain, where speed = slave -&gt; sidecar -&gt; endpoint RTT.</p></div>
                    <h3 style="margin-top:18px;">Live donations</h3>
                    <div class="table-wrap"><table><thead><tr><th>Client</th><th>State</th><th>RTT</th><th>Endpoint</th><th>Action</th></tr></thead><tbody>' . $liveDonationRows . '</tbody></table></div>
                  </div>';

        $previewLabel = (string) ($editing['tenant_label'] ?? 'SHA -> NRT');
        $previewDescription = (string) ($editing['tenant_description'] ?? 'Best for Japan');
        $previewBadge = (string) ($editing['tenant_badge'] ?? 'Recommended');
        $helperNodeGroupKeysText = implode("\n", array_map(
            static fn(mixed $value): string => trim((string) $value),
            is_array($editing['helper_node_group_keys'] ?? null) ? (array) $editing['helper_node_group_keys'] : []
        ));
        $body = '
            <div class="hero">
              <div>
                <h2>Forward Combinations</h2>
                <p class="muted">Compose backend topology into one tenant-facing route package. Tenants should only ever choose this object.</p>
              </div>
            </div>
            <div class="grid two">
              <section class="card">
                <h3>' . ($editing !== null ? 'Edit Forward Combination' : 'Create Forward Combination') . '</h3>
                <form method="post" action="/admin/forward-combinations">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <input type="hidden" name="id" value="' . (int) ($editing['id'] ?? 0) . '">
                  <div class="form-grid">
                    <div class="field"><label>Internal Name</label><input type="text" name="name" value="' . $this->escape((string) ($editing['name'] ?? '')) . '" required></div>
                    <div class="field"><label>Tenant Label</label><input type="text" name="tenant_label" value="' . $this->escape((string) ($editing['tenant_label'] ?? '')) . '" required></div>
                    <div class="field"><label>Tenant Description</label><input type="text" name="tenant_description" value="' . $this->escape((string) ($editing['tenant_description'] ?? '')) . '" placeholder="Best for Japan"></div>
                    <div class="field"><label>Tenant Badge</label><input type="text" name="tenant_badge" value="' . $this->escape((string) ($editing['tenant_badge'] ?? '')) . '" placeholder="Recommended"></div>
                    <div class="field"><label>Direction</label><input type="text" name="line_direction" value="' . $this->escape((string) ($editing['line_direction'] ?? '')) . '" placeholder="Shanghai to Japan" maxlength="191"></div>
                    <div class="field" data-node-group-field><label>Slave Group</label><select name="node_group_key">' . $groupOptions . '</select></div>
                    <div class="field"><label>Backend Mode</label><select name="backend_mode"><option value="current_mode"' . ($selectedBackendMode === 'current_mode' ? ' selected' : '') . '>current_mode</option><option value="cf_mode"' . ($selectedBackendMode === 'cf_mode' ? ' selected' : '') . '>cf_mode</option><option value="leigod_mode"' . ($selectedBackendMode === 'leigod_mode' ? ' selected' : '') . '>Managed TYO transport</option><option value="ultra"' . ($selectedBackendMode === 'ultra' ? ' selected' : '') . '>Ultra</option></select></div>
                    <div class="field" data-non-ultra-field><label>Transport Endpoint</label><select name="sidecar_point_id">' . $sidecarOptions . '</select><p class="muted">Optional for dedicated Managed TYO forward-node routes. Required for bond/MPTCP aggregation and CF mode; aggregation transport endpoints must be overseas.</p></div>
                    <div class="field"><label>Cloudflare Location Group</label><select name="cf_location_group_id">' . $cfOptions . '</select></div>
                    <div class="field" data-ultra-single-field><label>Client Mode</label><select name="client_mode"><option value="single"' . (((string) ($editing['client_mode'] ?? 'single')) === 'single' ? ' selected' : '') . '>single</option><option value="bond"' . (((string) ($editing['client_mode'] ?? 'single')) === 'bond' ? ' selected' : '') . '>bond</option></select></div>
                    <div class="field" data-ultra-single-field><label>Runtime Preference</label><select name="runtime_preference"><option value="single"' . (((string) ($editing['runtime_preference'] ?? 'single')) === 'single' ? ' selected' : '') . '>single</option><option value="mptcp_preferred_bond_fallback"' . (((string) ($editing['runtime_preference'] ?? 'single')) === 'mptcp_preferred_bond_fallback' ? ' selected' : '') . '>mptcp_preferred_bond_fallback</option></select></div>
                    <div class="field" data-non-ultra-field><label>Allow Private Self Leg</label><select name="allow_private_self_leg"><option value="0"' . (((int) ($editing['allow_private_self_leg'] ?? 0)) === 0 ? ' selected' : '') . '>No</option><option value="1"' . (((int) ($editing['allow_private_self_leg'] ?? 0)) === 1 ? ' selected' : '') . '>Yes</option></select></div>
                    <div class="field" data-non-ultra-field><label>Self Leg Scope</label><select name="self_leg_scope"><option value="none"' . (((string) ($editing['self_leg_scope'] ?? 'none')) === 'none' ? ' selected' : '') . '>none</option><option value="device_session"' . (((string) ($editing['self_leg_scope'] ?? 'none')) === 'device_session' ? ' selected' : '') . '>device_session</option></select></div>
                    <div class="field" data-non-ultra-field><label>Self Leg Role</label><select name="self_leg_role"><option value="none"' . (((string) ($editing['self_leg_role'] ?? 'none')) === 'none' ? ' selected' : '') . '>none</option><option value="latency_anchor"' . (((string) ($editing['self_leg_role'] ?? 'none')) === 'latency_anchor' ? ' selected' : '') . '>latency_anchor</option></select></div>
                    <div class="field" data-non-ultra-field><label>Max Legs Used (cap)</label><input type="number" name="helper_group_cap" min="0" value="' . $this->escape((string) ($editing['helper_group_cap'] ?? 4)) . '"><p class="muted">Default 4 for bond, CF Bond and MPTCP. 0 = unlimited.</p></div>
                    <div class="field" data-non-ultra-field><label>Helper Escalation Policy</label><select name="helper_escalation_policy"><option value="disabled"' . (((string) ($editing['helper_escalation_policy'] ?? 'disabled')) === 'disabled' ? ' selected' : '') . '>disabled</option><option value="desired_latency_only"' . (((string) ($editing['helper_escalation_policy'] ?? 'disabled')) === 'desired_latency_only' ? ' selected' : '') . '>desired_latency_only</option></select></div>
                    <div class="field" data-non-ultra-field><label>Self-leg CF transport</label><select name="self_leg_cf_transport"><option value="cf_direct"' . (((string) ($editing['self_leg_cf_transport'] ?? 'cf_direct')) === 'cf_direct' ? ' selected' : '') . '>cf_direct (default)</option><option value="cf_wg"' . (((string) ($editing['self_leg_cf_transport'] ?? 'cf_direct')) === 'cf_wg' ? ' selected' : '') . '>cf_wg (WG over CF IP)</option></select><p class="muted">Only effective when backend_mode = cf_mode. cf_wg routes the WireGuard underlay through wstunnel UDP-over-WSS to the CF IP.</p></div>
                    ' . $leigodField . '
                    ' . $ultraField . '
                    <div class="field" data-non-ultra-field><label>Bond Ingress Mode</label><select name="bond_ingress_mode"><option value="plain_tcp_mux"' . (((string) ($editing['bond_ingress_mode'] ?? 'plain_tcp_mux')) === 'plain_tcp_mux' ? ' selected' : '') . '>plain_tcp_mux</option><option value="wss_path_mux"' . (((string) ($editing['bond_ingress_mode'] ?? 'plain_tcp_mux')) === 'wss_path_mux' ? ' selected' : '') . '>wss_path_mux</option></select></div>
                    <div class="field"><label>Sort Order</label><input type="number" name="sort_order" value="' . $this->escape((string) ($editing['sort_order'] ?? 100)) . '"></div>
                    <div class="field"><label>Status</label><select name="is_active"><option value="1"' . (((int) ($editing['is_active'] ?? 1)) === 1 ? ' selected' : '') . '>active</option><option value="0"' . (((int) ($editing['is_active'] ?? 1)) !== 1 ? ' selected' : '') . '>inactive</option></select></div>
                  </div>
                  <div class="field" style="margin-top:14px;" data-non-ultra-field><label>Helper Node Group Keys</label><textarea name="helper_node_group_keys_text" placeholder="shanghai-a&#10;shanghai-b&#10;guangzhou-a">' . $this->escape($helperNodeGroupKeysText) . '</textarea><p class="muted">One node group key per line, ordered by priority.</p></div>
                  ' . $donationSection . '
                  ' . $slavePickerSection . '
                  <div class="field" style="margin-top:14px;"><label>Notes</label><textarea name="notes">' . $this->escape((string) ($editing['notes'] ?? '')) . '</textarea></div>
                  <div class="card" style="margin-top:16px;">
                    <h3 style="margin-bottom:8px;">Tenant sees:</h3>
                    <div><strong>' . $this->escape($previewLabel) . '</strong></div>
                    <div class="muted">' . $this->escape($previewDescription) . '</div>
                    <div style="margin-top:8px;">' . ($previewBadge !== '' ? '<span class="badge info">' . $this->escape($previewBadge) . '</span>' : '<span class="muted">No badge</span>') . '</div>
                  </div>
                  <div class="actions"><button class="button" type="submit">Save Combination</button>' . ($editing !== null ? '<a class="button secondary" href="/admin/forward-combinations">Cancel</a>' : '') . '</div>
                </form>
              </section>
              <section class="card">
                <h3>Forward Combination List</h3>
                <div class="table-wrap">
                  <table>
                    <thead><tr><th>ID</th><th>Tenant Label</th><th>Description</th><th>Badge</th><th>Direction</th><th>Backend</th><th>Mode</th><th>Slave Group</th><th>Sidecar</th><th>CF Group</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>' . $rows . '</tbody>
                  </table>
                </div>
                <h3 style="margin-top:18px;">Current Best CF IP</h3>
                <div class="table-wrap">
                  <table>
                    <thead><tr><th>Node</th><th>Location Group</th><th>Current Best CF IP</th><th>last_latency_ms</th><th>last_jitter_ms</th><th>Last Probe</th></tr></thead>
                    <tbody>' . $cfStatsRows . '</tbody>
                  </table>
                </div>
              </section>
            </div>';

        return $this->adminPage('Forward Combinations', $body, $viewer, 'forward-combinations');
    }

    public function forwardRoutesPage(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }
        $csrf = $this->escape(SessionAuth::csrfToken());
        $editId = (int) ($request->query()['edit'] ?? 0);
        $routes = $this->db->fetchAll('SELECT * FROM forward_routes ORDER BY sort_order ASC, id ASC');
        $combos = $this->db->fetchAll('SELECT id, tenant_label, name, backend_mode, node_group_key, self_leg_outer_transport FROM forward_combinations WHERE is_active = 1 ORDER BY sort_order ASC, id ASC');
        $editing = $editId > 0 ? $this->db->fetchOne('SELECT * FROM forward_routes WHERE id = :id LIMIT 1', ['id' => $editId]) : null;
        $selected = [];
        if ($editing !== null) {
            foreach ($this->db->fetchAll('SELECT forward_combination_id, enabled, sort_order FROM forward_route_combinations WHERE forward_route_id = :id', ['id' => $editId]) as $rc) {
                $selected[(int) ($rc['forward_combination_id'] ?? 0)] = $rc;
            }
        }
        $comboRows = '';
        foreach ($combos as $c) {
            $cid = (int) ($c['id'] ?? 0);
            $checked = isset($selected[$cid]) && (int) ($selected[$cid]['enabled'] ?? 1) === 1 ? ' checked' : '';
            $sort = (int) ($selected[$cid]['sort_order'] ?? 100);
            $label = $this->escape((string) ($c['tenant_label'] ?? $c['name'] ?? ('#' . $cid)));
            $meta = $this->escape((string) ($c['backend_mode'] ?? '') . ' / ' . (string) ($c['self_leg_outer_transport'] ?? 'cnix') . ' / ' . (string) ($c['node_group_key'] ?? ''));
            $comboRows .= '<tr><td><label><input type="checkbox" name="combinations[' . $cid . '][forward_combination_id]" value="' . $cid . '"' . $checked . '> ' . $label . '</label></td><td class="muted">' . $meta . '</td><td><input type="number" style="width:80px" name="combinations[' . $cid . '][sort_order]" value="' . $sort . '"></td></tr>';
        }
        $routeRows = '';
        foreach ($routes as $r) {
            $rid = (int) ($r['id'] ?? 0);
            $availability = $this->provisioning?->leigodRouteAvailability($rid, 'tcp') ?? [];
            $healthGated = (bool) ($availability['health_gated'] ?? false);
            $health = !$healthGated
                ? '<span class="muted">not gated</span>'
                : ((bool) ($availability['available'] ?? false)
                    ? '<span class="badge success">' . (int) ($availability['healthy_node_count'] ?? 0) . ' healthy</span>'
                    : '<span class="badge danger">' . $this->escape((string) ($availability['unavailable_reason'] ?? 'unavailable')) . '</span>');
            $routeRows .= '<tr><td>' . $rid . '</td><td>' . $this->escape((string) ($r['tenant_label'] ?? $r['name'] ?? '')) . '</td><td>' . ((int) ($r['race_enabled'] ?? 1) === 1 ? 'on' : 'off') . '</td><td>' . ((int) ($r['is_active'] ?? 1) === 1 ? 'active' : 'inactive') . '</td><td>' . $health . '</td><td><a class="button secondary" href="/admin/forward-routes?edit=' . $rid . '">Edit</a></td></tr>';
        }
        $raceChecked = ($editing === null || (int) ($editing['race_enabled'] ?? 1) === 1) ? ' checked' : '';
        $activeChecked = ($editing === null || (int) ($editing['is_active'] ?? 1) === 1) ? ' checked' : '';
        $body = '<div class="grid">
              <section class="card">
                <h3>' . ($editing !== null ? 'Edit Forward Route #' . $editId : 'New Forward Route') . '</h3>
                <p class="muted">A route bundles forward combinations that share the client&rarr;slave ingress (same backend_mode); the slave auto-races the enabled ones and fails over. ultra stays isolated from standard and Managed TYO routes. Mixed backend_mode is rejected.</p>
                <form method="post" action="/admin/forward-routes">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  ' . ($editing !== null ? '<input type="hidden" name="id" value="' . $editId . '">' : '') . '
                  <div class="field"><label>Name (internal)</label><input name="name" required value="' . $this->escape((string) ($editing['name'] ?? '')) . '"></div>
                  <div class="field"><label>Tenant Label</label><input name="tenant_label" value="' . $this->escape((string) ($editing['tenant_label'] ?? '')) . '"></div>
                  <div class="field"><label>Tenant Description</label><input name="tenant_description" value="' . $this->escape((string) ($editing['tenant_description'] ?? '')) . '"></div>
                  <div class="field"><input type="hidden" name="race_enabled" value="0"><label><input type="checkbox" name="race_enabled" value="1"' . $raceChecked . '> Race enabled (off = pin lowest sort_order)</label></div>
                  <div class="field"><label>Sort Order</label><input type="number" name="sort_order" value="' . (int) ($editing['sort_order'] ?? 100) . '"></div>
                  <div class="field"><label><input type="checkbox" name="is_active" value="1"' . $activeChecked . '> Active</label></div>
                  <h4 style="margin-top:12px;">Combinations in this route</h4>
                  <div class="flash" data-route-health-warning>Protocol Health</div>
                  <div class="table-wrap"><table><thead><tr><th>Include / Combination</th><th>backend / self-leg / group</th><th>Priority</th></tr></thead><tbody>' . $comboRows . '</tbody></table></div>
                  <div class="actions"><button class="button" type="submit">Save Route</button>' . ($editing !== null ? '<a class="button secondary" href="/admin/forward-routes">Cancel</a>' : '') . '</div>
                </form>
              </section>
              <section class="card">
                <h3>Forward Routes</h3>
                <div class="table-wrap"><table><thead><tr><th>ID</th><th>Tenant Label</th><th>Race</th><th>Status</th><th>Health</th><th>Actions</th></tr></thead><tbody>' . $routeRows . '</tbody></table></div>
              </section>
            </div>';
        return $this->adminPage('Forward Routes', $body, $viewer, 'forward-routes');
    }

    public function saveForwardRoute(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }
        try {
            SessionAuth::validateCsrf($request);
            $this->provisioning?->saveForwardRoute(is_array($request->body()) ? $request->body() : []);
            SessionAuth::flash('success', 'Forward route saved.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to save forward route: ' . $this->displayThrowableMessage($throwable));
        }
        return Response::redirect('/admin/forward-routes');
    }

    public function saveForwardCombination(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }
        try {
            SessionAuth::validateCsrf($request);
            $body = is_array($request->body()) ? $request->body() : [];
            if (is_array($body['leigod_lines'] ?? null)) {
                $body['leigod_lines'] = array_values(array_filter(
                    $body['leigod_lines'],
                    static fn(mixed $row): bool => is_array($row)
                        && (int) ($row['leigod_line_id'] ?? 0) > 0
                        && !in_array(strtolower(trim((string) ($row['enabled'] ?? '0'))), ['', '0', 'false', 'no', 'off'], true)
                ));
            }
            if (is_array($body['ultra_lines'] ?? null)) {
                $body['ultra_lines'] = array_values(array_filter(
                    $body['ultra_lines'],
                    static fn(mixed $row): bool => is_array($row)
                        && (int) ($row['ultra_line_id'] ?? 0) > 0
                        && !in_array(strtolower(trim((string) ($row['enabled'] ?? '0'))), ['', '0', 'false', 'no', 'off'], true)
                ));
            }
            $saved = $this->provisioning?->saveForwardCombination($body);
            $savedId = is_array($saved) ? (int) ($saved['id'] ?? 0) : 0;
            $savedIsUltra = UltraMode::isBackendMode($saved['backend_mode'] ?? ($body['backend_mode'] ?? ''));
            $this->provisioning?->saveCombinationAdminPinnedSlaves(
                $savedId,
                !$savedIsUltra && is_array($body['combination_slaves'] ?? null) ? $body['combination_slaves'] : []
            );
            SessionAuth::flash('success', 'Forward combination saved.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to save forward combination: ' . $this->displayThrowableMessage($throwable));
        }
        return Response::redirect('/admin/forward-combinations');
    }

    public function ultraOperationsPage(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }
        $inventory = $this->ultraInventory ?? new UltraInventoryService($this->config, $this->db);
        $statusService = $this->ultraClientStatus ?? new UltraClientStatusService($this->config, $this->db);
        $view = strtolower(trim((string) $request->input('view', 'sessions')));
        if (!in_array($view, ['sessions', 'lines', 'combinations', 'source'], true)) {
            $view = 'sessions';
        }
        $limit = min(200, max(1, (int) $request->input('limit', 50)));
        $pageNumber = max(1, (int) $request->input('page', 1));
        $offset = ($pageNumber - 1) * $limit;
        $inventoryCounts = $inventory->summaryCounts();
        $statusCounts = $statusService->statusCounts();
        $csrf = $this->escape(SessionAuth::csrfToken());

        $summary = '';
        foreach ([
            'Online' => (int) ($statusCounts['online'] ?? 0),
            'Stale' => (int) ($statusCounts['stale'] ?? 0),
            'Available lines' => (int) ($inventoryCounts['discovered'] ?? 0),
            'Unavailable lines' => (int) ($inventoryCounts['unavailable'] ?? 0),
            'Material not required' => (int) ($inventoryCounts['not_required'] ?? 0),
            'Material not acquired' => (int) ($inventoryCounts['not_acquired'] ?? 0),
            'Material expired' => (int) ($inventoryCounts['expired'] ?? 0),
            'Material ready' => (int) ($inventoryCounts['ready'] ?? 0),
            'Expiring soon' => (int) ($inventoryCounts['expiring'] ?? 0),
        ] as $label => $value) {
            $summary .= '<section class="card"><div class="muted">' . $this->escape($label) . '</div><div class="stats">' . $value . '</div></section>';
        }
        $tabs = '';
        foreach ([
            ['view' => 'sessions', 'href' => '/admin/ultra?view=sessions', 'label' => 'Sessions'],
            ['view' => 'lines', 'href' => '/admin/ultra?view=lines', 'label' => 'Lines'],
            ['view' => 'combinations', 'href' => '/admin/ultra?view=combinations', 'label' => 'Combinations'],
            ['view' => 'source', 'href' => '/admin/ultra?view=source', 'label' => 'Source'],
        ] as $tab) {
            $tabs .= '<a class="button ' . ($view === $tab['view'] ? '' : 'ghost') . '" href="' . $tab['href'] . '">' . $tab['label'] . '</a>';
        }

        $content = '';
        if ($view === 'sessions') {
            $sessions = $statusService->sessionsForAdmin([
                'state' => $request->input('state', ''),
                'destination_zone' => $request->input('destination_zone', ''),
                'search' => $request->input('search', ''),
                'forward' => $request->input('forward', ''),
            ], $limit, $offset);
            $rows = '';
            foreach ($sessions as $session) {
                $state = (string) ($session['state'] ?? 'Offline');
                $badge = $state === 'Online' ? 'success' : ($state === 'Stale' ? 'info' : 'danger');
                $user = trim((string) (($session['user_name'] ?? '') ?: ($session['user_email'] ?? '')));
                $client = '#' . (int) ($session['client_id'] ?? 0) . ' ' . trim((string) ($session['client_name'] ?? ''));
                $device = trim((string) (($session['device_name'] ?? '') ?: ($session['device_id'] ?? '')));
                $tcp = trim((string) ($session['latest_tcp_selection_ref'] ?? ''));
                $udp = trim((string) ($session['active_udp_selection_ref'] ?? ''));
                $rows .= '<tr><td><span class="badge ' . $badge . '">' . $this->escape($state) . '</span></td>'
                    . '<td>' . $this->escape($user) . '</td>'
                    . '<td>' . $this->escape($client) . '<br><span class="muted">' . $this->escape($device) . '</span></td>'
                    . '<td>' . $this->escape((string) ($session['client_ip'] ?? '')) . '</td>'
                    . '<td>#' . (int) ($session['forward_id'] ?? 0) . ' ' . $this->escape((string) ($session['forward_label'] ?? '')) . '</td>'
                    . '<td>' . $this->escape((string) ($session['destination'] ?? '')) . '</td>'
                    . '<td>' . $this->escape((string) ($session['detected_ingress_zone'] ?? 'unknown')) . '</td>'
                    . '<td>' . $this->escape($tcp) . '</td>'
                    . '<td>' . $this->escape($udp) . '</td>'
                    . '<td>' . $this->escape((string) ($session['last_seen_at'] ?? '')) . '</td></tr>';
            }
            if ($rows === '') {
                $rows = '<tr><td colspan="10" class="muted">No Ultra sessions in the last 24 hours.</td></tr>';
            }
            $content = '<section class="card"><div class="table-wrap"><table><thead><tr><th>State</th><th>User</th><th>Client / device</th><th>Client IP</th><th>Forward</th><th>Endpoint</th><th>Ingress</th><th>Latest TCP</th><th>Active UDP</th><th>Last seen</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>';
        } elseif ($view === 'lines') {
            $lines = $inventory->listLinesForAdmin([
                'ingress' => $request->input('ingress', ''),
                'destination' => $request->input('destination_zone', ''),
                'catalog_ingress' => $request->input('catalog_ingress', ''),
                'catalog_destination' => $request->input('catalog_destination', ''),
                'include_absent' => $request->input('include_absent', ''),
            ], $limit, $offset);
            $ingressZones = $inventory->activeZones('ingress');
            $destinationZones = $inventory->activeZones('destination');
            $rows = '';
            foreach ($lines as $line) {
                $lineId = (int) ($line['id'] ?? 0);
                $relayIps = is_string($line['relay_ips_json'] ?? null) ? json_decode((string) $line['relay_ips_json'], true) : [];
                $safeRelayIps = [];
                foreach (is_array($relayIps) ? array_slice($relayIps, 0, 8) : [] as $relayIp) {
                    if (filter_var((string) $relayIp, FILTER_VALIDATE_IP) !== false) {
                        $safeRelayIps[] = (string) $relayIp;
                    }
                }
                $expiresAt = trim((string) ($line['last_material_expires_at'] ?? ''));
                $materialState = UltraInventoryService::materialState(
                    $expiresAt,
                    time(),
                    600,
                    (int) ($line['material_required'] ?? 0) === 1,
                );
                $materialStateLabel = UltraInventoryService::materialStateLabel($materialState);
                $materialStateClass = UltraInventoryService::materialStateBadgeClass($materialState);
                $ingressOptions = '<option value="">Auto</option>';
                foreach ($ingressZones as $zone) {
                    $code = (string) ($zone['zone_code'] ?? '');
                    $ingressOptions .= '<option value="' . $this->escape($code) . '"' . ((string) ($line['admin_ingress_zone'] ?? '') === $code ? ' selected' : '') . '>' . $this->escape((string) ($zone['label'] ?? $code)) . '</option>';
                }
                $destinationOptions = '<option value="">Auto</option>';
                foreach ($destinationZones as $zone) {
                    $code = (string) ($zone['zone_code'] ?? '');
                    $destinationOptions .= '<option value="' . $this->escape($code) . '"' . ((string) ($line['admin_destination_zone'] ?? '') === $code ? ' selected' : '') . '>' . $this->escape((string) ($zone['label'] ?? $code)) . '</option>';
                }
                $rows .= '<tr><td>' . (int) ($line['line_id'] ?? 0) . '</td>'
                    . '<td>' . $this->escape((string) ($line['source_title'] ?? '')) . '</td>'
                    . '<td>' . $this->escape((string) ($line['catalog_ingress_label'] ?? '')) . '</td>'
                    . '<td>' . $this->escape((string) ($line['effective_ingress_zone'] ?? 'unknown')) . '</td>'
                    . '<td>' . $this->escape((string) ($line['catalog_destination_label'] ?? '')) . '</td>'
                    . '<td>' . $this->escape((string) ($line['effective_destination_zone'] ?? 'unknown')) . '</td>'
                    . '<td>' . $this->escape((string) ($line['discovery_ip'] ?? '')) . '</td>'
                    . '<td>' . $this->escape(implode(', ', $safeRelayIps)) . '</td>'
                    . '<td><span class="badge ' . $materialStateClass . '">' . $this->escape($materialStateLabel) . '</span><br><span class="muted">' . $this->escape($expiresAt) . '</span></td>'
                    . '<td>' . $this->escape((string) ($line['last_discovered_at'] ?? '')) . '</td>'
                    . '<td><form method="post" action="/admin/ultra/lines/' . $lineId . '"><input type="hidden" name="csrf_token" value="' . $csrf . '">'
                    . '<input name="admin_label" value="' . $this->escape((string) ($line['admin_label'] ?? '')) . '" placeholder="Label">'
                    . '<select name="admin_ingress_zone">' . $ingressOptions . '</select><select name="admin_destination_zone">' . $destinationOptions . '</select>'
                    . '<label><input type="checkbox" name="is_enabled" value="1"' . ((int) ($line['is_enabled'] ?? 0) === 1 ? ' checked' : '') . '> Enabled</label>'
                    . '<input type="number" name="sort_order" value="' . (int) ($line['sort_order'] ?? 100) . '" style="width:78px">'
                    . '<input name="notes" value="' . $this->escape((string) ($line['notes'] ?? '')) . '" placeholder="Notes"><button class="button" type="submit">Save</button></form></td></tr>';
            }
            if ($rows === '') {
                $rows = '<tr><td colspan="11" class="muted">No discovered Ultra lines.</td></tr>';
            }
            $catalogIngressFilter = $this->escape((string) $request->input('catalog_ingress', ''));
            $catalogDestinationFilter = $this->escape((string) $request->input('catalog_destination', ''));
            $includeUnavailable = !in_array(strtolower(trim((string) $request->input('include_absent', ''))), ['', '0', 'false', 'no', 'off'], true);
            $content = '<form method="get" action="/admin/ultra" class="actions" style="margin-bottom:12px"><input type="hidden" name="view" value="lines">'
                . '<label>Catalog ingress <input name="catalog_ingress" value="' . $catalogIngressFilter . '" maxlength="191"></label>'
                . '<label>Catalog destination <input name="catalog_destination" value="' . $catalogDestinationFilter . '" maxlength="191"></label>'
                . '<label><input type="checkbox" name="include_absent" value="1"' . ($includeUnavailable ? ' checked' : '') . '> Include unavailable lines</label>'
                . '<button class="button" type="submit">Filter lines</button><a class="button ghost" href="/admin/ultra?view=lines">Clear</a></form>'
                . '<section class="card"><div class="table-wrap"><table><thead><tr><th>Line ID</th><th>Source title</th><th>Catalog ingress</th><th>Effective ingress</th><th>Catalog destination</th><th>Effective destination</th><th>Discovery IP</th><th>Observed relay IPs</th><th>Material state</th><th>Discovered</th><th>Policy</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>';
        } elseif ($view === 'combinations') {
            $combinations = $this->provisioning?->listUltraCombinationsForAdmin() ?? [];
            $rows = '';
            foreach (array_slice($combinations, $offset, $limit) as $combination) {
                $allowed = is_string($combination['ultra_allowed_ingress_zones_json'] ?? null) ? json_decode((string) $combination['ultra_allowed_ingress_zones_json'], true) : [];
                $memberships = [];
                foreach ((array) ($combination['ultra_lines'] ?? []) as $membership) {
                    if (is_array($membership)) {
                        $memberships[] = (string) ((int) ($membership['line_id'] ?? 0));
                    }
                }
                $rows .= '<tr><td>' . (int) ($combination['id'] ?? 0) . '</td><td>' . $this->escape((string) ($combination['tenant_label'] ?? '')) . '</td>'
                    . '<td>' . $this->escape((string) ($combination['ultra_destination_zone'] ?? 'unknown')) . '</td>'
                    . '<td>' . $this->escape(implode(', ', is_array($allowed) ? array_map('strval', $allowed) : [])) . '</td>'
                    . '<td>' . $this->escape((string) ($combination['ultra_fallback_policy'] ?? 'detected_then_any')) . '</td>'
                    . '<td>' . $this->escape(implode(', ', $memberships)) . '</td><td>' . (int) ($combination['active_forward_count'] ?? 0) . '</td>'
                    . '<td><a class="button ghost" href="/admin/forward-combinations?edit=' . (int) ($combination['id'] ?? 0) . '">Edit</a></td></tr>';
            }
            if ($rows === '') {
                $rows = '<tr><td colspan="8" class="muted">No Ultra combinations.</td></tr>';
            }
            $content = '<section class="card"><div class="table-wrap"><table><thead><tr><th>ID</th><th>Combination</th><th>Destination</th><th>Ingress zones</th><th>Fallback</th><th>Lines</th><th>Forwards</th><th>Action</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>';
        } else {
            $sources = $inventory->listSourcesForAdmin();
            $rows = '';
            foreach (array_slice($sources, $offset, $limit) as $source) {
                $fingerprint = trim((string) ($source['inventory_fingerprint'] ?? ''));
                $status = (int) ($source['is_active'] ?? 1) === 1 ? 'active' : 'disabled';
                $rows .= '<tr><td>' . $this->escape((string) ($source['display_label'] ?? $source['source_id'] ?? '')) . '</td><td>'
                    . $this->escape((string) ($source['source_id'] ?? '')) . '</td><td>' . (int) ($source['priority'] ?? 100) . '</td><td>' . $this->escape($status) . '</td><td>' . $this->escape((string) ($source['agent_version'] ?? '')) . '</td>'
                    . '<td>' . (int) ($source['inventory_count'] ?? 0) . '</td><td>' . $this->escape(substr($fingerprint, 0, 16)) . '</td>'
                    . '<td>' . $this->escape((string) ($source['last_seen_at'] ?? '')) . '</td><td>' . $this->escape((string) ($source['last_inventory_at'] ?? '')) . '</td>'
                    . '<td>' . $this->escape((string) ($source['last_jobs_at'] ?? '')) . '</td><td>' . $this->escape((string) ($source['last_material_upload_at'] ?? '')) . '</td>'
                    . '<td>' . (int) ($source['last_requested_line_count'] ?? 0) . ' / ' . (int) ($source['last_accepted_line_count'] ?? 0) . '</td>
                    <td>' . $this->escape((string) ($source['last_error_code'] ?? '')) . '</td></tr>';
            }
            if ($rows === '') {
                $rows = '<tr><td colspan="13" class="muted">No authenticated source contact.</td></tr>';
            }
            $content = '<section class="card"><div class="table-wrap"><table><thead><tr><th>Point</th><th>Source ID</th><th>Priority</th><th>Status</th><th>Agent</th><th>Inventory</th><th>Fingerprint</th><th>Last contact</th><th>Inventory accepted</th><th>Jobs</th><th>Material upload</th><th>Requested / accepted</th><th>Error</th></tr></thead><tbody>' . $rows . '</tbody></table></div></section>';
        }

        $body = '<div class="hero"><div><h2>Ultra Operations</h2></div><div class="actions"><a class="button" href="/admin/forward-combinations?backend_mode=ultra">New Ultra Combination</a></div></div>'
            . '<div class="grid five">' . $summary . '</div><nav class="actions" style="margin:16px 0">' . $tabs . '</nav>' . $content;
        return $this->adminPage('Ultra Operations', $body, $viewer, 'ultra');
    }

    public function saveUltraLinePolicy(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }
        try {
            SessionAuth::validateCsrf($request);
            $lineId = max(0, (int) ($params['id'] ?? 0));
            if ($lineId <= 0) {
                throw new ApiException('ultra_line_not_found', 404);
            }
            $inventory = $this->ultraInventory ?? new UltraInventoryService($this->config, $this->db);
            $inventory->updateLinePolicy($lineId, $request->body());
            SessionAuth::flash('success', 'Ultra line policy saved.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to save Ultra line policy: ' . $this->displayThrowableMessage($throwable));
        }
        return Response::redirect('/admin/ultra?view=lines');
    }

    public function revokeDonation(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }
        try {
            SessionAuth::validateCsrf($request);
            $this->provisioning?->revokeDonationManually($this->idParam($params), $this->idParam($params, 'donationId'));
            SessionAuth::flash('success', 'Donation revoked.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to revoke donation: ' . $this->displayThrowableMessage($throwable));
        }
        return Response::redirect('/admin/forward-combinations?edit=' . $this->idParam($params));
    }

    public function sidecarsPage(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        $view = PrecisionGridUi::normalizeView((string) $request->input('view', 'list'), ['list', 'create', 'edit', 'updates'], 'list');

        $points = $this->provisioning?->listSidecarPoints() ?? [];
        $tenants = $this->db->fetchAll('SELECT id, name, email FROM users WHERE role = :role ORDER BY name ASC, email ASC', ['role' => 'tenant']);
        $editId = (int) $request->input('edit', 0);
        if ($editId > 0) $view = 'edit';
        $editing = null;
        foreach ($points as $point) {
            if ((int) $point['id'] === $editId) {
                $editing = $point;
                break;
            }
        }
        $editingSidecarPrice = ($editing !== null && $this->provisioning !== null)
            ? $this->provisioning->getTrafficPriceFor('sidecar', (int) $editing['id'], null)
            : null;

        $ownerOptions = '<option value="">Shared / admin-managed</option>';
        foreach ($tenants as $tenant) {
            $selected = $editing !== null && (int) ($editing['owner_user_id'] ?? 0) === (int) $tenant['id'] ? ' selected' : '';
            $ownerOptions .= '<option value="' . (int) $tenant['id'] . '"' . $selected . '>' . $this->escape((string) $tenant['name']) . ' (' . $this->escape((string) $tenant['email']) . ')</option>';
        }

        $sidecarUsageMap = $this->provisioning->sidecarUsageOverviewMap(array_map(
            static fn(array $point): int => (int) ($point['id'] ?? 0),
            $points
        ));
        $rows = '';
        $mobileRows = '';
        foreach ($points as $point) {
            $pointId = (int) $point['id'];
            $pointName = (string) $point['name'];
            $host = (string) $point['sidecar_host'];
            $owner = (string) ($point['owner_email'] ?? 'Shared');
            $status = $this->sidecarPointStatusInfo($point);
            $installCommand = $this->bondSidecarInstallCommand($point);
            $uninstallCommand = $this->bondSidecarUninstallCommand($point);
            $usagePanel = $this->renderUsageOverviewPanel($sidecarUsageMap[(int) ($point['id'] ?? 0)] ?? [], 'Transport Traffic');
            $commands = '<a href="/admin-transport-endpoints?view=edit&amp;edit=' . $pointId . '">Edit</a>
                <form class="inline" method="post" action="/admin-transport-endpoints/' . $pointId . '/request-update"><input type="hidden" name="csrf_token" value="' . $this->escape(SessionAuth::csrfToken()) . '"><button type="submit">Request Update</button></form>
                <details><summary>Install command</summary><pre>' . $this->escape($installCommand) . '</pre></details>
                <details><summary>Uninstall command</summary><pre>' . $this->escape($uninstallCommand) . '</pre></details>
                <details><summary>Usage Calendar</summary>' . $usagePanel . '</details>
                <form class="inline" method="post" action="/admin-transport-endpoints/' . $pointId . '/delete"><input type="hidden" name="csrf_token" value="' . $this->escape(SessionAuth::csrfToken()) . '"><button type="submit" class="danger">Delete</button></form>';
            $rows .= '<tr>
                <td>' . PrecisionGridUi::resourceCell($pointName, $owner) . '</td><td>' . PrecisionGridUi::resourceCell($host, $this->sidecarPortSummary($point)) . '</td>
                <td>' . PrecisionGridUi::status($status['label'], $status['class'] === 'success' ? 'ok' : 'warning') . '</td>
                <td>' . PrecisionGridUi::resourceCell((string) ($point['last_heartbeat_at'] ?? 'Never'), (string) ($point['agent_version'] ?? '')) . '</td>
                <td>' . (int) ($point['forward_count'] ?? 0) . '</td><td>' . PrecisionGridUi::rowMenu($pointName . ' actions', $commands) . '</td>
            </tr>';
            $mobileRows .= '<article class="pg-mobile-resource-row"><header>' . PrecisionGridUi::resourceCell($pointName, $owner) . PrecisionGridUi::status($status['label'], $status['class'] === 'success' ? 'ok' : 'warning') . '</header><dl><div><dt>Address</dt><dd>' . $this->escape($host) . '</dd></div><div><dt>Ports</dt><dd>' . $this->escape($this->sidecarPortSummary($point)) . '</dd></div><div><dt>Heartbeat</dt><dd>' . $this->escape((string) ($point['last_heartbeat_at'] ?? 'Never')) . '</dd></div><div><dt>Forwards</dt><dd>' . (int) ($point['forward_count'] ?? 0) . '</dd></div></dl><footer>' . PrecisionGridUi::rowMenu($pointName . ' actions', $commands) . '</footer></article>';
        }

        $csrf = $this->escape(SessionAuth::csrfToken());
        $tabs = PrecisionGridUi::viewTabs([
            ['key' => 'list', 'label' => 'Endpoints', 'href' => '/admin-transport-endpoints?view=list'],
            ['key' => 'create', 'label' => 'Create', 'href' => '/admin-transport-endpoints?view=create'],
            ['key' => 'updates', 'label' => 'Updates', 'href' => '/admin-transport-endpoints?view=updates'],
        ], $view);
        $listView = '<section class="pg-sheet" data-mobile-resources="true"><div class="pg-sheet-toolbar"><strong>Transport Endpoints</strong><span class="muted">' . count($points) . ' endpoints</span></div><div class="pg-sheet-scroll"><table class="pg-sheet-table"><thead><tr><th>Endpoint / Owner</th><th>Address / Ports</th><th>Status</th><th>Heartbeat / Version</th><th>Forwards</th><th><span class="pg-visually-hidden">Actions</span></th></tr></thead><tbody>' . $rows . '</tbody></table></div><div class="pg-mobile-resource-list">' . $mobileRows . '</div></section>';
        $body = $tabs . ($view === 'list' || $view === 'updates' ? $listView : '') . ($view === 'create' || $view === 'edit' ? '
            <div class="hero">
              <div>
                <h2>Target Transport Endpoints</h2>
                <p class="muted">Create tenant-owned or shared/admin-managed target-side target transport endpoints here. Shared points are visible to all tenants and can be selected in bond or MPTCP forwards.</p>
              </div>
            </div>
            <div class="grid two">
              <section class="card">
                <h3>' . ($editing !== null ? 'Edit Transport Endpoint' : 'Create Transport Endpoint') . '</h3>
                <form method="post" action="/admin-transport-endpoints">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <input type="hidden" name="id" value="' . (int) ($editing['id'] ?? 0) . '">
                  <div class="form-grid">
                    <div class="field"><label>Name</label><input type="text" name="name" value="' . $this->escape((string) ($editing['name'] ?? '')) . '" required></div>
                    <div class="field"><label>Owner Tenant</label><select name="owner_user_id">' . $ownerOptions . '</select></div>
                    <div class="field"><label>Sidecar Host</label><input type="text" name="sidecar_host" value="' . $this->escape((string) ($editing['sidecar_host'] ?? '')) . '" required></div>
                    <div class="field"><label>Bond Listen Port</label><input type="number" name="bond_listen_port" value="' . $this->escape((string) ($editing['bond_listen_port'] ?? $editing['listen_port'] ?? 9000)) . '" required></div>
                    <div class="field"><label>MPTCP Listen Port</label><input type="number" name="mptcp_listen_port" value="' . $this->escape((string) ($editing['mptcp_listen_port'] ?? $this->sidecarMptcpListenPort($editing ?? []))) . '" required></div>
                    <div class="field"><label>CF Listen Port</label><input type="number" name="cf_listen_port" value="' . $this->escape((string) ($editing['cf_listen_port'] ?? 443)) . '" required></div>
                    <div class="field"><label>CF TLS Cert Path</label><input type="text" name="cf_tls_cert_path" value="' . $this->escape((string) ($this->decodeJsonList($editing['agent_config_json'] ?? null)['cf_tls_cert_path'] ?? '')) . '" placeholder="/etc/ssl/cf/fullchain.pem"></div>
                    <div class="field"><label>CF TLS Key Path</label><input type="text" name="cf_tls_key_path" value="' . $this->escape((string) ($this->decodeJsonList($editing['agent_config_json'] ?? null)['cf_tls_key_path'] ?? '')) . '" placeholder="/etc/ssl/cf/privkey.pem"></div>
                    <div class="field"><label>Bind Host</label><input type="text" name="bind_host" value="' . $this->escape((string) ($editing['bind_host'] ?? '0.0.0.0')) . '"></div>
                    <div class="field"><label>Total Limit Mbps</label><input type="number" name="total_speed_limit_mbps" value="' . $this->escape((string) ($editing['total_speed_limit_mbps'] ?? '')) . '" min="1" placeholder="optional"></div>
                    <div class="field"><label>Status</label><select name="is_active"><option value="1"' . (((int) ($editing['is_active'] ?? 1)) === 1 ? ' selected' : '') . '>active</option><option value="0"' . (((int) ($editing['is_active'] ?? 1)) !== 1 ? ' selected' : '') . '>disabled</option></select></div>
                    <div class="field"><label>Traffic Price / GB</label><input type="number" step="0.0001" min="0" name="traffic_price_per_gb" value="' . $this->escape((string) ($editingSidecarPrice['price_per_gb'] ?? '0')) . '" placeholder="0.0000"><p class="muted">Per-GB price for traffic through this transport endpoint. 0 = free.</p></div>
                  </div>
                  <div class="actions"><button class="button" type="submit">Save Transport Endpoint</button>' . ($editing !== null ? '<a class="button secondary" href="/admin-transport-endpoints">Cancel</a>' : '') . '</div>
                </form>
              </section>
              <section class="card">
                <h3>Transport Endpoint List</h3>
                <div class="table-wrap">
                  <table>
                    <thead><tr><th>ID</th><th>Name</th><th>Owner</th><th>Host</th><th>Ports</th><th>Bind</th><th>Total Limit</th><th>Status</th><th>Version</th><th>Heartbeat</th><th>Forwards</th><th>Actions</th></tr></thead>
                    <tbody>' . $rows . '</tbody>
                  </table>
                </div>
              </section>
            </div>' : '');

        return $this->adminPage('Target Transport Endpoints', $body, $viewer, 'transport-endpoints');
    }

    public function saveSidecarPoint(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $this->provisioning?->saveSidecarPoint($request->body());
            $savedId = (int) ($request->input('id', 0));
            if ($savedId <= 0) {
                $last = $this->db->fetchOne('SELECT id FROM sidecar_points ORDER BY id DESC LIMIT 1');
                $savedId = (int) ($last['id'] ?? 0);
            }
            $trafficPrice = trim((string) $request->input('traffic_price_per_gb', '0'));
            if ($savedId > 0 && $this->provisioning !== null) {
                $this->provisioning->upsertTrafficPrice('sidecar', $savedId, null, $trafficPrice);
            }
            SessionAuth::flash('success', 'Transport endpoint saved.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to save target transport endpoint: ' . $this->displayThrowableMessage($throwable));
        }

        return Response::redirect('/admin-transport-endpoints');
    }

    public function deleteSidecarPoint(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        $pointId = $this->idParam($params);
        try {
            SessionAuth::validateCsrf($request);
            $this->provisioning?->deleteSidecarPoint($pointId);
            SessionAuth::flash('success', 'Transport endpoint deleted.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to delete target transport endpoint: ' . $this->displayThrowableMessage($throwable));
        }

        return Response::redirect('/admin-transport-endpoints');
    }

    public function requestSidecarUpdate(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        $pointId = $this->idParam($params);
        try {
            SessionAuth::validateCsrf($request);
            $point = $this->provisioning?->sidecarPointById($pointId);
            if ($point === null) {
                throw new ApiException('sidecar_point_not_found', 404);
            }
            $token = bin2hex(random_bytes(24));
            $this->db->execute(
                'UPDATE sidecar_points
                 SET agent_update_request_token = :request_token,
                     agent_update_requested_at = :requested_at,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    'id' => $pointId,
                    'request_token' => $token,
                    'requested_at' => gmdate('Y-m-d H:i:s'),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]
            );
            SessionAuth::flash('success', 'Transport endpoint update requested. The installed target transport heartbeat will pull the latest panel-hosted version automatically.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to request transport endpoint update: ' . $this->displayThrowableMessage($throwable));
        }

        return Response::redirect('/admin-transport-endpoints');
    }

    public function chainsPage(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        $chains = $this->provisioning?->listForwardChains() ?? [];
        $nodeGroups = $this->provisioning?->listNodeGroups() ?? [];
        $groupCheckboxes = $this->renderGroupCheckboxes($nodeGroups, 'node_group_ids[]');

        $rows = '';
        foreach ($chains as $chain) {
            $steps = array_map(static fn(array $step): string => (string) $step['group_name'], $chain['steps'] ?? []);
            $rows .= '<tr>
                <td>' . (int) $chain['id'] . '</td>
                <td>' . $this->escape((string) $chain['name']) . '</td>
                <td>' . $this->escape((string) ($chain['description'] ?? '')) . '</td>
                <td>' . $this->escape(implode(' -> ', $steps)) . '</td>
                <td>' . ((int) $chain['is_active'] === 1 ? '<span class="badge success">active</span>' : '<span class="badge danger">inactive</span>') . '</td>
            </tr>';
        }

        $csrf = $this->escape(SessionAuth::csrfToken());
        $body = '
            <div class="hero">
              <div>
                <h2>Forward Chains</h2>
                <p class="muted">A chain is an ordered sequence of slave groups. This is the Zero Forwarder style multi-hop chain model.</p>
              </div>
            </div>
            <div class="grid two">
              <section class="card">
                <h3>Create Chain</h3>
                <form method="post" action="/admin/chains">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <div class="form-grid">
                    <div class="field"><label>Name</label><input type="text" name="name" required></div>
                    <div class="field"><label>Status</label><select name="is_active"><option value="1">active</option><option value="0">inactive</option></select></div>
                  </div>
                  <div class="field" style="margin-top:14px;"><label>Description</label><input type="text" name="description"></div>
                  <div class="field" style="margin-top:14px;">
                    <label>Ordered Slave Groups</label>
                    <div class="stack">' . $groupCheckboxes . '</div>
                    <p class="muted">Current implementation uses checkbox order as chain order; later we can replace this with drag-and-drop.</p>
                  </div>
                  <div class="actions"><button class="button" type="submit">Save Chain</button></div>
                </form>
              </section>
              <section class="card">
                <h3>Chain List</h3>
                <div class="table-wrap">
                  <table>
                    <thead><tr><th>ID</th><th>Name</th><th>Description</th><th>Steps</th><th>Status</th></tr></thead>
                    <tbody>' . $rows . '</tbody>
                  </table>
                </div>
              </section>
            </div>';

        return $this->adminPage('Forward Chains', $body, $viewer, 'chains');
    }

    public function nodeGroupsPage(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        $groups = $this->provisioning?->listNodeGroups() ?? [];
        $groupPriceMap = [];
        foreach ($groups as $group) {
            $gp = $this->provisioning?->getTrafficPriceFor('slave', null, (int) $group['id']);
            $groupPriceMap[(int) $group['id']] = $gp !== null ? $gp['price_per_gb'] . ' ' . $gp['currency'] : '-';
        }
        $rows = '';
        foreach ($groups as $group) {
            $rows .= '<tr>
                <td>' . (int) $group['id'] . '</td>
                <td>' . $this->escape((string) $group['group_key']) . '</td>
                <td>' . $this->escape((string) $group['name']) . '</td>
                <td>' . $this->escape((string) ($group['description'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($group['ingress_cdn_host'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($group['ingress_sni_host'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($group['ingress_preferred_public_port'] ?? 'auto')) . '</td>
                <td>' . $this->escape((string) ($group['ingress_path_strategy'] ?? 'random')) . '</td>
                <td>' . (int) ($group['node_count'] ?? 0) . '</td>
                <td>' . $this->escape($groupPriceMap[(int) $group['id']] ?? '-') . '</td>
            </tr>';
        }

        $csrf = $this->escape(SessionAuth::csrfToken());
        $body = '
            <div class="hero">
              <div>
                <h2>Slave Groups</h2>
                <p class="muted">Create logical slave pools before assigning them to plans and installing slaves into them.</p>
              </div>
            </div>
            <div class="grid two">
              <section class="card">
                <h3>Create Slave Group</h3>
                <p class="muted">This is now also your ingress profile for the group. New forwards can inherit CDN/SNI host, path generation strategy and preferred public port from here.</p>
                <form method="post" action="/admin/node-groups">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <div class="form-grid">
                    <div class="field"><label>Group Key</label><input type="text" name="group_key" placeholder="hk-edge" required></div>
                    <div class="field"><label>Name</label><input type="text" name="name" placeholder="Hong Kong Edge" required></div>
                    <div class="field"><label>Default CDN Host</label><input type="text" name="ingress_cdn_host" placeholder="edge.example.com"></div>
                    <div class="field"><label>Default SNI Host</label><input type="text" name="ingress_sni_host" placeholder="edge.example.com"></div>
                    <div class="field"><label>Preferred Public Port</label><input type="number" name="ingress_preferred_public_port" placeholder="443"><p class="muted">Recommended: use <code>443</code> here so slaves in this group share one TLS port and distinguish services by path.</p></div>
                    <div class="field"><label>Path Strategy</label><select name="ingress_path_strategy"><option value="random">random token</option><option value="date-random">date + random token</option></select></div>
                    <div class="field"><label>Port Strategy</label><select name="ingress_port_strategy"><option value="shared_preferred_port">shared preferred port, multi-path routing</option><option value="client_range_then_target">client range first, then target port</option><option value="target_port_then_client_range">target port first, then client range</option></select></div>
                    <div class="field"><label>Traffic Price / GB</label><input type="number" step="0.0001" min="0" name="traffic_price_per_gb" value="0" placeholder="0.0000"><p class="muted">Per-GB price for traffic through slaves in this group. Set 0 for free.</p></div>
                  </div>
                  <div class="field" style="margin-top:14px;"><label>Description</label><input type="text" name="description"></div>
                  <div class="actions"><button class="button" type="submit">Save Group</button></div>
                </form>
              </section>
              <section class="card">
                <h3>Slave Group List</h3>
                <div class="table-wrap">
                  <table>
                    <thead><tr><th>ID</th><th>Group Key</th><th>Name</th><th>Description</th><th>CDN Host</th><th>SNI Host</th><th>Preferred Port</th><th>Path Strategy</th><th>Nodes</th><th>Price/GB</th></tr></thead>
                    <tbody>' . $rows . '</tbody>
                  </table>
                </div>
              </section>
            </div>';

        return $this->adminPage('Slave Groups', $body, $viewer, 'node-groups');
    }

    public function nodesPage(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        $view = PrecisionGridUi::normalizeView(
            (string) $request->input('view', 'nodes'),
            ['nodes', 'install', 'defaults', 'updates', 'detail'],
            'nodes'
        );
        $editId = (int) $request->input('edit', 0);
        if ($editId > 0) {
            $view = 'detail';
        }

        $nodes = $this->db->fetchAll(
            'SELECT n.id, n.name, n.public_ip, n.private_ip, n.region, n.status, n.agent_version, n.current_load, n.last_heartbeat_at, n.agent_config_json, n.credential_profile_id, n.ssl_certificate_id, g.group_key, g.name AS group_name, cp.name AS credential_profile_name, sc.name AS ssl_certificate_name, sc.expires_at AS ssl_certificate_expires_at
             FROM nodes n
             LEFT JOIN node_groups g ON g.id = n.group_id
             LEFT JOIN credential_profiles cp ON cp.id = n.credential_profile_id
             LEFT JOIN ssl_certificates sc ON sc.id = n.ssl_certificate_id
             ORDER BY n.last_heartbeat_at DESC, n.id DESC'
        );
        $groups = $this->provisioning?->listNodeGroups() ?? [];
        $credentialProfiles = $this->db->fetchAll('SELECT * FROM credential_profiles ORDER BY updated_at DESC, id DESC');
        $sslCertificates = $this->db->fetchAll('SELECT * FROM ssl_certificates ORDER BY expires_at ASC, updated_at DESC, id DESC');
        $editing = null;
        foreach ($nodes as $node) {
            if ((int) $node['id'] === $editId) {
                $editing = $node;
                break;
            }
        }
        $editingAgentConfig = $editing !== null ? $this->decodeJsonList($editing['agent_config_json'] ?? null) : [];
        $editingCredentialProfileId = $editing !== null ? (int) ($editing['credential_profile_id'] ?? 0) : 0;
        $editingSslCertificateId = $editing !== null ? (int) ($editing['ssl_certificate_id'] ?? 0) : 0;
        $editingNodePrice = ($editing !== null && $this->provisioning !== null)
            ? $this->provisioning->getTrafficPriceFor('slave', (int) $editing['id'], null)
            : null;
        $editingTlsMode = (string) ($editingAgentConfig['tls_mode'] ?? 'self-signed');
        $editingMuxHosts = $editingAgentConfig['mux_listen_hosts'] ?? ['::', '0.0.0.0'];
        if (is_array($editingMuxHosts)) {
            $editingMuxHosts = implode(',', array_map(static fn(mixed $value): string => (string) $value, $editingMuxHosts));
        } else {
            $editingMuxHosts = (string) $editingMuxHosts;
        }

        $nodeUsageMap = $this->provisioning->nodeUsageOverviewMap(array_map(
            static fn(array $node): int => (int) ($node['id'] ?? 0),
            $nodes
        ));
        $rows = '';
        $mobileRows = '';
        foreach ($nodes as $node) {
            $nodeId = (int) $node['id'];
            $nodeName = (string) $node['name'];
            $publicIp = (string) $node['public_ip'];
            $region = (string) $node['region'];
            $groupName = (string) ($node['group_name'] ?? $node['group_key'] ?? 'Unassigned');
            $status = (string) $node['status'];
            $statusState = $status === 'active' ? 'ok' : 'error';
            $load = (int) $node['current_load'];
            $heartbeat = (string) $node['last_heartbeat_at'];
            $usagePanel = $this->renderUsageOverviewPanel($nodeUsageMap[(int) ($node['id'] ?? 0)] ?? [], 'Slave Traffic');
            $commands = '<a href="/admin/nodes?view=detail&amp;edit=' . $nodeId . '">Edit</a>
                <form class="inline" method="post" action="/admin/nodes/' . $nodeId . '/request-update">
                    <input type="hidden" name="csrf_token" value="' . $this->escape(SessionAuth::csrfToken()) . '">
                    <button type="submit">Request Update</button>
                </form>
                <details><summary>Usage Calendar</summary>' . $usagePanel . '</details>
                <form class="inline" method="post" action="/admin/nodes/' . $nodeId . '/delete" onsubmit="return confirm(\'Delete this slave node and remove its database references? Clients will stop using it on their next config sync.\')">
                    <input type="hidden" name="csrf_token" value="' . $this->escape(SessionAuth::csrfToken()) . '">
                    <button type="submit" class="danger">Delete</button>
                </form>';
            $selection = '<input type="checkbox" class="node-update-select" name="node_ids[]" value="' . $nodeId . '" form="selected-node-update-form" aria-label="Select ' . $this->escape($nodeName) . ' for update">';
            $rows .= '<tr>
                <td><span class="pg-node-select">' . $selection . PrecisionGridUi::resourceCell($nodeName, $publicIp) . '</span></td>
                <td>' . PrecisionGridUi::resourceCell($region, $groupName) . '</td>
                <td>' . PrecisionGridUi::status(ucfirst($status), $statusState) . '</td>
                <td>' . $load . '%</td>
                <td>' . PrecisionGridUi::resourceCell($heartbeat !== '' ? $heartbeat : 'Never', (string) ($node['agent_version'] ?? '')) . '</td>
                <td>' . PrecisionGridUi::rowMenu($nodeName . ' actions', $commands) . '</td>
            </tr>';
            $mobileRows .= '<article class="pg-mobile-resource-row">
                <header>' . $selection . PrecisionGridUi::resourceCell($nodeName, $publicIp) . PrecisionGridUi::status(ucfirst($status), $statusState) . '</header>
                <dl><div><dt>Region</dt><dd>' . $this->escape($region) . '</dd></div><div><dt>Group</dt><dd>' . $this->escape($groupName) . '</dd></div><div><dt>Load</dt><dd>' . $load . '%</dd></div><div><dt>Heartbeat</dt><dd>' . $this->escape($heartbeat !== '' ? $heartbeat : 'Never') . '</dd></div></dl>
                <footer>' . PrecisionGridUi::rowMenu($nodeName . ' actions', $commands) . '</footer>
            </article>';
        }

        $groupOptions = '<option value="">Select a slave group</option>';
        $batchGroupOptions = '<option value="">Select a slave group</option>';
        foreach ($groups as $group) {
            $selected = $editing !== null && (string) ($editing['group_key'] ?? '') === (string) $group['group_key'] ? ' selected' : '';
            $groupOptions .= '<option value="' . $this->escape((string) $group['group_key']) . '"' . $selected . '>' . $this->escape((string) $group['name']) . '</option>';
            $batchGroupOptions .= '<option value="' . $this->escape((string) $group['group_key']) . '">' . $this->escape((string) $group['name']) . ' (' . $this->escape((string) $group['group_key']) . ')</option>';
        }
        $editCredentialOptions = '<option value="">No credential profile</option>';
        $installCredentialOptions = '<option value="">No credential profile</option>';
        foreach ($credentialProfiles as $profile) {
            $value = (int) $profile['id'];
            $label = $this->escape((string) $profile['name']);
            $selected = $editingCredentialProfileId === $value ? ' selected' : '';
            $editCredentialOptions .= '<option value="' . $value . '"' . $selected . '>' . $label . '</option>';
            $installCredentialOptions .= '<option value="' . $value . '">' . $label . '</option>';
        }
        $sslCertificateOptions = '<option value="">No SSL certificate library item</option>';
        foreach ($sslCertificates as $certificate) {
            $value = (int) $certificate['id'];
            $domains = $this->sslCertificateDomainsSummary($certificate);
            $label = $this->escape((string) $certificate['name']) . ($domains !== '' ? ' [' . $this->escape($domains) . ']' : '');
            $selected = $editingSslCertificateId === $value ? ' selected' : '';
            $sslCertificateOptions .= '<option value="' . $value . '"' . $selected . '>' . $label . '</option>';
        }

        $appUrl = $this->escape($this->agentBaseUrl());
        $artifactUrl = $this->escape($this->artifactBaseUrl());
        $token = $this->escape((string) $this->config->get('NODE_ENROLLMENT_TOKEN', 'NODE_ENROLLMENT_TOKEN'));
        $csrf = $this->escape(SessionAuth::csrfToken());
        $edgeCdnHost = $this->escape((string) $this->config->get('DEFAULT_CDN_HOST', 'edge.example.com'));
        $edgeDefaultPublicPort = (int) $this->config->getInt('DEFAULT_EDGE_PUBLIC_PORT', 443);
        $edgePortStrategy = (string) $this->config->get('EDGE_PORT_STRATEGY', 'shared_preferred_port');
        $edgePathStrategy = (string) $this->config->get('EDGE_PATH_STRATEGY', 'random');
        $manualInstallCommand = 'curl -fsSL ' . $artifactUrl . '/artifacts/install-slave.sh | bash -s -- \
  --master ' . $appUrl . ' \
  --token ' . $token . ' \
  --group &lt;group_key&gt; \
  --region &lt;region&gt; \
  --tls-mode self-signed';
        $manualNewInstallCommand = $manualInstallCommand . ' \
  --force-register';

        $tabs = PrecisionGridUi::viewTabs([
            ['key' => 'nodes', 'label' => 'Nodes', 'href' => '/admin/nodes?view=nodes'],
            ['key' => 'install', 'label' => 'Install', 'href' => '/admin/nodes?view=install'],
            ['key' => 'defaults', 'label' => 'Edge Defaults', 'href' => '/admin/nodes?view=defaults'],
            ['key' => 'updates', 'label' => 'Updates', 'href' => '/admin/nodes?view=updates'],
        ], $view);

        $installView = '
            <section class="card">
              <h3>Manual Install Command</h3>
              <div class="flash success">Quick start: create one slave group first, then install one slave into that group. You can build your first tenant forward without chains or multiple endpoints.</div>
              <div class="grid two" style="gap:14px;">
                <div>
                  <h4 style="margin:0 0 8px;">Reuse Existing Identity</h4>
                  <pre>' . $manualInstallCommand . '

# if this host already has /etc/leikwan-slave/config.json from an older install,
# append: --force-register
# lets encrypt http challenge:
#   --tls-mode letsencrypt --tls-domain edge.example.com --tls-email ops@example.com
# lets encrypt dns challenge + wildcard:
#   --tls-mode letsencrypt --tls-domain example.com --tls-wildcard 1 --tls-email ops@example.com \
#   --cloudflare-email ops@example.com --cloudflare-global-key <global_key>
# existing cert files:
#   --tls-mode existing --tls-cert /path/fullchain.pem --tls-key /path/privkey.pem</pre>
                </div>
                <div>
                  <h4 style="margin:0 0 8px;">&#26032;&#22686;</h4>
                  <pre>' . $manualNewInstallCommand . '</pre>
                  <p class="muted">Use this when you want a brand-new node row for this host, or when the host was reused/cloned and you do not want to reuse the old <code>node_id</code>/<code>node_token</code>.</p>
                </div>
              </div>
              <p class="muted">The panel no longer triggers Linux slave install over remote SSH. Install locally on the target host using the command above.</p>
              <p class="muted">If the host was reused or cloned from an image and already has <code>/etc/leikwan-slave/config.json</code>, the installer will reuse that node identity unless you append <code>--force-register</code>.</p>
            </section>';

        $defaultsView = '
            <section class="card">
              <h3>Edge Defaults</h3>
              <p class="muted">These defaults are used when a forward is published to slaves. Recommended now: one shared public port, usually <code>443</code>, and unique WebSocket paths per service.</p>
              <form method="post" action="/admin/edge-defaults">
                <input type="hidden" name="csrf_token" value="' . $csrf . '">
                <div class="form-grid">
                  <div class="field">
                    <label>CDN / SNI Fallback Host</label>
                    <input type="text" name="DEFAULT_CDN_HOST" value="' . $edgeCdnHost . '" placeholder="edge.example.com">
                    <p class="muted">Used when a client does not provide its own display host.</p>
                  </div>
                  <div class="field">
                    <label>Default Shared Public Port</label>
                    <input type="number" name="DEFAULT_EDGE_PUBLIC_PORT" value="' . $this->escape((string) $edgeDefaultPublicPort) . '" placeholder="443" min="1" max="65535">
                    <p class="muted">Used by the shared-port strategy. Leave this at <code>443</code> when you want one TLS port and many paths.</p>
                  </div>
                  <div class="field">
                    <label>Public Port Strategy</label>
                    <select name="EDGE_PORT_STRATEGY">
                      <option value="shared_preferred_port"' . ($edgePortStrategy === 'shared_preferred_port' ? ' selected' : '') . '>shared preferred port, multi-path routing</option>
                      <option value="client_range_then_target"' . ($edgePortStrategy === 'client_range_then_target' ? ' selected' : '') . '>client range first, then target port</option>
                      <option value="target_port_then_client_range"' . ($edgePortStrategy === 'target_port_then_client_range' ? ' selected' : '') . '>target port first, then client range</option>
                    </select>
                    <p class="muted">Recommended: keep the shared-port mode so new forwards default to the same public port and are distinguished by path.</p>
                  </div>
                  <div class="field">
                    <label>Path Strategy</label>
                    <select name="EDGE_PATH_STRATEGY">
                      <option value="random"' . ($edgePathStrategy === 'random' ? ' selected' : '') . '>random token</option>
                      <option value="date-random"' . ($edgePathStrategy === 'date-random' ? ' selected' : '') . '>date + random token</option>
                    </select>
                    <p class="muted">Determines how WebSocket path prefixes are assigned for new forwards.</p>
                  </div>
                </div>
                <div class="actions"><button class="button" type="submit">Save Edge Defaults</button></div>
             </form>
            </section>';

        $detailView = '
            <section class="pg-detail-section pg-node-detail">
              <header><h2>' . ($editing !== null ? 'Edit Slave Node' : 'Select a Slave Node') . '</h2>
              <p>Identity, TLS, runtime, pricing, and advanced settings stay together here instead of crowding the node list.</p></header>
              <div class="pg-detail-body">
              <form method="post" action="/admin/nodes/' . (int) ($editing['id'] ?? 0) . '/manage">
                <input type="hidden" name="csrf_token" value="' . $csrf . '">
                <div class="form-grid">
                  <div class="field"><label>Node Name</label><input type="text" name="name" value="' . $this->escape((string) ($editing['name'] ?? '')) . '"' . ($editing === null ? ' disabled' : '') . '></div>
                  <div class="field"><label>Region</label><input type="text" name="region" value="' . $this->escape((string) ($editing['region'] ?? '')) . '"' . ($editing === null ? ' disabled' : '') . '></div>
                  <div class="field"><label>Public IP</label><input type="text" name="public_ip" value="' . $this->escape((string) ($editing['public_ip'] ?? '')) . '"' . ($editing === null ? ' disabled' : '') . '></div>
                  <div class="field"><label>Private IP</label><input type="text" name="private_ip" value="' . $this->escape((string) ($editing['private_ip'] ?? '')) . '"' . ($editing === null ? ' disabled' : '') . '></div>
                  <div class="field"><label>Slave Group</label><select name="group_key"' . ($editing === null ? ' disabled' : '') . '>' . $groupOptions . '</select></div>
                  <div class="field"><label>Credential Profile</label><select name="credential_profile_id"' . ($editing === null ? ' disabled' : '') . '>' . $editCredentialOptions . '</select></div>
                  <div class="field"><label>SSL Certificate Library</label><select name="ssl_certificate_id"' . ($editing === null ? ' disabled' : '') . '>' . $sslCertificateOptions . '</select><p class="muted">Assign a reusable certificate from <a href="/admin/credentials">Credentials</a>. When selected, the slave receives the certificate PEM from the library on its next poll.</p></div>
                  <div class="field"><label>TLS Cert Path</label><input type="text" name="tls_cert_path" value="' . $this->escape((string) ($editingAgentConfig['tls_cert_path'] ?? '/etc/wstunnel/ssl/server.crt')) . '"' . ($editing === null ? ' disabled' : '') . '></div>
                  <div class="field"><label>TLS Key Path</label><input type="text" name="tls_key_path" value="' . $this->escape((string) ($editingAgentConfig['tls_key_path'] ?? '/etc/wstunnel/ssl/server.key')) . '"' . ($editing === null ? ' disabled' : '') . '></div>
                </div>
                <div class="form-grid" style="margin-top:18px;">
                  <div class="field"><label>TLS Mode</label><select name="tls_mode"' . ($editing === null ? ' disabled' : '') . '><option value="self-signed"' . ($editingTlsMode === 'self-signed' ? ' selected' : '') . '>self-signed</option><option value="letsencrypt"' . ($editingTlsMode === 'letsencrypt' ? ' selected' : '') . '>lets encrypt</option><option value="existing"' . ($editingTlsMode === 'existing' ? ' selected' : '') . '>existing files</option></select><p class="muted">This applies to local node-managed TLS. If an SSL certificate library item is selected above, the slave will effectively run in <code>existing</code> mode with library-provided PEM material.</p></div>
                  <div class="field"><label>TLS Domain</label><input type="text" name="tls_domain" value="' . $this->escape((string) ($editingAgentConfig['tls_domain'] ?? '')) . '"' . ($editing === null ? ' disabled' : '') . '></div>
                  <div class="field"><label>Wildcard</label><select name="tls_wildcard"' . ($editing === null ? ' disabled' : '') . '><option value="0"' . (!empty($editingAgentConfig['tls_wildcard']) ? '' : ' selected') . '>standard certificate</option><option value="1"' . (!empty($editingAgentConfig['tls_wildcard']) ? ' selected' : '') . '>wildcard + apex</option></select></div>
                  <div class="field"><label>TLS Email</label><input type="text" name="tls_email" value="' . $this->escape((string) ($editingAgentConfig['tls_email'] ?? '')) . '"' . ($editing === null ? ' disabled' : '') . '></div>
                  <div class="field"><label>Poll Interval Seconds</label><input type="number" name="poll_interval_seconds" value="' . $this->escape((string) ($editingAgentConfig['poll_interval_seconds'] ?? 5)) . '"' . ($editing === null ? ' disabled' : '') . '></div>
                  <div class="field"><label>Heartbeat Seconds</label><input type="number" name="heartbeat_interval_seconds" value="' . $this->escape((string) ($editingAgentConfig['heartbeat_interval_seconds'] ?? 30)) . '"' . ($editing === null ? ' disabled' : '') . '></div>
                  <div class="field"><label>Usage Report Seconds</label><input type="number" name="usage_interval_seconds" value="' . $this->escape((string) ($editingAgentConfig['usage_interval_seconds'] ?? 60)) . '"' . ($editing === null ? ' disabled' : '') . '></div>
                  <div class="field"><label>Log Level</label><select name="log_level"' . ($editing === null ? ' disabled' : '') . '><option value="DEBUG"' . (((string) ($editingAgentConfig['log_level'] ?? 'INFO')) === 'DEBUG' ? ' selected' : '') . '>DEBUG</option><option value="INFO"' . (((string) ($editingAgentConfig['log_level'] ?? 'INFO')) === 'INFO' ? ' selected' : '') . '>INFO</option><option value="WARNING"' . (((string) ($editingAgentConfig['log_level'] ?? 'INFO')) === 'WARNING' ? ' selected' : '') . '>WARNING</option><option value="ERROR"' . (((string) ($editingAgentConfig['log_level'] ?? 'INFO')) === 'ERROR' ? ' selected' : '') . '>ERROR</option></select></div>
                  <div class="field"><label>Backend Port Range Start</label><input type="number" name="backend_port_range_start" value="' . $this->escape((string) ($editingAgentConfig['backend_port_range_start'] ?? 20000)) . '"' . ($editing === null ? ' disabled' : '') . '></div>
                  <div class="field"><label>Backend Port Range End</label><input type="number" name="backend_port_range_end" value="' . $this->escape((string) ($editingAgentConfig['backend_port_range_end'] ?? 40000)) . '"' . ($editing === null ? ' disabled' : '') . '></div>
                  <div class="field"><label>Mux Listen Hosts</label><input type="text" name="mux_listen_hosts" value="' . $this->escape((string) $editingMuxHosts) . '"' . ($editing === null ? ' disabled' : '') . '><p class="muted">Comma-separated, for example <code>::,0.0.0.0</code>.</p></div>
                </div>
                <div class="form-grid" style="margin-top:18px;">
                  <div class="field"><label>Traffic Price Override / GB</label><input type="number" step="0.0001" min="0" name="traffic_price_per_gb" value="' . $this->escape((string) ($editingNodePrice['price_per_gb'] ?? '')) . '" placeholder="use group default"' . ($editing === null ? ' disabled' : '') . '><p class="muted">Leave empty to use the group default price. Set a value to override.</p></div>
                </div>
                <div class="field" style="margin-top:14px;"><label>Additional Agent Config JSON</label><textarea name="agent_config_json" placeholder="{&quot;backend_host&quot;:&quot;127.0.0.1&quot;}"' . ($editing === null ? ' disabled' : '') . '>' . $this->escape((string) ($editing['agent_config_json'] ?? '')) . '</textarea></div>
                <div class="actions"><button class="button" type="submit"' . ($editing === null ? ' disabled' : '') . '>Save Node Config</button>' . ($editing !== null ? '<a class="button secondary" href="/admin/nodes?view=nodes">Cancel</a>' : '') . '</div>
              </form>
              </div>
            </section>';

        $updatesView = '
            <section class="card">
              <h3>Update Slaves</h3>
              <p class="muted">You can update one slave, multiple selected slaves, or every slave in one group. The panel only writes an update request token; each slave then pulls the latest panel-hosted installer on its next poll.</p>
              <div class="grid two">
                <form method="post" action="/admin/nodes/request-update-group">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <div class="form-grid">
                    <div class="field"><label>Slave Group</label><select name="group_key">' . $batchGroupOptions . '</select></div>
                  </div>
                  <div class="actions"><button class="button secondary" type="submit">Request Update For Group</button></div>
                </form>
                <div class="card" style="margin:0;">
                  <h3>How It Works</h3>
                  <p class="muted">Group update is useful for rolling out a new slave-agent or landing page to one edge region at a time. Selected-node update is better when you only want to touch a few machines first.</p>
                </div>
              </div>
            </section>';

        $nodesView = '
            <section class="pg-sheet" data-mobile-resources="true">
              <div class="pg-sheet-toolbar">
                <div><strong>Registered Nodes</strong><span class="muted"> ' . count($nodes) . ' total</span></div>
                <div class="actions">
                  <button class="button secondary" type="submit" form="selected-node-update-form">Request Update For Selected</button>
                  <button class="button ghost" type="button" onclick="(function(){const boxes=document.querySelectorAll(&quot;.node-update-select&quot;);const all=[...boxes].length>0&&[...boxes].every((b)=>b.checked);boxes.forEach((b)=>b.checked=!all);})()">Toggle All</button>
                </div>
              </div>
              <form id="selected-node-update-form" method="post" action="/admin/nodes/request-update-selected">
                <input type="hidden" name="csrf_token" value="' . $csrf . '">
              </form>
              <div class="pg-sheet-scroll">
                <table class="pg-sheet-table">
                  <thead><tr><th>Node / IP</th><th>Region / Group</th><th>Status</th><th>Load</th><th>Heartbeat</th><th><span class="pg-visually-hidden">Actions</span></th></tr></thead>
                  <tbody>' . $rows . '</tbody>
                </table>
              </div>
              <div class="pg-mobile-resource-list">' . $mobileRows . '</div>
            </section>';

        $viewBody = match ($view) {
            'install' => $installView,
            'defaults' => $defaultsView,
            'updates' => $updatesView,
            'detail' => $detailView,
            default => $nodesView,
        };
        $body = '<div class="pg-view-tabs-shell">' . $tabs . '</div>' . $viewBody;

        return $this->adminPage('Slave Nodes', $body, $viewer, 'nodes');
    }

    public function servicesPage(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        $view = PrecisionGridUi::normalizeView(
            (string) $request->input('view', 'list'),
            ['list', 'create'],
            'list'
        );

        $services = $this->db->fetchAll(
            'SELECT s.id, s.name, s.status, s.whmcs_service_id, s.expire_at, s.bandwidth_limit_mbps, s.traffic_limit_gb, u.email AS user_email, p.name AS plan_name
             FROM services s
             INNER JOIN users u ON u.id = s.user_id
             LEFT JOIN service_plans p ON p.id = s.plan_id
             ORDER BY s.updated_at DESC, s.id DESC'
        );
        $plans = $this->provisioning?->listPlans() ?? [];
        $tenants = $this->db->fetchAll(
            'SELECT id, name, email FROM users WHERE role = :role ORDER BY name ASC, email ASC',
            ['role' => 'tenant']
        );

        $planOptions = '<option value="">Select a plan</option>';
        foreach ($plans as $plan) {
            $planOptions .= '<option value="' . (int) $plan['id'] . '">' . $this->escape((string) $plan['name']) . ' (' . $this->escape((string) $plan['product_code']) . ')</option>';
        }

        $rows = '';
        $mobileRows = '';
        foreach ($services as $service) {
            $serviceName = (string) $service['name'];
            $owner = (string) $service['user_email'];
            $status = (string) $service['status'];
            $planName = (string) ($service['plan_name'] ?? 'No plan');
            $billing = $this->provisioning?->serviceBillingOverview((int) $service['id']) ?? null;
            $billingHtml = $billing !== null
                ? $this->escape((string) ($billing['mode_label'] ?? '')) . '<br><span class="muted">' . $this->escape((string) ($billing['estimated_total_formatted'] ?? '')) . '</span>'
                : '<span class="muted">n/a</span>';
            $limits = (string) ($service['bandwidth_limit_mbps'] ?? '') . ' Mbps / ' . (string) ($service['traffic_limit_gb'] ?? '') . ' GB';
            $commands = '<a href="/admin/tenants?view=list">Open tenant directory</a>';
            $rows .= '<tr>
                <td>' . PrecisionGridUi::resourceCell($serviceName, $owner) . '</td>
                <td>' . $this->escape($planName) . '</td>
                <td>' . PrecisionGridUi::status(ucfirst($status), $status === 'active' ? 'ok' : 'error') . '</td>
                <td>' . $billingHtml . '</td>
                <td>' . PrecisionGridUi::resourceCell((string) $service['expire_at'], $limits) . '</td>
                <td>' . PrecisionGridUi::rowMenu($serviceName . ' actions', $commands) . '</td>
            </tr>';
            $mobileRows .= '<article class="pg-mobile-resource-row"><header>'
                . PrecisionGridUi::resourceCell($serviceName, $owner)
                . PrecisionGridUi::status(ucfirst($status), $status === 'active' ? 'ok' : 'error')
                . '</header><dl><div><dt>Plan</dt><dd>' . $this->escape($planName) . '</dd></div><div><dt>Expires</dt><dd>' . $this->escape((string) $service['expire_at']) . '</dd></div><div><dt>Limits</dt><dd>' . $this->escape($limits) . '</dd></div></dl><footer>'
                . PrecisionGridUi::rowMenu($serviceName . ' actions', $commands) . '</footer></article>';
        }

        $csrf = $this->escape(SessionAuth::csrfToken());
        $tenantOptions = '';
        foreach ($tenants as $tenant) {
            $tenantOptions .= '<option value="' . $this->escape((string) $tenant['email']) . '" data-name="' . $this->escape((string) $tenant['name']) . '"></option>';
        }
        $tenantLookupJson = $this->escape((string) json_encode(array_map(static fn(array $tenant): array => [
            'email' => (string) $tenant['email'],
            'name' => (string) $tenant['name'],
        ], $tenants), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $tabs = PrecisionGridUi::viewTabs([
            ['key' => 'list', 'label' => 'Services', 'href' => '/admin/services?view=list'],
            ['key' => 'create', 'label' => 'Activate Service', 'href' => '/admin/services?view=create'],
        ], $view);
        $createView = '
              <section class="card">
                <h3>Activate Service</h3>
                <form method="post" action="/admin/services">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <datalist id="tenant-email-list">' . $tenantOptions . '</datalist>
                  <div class="form-grid">
                    <div class="field"><label>Tenant Email</label><input type="email" name="user_email" list="tenant-email-list" required></div>
                    <div class="field"><label>Tenant Name</label><input type="text" name="user_name" required></div>
                    <div class="field"><label>Service Name</label><input type="text" name="service_name" required></div>
                    <div class="field"><label>Plan</label><select name="plan_id">' . $planOptions . '</select></div>
                    <div class="field"><label>WHMCS Service ID</label><input type="number" name="whmcs_service_id" placeholder="optional"></div>
                    <div class="field"><label>Expire At</label><input type="text" name="expire_at" value="' . gmdate('Y-m-d H:i:s', strtotime('+30 days')) . '"></div>
                    <div class="field"><label>Listen Port (optional)</label><input type="number" name="listen_port"></div>
                    <div class="field"><label>Public Port (optional)</label><input type="number" name="public_port"></div>
                    <div class="field"><label>Target Host (optional)</label><input type="text" name="target_host" placeholder="10.0.0.5"></div>
                    <div class="field"><label>Target Port (optional)</label><input type="number" name="target_port" placeholder="443"></div>
                  </div>
                  <div class="actions"><button class="button" type="submit">Activate Service</button></div>
                </form>
                <script type="application/json" id="tenant-lookup-data">' . $tenantLookupJson . '</script>
                <script>
                  (function () {
                    const script = document.getElementById("tenant-lookup-data");
                    if (!script) return;
                    const records = JSON.parse(script.textContent || "[]");
                    const emailInput = document.querySelector("input[name=\'user_email\']");
                    const nameInput = document.querySelector("input[name=\'user_name\']");
                    if (!emailInput || !nameInput) return;
                    emailInput.addEventListener("change", function () {
                      const match = records.find((item) => (item.email || "").toLowerCase() === this.value.toLowerCase());
                      if (match && !nameInput.value) {
                        nameInput.value = match.name || "";
                      }
                    });
                  })();
                </script>
              </section>';
        $listView = '
              <section class="pg-sheet" data-mobile-resources="true">
                <div class="pg-sheet-toolbar"><strong>Active Services</strong><span class="muted">' . count($services) . ' total</span></div>
                <div class="pg-sheet-scroll">
                  <table class="pg-sheet-table">
                    <thead><tr><th>Service / Owner</th><th>Plan</th><th>Status</th><th>Billing</th><th>Expiry / Limits</th><th><span class="pg-visually-hidden">Actions</span></th></tr></thead>
                    <tbody>' . $rows . '</tbody>
                  </table>
                </div>
                <div class="pg-mobile-resource-list">' . $mobileRows . '</div>
              </section>';
        $body = $tabs . ($view === 'create' ? $createView : $listView);

        return $this->adminPage('Services', $body, $viewer, 'services');
    }

    public function saveTenant(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $id = (int) $request->input('id', 0);
            $email = strtolower(trim((string) $request->input('email', '')));
            $name = trim((string) $request->input('name', ''));
            $password = (string) $request->input('password', '');
            $status = (string) $request->input('status', 'active');
            if ($email === '' || $name === '' || ($password === '' && $id <= 0)) {
                throw new ApiException('tenant_form_invalid', 422, ['message' => 'Name and email are required, and password is required for new tenants.']);
            }

            $existing = $id > 0
                ? $this->db->fetchOne('SELECT * FROM users WHERE id = :id AND role = :role LIMIT 1', ['id' => $id, 'role' => 'tenant'])
                : $this->db->fetchOne('SELECT * FROM users WHERE email = :email LIMIT 1', ['email' => $email]);
            $passwordHash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null;
            $now = gmdate('Y-m-d H:i:s');
            $emailVerifiedAt = $status === 'active' ? ($existing['email_verified_at'] ?? $now) : null;
            $emailVerificationToken = $status === 'pending_verification'
                ? (string) (($existing['email_verification_token'] ?? '') ?: bin2hex(random_bytes(24)))
                : null;
            $verificationSentAt = $status === 'pending_verification' ? ($existing['verification_sent_at'] ?? null) : null;

            if ($existing === null) {
                $tenantId = $this->db->insert('users', [
                    'name' => $name,
                    'email' => $email,
                    'api_token' => bin2hex(random_bytes(24)),
                    'password_hash' => $passwordHash,
                    'role' => 'tenant',
                    'feature_flags_json' => json_encode($this->extractFeatureFlagsFromRequest($request), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'status' => $status,
                    'email_verified_at' => $emailVerifiedAt,
                    'email_verification_token' => $emailVerificationToken,
                    'verification_sent_at' => $verificationSentAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                SessionAuth::flash('success', 'Tenant created.');
            } else {
                $tenantId = (int) $existing['id'];
                $this->db->execute(
                    'UPDATE users
                     SET name = :name,
                         email = :email,
                         password_hash = :password_hash,
                         role = :role,
                         feature_flags_json = :feature_flags_json,
                         status = :status,
                         email_verified_at = :email_verified_at,
                         email_verification_token = :email_verification_token,
                         verification_sent_at = :verification_sent_at,
                         updated_at = :updated_at
                     WHERE id = :id',
                    [
                        'id' => $existing['id'],
                        'name' => $name,
                        'email' => $email,
                        'password_hash' => $passwordHash ?? $existing['password_hash'],
                        'role' => 'tenant',
                        'feature_flags_json' => json_encode($this->extractFeatureFlagsFromRequest($request), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'status' => $status,
                        'email_verified_at' => $emailVerifiedAt,
                        'email_verification_token' => $emailVerificationToken,
                        'verification_sent_at' => $verificationSentAt,
                        'updated_at' => $now,
                    ]
                );
                SessionAuth::flash('success', 'Tenant updated.');
            }

            $this->provisioning?->ensureDefaultClientForUser($tenantId);
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to save tenant: ' . $throwable->getMessage());
        }

        return Response::redirect('/admin/registration');
    }

    public function deleteTenant(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $tenantId = $this->idParam($params);
            $tenant = $this->db->fetchOne('SELECT * FROM users WHERE id = :id AND role = :role LIMIT 1', ['id' => $tenantId, 'role' => 'tenant']);
            if ($tenant === null) {
                throw new ApiException('tenant_not_found', 404);
            }
            $serviceCount = (int) (($this->db->fetchOne('SELECT COUNT(*) AS total FROM services WHERE user_id = :user_id', ['user_id' => $tenantId])['total'] ?? 0));
            if ($serviceCount > 0) {
                throw new ApiException('tenant_delete_blocked', 409, ['message' => 'Tenant still has active services.']);
            }

            $this->db->execute('DELETE FROM users WHERE id = :id', ['id' => $tenantId]);
            SessionAuth::flash('success', 'Tenant deleted.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to delete tenant: ' . $throwable->getMessage());
        }

        return Response::redirect('/admin/registration');
    }

    public function saveTenantRegistrationSettings(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $this->registrationService()->saveRegistrationSettings((string) $request->input('require_invite_code', '') === '1');
            SessionAuth::flash('success', 'Tenant registration settings updated.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to update registration settings: ' . $throwable->getMessage());
        }

        return Response::redirect('/admin/registration');
    }

    public function createTenantRegistrationCode(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $code = $this->registrationService()->createInviteCode([
                'label' => $request->input('label'),
                'max_uses' => $request->input('max_uses', '1'),
                'expires_at' => $request->input('expires_at'),
            ], (int) $viewer['id']);
            SessionAuth::flash('success', 'Registration code generated: ' . (string) ($code['code'] ?? ''));
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to generate registration code: ' . $throwable->getMessage());
        }

        return Response::redirect('/admin/tenants');
    }

    public function toggleTenantRegistrationCode(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $this->registrationService()->toggleInviteCode($this->idParam($params));
            SessionAuth::flash('success', 'Registration code updated.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to update registration code: ' . $throwable->getMessage());
        }

        return Response::redirect('/admin/tenants');
    }

    public function savePlan(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $this->provisioning?->savePlan([
                'id' => $request->input('id', 0),
                'name' => $request->input('name'),
                'product_code' => $request->input('product_code'),
                'description' => $request->input('description'),
                'default_bandwidth_limit_mbps' => $request->input('default_bandwidth_limit_mbps'),
                'default_traffic_limit_gb' => $request->input('default_traffic_limit_gb'),
                'billing_mode' => $request->input('billing_mode', 'monthly_bundle'),
                'billing_currency' => $request->input('billing_currency', 'CNY'),
                'monthly_base_fee' => $request->input('monthly_base_fee', '0'),
                'monthly_included_traffic_gb' => $request->input('monthly_included_traffic_gb', '0'),
                'traffic_price_per_gb' => $request->input('traffic_price_per_gb', '0'),
                'traffic_reset_price' => $request->input('traffic_reset_price', '0'),
                'bandwidth_step_mbps' => $request->input('bandwidth_step_mbps', '0'),
                'bandwidth_step_price' => $request->input('bandwidth_step_price', '0'),
                'included_client_count' => $request->input('included_client_count', '0'),
                'extra_client_price' => $request->input('extra_client_price', '0'),
                'max_client_profiles' => $request->input('max_client_profiles', '1'),
                'max_forward_rule_quantity' => $request->input('max_forward_rule_quantity', '64'),
                'default_client_online_device_limit' => $request->input('default_client_online_device_limit', '1'),
                'is_active' => $request->input('is_active', '1'),
                'is_tenant_visible' => $request->input('is_tenant_visible', '1'),
                'geo_lock_enabled' => $request->input('geo_lock_enabled', '0'),
                'allowed_forward_route_ids' => $request->body()['allowed_forward_route_ids'] ?? [],
                'allowed_node_group_ids' => $request->body()['allowed_node_group_ids'] ?? [],
                'allowed_client_group_ids' => $request->body()['allowed_client_group_ids'] ?? [],
                'allowed_sidecar_point_ids' => $request->body()['allowed_sidecar_point_ids'] ?? [],
                'feature_flags' => $this->extractFeatureFlagsFromRequest($request),
            ]);
            SessionAuth::flash('success', 'Plan saved.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to save plan: ' . $throwable->getMessage());
        }

        return Response::redirect('/admin/plans');
    }

    public function deletePlan(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $planId = $this->idParam($params);
            $plan = $this->db->fetchOne('SELECT * FROM service_plans WHERE id = :id LIMIT 1', ['id' => $planId]);
            if ($plan === null) {
                throw new ApiException('plan_not_found', 404);
            }
            $serviceCount = (int) (($this->db->fetchOne('SELECT COUNT(*) AS total FROM services WHERE plan_id = :plan_id', ['plan_id' => $planId])['total'] ?? 0));
            if ($serviceCount > 0) {
                throw new ApiException('plan_delete_blocked', 409, ['message' => 'Plan is still used by services.']);
            }

            $this->db->execute('DELETE FROM service_plan_node_groups WHERE plan_id = :plan_id', ['plan_id' => $planId]);
            $this->db->execute('DELETE FROM service_plan_client_groups WHERE plan_id = :plan_id', ['plan_id' => $planId]);
            $this->db->execute('DELETE FROM service_plans WHERE id = :id', ['id' => $planId]);
            SessionAuth::flash('success', 'Plan deleted.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to delete plan: ' . $throwable->getMessage());
        }

        return Response::redirect('/admin/plans');
    }

    public function saveClientGroup(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $this->provisioning?->saveClientGroup([
                'group_key' => $request->input('group_key'),
                'name' => $request->input('name'),
                'description' => $request->input('description'),
            ]);
            SessionAuth::flash('success', 'Client group saved.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to save client group: ' . $throwable->getMessage());
        }

        return Response::redirect('/admin/client-groups');
    }

    public function saveClient(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $this->provisioning?->saveClient([
                'id' => $request->input('id'),
                'owner_user_id' => $request->input('owner_user_id'),
                'group_id' => $request->input('group_id'),
                'name' => $request->input('name'),
                'display_host' => $request->input('display_host'),
                'bind_ip' => $request->input('bind_ip'),
                'nic_name' => $request->input('nic_name'),
                'port_range_start' => $request->input('port_range_start'),
                'port_range_end' => $request->input('port_range_end'),
                'traffic_multiplier' => $request->input('traffic_multiplier', '1.00'),
                'allow_custom_endpoints' => $request->input('allow_custom_endpoints'),
                'force_slave_mode' => $request->input('force_slave_mode'),
                'allow_forward_chain' => $request->input('allow_forward_chain'),
                'allow_latency_test' => $request->input('allow_latency_test'),
                'allow_inbound_proxy' => $request->input('allow_inbound_proxy'),
                'default_endpoint_mode' => $request->input('default_endpoint_mode', 'slave_group'),
                'default_node_group_key' => $request->input('default_node_group_key'),
                'default_forward_chain_id' => $request->input('default_forward_chain_id'),
                'max_ip_count' => $request->input('max_ip_count'),
                'max_conn_per_ip' => $request->input('max_conn_per_ip'),
                'protocol_filter_json' => $request->input('protocol_filter_json'),
                'custom_config_json' => $request->input('custom_config_json'),
                'agent_config_json' => $request->input('agent_config_json'),
                'speed_limit_mbps' => $request->input('speed_limit_mbps'),
                'tags_json' => $request->input('tags_json'),
                'status' => $request->input('status', 'active'),
            ]);
            SessionAuth::flash('success', 'Client saved.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to save client: ' . $throwable->getMessage());
        }

        return Response::redirect('/admin/clients');
    }

    public function deleteClient(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $clientId = $this->idParam($params);
            $result = $this->provisioning?->deleteClient($clientId) ?? ['detached_disabled_forwards' => 0];
            SessionAuth::flash(
                'success',
                'Client deleted from database.'
                . ((int) ($result['detached_disabled_forwards'] ?? 0) > 0
                    ? ' Detached ' . (int) $result['detached_disabled_forwards'] . ' disabled forward reference(s).'
                    : '')
            );
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to delete client: ' . $this->displayThrowableMessage($throwable));
        }

        return Response::redirect('/admin/clients');
    }

    public function republishForClient(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $clientId = $this->idParam($params);
            $result = $this->provisioning?->republishForwardsForClientWithResult($clientId) ?? ['republished' => 0, 'errors' => ['provisioning unavailable']];
            $republished = (int) ($result['republished'] ?? 0);
            $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
            if ($errors !== []) {
                SessionAuth::flash('error', 'Republish finished with issues: ' . implode('; ', array_map('strval', $errors)));
            } else {
                SessionAuth::flash('success', 'Republished ' . $republished . ' forward(s) for the client.');
            }
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to republish client forwards: ' . $this->displayThrowableMessage($throwable));
        }

        return Response::redirect('/admin/clients');
    }

    public function requestClientDiagnostics(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $clientId = $this->idParam($params);
            $client = $this->db->fetchOne('SELECT * FROM clients WHERE id = :id LIMIT 1', ['id' => $clientId]);
            if ($client === null) {
                throw new ApiException('client_not_found', 404);
            }
            $token = bin2hex(random_bytes(24));
            $this->db->execute(
                'UPDATE clients
                 SET last_diag_requested_at = :requested_at,
                     last_diag_request_token = :request_token,
                     last_diag_status = :status,
                     last_diag_error = NULL,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    'id' => $clientId,
                    'requested_at' => gmdate('Y-m-d H:i:s'),
                    'request_token' => $token,
                    'status' => 'requested',
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]
            );
            SessionAuth::flash('success', 'Diagnostics requested. Wait for the client-agent to sync and refresh this page.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to request diagnostics: ' . $throwable->getMessage());
        }

        return Response::redirect('/admin/clients');
    }

    public function requestClientUpdate(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $clientId = $this->idParam($params);
            $client = $this->db->fetchOne('SELECT * FROM clients WHERE id = :id LIMIT 1', ['id' => $clientId]);
            if ($client === null) {
                throw new ApiException('client_not_found', 404);
            }
            $token = bin2hex(random_bytes(24));
            $this->db->execute(
                'UPDATE clients
                 SET agent_update_request_token = :request_token,
                     agent_update_requested_at = :requested_at,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    'id' => $clientId,
                    'request_token' => $token,
                    'requested_at' => gmdate('Y-m-d H:i:s'),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]
            );
            SessionAuth::flash('success', 'Client update requested. The installed client-agent will pull the latest panel-hosted version on its next sync.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to request client update: ' . $throwable->getMessage());
        }

        return Response::redirect('/admin/clients');
    }

    public function saveNodeManagement(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $nodeId = $this->idParam($params);
            $node = $this->db->fetchOne('SELECT * FROM nodes WHERE id = :id LIMIT 1', ['id' => $nodeId]);
            if ($node === null) {
                throw new ApiException('node_not_found', 404);
            }

            $existingAgentConfig = $this->decodeJsonList($node['agent_config_json'] ?? null);
            $extraAgentConfigJson = trim((string) $request->input('agent_config_json', ''));
            $extraAgentConfig = [];
            if ($extraAgentConfigJson !== '') {
                $decoded = json_decode($extraAgentConfigJson, true);
                if (!is_array($decoded)) {
                    throw new ApiException('invalid_node_agent_config_json', 422, ['message' => json_last_error_msg()]);
                }
                $extraAgentConfig = $decoded;
            }

            $groupKey = trim((string) $request->input('group_key', ''));
            $groupId = null;
            if ($groupKey !== '') {
                $group = $this->db->fetchOne('SELECT id FROM node_groups WHERE group_key = :group_key LIMIT 1', ['group_key' => $groupKey]);
                if ($group === null) {
                    throw new ApiException('node_group_not_found', 404, ['group_key' => $groupKey]);
                }
                $groupId = $group['id'];
            }
            $credentialProfileId = (int) $request->input('credential_profile_id', 0);
            if ($credentialProfileId > 0) {
                $credentialProfile = $this->db->fetchOne('SELECT * FROM credential_profiles WHERE id = :id LIMIT 1', ['id' => $credentialProfileId]);
                if ($credentialProfile === null) {
                    throw new ApiException('credential_profile_not_found', 404, ['id' => $credentialProfileId]);
                }
            }

            $tlsMode = trim((string) $request->input('tls_mode', 'self-signed'));
            if (!in_array($tlsMode, ['self-signed', 'letsencrypt', 'existing'], true)) {
                throw new ApiException('invalid_node_agent_tls_mode', 422, ['mode' => $tlsMode]);
            }
            $tlsWildcard = (int) $request->input('tls_wildcard', 0) === 1;
            $sslCertificateId = (int) $request->input('ssl_certificate_id', 0);
            if ($sslCertificateId > 0) {
                $sslCertificate = $this->db->fetchOne('SELECT * FROM ssl_certificates WHERE id = :id LIMIT 1', ['id' => $sslCertificateId]);
                if ($sslCertificate === null) {
                    throw new ApiException('ssl_certificate_not_found', 404, ['id' => $sslCertificateId]);
                }
                $tlsMode = 'existing';
            } elseif ($tlsMode === 'letsencrypt' && $tlsWildcard && $credentialProfileId <= 0) {
                throw new ApiException('credential_profile_required_for_wildcard', 422, ['message' => 'wildcard letsencrypt requires a DNS credential profile']);
            }

            $muxListenHostsRaw = trim((string) $request->input('mux_listen_hosts', ''));
            $muxListenHosts = array_values(array_filter(array_map('trim', explode(',', $muxListenHostsRaw)), static fn(string $value): bool => $value !== ''));

            $preservedRuntimeConfig = [];
            foreach ([
                'supports_udp',
                'supports_filtering',
                'supports_quic',
                'supports_bond_hybrid',
                'supports_sidecar_quic',
                'accept_panel_republish',
                'bond_protocols',
                'hybrid_modes',
                'leigod_line_health',
                'leigod_forward_selections',
            ] as $runtimeKey) {
                if (array_key_exists($runtimeKey, $existingAgentConfig)) {
                    $preservedRuntimeConfig[$runtimeKey] = $existingAgentConfig[$runtimeKey];
                }
            }

            $agentConfig = array_merge(
                $preservedRuntimeConfig,
                $extraAgentConfig,
                [
                    'tls_mode' => $tlsMode,
                    'tls_domain' => ($value = trim((string) $request->input('tls_domain', ''))) !== '' ? $value : null,
                    'tls_wildcard' => $tlsWildcard ? 1 : 0,
                    'tls_email' => ($value = trim((string) $request->input('tls_email', ''))) !== '' ? $value : null,
                    'tls_cert_path' => ($value = trim((string) $request->input('tls_cert_path', ''))) !== '' ? $value : null,
                    'tls_key_path' => ($value = trim((string) $request->input('tls_key_path', ''))) !== '' ? $value : null,
                    'poll_interval_seconds' => ($value = trim((string) $request->input('poll_interval_seconds', ''))) !== '' ? (int) $value : null,
                    'heartbeat_interval_seconds' => ($value = trim((string) $request->input('heartbeat_interval_seconds', ''))) !== '' ? (int) $value : null,
                    'usage_interval_seconds' => ($value = trim((string) $request->input('usage_interval_seconds', ''))) !== '' ? (int) $value : null,
                    'log_level' => ($value = trim((string) $request->input('log_level', ''))) !== '' ? strtoupper($value) : null,
                    'backend_port_range_start' => ($value = trim((string) $request->input('backend_port_range_start', ''))) !== '' ? (int) $value : null,
                    'backend_port_range_end' => ($value = trim((string) $request->input('backend_port_range_end', ''))) !== '' ? (int) $value : null,
                    'mux_listen_hosts' => $muxListenHosts !== [] ? $muxListenHosts : null,
                ]
            );
            if ($sslCertificateId > 0) {
                $agentConfig['panel_tls_delivery_mode'] = null;
                $agentConfig['tls_cert_pem'] = null;
                $agentConfig['tls_key_pem'] = null;
                $agentConfig['panel_tls_sync_domain'] = null;
                $agentConfig['panel_tls_cert_source_path'] = null;
                $agentConfig['panel_tls_key_source_path'] = null;
            }
            $agentConfigJson = json_encode($agentConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($agentConfigJson)) {
                throw new ApiException('invalid_node_agent_config_json', 422, ['message' => 'unable to encode node config']);
            }

            $this->db->execute(
                'UPDATE nodes
                 SET group_id = :group_id,
                     credential_profile_id = :credential_profile_id,
                     ssl_certificate_id = :ssl_certificate_id,
                     name = :name,
                     region = :region,
                     public_ip = :public_ip,
                     private_ip = :private_ip,
                     agent_config_json = :agent_config_json,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    'id' => $nodeId,
                    'group_id' => $groupId,
                    'credential_profile_id' => $credentialProfileId > 0 ? $credentialProfileId : null,
                    'ssl_certificate_id' => $sslCertificateId > 0 ? $sslCertificateId : null,
                    'name' => trim((string) $request->input('name', (string) ($node['name'] ?? ''))),
                    'region' => trim((string) $request->input('region', (string) ($node['region'] ?? 'unknown'))),
                    'public_ip' => trim((string) $request->input('public_ip', (string) ($node['public_ip'] ?? ''))),
                    'private_ip' => trim((string) $request->input('private_ip', (string) ($node['private_ip'] ?? ''))),
                    'agent_config_json' => $agentConfigJson,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]
            );
            $trafficPrice = trim((string) $request->input('traffic_price_per_gb', ''));
            if ($trafficPrice !== '' && $this->provisioning !== null) {
                $this->provisioning->upsertTrafficPrice('slave', $nodeId, null, $trafficPrice);
            }
            SessionAuth::flash('success', 'Node management config saved. The slave agent will refresh to the latest full panel-managed config on its next poll.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to save node management config: ' . $throwable->getMessage());
        }

        return Response::redirect('/admin/nodes');
    }

    public function requestNodeUpdate(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $nodeId = $this->idParam($params);
            $node = $this->db->fetchOne('SELECT * FROM nodes WHERE id = :id LIMIT 1', ['id' => $nodeId]);
            if ($node === null) {
                throw new ApiException('node_not_found', 404);
            }
            $token = bin2hex(random_bytes(24));
            $this->db->execute(
                'UPDATE nodes
                 SET agent_update_request_token = :request_token,
                     agent_update_requested_at = :requested_at,
                     updated_at = :updated_at
                 WHERE id = :id',
                [
                    'id' => $nodeId,
                    'request_token' => $token,
                    'requested_at' => gmdate('Y-m-d H:i:s'),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]
            );
            SessionAuth::flash('success', 'Slave update requested. The installed slave agent will pull the latest panel-hosted version on its next poll.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to request slave update: ' . $throwable->getMessage());
        }

        return Response::redirect('/admin/nodes');
    }

    public function deleteNode(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $nodeId = $this->idParam($params);
            $result = $this->deleteNodeAndReferences($nodeId);
            SessionAuth::flash(
                'success',
                'Slave node deleted. Removed '
                . $result['service_endpoints'] . ' endpoint(s), '
                . $result['provision_tasks'] . ' task(s), '
                . $result['node_heartbeats'] . ' heartbeat row(s), and '
                . $result['traffic_usage_hourly'] . ' traffic row(s). '
                . 'Clients stop using this slave after their next config sync.'
            );
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to delete slave node: ' . $this->displayThrowableMessage($throwable));
        }

        return Response::redirect('/admin/nodes');
    }

    public function requestSelectedNodeUpdates(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $rawIds = $request->body()['node_ids'] ?? [];
            $nodeIds = [];
            foreach ((array) $rawIds as $rawId) {
                $nodeId = (int) $rawId;
                if ($nodeId > 0) {
                    $nodeIds[] = $nodeId;
                }
            }
            $nodeIds = array_values(array_unique($nodeIds));
            if ($nodeIds === []) {
                throw new ApiException('node_selection_required', 422, ['message' => 'select at least one slave node']);
            }

            $updatedCount = $this->requestNodeUpdatesByIds($nodeIds);
            SessionAuth::flash('success', 'Slave update requested for ' . $updatedCount . ' selected node(s).');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to request selected slave updates: ' . $throwable->getMessage());
        }

        return Response::redirect('/admin/nodes');
    }

    public function requestNodeGroupUpdate(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $groupKey = trim((string) $request->input('group_key', ''));
            if ($groupKey === '') {
                throw new ApiException('node_group_required', 422, ['message' => 'select one slave group']);
            }

            $group = $this->db->fetchOne('SELECT id, name FROM node_groups WHERE group_key = :group_key LIMIT 1', ['group_key' => $groupKey]);
            if ($group === null) {
                throw new ApiException('node_group_not_found', 404, ['group_key' => $groupKey]);
            }

            $nodes = $this->db->fetchAll('SELECT id FROM nodes WHERE group_id = :group_id ORDER BY id ASC', ['group_id' => $group['id']]);
            $nodeIds = array_values(array_map(static fn(array $node): int => (int) $node['id'], $nodes));
            if ($nodeIds === []) {
                throw new ApiException('node_group_empty', 422, ['message' => 'selected slave group has no registered nodes']);
            }

            $updatedCount = $this->requestNodeUpdatesByIds($nodeIds);
            SessionAuth::flash('success', 'Slave update requested for group ' . (string) $group['name'] . ' (' . $updatedCount . ' node(s)).');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to request slave group update: ' . $throwable->getMessage());
        }

        return Response::redirect('/admin/nodes');
    }

    public function saveChain(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $this->provisioning?->saveForwardChain([
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'is_active' => $request->input('is_active', '1'),
                'node_group_ids' => $request->body()['node_group_ids'] ?? [],
            ]);
            SessionAuth::flash('success', 'Forward chain saved.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to save forward chain: ' . $throwable->getMessage());
        }

        return Response::redirect('/admin/chains');
    }

    public function saveNodeGroup(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $result = $this->provisioning?->saveNodeGroup([
                'group_key' => $request->input('group_key'),
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'ingress_cdn_host' => $request->input('ingress_cdn_host'),
                'ingress_sni_host' => $request->input('ingress_sni_host'),
                'ingress_path_strategy' => $request->input('ingress_path_strategy', 'random'),
                'ingress_port_strategy' => $request->input('ingress_port_strategy', 'shared_preferred_port'),
                'ingress_preferred_public_port' => $request->input('ingress_preferred_public_port'),
            ]);
            $savedGroupId = (int) ($result['id'] ?? 0);
            $trafficPrice = trim((string) $request->input('traffic_price_per_gb', '0'));
            if ($savedGroupId > 0 && $this->provisioning !== null) {
                $this->provisioning->upsertTrafficPrice('slave', null, $savedGroupId, $trafficPrice);
            }
            SessionAuth::flash('success', 'Node group saved.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to save node group: ' . $throwable->getMessage());
        }

        return Response::redirect('/admin/node-groups');
    }

    public function createService(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            if ($this->provisioning === null) {
                throw new ApiException('provisioning_unavailable', 500);
            }

            $planId = (int) $request->input('plan_id', 0);
            $plan = $planId > 0 ? $this->db->fetchOne('SELECT * FROM service_plans WHERE id = :id LIMIT 1', ['id' => $planId]) : null;
            if ($planId > 0 && $plan === null) {
                throw new ApiException('plan_not_found', 404);
            }

            $whmcsId = trim((string) $request->input('whmcs_service_id', ''));
            if ($whmcsId === '') {
                $whmcsId = (string) (time() . random_int(100, 999));
            }

            $payload = [
                'whmcs_service_id' => $whmcsId,
                'user_email' => (string) $request->input('user_email', ''),
                'user_name' => (string) $request->input('user_name', ''),
                'product_code' => (string) ($plan['product_code'] ?? 'manual-plan'),
                'service_name' => (string) $request->input('service_name', ''),
                'expire_at' => (string) $request->input('expire_at', gmdate('Y-m-d H:i:s', strtotime('+30 days'))),
                'bandwidth_limit_mbps' => (int) ($plan['default_bandwidth_limit_mbps'] ?? 100),
                'traffic_limit_gb' => (int) ($plan['default_traffic_limit_gb'] ?? 1000),
                'status' => 'active',
            ];

            $listenPort = (int) $request->input('listen_port', 0);
            $targetHost = trim((string) $request->input('target_host', ''));
            $targetPort = (int) $request->input('target_port', 0);
            if ($listenPort > 0 && $targetHost !== '' && $targetPort > 0) {
                $payload['forwards'] = [[
                    'listen_port' => $listenPort,
                    'public_port' => (int) $request->input('public_port', $listenPort),
                    'target_host' => $targetHost,
                    'target_port' => $targetPort,
                ]];
            }

            $service = $this->provisioning->createOrUpdateServiceFromWhmcs($payload);
            SessionAuth::flash('success', 'Service activated: ' . (string) $service['name']);
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to activate service: ' . $throwable->getMessage());
        }

        return Response::redirect('/admin/services');
    }

    public function saveEdgeDefaults(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $cdnHost = trim((string) $request->input('DEFAULT_CDN_HOST', ''));
            $defaultPublicPort = max(1, (int) $request->input('DEFAULT_EDGE_PUBLIC_PORT', 443));
            $portStrategy = trim((string) $request->input('EDGE_PORT_STRATEGY', 'shared_preferred_port'));
            $pathStrategy = trim((string) $request->input('EDGE_PATH_STRATEGY', 'random'));

            if (!in_array($portStrategy, ['shared_preferred_port', 'client_range_then_target', 'target_port_then_client_range'], true)) {
                throw new ApiException('invalid_edge_port_strategy', 422, ['strategy' => $portStrategy]);
            }
            if (!in_array($pathStrategy, ['random', 'date-random'], true)) {
                throw new ApiException('invalid_edge_path_strategy', 422, ['strategy' => $pathStrategy]);
            }

            $this->writeEnvValues([
                'DEFAULT_CDN_HOST' => $cdnHost,
                'DEFAULT_EDGE_PUBLIC_PORT' => (string) $defaultPublicPort,
                'EDGE_PORT_STRATEGY' => $portStrategy,
                'EDGE_PATH_STRATEGY' => $pathStrategy,
            ]);

            SessionAuth::flash('success', 'Edge defaults saved. New forwards will use the updated automatic assignment rules.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to save edge defaults: ' . $throwable->getMessage());
        }

        return Response::redirect('/admin/nodes');
    }

    public function accessControlPage(Request $request, array $params): Response
    {
        // The service reads access_blacklist_rules, user_geo_lock_profiles,
        // access_policy_events, and account_access_bans without exposing raw
        // provider payloads to the Panel.
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        $entityFilter = trim((string) $request->input('entity_type', ''));
        $activeFilter = $request->input('active_only', null);
        $activeOnly = $activeFilter === null || $activeFilter === '' ? null : (bool) $activeFilter;
        $rules = $this->accessPolicy->listBlacklistRules([
            'entity_type' => $entityFilter,
            'active_only' => $activeOnly,
        ]);
        $profiles = $this->accessPolicy->adminProfiles(100);
        $violations = $this->accessPolicy->adminViolations(100);
        $blocks = $this->accessPolicy->adminDeviceBlocks(200);
        $bans = $this->accessPolicy->adminAccountBans(100);
        $csrf = $this->escape(SessionAuth::csrfToken());

        $ruleRows = '';
        foreach ($rules as $rule) {
            $ruleId = (int) ($rule['id'] ?? 0);
            $isActive = (int) ($rule['is_active'] ?? 0) === 1;
            $ruleRows .= '<tr><td>' . $ruleId . '</td>'
                . '<td>' . $this->escape((string) ($rule['entity_type'] ?? '')) . '</td>'
                . '<td><code>' . $this->escape((string) ($rule['display_value'] ?? $rule['canonical_value'] ?? '')) . '</code></td>'
                . '<td>' . $this->escape((string) ($rule['reason_code'] ?? '')) . '</td>'
                . '<td><span class="badge ' . ($isActive ? 'danger' : 'muted') . '">' . ($isActive ? 'active' : 'disabled') . '</span></td>'
                . '<td><form class="inline" method="post" action="/admin/access-control/rules/' . $ruleId . '/toggle">'
                . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                . '<input type="hidden" name="active" value="' . ($isActive ? '0' : '1') . '">'
                . '<button class="button ghost" type="submit">' . ($isActive ? 'Disable' : 'Enable') . '</button></form></td></tr>';
        }
        if ($ruleRows === '') {
            $ruleRows = '<tr><td colspan="6" class="muted">No blacklist rules configured.</td></tr>';
        }

        $profileRows = '';
        foreach ($profiles as $profile) {
            $profileRows .= '<tr><td>' . $this->escape((string) ($profile['user_name'] ?? $profile['user_email'] ?? $profile['user_id'] ?? '')) . '</td>'
                . '<td>' . $this->escape((string) ($profile['user_email'] ?? '')) . '</td>'
                . '<td>' . $this->escape((string) ($profile['state'] ?? 'unbound')) . '</td>'
                . '<td>' . $this->escape((string) ($profile['main_province_code'] ?? '')) . '</td>'
                . '<td>' . $this->escape((string) ($profile['previous_province_code'] ?? '')) . '</td>'
                . '<td>' . $this->escape((string) ($profile['last_verified_at'] ?? '')) . '</td></tr>';
        }
        if ($profileRows === '') {
            $profileRows = '<tr><td colspan="6" class="muted">No geographic profiles yet.</td></tr>';
        }

        $violationRows = '';
        foreach ($violations as $event) {
            $violationRows .= '<tr><td>' . $this->escape((string) ($event['created_at'] ?? '')) . '</td>'
                . '<td>' . $this->escape((string) ($event['user_name'] ?? $event['user_email'] ?? $event['user_id'] ?? '')) . '</td>'
                . '<td><code>' . $this->escape((string) ($event['device_uuid'] ?? '')) . '</code></td>'
                . '<td>' . $this->escape((string) ($event['reason_code'] ?? '')) . '</td>'
                . '<td>' . $this->escape((string) ($event['attempt_id'] ?? '')) . '</td></tr>';
        }
        if ($violationRows === '') {
            $violationRows = '<tr><td colspan="5" class="muted">No geographic violations recorded.</td></tr>';
        }

        $blockRows = '';
        foreach ($blocks as $block) {
            $clientId = (int) ($block['client_id'] ?? 0);
            $deviceUuid = (string) ($block['device_id'] ?? '');
            $blockRows .= '<tr><td>' . $this->escape((string) ($block['user_name'] ?? $block['owner_user_id'] ?? '')) . '</td>'
                . '<td>' . $this->escape((string) ($block['client_name'] ?? $clientId)) . '</td>'
                . '<td><code>' . $this->escape($deviceUuid) . '</code></td>'
                . '<td>' . $this->escape((string) ($block['state'] ?? 'active')) . '</td>'
                . '<td>' . $this->escape((string) ($block['block_origin'] ?? '')) . '</td>'
                . '<td>' . $this->escape((string) ($block['blocked_until'] ?? '')) . '</td>'
                . '<td><form class="inline" method="post" action="/admin/access-control/devices/release">'
                . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                . '<input type="hidden" name="client_id" value="' . $clientId . '">'
                . '<input type="hidden" name="device_uuid" value="' . $this->escape($deviceUuid) . '">'
                . '<button class="button ghost" type="submit">Release</button></form></td></tr>';
        }
        if ($blockRows === '') {
            $blockRows = '<tr><td colspan="7" class="muted">No device blocks found.</td></tr>';
        }

        $banRows = '';
        foreach ($bans as $ban) {
            $userId = (int) ($ban['user_id'] ?? 0);
            $released = ($ban['released_at'] ?? null) !== null;
            $banRows .= '<tr><td>' . $this->escape((string) ($ban['user_name'] ?? $ban['user_email'] ?? $userId)) . '</td>'
                . '<td>' . $this->escape((string) ($ban['reason_code'] ?? '')) . '</td>'
                . '<td>' . $this->escape((string) ($ban['banned_at'] ?? '')) . '</td>'
                . '<td>' . ($released ? '<span class="badge success">released</span>' : '<span class="badge danger">active</span>') . '</td>'
                . '<td>' . ($released ? '' : '<form class="inline" method="post" action="/admin/access-control/accounts/release">'
                    . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                    . '<input type="hidden" name="user_id" value="' . $userId . '">'
                    . '<button class="button ghost" type="submit">Release Account</button></form>') . '</td></tr>';
        }
        if ($banRows === '') {
            $banRows = '<tr><td colspan="5" class="muted">No account bans found.</td></tr>';
        }

        $body = '<div class="hero"><div><h2>Access Control</h2><p class="muted">Manage global deny rules, geographic profiles, policy events, device blocks, and account bans.</p></div></div>'
            . '<section class="card"><h3>Blacklist Rules</h3><form method="post" action="/admin/access-control/rules"><input type="hidden" name="csrf_token" value="' . $csrf . '"><div class="form-grid">'
            . '<div class="field"><label>Entity</label><select name="entity_type"><option value="device_uuid">Device UUID</option><option value="ip">Exact IP</option><option value="cidr">IP subnet</option><option value="province">Mainland province</option><option value="city">Prefecture city</option><option value="country">Country / region</option></select></div>'
            . '<div class="field"><label>Value</label><input name="value" required maxlength="255" placeholder="UUID, IP, CIDR, province, city, or country"></div>'
            . '<div class="field"><label>Province (city rules)</label><input name="province" maxlength="64"></div>'
            . '<div class="field"><label>Reason code</label><input name="reason_code" value="admin_blacklist" maxlength="64"></div>'
            . '</div><div class="actions"><button class="button" type="submit">Add Rule</button></div></form><div class="table-wrap"><table><thead><tr><th>ID</th><th>Entity</th><th>Value</th><th>Reason</th><th>Status</th><th>Action</th></tr></thead><tbody>' . $ruleRows . '</tbody></table></div></section>'
            . '<section class="card"><h3>Geo Profiles</h3><div class="table-wrap"><table><thead><tr><th>User</th><th>Email</th><th>State</th><th>Main Province</th><th>Previous</th><th>Last Verified</th></tr></thead><tbody>' . $profileRows . '</tbody></table></div></section>'
            . '<section class="card"><h3>Violations</h3><div class="table-wrap"><table><thead><tr><th>Time</th><th>User</th><th>Device UUID</th><th>Reason</th><th>Attempt</th></tr></thead><tbody>' . $violationRows . '</tbody></table></div></section>'
            . '<section class="card"><h3>Device Blocks</h3><div class="table-wrap"><table><thead><tr><th>User</th><th>Client</th><th>Device UUID</th><th>State</th><th>Origin</th><th>Blocked Until</th><th>Action</th></tr></thead><tbody>' . $blockRows . '</tbody></table></div></section>'
            . '<section class="card"><h3>Account Bans</h3><div class="table-wrap"><table><thead><tr><th>User</th><th>Reason</th><th>Banned At</th><th>Status</th><th>Action</th></tr></thead><tbody>' . $banRows . '</tbody></table></div></section>';

        return $this->adminPage('Access Control', $body, $viewer, 'access-control');
    }

    public function saveAccessBlacklistRule(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }
        try {
            SessionAuth::validateCsrf($request);
            $this->accessPolicy->registerBlacklistRule($request->body(), (int) $viewer['id']);
            SessionAuth::flash('success', 'Access rule saved.');
        } catch (\Throwable) {
            SessionAuth::flash('error', 'Unable to save the access rule.');
        }
        return Response::redirect('/admin/access-control');
    }

    public function toggleAccessBlacklistRule(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }
        try {
            SessionAuth::validateCsrf($request);
            $ruleId = $this->idParam($params);
            $this->accessPolicy->setBlacklistRuleActive($ruleId, (bool) $request->input('active', '0'), (int) $viewer['id']);
            SessionAuth::flash('success', 'Access rule status updated.');
        } catch (\Throwable) {
            SessionAuth::flash('error', 'Unable to update the access rule.');
        }
        return Response::redirect('/admin/access-control');
    }

    public function releaseDeviceBlock(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }
        try {
            SessionAuth::validateCsrf($request);
            $this->accessPolicy->releaseDeviceBlock(
                (int) $request->input('client_id', 0),
                (string) $request->input('device_uuid', ''),
                (int) $viewer['id'],
                true
            );
            SessionAuth::flash('success', 'Device block released.');
        } catch (\Throwable) {
            SessionAuth::flash('error', 'Unable to release the device block.');
        }
        return Response::redirect('/admin/access-control');
    }

    public function releaseAccountBan(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }
        try {
            SessionAuth::validateCsrf($request);
            $this->accessPolicy->releaseAccountBan(
                (int) $request->input('user_id', 0),
                (int) $viewer['id'],
                (string) $request->input('release_note', '')
            );
            SessionAuth::flash('success', 'Account ban released.');
        } catch (\Throwable) {
            SessionAuth::flash('error', 'Unable to release the account ban.');
        }
        return Response::redirect('/admin/access-control');
    }

    private function adminViewer(): ?array
    {
        $viewer = SessionAuth::currentUser($this->db);
        if ($viewer === null || (string) $viewer['role'] !== 'admin') {
            return null;
        }
        return $viewer;
    }

    private function sslCertificates(): SslCertificateService
    {
        return $this->sslCertificateService ??= new SslCertificateService($this->config, $this->db);
    }

    private function registrationService(): TenantRegistrationService
    {
        return new TenantRegistrationService($this->config, $this->db);
    }

    /**
     * @param array<int, int> $nodeIds
     */
    private function requestNodeUpdatesByIds(array $nodeIds): int
    {
        $filtered = array_values(array_unique(array_filter(array_map('intval', $nodeIds), static fn(int $id): bool => $id > 0)));
        if ($filtered === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($filtered), '?'));
        $token = bin2hex(random_bytes(24));
        $params = array_merge([$token, gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')], $filtered);
        $sql = 'UPDATE nodes
                SET agent_update_request_token = ?,
                    agent_update_requested_at = ?,
                    updated_at = ?
                WHERE id IN (' . $placeholders . ')';

        return $this->db->execute($sql, $params);
    }

    /**
     * @return array{service_endpoints:int,provision_tasks:int,node_heartbeats:int,traffic_usage_hourly:int,forwards_touched:int}
     */
    private function deleteNodeAndReferences(int $nodeId): array
    {
        $node = $this->db->fetchOne('SELECT id, name FROM nodes WHERE id = :id LIMIT 1', ['id' => $nodeId]);
        if ($node === null) {
            throw new ApiException('node_not_found', 404);
        }

        return $this->db->transaction(function (\Leikwan\Master\Database\Database $db) use ($nodeId): array {
            $endpointRows = $db->fetchAll(
                'SELECT id, forward_id
                 FROM service_endpoints
                 WHERE node_id = :node_id
                 ORDER BY id ASC',
                ['node_id' => $nodeId]
            );
            $forwardIds = array_values(array_unique(array_filter(array_map(
                static fn(array $row): int => (int) ($row['forward_id'] ?? 0),
                $endpointRows
            ), static fn(int $id): bool => $id > 0)));

            $deletedTasks = $db->execute('DELETE FROM provision_tasks WHERE node_id = :node_id', ['node_id' => $nodeId]);
            $deletedUsage = $db->execute('DELETE FROM traffic_usage_hourly WHERE node_id = :node_id', ['node_id' => $nodeId]);
            $deletedHeartbeats = $db->execute('DELETE FROM node_heartbeats WHERE node_id = :node_id', ['node_id' => $nodeId]);
            $deletedEndpoints = $db->execute('DELETE FROM service_endpoints WHERE node_id = :node_id', ['node_id' => $nodeId]);

            if ($forwardIds !== []) {
                $placeholders = implode(', ', array_fill(0, count($forwardIds), '?'));
                $params = array_merge([gmdate('Y-m-d H:i:s')], $forwardIds);
                $db->execute(
                    'UPDATE forwards SET updated_at = ? WHERE id IN (' . $placeholders . ')',
                    $params
                );
            }

            $deletedNodes = $db->execute('DELETE FROM nodes WHERE id = :id', ['id' => $nodeId]);
            if ($deletedNodes !== 1) {
                throw new ApiException('node_delete_failed', 500, ['message' => 'failed to delete slave node row']);
            }

            return [
                'service_endpoints' => $deletedEndpoints,
                'provision_tasks' => $deletedTasks,
                'node_heartbeats' => $deletedHeartbeats,
                'traffic_usage_hourly' => $deletedUsage,
                'forwards_touched' => count($forwardIds),
            ];
        });
    }

    public function knowledgebaseTopicsPage(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        $service = new KnowledgebaseService($this->db);
        $topics = $service->listAdminTopics();
        $languages = [
            ['en', 'English', 'en_status'],
            ['zh-Hans', 'Simplified Chinese', 'zh_hans_status'],
            ['zh-TW', 'Traditional Chinese', 'zh_tw_status'],
        ];
        $rows = '';
        foreach ($topics as $topic) {
            $topicId = (int) ($topic['id'] ?? 0);
            $topicKey = (string) ($topic['topic_key'] ?? '');
            $languageCards = '';
            foreach ($languages as [$locale, $label, $statusField]) {
                $status = (string) ($topic[$statusField] ?? '');
                $statusLabel = $status === '' ? 'Missing' : ucfirst($status);
                $statusClass = $status === 'published' ? 'success' : ($status === 'draft' ? 'info' : 'muted');
                $languageCards .= '<div class="card" style="min-width:180px;padding:14px;">'
                    . '<strong>' . $label . '</strong>'
                    . '<div style="margin:8px 0;"><span class="badge ' . $statusClass . '">' . $statusLabel . '</span></div>'
                    . '<a class="button ghost" href="/admin/knowledgebase/topics/' . $topicId . '/articles/' . $locale . '/edit">Edit Language Version</a>'
                    . '</div>';
            }

            $rows .= '<tr>'
                . '<td><strong>' . $this->escape($topicKey) . '</strong></td>'
                . '<td>' . (((int) ($topic['is_pinned'] ?? 0) === 1) ? 'Yes' : 'No') . '</td>'
                . '<td>' . (int) ($topic['sort_order'] ?? 100) . '</td>'
                . '<td><div style="display:flex;flex-wrap:wrap;gap:10px;">' . $languageCards . '</div></td>'
                . '<td><div class="actions">'
                . '<a class="button" href="/admin/knowledgebase/topics/' . $topicId . '/edit">Edit Topic</a>'
                . '<a class="button ghost" href="' . '/knowledgebase?thread=' . urlencode($topicKey) . '" target="_blank" rel="noopener">View Live Page</a>'
                . '</div></td>'
                . '</tr>';
        }

        $body = '
            <div class="hero">
              <div>
                <h2>Knowledgebase Topics</h2>
                <p class="muted">Manage topic ordering and each language version independently.</p>
              </div>
              <div class="actions">
                <a class="button" href="/admin/knowledgebase/topics/new">Create Topic</a>
                <a class="button ghost" href="/knowledgebase" target="_blank" rel="noopener">Open Reader</a>
              </div>
            </div>
            <section class="card" style="margin-top:20px;">
              <div class="table-wrap">
                <table>
                  <thead><tr><th>Topic Key</th><th>Pinned</th><th>Order</th><th>Language Versions</th><th>Actions</th></tr></thead>
                  <tbody>' . ($rows !== '' ? $rows : '<tr><td colspan="5" class="muted">No topics yet.</td></tr>') . '</tbody>
                </table>
              </div>
            </section>';

        return $this->adminPage('Knowledgebase Topics', $body, $viewer, 'knowledgebase');
    }

    public function knowledgebaseTopicEditorPage(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        $service = new KnowledgebaseService($this->db);
        $topicId = $this->idParam($params);
        $topic = $topicId > 0 ? $service->findAdminTopicById($topicId) : null;
        if ($topicId > 0 && $topic === null) {
            SessionAuth::flash('error', 'Knowledgebase topic not found.');
            return Response::redirect('/admin/knowledgebase');
        }

        $topic = $topic ?? [
            'id' => 0,
            'topic_key' => '',
            'is_pinned' => 0,
            'sort_order' => 100,
        ];
        $csrf = $this->escape(SessionAuth::csrfToken());
        $topicIdValue = (int) ($topic['id'] ?? 0);
        $pageTitle = $topicIdValue > 0 ? 'Edit Topic' : 'Create Topic';
        $topicKeyValue = $this->escape((string) ($topic['topic_key'] ?? ''));
        $sortOrderValue = (int) ($topic['sort_order'] ?? 100);
        $pinnedNoSelected = (int) ($topic['is_pinned'] ?? 0) === 0 ? ' selected' : '';
        $pinnedYesSelected = (int) ($topic['is_pinned'] ?? 0) === 1 ? ' selected' : '';
        $deleteForm = '';
        if ($topicIdValue > 0) {
            $deleteForm = <<<HTML
                <form method="post" action="/admin/knowledgebase/topics/{$topicIdValue}/delete" data-kb-confirm="kb-topic-delete-confirm">
                  <input type="hidden" name="csrf_token" value="{$csrf}">
                  <button class="button danger" type="submit">Delete Topic</button>
                </form>
                <span hidden id="kb-topic-delete-confirm">Delete this topic and all three language versions?</span>
            HTML;
        }

        $body = <<<HTML
            <section class="card">
              <div class="hero" style="margin-bottom:20px;">
                <div><h2>{$pageTitle}</h2><p class="muted">The topic key is used in the public knowledgebase link.</p></div>
                <div class="actions"><a class="button ghost" href="/admin/knowledgebase">Back to Library</a></div>
              </div>
              <form method="post" action="/admin/knowledgebase/topics/save">
                <input type="hidden" name="csrf_token" value="{$csrf}">
                <input type="hidden" name="id" value="{$topicIdValue}">
                <div class="form-grid">
                  <div class="field"><label>Topic Key</label><input type="text" name="topic_key" value="{$topicKeyValue}" required></div>
                  <div class="field"><label>Order</label><input type="number" name="sort_order" value="{$sortOrderValue}"></div>
                  <div class="field"><label>Pinned</label><select name="is_pinned"><option value="0"{$pinnedNoSelected}>No</option><option value="1"{$pinnedYesSelected}>Yes</option></select></div>
                </div>
                <div class="actions" style="margin-top:18px;"><button class="button" type="submit">Save Topic</button></div>
              </form>
              <div class="actions" style="margin-top:18px;">{$deleteForm}</div>
            </section>
            <script>
              document.querySelectorAll('[data-kb-confirm]').forEach((form) => {
                form.addEventListener('submit', (event) => {
                  const message = document.getElementById(form.dataset.kbConfirm || '');
                  if (message && !confirm(message.textContent || '')) event.preventDefault();
                });
              });
            </script>
        HTML;

        return $this->adminPage($pageTitle, $body, $viewer, 'knowledgebase');
    }

    public function saveKnowledgebaseTopic(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        $topicId = (int) $request->input('id', 0);
        try {
            SessionAuth::validateCsrf($request);
            $service = new KnowledgebaseService($this->db);
            $topicId = $service->saveTopic([
                'id' => $request->input('id', 0),
                'topic_key' => $request->input('topic_key', ''),
                'sort_order' => $request->input('sort_order', '100'),
                'is_pinned' => $request->input('is_pinned', '0'),
            ]);
            SessionAuth::flash('success', 'Knowledgebase topic saved.');
            return Response::redirect('/admin/knowledgebase/topics/' . $topicId . '/edit');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', $this->displayThrowableMessage($throwable));
            return Response::redirect($topicId > 0
                ? '/admin/knowledgebase/topics/' . $topicId . '/edit'
                : '/admin/knowledgebase/topics/new');
        }
    }

    public function deleteKnowledgebaseTopic(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $topicId = $this->idParam($params);
            if ($topicId <= 0) {
                throw new \InvalidArgumentException('Knowledgebase topic not found.');
            }
            (new KnowledgebaseService($this->db))->deleteTopic($topicId);
            SessionAuth::flash('success', 'Knowledgebase topic deleted.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', $this->displayThrowableMessage($throwable));
        }

        return Response::redirect('/admin/knowledgebase');
    }

    public function knowledgebaseArticleEditorPage(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        $topicId = $this->idParam($params);
        $routeLocale = $params['locale'] ?? null;
        $locale = is_scalar($routeLocale) ? LocaleResolver::normalize((string) $routeLocale) : null;
        if ($locale === null) {
            SessionAuth::flash('error', 'Unsupported knowledgebase locale.');
            return Response::redirect($topicId > 0
                ? '/admin/knowledgebase/topics/' . $topicId . '/edit'
                : '/admin/knowledgebase');
        }

        $service = new KnowledgebaseService($this->db);
        $topic = $service->findAdminTopicById($topicId);
        if ($topic === null) {
            SessionAuth::flash('error', 'Knowledgebase topic not found.');
            return Response::redirect('/admin/knowledgebase');
        }

        $article = $service->findAdminArticle($topicId, $locale) ?? [
            'id' => 0,
            'topic_id' => $topicId,
            'locale' => $locale,
            'title' => '',
            'excerpt' => '',
            'body_html' => '',
            'status' => 'draft',
        ];
        $csrf = $this->escape(SessionAuth::csrfToken());
        $articleId = (int) ($article['id'] ?? 0);
        $titleValue = $this->escape((string) ($article['title'] ?? ''));
        $excerptValue = $this->escape((string) ($article['excerpt'] ?? ''));
        $bodyHtmlValue = $this->escape((string) ($article['body_html'] ?? ''));
        $topicKey = $this->escape((string) ($topic['topic_key'] ?? ''));
        $localeValue = $this->escape($locale);
        $draftSelected = (string) ($article['status'] ?? 'draft') === 'draft' ? ' selected' : '';
        $publishedSelected = (string) ($article['status'] ?? 'draft') === 'published' ? ' selected' : '';
        $deleteForm = '';
        if ($articleId > 0) {
            $deleteForm = <<<HTML
                <form method="post" action="/admin/knowledgebase/articles/{$articleId}/delete" data-kb-confirm="kb-article-delete-confirm">
                  <input type="hidden" name="csrf_token" value="{$csrf}">
                  <button class="button danger" type="submit">Delete Language Version</button>
                </form>
                <span hidden id="kb-article-delete-confirm">Delete this language version?</span>
            HTML;
        }

        $body = <<<HTML
            <section class="card">
              <div class="hero" style="margin-bottom:20px;">
                <div><h2>Edit Language Version</h2><p class="muted">{$topicKey} · {$localeValue}</p></div>
                <div class="actions"><a class="button ghost" href="/admin/knowledgebase/topics/{$topicId}/edit">Edit Topic</a></div>
              </div>
              <form method="post" action="/admin/knowledgebase/articles/save" enctype="multipart/form-data" data-kb-article-editor data-csrf-token="{$csrf}">
                <input type="hidden" name="csrf_token" value="{$csrf}">
                <input type="hidden" name="id" value="{$articleId}">
                <input type="hidden" name="topic_id" value="{$topicId}">
                <input type="hidden" name="locale" value="{$localeValue}">
                <input type="hidden" name="mermaid_validation_digest" value="">
                <div class="form-grid">
                  <div class="field"><label>Title</label><input type="text" name="title" value="{$titleValue}" required></div>
                  <div class="field"><label>Summary</label><input type="text" name="excerpt" value="{$excerptValue}"></div>
                  <div class="field"><label>Status</label><select name="status"><option value="draft"{$draftSelected}>Draft</option><option value="published"{$publishedSelected}>Published</option></select></div>
                </div>
                <div class="field" style="margin-top:16px;">
                  <label>Body HTML</label>
                  <textarea id="body_html" name="body_html" rows="24">{$bodyHtmlValue}</textarea>
                </div>
                <div data-kb-mermaid-error class="flash error" role="alert" hidden style="margin-top:14px;"></div>
                <div class="actions" style="margin-top:18px;">
                  <button class="button" type="submit">Save Language Version</button>
                  <button class="button ghost" type="submit" formaction="/admin/knowledgebase/articles/preview" formtarget="_blank" formnovalidate>Preview</button>
                </div>
              </form>
              <div class="actions" style="margin-top:18px;">{$deleteForm}</div>
            </section>
            <script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>
            <script>
              if (typeof tinymce !== 'undefined') {
                const form = document.querySelector('[data-kb-article-editor]');
                const csrfToken = form ? form.getAttribute('data-csrf-token') || '' : '';
                tinymce.init({
                  selector: '#body_html',
                  height: 560,
                  menubar: 'file edit view insert format table tools help',
                  plugins: 'advlist autolink lists link image table code codesample fullscreen paste',
                  toolbar: 'undo redo | blocks | bold italic underline | bullist numlist blockquote | alignleft aligncenter alignright | link image table codesample | fullscreen code',
                  extended_valid_elements: 'pre[class],img[src|alt|width|height]',
                  paste_data_images: true,
                  automatic_uploads: true,
                  images_upload_handler: (blobInfo) => new Promise((resolve, reject) => {
                    const formData = new FormData();
                    formData.append('file', blobInfo.blob(), blobInfo.filename());
                    formData.append('csrf_token', csrfToken);
                    fetch('/admin/knowledgebase/images', {
                      method: 'POST',
                      body: formData,
                      credentials: 'same-origin'
                    }).then(async (response) => {
                      const payload = await response.json();
                      if (!response.ok || !payload.location) throw new Error(payload.error || 'Image upload failed');
                      resolve(payload.location);
                    }).catch((error) => reject(error.message));
                  })
                });
              }
              document.querySelectorAll('[data-kb-confirm]').forEach((form) => {
                form.addEventListener('submit', (event) => {
                  const message = document.getElementById(form.dataset.kbConfirm || '');
                  if (message && !confirm(message.textContent || '')) event.preventDefault();
                });
              });
            </script>
            <script src="/assets/vendor/mermaid/mermaid.min.js?v=11.12.0"></script>
            <script src="/assets/knowledgebase-admin.js?v=20260729"></script>
        HTML;

        return $this->adminPage('Edit Language Version', $body, $viewer, 'knowledgebase');
    }

    public function saveKnowledgebaseArticle(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        $topicId = (int) $request->input('topic_id', 0);
        $localeInput = $request->input('locale', '');
        $locale = is_scalar($localeInput) ? LocaleResolver::normalize((string) $localeInput) : null;
        $editorPath = $topicId > 0 && $locale !== null
            ? '/admin/knowledgebase/topics/' . $topicId . '/articles/' . $locale . '/edit'
            : '/admin/knowledgebase';
        try {
            SessionAuth::validateCsrf($request);
            $service = new KnowledgebaseService($this->db);
            $service->saveArticle([
                'id' => $request->input('id', 0),
                'topic_id' => $request->input('topic_id', 0),
                'locale' => $request->input('locale', ''),
                'title' => $request->input('title', ''),
                'excerpt' => $request->input('excerpt', ''),
                'status' => $request->input('status', 'draft'),
                'body_html' => $request->input('body_html', ''),
                'mermaid_validation_digest' => $request->input('mermaid_validation_digest', ''),
            ], (int) ($viewer['id'] ?? 0));
            SessionAuth::flash('success', 'Knowledgebase language version saved.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', $this->displayThrowableMessage($throwable));
        }

        return Response::redirect($editorPath);
    }

    public function previewKnowledgebaseArticle(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
        } catch (ApiException $exception) {
            return $this->knowledgebasePreviewErrorPage(
                $exception->statusCode(),
                'Preview request expired. Reload the editor and try again.'
            );
        }

        try {
            $localeInput = $request->input('locale', '');
            $titleInput = $request->input('title', '');
            $excerptInput = $request->input('excerpt', '');
            $bodyInput = $request->input('body_html', '');
            if (!is_scalar($localeInput) || !is_scalar($titleInput) || !is_scalar($excerptInput) || !is_scalar($bodyInput)) {
                throw new \InvalidArgumentException('Invalid preview content.');
            }
            $locale = LocaleResolver::normalize((string) $localeInput);
            if ($locale === null) {
                throw new \InvalidArgumentException('Unsupported preview locale.');
            }

            $sanitizedBody = (new KnowledgebaseHtmlSanitizer())->sanitize((string) $bodyInput);
            MermaidSourcePolicy::assertPreviewable($sanitizedBody);
        } catch (\Throwable) {
            return $this->knowledgebasePreviewErrorPage(
                422,
                'Preview could not be rendered. Check the article and diagrams.'
            );
        }

        $banners = [
            'en' => 'Preview — not published',
            'zh-Hans' => '预览 — 尚未发布',
            'zh-TW' => '預覽 — 尚未發佈',
        ];
        $safeLocale = $this->escape($locale);
        $safeTitle = $this->escape((string) $titleInput);
        $safeExcerpt = $this->escape((string) $excerptInput);
        $safeBanner = $this->escape($banners[$locale]);
        $diagramAssets = str_contains($sanitizedBody, 'class="mermaid"')
            ? '<script src="/assets/vendor/mermaid/mermaid.min.js?v=11.12.0"></script>'
                . '<script src="/assets/knowledgebase-mermaid.js?v=20260729"></script>'
            : '';
        $html = <<<HTML
            <!doctype html>
            <html lang="{$safeLocale}">
            <head>
              <meta charset="utf-8">
              <meta name="viewport" content="width=device-width, initial-scale=1">
              <title>{$safeBanner}</title>
              <style>
                :root{color-scheme:light dark;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;line-height:1.65}
                body{margin:0;background:#f4f7fb;color:#172033}.kb-preview{width:min(920px,calc(100% - 32px));margin:32px auto;background:#fff;border:1px solid #dbe3ee;border-radius:18px;box-shadow:0 18px 50px rgba(23,32,51,.08);overflow:hidden}.kb-preview-banner{padding:12px 24px;background:#eef4ff;color:#244d86;font-weight:700}.kb-preview-article{padding:clamp(24px,6vw,56px)}h1{font-size:clamp(2rem,6vw,3.25rem);line-height:1.1;margin:0 0 12px}.kb-preview-excerpt{font-size:1.1rem;color:#56657a;margin:0 0 32px}.kb-preview-body{overflow-wrap:anywhere}.kb-preview-body img{max-width:100%;height:auto}.kb-preview-body table{display:block;max-width:100%;overflow-x:auto;border-collapse:collapse}.kb-preview-body th,.kb-preview-body td{padding:8px 12px;border:1px solid #ccd6e3}.kb-preview-body pre{max-width:100%;overflow:auto;padding:16px;border-radius:10px;background:#edf1f7}.kb-preview-locale{font-size:.85rem;color:#6b7789}@media(max-width:600px){.kb-preview{width:100%;margin:0;border:0;border-radius:0;min-height:100vh}.kb-preview-banner{padding:10px 18px}.kb-preview-article{padding:24px 18px}}
                @media(prefers-color-scheme:dark){body{background:#111827;color:#e5e7eb}.kb-preview{background:#182131;border-color:#334155}.kb-preview-banner{background:#24324a;color:#bfdbfe}.kb-preview-excerpt,.kb-preview-locale{color:#aab7ca}.kb-preview-body pre{background:#111827}}
              </style>
            </head>
            <body>
              <main class="kb-preview">
                <div class="kb-preview-banner">{$safeBanner}</div>
                <article class="kb-preview-article">
                  <div class="kb-preview-locale">{$safeLocale}</div>
                  <h1>{$safeTitle}</h1>
                  <p class="kb-preview-excerpt">{$safeExcerpt}</p>
                  <div class="kb-preview-body">{$sanitizedBody}</div>
                </article>
              </main>
              {$diagramAssets}
            </body>
            </html>
        HTML;

        return Response::html(200, $html);
    }

    private function knowledgebasePreviewErrorPage(int $status, string $message): Response
    {
        $safeMessage = $this->escape($message);
        $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$safeMessage}</title>
</head>
<body><p>{$safeMessage}</p></body>
</html>
HTML;

        return Response::html($status, $html);
    }

    public function deleteKnowledgebaseArticle(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        $service = new KnowledgebaseService($this->db);
        $articleId = $this->idParam($params);
        $article = $service->findAdminArticleById($articleId);
        if ($article === null) {
            SessionAuth::flash('error', 'Knowledgebase article not found.');
            return Response::redirect('/admin/knowledgebase');
        }

        $topicId = (int) ($article['topic_id'] ?? 0);
        $redirect = '/admin/knowledgebase/topics/' . $topicId . '/edit';
        try {
            SessionAuth::validateCsrf($request);
            $service->deleteArticle($articleId);
            SessionAuth::flash('success', 'Knowledgebase language version deleted.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', $this->displayThrowableMessage($throwable));
        }

        return Response::redirect($redirect);
    }

    public function uploadKnowledgebaseImage(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::json(401, ['error' => 'authentication_required']);
        }

        try {
            SessionAuth::validateCsrf($request);
            $upload = $this->readKnowledgebaseImageUpload();
            $relativeDir = '/uploads/knowledgebase/' . gmdate('Y') . '/' . gmdate('m');
            $absoluteDir = dirname(__DIR__, 2) . '/public' . $relativeDir;
            if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
                throw new ApiException('knowledgebase_upload_dir_failed', 500, ['message' => 'failed to create upload directory']);
            }

            $filename = bin2hex(random_bytes(16)) . '.' . $upload['extension'];
            $absolutePath = $absoluteDir . '/' . $filename;
            if (!move_uploaded_file($upload['tmp_name'], $absolutePath)) {
                throw new ApiException('knowledgebase_upload_failed', 500, ['message' => 'failed to store uploaded image']);
            }

            return Response::json(200, ['location' => $relativeDir . '/' . $filename]);
        } catch (\Throwable $throwable) {
            return Response::json(422, ['error' => $this->displayThrowableMessage($throwable)]);
        }
    }

    private function adminPage(string $title, string $body, array $viewer, string $active): Response
    {
        return $this->page($title, $body, $viewer, $this->adminNavigationItems($active));
    }

    /**
     * @param array<int, array<string, mixed>> $groups
     */
    private function renderGroupCheckboxes(array $groups, string $inputName = 'allowed_node_group_ids[]', array $selectedIds = []): string
    {
        if ($groups === []) {
            return '<div class="muted">No slave groups yet. Create a slave group first.</div>';
        }

        $html = '';
        foreach ($groups as $group) {
            $html .= '<label style="display:flex;align-items:center;gap:10px;padding:8px 0;">
                <input type="checkbox" name="' . $this->escape($inputName) . '" value="' . (int) $group['id'] . '"' . (in_array((int) $group['id'], $selectedIds, true) ? ' checked' : '') . '>
                <span><strong>' . $this->escape((string) $group['name']) . '</strong> <span class="muted">(' . $this->escape((string) $group['group_key']) . ')</span></span>
            </label>';
        }
        return $html;
    }

    /**
     * @param array<int, array<string, mixed>> $groups
     */
    private function renderClientGroupCheckboxes(array $groups, string $inputName = 'allowed_client_group_ids[]', array $selectedIds = []): string
    {
        if ($groups === []) {
            return '<div class="muted">No client groups yet. Create client groups first.</div>';
        }

        $html = '';
        foreach ($groups as $group) {
            $html .= '<label style="display:flex;align-items:center;gap:10px;padding:8px 0;">
                <input type="checkbox" name="' . $this->escape($inputName) . '" value="' . (int) $group['id'] . '"' . (in_array((int) $group['id'], $selectedIds, true) ? ' checked' : '') . '>
                <span><strong>' . $this->escape((string) $group['name']) . '</strong> <span class="muted">(' . $this->escape((string) $group['group_key']) . ')</span></span>
            </label>';
        }
        return $html;
    }

    private function renderSidecarPointCheckboxes(array $points, string $name, array $selectedIds): string
    {
        if ($points === []) {
            return '<span class="muted">No target transport endpoints available.</span>';
        }

        $html = '';
        foreach ($points as $point) {
            $id = (int) ($point['id'] ?? 0);
            $checked = in_array($id, $selectedIds, true) ? ' checked' : '';
            $label = $this->escape((string) ($point['name'] ?? '')) . ' (' . $this->escape((string) ($point['sidecar_host'] ?? '')) . ' ' . $this->escape($this->sidecarPortSummary($point)) . ')';
            $html .= '<label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" name="' . $this->escape($name) . '" value="' . $id . '"' . $checked . '> ' . $label . '</label>';
        }
        return $html;
    }

    /**
     * @param array<int, array<string, mixed>> $routes
     * @param array<int, int> $selectedIds
     */
    private function renderForwardRoutePlanCheckboxes(array $routes, array $selectedIds): string
    {
        if ($routes === []) {
            return '<div class="muted">No active Forward Routes.</div>';
        }

        $html = '';
        foreach ($routes as $route) {
            $routeId = (int) ($route['id'] ?? 0);
            $label = trim((string) (($route['tenant_label'] ?? '') !== '' ? $route['tenant_label'] : ($route['name'] ?? '')));
            $details = array_values(array_filter([
                trim((string) ($route['tenant_badge'] ?? '')),
                trim((string) ($route['tenant_description'] ?? '')),
            ]));
            $html .= '<label style="display:flex;gap:10px;align-items:flex-start;padding:10px 0;border-top:1px solid rgba(19,34,56,0.08);">
                <input type="checkbox" name="allowed_forward_route_ids[]" value="' . $routeId . '"' . (in_array($routeId, $selectedIds, true) ? ' checked' : '') . '>
                <span><strong>' . $this->escape($label) . '</strong>'
                . ($details !== [] ? '<br><span class="muted" style="font-size:12px;line-height:1.6;">' . $this->escape(implode(' / ', $details)) . '</span>' : '')
                . '</span></label>';
        }
        return $html;
    }

    /**
     * @param array<string, bool> $defaults
     */
    private function renderFeatureCheckboxes(array $defaults, string $baseName, ?array $current = null): string
    {
        $labels = [
            'allow_manage_forwards' => 'Allow manage forwards',
            'allow_delete_forwards' => 'Allow delete forwards',
            'allow_view_client_config' => 'Allow view client config',
            'allow_rotate_client_token' => 'Allow rotate client token',
            'allow_select_multiple_line_groups' => 'Allow multiple line groups',
            'allow_use_all_line_groups' => 'Allow all allowed line groups',
            'allow_select_specific_line_client' => 'Allow specific line client',
            'allow_forward_chain' => 'Allow forward chain',
            'allow_custom_slaves' => 'Allow custom slaves',
            'allow_inbound_proxy' => 'Allow inbound proxy',
            'allow_latency_test' => 'Allow latency test',
        ];

        $html = '';
        foreach ($defaults as $key => $enabled) {
            $checked = $current[$key] ?? $enabled;
            $label = $labels[$key] ?? $key;
            $html .= '<label style="display:flex;align-items:center;gap:10px;padding:8px 0;">
                <input type="hidden" name="' . $this->escape($baseName) . '[' . $this->escape($key) . ']" value="0">
                <input type="checkbox" name="' . $this->escape($baseName) . '[' . $this->escape($key) . ']" value="1"' . ($checked ? ' checked' : '') . '>
                <span>' . $this->escape($label) . '</span>
            </label>';
        }
        return $html;
    }

    /**
     * @return array<string, bool>
     */
    private function extractFeatureFlagsFromRequest(Request $request): array
    {
        $defaults = $this->provisioning?->defaultFeatureFlags() ?? [];
        $raw = $request->body()['feature_flags'] ?? [];
        $flags = [];
        foreach ($defaults as $key => $default) {
            $flags[$key] = !empty($raw[$key]);
        }
        return $flags;
    }

    /**
     * @param array<string, mixed>|null $existing
     * @return array{
     *   domains: array<int, string>,
     *   issuer_name: ?string,
     *   expires_at: ?string,
     *   renewal_mode: string,
     *   source_config: array<string, mixed>,
     *   cert_pem: ?string,
     *   key_pem: ?string
     * }
     */
    private function buildSslCertificatePayload(Request $request, string $sourceType, ?array $existing = null): array
    {
        return match ($sourceType) {
            'panel_letsencrypt' => $this->buildPanelLetsEncryptCertificatePayload($request, $existing),
            'panel_filesystem' => $this->buildPanelFilesystemCertificatePayload($request),
            default => $this->buildUploadedCertificatePayload($request, $existing),
        };
    }

    /**
     * @param array<string, mixed>|null $existing
     * @return array{
     *   domains: array<int, string>,
     *   issuer_name: ?string,
     *   expires_at: ?string,
     *   renewal_mode: string,
     *   source_config: array<string, mixed>,
     *   cert_pem: ?string,
     *   key_pem: ?string
     * }
     */
    private function buildUploadedCertificatePayload(Request $request, ?array $existing = null): array
    {
        $uploadedCertPem = $this->readUploadedTextFile('ssl_cert_file');
        $uploadedKeyPem = $this->readUploadedTextFile('ssl_key_file');
        $pastedCertPem = trim((string) $request->input('ssl_cert_pem', ''));
        $pastedKeyPem = trim((string) $request->input('ssl_key_pem', ''));
        $certPem = $uploadedCertPem !== '' ? $uploadedCertPem : ($pastedCertPem !== '' ? $pastedCertPem : trim((string) ($existing['cert_pem'] ?? '')));
        $keyPem = $uploadedKeyPem !== '' ? $uploadedKeyPem : ($pastedKeyPem !== '' ? $pastedKeyPem : trim((string) ($existing['key_pem'] ?? '')));
        if ($certPem === '' || $keyPem === '') {
            throw new ApiException('invalid_ssl_certificate', 422, ['message' => 'uploaded certificate source requires both certificate PEM and private key PEM']);
        }

        $this->validateUploadedTlsPair($certPem, $keyPem);
        $metadata = $this->parseCertificateMetadata($certPem);

        return [
            'domains' => $metadata['domains'],
            'issuer_name' => $metadata['issuer_name'],
            'expires_at' => $metadata['expires_at'],
            'renewal_mode' => 'manual',
            'source_config' => [],
            'cert_pem' => $certPem,
            'key_pem' => $keyPem,
        ];
    }

    /**
     * @return array{
     *   domains: array<int, string>,
     *   issuer_name: ?string,
     *   expires_at: ?string,
     *   renewal_mode: string,
     *   source_config: array<string, mixed>,
     *   cert_pem: ?string,
     *   key_pem: ?string,
     *   queue_task?: bool,
     *   task_type?: string
     * }
     */
    private function buildPanelLetsEncryptCertificatePayload(Request $request, ?array $existing = null): array
    {
        $existingConfig = $existing !== null ? $this->decodeJsonList($existing['source_config_json'] ?? null) : [];
        $domain = trim((string) $request->input('ssl_domain', (string) ($existingConfig['domain'] ?? '')));
        if ($domain === '') {
            throw new ApiException('invalid_ssl_certificate', 422, ['message' => 'panel-hosted Let\'s Encrypt source requires a primary domain']);
        }
        $acmeEmail = trim((string) $request->input('ssl_acme_email', (string) ($existingConfig['email'] ?? '')));
        if ($acmeEmail === '') {
            throw new ApiException('invalid_ssl_certificate', 422, ['message' => 'panel-hosted Let\'s Encrypt source requires an ACME email']);
        }
        $wildcard = (string) $request->input('ssl_domain_scope', (!empty($existingConfig['wildcard']) ? 'wildcard' : 'single')) === 'wildcard';
        $credentialProfileId = (int) $request->input('ssl_credential_profile_id', (int) ($existingConfig['credential_profile_id'] ?? 0));
        if ($credentialProfileId <= 0) {
            throw new ApiException('invalid_ssl_certificate', 422, ['message' => 'panel-hosted Let\'s Encrypt via CF DNS requires a Cloudflare DNS credential profile']);
        }
        $credentialProfile = $this->db->fetchOne('SELECT * FROM credential_profiles WHERE id = :id LIMIT 1', ['id' => $credentialProfileId]);
        if ($credentialProfile === null) {
            throw new ApiException('credential_profile_not_found', 404, ['id' => $credentialProfileId]);
        }
        if ((string) ($credentialProfile['provider'] ?? '') !== 'cloudflare_global_key') {
            throw new ApiException('invalid_ssl_certificate', 422, ['message' => 'selected DNS credential profile must use Cloudflare Global Key']);
        }
        $dnsPropagationSeconds = max(10, (int) $request->input('ssl_dns_propagation_seconds', (int) ($existingConfig['dns_propagation_seconds'] ?? 60)));

        $sslCertificates = $this->sslCertificates();
        $certificateName = $sslCertificates->certificateDirectoryDomain($domain, $wildcard);
        [$defaultCertPath, $defaultKeyPath] = $sslCertificates->defaultPanelCertbotPaths($certificateName);
        $certPath = trim((string) $request->input('panel_tls_cert_source_path', (string) ($existingConfig['cert_path'] ?? '')));
        $keyPath = trim((string) $request->input('panel_tls_key_source_path', (string) ($existingConfig['key_path'] ?? '')));
        if ($certPath === '') {
            $certPath = $defaultCertPath;
        }
        if ($keyPath === '') {
            $keyPath = $defaultKeyPath;
        }
        $issueNow = (int) $request->input('ssl_issue_now', $existing === null ? 1 : 0) === 1;
        if (is_file($certPath) && is_readable($certPath) && is_file($keyPath) && is_readable($keyPath)) {
            [$certPem] = $sslCertificates->readPanelCertificateFiles($certPath, $keyPath);
            $metadata = $sslCertificates->parseCertificateMetadata($certPem);
        } elseif ($existing !== null && !$this->panelLetsEncryptConfigChanged($existingConfig, $domain, $acmeEmail, $wildcard, $credentialProfileId, $dnsPropagationSeconds, $certPath, $keyPath)) {
            $metadata = $this->existingSslCertificateMetadata($existing);
        } else {
            $metadata = [
                'domains' => $sslCertificates->requestedDomains($domain, $wildcard),
                'issuer_name' => null,
                'expires_at' => null,
            ];
        }

        return [
            'domains' => $metadata['domains'],
            'issuer_name' => $metadata['issuer_name'],
            'expires_at' => $metadata['expires_at'],
            'renewal_mode' => 'panel_certbot',
            'source_config' => [
                'domain' => $domain,
                'email' => $acmeEmail,
                'wildcard' => $wildcard ? 1 : 0,
                'credential_profile_id' => $credentialProfileId,
                'dns_propagation_seconds' => $dnsPropagationSeconds,
                'certificate_name' => $certificateName,
                'cert_path' => $certPath,
                'key_path' => $keyPath,
            ],
            'cert_pem' => null,
            'key_pem' => null,
            'queue_task' => $issueNow,
            'task_type' => $existing !== null && (string) ($existing['source_type'] ?? '') === 'panel_letsencrypt' ? 'renew' : 'issue',
        ];
    }

    /**
     * @return array{
     *   domains: array<int, string>,
     *   issuer_name: ?string,
     *   expires_at: ?string,
     *   renewal_mode: string,
     *   source_config: array<string, mixed>,
     *   cert_pem: ?string,
     *   key_pem: ?string
     * }
     */
    private function buildPanelFilesystemCertificatePayload(Request $request): array
    {
        $certPath = trim((string) $request->input('panel_filesystem_cert_path', ''));
        $keyPath = trim((string) $request->input('panel_filesystem_key_path', ''));
        if ($certPath === '' || $keyPath === '') {
            throw new ApiException('invalid_ssl_certificate', 422, ['message' => 'panel filesystem certificate source requires both cert and key paths']);
        }

        [$certPem] = $this->readPanelCertificateFiles($certPath, $keyPath);
        $metadata = $this->parseCertificateMetadata($certPem);

        return [
            'domains' => $metadata['domains'],
            'issuer_name' => $metadata['issuer_name'],
            'expires_at' => $metadata['expires_at'],
            'renewal_mode' => 'panel_sync',
            'source_config' => [
                'cert_path' => $certPath,
                'key_path' => $keyPath,
            ],
            'cert_pem' => null,
            'key_pem' => null,
        ];
    }

    /**
     * @param array<string, mixed> $credentialProfile
     */
    private function issuePanelLetsEncryptCertificate(string $domain, string $acmeEmail, bool $wildcard, array $credentialProfile, string $certificateName, int $dnsPropagationSeconds): void
    {
        $credentialConfig = $this->decodeJsonList($credentialProfile['config_json'] ?? null);
        $cloudflareEmail = trim((string) ($credentialConfig['cloudflare_email'] ?? ''));
        $cloudflareGlobalKey = trim((string) ($credentialConfig['cloudflare_global_key'] ?? ''));
        if ($cloudflareEmail === '' || $cloudflareGlobalKey === '') {
            throw new ApiException('credential_profile_invalid', 422, ['message' => 'selected Cloudflare DNS credential profile is missing email or global key']);
        }

        $credentialFile = tempnam(sys_get_temp_dir(), 'leikwan-cf-');
        if ($credentialFile === false) {
            throw new ApiException('ssl_certificate_issue_failed', 500, ['message' => 'failed to allocate temporary Cloudflare credential file']);
        }

        try {
            $content = "dns_cloudflare_email = {$cloudflareEmail}\n"
                . "dns_cloudflare_api_key = {$cloudflareGlobalKey}\n";
            file_put_contents($credentialFile, $content);
            @chmod($credentialFile, 0600);
            $certbotDirs = $this->panelCertbotDirectories();

            $parts = [
                'certbot',
                'certonly',
                '--dns-cloudflare',
                '--dns-cloudflare-credentials',
                $credentialFile,
                '--dns-cloudflare-propagation-seconds',
                (string) max(10, $dnsPropagationSeconds),
                '--config-dir',
                $certbotDirs['config'],
                '--work-dir',
                $certbotDirs['work'],
                '--logs-dir',
                $certbotDirs['logs'],
                '--cert-name',
                $certificateName,
                '--non-interactive',
                '--agree-tos',
                '--keep-until-expiring',
                '-m',
                $acmeEmail,
            ];
            foreach ($this->letsEncryptRequestedDomains($domain, $wildcard) as $requestedDomain) {
                $parts[] = '-d';
                $parts[] = $requestedDomain;
            }

            [$exitCode, $stdout, $stderr] = $this->runLocalCommand($parts);
            if ($exitCode !== 0) {
                $message = trim($stderr !== '' ? $stderr : $stdout);
                if ($message === '') {
                    $message = 'certbot returned a non-zero exit code';
                }
                $message = $this->normalizeCertbotIssueError($message);
                throw new ApiException('ssl_certificate_issue_failed', 422, ['message' => $message]);
            }
        } finally {
            if (is_file($credentialFile)) {
                @unlink($credentialFile);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function letsEncryptRequestedDomains(string $domain, bool $wildcard): array
    {
        $normalized = $this->normalizeCertificateDomain($domain);
        if (!$wildcard) {
            return [$normalized];
        }

        $baseDomain = $this->certificateDirectoryDomain($normalized, true);
        return ['*.' . $baseDomain, $baseDomain];
    }

    private function normalizeCertificateDomain(string $domain): string
    {
        $normalized = strtolower(trim($domain));
        if ($normalized === '') {
            return '';
        }
        return str_starts_with($normalized, '*.') ? substr($normalized, 2) : $normalized;
    }

    private function certificateDirectoryDomain(string $domain, bool $wildcard): string
    {
        $normalized = $this->normalizeCertificateDomain($domain);
        return $wildcard ? $normalized : $normalized;
    }

    /**
     * @return array{config:string,work:string,logs:string}
     */
    private function panelCertbotDirectories(): array
    {
        $masterRoot = dirname(__DIR__, 2);
        $baseDir = rtrim((string) ($this->config->get('PANEL_CERTBOT_BASE_DIR', $masterRoot . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'certbot') ?? ''), DIRECTORY_SEPARATOR);
        $configDir = (string) ($this->config->get('PANEL_CERTBOT_CONFIG_DIR', $baseDir . DIRECTORY_SEPARATOR . 'config') ?? '');
        $workDir = (string) ($this->config->get('PANEL_CERTBOT_WORK_DIR', $baseDir . DIRECTORY_SEPARATOR . 'work') ?? '');
        $logsDir = (string) ($this->config->get('PANEL_CERTBOT_LOGS_DIR', $baseDir . DIRECTORY_SEPARATOR . 'logs') ?? '');

        foreach ([$configDir, $workDir, $logsDir] as $path) {
            if ($path === '') {
                throw new ApiException('ssl_certificate_issue_failed', 500, ['message' => 'panel certbot directories are not configured']);
            }
            if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
                throw new ApiException('ssl_certificate_issue_failed', 500, ['message' => 'failed to create writable panel certbot directory: ' . $path]);
            }
            if (!is_writable($path)) {
                throw new ApiException('ssl_certificate_issue_failed', 500, ['message' => 'panel certbot directory is not writable: ' . $path]);
            }
        }

        return [
            'config' => $configDir,
            'work' => $workDir,
            'logs' => $logsDir,
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function defaultPanelCertbotPaths(string $certificateName): array
    {
        $dirs = $this->panelCertbotDirectories();
        $name = $this->normalizeCertificateDomain($certificateName);

        return [
            $dirs['config'] . DIRECTORY_SEPARATOR . 'live' . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR . 'fullchain.pem',
            $dirs['config'] . DIRECTORY_SEPARATOR . 'live' . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR . 'privkey.pem',
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function readPanelCertificateFiles(string $certPath, string $keyPath): array
    {
        if (!is_file($certPath) || !is_readable($certPath)) {
            throw new ApiException('invalid_ssl_certificate', 422, ['message' => 'panel certificate file is not readable: ' . $certPath]);
        }
        if (!is_file($keyPath) || !is_readable($keyPath)) {
            throw new ApiException('invalid_ssl_certificate', 422, ['message' => 'panel private key file is not readable: ' . $keyPath]);
        }

        $certPem = trim((string) file_get_contents($certPath));
        $keyPem = trim((string) file_get_contents($keyPath));
        if ($certPem === '' || $keyPem === '') {
            throw new ApiException('invalid_ssl_certificate', 422, ['message' => 'panel certificate or private key file is empty']);
        }

        $this->validateUploadedTlsPair($certPem, $keyPem);
        return [$certPem, $keyPem];
    }

    /**
     * @return array{domains: array<int, string>, issuer_name: ?string, expires_at: ?string}
     */
    private function parseCertificateMetadata(string $certPem): array
    {
        if (!function_exists('openssl_x509_read') || !function_exists('openssl_x509_parse')) {
            throw new ApiException('openssl_unavailable', 500, ['message' => 'OpenSSL support is required to parse SSL certificate metadata']);
        }

        $certificate = @openssl_x509_read($certPem);
        if ($certificate === false) {
            throw new ApiException('invalid_ssl_certificate', 422, ['message' => 'certificate PEM could not be parsed']);
        }

        $parsed = @openssl_x509_parse($certificate);
        if (!is_array($parsed)) {
            throw new ApiException('invalid_ssl_certificate', 422, ['message' => 'certificate metadata could not be parsed']);
        }

        $domains = [];
        $subjectCommonName = trim((string) (($parsed['subject']['CN'] ?? '') ?: ''));
        if ($subjectCommonName !== '') {
            $domains[] = $subjectCommonName;
        }

        $subjectAltName = (string) (($parsed['extensions']['subjectAltName'] ?? '') ?: '');
        if ($subjectAltName !== '' && preg_match_all('/DNS:([^,\s]+)/', $subjectAltName, $matches)) {
            foreach ($matches[1] as $match) {
                $value = trim((string) $match);
                if ($value !== '') {
                    $domains[] = $value;
                }
            }
        }
        $domains = array_values(array_unique($domains));

        $issuerParts = is_array($parsed['issuer'] ?? null) ? $parsed['issuer'] : [];
        $issuerNameParts = array_values(array_filter([
            trim((string) ($issuerParts['O'] ?? '')),
            trim((string) ($issuerParts['OU'] ?? '')),
            trim((string) ($issuerParts['CN'] ?? '')),
        ], static fn(string $value): bool => $value !== ''));
        $issuerName = $issuerNameParts !== [] ? implode(' / ', array_unique($issuerNameParts)) : null;

        $expiresAt = null;
        if (isset($parsed['validTo_time_t'])) {
            $expiresAt = gmdate('Y-m-d H:i:s', (int) $parsed['validTo_time_t']);
        }

        return [
            'domains' => $domains,
            'issuer_name' => $issuerName,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @param array<string, mixed> $certificate
     * @return array{domains: array<int, string>, issuer_name: ?string, expires_at: ?string}
     */
    private function existingSslCertificateMetadata(array $certificate): array
    {
        $domains = array_values(array_filter(
            array_map(static fn(mixed $value): string => trim((string) $value), $this->decodeJsonList($certificate['domains_json'] ?? null)),
            static fn(string $value): bool => $value !== ''
        ));
        $issuerName = trim((string) ($certificate['issuer_name'] ?? ''));
        $expiresAt = trim((string) ($certificate['expires_at'] ?? ''));

        return [
            'domains' => $domains,
            'issuer_name' => $issuerName !== '' ? $issuerName : null,
            'expires_at' => $expiresAt !== '' ? $expiresAt : null,
        ];
    }

    /**
     * @param array<string, mixed> $existingConfig
     */
    private function panelLetsEncryptConfigChanged(
        array $existingConfig,
        string $domain,
        string $acmeEmail,
        bool $wildcard,
        int $credentialProfileId,
        int $dnsPropagationSeconds,
        string $certPath,
        string $keyPath
    ): bool {
        return strtolower(trim((string) ($existingConfig['domain'] ?? ''))) !== strtolower(trim($domain))
            || strtolower(trim((string) ($existingConfig['email'] ?? ''))) !== strtolower(trim($acmeEmail))
            || !empty($existingConfig['wildcard']) !== $wildcard
            || (int) ($existingConfig['credential_profile_id'] ?? 0) !== $credentialProfileId
            || max(10, (int) ($existingConfig['dns_propagation_seconds'] ?? 60)) !== $dnsPropagationSeconds
            || trim((string) ($existingConfig['cert_path'] ?? '')) !== trim($certPath)
            || trim((string) ($existingConfig['key_path'] ?? '')) !== trim($keyPath);
    }

    /**
     * @param array<string, mixed> $certificate
     */
    private function sslCertificateDomainsSummary(array $certificate): string
    {
        $domains = $this->decodeJsonList($certificate['domains_json'] ?? null);
        $clean = array_values(array_filter(array_map(static fn(mixed $value): string => trim((string) $value), $domains), static fn(string $value): bool => $value !== ''));
        return implode(', ', $clean);
    }

    private function sslCertificateSourceLabel(string $sourceType): string
    {
        return match ($sourceType) {
            'panel_letsencrypt' => 'Panel Let\'s Encrypt via CF DNS',
            'panel_filesystem' => 'Panel filesystem',
            default => 'Uploaded PEM pair',
        };
    }

    private function sslCertificateRenewalLabel(string $renewalMode): string
    {
        return match ($renewalMode) {
            'panel_certbot' => 'Panel certbot renew',
            'panel_sync' => 'Panel path sync',
            default => 'Manual replace',
        };
    }

    private function sslCertificateTaskTypeLabel(string $taskType): string
    {
        return match ($taskType) {
            'renew' => 'Renew',
            default => 'Issue',
        };
    }

    private function sslCertificateTaskPlainStatusLabel(string $status): string
    {
        return match ($status) {
            'completed' => 'completed',
            'failed' => 'failed',
            'running' => 'running',
            default => 'queued',
        };
    }

    private function sslCertificateTaskStatusHtml(string $status): string
    {
        $class = match ($status) {
            'completed' => 'success',
            'failed' => 'danger',
            default => 'info',
        };

        return '<span class="badge ' . $class . '">' . $this->escape($this->sslCertificateTaskPlainStatusLabel($status)) . '</span>';
    }

    /**
     * @param array<string, mixed>|null $task
     */
    private function sslCertificateLatestTaskHtml(?array $task): string
    {
        if (!is_array($task)) {
            return '<span class="muted">none</span>';
        }

        $time = trim((string) ($task['updated_at'] ?? ''));
        $timeHtml = $time !== '' ? '<div class="muted" style="margin-top:6px;">' . $this->escape($time) . '</div>' : '';

        return '#'
            . (int) ($task['id'] ?? 0)
            . '<div style="margin-top:6px;">'
            . $this->sslCertificateTaskStatusHtml((string) ($task['status'] ?? 'pending'))
            . '</div>'
            . $timeHtml;
    }

    /**
     * @param array<string, mixed> $task
     */
    private function sslCertificateTaskLogHtml(array $task): string
    {
        $error = trim((string) ($task['error_message'] ?? ''));
        $output = trim((string) ($task['output_log'] ?? ''));
        if ($error === '' && $output === '') {
            return '<span class="muted">No output yet.</span>';
        }

        $body = '';
        if ($error !== '') {
            $body .= '<div class="flash danger" style="margin:12px 0 12px;">' . $this->escape($error) . '</div>';
        }
        if ($output !== '') {
            $body .= '<pre style="margin:0;">' . $this->escape($output) . '</pre>';
        }

        return '<details><summary>' . $this->escape($error !== '' ? 'Open error / log' : 'Open log') . '</summary><div style="margin-top:12px;">' . $body . '</div></details>';
    }

    /**
     * @param array<string, mixed> $certificate
     */
    private function sslCertificateExpiryHtml(array $certificate): string
    {
        $expiresAt = trim((string) ($certificate['expires_at'] ?? ''));
        if ($expiresAt === '') {
            return '<span class="muted">unknown</span>';
        }

        $timestamp = strtotime($expiresAt);
        if ($timestamp === false) {
            return $this->escape($expiresAt);
        }

        $class = 'success';
        if ($timestamp < time()) {
            $class = 'danger';
        } elseif ($timestamp < time() + 30 * 86400) {
            $class = 'info';
        }

        return '<span class="badge ' . $class . '">' . $this->escape($expiresAt) . '</span>';
    }

    private function normalizeCertbotIssueError(string $message): string
    {
        $normalized = strtolower($message);
        if (
            str_contains($normalized, '--dns-cloudflare')
            || str_contains($normalized, '--dns-cloudflare-credentials')
            || str_contains($normalized, 'unrecognized arguments')
        ) {
            return 'The panel host certbot is missing the Cloudflare DNS plugin. Install `python3-certbot-dns-cloudflare` on the panel host, then try again.';
        }
        if (str_contains($normalized, 'certbot: not found') || str_contains($normalized, 'command not found')) {
            return 'The panel host does not have certbot installed. Install `certbot` and `python3-certbot-dns-cloudflare`, then try again.';
        }
        if (str_contains($normalized, 'challenge failed for domain') || str_contains($normalized, 'dns-01 challenge')) {
            return 'Cloudflare DNS validation failed. Usually this means the TXT record did not propagate in time, or the selected Cloudflare credential cannot manage the correct zone. Increase `DNS Propagation Seconds` to 60 or 120 and verify the domain is in the same Cloudflare account as the selected credential.';
        }
        return $message;
    }


    /**
     * @param array<int, string> $parts
     * @param array<string, string> $env
     * @return array{0:int,1:string,2:string}
     */
    private function runLocalCommand(array $parts, array $env = []): array
    {
        if (!function_exists('proc_open')) {
            throw new ApiException('proc_open_unavailable', 500);
        }

        $escaped = array_map(static fn(string $part): string => escapeshellarg($part), $parts);
        $command = implode(' ', $escaped);
        $descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $processEnv = null;
        if ($env !== []) {
            $processEnv = getenv();
            if (!is_array($processEnv)) {
                $processEnv = [];
            }
            foreach ($env as $key => $value) {
                $processEnv[(string) $key] = (string) $value;
            }
        }

        $process = proc_open($command, $descriptorSpec, $pipes, dirname(__DIR__, 3), $processEnv);
        if (!is_resource($process)) {
            throw new ApiException('proc_open_failed', 500);
        }

        $stdout = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [$exitCode, $stdout, $stderr];
    }

    private function readKnowledgebaseImageUpload(): array
    {
        $file = $_FILES['file'] ?? $_FILES['image'] ?? null;
        if (!is_array($file)) {
            throw new ApiException('knowledgebase_upload_missing', 422, ['message' => 'no image upload found']);
        }

        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode !== UPLOAD_ERR_OK) {
            throw new ApiException('knowledgebase_upload_invalid', 422, ['message' => 'image upload failed']);
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new ApiException('knowledgebase_upload_invalid', 422, ['message' => 'uploaded image is not readable']);
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
            throw new ApiException('knowledgebase_upload_size', 422, ['message' => 'image must be between 1 byte and 5 MB']);
        }

        $mime = (string) ((new \finfo(FILEINFO_MIME_TYPE))->file($tmpName) ?: '');
        $allowed = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        if (!isset($allowed[$mime])) {
            throw new ApiException('knowledgebase_upload_type', 422, ['message' => 'only PNG, JPG, GIF, and WEBP images are allowed']);
        }

        return [
            'tmp_name' => $tmpName,
            'extension' => $allowed[$mime],
        ];
    }

    private function readUploadedTextFile(string $field): string
    {
        $file = $_FILES[$field] ?? null;
        if (!is_array($file)) {
            return '';
        }

        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            return '';
        }
        if ($errorCode !== UPLOAD_ERR_OK) {
            throw new ApiException('invalid_uploaded_tls_file', 422, ['message' => 'failed to upload file: ' . $field]);
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new ApiException('invalid_uploaded_tls_file', 422, ['message' => 'uploaded file is not readable: ' . $field]);
        }

        $content = file_get_contents($tmpName);
        if (!is_string($content) || trim($content) === '') {
            throw new ApiException('invalid_uploaded_tls_file', 422, ['message' => 'uploaded file is empty: ' . $field]);
        }

        return trim($content);
    }

    private function validateUploadedTlsPair(string $certPem, string $keyPem): void
    {
        if (!str_contains($certPem, 'BEGIN CERTIFICATE')) {
            throw new ApiException('invalid_uploaded_tls_cert', 422, ['message' => 'certificate PEM is invalid or missing BEGIN CERTIFICATE']);
        }
        if (!str_contains($keyPem, 'BEGIN ') || !str_contains($keyPem, 'PRIVATE KEY')) {
            throw new ApiException('invalid_uploaded_tls_key', 422, ['message' => 'private key PEM is invalid or missing BEGIN PRIVATE KEY']);
        }
    }

    /**
     * @return array<string, string>
     */
    private function readEnvValues(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $trimmed, 2);
            $values[trim($key)] = trim((string) $value, "\"'");
        }

        return $values;
    }

    /**
     * @param array<string, string> $overrides
     */
    private function writeEnvValues(array $overrides): void
    {
        $masterRoot = dirname(__DIR__, 2);
        $envPath = $masterRoot . DIRECTORY_SEPARATOR . '.env';
        $templatePath = $masterRoot . DIRECTORY_SEPARATOR . '.env.example';
        $values = $this->readEnvValues(is_file($envPath) ? $envPath : $templatePath);
        foreach ($overrides as $key => $value) {
            $values[$key] = $value;
        }

        $orderedKeys = [];
        foreach (file($templatePath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
                continue;
            }
            [$key] = explode('=', $trimmed, 2);
            $orderedKeys[] = trim($key);
        }
        foreach (array_keys($values) as $key) {
            if (!in_array($key, $orderedKeys, true)) {
                $orderedKeys[] = $key;
            }
        }

        $lines = [];
        foreach ($orderedKeys as $key) {
            $value = (string) ($values[$key] ?? '');
            $needsQuotes = str_contains($value, ' ') || str_contains($value, '#');
            $encoded = $needsQuotes ? '"' . addcslashes($value, "\\\"") . '"' : $value;
            $lines[] = $key . '=' . $encoded;
        }

        file_put_contents($envPath, implode(PHP_EOL, $lines) . PHP_EOL);
    }

    public function userSlaveGroupsPage(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        $groups = $this->db->fetchAll(
            'SELECT g.*, u.email AS owner_email,
                    (SELECT COUNT(*) FROM user_slave_group_members m WHERE m.group_id = g.id) AS member_count,
                    (SELECT COUNT(*) FROM nodes n WHERE n.user_slave_group_id = g.id AND n.sharing_mode = :shared) AS shared_node_count
             FROM user_slave_groups g
             LEFT JOIN users u ON u.id = g.owner_user_id
             ORDER BY g.created_at DESC, g.id DESC',
            ['shared' => 'group_shared']
        );

        $csrf = $this->escape(SessionAuth::csrfToken());
        $rows = '';
        foreach ($groups as $group) {
            $rows .= '<tr>
                <td>' . (int) $group['id'] . '</td>
                <td>' . $this->escape((string) $group['name']) . '</td>
                <td>' . $this->escape((string) ($group['owner_email'] ?? '')) . '</td>
                <td>' . $this->escape((string) $group['invite_code']) . '</td>
                <td>' . (int) ($group['member_count'] ?? 0) . '</td>
                <td>' . (int) ($group['shared_node_count'] ?? 0) . '</td>
                <td>' . $this->escape((string) $group['created_at']) . '</td>
                <td>
                    <form class="inline" method="post" action="/admin/user-groups/' . (int) $group['id'] . '/delete">
                        <input type="hidden" name="csrf_token" value="' . $csrf . '">
                        <button type="submit" class="button danger">Delete</button>
                    </form>
                </td>
            </tr>';
        }

        $body = '
            <div class="hero">
              <div>
                <h2>User Slave Groups</h2>
                <p class="muted">View and manage all tenant-created slave sharing groups.</p>
              </div>
            </div>
            <section class="card">
              <div class="table-wrap">
                <table>
                  <thead><tr><th>ID</th><th>Name</th><th>Owner</th><th>Invite Code</th><th>Members</th><th>Shared Nodes</th><th>Created</th><th>Actions</th></tr></thead>
                  <tbody>' . $rows . '</tbody>
                </table>
              </div>
            </section>';

        return $this->adminPage('User Slave Groups', $body, $viewer, 'user-groups');
    }

    public function deleteUserSlaveGroup(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }
        try {
            SessionAuth::validateCsrf($request);
            $groupId = $this->idParam($params);
            // Reset sharing on nodes in this group
            $this->db->execute(
                'UPDATE nodes SET sharing_mode = :private, user_slave_group_id = NULL WHERE user_slave_group_id = :gid',
                ['private' => 'private', 'gid' => $groupId]
            );
            $this->db->execute('DELETE FROM user_slave_group_members WHERE group_id = :gid', ['gid' => $groupId]);
            $this->db->execute('DELETE FROM user_slave_groups WHERE id = :id', ['id' => $groupId]);
            SessionAuth::flash('success', 'Group deleted.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed: ' . $throwable->getMessage());
        }
        return Response::redirect('/admin/user-groups');
    }

    /**
     * @return array{0:string,1:string}
     */
    private function defaultPanelTlsPaths(string $domain): array
    {
        $normalized = trim($domain);
        if (str_starts_with($normalized, '*.')) {
            $normalized = substr($normalized, 2);
        }

        return [
            '/etc/letsencrypt/live/' . $normalized . '/fullchain.pem',
            '/etc/letsencrypt/live/' . $normalized . '/privkey.pem',
        ];
    }
}
