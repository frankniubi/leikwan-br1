<?php
declare(strict_types=1);

namespace Leikwan\Master\Controllers;

use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;
use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\ProvisioningService;
use Leikwan\Master\Support\SessionAuth;
use Leikwan\Master\Support\SslCertificateService;
use Leikwan\Master\Support\TenantRegistrationService;

final class AdminController extends BaseController
{
    private ?SslCertificateService $sslCertificateService = null;

    public function __construct(
        \Leikwan\Master\Config $config,
        \Leikwan\Master\Database\Database $db,
        private ?ProvisioningService $provisioning = null,
    ) {
        parent::__construct($config, $db);
    }

    public function dashboard(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

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

        $body = '
            <div class="hero">
              <div>
                <h2>Admin Overview</h2>
                <p class="muted">Use the left navigation to manage tenants, plans, credentials, clients, slave groups and slave nodes.</p>
              </div>
            </div>
            <div class="grid three">' . $cardHtml . '</div>
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

    public function tenantsPage(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        $registration = $this->registrationService();
        $registrationSettings = $registration->registrationSettings();
        $inviteCodes = $registration->listInviteCodes();
        $defaults = $this->provisioning?->defaultFeatureFlags() ?? [];
        $editId = (int) $request->input('edit', 0);
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
        foreach ($tenants as $tenant) {
            $verifiedHtml = !empty($tenant['email_verified_at'])
                ? '<span class="badge success">verified</span>'
                : '<span class="badge info">not verified</span>';
            $rows .= '<tr>
                <td>' . (int) $tenant['id'] . '</td>
                <td>' . $this->escape((string) $tenant['name']) . '</td>
                <td>' . $this->escape((string) $tenant['email']) . '</td>
                <td><span class="badge ' . (((string) $tenant['status'] === 'active') ? 'success' : 'danger') . '">' . $this->escape((string) $tenant['status']) . '</span></td>
                <td>' . $verifiedHtml . '</td>
                <td>' . (int) $tenant['service_count'] . '</td>
                <td>
                    <div class="actions">
                        <a class="button ghost" href="/admin/tenants?edit=' . (int) $tenant['id'] . '">Edit</a>
                        <form class="inline" method="post" action="/admin/tenants/' . (int) $tenant['id'] . '/delete">
                            <input type="hidden" name="csrf_token" value="' . $this->escape(SessionAuth::csrfToken()) . '">
                            <button type="submit" class="button danger">Delete</button>
                        </form>
                    </div>
                </td>
            </tr>';
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
        $body = '
            <div class="hero">
              <div>
                <h2>Tenants</h2>
                <p class="muted">Create tenant accounts manually, or let users self-register as tenants with email verification and optional invite codes.</p>
              </div>
            </div>
            <div class="grid two">
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
              </section>
              <section class="card">
                <h3>Tenant List</h3>
                <div class="table-wrap">
                  <table>
                    <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Status</th><th>Verified</th><th>Services</th><th>Actions</th></tr></thead>
                    <tbody>' . $rows . '</tbody>
                  </table>
                </div>
              </section>
            </div>
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

        return $this->adminPage('Tenants', $body, $viewer, 'tenants');
    }

    public function plansPage(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        $plans = $this->provisioning?->listPlans() ?? [];
        $nodeGroups = $this->provisioning?->listNodeGroups() ?? [];
        $clientGroups = $this->provisioning?->listClientGroups() ?? [];
        $defaults = $this->provisioning?->defaultFeatureFlags() ?? [];
        $editId = (int) $request->input('edit', 0);
        $editing = null;
        foreach ($plans as $plan) {
            if ((int) $plan['id'] === $editId) {
                $editing = $plan;
                break;
            }
        }
        $rows = '';
        foreach ($plans as $plan) {
            $allowed = array_map(static fn(array $group): string => (string) $group['name'], $plan['allowed_node_groups'] ?? []);
            $allowedClients = array_map(static fn(array $group): string => (string) $group['name'], $plan['allowed_client_groups'] ?? []);
            $billingSummary = ((string) ($plan['billing_mode'] ?? 'monthly_bundle') === 'metered')
                ? 'Metered / ' . $this->escape((string) ($plan['billing_currency'] ?? 'CNY')) . ' ' . $this->escape((string) ($plan['monthly_base_fee'] ?? '0.00')) . ' base + ' . $this->escape((string) ($plan['traffic_price_per_gb'] ?? '0.0000')) . ' / GB'
                : 'Bundle / ' . $this->escape((string) ($plan['billing_currency'] ?? 'CNY')) . ' ' . $this->escape((string) ($plan['monthly_base_fee'] ?? '0.00')) . ' + ' . (int) ($plan['monthly_included_traffic_gb'] ?? 0) . ' GB / month';
            $billingSummary .= ' / Reset ' . $this->escape((string) ($plan['billing_currency'] ?? 'CNY')) . ' ' . $this->escape((string) ($plan['traffic_reset_price'] ?? '0.00'));
            $billingSummary .= ' / +' . (int) ($plan['bandwidth_step_mbps'] ?? 0) . ' Mbps => '
                . $this->escape((string) ($plan['billing_currency'] ?? 'CNY')) . ' ' . $this->escape((string) ($plan['bandwidth_step_price'] ?? '0.00'));
            $billingSummary .= ' / Included Clients ' . (int) ($plan['included_client_count'] ?? 0)
                . ' + Extra Client '
                . $this->escape((string) ($plan['billing_currency'] ?? 'CNY')) . ' ' . $this->escape((string) ($plan['extra_client_price'] ?? '0.00'));
            $rows .= '<tr>
                <td>' . (int) $plan['id'] . '</td>
                <td>' . $this->escape((string) $plan['name']) . '</td>
                <td>' . $this->escape((string) $plan['product_code']) . '</td>
                <td>' . (int) $plan['default_bandwidth_limit_mbps'] . ' Mbps</td>
                <td>' . (int) $plan['default_traffic_limit_gb'] . ' GB</td>
                <td>' . $billingSummary . '</td>
                <td>' . $this->escape(implode(', ', $allowed)) . '</td>
                <td>' . $this->escape(implode(', ', $allowedClients)) . '</td>
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
                <p class="muted">Plans define usable resources, allowed slave groups and the billing model attached to each tenant service.</p>
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
                    <div class="field"><label>Description</label><input type="text" name="description" value="' . $this->escape((string) ($editing['description'] ?? '')) . '"></div>
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
                  </div>
                  <div class="field" style="margin-top:14px;">
                    <label>Allowed Slave Groups</label>
                    <div class="stack">' . $this->renderGroupCheckboxes($nodeGroups, 'allowed_node_group_ids[]', array_map(static fn(array $group): int => (int) $group['id'], $editing['allowed_node_groups'] ?? [])) . '</div>
                  </div>
                  <div class="field" style="margin-top:14px;">
                    <label>Allowed Client Groups</label>
                    <div class="stack">' . $this->renderClientGroupCheckboxes($clientGroups, 'allowed_client_group_ids[]', array_map(static fn(array $group): int => (int) $group['id'], $editing['allowed_client_groups'] ?? [])) . '</div>
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
                    <thead><tr><th>ID</th><th>Name</th><th>Code</th><th>Bandwidth</th><th>Traffic</th><th>Billing</th><th>Allowed Slave Groups</th><th>Allowed Client Groups</th><th>Status</th><th>Actions</th></tr></thead>
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

        $clients = $this->provisioning?->listClients() ?? [];
        $clientGroups = $this->provisioning?->listClientGroups() ?? [];
        $nodeGroups = $this->provisioning?->listNodeGroups() ?? [];
        $chains = $this->provisioning?->listForwardChains() ?? [];
        $tenants = $this->db->fetchAll('SELECT id, name, email FROM users WHERE role = :role ORDER BY name ASC, email ASC', ['role' => 'tenant']);
        $editId = (int) $request->input('edit', 0);
        $editing = null;
        foreach ($clients as $client) {
            if ((int) $client['id'] === $editId) {
                $editing = $client;
                break;
            }
        }
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
        foreach ($clients as $client) {
            $assignedForwards = $clientForwardInstallMap[(int) ($client['id'] ?? 0)] ?? [];
            $assignedForwardSummary = $this->renderAssignedForwardSummary($assignedForwards);
            $installDetails = $this->renderClientAgentInstallDetails(
                $client,
                $assignedForwards
            );
            $diagItems = $this->renderClientDiagnosticsHtml($client);
            $usagePanel = $this->renderUsageOverviewPanel($clientUsageMap[(int) ($client['id'] ?? 0)] ?? [], 'Client Traffic');
            $rows .= '<tr>
                <td>' . (int) $client['id'] . '</td>
                <td>' . $this->escape((string) $client['name']) . '</td>
                <td>' . $this->escape((string) $client['display_host']) . '</td>
                <td>' . $this->escape((string) ($client['owner_email'] ?? 'shared')) . '</td>
                <td>' . $this->escape((string) ($client['group_name'] ?? '')) . '</td>
                <td>' . $this->escape($this->endpointModeLabel((string) ($client['default_endpoint_mode'] ?? 'slave_group'))) . '</td>
                <td>' . $this->escape((string) ($client['default_node_group_key'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($client['default_forward_chain_name'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($client['port_range_start'] ?? '')) . ' - ' . $this->escape((string) ($client['port_range_end'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($client['speed_limit_mbps'] ?? '')) . '</td>
                <td>' . $this->escape((string) $client['status']) . '</td>
                <td>' . $this->escape((string) ($client['agent_version'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($client['last_sync_at'] ?? '')) . '</td>
                <td>
                    <div class="actions">
                        <a class="button ghost" href="/admin/clients?edit=' . (int) $client['id'] . '">Edit</a>
                        <form class="inline" method="post" action="/admin/clients/' . (int) $client['id'] . '/delete" onsubmit="return confirm(\'Delete this client from the database? Active forwards must be removed first.\')">
                            <input type="hidden" name="csrf_token" value="' . $csrf . '">
                            <button type="submit" class="button danger">Delete</button>
                        </form>
                        <form class="inline" method="post" action="/admin/clients/' . (int) $client['id'] . '/diagnose">
                            <input type="hidden" name="csrf_token" value="' . $csrf . '">
                            <button type="submit" class="button secondary">Run Diagnostics</button>
                        </form>
                        <form class="inline" method="post" action="/admin/clients/' . (int) $client['id'] . '/request-update">
                            <input type="hidden" name="csrf_token" value="' . $csrf . '">
                            <button type="submit" class="button secondary">Request Update</button>
                        </form>
                    </div>
                    ' . $assignedForwardSummary . '
                    ' . $installDetails . '
                    <details style="margin-top:12px;">
                        <summary>Diagnostics</summary>
                        <div class="muted">Status: ' . $this->escape((string) ($client['last_diag_status'] ?? '')) . '</div>
                        <div class="muted">Last Run: ' . $this->escape((string) ($client['last_diag_run_at'] ?? '')) . '</div>
                        <div class="muted">Error: ' . $this->escape((string) ($client['last_diag_error'] ?? '')) . '</div>
                        <div style="margin-top:12px;">' . $diagItems . '</div>
                    </details>
                    <details style="margin-top:12px;">
                        <summary>Usage Calendar</summary>
                        ' . $usagePanel . '
                    </details>
                </td>
            </tr>';
        }

        $body = '
            <div class="hero">
              <div>
                <h2>Clients</h2>
                <p class="muted">Clients are the tenant-side line definitions. For the easiest start, create one client, point it at one default slave group, and let tenants build their first rule in single mode.</p>
              </div>
            </div>
            <div class="grid two">
              <section class="card">
                <h3>' . ($editing !== null ? 'Edit Client' : 'Create Client') . '</h3>
                <div class="flash success">
                  <strong>Recommended first setup:</strong> keep default route mode on <code>slave_group</code>, choose one default slave group, and leave forward chain empty.
                </div>
                <p class="muted">These fields define the default routing policy for the client inside the panel. They do not change how the client-agent installer works. Installing a client agent still only needs the panel URL, client id and agent token. If you leave owner empty, this client is shared and admin-managed.</p>
                <form method="post" action="/admin/clients">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <input type="hidden" name="id" value="' . (int) ($editing['id'] ?? 0) . '">
                  <div class="form-grid">
                    <div class="field"><label>Name</label><input type="text" name="name" value="' . $this->escape((string) ($editing['name'] ?? '')) . '" required></div>
                    <div class="field"><label>Display Host</label><input type="text" name="display_host" value="' . $this->escape((string) ($editing['display_host'] ?? '')) . '" placeholder="bgp.example.com" required><p class="muted">Tenant-facing display label only. It does not drive slave SNI / CDN routing.</p></div>
                    <div class="field"><label>Owner Tenant</label><select name="owner_user_id">' . $ownerOptions . '</select></div>
                    <div class="field"><label>Client Group</label><select name="group_id">' . $groupOptions . '</select></div>
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
                    <div class="field"><label>Status</label><select name="status"><option value="active"' . (((string) ($editing['status'] ?? 'active')) === 'active' ? ' selected' : '') . '>active</option><option value="disabled"' . (((string) ($editing['status'] ?? '')) === 'disabled' ? ' selected' : '') . '>disabled</option></select></div>
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
                  <div class="actions"><button class="button" type="submit">Save Client</button>' . ($editing !== null ? '<a class="button secondary" href="/admin/clients">Cancel</a>' : '') . '</div>
                </form>
              </section>
              <section class="card">
                <h3>Client List</h3>
                <p class="muted">Admin can install any client-agent from this page. Tenant-owned and shared/admin-provided clients both work here.</p>
                <div class="table-wrap">
                  <table>
                    <thead><tr><th>ID</th><th>Name</th><th>Display Host</th><th>Owner</th><th>Group</th><th>Default Route Mode</th><th>Default Slave Group</th><th>Default Chain</th><th>Port Range</th><th>Speed Limit</th><th>Status</th><th>Agent</th><th>Last Sync</th><th>Actions</th></tr></thead>
                    <tbody>' . $rows . '</tbody>
                  </table>
                </div>
              </section>
            </div>';

        return $this->adminPage('Clients', $body, $viewer, 'clients');
    }

    public function sidecarsPage(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        $points = $this->provisioning?->listSidecarPoints() ?? [];
        $tenants = $this->db->fetchAll('SELECT id, name, email FROM users WHERE role = :role ORDER BY name ASC, email ASC', ['role' => 'tenant']);
        $editId = (int) $request->input('edit', 0);
        $editing = null;
        foreach ($points as $point) {
            if ((int) $point['id'] === $editId) {
                $editing = $point;
                break;
            }
        }

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
        foreach ($points as $point) {
            $status = $this->sidecarPointStatusInfo($point);
            $installCommand = $this->bondSidecarInstallCommand($point);
            $uninstallCommand = $this->bondSidecarUninstallCommand($point);
            $usagePanel = $this->renderUsageOverviewPanel($sidecarUsageMap[(int) ($point['id'] ?? 0)] ?? [], 'Sidecar Traffic');
            $rows .= '<tr>
                <td>' . (int) $point['id'] . '</td>
                <td>' . $this->escape((string) $point['name']) . '</td>
                <td>' . $this->escape((string) ($point['owner_email'] ?? 'shared')) . '</td>
                <td>' . $this->escape((string) $point['sidecar_host']) . '</td>
                <td>' . (int) $point['listen_port'] . '</td>
                <td>' . $this->escape((string) ($point['bind_host'] ?? '0.0.0.0')) . '</td>
                <td>' . $this->escape((string) ($point['total_speed_limit_mbps'] ?? '')) . '</td>
                <td><span class="badge ' . $status['class'] . '">' . $this->escape($status['label']) . '</span></td>
                <td>' . $this->escape((string) ($point['agent_version'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($point['last_heartbeat_at'] ?? '')) . '</td>
                <td>' . (int) ($point['forward_count'] ?? 0) . '</td>
                <td>
                    <div class="actions">
                        <a class="button ghost" href="/admin/sidecars?edit=' . (int) $point['id'] . '">Edit</a>
                        <form class="inline" method="post" action="/admin/sidecars/' . (int) $point['id'] . '/request-update">
                            <input type="hidden" name="csrf_token" value="' . $this->escape(SessionAuth::csrfToken()) . '">
                            <button type="submit" class="button secondary">Request Update</button>
                        </form>
                        <form class="inline" method="post" action="/admin/sidecars/' . (int) $point['id'] . '/delete">
                            <input type="hidden" name="csrf_token" value="' . $this->escape(SessionAuth::csrfToken()) . '">
                            <button type="submit" class="button danger">Delete</button>
                        </form>
                    </div>
                    <details style="margin-top:12px;">
                        <summary>Install command</summary>
                        <pre>' . $this->escape($installCommand) . '</pre>
                    </details>
                    <details style="margin-top:12px;">
                        <summary>Uninstall command</summary>
                        <pre>' . $this->escape($uninstallCommand) . '</pre>
                    </details>
                    <details style="margin-top:12px;">
                        <summary>Usage Calendar</summary>
                        ' . $usagePanel . '
                    </details>
                </td>
            </tr>';
        }

        $csrf = $this->escape(SessionAuth::csrfToken());
        $body = '
            <div class="hero">
              <div>
                <h2>Sidecar Points</h2>
                <p class="muted">Create tenant-owned or shared/admin-managed target-side bond sidecar points here. Shared points are visible to all tenants and can be selected in bond forwards.</p>
              </div>
            </div>
            <div class="grid two">
              <section class="card">
                <h3>' . ($editing !== null ? 'Edit Sidecar Point' : 'Create Sidecar Point') . '</h3>
                <form method="post" action="/admin/sidecars">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <input type="hidden" name="id" value="' . (int) ($editing['id'] ?? 0) . '">
                  <div class="form-grid">
                    <div class="field"><label>Name</label><input type="text" name="name" value="' . $this->escape((string) ($editing['name'] ?? '')) . '" required></div>
                    <div class="field"><label>Owner Tenant</label><select name="owner_user_id">' . $ownerOptions . '</select></div>
                    <div class="field"><label>Sidecar Host</label><input type="text" name="sidecar_host" value="' . $this->escape((string) ($editing['sidecar_host'] ?? '')) . '" required></div>
                    <div class="field"><label>Listen Port</label><input type="number" name="listen_port" value="' . $this->escape((string) ($editing['listen_port'] ?? 9000)) . '" required></div>
                    <div class="field"><label>Bind Host</label><input type="text" name="bind_host" value="' . $this->escape((string) ($editing['bind_host'] ?? '0.0.0.0')) . '"></div>
                    <div class="field"><label>Total Limit Mbps</label><input type="number" name="total_speed_limit_mbps" value="' . $this->escape((string) ($editing['total_speed_limit_mbps'] ?? '')) . '" min="1" placeholder="optional"></div>
                    <div class="field"><label>Status</label><select name="is_active"><option value="1"' . (((int) ($editing['is_active'] ?? 1)) === 1 ? ' selected' : '') . '>active</option><option value="0"' . (((int) ($editing['is_active'] ?? 1)) !== 1 ? ' selected' : '') . '>disabled</option></select></div>
                  </div>
                  <div class="actions"><button class="button" type="submit">Save Sidecar Point</button>' . ($editing !== null ? '<a class="button secondary" href="/admin/sidecars">Cancel</a>' : '') . '</div>
                </form>
              </section>
              <section class="card">
                <h3>Sidecar Point List</h3>
                <div class="table-wrap">
                  <table>
                    <thead><tr><th>ID</th><th>Name</th><th>Owner</th><th>Host</th><th>Port</th><th>Bind</th><th>Total Limit</th><th>Status</th><th>Version</th><th>Heartbeat</th><th>Forwards</th><th>Actions</th></tr></thead>
                    <tbody>' . $rows . '</tbody>
                  </table>
                </div>
              </section>
            </div>';

        return $this->adminPage('Sidecar Points', $body, $viewer, 'sidecars');
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
            SessionAuth::flash('success', 'Sidecar point saved.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to save sidecar point: ' . $this->displayThrowableMessage($throwable));
        }

        return Response::redirect('/admin/sidecars');
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
            SessionAuth::flash('success', 'Sidecar point deleted.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to delete sidecar point: ' . $this->displayThrowableMessage($throwable));
        }

        return Response::redirect('/admin/sidecars');
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
            SessionAuth::flash('success', 'Sidecar update requested. The installed sidecar heartbeat will pull the latest panel-hosted version automatically.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to request sidecar update: ' . $this->displayThrowableMessage($throwable));
        }

        return Response::redirect('/admin/sidecars');
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
        $editId = (int) $request->input('edit', 0);
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
        foreach ($nodes as $node) {
            $statusClass = ((string) $node['status'] === 'active') ? 'success' : 'danger';
            $usagePanel = $this->renderUsageOverviewPanel($nodeUsageMap[(int) ($node['id'] ?? 0)] ?? [], 'Slave Traffic');
            $rows .= '<tr>
                <td><input type="checkbox" class="node-update-select" name="node_ids[]" value="' . (int) $node['id'] . '" form="selected-node-update-form"></td>
                <td>' . (int) $node['id'] . '</td>
                <td>' . $this->escape((string) $node['name']) . '</td>
                <td>' . $this->escape((string) ($node['group_name'] ?? $node['group_key'] ?? '')) . '</td>
                <td>' . $this->escape((string) $node['public_ip']) . '</td>
                <td>' . $this->escape((string) $node['region']) . '</td>
                <td>' . $this->escape((string) ($node['agent_version'] ?? '')) . '</td>
                <td>' . (int) $node['current_load'] . '</td>
                <td><span class="badge ' . $statusClass . '">' . $this->escape((string) $node['status']) . '</span></td>
                <td>' . $this->escape((string) $node['last_heartbeat_at']) . '</td>
                <td>' . $this->escape((string) ($node['credential_profile_name'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($node['ssl_certificate_name'] ?? '')) . '</td>
                <td>
                    <div class="actions">
                        <a class="button ghost" href="/admin/nodes?edit=' . (int) $node['id'] . '">Edit</a>
                        <form class="inline" method="post" action="/admin/nodes/' . (int) $node['id'] . '/request-update">
                            <input type="hidden" name="csrf_token" value="' . $this->escape(SessionAuth::csrfToken()) . '">
                            <button type="submit" class="button secondary">Request Update</button>
                        </form>
                        <form class="inline" method="post" action="/admin/nodes/' . (int) $node['id'] . '/delete" onsubmit="return confirm(\'Delete this slave node and remove its database references? Clients will stop using it on their next config sync.\')">
                            <input type="hidden" name="csrf_token" value="' . $this->escape(SessionAuth::csrfToken()) . '">
                            <button type="submit" class="button danger">Delete</button>
                        </form>
                    </div>
                    <details style="margin-top:12px;">
                        <summary>Usage Calendar</summary>
                        ' . $usagePanel . '
                    </details>
                </td>
            </tr>';
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

        $body = '
            <div class="hero">
              <div>
                <h2>Slave Nodes</h2>
                <p class="muted">Install a slave manually with a script or let the panel trigger installation over SSH. For the first forward, you only need one online slave in one slave group.</p>
              </div>
            </div>
            <div class="grid two">
              <section class="card">
                <h3>Remote SSH Install</h3>
                <div class="flash success">Quick start: create one slave group first, then install one slave into that group. You can build your first tenant forward without chains or multiple endpoints.</div>
                <form method="post" action="/admin/nodes/install-remote">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <div class="form-grid">
                    <div class="field"><label>Remote Host</label><input type="text" name="host" placeholder="1.2.3.4" required></div>
                    <div class="field"><label>SSH User</label><input type="text" name="user" value="root"></div>
                    <div class="field"><label>SSH Port</label><input type="number" name="port" value="22"></div>
                    <div class="field"><label>SSH Key Path</label><input type="text" name="key" placeholder="/root/.ssh/id_rsa"></div>
                    <div class="field"><label>SSH Password</label><input type="password" name="password" placeholder="root password" autocomplete="new-password"></div>
                   <div class="field"><label>Region</label><input type="text" name="region" placeholder="hk"></div>
                   <div class="field"><label>Node Name</label><input type="text" name="name" placeholder="hk-edge-01"></div>
                   <div class="field"><label>Slave Group</label><select name="group_key">' . $groupOptions . '</select></div>
                   <div class="field"><label>Agent API URL</label><input type="text" name="master_url" value="' . $appUrl . '"></div>
                   <div class="field"><label>Credential Profile</label><select name="credential_profile_id">' . $installCredentialOptions . '</select></div>
                   <div class="field"><label>TLS Mode</label><select name="tls_mode"><option value="self-signed">self-signed</option><option value="letsencrypt">lets encrypt</option><option value="existing">existing files</option></select></div>
                   <div class="field"><label>TLS Domain</label><input type="text" name="tls_domain" placeholder="edge.example.com"></div>
                   <div class="field"><label>Wildcard</label><select name="tls_wildcard"><option value="0">standard certificate</option><option value="1">wildcard + apex</option></select></div>
                   <div class="field"><label>TLS Email</label><input type="text" name="tls_email" placeholder="ops@example.com"></div>
                   <div class="field"><label>TLS Cert Path</label><input type="text" name="tls_cert_path" placeholder="/etc/wstunnel/ssl/server.crt"></div>
                   <div class="field"><label>TLS Key Path</label><input type="text" name="tls_key_path" placeholder="/etc/wstunnel/ssl/server.key"></div>
                    <div class="field" style="grid-column:1 / -1;"><label><input type="checkbox" name="force_register" value="1"> &#26032;&#22686; Node: ignore any existing node token on the host and append <code>--force-register</code></label></div>
                   </div>
                   <p class="muted">Use either SSH key path or SSH password. Password mode requires <code>sshpass</code> on the panel host. Choose a credential profile when you want Cloudflare DNS authorization and wildcard issuance. TLS mode defaults to self-signed. If you want the slave to receive a panel-managed certificate, install with self-signed first and then assign one from <code>Credentials -> SSL Certificate Library</code>.</p>
                   <div class="actions"><button class="button" type="submit">Install Slave Remotely</button></div>
                 </form>
               </section>
               <section class="card">
                 <h3>Manual Install Command</h3>
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
                <p class="muted">If the host was reused or cloned from an image and already has <code>/etc/leikwan-slave/config.json</code>, the installer will reuse that node identity unless you append <code>--force-register</code>.</p>
               </section>
             </div>
            <section class="card" style="margin-top:20px;">
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
            </section>
            <section class="card" style="margin-top:20px;">
              <h3>' . ($editing !== null ? 'Edit Slave Node' : 'Manage Slave Node') . '</h3>
              <p class="muted">Select one registered slave to push modification-level config. TLS, polling and listener settings are exposed here, but reusable certificates now live in <code>Credentials -> SSL Certificate Library</code> so nodes only reference library items.</p>
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
                <div class="actions"><button class="button" type="submit"' . ($editing === null ? ' disabled' : '') . '>Save Node Config</button>' . ($editing !== null ? '<a class="button secondary" href="/admin/nodes">Cancel</a>' : '') . '</div>
              </form>
            </section>
            <section class="card" style="margin-top:20px;">
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
            </section>
            <section class="card" style="margin-top:20px;">
              <h3>Registered Nodes</h3>
              <form id="selected-node-update-form" method="post" action="/admin/nodes/request-update-selected">
                <input type="hidden" name="csrf_token" value="' . $csrf . '">
              </form>
              <div class="table-wrap">
                <div class="actions" style="margin-bottom:12px;">
                  <button class="button secondary" type="submit" form="selected-node-update-form">Request Update For Selected</button>
                  <button class="button ghost" type="button" onclick="(function(){const boxes=document.querySelectorAll(&quot;.node-update-select&quot;);const all=[...boxes].length>0&&[...boxes].every((b)=>b.checked);boxes.forEach((b)=>b.checked=!all);})()">Toggle All</button>
                </div>
                <table>
                  <thead><tr><th>Select</th><th>ID</th><th>Name</th><th>Group</th><th>Public IP</th><th>Region</th><th>Agent</th><th>Load</th><th>Status</th><th>Heartbeat</th><th>Credential</th><th>SSL Certificate</th><th>Actions</th></tr></thead>
                  <tbody>' . $rows . '</tbody>
                </table>
              </div>
            </section>';

        return $this->adminPage('Slave Nodes', $body, $viewer, 'nodes');
    }

    public function servicesPage(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

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
        foreach ($services as $service) {
            $statusClass = ((string) $service['status'] === 'active') ? 'success' : 'danger';
            $billing = $this->provisioning?->serviceBillingOverview((int) $service['id']) ?? null;
            $billingHtml = $billing !== null
                ? $this->escape((string) ($billing['mode_label'] ?? '')) . '<br><span class="muted">' . $this->escape((string) ($billing['estimated_total_formatted'] ?? '')) . '</span>'
                : '<span class="muted">n/a</span>';
            $rows .= '<tr>
                <td>' . (int) $service['id'] . '</td>
                <td>' . $this->escape((string) $service['name']) . '</td>
                <td>' . $this->escape((string) $service['user_email']) . '</td>
                <td>' . $this->escape((string) ($service['plan_name'] ?? '')) . '</td>
                <td><span class="badge ' . $statusClass . '">' . $this->escape((string) $service['status']) . '</span></td>
                <td>' . $billingHtml . '</td>
                <td>' . $this->escape((string) $service['whmcs_service_id']) . '</td>
                <td>' . $this->escape((string) $service['expire_at']) . '</td>
            </tr>';
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
        $body = '
            <div class="hero">
              <div>
                <h2>Services</h2>
                <p class="muted">Use this page to activate services, assign plans and inspect the current billing model attached to each service.</p>
              </div>
            </div>
            <div class="grid two">
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
              </section>
              <section class="card">
                <h3>Service List</h3>
                <div class="table-wrap">
                  <table>
                    <thead><tr><th>ID</th><th>Name</th><th>Tenant</th><th>Plan</th><th>Status</th><th>Billing</th><th>WHMCS</th><th>Expire At</th></tr></thead>
                    <tbody>' . $rows . '</tbody>
                  </table>
                </div>
              </section>
            </div>';

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
                $this->db->insert('users', [
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
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to save tenant: ' . $throwable->getMessage());
        }

        return Response::redirect('/admin/tenants');
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

        return Response::redirect('/admin/tenants');
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

        return Response::redirect('/admin/tenants');
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
                'is_active' => $request->input('is_active', '1'),
                'allowed_node_group_ids' => $request->body()['allowed_node_group_ids'] ?? [],
                'allowed_client_group_ids' => $request->body()['allowed_client_group_ids'] ?? [],
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

    public function installClientRemote(Request $request, array $params): Response
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

            $script = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR . 'remote-install-client-agent.sh';
            $host = trim((string) $request->input('host', ''));
            $sshUser = trim((string) $request->input('user', 'root'));
            $sshPort = trim((string) $request->input('port', '22'));
            $sshKey = trim((string) $request->input('key', ''));
            $sshPassword = (string) $request->input('password', '');

            if ($host === '') {
                throw new ApiException('remote_install_invalid', 422, ['message' => 'host is required']);
            }
            if ($sshKey !== '' && $sshPassword !== '') {
                throw new ApiException('remote_install_invalid', 422, ['message' => 'use either ssh key or ssh password']);
            }

            $parts = [
                'bash',
                $script,
                '--host',
                $host,
                '--user',
                $sshUser,
                '--port',
                $sshPort,
                '--master',
                $this->agentBaseUrl(),
                '--artifact-base',
                $this->artifactBaseUrl(),
                '--client-id',
                (string) $clientId,
                '--agent-token',
                (string) ($client['agent_token'] ?? ''),
            ];
            if ($sshKey !== '') {
                $parts[] = '--key';
                $parts[] = $sshKey;
            }

            $env = [];
            if ($sshPassword !== '') {
                $env['LEIKWAN_SSH_PASSWORD'] = $sshPassword;
            }

            [$exitCode, $stdout, $stderr] = $this->runLocalCommand($parts, $env);
            if ($exitCode !== 0) {
                throw new ApiException('remote_install_failed', 500, ['stdout' => $stdout, 'stderr' => $stderr, 'exit_code' => $exitCode]);
            }

            SessionAuth::flash('success', 'Remote client-agent install started. ' . trim($stdout));
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to start remote client-agent install: ' . $throwable->getMessage());
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
                'bond_protocols',
                'hybrid_modes',
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

    public function installNodeRemote(Request $request, array $params): Response
    {
        $viewer = $this->adminViewer();
        if ($viewer === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $host = trim((string) $request->input('host', ''));
            $masterUrl = trim((string) $request->input('master_url', $this->agentBaseUrl()));
            $artifactBaseUrl = trim((string) $request->input('artifact_base_url', $this->artifactBaseUrl()));
            $groupKey = trim((string) $request->input('group_key', ''));
            $sshKey = trim((string) $request->input('key', ''));
            $sshPassword = (string) $request->input('password', '');
            $tlsMode = trim((string) $request->input('tls_mode', 'self-signed'));
            $credentialProfileId = (int) $request->input('credential_profile_id', 0);
            $tlsWildcard = (int) $request->input('tls_wildcard', 0) === 1;
            $forceRegister = (int) $request->input('force_register', 0) === 1;
            if ($host === '' || $masterUrl === '' || $groupKey === '') {
                throw new ApiException('remote_install_invalid', 422, ['message' => 'host, master_url and group_key are required']);
            }
            if ($sshKey !== '' && $sshPassword !== '') {
                throw new ApiException('remote_install_invalid', 422, ['message' => 'use either ssh key or ssh password']);
            }
            if (!in_array($tlsMode, ['self-signed', 'letsencrypt', 'existing'], true)) {
                throw new ApiException('remote_install_invalid', 422, ['message' => 'invalid tls_mode']);
            }
            if ($tlsMode === 'letsencrypt' && $tlsWildcard && $credentialProfileId <= 0) {
                throw new ApiException('remote_install_invalid', 422, ['message' => 'wildcard letsencrypt requires a credential profile']);
            }
            $credentialProfile = null;
            if ($credentialProfileId > 0) {
                $credentialProfile = $this->db->fetchOne('SELECT * FROM credential_profiles WHERE id = :id LIMIT 1', ['id' => $credentialProfileId]);
                if ($credentialProfile === null) {
                    throw new ApiException('credential_profile_not_found', 404, ['id' => $credentialProfileId]);
                }
            }

            $script = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR . 'remote-install-slave.sh';
            $parts = ['bash', $script, '--host', $host, '--master', $masterUrl, '--artifact-base', $artifactBaseUrl, '--token', (string) $this->config->get('NODE_ENROLLMENT_TOKEN', ''), '--group', $groupKey];
            if ($credentialProfileId > 0) {
                $parts[] = '--credential-profile-id';
                $parts[] = (string) $credentialProfileId;
            }
            foreach ([['user', 'user'], ['port', 'port'], ['key', 'key'], ['region', 'region'], ['name', 'name'], ['public-ip', 'public_ip'], ['private-ip', 'private_ip']] as [$arg, $field]) {
                $value = trim((string) $request->input($field, ''));
                if ($value !== '') {
                    $parts[] = '--' . $arg;
                    $parts[] = $value;
                }
            }
            foreach ([['tls-mode', $tlsMode], ['tls-domain', (string) $request->input('tls_domain', '')], ['tls-email', (string) $request->input('tls_email', '')], ['tls-cert', (string) $request->input('tls_cert_path', '')], ['tls-key', (string) $request->input('tls_key_path', '')]] as [$arg, $value]) {
                $value = trim((string) $value);
                if ($value !== '') {
                    $parts[] = '--' . $arg;
                    $parts[] = $value;
                }
            }
            if ($tlsWildcard) {
                $parts[] = '--tls-wildcard';
                $parts[] = '1';
            }
            if (is_array($credentialProfile)) {
                $credentialConfig = $this->decodeJsonList($credentialProfile['config_json'] ?? null);
                if ((string) ($credentialProfile['provider'] ?? '') === 'cloudflare_global_key') {
                    $cloudflareEmail = trim((string) ($credentialConfig['cloudflare_email'] ?? ''));
                    $cloudflareGlobalKey = trim((string) ($credentialConfig['cloudflare_global_key'] ?? ''));
                    if ($cloudflareEmail === '' || $cloudflareGlobalKey === '') {
                        throw new ApiException('credential_profile_invalid', 422, ['message' => 'selected credential profile is missing Cloudflare fields']);
                    }
                    $parts[] = '--cloudflare-email';
                    $parts[] = $cloudflareEmail;
                    $parts[] = '--cloudflare-global-key';
                    $parts[] = $cloudflareGlobalKey;
                }
            }
            if ($forceRegister) {
                $parts[] = '--force-register';
            }

            $env = [];
            if ($sshPassword !== '') {
                $env['LEIKWAN_SSH_PASSWORD'] = $sshPassword;
            }

            [$exitCode, $stdout, $stderr] = $this->runLocalCommand($parts, $env);
            if ($exitCode !== 0) {
                throw new ApiException('remote_install_failed', 500, ['stdout' => $stdout, 'stderr' => $stderr, 'exit_code' => $exitCode]);
            }

            SessionAuth::flash('success', 'Remote install started. ' . trim($stdout));
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to start remote install: ' . $throwable->getMessage());
        }

        return Response::redirect('/admin/nodes');
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

    private function adminPage(string $title, string $body, array $viewer, string $active): Response
    {
        return $this->page($title, $body, $viewer, [
            ['href' => '/admin', 'label' => 'Overview', 'active' => $active === 'overview'],
            ['href' => '/admin/tenants', 'label' => 'Tenants', 'active' => $active === 'tenants'],
            ['href' => '/admin/services', 'label' => 'Services', 'active' => $active === 'services'],
            ['href' => '/admin/plans', 'label' => 'Plans', 'active' => $active === 'plans'],
            ['href' => '/admin/client-groups', 'label' => 'Client Groups', 'active' => $active === 'client-groups'],
            ['href' => '/admin/credentials', 'label' => 'Credentials', 'active' => $active === 'credentials'],
            ['href' => '/admin/clients', 'label' => 'Clients', 'active' => $active === 'clients'],
            ['href' => '/admin/sidecars', 'label' => 'Sidecar Points', 'active' => $active === 'sidecars'],
            ['href' => '/knowledgebase', 'label' => 'Knowledgebase'],
            ['href' => '/admin/node-groups', 'label' => 'Slave Groups', 'active' => $active === 'node-groups'],
            ['href' => '/admin/chains', 'label' => 'Chains', 'active' => $active === 'chains'],
            ['href' => '/admin/nodes', 'label' => 'Slave Nodes', 'active' => $active === 'nodes'],
            ['href' => '/tenant', 'label' => 'Tenant Area'],
        ]);
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

    private function displayThrowableMessage(\Throwable $throwable): string
    {
        if ($throwable instanceof ApiException) {
            $details = $throwable->details();
            $message = trim((string) ($details['message'] ?? ''));
            if ($message !== '') {
                return $message;
            }
        }

        return $throwable->getMessage();
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
