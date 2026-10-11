<?php
declare(strict_types=1);

namespace Leikwan\Master\Controllers;

use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;
use Leikwan\Master\Support\AndroidEnrollmentCodeService;
use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\AccessPolicyService;
use Leikwan\Master\Support\DebugCollectionStore;
use Leikwan\Master\Support\ClientOnlineSessionService;
use Leikwan\Master\Support\DeviceControlService;
use Leikwan\Master\Support\ForwardLineApiContract;
use Leikwan\Master\Support\PrecisionGridUi;
use Leikwan\Master\Support\ProvisioningService;
use Leikwan\Master\Support\ServiceStatus;
use Leikwan\Master\Support\SessionAuth;
use Leikwan\Master\Support\UltraLeaseService;
use Leikwan\Master\Support\WhmcsIdentityService;

final class TenantController extends BaseController
{
    private AccessPolicyService $accessPolicy;

    public function __construct(
        \Leikwan\Master\Config $config,
        \Leikwan\Master\Database\Database $db,
        private ProvisioningService $provisioning,
        ?AccessPolicyService $accessPolicy = null,
    ) {
        parent::__construct($config, $db);
        $this->accessPolicy = $accessPolicy ?? new AccessPolicyService($db);
    }

    public function introPage(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        $serviceSummary = $this->db->fetchOne(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = :active THEN 1 ELSE 0 END) AS active_total,
                    MAX(expire_at) AS latest_expire_at
             FROM services
             WHERE user_id = :user_id',
            [
                'user_id' => $user['id'],
                'active' => 'active',
            ]
        ) ?? [];
        $clientCount = count($this->provisioning->listClientsForUser((int) $user['id']));
        $activeServiceCount = (int) ($serviceSummary['active_total'] ?? 0);

        $activeServices = $this->db->fetchAll(
            'SELECT id FROM services WHERE user_id = :user_id AND status = :status ORDER BY id ASC',
            ['user_id' => $user['id'], 'status' => 'active']
        );
        $forwardCount = 0;
        if ($activeServices !== []) {
            $serviceIds = array_map(static fn(array $service): int => (int) ($service['id'] ?? 0), $activeServices);
            $serviceIds = array_values(array_filter($serviceIds, static fn(int $id): bool => $id > 0));
            if ($serviceIds !== []) {
                $forwardRow = $this->db->fetchOne(
                    'SELECT COUNT(*) AS total FROM forwards WHERE service_id IN (' . implode(',', $serviceIds) . ')'
                );
                $forwardCount = (int) ($forwardRow['total'] ?? 0);
            }
        }

        $hasPackage = $activeServiceCount > 0;
        $hasForward = $forwardCount > 0;
        $hasClient = $clientCount > 0;
        $setupComplete = $hasPackage && $hasForward && $hasClient;
        $currentStep = !$hasPackage ? 1 : (!$hasForward ? 2 : (!$hasClient ? 3 : 0));

        $stepState = static function (int $step, int $currentStep, bool $done): string {
            if ($done) {
                return 'done';
            }
            if ($currentStep === $step) {
                return 'current';
            }
            return 'todo';
        };

        $stepBadge = static function (string $state): string {
            return match ($state) {
                'done' => '<span class="badge success">Done</span>',
                'current' => '<span class="badge info">In progress</span>',
                default => '<span class="badge">To do</span>',
            };
        };

        $step1State = $stepState(1, $currentStep, $hasPackage);
        $step2State = $stepState(2, $currentStep, $hasForward);
        $step3State = $stepState(3, $currentStep, $hasClient);

        $continueHref = match ($currentStep) {
            1 => '/tenant/plans',
            2 => $activeServices !== []
                ? '/tenant/services/' . (int) ($activeServices[0]['id'] ?? 0) . '/forward-wizard'
                : '/tenant/forwards',
            3 => '/tenant/clients',
            default => '/tenant/forwards',
        };
        $continueLabel = match ($currentStep) {
            1 => 'Buy a package',
            2 => 'Create a forward',
            3 => 'Install client',
            default => 'Manage forwards',
        };

        $step = static fn(string $state, string $title, string $hint): string => '<li class="console-step" data-state="' . $state . '">'
            . '<div class="console-step-head"><strong>' . $title . '</strong>' . $stepBadge($state) . '</div>'
            . '<div class="muted">' . $hint . '</div></li>';

        $onboardingCard = $setupComplete
            ? '<section class="card">
                <h3>Setup complete</h3>
                <p class="muted">Your package, forward, and client path are ready. Open forwards to manage day-to-day traffic.</p>
                <div class="actions">
                  <a class="button" href="/tenant/forwards">Open Forwards</a>
                  <a class="button ghost" href="/tenant/getting-started">Review setup guide</a>
                </div>
              </section>'
            : '<section class="card">
                <h3>Get connected in 3 steps</h3>
                <ol class="console-steps">'
                . $step($step1State, '1. Buy a package', 'Activate a service plan before routing traffic.')
                . $step($step2State, '2. Create a forward', 'One forward with defaults is enough to get online.')
                . $step($step3State, '3. Install client', 'Download the desktop or mobile client and sign in.')
                . '</ol>
                <div class="actions">
                  <a class="button" href="' . $this->escape($continueHref) . '">' . $this->escape($continueLabel) . '</a>
                  <a class="button ghost" href="/tenant/getting-started">Open setup guide</a>
                </div>
              </section>';

        $latestService = $this->db->fetchOne(
            'SELECT id, name, status, expire_at
             FROM services
             WHERE user_id = :user_id
             ORDER BY id DESC
             LIMIT 1',
            ['user_id' => $user['id']]
        );

        $latestServiceCard = $latestService !== null
            ? '<section class="card">
                <div class="console-service-head">
                  <h3>Latest package</h3>
                  ' . $this->tenantServiceStatusBadge($latestService) . '
                </div>
                <dl class="console-kv">
                  <dt>Package name</dt><dd>' . $this->escape((string) ($latestService['name'] ?? 'Service')) . '</dd>
                  <dt>Expires</dt><dd>' . $this->escape((string) ($latestService['expire_at'] ?? '-')) . '</dd>
                </dl>
                <div class="actions">
                  <a class="button" href="/tenant/services/' . (int) ($latestService['id'] ?? 0) . '">Open details</a>
                  <a class="button ghost" href="/tenant/forwards">View all forwards</a>
                </div>
              </section>'
            : '<section class="card">
                <h3>Latest package</h3>
                <p class="muted">No active package yet. Buy a package first, then create a forward rule.</p>
                <div class="actions">
                  <a class="button" href="/tenant/plans">View packages</a>
                  <a class="button ghost" href="/tenant/account">Open account</a>
                </div>
              </section>';

        $walletCurrency = strtoupper(trim((string) ($user['wallet_currency'] ?? 'CNY')));
        $walletBalance = number_format((float) ($user['wallet_balance'] ?? 0), 2);
        $statCard = static fn(string $href, string $label, string $value): string => '<a class="card console-stat" href="' . $href . '">'
            . '<span class="muted">' . $label . '</span><span class="stats">' . $value . '</span></a>';

        $body = '
            <div class="console-welcome">
              <div>
                <h2>Welcome back</h2>
                <p>Check account and package status, then create a forward and install a client.</p>
              </div>
              <div class="actions">
                <a class="button" href="' . $this->escape($continueHref) . '">' . $this->escape($continueLabel) . '</a>
              </div>
            </div>
            <div class="grid four">
              ' . $statCard('/tenant/forwards', 'Active packages', (string) $activeServiceCount) . '
              ' . $statCard('/tenant/forwards', 'Forwards', (string) $forwardCount) . '
              ' . $statCard('/tenant/clients', 'Clients', (string) $clientCount) . '
              ' . $statCard('/tenant/account', 'Account balance', $this->escape($walletCurrency . ' ' . $walletBalance)) . '
            </div>
            <div style="margin-top:16px;">' . $onboardingCard . '</div>
            <div class="grid two" style="margin-top:16px;">
              ' . $latestServiceCard . '
              <section class="card">
                <h3>Need help?</h3>
                <ul class="console-links">
                  <li><a href="/knowledgebase?thread=getting-started-five-minute-overview">Read the five-minute overview</a></li>
                  <li><a href="/knowledgebase?thread=install-client">Read the installation guide</a></li>
                  <li><a href="/knowledgebase?thread=connect-and-verify">Read the connection check</a></li>
                  <li><a href="/knowledgebase">Open knowledgebase</a></li>
                </ul>
              </section>
            </div>';

        return $this->page('Overview', $body, $user, $this->tenantNav('intro'));
    }

    public function gettingStartedPage(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        $activeServices = $this->db->fetchAll(
            'SELECT * FROM services WHERE user_id = :user_id AND status = :status ORDER BY id ASC',
            ['user_id' => $user['id'], 'status' => 'active']
        );

        if ($activeServices === []) {
            $body = '
                <div class="hero">
                  <div>
                    <h2>Getting Started - Step 1: Get a Service</h2>
                    <p class="muted">No active package yet. Choose a package first so you have a service to configure.</p>
                  </div>
                </div>
                <section class="card">
                  <h3>Choose a package</h3>
                  <p>Open Packages and activate the plan that fits this device or location.</p>
                  <div class="actions">
                    <a class="button" href="/tenant/plans">View packages</a>
                    <a class="button ghost" href="/knowledgebase?thread=getting-started-five-minute-overview">Read the five-minute overview</a>
                  </div>
                </section>
            ';
        } else {
            $serviceIds = array_map(static fn(array $service): int => (int) ($service['id'] ?? 0), $activeServices);
            $serviceIds = array_values(array_filter($serviceIds, static fn(int $id): bool => $id > 0));
            $forwards = $serviceIds === []
                ? []
                : $this->db->fetchAll(
                    'SELECT * FROM forwards WHERE service_id IN (' . implode(',', $serviceIds) . ')'
                );

            if ($forwards === []) {
                $firstServiceId = (int) ($activeServices[0]['id'] ?? 0);
                $body = '
                    <div class="hero">
                      <div>
                        <h2>Getting Started - Step 2: Add a Forward</h2>
                        <p class="muted">Your package is active. Create your first forward to choose where traffic should go.</p>
                      </div>
                    </div>
                    <section class="card">
                      <h3>Create your first forward</h3>
                      <p>Use the guided form and start with the recommended defaults.</p>
                      <div class="actions">
                        <a class="button" href="/tenant/services/' . $firstServiceId . '/forward-wizard">Create a forward</a>
                        <a class="button ghost" href="/knowledgebase?thread=getting-started-first-forward-checklist">Open the first-forward checklist</a>
                      </div>
                    </section>
                ';
            } else {
                $clientCount = (int) ($this->db->fetchOne(
                    'SELECT COUNT(*) AS total FROM clients WHERE user_id = :user_id',
                    ['user_id' => $user['id']]
                )['total'] ?? 0);
                if ($clientCount === 0) {
                    $body = '
                        <div class="hero">
                          <div>
                            <h2>Getting Started - Step 3: Install Client</h2>
                            <p class="muted">Your forward is ready. Install the client on this device to start using it.</p>
                          </div>
                        </div>
                        <section class="card">
                          <h3>Install the client</h3>
                          <p>Open Clients to download the app or copy the Linux installation command.</p>
                          <div class="actions">
                            <a class="button" href="/tenant/clients">Open client downloads</a>
                            <a class="button ghost" href="/knowledgebase?thread=install-client">Read the installation guide</a>
                          </div>
                        </section>
                    ';
                } else {
                    $body = '
                        <div class="hero">
                          <div>
                            <h2>Getting Started - Setup Complete</h2>
                            <p class="muted">Your package, forward, and client are ready.</p>
                          </div>
                        </div>
                        <section class="card">
                          <h3>Connect and verify</h3>
                          <p>Open Forwards to manage traffic, then use the verification guide to confirm the complete path.</p>
                          <div class="actions">
                            <a class="button" href="/tenant/forwards">Open forwards</a>
                            <a class="button ghost" href="/knowledgebase?thread=connect-and-verify">Read the connection check</a>
                          </div>
                        </section>
                    ';
                }
            }
        }

        return $this->page('Getting Started', $body, $user, $this->tenantNav('intro'));
    }


    public function dashboard(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        $services = $this->db->fetchAll(
            'SELECT s.id, s.name, s.status, s.expire_at, s.bandwidth_limit_mbps, s.traffic_limit_gb, s.traffic_used_bytes, s.whmcs_service_id, p.name AS plan_name
             FROM services s
             LEFT JOIN service_plans p ON p.id = s.plan_id
             WHERE s.user_id = :user_id
             ORDER BY s.id DESC',
            ['user_id' => $user['id']]
        );

        $forwardCounts = [];
        if ($services !== []) {
            $serviceIds = implode(',', array_map(static fn(array $row): int => (int) $row['id'], $services));
            foreach ($this->db->fetchAll(
                'SELECT service_id, COUNT(*) AS cnt
                 FROM forwards
                 WHERE service_id IN (' . $serviceIds . ') AND enabled = 1 AND user_deleted_at IS NULL
                 GROUP BY service_id'
            ) as $row) {
                $forwardCounts[(int) $row['service_id']] = (int) $row['cnt'];
            }
        }

        $totalUsedBytes = 0;
        $totalLimitBytes = 0;
        $serviceCards = '';
        foreach ($services as $service) {
            $billing = $this->provisioning->serviceBillingOverview((int) $service['id']);
            $trafficUsedBytes = max(0, (int) ($billing['current_cycle_used_bytes'] ?? 0));
            $trafficLimitGb = ((string) ($billing['mode'] ?? 'monthly_bundle') === 'monthly_bundle')
                ? max(0, (int) ($billing['included_traffic_gb'] ?? 0))
                : 0;
            $trafficLimitBytes = $trafficLimitGb > 0 ? $trafficLimitGb * 1024 * 1024 * 1024 : 0;
            $trafficRemainingBytes = ((string) ($billing['mode'] ?? 'monthly_bundle') === 'monthly_bundle')
                ? max(0, $trafficLimitBytes - $trafficUsedBytes)
                : null;
            $pct = $trafficLimitBytes > 0 ? min(100, (int) round($trafficUsedBytes / $trafficLimitBytes * 100)) : 0;
            $totalUsedBytes += $trafficUsedBytes;
            $totalLimitBytes += $trafficLimitBytes;
            $forwardCount = (int) ($forwardCounts[(int) $service['id']] ?? 0);

            $usageText = $trafficRemainingBytes !== null
                ? $this->formatTrafficBytes($trafficUsedBytes) . ' / ' . $this->formatTrafficBytes($trafficLimitBytes)
                : $this->formatTrafficBytes($trafficUsedBytes) . ' · <span>pay as you go</span>';

            $serviceCards .= '<section class="card">
                <div class="console-service-head">
                  <div>
                    <h3>' . $this->escape((string) $service['name']) . '</h3>
                    <div class="muted">' . $this->escape((string) ($service['plan_name'] ?? '—')) . '</div>
                  </div>
                  ' . $this->tenantServiceStatusBadge($service) . '
                </div>
                <div class="console-service-meta">
                  <span><span>Expires</span> ' . $this->escape((string) $service['expire_at']) . '</span>
                  <span><span>Bandwidth</span> ' . (int) $service['bandwidth_limit_mbps'] . ' Mbps</span>
                  <span><span>Forwards</span> ' . $forwardCount . '</span>
                </div>
                <div class="muted" style="margin-top:12px;">Traffic used this cycle</div>
                <div class="console-progress">
                  <div class="console-progress-track"><div class="console-progress-fill" style="width:' . $pct . '%;"></div></div>
                  <span>' . $usageText . '</span>
                </div>
                <div class="actions">
                  <a class="button" href="/tenant/services/' . (int) $service['id'] . '">Manage</a>
                  <a class="button ghost" href="/tenant/services/' . (int) $service['id'] . '/create-forward">New forward</a>
                </div>
              </section>';
        }

        $emptyState = $services === []
            ? '<section class="card">
                <h3>No Service Yet</h3>
                <p class="muted">There is nothing to configure yet because no service has been activated for this tenant.</p>
                <div class="actions"><a class="button" href="/tenant/plans">View packages</a></div>
              </section>'
            : '';

        $totalRemainingBytes = max(0, $totalLimitBytes - $totalUsedBytes);
        $body = '
            <div class="hero">
              <div>
                <p class="muted">One package is one service. All forwards under it share its traffic and billing. Open Manage to view or change them.</p>
              </div>
              <div class="actions">
                <a class="button ghost" href="/tenant/plans">Buy Packages</a>
              </div>
            </div>
            <div class="grid three">
              <section class="card"><div class="muted">Packages</div><div class="stats">' . count($services) . '</div></section>
              <section class="card"><div class="muted">Used this cycle</div><div class="stats">' . $this->escape($this->formatTrafficBytes($totalUsedBytes)) . '</div></section>
              <section class="card"><div class="muted">Remaining this cycle</div><div class="stats">' . $this->escape($this->formatTrafficBytes($totalRemainingBytes)) . '</div></section>
            </div>
            ' . ($emptyState !== '' ? '<div style="margin-top:16px;">' . $emptyState . '</div>' : '') . '
            <div class="grid two" style="margin-top:16px;">
              ' . $serviceCards . '
            </div>';

        return $this->page('My Services', $body, $user, $this->tenantNav('forwards'));
    }

    public function billingPage(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        $service = $this->whmcsIdentityService();
        $buyLoginEnabled = $service->isConfigured();
        $snapshot = [
            'user' => $user,
            'linked' => !empty($user['whmcs_client_id']),
            'remote' => null,
            'transactions' => $this->db->fetchAll(
                'SELECT * FROM wallet_transactions WHERE user_id = :user_id ORDER BY id DESC LIMIT 20',
                ['user_id' => $user['id']]
            ),
        ];
        $syncError = '';

        try {
            if ($buyLoginEnabled || empty($user['whmcs_client_id'])) {
                $snapshot = $service->accountSnapshot((int) $user['id']);
            } elseif (!empty($user['whmcs_client_id'])) {
                $syncError = 'WHMCS connection is not configured on this panel right now.';
            }
        } catch (\Throwable $throwable) {
            $syncError = $throwable->getMessage();
        }

        $user = is_array($snapshot['user'] ?? null) ? $snapshot['user'] : $user;
        $remote = is_array($snapshot['remote'] ?? null) ? $snapshot['remote'] : null;
        $transactions = is_array($snapshot['transactions'] ?? null) ? $snapshot['transactions'] : [];
        $linked = !empty($snapshot['linked']);
        $localCurrency = strtoupper(trim((string) ($user['wallet_currency'] ?? 'CNY')));
        $localBalance = (float) ($user['wallet_balance'] ?? 0);
        $balanceClass = 'success';
        $balanceLabel = 'Balance OK';
        if ($localBalance <= 0) {
            $balanceClass = 'danger';
            $balanceLabel = 'Suspended';
        } elseif ($localBalance <= (float) ($user['wallet_low_balance_threshold'] ?? 5.00)) {
            $balanceClass = 'warning';
            $balanceLabel = 'Low balance';
        }
        $remoteBalance = $remote !== null ? (float) ($remote['credit'] ?? 0) : 0.0;
        $remoteCurrency = $remote !== null
            ? strtoupper(trim((string) ($remote['currency_code'] ?? $localCurrency)))
            : $localCurrency;
        $stats = $remote !== null && is_array($remote['stats'] ?? null) ? $remote['stats'] : [];
        $products = $remote !== null && is_array($remote['products'] ?? null) ? $remote['products'] : [];
        $purchasePlans = $this->provisioning->listPurchasablePlans();

        $csrf = $this->escape(SessionAuth::csrfToken());

        $syncNotice = $syncError !== ''
            ? '<div class="flash danger">' . $this->escape($syncError) . '</div>'
            : '';

        $bindSection = '';
        if ($remote !== null) {
            $bindSection = '<section class="card">
                <h3>Bound Buy Station Account</h3>
                <div class="grid two">
                  <div>
                    <div><span class="muted">Display Name:</span> ' . $this->escape((string) ($remote['full_name'] ?? '')) . '</div>
                    <div><span class="muted">Email:</span> ' . $this->escape((string) ($remote['email'] ?? '')) . '</div>
                    <div><span class="muted">Client ID:</span> ' . (int) ($remote['whmcs_client_id'] ?? 0) . '</div>
                  </div>
                  <div>
                    <div><span class="muted">Currency:</span> ' . $this->escape($remoteCurrency) . '</div>
                    <div><span class="muted">Buy Balance:</span> ' . $this->escape(number_format($remoteBalance, 2)) . '</div>
                    <div><span class="muted">Last Sync:</span> ' . $this->escape((string) ($user['whmcs_last_synced_at'] ?? '')) . '</div>
                  </div>
                </div>
                <div class="muted" style="margin-top:14px;">Your Buy station account is already linked. You can now read Buy station data and transfer balance in both directions below.</div>
              </section>';
        } elseif ($linked) {
            $bindSection = '<section class="card">
                <h3>Bound Buy Station Account</h3>
                <div class="grid two">
                  <div>
                    <div><span class="muted">Bound Email:</span> ' . $this->escape((string) ($user['whmcs_email'] ?? $user['email'] ?? '')) . '</div>
                    <div><span class="muted">Client ID:</span> ' . (int) ($user['whmcs_client_id'] ?? 0) . '</div>
                  </div>
                  <div>
                    <div><span class="muted">Currency:</span> ' . $this->escape(strtoupper(trim((string) ($user['whmcs_currency_code'] ?? $localCurrency)))) . '</div>
                    <div><span class="muted">Last Sync:</span> ' . $this->escape((string) ($user['whmcs_last_synced_at'] ?? '')) . '</div>
                  </div>
                </div>
                <div class="muted" style="margin-top:14px;">This account is already linked, but the live Buy station data could not be refreshed right now.</div>
              </section>';
        } elseif ($buyLoginEnabled) {
            $bindSection = '<section class="card">
                <h3>Bind Buy Station Account</h3>
                <p class="muted">If your panel email matches the Buy station email, binding will happen immediately. If your Buy station uses a different email, enter that email below and we will create a verification ticket with a code for you to confirm.</p>
                <form method="post" action="/tenant/account/bind">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <div class="form-grid">
                    <div class="field"><label>Buy Station Email</label><input type="email" name="whmcs_email" value="' . $this->escape((string) ($user['email'] ?? '')) . '" placeholder="you@example.com" required><p class="muted">Use the same email for direct binding. Use another Buy station email only when your billing account uses a different mailbox.</p></div>
                  </div>
                  <div class="actions">
                    <button class="button" type="submit">Bind Buy Station Account</button>
                  </div>
                </form>
              </section>';
        } else {
            $bindSection = '<section class="card">
                <h3>Bind Buy Station Account</h3>
                <p class="muted">WHMCS is not configured on this panel right now, so Buy station binding is temporarily unavailable.</p>
              </section>';
        }

        $transferSection = $remote !== null
            ? '<section class="card">
                <h3>Transfer Balance</h3>
                <p class="muted">Local panel balance and Buy station credit can be moved in both directions. Transfers are recorded in the transaction history below.</p>
                <form method="post" action="/tenant/account/transfer">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <div class="form-grid">
                    <div class="field">
                      <label>Direction</label>
                      <select name="direction">
                        <option value="panel_to_whmcs">Panel -> Buy station</option>
                        <option value="whmcs_to_panel">Buy station -> Panel</option>
                      </select>
                    </div>
                    <div class="field">
                      <label>Amount</label>
                      <input type="number" step="0.01" min="0.01" name="amount" placeholder="100.00" required>
                    </div>
                  </div>
                  <div class="actions">
                    <button class="button" type="submit">Transfer Balance</button>
                  </div>
                </form>
              </section>'
            : ($linked
                ? '<section class="card">
                    <h3>Transfer Balance</h3>
                    <p class="muted">This account is already linked, but live Buy station balance could not be read just now. Please retry after the WHMCS connection recovers.</p>
                  </section>'
            : '<section class="card">
                <h3>Transfer Balance</h3>
                <p class="muted">Bind your Buy station account first, and the balance transfer form will appear here.</p>
              </section>');

        $productRows = '';
        foreach ($products as $product) {
            if (!is_array($product)) {
                continue;
            }
            $productRows .= '<tr>
                <td>' . (int) ($product['id'] ?? 0) . '</td>
                <td>' . $this->escape((string) ($product['name'] ?? $product['productname'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($product['status'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($product['billingcycle'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($product['nextduedate'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($product['recurringamount'] ?? $product['amount'] ?? '')) . '</td>
              </tr>';
        }
        if ($productRows === '') {
            $productRows = '<tr><td colspan="6" class="muted">No Buy station products were returned.</td></tr>';
        }

        $purchasePlanOptions = '<option value="">Select a package</option>';
        $purchasePlanRows = '';
        foreach ($purchasePlans as $plan) {
            if (!is_array($plan)) {
                continue;
            }

            $planCurrency = strtoupper(trim((string) ($plan['billing_currency'] ?? 'CNY')));
            $planPrice = number_format((float) ($plan['monthly_base_fee'] ?? 0), 2);
            $resetPrice = number_format((float) ($plan['traffic_reset_price'] ?? 0), 2);
            $bandwidthStepPrice = number_format((float) ($plan['bandwidth_step_price'] ?? 0), 2);
            $purchasePlanOptions .= '<option value="' . (int) ($plan['id'] ?? 0) . '">'
                . $this->escape((string) ($plan['name'] ?? 'Plan'))
                . ' / ' . $this->escape($planCurrency) . ' ' . $this->escape($planPrice)
                . '</option>';
            $purchasePlanRows .= '<tr>
                <td>' . $this->escape((string) ($plan['name'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($plan['description'] ?? '')) . '</td>
                <td>' . (int) ($plan['default_bandwidth_limit_mbps'] ?? 0) . ' Mbps</td>
                <td>' . (int) ($plan['default_traffic_limit_gb'] ?? 0) . ' GB</td>
                <td>' . $this->escape($planCurrency) . ' ' . $this->escape($planPrice) . '</td>
                <td>+' . (int) ($plan['bandwidth_step_mbps'] ?? 0) . ' Mbps / ' . $this->escape($planCurrency) . ' ' . $this->escape($bandwidthStepPrice) . '</td>
                <td>' . (int) ($plan['included_client_count'] ?? 0) . ' included / + ' . $this->escape($planCurrency) . ' ' . $this->escape(number_format((float) ($plan['extra_client_price'] ?? 0), 2)) . '</td>
                <td>' . $this->escape($planCurrency) . ' ' . $this->escape($resetPrice) . '</td>
              </tr>';
        }
        if ($purchasePlanRows === '') {
            $purchasePlanRows = '<tr><td colspan="8" class="muted">No active package is currently available for purchase.</td></tr>';
        }
        $purchaseDisabled = $purchasePlans === [] ? ' disabled' : '';

        $transactionRows = '';
        foreach ($transactions as $transaction) {
            if (!is_array($transaction)) {
                continue;
            }
            $directionLabel = match ((string) ($transaction['direction'] ?? '')) {
                'panel_to_whmcs' => 'Panel -> Buy',
                'whmcs_to_panel' => 'Buy -> Panel',
                'plan_purchase' => 'Package Purchase',
                'traffic_reset' => 'Traffic Reset',
                default => (string) ($transaction['direction'] ?? 'unknown'),
            };

            $transactionRows .= '<tr>
                <td>' . $this->escape($directionLabel) . '</td>
                <td>' . $this->escape((string) ($transaction['currency'] ?? $localCurrency)) . ' ' . $this->escape(number_format((float) ($transaction['amount'] ?? 0), 2)) . '</td>
                <td>' . $this->escape(number_format((float) ($transaction['panel_balance_before'] ?? 0), 2)) . ' -> ' . $this->escape(number_format((float) ($transaction['panel_balance_after'] ?? 0), 2)) . '</td>
                <td>' . $this->escape(number_format((float) ($transaction['whmcs_balance_before'] ?? 0), 2)) . ' -> ' . $this->escape(number_format((float) ($transaction['whmcs_balance_after'] ?? 0), 2)) . '</td>
                <td>' . $this->escape((string) ($transaction['created_at'] ?? '')) . '</td>
              </tr>';
        }
        if ($transactionRows === '') {
            $transactionRows = '<tr><td colspan="5" class="muted">No balance transfer record yet.</td></tr>';
        }

        $usageBreakdown = $this->buildBillingUsageBreakdown((int) $user['id']);
        $usageRows = '';
        foreach ($usageBreakdown as $row) {
            $usageRows .= '<tr>
                <td>' . $this->escape((string) $row['name']) . '</td>
                <td>' . $this->escape((string) $row['region']) . '</td>
                <td>' . $this->escape($this->formatTrafficBytes((int) $row['bytes'])) . '</td>
                <td>' . $this->escape((string) $row['price_per_gb']) . '/GB</td>
                <td>' . $this->escape((string) $row['currency']) . ' ' . $this->escape(number_format((float) $row['cost'], 2)) . '</td>
            </tr>';
        }
        $usageTable = $usageRows !== '' ? '
            <section class="card" style="margin-top:20px;">
              <h3>This Cycle Usage Breakdown</h3>
              <div class="table-wrap">
                <table>
                  <thead><tr><th>Node</th><th>Region</th><th>Traffic</th><th>Unit Price</th><th>Cost</th></tr></thead>
                  <tbody>' . $usageRows . '</tbody>
                </table>
              </div>
            </section>' : '';

        // Buy-station counters only mean something once the account is linked.
        $remoteStatCards = $remote !== null
            ? '<section class="card"><div class="muted">Active Products</div><div class="stats">' . (int) ($stats['productsnumactive'] ?? 0) . '</div></section>
              <section class="card"><div class="muted">Unpaid Invoices</div><div class="stats">' . (int) ($stats['numunpaidinvoices'] ?? 0) . '</div></section>
              <section class="card"><div class="muted">Open Tickets</div><div class="stats">' . (int) ($stats['numtickets'] ?? 0) . '</div></section>'
            : '';
        $body = '
            <div class="hero">
              <div>
                <p class="muted">Manage account binding, balance and transfer history. Packages are bought on the Buy Packages page.</p>
              </div>
              <div class="actions">
                <a class="button ghost" href="/tenant/plans">Buy Packages</a>
              </div>
            </div>
            ' . $syncNotice . '
            <div class="grid three">
              <section class="card"><div class="console-service-head"><div class="muted">Panel Balance</div><span class="badge ' . $balanceClass . '">' . $this->escape($balanceLabel) . '</span></div><div class="stats">' . $this->escape($localCurrency) . ' ' . $this->escape(number_format($localBalance, 2)) . '</div>'
            . ($localBalance > 0 && $this->hasSuspendedForwards($user) ? '<form method="post" action="/tenant/account/reactivate" style="margin-top:10px;"><input type="hidden" name="csrf_token" value="' . $csrf . '"><button class="button" type="submit" style="width:100%;">Reactivate All Forwards</button></form>' : '') . '</section>
              <section class="card"><div class="muted">Buy Balance</div><div class="stats">' . ($remote !== null ? $this->escape($remoteCurrency) . ' ' . $this->escape(number_format($remoteBalance, 2)) : '-') . '</div></section>
              <section class="card"><div class="muted">Binding Status</div><div class="stats">' . ($linked ? 'Bound' : 'Not Bound') . '</div></section>
              ' . $remoteStatCards . '
            </div>
            <div class="grid two" style="margin-top:20px;">
              ' . $bindSection . '
              ' . $transferSection . '
            </div>
            ' . $usageTable . '
            ' . ($remote !== null ? '<section class="card" style="margin-top:16px;">
              <h3>Buy Station Products</h3>
              <div class="table-wrap">
                <table>
                  <thead><tr><th>ID</th><th>Name</th><th>Status</th><th>Billing Cycle</th><th>Next Due</th><th>Amount</th></tr></thead>
                  <tbody>' . $productRows . '</tbody>
                </table>
              </div>
            </section>' : '') . '
            <section class="card" style="margin-top:16px;">
              <h3>Balance Transfer History</h3>
              <div class="table-wrap">
                <table>
                  <thead><tr><th>Direction</th><th>Amount</th><th>Panel Balance</th><th>Buy Balance</th><th>Created At</th></tr></thead>
                  <tbody>' . $transactionRows . '</tbody>
                </table>
              </div>
            </section>';

        return $this->page('Balance & Billing', $body, $user, $this->tenantNav('account'));
    }

    public function plansPage(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        $expandedPlanId = max(0, (int) $request->input('buy', 0));
        $services = $this->db->fetchAll(
            'SELECT s.id, s.name, s.status, s.expire_at, s.bandwidth_limit_mbps, s.whmcs_service_id, p.name AS plan_name
             FROM services s
             LEFT JOIN service_plans p ON p.id = s.plan_id
             WHERE s.user_id = :user_id
             ORDER BY s.id DESC',
            ['user_id' => $user['id']]
        );
        $purchasePlans = $this->provisioning->listPurchasablePlans();
        $csrf = $this->escape(SessionAuth::csrfToken());

        $serviceRows = '';
        $totalEstimatedBilling = 0.0;
        $totalBandwidth = 0;
        foreach ($services as $service) {
            $billing = $this->provisioning->serviceBillingOverview((int) $service['id']);
            $currentBandwidth = (int) ($billing['current_bandwidth_mbps'] ?? $service['bandwidth_limit_mbps'] ?? 0);
            $totalBandwidth += max(0, $currentBandwidth);
            $totalEstimatedBilling += (float) ($billing['estimated_total_amount'] ?? 0);
            $trafficPolicy = ((string) ($billing['mode'] ?? 'monthly_bundle') === 'monthly_bundle')
                ? ((int) ($billing['included_traffic_gb'] ?? 0) . ' GB / <span>month</span>')
                : '<span>metered</span>';

            $serviceRows .= '<tr>
                <td><a href="/tenant/services/' . (int) $service['id'] . '"><strong>' . $this->escape((string) ($service['name'] ?? 'Service')) . '</strong></a></td>
                <td>' . $this->tenantServiceStatusBadge($service) . '</td>
                <td>' . $this->escape((string) ($service['plan_name'] ?? '')) . '</td>
                <td>' . $currentBandwidth . ' Mbps</td>
                <td>' . $trafficPolicy . '</td>
                <td>' . $this->escape((string) ($billing['estimated_total_formatted'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($service['expire_at'] ?? '')) . '</td>
                <td>
                  <div class="actions">
                    <a class="button ghost" href="/tenant/services/' . (int) $service['id'] . '">Details</a>
                    <a class="button" href="/tenant/services/' . (int) $service['id'] . '/forward-wizard">Create Forward</a>
                  </div>
                </td>
              </tr>';
        }
        if ($serviceRows === '') {
            $serviceRows = '<tr><td colspan="8" class="muted">No active package yet.</td></tr>';
        }

        $planCards = '';
        foreach ($purchasePlans as $plan) {
            if (!is_array($plan)) {
                continue;
            }

            $planId = (int) ($plan['id'] ?? 0);
            $isExpanded = $expandedPlanId === $planId;
            $planCurrency = strtoupper(trim((string) ($plan['billing_currency'] ?? 'CNY')));
            $planPrice = number_format((float) ($plan['monthly_base_fee'] ?? 0), 2);
            $resetPrice = number_format((float) ($plan['traffic_reset_price'] ?? 0), 2);
            $includedClients = (int) ($plan['included_client_count'] ?? 0);
            $maxClientProfiles = max(1, (int) ($plan['max_client_profiles'] ?? 1));
            $deviceLimit = max(1, (int) ($plan['default_client_online_device_limit'] ?? 1));
            $inlineForm = $isExpanded
                ? '<form class="storefront-order-form" method="post" action="/tenant/plans/purchase">
                    <input type="hidden" name="csrf_token" value="' . $csrf . '">
                    <input type="hidden" name="plan_id" value="' . $planId . '">
                    <div class="form-grid">
                      <div class="field"><label>Service Name</label><input type="text" name="service_name" placeholder="Optional. Leave empty to auto-generate a name."></div>
                      <div class="field"><label>Bandwidth Mbps</label><input type="number" min="1" name="bandwidth_limit_mbps" placeholder="Optional. Leave empty to use the package base bandwidth."></div>
                    </div>
                    <div class="actions"><button class="button storefront-order" type="submit">Purchase Package</button><a class="button secondary" href="/tenant/plans">Cancel</a></div>
                  </form>'
                : '<div class="storefront-order-actions"><a class="button storefront-order" href="/tenant/plans?buy=' . $planId . '#plan-' . $planId . '">Order Now</a></div>';

            $planCards .= '<article class="storefront-product" id="plan-' . $planId . '">
                <header class="storefront-product-header"><h3>' . $this->escape((string) ($plan['name'] ?? 'Plan')) . '</h3></header>
                <div class="storefront-product-body">
                  <div class="storefront-product-features">
                    ' . $this->tenantPlanFeatureListHtml($plan) . '
                  </div>
                  <div class="storefront-product-price">
                    <div class="muted">Starting from</div>
                    <div class="stats">' . $this->escape($planCurrency) . ' ' . $this->escape($planPrice) . '</div>
                    <div class="muted">Monthly</div>
                    <dl class="console-kv" style="margin-top:10px;text-align:left;">
                      <dt>Base Bandwidth</dt><dd>' . (int) ($plan['default_bandwidth_limit_mbps'] ?? 0) . ' Mbps</dd>
                      <dt>Included Traffic</dt><dd>' . (int) ($plan['default_traffic_limit_gb'] ?? 0) . ' GB</dd>
                      <dt>Traffic Reset</dt><dd>' . $this->escape($planCurrency) . ' ' . $this->escape($resetPrice) . '</dd>
                      <dt>Included Clients</dt><dd>' . $includedClients . '</dd>
                      <dt>Client profile limit</dt><dd>' . $maxClientProfiles . '</dd>
                      <dt>Client Device Limit</dt><dd>' . $deviceLimit . '</dd>
                    </dl>
                    ' . $inlineForm . '
                  </div>
                </div>
              </article>';
        }
        if ($planCards === '') {
            $planCards = '<section class="storefront-empty"><h3>No package available</h3><p class="muted">No visible package is available right now. Contact the administrator if you expected to self-purchase.</p></section>';
        }

        $body = '
            <link rel="stylesheet" href="/assets/storefront.css?v=20260727a">
            <div class="hero">
              <div>
                <p class="muted">Use this storefront to compare packages first, then open an inline order form to set service name and bandwidth before purchase.</p>
              </div>
              <div class="actions">
                <a class="button ghost" href="/tenant/forwards">My Services</a>
              </div>
            </div>
            <div class="grid four">
              <section class="card"><div class="muted">Current Services</div><div class="stats">' . count($services) . '</div></section>
              <section class="card"><div class="muted">Visible Packages</div><div class="stats">' . count($purchasePlans) . '</div></section>
              <section class="card"><div class="muted">Total Bandwidth</div><div class="stats">' . $totalBandwidth . ' Mbps</div></section>
              <section class="card"><div class="muted">Current Cycle Estimate</div><div class="stats">CNY ' . $this->escape(number_format($totalEstimatedBilling, 2)) . '</div></section>
            </div>
            <section class="storefront-section">
              <div class="storefront-heading">
                <h3>Purchase Package</h3>
                <p class="muted">Choose the package that fits your usage, then open its inline order form to set service name and bandwidth before purchase.</p>
              </div>
              <div class="storefront-shell">
                <nav class="storefront-categories" aria-label="Package categories">
                  <div class="storefront-category-title">Categories</div>
                  <a class="storefront-category active" href="/tenant/plans" aria-current="page">All Packages</a>
                </nav>
                <div class="storefront-products">' . $planCards . '</div>
              </div>
            </section>
            <section class="card storefront-services">
              <h3>Current Services</h3>
              <div class="table-wrap">
                <table>
                  <thead><tr><th>Name</th><th>Status</th><th>Plan</th><th>Current Bandwidth</th><th>Traffic Policy</th><th>Current Bill</th><th>Expire At</th><th>Actions</th></tr></thead>
                  <tbody>' . $serviceRows . '</tbody>
                </table>
              </div>
            </section>';

        return $this->page('Buy Packages', $body, $user, $this->tenantNav('plans'));
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function tenantPlanFeatureListHtml(array $plan): string
    {
        $description = trim((string) ($plan['description'] ?? ''));
        $lines = preg_split('/\r\n|\r|\n/', $description) ?: [];
        $items = [];
        foreach ($lines as $line) {
            $trimmed = trim((string) $line);
            if ($trimmed !== '') {
                $items[] = '<li>' . $this->escape($trimmed) . '</li>';
            }
        }

        if ($items === []) {
            return '<p class="muted">No package details provided yet.</p>';
        }

        return '<ul class="list">' . implode('', $items) . '</ul>';
    }

    public function transferBalance(Request $request, array $params): Response    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $this->whmcsIdentityService()->transferBalance(
                (int) $user['id'],
                (string) $request->input('direction', ''),
                $request->input('amount', '0')
            );
            SessionAuth::flash('success', 'Balance transfer completed successfully.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Balance transfer failed: ' . $throwable->getMessage());
        }

        return Response::redirect('/tenant/account');
    }

    public function reactivateForwards(Request $request, array $params): Response
    {
        try {
            $user = SessionAuth::currentUser($this->db);
            if ($user === null || (string) $user['role'] !== 'tenant') {
                return Response::redirect('/login');
            }

            SessionAuth::validateCsrf($request);
            $balance = (float) ($user['wallet_balance'] ?? 0);
            if ($balance <= 0) {
                SessionAuth::flash('error', 'A positive balance is required before reactivation.');
                return Response::redirect('/tenant/account');
            }

            $reactivated = $this->provisioning->reactivateEligibleForwardsForUser(
                (int) $user['id'],
                max(1, $this->config->getInt('USER_FORWARD_LIMIT_PER_SERVICE', 64))
            );
            $this->db->execute(
                'UPDATE users SET wallet_warning_sent = 0 WHERE id = :id',
                ['id' => (int) $user['id']]
            );
            SessionAuth::flash(
                'success',
                $reactivated > 0 ? 'Eligible forwards have been reactivated.' : 'No eligible forwards required reactivation.'
            );
        } catch (\Throwable $throwable) {
            error_log('tenant forward reactivation failed: ' . $throwable::class);
            SessionAuth::flash('error', 'Forward reactivation could not be completed.');
        }

        return Response::redirect('/tenant/account');
    }

    public function purchasePackage(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $service = $this->provisioning->purchaseFlexibleService(
                (int) $user['id'],
                (int) $request->input('plan_id', 0),
                (int) $request->input('bandwidth_limit_mbps', 0),
                (string) $request->input('service_name', '')
            );
            SessionAuth::flash('success', 'Package purchased successfully. Your new service is ready.');
            return Response::redirect('/tenant/services/' . (int) ($service['id'] ?? 0));
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Package purchase failed: ' . $throwable->getMessage());
            return Response::redirect('/tenant/plans?buy=' . (int) $request->input('plan_id', 0) . '#plan-' . (int) $request->input('plan_id', 0));
        }
    }

    public function resetTraffic(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        $serviceId = $this->idParam($params);
        try {
            SessionAuth::validateCsrf($request);
            $this->provisioning->resetServiceTraffic((int) $user['id'], $serviceId);
            SessionAuth::flash('success', 'Service traffic has been reset for the current billing cycle.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Traffic reset failed: ' . $throwable->getMessage());
        }

        return Response::redirect('/tenant/services/' . $serviceId);
    }

    public function updateFlexibleOptions(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        $serviceId = $this->idParam($params);
        try {
            SessionAuth::validateCsrf($request);
            $this->provisioning->updateFlexibleServiceOptions(
                (int) $user['id'],
                $serviceId,
                (int) $request->input('bandwidth_limit_mbps', 0)
            );
            SessionAuth::flash('success', 'Flexible package options updated successfully.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Flexible package update failed: ' . $throwable->getMessage());
        }

        return Response::redirect('/tenant/services/' . $serviceId);
    }

    public function accessSecurityPage(Request $request, array $params): Response
    {
        $user = $this->tenantUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        $userId = (int) $user['id'];
        $profile = $this->accessPolicy->tenantProfile($userId) ?? [
            'state' => 'unbound',
            'main_province_code' => null,
            'previous_province_code' => null,
            'last_verified_at' => null,
            'self_change_available_at' => null,
            'reset_generation' => 0,
            'reset_started_at' => null,
        ];
        $devices = $this->accessPolicy->tenantDeviceRows($userId);
        $csrf = $this->escape(SessionAuth::csrfToken());
        $mainProvince = trim((string) ($profile['main_province_code'] ?? ''));
        $availableAt = trim((string) ($profile['self_change_available_at'] ?? ''));
        $geoEnabled = $this->accessPolicy->effectiveGeoLockForUser($userId);

        $options = '<option value="">Select province</option>';
        foreach ($this->accessPolicy->regions()->allProvinces() as $province) {
            $selected = $province === $mainProvince ? ' selected' : '';
            $options .= '<option value="' . $this->escape($province) . '"' . $selected . '>'
                . $this->escape($province) . '</option>';
        }

        $deviceRows = '';
        foreach ($devices as $device) {
            $clientId = (int) ($device['client_id'] ?? 0);
            $deviceUuid = trim((string) ($device['device_uuid'] ?? ''));
            if ($clientId <= 0 || $deviceUuid === '') {
                continue;
            }
            $origin = trim((string) ($device['block_origin'] ?? ''));
            $releasePolicy = trim((string) ($device['release_policy'] ?? ''));
            $blockedUntil = trim((string) ($device['blocked_until'] ?? ''));
            $canRestore = $blockedUntil !== ''
                && $origin === 'manual_tenant'
                && $releasePolicy === 'tenant_or_admin';
            $restore = $canRestore
                ? '<form class="inline" method="post" action="/tenant/security/devices/restore">'
                    . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                    . '<input type="hidden" name="client_id" value="' . $clientId . '">'
                    . '<input type="hidden" name="device_uuid" value="' . $this->escape($deviceUuid) . '">'
                    . '<button class="button ghost" type="submit">Restore</button></form>'
                : '<span class="muted">Admin release</span>';
            $deviceRows .= '<tr><td>' . $this->escape((string) ($device['client_name'] ?? $clientId)) . '</td>'
                . '<td><code>' . $this->escape($deviceUuid) . '</code></td>'
                . '<td>' . $this->escape((string) ($device['platform'] ?? '')) . '</td>'
                . '<td>' . $this->escape((string) ($device['state'] ?? 'active')) . '</td>'
                . '<td>' . $this->escape($origin !== '' ? $origin : '-') . '</td>'
                . '<td>' . $this->escape($blockedUntil !== '' ? $blockedUntil : '-') . '</td>'
                . '<td>' . $restore . '</td></tr>';
        }
        if ($deviceRows === '') {
            $deviceRows = '<tr><td colspan="7" class="muted">No registered devices.</td></tr>';
        }

        $profileState = match ((string) ($profile['state'] ?? 'unbound')) {
            'unbound' => 'Not bound',
            'bound' => 'Bound',
            'pending' => 'Pending',
            'reset_pending' => 'Reset pending',
            default => (string) ($profile['state'] ?? ''),
        };
        $body = '<div class="hero"><div>'
            . '<p class="muted">Review location binding and control devices for this account.</p></div></div>'
            . '<section class="card"><h3>Location binding</h3>'
            . '<dl class="console-kv">'
            . '<dt>Geo lock</dt><dd>' . ($geoEnabled ? '<span class="badge success">Enabled</span>' : '<span class="badge">Disabled</span>') . '</dd>'
            . '<dt>Binding state</dt><dd>' . $this->escape($profileState) . '</dd>'
            . '<dt>Main province</dt><dd>' . $this->escape($mainProvince !== '' ? $mainProvince : 'Not bound') . '</dd>'
            . '<dt>Last verified</dt><dd>' . $this->escape((string) ($profile['last_verified_at'] ?? 'Not verified')) . '</dd>'
            . '<dt>Next self-change</dt><dd>' . $this->escape($availableAt !== '' ? $availableAt : 'Available now') . '</dd>'
            . '</dl>'
            . '<form method="post" action="/tenant/security/main-province"><input type="hidden" name="csrf_token" value="' . $csrf . '">'
            . '<div class="form-grid"><div class="field"><label for="main-province">Main province</label>'
            . '<select id="main-province" name="province" required>' . $options . '</select></div>'
            . '</div><div class="actions"><button class="button" type="submit">Change main province</button></div></form></section>'
            . $this->renderApiTokenCard(trim((string) ($user['api_token'] ?? '')), $csrf)
            . '<section class="card"><div class="hero"><div><h3>Devices</h3>'
            . '<p class="muted">Kick all devices to reset application sessions. Your web Panel session stays active.</p></div>'
            . '<form method="post" action="/tenant/security/kick-all" data-confirm="Confirm kicking all application devices" onsubmit="return confirm(this.dataset.confirm);">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
            . '<button class="button danger" type="submit">Kick all devices</button></form></div>'
            . '<div class="table-wrap"><table><thead><tr><th>Client</th><th>Device UUID</th><th>Platform</th><th>State</th><th>Block origin</th><th>Blocked until</th><th>Action</th></tr></thead><tbody>'
            . $deviceRows . '</tbody></table></div></section>';

        return $this->page('Account Security', $body, $user, $this->tenantNav('security'));
    }

    private function renderApiTokenCard(string $apiToken, string $csrf): string
    {
        // users.api_token authenticates the Forward Lines API. It is a separate
        // credential from the client Agent Token, which tenants kept pasting
        // into API calls because nothing else on the console showed a token.
        $tokenBlock = $apiToken !== ''
            ? '<details><summary>Show API token</summary><code>' . $this->escape($apiToken) . '</code></details>'
                . '<div class="actions">' . $this->renderCopyButton('Copy API token', $apiToken) . '</div>'
            : '<p class="muted">No API token yet.</p>';
        $resetLabel = $apiToken !== '' ? 'Reset API token' : 'Generate API token';

        return '<section class="card"><h3>API token</h3>'
            . '<p class="muted">Use this token as <code>Authorization: Bearer</code> when calling the Forward Lines API. '
            . 'It is not the Agent Token shown on the Clients page.</p>'
            . $tokenBlock
            . '<div class="actions">'
            . '<form class="inline" method="post" action="/tenant/security/api-token/reset" data-confirm="Reset the API token? Programs using the old token stop working immediately." onsubmit="return confirm(this.dataset.confirm);">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
            . '<button class="button danger" type="submit">' . $resetLabel . '</button></form>'
            . '<a class="button ghost" href="/knowledgebase?thread=forward-lines-api">Read the API guide</a>'
            . '</div></section>';
    }

    public function resetApiToken(Request $request, array $params): Response
    {
        $user = $this->tenantUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $this->db->execute(
                'UPDATE users SET api_token = :token, updated_at = :updated_at WHERE id = :id',
                [
                    'token' => bin2hex(random_bytes(24)),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                    'id' => (int) $user['id'],
                ]
            );
            SessionAuth::flash('success', 'API token reset. Update your programs with the new token.');
        } catch (\Throwable) {
            SessionAuth::flash('error', 'Unable to reset the API token.');
        }
        return Response::redirect('/tenant/security');
    }

    public function changeMainProvince(Request $request, array $params): Response
    {
        $user = $this->tenantUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $province = trim((string) $request->input('province', ''));
            $this->accessPolicy->changeMainProvince((int) $user['id'], $province, (int) $user['id'], false);
            SessionAuth::flash('success', 'Main province updated.');
        } catch (\Throwable) {
            SessionAuth::flash('error', 'Unable to update the main province.');
        }
        return Response::redirect('/tenant/security');
    }

    public function kickAllDevices(Request $request, array $params): Response
    {
        $user = $this->tenantUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $result = $this->accessPolicy->beginAllDeviceReset((int) $user['id'], (int) $user['id']);
            $message = !empty($result['idempotent'])
                ? 'A device reset is already pending.'
                : 'All application devices were kicked out.';
            SessionAuth::flash('success', $message);
        } catch (\Throwable) {
            SessionAuth::flash('error', 'Unable to kick all devices.');
        }
        // Deliberately preserve the authenticated web Panel session.
        return Response::redirect('/tenant/security');
    }

    public function restoreDevice(Request $request, array $params): Response
    {
        $user = $this->tenantUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            [$clientId, $deviceUuid] = $this->ownedTenantDevice($user, $request);
            DeviceControlService::fromConfig($this->config, $this->db)->releaseTemporaryBlock(
                $clientId,
                $deviceUuid,
                (int) $user['id'],
                false,
            );
            SessionAuth::flash('success', 'Device restored.');
        } catch (\Throwable) {
            SessionAuth::flash('error', 'This device cannot be restored by the tenant.');
        }
        return Response::redirect('/tenant/security');
    }

    public function clientsPage(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        $client = $this->provisioning->defaultClientForUser((int) $user['id']);
        if ($client === null) {
            $client = $this->provisioning->ensureDefaultClientForUser((int) $user['id']);
        }

        $clientId = (int) ($client['id'] ?? 0);
        $agentToken = trim((string) ($client['agent_token'] ?? ''));
        if ($clientId <= 0 || $agentToken === '') {
            SessionAuth::flash('error', 'Client install is temporarily unavailable.');
            return Response::redirect('/tenant');
        }

        $installCommand = $this->clientAgentInstallCommand($client);
        $sessionService = new ClientOnlineSessionService($this->db);
        $onlineCount = $sessionService->activeCountForClient($clientId);
        $onlineLimit = max(1, (int) ($client['online_device_limit'] ?? 1));
        $sessions = $sessionService->activeSessionsForClient($clientId);
        $controlRows = DeviceControlService::fromConfig($this->config, $this->db)->controlsForClient($clientId);
        $controlsByDevice = [];
        foreach ($controlRows as $controlRow) {
            $controlDeviceId = trim((string) ($controlRow['device_id'] ?? ''));
            if ($controlDeviceId !== '') {
                $controlsByDevice[$controlDeviceId] = $controlRow;
            }
        }
        $observedDevices = [];
        foreach ($sessions as $session) {
            $observedDevices[(string) ($session['device_id'] ?? '')] = true;
        }
        foreach ($controlRows as $controlRow) {
            $controlDeviceId = trim((string) ($controlRow['device_id'] ?? ''));
            if ($controlDeviceId === '' || isset($observedDevices[$controlDeviceId])) {
                continue;
            }
            $sessions[] = [
                'device_id' => $controlDeviceId,
                'platform' => 'android',
                'last_ip' => '',
                'os_release' => '',
                'client_version' => '',
                'user_agent' => '',
            ];
        }
        $csrf = $this->escape(SessionAuth::csrfToken());
        $resetForm = '<form class="inline" method="post" action="/tenant/clients/reset-token">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
            . '<button class="button ghost" type="submit">重置令牌</button></form>';

        $onlineRows = '';
        foreach ($sessions as $session) {
            $ip = trim((string) ($session['last_ip'] ?? ''));
            $os = trim((string) ($session['os_release'] ?? ''));
            if ($os === '') {
                $platform = trim((string) ($session['platform'] ?? ''));
                $os = ($platform !== '' && $platform !== 'unknown') ? $platform : '';
            }
            $version = trim((string) ($session['client_version'] ?? ''));
            if ($version === '') {
                $ua = trim((string) ($session['user_agent'] ?? ''));
                if (preg_match('/Leikwan-Client-Agent\/([A-Za-z0-9._-]+)/', $ua, $matches) === 1) {
                    $version = $matches[1];
                }
            }
            $deviceId = trim((string) ($session['device_id'] ?? ''));
            $kickForm = '<form class="inline" method="post" action="/tenant/clients/kick" data-confirm="Confirm kicking this client" onsubmit="return confirm(this.dataset.confirm);">'
                . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                . '<input type="hidden" name="client_id" value="' . $clientId . '">'
                . '<input type="hidden" name="device_id" value="' . $this->escape($deviceId) . '">'
                . '<button class="button ghost" type="submit">踢下线</button></form>';
            $control = $controlsByDevice[$deviceId] ?? null;
            $controlState = is_array($control) ? (string) ($control['state'] ?? 'active') : 'online';
            $controlForms = $kickForm;
            if (is_array($control)) {
                $blockedUntil = trim((string) ($control['blocked_until'] ?? ''));
                $tenantCanRestore = $blockedUntil !== ''
                    && (string) ($control['block_origin'] ?? '') === 'manual_tenant'
                    && (string) ($control['release_policy'] ?? '') === 'tenant_or_admin';
                if ($tenantCanRestore) {
                    $controlForms = '<form class="inline" method="post" action="/tenant/security/devices/restore">'
                        . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                        . '<input type="hidden" name="client_id" value="' . $clientId . '">'
                        . '<input type="hidden" name="device_uuid" value="' . $this->escape($deviceId) . '">'
                        . '<button class="button ghost" type="submit">Restore</button></form>';
                } elseif ($controlState === 'banned' || $controlState === 'kicked'
                    || ($blockedUntil !== '' && (string) ($control['release_policy'] ?? '') !== 'tenant_or_admin')) {
                    // Tenant-origin temporary blocks are restorable above;
                    // permanent and policy/admin blocks stay administrator-only.
                    $controlForms = '<span class="muted">Admin release</span>';
                } else {
                    $controlForms .= '<form class="inline" method="post" action="/tenant/clients/ban" data-confirm="Confirm banning this device" onsubmit="return confirm(this.dataset.confirm);">'
                        . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                        . '<input type="hidden" name="client_id" value="' . $clientId . '">'
                        . '<input type="hidden" name="device_id" value="' . $this->escape($deviceId) . '">'
                        . '<button class="button ghost" type="submit">Ban</button></form>';
                }
            }
            $controlForms = '<span class="muted">' . $this->escape($controlState) . '</span>' . $controlForms;
            $onlineRows .= '<tr>'
                . '<td>' . $this->escape($ip !== '' ? $ip : '—') . '</td>'
                . '<td>' . $this->escape($os !== '' ? $os : '—') . '</td>'
                . '<td>' . $this->escape($version !== '' ? $version : '—') . '</td>'
                . '<td class="actions">' . $controlForms . '</td>'
                . '</tr>';
        }

        $onlineList = '<div class="tenant-client-install__online-list">'
            . '<div class="tenant-client-install__online-list-head"><strong>在线设备</strong>'
            . '<span class="muted">' . count($sessions) . ' 台在线</span></div>';
        if ($sessions === []) {
            $onlineList .= '<div class="muted">当前没有设备在线。客户端登录后会显示在这里。</div>';
        } else {
            $onlineList .= '<div class="table-wrap"><table class="table"><thead><tr><th>IP</th><th>系统</th><th>客户端版本</th><th></th></tr></thead><tbody>'
                . $onlineRows . '</tbody></table></div>';
        }
        $onlineList .= '</div>';

        $credentialPanel = '<section class="tenant-client-install__credentials">'
            . '<div><strong>Client ID</strong><code>' . $clientId . '</code>'
            . '<div class="actions">' . $this->renderCopyButton('Copy Client ID', (string) $clientId) . '</div></div>'
            . '<div><strong>Agent Token</strong><code>' . $this->escape($agentToken) . '</code>'
            . '<div class="actions">' . $this->renderCopyButton('Copy Agent Token', $agentToken) . '</div></div>'
            . '<p class="muted" style="grid-column:1 / -1;margin:0;">The Agent Token is only for the client app. The Forward Lines API uses a separate API token: <a href="/tenant/security">Account Security</a></p>'
            . '</section>'
            . '<form method="post" action="/tenant/clients/android-enrollment-code">'
            . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
            . '<button class="button ghost" type="submit">Create enrollment code</button></form>';

        // macOS: App (Apple Silicon) + no-GUI together; Windows + Windows no-GUI together; Docker standalone.
        // All command <pre> blocks are collapsible via <details> so the page stays scannable.
        $macosHeadlessCommand = $this->macosHeadlessInstallCommand($client);
        $windowsHeadlessCommand = $this->windowsHeadlessInstallCommand($client);
        $dockerCommand = $this->dockerClientInstallCommand($client);
        $dockerComposeSnippet = $this->dockerClientComposeInline($client);

        $macosGuiSectionRaw = $this->renderMacClientInstallSection();
        $macosSection = '';
        if ($macosGuiSectionRaw !== '') {
            $macosNoGuiBlock = '<details style="margin-top:12px;"><summary>无界面版 · macOS（launchd 守护进程）</summary>'
                . '<div class="muted" style="margin:8px 0 8px;">适合服务器或不登录桌面的电脑：以 launchd 守护进程运行，与图形版客户端使用同一内核和同一套更新校验。</div>'
                . '<pre class="tenant-client-install__command">' . $this->escape($macosHeadlessCommand) . '</pre>'
                . '<div class="actions">'
                . $this->renderCopyButton('复制 macOS 命令', $macosHeadlessCommand, 'secondary', '已复制', '复制失败')
                . '<a class="button secondary" href="' . $this->escape($this->macosHeadlessInstallerUrl()) . '">macOS 安装脚本</a>'
                . '<a class="button secondary" href="/knowledgebase?thread=no-gui-clients">使用说明</a>'
                . '</div></details>';
            $macosSection = str_replace('</section>', $macosNoGuiBlock . '</section>', $macosGuiSectionRaw);
        } else {
            $macosSection = '<section class="tenant-client-install__platform"><strong>'
                . $this->platformIcon('macos')
                . $this->escape($this->clientAppReleaseLabel('macOS · 无界面版', 'windows_headless_client')) . '</strong>'
                . '<div class="muted">适合服务器或不登录桌面的电脑：以 launchd 守护进程运行，与图形版客户端使用同一内核和同一套更新校验。</div>'
                . '<details open><summary>安装命令</summary><pre class="tenant-client-install__command">' . $this->escape($macosHeadlessCommand) . '</pre>'
                . '<div class="actions">' . $this->renderCopyButton('复制 macOS 命令', $macosHeadlessCommand, 'secondary', '已复制', '复制失败') . '</div></details>'
                . '</section>';
        }

        $windowsSection = '<section class="tenant-client-install__platform"><strong>'
            . $this->platformIcon('windows')
            . $this->escape($this->clientAppReleaseLabel('Windows', 'windows_client_app')) . '</strong>'
            . '<div class="actions">' . $this->renderCommandLink('下载安装包', $this->windowsClientAppUrl(), 'secondary') . '</div>'
            . '<details style="margin-top:10px;"><summary>' . $this->platformIcon('helper') . $this->escape($this->clientAppReleaseLabel('Windows 更新助手', 'windows_update_helper')) . ' · 复制命令</summary>'
            . '<pre class="tenant-client-install__command">' . $this->escape($this->windowsUpdateHelperCommand()) . '</pre>'
            . '<div class="actions">'
            . $this->renderCopyButton('复制命令', $this->windowsUpdateHelperCommand(), 'secondary', '已复制', '复制失败')
            . '<a class="button secondary" href="' . $this->escape($this->windowsUpdateHelperUrl()) . '">下载助手</a>'
            . '</div></details>'
            . '<details style="margin-top:10px;"><summary>' . $this->platformIcon('helper') . '无界面版 · Windows 系统服务</summary>'
            . '<div class="muted" style="margin:8px 0 8px;">适合服务器或不登录桌面的电脑：以 Windows 系统服务运行，与图形版客户端使用同一内核和同一套更新校验。</div>'
            . '<pre class="tenant-client-install__command">' . $this->escape($windowsHeadlessCommand) . '</pre>'
            . '<div class="actions">'
            . $this->renderCopyButton('复制 Windows 命令', $windowsHeadlessCommand, 'secondary', '已复制', '复制失败')
            . $this->renderCommandLink('下载 Windows 无界面版', $this->windowsHeadlessClientUrl(), 'secondary')
            . '<a class="button secondary" href="' . $this->escape($this->windowsHeadlessInstallerUrl()) . '">Windows 安装脚本</a>'
            . '<a class="button secondary" href="/knowledgebase?thread=no-gui-clients">使用说明</a>'
            . '</div></details>'
            . '</section>';

        $dockerSection = '<section class="tenant-client-install__platform"><strong>'
            . $this->platformIcon('docker')
            . $this->escape($this->clientAppReleaseLabel('Docker · 全平台 (TCP+UDP)', 'docker_client_installer')) . '</strong>'
            . '<div class="muted">最纯粹的 TCP+UDP 转发容器，无 GUI、免 TUN：linux/amd64 与 linux/arm64 双架构，含私有 Registry（--image）与全平台宿主支持。</div>'
            . '<details style="margin-top:10px;" open><summary>一键安装命令 · 复制即用</summary>'
            . '<pre class="tenant-client-install__command">' . $this->escape($dockerCommand) . '</pre>'
            . '<div class="actions">'
            . $this->renderCopyButton('复制 Docker 命令', $dockerCommand, 'secondary', '已复制', '复制失败')
            . '<a class="button secondary" href="' . $this->escape($this->dockerClientInstallerUrl()) . '">安装脚本</a>'
            . '<a class="button secondary" href="' . $this->escape($this->dockerClientComposeUrl()) . '">docker-compose.yml</a>'
            . '</div></details>'
            . '<details style="margin-top:10px;"><summary>docker-compose.yml · 预览</summary>'
            . '<pre class="tenant-client-install__command">' . $this->escape($dockerComposeSnippet) . '</pre>'
            . '<div class="muted">完整模板位于 /artifacts/docker-client/docker-compose.yml；安装脚本会自动拉取并校验，支持 LEIKWAN_IMAGE 私有镜像。</div>'
            . '</div></details>'
            . '</section>';


        $body = '<div class="tenant-client-install">'
            . '<div class="tenant-client-install__shell">'
            . '<div class="tenant-client-install__header"><strong>下载与安装</strong>'
            . '<span class="muted">选择设备对应的系统，安装后用当前账户登录即可。Linux 和无界面版可直接复制命令运行。</span></div>'
            . '<div class="tenant-client-install__body"><div class="tenant-client-install__platforms">'
            . '<section class="tenant-client-install__platform"><strong>'
            . $this->platformIcon('linux')
            . $this->escape($this->clientAppReleaseLabel('Linux (x86_64 / arm64)', 'client_agent_installer')) . '</strong>'
            . '<pre class="tenant-client-install__command">' . $this->escape($installCommand) . '</pre>'
            . '<div class="muted">同一条命令适用于 x86_64/amd64 与 aarch64/arm64，脚本会自动识别架构并校验所有产物哈希。仅支持 Debian 11/12/13。只能访问国内网络的机器也可以直接运行：连不上 Cloudflare 时会自动改用阿里云 CDN。</div>'
            . '<div class="actions">'
            . $this->renderCopyButton('复制命令', $installCommand, 'secondary', '已复制', '复制失败')
            . $resetForm
            . '</div></section>'
            . $windowsSection
            . $macosSection
            // Docker installation is hidden until container runtime acceptance passes.
            . '<section class="tenant-client-install__platform"><strong>'
            . $this->platformIcon('android')
            . $this->escape($this->clientAppReleaseLabel('Android (Unstable)', 'android_client_apk')) . '</strong>'
            . '<div class="actions">' . $this->renderCommandLink('Download APK', $this->androidClientApkUrl(), 'secondary') . '</div>'
            . '</section></div>'
            . '<div class="tenant-client-install__online"><span>最大在线</span><strong>'
            . $onlineCount . ' / ' . $onlineLimit . '</strong>'
            . ($onlineCount >= $onlineLimit
                ? '<div class="muted">已达上限：新设备会被拒绝连接并提示“设备数量已达上限”。在线设备不受影响；可移除下方设备，或等其他设备断开约 3 分钟后自动释放名额。</div>'
                : '')
            . '</div></div></div>'
            . $credentialPanel
            . $onlineList
            . '</div>';

        return $this->page('Clients', $body, $user, $this->tenantNav('clients'));
    }

    public function resetDefaultClientToken(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $service = $this->db->fetchOne(
                'SELECT id FROM services
                 WHERE user_id = :user_id AND status = :status
                 ORDER BY id ASC LIMIT 1',
                ['user_id' => $user['id'], 'status' => 'active']
            );
            if ($service === null) {
                throw new ApiException('service_not_found', 404);
            }
            $features = $this->provisioning->serviceSummary((int) $service['id'])['effective_features'] ?? [];
            if (empty($features['allow_rotate_client_token'])) {
                throw new ApiException('feature_denied_rotate_client_token', 403);
            }

            $this->provisioning->rotateDefaultClientTokenForUser((int) $user['id']);
            SessionAuth::flash('success', 'Client token reset.');
        } catch (\Throwable) {
            SessionAuth::flash('error', 'Client token reset failed.');
        }

        return Response::redirect('/tenant/clients');
    }

    public function kickClientDevice(Request $request, array $params): Response
    {
        $user = $this->tenantUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            [$clientId, $deviceId] = $this->ownedTenantDevice($user, $request);

            DeviceControlService::fromConfig($this->config, $this->db)->setTemporaryBlock(
                $clientId,
                $deviceId,
                'manual_tenant',
                1800,
                (int) $user['id'],
            );
            (new UltraLeaseService($this->config, $this->db))->revokeDevice($clientId, $deviceId);
            SessionAuth::flash('success', '客户端已踢下线。');
        } catch (\Throwable) {
            SessionAuth::flash('error', '踢下线失败。');
        }

        return Response::redirect('/tenant/clients');
    }

    public function banClientDevice(Request $request, array $params): Response
    {
        return $this->changeAndroidDeviceState($request, 'banned', 'tenant_ban');
    }

    public function unbanClientDevice(Request $request, array $params): Response
    {
        $user = $this->tenantUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            // Permanent device bans are an administrator-only control. Keep
            // the legacy route so old clients receive a deterministic denial.
            throw new ApiException('device_unban_admin_only', 403);
        } catch (\Throwable) {
            SessionAuth::flash('error', 'Permanent device bans require administrator release.');
        }

        return Response::redirect('/tenant/clients');
    }

    public function createAndroidEnrollmentCode(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $client = $this->provisioning->defaultClientForUser((int) $user['id']);
            if ($client === null) {
                $client = $this->provisioning->ensureDefaultClientForUser((int) $user['id']);
            }
            $issued = (new AndroidEnrollmentCodeService($this->db))->issue(
                (int) ($client['id'] ?? 0),
                (int) $user['id'],
            );
            $link = $this->agentBaseUrl() . '/android/enroll?client_id=' . (int) ($client['id'] ?? 0)
                . '&code=' . rawurlencode((string) $issued['code']);
            SessionAuth::flash('success', 'Enrollment link (valid 5 minutes): ' . $link);
        } catch (\Throwable) {
            SessionAuth::flash('error', 'Enrollment code creation failed.');
        }

        return Response::redirect('/tenant/clients');
    }

    private function changeAndroidDeviceState(Request $request, string $state, string $reason): Response
    {
        $user = $this->tenantUser();
        if ($user === null) {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            [$clientId, $deviceId] = $this->ownedTenantDevice($user, $request);
            DeviceControlService::fromConfig($this->config, $this->db)->setState(
                $clientId,
                $deviceId,
                $state,
                $reason,
                (int) $user['id'],
            );
            (new ClientOnlineSessionService($this->db))->revokeDevice($clientId, $deviceId);
            (new UltraLeaseService($this->config, $this->db))->revokeDevice($clientId, $deviceId);
            SessionAuth::flash('success', $state === 'banned' ? 'Device banned.' : 'Device unbanned; enrollment is still required.');
        } catch (\Throwable) {
            SessionAuth::flash('error', 'Device control update failed.');
        }

        return Response::redirect('/tenant/clients');
    }

    public function sidecarsPage(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        $view = PrecisionGridUi::normalizeView((string) $request->input('view', 'list'), ['list', 'create', 'edit'], 'list');

        $points = $this->provisioning->listSidecarPointsForUser((int) $user['id']);
        $editId = (int) $request->input('edit', 0);
        if ($editId > 0) $view = 'edit';
        $editing = null;
        foreach ($points as $point) {
            if ((int) $point['id'] === $editId && (int) ($point['owner_user_id'] ?? 0) === (int) $user['id']) {
                $editing = $point;
                break;
            }
        }

        $sidecarUsageMap = $this->provisioning->sidecarUsageOverviewMap(array_map(
            static fn(array $point): int => (int) ($point['id'] ?? 0),
            $points
        ));
        $cards = '';
        $rows = '';
        $mobileRows = '';
        foreach ($points as $point) {
            $status = $this->sidecarPointStatusInfo($point);
            $isShared = (int) ($point['owner_user_id'] ?? 0) === 0;
            $canManage = !$isShared && (int) ($point['owner_user_id'] ?? 0) === (int) $user['id'];
            $installSection = $canManage
                ? '<details style="margin-top:14px;">
                  <summary>Install Command</summary>
                  <div class="muted" style="margin:10px 0 12px;">Run this on the C-side machine. It installs the bond target transport service plus heartbeat timer.</div>
                  <pre>' . $this->escape($this->bondSidecarInstallCommand($point)) . '</pre>
                </details>
                <details style="margin-top:14px;">
                  <summary>Uninstall Command</summary>
                  <pre>' . $this->escape($this->bondSidecarUninstallCommand($point)) . '</pre>
                </details>'
                : '<details style="margin-top:14px;">
                  <summary>Install / Uninstall</summary>
                  <div class="muted">This is an admin-shared target transport endpoint. You can select it in bond forwards, but installation and secret management stay with the administrator.</div>
                </details>';

            $usage = $sidecarUsageMap[(int) ($point['id'] ?? 0)] ?? [];
            $usagePanel = $this->renderUsageOverviewPanel($usage, 'Sidecar Traffic');
            $usageSummary = '<div class="muted" style="margin-top:14px;">Traffic: Hour '
                . $this->escape($this->formatUsageBytes((int) ($usage['hour_bytes'] ?? 0)))
                . ' | Today '
                . $this->escape($this->formatUsageBytes((int) ($usage['day_bytes'] ?? 0)))
                . ' | Month '
                . $this->escape($this->formatUsageBytes((int) ($usage['month_bytes'] ?? 0)))
                . '</div>';
            $pointId = (int) $point['id'];
            $pointName = (string) $point['name'];
            $host = (string) $point['sidecar_host'];
            $commands = $canManage
                ? '<a href="/tenant-transport-endpoints?view=edit&amp;edit=' . $pointId . '">Edit</a><form class="inline" method="post" action="/tenant-transport-endpoints/' . $pointId . '/request-update"><input type="hidden" name="csrf_token" value="' . $this->escape(SessionAuth::csrfToken()) . '"><button type="submit">Request Update</button></form><form class="inline" method="post" action="/tenant-transport-endpoints/' . $pointId . '/delete"><input type="hidden" name="csrf_token" value="' . $this->escape(SessionAuth::csrfToken()) . '"><button type="submit" class="danger">Delete</button></form>'
                : '<span class="muted">Shared endpoint is read-only</span>';
            $ownerLabel = $isShared ? 'Shared by admin' : 'Owned by you';
            $rows .= '<tr><td>' . PrecisionGridUi::resourceCell($pointName, $ownerLabel) . '</td><td>' . PrecisionGridUi::resourceCell($host, $this->sidecarPortSummary($point)) . '</td><td>' . PrecisionGridUi::status($status['label'], $status['class'] === 'success' ? 'ok' : 'warning') . '</td><td>' . PrecisionGridUi::resourceCell((string) ($point['last_heartbeat_at'] ?? 'Never'), (string) ($point['agent_version'] ?? '')) . '</td><td>' . PrecisionGridUi::rowMenu($pointName . ' actions', $commands) . '</td></tr>';
            $mobileRows .= '<article class="pg-mobile-resource-row"><header>' . PrecisionGridUi::resourceCell($pointName, $ownerLabel) . PrecisionGridUi::status($status['label'], $status['class'] === 'success' ? 'ok' : 'warning') . '</header><dl><div><dt>Address</dt><dd>' . $this->escape($host) . '</dd></div><div><dt>Ports</dt><dd>' . $this->escape($this->sidecarPortSummary($point)) . '</dd></div><div><dt>Heartbeat</dt><dd>' . $this->escape((string) ($point['last_heartbeat_at'] ?? 'Never')) . '</dd></div></dl><footer>' . PrecisionGridUi::rowMenu($pointName . ' actions', $commands) . '</footer></article>';
            $cards .= '<section class="card">
                <div class="hero" style="margin-bottom:14px;">
                  <div>
                    <h3 style="margin-bottom:6px;">' . $this->escape((string) $point['name']) . '</h3>
                    <div class="muted">' . $this->escape((string) $point['sidecar_host']) . ' (' . $this->escape($this->sidecarPortSummary($point)) . ') • ' . ($isShared ? 'Shared by admin' : 'Owned by you') . '</div>
                  </div>
                  <div class="actions" style="margin-top:0;">
                    <span class="badge ' . $status['class'] . '">' . $this->escape($status['label']) . '</span>
                  </div>
                </div>
                <div class="grid two">
                  <div>
                    <div><span class="muted">Bind Host:</span> ' . $this->escape((string) ($point['bind_host'] ?? '0.0.0.0')) . '</div>
                    <div><span class="muted">Total Limit:</span> ' . $this->escape((string) (($point['total_speed_limit_mbps'] ?? '') !== '' ? ((string) $point['total_speed_limit_mbps']) . ' Mbps' : 'unlimited')) . '</div>
                    <div><span class="muted">Forwards Using It:</span> ' . (int) ($point['forward_count'] ?? 0) . '</div>
                    <div><span class="muted">Last Heartbeat:</span> ' . $this->escape((string) ($point['last_heartbeat_at'] ?? 'never')) . '</div>
                  </div>
                  <div>
                    <div><span class="muted">Agent Version:</span> ' . $this->escape((string) ($point['agent_version'] ?? '')) . '</div>
                    <div><span class="muted">Last Status:</span> ' . $this->escape((string) ($point['last_status'] ?? 'not installed')) . '</div>
                    <div><span class="muted">Last Error:</span> ' . $this->escape((string) ($point['last_error'] ?? '')) . '</div>
                  </div>
                </div>
                ' . $usageSummary . '
                <div class="actions">
                  ' . ($canManage ? '<a class="button ghost" href="/tenant-transport-endpoints?edit=' . (int) $point['id'] . '">Edit</a>
                  <form class="inline" method="post" action="/tenant-transport-endpoints/' . (int) $point['id'] . '/request-update">
                    <input type="hidden" name="csrf_token" value="' . $this->escape(SessionAuth::csrfToken()) . '">
                    <button type="submit" class="button secondary">Request Update</button>
                  </form>
                  <form class="inline" method="post" action="/tenant-transport-endpoints/' . (int) $point['id'] . '/delete">
                    <input type="hidden" name="csrf_token" value="' . $this->escape(SessionAuth::csrfToken()) . '">
                    <button type="submit" class="button danger">Delete</button>
                  </form>' : '<span class="muted">Shared target transport endpoints are read-only for tenants.</span>') . '
                </div>
                ' . $installSection . '
                <details style="margin-top:14px;">
                  <summary>Usage Calendar</summary>
                  ' . $usagePanel . '
                </details>
                <details style="margin-top:14px;">
                  <summary>Forward Usage</summary>
                  <div class="muted">Use this point from the service forward form by selecting it under <code>Transport Endpoint</code>. Shared points can be reused across multiple forwards.</div>
                </details>
              </section>';
        }

        if ($cards === '') {
            $cards = '<div class="muted">No target transport endpoint yet. Create one, install it on your C-side machine, then select it when you create a bond or MPTCP forward.</div>';
        }

        $tabs = PrecisionGridUi::viewTabs([
            ['key' => 'list', 'label' => 'Endpoints', 'href' => '/tenant-transport-endpoints?view=list'],
            ['key' => 'create', 'label' => 'Create', 'href' => '/tenant-transport-endpoints?view=create'],
        ], $view);
        $listView = '<section class="pg-sheet" data-mobile-resources="true"><div class="pg-sheet-toolbar"><strong>Transport Endpoints</strong><span class="muted">' . count($points) . ' endpoints</span></div><div class="pg-sheet-scroll"><table class="pg-sheet-table"><thead><tr><th>Endpoint / Owner</th><th>Address / Ports</th><th>Status</th><th>Heartbeat / Version</th><th><span class="pg-visually-hidden">Actions</span></th></tr></thead><tbody>' . $rows . '</tbody></table></div><div class="pg-mobile-resource-list">' . $mobileRows . '</div></section>';
        $body = $tabs . ($view === 'list' ? $listView : '') . ($view !== 'list' ? '
            <div class="hero">
              <div>
                <h2>Target Endpoints</h2>
                <p class="muted">Advanced target transport setup and backend reachability details. Each endpoint represents a C-side machine and its listen ports.</p>
              </div>
            </div>
            <div class="grid two">
              <section class="card">
                <h3>' . ($editing !== null ? 'Edit Transport Endpoint' : 'Create Transport Endpoint') . '</h3>
                <p class="muted">Tenant-owned target transport endpoints can be installed and monitored directly here. Admin-shared target transport endpoints will also appear in this list and can be selected in bond forwards.</p>
                <form method="post" action="/tenant-transport-endpoints">
                  <input type="hidden" name="csrf_token" value="' . $this->escape(SessionAuth::csrfToken()) . '">
                  <input type="hidden" name="id" value="' . (int) ($editing['id'] ?? 0) . '">
                  <div class="form-grid">
                    <div class="field"><label>Name</label><input type="text" name="name" value="' . $this->escape((string) ($editing['name'] ?? '')) . '" required></div>
                    <div class="field"><label>Sidecar Host</label><input type="text" name="sidecar_host" value="' . $this->escape((string) ($editing['sidecar_host'] ?? '')) . '" placeholder="198.51.100.10 or c.example.com" required></div>
                    <div class="field"><label>Transport Listen Port</label><input type="number" name="bond_listen_port" value="' . $this->escape((string) ($editing['bond_listen_port'] ?? $editing['listen_port'] ?? $this->defaultBondRemotePort())) . '" required></div>
                    <div class="field"><label>Alternate Listen Port</label><input type="number" name="mptcp_listen_port" value="' . $this->escape((string) ($editing['mptcp_listen_port'] ?? $this->sidecarMptcpListenPort($editing ?? []))) . '" required></div>
                    <div class="field"><label>CF Listen Port</label><input type="number" name="cf_listen_port" value="' . $this->escape((string) ($editing['cf_listen_port'] ?? 443)) . '" required></div>
                    <div class="field"><label>CF TLS Cert Path</label><input type="text" name="cf_tls_cert_path" value="' . $this->escape((string) ($this->decodeJsonList($editing['agent_config_json'] ?? null)['cf_tls_cert_path'] ?? '')) . '" placeholder="/etc/ssl/cf/fullchain.pem"></div>
                    <div class="field"><label>CF TLS Key Path</label><input type="text" name="cf_tls_key_path" value="' . $this->escape((string) ($this->decodeJsonList($editing['agent_config_json'] ?? null)['cf_tls_key_path'] ?? '')) . '" placeholder="/etc/ssl/cf/privkey.pem"></div>
                    <div class="field"><label>Bind Host</label><input type="text" name="bind_host" value="' . $this->escape((string) ($editing['bind_host'] ?? '0.0.0.0')) . '" placeholder="0.0.0.0"></div>
                    <div class="field"><label>Total Limit Mbps</label><input type="number" name="total_speed_limit_mbps" value="' . $this->escape((string) ($editing['total_speed_limit_mbps'] ?? '')) . '" min="1" placeholder="optional"></div>
                    <div class="field"><label>Status</label><select name="is_active"><option value="1"' . (((int) ($editing['is_active'] ?? 1)) === 1 ? ' selected' : '') . '>active</option><option value="0"' . (((int) ($editing['is_active'] ?? 1)) !== 1 ? ' selected' : '') . '>disabled</option></select></div>
                  </div>
                  <div class="actions">
                    <button class="button" type="submit">Save Transport Endpoint</button>
                    ' . ($editing !== null ? '<a class="button secondary" href="/tenant-transport-endpoints">Cancel</a>' : '') . '
                  </div>
                </form>
              </section>
              <section class="card">
                <h3>Transport Endpoint List</h3>
                <div class="stack">' . $cards . '</div>
              </section>
            </div>' : '');

        return $this->page('Target Endpoints', $body, $user, $this->tenantNav('transport-endpoints'));
    }

    public function saveSidecarPoint(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $id = (int) $request->input('id', 0);
            if ($id > 0) {
                $point = $this->provisioning->sidecarPointById($id);
                if ($point === null || (int) ($point['owner_user_id'] ?? 0) !== (int) $user['id']) {
                    throw new ApiException('sidecar_point_not_found', 404);
                }
            }

            $payload = $request->body();
            $payload['owner_user_id'] = (int) $user['id'];
            // Security: a tenant must not choose its own sidecar agent_token
            // (predictable/colliding tokens, or impersonation). The service
            // generates one on create and keeps the existing one on edit.
            unset($payload['agent_token']);
            $this->provisioning->saveSidecarPoint($payload);
            SessionAuth::flash('success', 'Transport endpoint saved.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to save target transport endpoint: ' . $this->displayThrowableMessage($throwable));
        }

        return Response::redirect('/tenant-transport-endpoints');
    }

    public function deleteSidecarPoint(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        $pointId = $this->idParam($params);
        try {
            SessionAuth::validateCsrf($request);
            $point = $this->provisioning->sidecarPointById($pointId);
            if ($point === null || (int) ($point['owner_user_id'] ?? 0) !== (int) $user['id']) {
                throw new ApiException('sidecar_point_not_found', 404);
            }
            $this->provisioning->deleteSidecarPoint($pointId);
            SessionAuth::flash('success', 'Transport endpoint deleted.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to delete target transport endpoint: ' . $this->displayThrowableMessage($throwable));
        }

        return Response::redirect('/tenant-transport-endpoints');
    }

    public function requestSidecarUpdate(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        $pointId = $this->idParam($params);
        try {
            SessionAuth::validateCsrf($request);
            $point = $this->provisioning->sidecarPointById($pointId);
            if ($point === null || (int) ($point['owner_user_id'] ?? 0) !== (int) $user['id']) {
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
            SessionAuth::flash('error', 'Failed to request target transport update: ' . $this->displayThrowableMessage($throwable));
        }

        return Response::redirect('/tenant-transport-endpoints');
    }

    public function serviceDetail(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        $view = PrecisionGridUi::normalizeView(
            (string) $request->input('view', 'overview'),
            ['overview', 'forwards'],
            'overview'
        );

        $serviceId = $this->idParam($params);
        $service = $this->db->fetchOne('SELECT * FROM services WHERE id = :id AND user_id = :user_id LIMIT 1', [
            'id' => $serviceId,
            'user_id' => $user['id'],
        ]);
        if ($service === null) {
            throw new ApiException('service_not_found', 404);
        }

        $summary = $this->provisioning->serviceSummary($serviceId);
        $features = $summary['effective_features'] ?? [];
        $bondReady = $this->isBondReady($service);
        $allowedGroups = $summary['allowed_node_groups'] ?? [];
        $allowedClientGroups = $summary['allowed_client_groups'] ?? [];
        $availableClients = $summary['available_clients'] ?? [];
        $availableSidecarPoints = $summary['available_sidecar_points'] ?? [];
        $availableChains = $summary['available_forward_chains'] ?? [];
        $availableForwardCombinations = is_array($summary['available_forward_combinations'] ?? null) ? $summary['available_forward_combinations'] : [];
        $availableClientHtml = '';
        foreach ($availableClients as $client) {
            $availableClientHtml .= '<li><strong>' . $this->escape((string) $client['name']) . '</strong> <span class="muted">(' . $this->escape((string) $client['display_host']) . ')</span></li>';
        }
        if ($availableClientHtml === '') {
            $availableClientHtml = '<li class="muted">No line client is currently available for this service.</li>';
        }
        $pausedForwards = $this->provisioning->pausedForwardsForService($serviceId);
        $forwardRows = array_merge($summary['forwards'], $pausedForwards);
        usort($forwardRows, static fn(array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
        $cycleStart = (string) (($summary['billing']['cycle_started_at'] ?? '') ?: ($service['created_at'] ?? ''));
        $usageMap = $this->provisioning->forwardCycleUsageMap($serviceId, $cycleStart);
        $includedBytes = max(0, (int) (($summary['billing']['included_bytes'] ?? 0) ?: 0));
        $csrf = $this->escape(SessionAuth::csrfToken());
        // Regression sentinel: awaiting_policy_is_a_health_failure
        // "enabled" is only a DB flag. An Ultra forward is provisioned by a
        // lease, so an enabled Ultra forward that holds no live lease - or whose
        // runtime reports awaiting_policy/degraded - was rendering below as
        // running while it carried no traffic at all. Resolve its real health
        // before the rows are built.
        $ultraHealth = [];
        $ultraForwardIds = [];
        foreach ($forwardRows as $forward) {
            if ((int) ($forward['enabled'] ?? 0) === 1
                && \Leikwan\Master\Support\UltraMode::isBackendMode($forward['backend_mode'] ?? '')) {
                $ultraForwardIds[] = (int) ($forward['id'] ?? 0);
            }
        }
        if ($ultraForwardIds !== []) {
            try {
                $ultraHealth = (new \Leikwan\Master\Support\UltraClientStatusService($this->config, $this->db))
                    ->runtimeHealthForForwards($ultraForwardIds);
            } catch (\Throwable) {
                $ultraHealth = [];
            }
        }
        $serviceExpired = ServiceStatus::effective($service) === ServiceStatus::EXPIRED;
        $rows = '';
        foreach ($forwardRows as $forward) {
            $isRunning = (int) ($forward['enabled'] ?? 0) === 1;
            $forwardHealth = $ultraHealth[(int) ($forward['id'] ?? 0)] ?? null;
            $healthFailed = !$serviceExpired && is_array($forwardHealth) && ($forwardHealth['unhealthy'] ?? false) === true;
            $healthReason = $healthFailed ? trim((string) ($forwardHealth['health_reason'] ?? '')) : '';
            $statusBadge = !$isRunning
                ? '<span class="badge warning">Paused</span>'
                : ($serviceExpired
                    ? '<span class="badge danger">Expired</span>'
                    : ($healthFailed
                        ? '<span class="badge danger">已启用但未运行</span>'
                        : '<span class="badge success">Running</span>'));
            if ($healthFailed) {
                $statusBadge .= '<div class="muted">' . $this->escape($healthReason !== '' ? $healthReason : 'unhealthy') . '</div>';
            }

            $usedBytes = (int) ($usageMap[(int) $forward['id']] ?? 0);
            $usedLabel = $this->formatTrafficBytes($usedBytes);
            $pct = $includedBytes > 0 ? min(100, (int) round($usedBytes / $includedBytes * 100)) : 0;
            $usageCell = '<div class="console-progress" style="min-width:120px;margin-top:0;">'
                . '<div class="console-progress-track"><div class="console-progress-fill" style="width:' . $pct . '%;"></div></div>'
                . '<span>' . $usedLabel . '</span></div>';

            $endpointHtml = '';
            $activeEndpointCount = 0;
            foreach (($forward['endpoints'] ?? []) as $endpoint) {
                $healthClass = ((string) $endpoint['health_status'] === 'active') ? 'success' : 'info';
                if ((string) $endpoint['health_status'] === 'active') {
                    $activeEndpointCount++;
                }
                $endpointHtml .= '<div><span class="badge ' . $healthClass . '">' . $this->escape((string) $endpoint['health_status']) . '</span> '
                    . $this->escape((string) ($endpoint['node_name'] ?? $endpoint['node'] ?? 'Slave endpoint')) . '</div>';
            }
            if ($endpointHtml === '') {
                if (!$isRunning) {
                    $endpointHtml = '<div class="muted">Paused — endpoints released</div>';
                } elseif ($serviceExpired) {
                    $endpointHtml = '<div class="muted">Service expired — renew to resume</div>';
                } elseif ($healthFailed) {
                    $endpointHtml = '<div class="muted">未运行 · 无有效 Ultra 租约，尚未建立监听</div>';
                } else {
                    $endpointHtml = '<div class="muted">No endpoint assigned yet</div>';
                }
            } else {
                $endpointHtml = '<div class="muted"><span>Total</span> ' . count($forward['endpoints']) . ' · <span>Active</span> ' . $activeEndpointCount . '</div>' . $endpointHtml;
            }

            $targetCell = $this->escape((string) $forward['target_host']) . ':' . (int) $forward['target_port'];
            if (in_array($this->canonicalClientMode((string) ($forward['client_mode'] ?? 'single')), ['bond', 'mptcp'], true) && !empty($forward['bond_remote_host'])) {
                if (!empty($forward['bond_sidecar_point_name'])) {
                    $targetCell .= '<div class="muted"><span>Transport point</span> ' . $this->escape((string) $forward['bond_sidecar_point_name']) . '</div>';
                }
                $targetCell .= '<div class="muted"><span>Target transport</span> ' . $this->escape((string) $forward['bond_remote_host']) . ':' . (int) ($forward['bond_remote_port'] ?? 0) . '</div>';
            }

            $actionsHtml = '';
            if (!empty($features['allow_manage_forwards'])) {
                $toggleAction = $isRunning ? 'pause' : 'start';
                $toggleLabel = $isRunning ? 'Pause' : 'Start';
                $toggleClass = $isRunning ? 'secondary' : '';
                $actionsHtml .= '<form class="inline" method="post" action="/tenant/services/' . $serviceId . '/forward/' . (int) $forward['id'] . '/toggle">'
                    . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                    . '<input type="hidden" name="action" value="' . $toggleAction . '">'
                    . '<button type="submit" class="button ' . $toggleClass . '">' . $toggleLabel . '</button>'
                    . '</form>';
                $actionsHtml .= '<a class="button ghost" href="/tenant/services/' . $serviceId . '/forward/' . (int) $forward['id'] . '/edit">Modify</a>';
            } else {
                $actionsHtml .= '<span class="muted">Manage disabled by policy</span>';
            }
            if (!empty($features['allow_delete_forwards'])) {
                $actionsHtml .= '<form class="inline" method="post" action="/tenant/forwards/' . (int) $forward['id'] . '/delete">'
                    . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
                    . '<button type="submit" class="button danger">Delete</button>'
                    . '</form>';
            } else {
                $actionsHtml .= '<span class="muted">Delete disabled by policy</span>';
            }

            $runtimeManagementHtml = '<div class="muted" style="margin-top:12px;">Manage client profiles and runtime installs from <a href="/tenant/clients"><strong>Client Profiles</strong></a>.</div>';
            if (in_array($this->canonicalClientMode((string) ($forward['client_mode'] ?? 'single')), ['bond', 'mptcp'], true) && !empty($forward['bond_remote_host'])) {
                $runtimeManagementHtml .= '<div class="muted"><span>Current target transport endpoint:</span> '
                    . $this->escape((string) $forward['bond_remote_host'])
                    . ':'
                    . (int) (($forward['bond_remote_port'] ?? 0) ?: $this->defaultBondRemotePort())
                    . '</div>';
            }

            $rows .= '<tr>
                <td>' . (int) $forward['id'] . '</td>
                <td>' . (int) $forward['listen_port'] . '</td>
                <td>' . $this->escape((string) ($forward['forward_combination_name'] ?? '')) . '</td>
                <td>' . $targetCell . '</td>
                <td>' . $this->escape($this->transportModeLabel((string) $forward['transport_mode'])) . '</td>
                <td>' . $usageCell . '</td>
                <td>' . $statusBadge . '</td>
                <td>' . $endpointHtml . '</td>
                <td><div class="actions">' . $actionsHtml . '</div>' . $runtimeManagementHtml . '</td>
            </tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="9" class="muted">No forward rules yet. Create your first forward from the button above.</td></tr>';
        }

        $defaultHost = $this->escape((string) $this->config->get('DEFAULT_CDN_HOST', 'edge.example.com'));
        $defaultEndpointCount = 1;
        $defaultClientMode = 'single';
        $defaultBondRemotePort = $this->defaultBondRemotePort();
        $agentBaseUrl = $this->agentBaseUrl();
        $artifactBaseUrl = $this->artifactBaseUrl();
        $createForwardNotice = $availableForwardCombinations === []
            ? '<div class="flash danger">No route package is available for this service yet. Ask admin to publish a forward combination first.</div>'
            : '<div class="flash success">Choose one route package, then fill your backend target.</div>';
        $bondCreateHelper = $this->renderBondSelectionInstallHelper(
            'tenant-forward-create',
            'tenant-forward-client-mode',
            'tenant-forward-bond-remote-port',
            $agentBaseUrl,
            $artifactBaseUrl,
            (string) $service['client_token'],
            $defaultBondRemotePort
        );
        $bondCreateBinding = $this->renderBondSidecarBindingScript(
            'tenant-forward-client-mode',
            'tenant-forward-sidecar-point',
            'tenant-forward-bond-remote-host',
            'tenant-forward-bond-remote-port',
            'tenant-forward-bond-host-field',
            'tenant-forward-bond-port-field',
            'tenant-forward-sidecar-summary'
        );
        $groupOptions = '<option value="">Auto choose first allowed group</option>';
        foreach ($allowedGroups as $group) {
            $groupOptions .= '<option value="' . $this->escape((string) $group['group_key']) . '">' . $this->escape((string) $group['name']) . ' (' . (int) ($group['node_count'] ?? 0) . ' nodes)</option>';
        }
        $clientOptions = '';
        foreach ($availableClients as $client) {
            $clientOptions .= '<option value="' . (int) $client['id'] . '">' . $this->escape((string) $client['name']) . ' - ' . $this->escape((string) $client['display_host']) . '</option>';
        }
        $clientGroupOptions = '<option value="">No specific client group</option>';
        foreach ($allowedClientGroups as $group) {
            $clientGroupOptions .= '<option value="' . (int) $group['id'] . '">' . $this->escape((string) $group['name']) . ' (' . (int) ($group['client_count'] ?? 0) . ' clients)</option>';
        }
        $chainOptions = '<option value="">No chain</option>';
        foreach ($availableChains as $chain) {
            $chainOptions .= '<option value="' . (int) $chain['id'] . '">' . $this->escape((string) $chain['name']) . '</option>';
        }
        $sidecarPointOptions = $this->renderSidecarPointOptions($availableSidecarPoints);
        $billing = is_array($summary['billing'] ?? null) ? $summary['billing'] : [];
        $trafficResetPrice = (float) ($summary['plan_traffic_reset_price'] ?? 0);
        $trafficResetCurrency = strtoupper(trim((string) ($summary['plan_billing_currency'] ?? $billing['currency'] ?? 'CNY')));
        if ($trafficResetCurrency === '') {
            $trafficResetCurrency = 'CNY';
        }
        $billingHistoryRows = '';
        foreach (($summary['billing_history'] ?? []) as $cycle) {
            if (!is_array($cycle)) {
                continue;
            }
            $billingHistoryRows .= '<tr>
                <td>' . $this->escape((string) ($cycle['cycle_started_at'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($cycle['cycle_ended_at'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($cycle['billing_mode'] ?? '')) . '</td>
                <td>' . round(((int) ($cycle['usage_bytes'] ?? 0)) / 1024 / 1024 / 1024, 2) . ' GB</td>
                <td>' . $this->escape((string) ($cycle['billing_currency'] ?? 'CNY')) . ' ' . number_format((float) ($cycle['estimated_total_amount'] ?? 0), 2) . '</td>
            </tr>';
        }
        if ($billingHistoryRows === '') {
            $billingHistoryRows = '<tr><td colspan="5" class="muted">No closed billing cycle yet.</td></tr>';
        }
        $groupNotice = $allowedGroups === []
            ? '<div class="flash danger">No slave group is ready yet. Ask admin to bind one online group to this plan.</div>'
            : '<div class="flash success">Keep the first rule simple: 1 line, 1 group, 1 endpoint.</div>';
        $clientNotice = $availableClients === []
            ? '<div class="flash danger">No client line is ready yet. Ask admin to create one and expose it to this plan.</div>'
            : '<div class="flash success">Start with Single mode and TCP.</div>';
        $bondNotice = $bondReady
            ? ''
            : '<div class="flash danger">Bond / MPTCP transport mode is unavailable because this service has no usable token secret yet. Restore the service client token or ask admin to configure <code>BOND_TOKEN_SECRET</code> as a fallback.</div>';
        $endpointModeOptions = $this->renderEndpointModeOptions('slave_group', !empty($features['allow_forward_chain']));

        $mySlavesSection = '';
        if (!empty($features['allow_custom_slaves'])) {
            $userNodes = $this->db->fetchAll(
                'SELECT * FROM nodes WHERE owner_service_id = :service_id AND enrollment_type = :enrollment_type ORDER BY created_at DESC',
                ['service_id' => $serviceId, 'enrollment_type' => 'user_enrolled']
            );
            $nodeRows = '';
            foreach ($userNodes as $node) {
                $nodeRows .= '<tr>'
                    . '<td>' . $this->escape((string) $node['name']) . '</td>'
                    . '<td>' . $this->escape((string) $node['public_ip']) . '</td>'
                    . '<td>' . $this->escape((string) $node['status']) . '</td>'
                    . '<td>' . $this->escape((string) ($node['last_heartbeat_at'] ?? 'never')) . '</td>'
                    . '<td>' . $this->escape((string) $node['created_at']) . '</td>'
                    . '<td><form class="inline" method="post" action="/tenant/nodes/' . (int) $node['id'] . '/remove"><input type="hidden" name="csrf_token" value="' . $csrf . '"><button class="button secondary small" type="submit">Remove</button></form></td>'
                    . '</tr>';
            }
            if ($nodeRows === '') {
                $nodeRows = '<tr><td colspan="6" class="muted">No user-enrolled nodes yet.</td></tr>';
            }
            $mySlavesSection = '
            <section class="card" style="margin-top:20px;">
              <h3>My Slaves</h3>
              <p class="muted">Your own VPS machines enrolled as transit nodes for this service.</p>
              <div class="table-wrap">
                <table>
                  <thead><tr><th>Name</th><th>Public IP</th><th>Status</th><th>Last Heartbeat</th><th>Enrolled</th><th>Actions</th></tr></thead>
                  <tbody>' . $nodeRows . '</tbody>
                </table>
              </div>
              <div class="actions" style="margin-top:12px;">
                <form class="inline" method="post" action="/tenant/services/' . (int) $service['id'] . '/enrollment-token">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <button class="button" type="submit">Generate Node Enrollment Token</button>
                </form>
              </div>
            </section>';
        }

        $tabs = PrecisionGridUi::viewTabs([
            ['key' => 'overview', 'label' => 'Overview', 'href' => '/tenant/services/' . $serviceId . '?view=overview'],
            ['key' => 'forwards', 'label' => 'Forwards', 'href' => '/tenant/services/' . $serviceId . '?view=forwards'],
        ], $view);
        $cycleUsedBytes = (int) ($billing['current_cycle_used_bytes'] ?? 0);
        $cyclePct = $includedBytes > 0 ? min(100, (int) round($cycleUsedBytes / $includedBytes * 100)) : 0;
        $isBundle = ((string) ($billing['mode'] ?? 'monthly_bundle')) === 'monthly_bundle';
        $kv = function (array $pairs): string {
            $html = '<dl class="console-kv">';
            foreach ($pairs as [$label, $value]) {
                $html .= '<dt>' . $label . '</dt><dd>' . $value . '</dd>';
            }
            return $html . '</dl>';
        };
        $body = $tabs . '
            <div class="hero">
              <div>
                <div class="console-service-head" style="justify-content:flex-start;">
                  <h2>' . $this->escape((string) $service['name']) . '</h2>
                  ' . $this->tenantServiceStatusBadge($service) . '
                </div>
                <p class="muted"><span>Expires</span> ' . $this->escape((string) $service['expire_at']) . '</p>
              </div>
              <div class="actions">
                <a class="button ghost" href="/tenant/forwards">Back to My Services</a>
              </div>
            </div>
            <div class="grid two">
              <section class="card">
                <h3>Service Info</h3>
                ' . $kv([
                    ['Plan', '<strong>' . $this->escape((string) ($summary['plan_name'] ?? '')) . '</strong>'],
                    ['Expires', $this->escape((string) $service['expire_at'])],
                    ['Bandwidth', (int) $service['bandwidth_limit_mbps'] . ' Mbps'],
                ]) . '
                <div style="margin-top:14px;">
                  <div class="muted">Traffic used this cycle</div>
                  <div class="console-progress">
                    <div class="console-progress-track"><div class="console-progress-fill" style="width:' . $cyclePct . '%;"></div></div>
                    <span>' . $this->formatTrafficBytes($cycleUsedBytes) . ' / ' . $this->formatTrafficBytes($includedBytes) . '</span>
                  </div>
                </div>
                <details style="margin-top:16px;">
                  <summary>More details</summary>
                  ' . $kv([
                    ['WHMCS Service ID', $this->escape((string) $service['whmcs_service_id'])],
                    ['Allowed client groups', $this->escape(implode(', ', array_map(static fn(array $group): string => (string) $group['name'], $allowedClientGroups))) ?: '-'],
                    ['Traffic Policy', $isBundle
                        ? (int) ($billing['included_traffic_gb'] ?? 0) . ' GB · <span>restored every billing month</span>'
                        : '<span>metered by monthly cycle</span>'],
                    ['Lifetime Used Traffic', round(((int) $service['traffic_used_bytes']) / 1024 / 1024 / 1024, 2) . ' GB'],
                  ]) . '
                  <h4 style="margin:18px 0 8px;">Flexible Package</h4>
                  ' . $kv([
                    ['Base Bandwidth', (int) ($billing['base_bandwidth_mbps'] ?? $service['bandwidth_limit_mbps']) . ' Mbps'],
                    ['Current Bandwidth', (int) ($billing['current_bandwidth_mbps'] ?? $service['bandwidth_limit_mbps']) . ' Mbps'],
                    ['Bandwidth step price', '+' . (int) ($billing['bandwidth_step_mbps'] ?? 0) . ' Mbps · ' . $this->escape((string) ($billing['bandwidth_step_price_formatted'] ?? 'CNY 0.00')) . ' <span>per month</span>'],
                    ['Current Extra Bandwidth', (int) ($billing['extra_bandwidth_mbps'] ?? 0) . ' Mbps (' . (int) ($billing['extra_bandwidth_steps'] ?? 0) . ' <span>steps</span>)'],
                    ['Current Bandwidth Add-on', $this->escape((string) ($billing['bandwidth_addon_formatted'] ?? 'CNY 0.00'))],
                    ['Included Clients', (string) (int) ($billing['included_client_count'] ?? 0)],
                    ['Clients in use', (string) (int) ($billing['active_client_count'] ?? 0)],
                    ['Extra client price', $this->escape((string) ($billing['extra_client_price_formatted'] ?? 'CNY 0.00')) . ' <span>per month</span>'],
                    ['Current Extra Client Charge', $this->escape((string) ($billing['client_addon_formatted'] ?? 'CNY 0.00'))],
                  ]) . '
                  <form method="post" action="/tenant/services/' . (int) $service['id'] . '/flexible-options" style="margin-top:12px;">
                    <input type="hidden" name="csrf_token" value="' . $csrf . '">
                    <div class="form-grid">
                      <div class="field">
                        <label>Bandwidth Mbps</label>
                        <input type="number" min="' . max(1, (int) ($billing['base_bandwidth_mbps'] ?? $service['bandwidth_limit_mbps'])) . '" name="bandwidth_limit_mbps" value="' . (int) ($billing['current_bandwidth_mbps'] ?? $service['bandwidth_limit_mbps']) . '" required>
                        <p class="muted">If this package uses bandwidth steps, keep the value aligned with the configured step size.</p>
                      </div>
                    </div>
                    <div class="actions">
                      <button class="button secondary" type="submit">Update Flexible Options</button>
                    </div>
                  </form>
                  <h4 style="margin:18px 0 8px;">Billing This Cycle</h4>
                  ' . $kv(array_merge([
                    ['Billing mode', $this->escape((string) ($billing['mode_label'] ?? 'Monthly Bundle'))],
                    ['Base Fee', $this->escape((string) ($billing['base_fee_formatted'] ?? 'CNY 0.00'))],
                    ['Traffic Charge', $this->escape((string) ($billing['traffic_charge_formatted'] ?? 'CNY 0.00'))],
                    ['Bandwidth Add-on', $this->escape((string) ($billing['bandwidth_addon_formatted'] ?? 'CNY 0.00'))],
                    ['Client Add-on', $this->escape((string) ($billing['client_addon_formatted'] ?? 'CNY 0.00'))],
                  ], $isBundle ? [
                    ['Included Traffic', (int) ($billing['included_traffic_gb'] ?? 0) . ' GB / <span>month</span>'],
                    ['Remaining Included Traffic', $this->escape((string) ($billing['remaining_included_gb'] ?? 0)) . ' GB'],
                  ] : [
                    ['Usage Price', $this->escape((string) ($billing['traffic_price_per_gb_formatted'] ?? 'CNY 0.0000 / GB'))],
                    ['Billable Traffic', $this->escape((string) ($billing['billable_gb'] ?? 0)) . ' GB'],
                  ], [
                    ['Current Estimate', '<strong>' . $this->escape((string) ($billing['estimated_total_formatted'] ?? 'CNY 0.00')) . '</strong>'],
                    ['Billing Cycle', $this->escape((string) ($billing['cycle_started_at'] ?? '')) . ' → ' . $this->escape((string) ($billing['cycle_ends_at'] ?? ''))],
                  ])) . '
                  <h4 style="margin:18px 0 8px;">Traffic Reset</h4>
                  ' . $kv([
                    ['Reset Price', $this->escape($trafficResetCurrency) . ' ' . $this->escape(number_format($trafficResetPrice, 2))],
                  ]) . '
                  <p class="muted" style="margin-top:8px;">Resetting sets this cycle&#39;s used traffic back to 0. Lifetime traffic stays unchanged.</p>
                  <form method="post" action="/tenant/services/' . (int) $service['id'] . '/reset-traffic" style="margin-top:12px;">
                    <input type="hidden" name="csrf_token" value="' . $csrf . '">
                    <div class="actions">
                      <button class="button secondary" type="submit">Reset Traffic</button>
                    </div>
                  </form>
                  <div class="table-wrap" style="margin-top:16px;">
                    <table>
                      <thead><tr><th>Cycle Start</th><th>Cycle End</th><th>Mode</th><th>Usage</th><th>Total</th></tr></thead>
                      <tbody>' . $billingHistoryRows . '</tbody>
                    </table>
                  </div>
                  <h4 style="margin:18px 0 8px;">Available Lines</h4>
                  <ul class="list">' . $availableClientHtml . '</ul>
                  <p class="muted">If there is no line here, admin still needs to create a client and authorize it through your plan.</p>
                  <div class="actions">
                    <a class="button ghost" href="/knowledgebase?thread=getting-started-first-forward-checklist">Knowledgebase</a>
                    <form class="inline" method="post" action="/tenant/services/' . (int) $service['id'] . '/regenerate-client-token">
                      <input type="hidden" name="csrf_token" value="' . $csrf . '">
                      <button class="button secondary" type="submit" ' . (empty($features['allow_rotate_client_token']) ? 'disabled' : '') . '>Rotate Client Token</button>
                    </form>
                  </div>
                </details>
              </section>
              <section class="card">
                <h3>Create Forward</h3>
                <p class="muted">Choose one route package, then fill your backend target on the dedicated create page.</p>
                <p class="field-note">Need help? <a href="/knowledgebase?thread=getting-started-first-forward-checklist">Create your first forward</a> &middot; <a href="/knowledgebase?thread=connect-and-verify">Connect and verify</a> &middot; <a href="/knowledgebase?thread=troubleshooting-common-mistakes">Troubleshoot a connection</a> &middot; <a href="/knowledgebase?thread=glossary">Read the glossary</a></p>
                ' . (empty($features['allow_manage_forwards']) ? '<div class="flash danger">Forward creation is disabled for your plan/account.</div>' : '') . '
                ' . $createForwardNotice . '
                <div class="actions" style="margin-top:12px;">
                  <a class="button" href="/tenant/services/' . (int) $service['id'] . '/create-forward">Create Forward</a>
                  <a class="button ghost" href="/knowledgebase?thread=getting-started-first-forward-checklist">Knowledgebase</a>
                </div>
              </section>
            </div>
            <section class="card" style="margin-top:20px;">
              <h3>Forward Rules</h3>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr><th>ID</th><th>Local Listen</th><th>Route</th><th>Target</th><th>Transport</th><th>Usage this cycle</th><th>Status</th><th>Endpoint Status</th><th>Actions</th></tr>
                  </thead>
                  <tbody>' . $rows . '</tbody>
                </table>
              </div>
            </section>'
            . $mySlavesSection;

        return $this->page('Service Details', $body, $user, $this->tenantNav('service', $serviceId));
    }

    public function forwardWizardRedirect(Request $request, array $params): Response
    {
        $serviceId = $this->idParam($params);
        return Response::redirect('/tenant/services/' . $serviceId . '/create-forward');
    }

    public function quickCreateForward(Request $request, array $params): Response
    {
        try {
            $user = SessionAuth::currentUser($this->db);
            if ($user === null || (string) $user['role'] !== 'tenant') {
                return Response::redirect('/login');
            }

            $serviceId = $this->idParam($params);
            $service = $this->db->fetchOne('SELECT * FROM services WHERE id = :id AND user_id = :user_id LIMIT 1', [
                'id' => $serviceId,
                'user_id' => $user['id'],
            ]);
            if ($service === null) {
                throw new ApiException('service_not_found', 404);
            }
            if ((string) ($service['status'] ?? '') !== 'active') {
                throw new ApiException('service_inactive', 409);
            }

            $summary = $this->provisioning->serviceSummary($serviceId);
            $features = $summary['effective_features'] ?? [];
            $plan = $this->db->fetchOne(
                'SELECT feature_flags_json FROM service_plans WHERE id = :id LIMIT 1',
                ['id' => (int) ($service['plan_id'] ?? 0)]
            );
            $planFeatureRow = [
                'plan_feature_flags_json' => is_array($plan) ? (string) ($plan['feature_flags_json'] ?? '') : '',
            ];
            $nativeAllowed = $this->provisioning->planAllowsNativeOutbound($planFeatureRow);
            $availableForwardCombinations = is_array($summary['available_forward_combinations'] ?? null) ? $summary['available_forward_combinations'] : [];
            $forwardLimit = $this->provisioning->forwardLimitForService($serviceId);
        } catch (\Throwable $throwable) {
            error_log('tenant forward preflight failed: ' . $throwable::class);
            SessionAuth::flash('error', $this->forwardCreateFlashMessage($throwable));
            return Response::redirect('/tenant/services');
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                SessionAuth::validateCsrf($request);
                if (empty($features['allow_manage_forwards'])) {
                    throw new ApiException('feature_denied_manage_forwards', 403);
                }
                $this->assertTenantForwardQuota($serviceId, $forwardLimit);
                $payload = ForwardLineApiContract::normalizeLegacyCreate(
                    $this->normalizeTenantForwardPayload($request->body())
                );
                $this->provisioning->createForward(
                    $serviceId,
                    $payload,
                    'tenant',
                    null,
                    null,
                    $forwardLimit,
                    ['user_id' => (int) $user['id'], 'feature' => 'allow_manage_forwards']
                );
                SessionAuth::flash('success', 'Forward created.');
                return Response::redirect('/tenant/services/' . $serviceId);
            } catch (\Throwable $throwable) {
                SessionAuth::flash('error', $this->forwardCreateFlashMessage($throwable));
            }
        }

        // Forward Route is the user-facing selection unit; the underlying combinations stay hidden.
        $availableForwardRoutes = [];
        try {
            $availableForwardRoutes = $this->provisioning->availableForwardRoutesForService($serviceId);
        } catch (\Throwable $throwable) {
            $availableForwardRoutes = [];
        }
        $routeOptions = '<option value="">Select a forward route</option>';
        foreach ($availableForwardRoutes as $route) {
            $routeOptions .= '<option value="' . (int) ($route['id'] ?? 0) . '"'
                . ' data-description="' . $this->escape((string) ($route['tenant_description'] ?? '')) . '"'
                . ' data-badge="' . $this->escape((string) ($route['tenant_badge'] ?? '')) . '">'
                . $this->escape((string) ($route['tenant_label'] ?? $route['name'] ?? ''))
                . '</option>';
        }

        $csrf = $this->escape(SessionAuth::csrfToken());
        $emptyState = $availableForwardRoutes === []
            ? '<div class="flash danger">No route package is available for this service yet. Ask admin to publish a forward route first.</div>'
            : '';

        $body = '
            <div class="hero">
              <div>
                <h2>Create Forward</h2>
                <p class="muted">Choose one route package, then fill your backend target. The panel keeps all backend topology hidden.</p>
              </div>
            </div>
            <div class="card">
              ' . (empty($features['allow_manage_forwards']) ? '<div class="flash danger">Forward creation is disabled for your plan/account.</div>' : '') . '
              ' . $emptyState . '
              <form method="post" action="/tenant/services/' . $serviceId . '/create-forward">
                <input type="hidden" name="csrf_token" value="' . $csrf . '">
                <input type="hidden" name="target_source" value="manual">
                <div class="form-grid">
                  <div class="field"><label>Forward Route</label><select name="forward_route_id" required>' . $routeOptions . '</select></div>
                  ' . ($nativeAllowed ? '<div class="field"><label><input type="checkbox" name="native_outbound" value="1" id="native-outbound-toggle"> Native outbound</label><div class="field-note">Use the plan-provided Ultra SOCKS5 exit. The panel will assign a local loopback target.</div></div>' : '') . '
                  <div class="field"><label>Target Host</label><input type="text" name="target_host" id="native-target-host" placeholder="10.0.0.5 or example.com" required></div>
                  <div class="field"><label>Target Port</label><input type="number" name="target_port" id="native-target-port" placeholder="443" required></div>
                  <div class="field"><label>Local Listen Port</label><input type="number" name="listen_port" placeholder="auto from line range"></div>
                  <div class="field"><label>Transport</label><select name="transport_mode"><option value="tcp">TCP</option><option value="tcp+udp">TCP + UDP</option><option value="udp">UDP</option></select></div>
                </div>
                <div class="field-note" id="forward-combination-help">Choose one admin-defined route package. The panel keeps routing details hidden until you create the forward.</div>
                <div class="actions" style="margin-top:20px;">
                  <button class="button" type="submit" ' . (empty($features['allow_manage_forwards']) || $availableForwardRoutes === [] ? 'disabled' : '') . '>Create Forward</button>
                  <a class="button ghost" href="/tenant/services/' . $serviceId . '">Cancel</a>
                </div>
              </form>
              ' . ($nativeAllowed ? '<script>(function(){const toggle=document.getElementById("native-outbound-toggle");const host=document.getElementById("native-target-host");const port=document.getElementById("native-target-port");function sync(){const native=toggle.checked;host.disabled=native;port.disabled=native;host.required=!native;port.required=!native;host.placeholder=native?"Generated by panel":"10.0.0.5 or example.com";port.placeholder=native?"Generated by panel":"443";}toggle.addEventListener("change",sync);sync();}());</script>' : '') . '
            </div>';

        return $this->page('Create Forward', $body, $user, $this->tenantNav('wizard', $serviceId));
    }

    public function editForward(Request $request, array $params): Response
    {
        try {
            $user = SessionAuth::currentUser($this->db);
            if ($user === null || (string) $user['role'] !== 'tenant') {
                return Response::redirect('/login');
            }

            $view = PrecisionGridUi::normalizeView(
                (string) $request->input('view', 'detail'),
                ['detail'],
                'detail'
            );

            $serviceId = $this->idParam($params);
            $forwardId = (int) ($params['fid'] ?? 0);
            $forward = $this->db->fetchOne(
                'SELECT f.*, s.user_id, s.status AS service_status, fr.tenant_label AS forward_route_label
                 FROM forwards f
                 INNER JOIN services s ON s.id = f.service_id
                 LEFT JOIN forward_routes fr ON fr.id = f.forward_route_id
                 WHERE f.id = :id AND f.service_id = :service_id AND f.enabled = 1 LIMIT 1',
                ['id' => $forwardId, 'service_id' => $serviceId]
            );
            if ($forward === null || (int) $forward['user_id'] !== (int) $user['id']) {
                throw new ApiException('forward_not_found', 404);
            }
            $this->assertTenantServiceActive($forward);
            $this->assertTenantForwardFeature($serviceId, 'allow_manage_forwards', 'feature_denied_manage_forwards');
            $plan = $this->db->fetchOne(
                'SELECT feature_flags_json FROM service_plans WHERE id = :id LIMIT 1',
                ['id' => (int) ($forward['plan_id'] ?? 0)]
            );
            if (!is_array($plan)) {
                $serviceRow = $this->db->fetchOne('SELECT plan_id FROM services WHERE id = :id LIMIT 1', ['id' => $serviceId]);
                $plan = $this->db->fetchOne(
                    'SELECT feature_flags_json FROM service_plans WHERE id = :id LIMIT 1',
                    ['id' => (int) ($serviceRow['plan_id'] ?? 0)]
                );
            }
            $nativeAllowed = $this->provisioning->planAllowsNativeOutbound([
                'plan_feature_flags_json' => is_array($plan) ? (string) ($plan['feature_flags_json'] ?? '') : '',
            ]);

        $csrf = $this->escape(SessionAuth::csrfToken());
        $targetHostField = (string) ($forward['target_source'] ?? 'manual') === 'service_ip'
            ? '<div class="field"><label>Target Host (managed by service)</label><div class="value-chip">' . $this->escape((string) $forward['target_host']) . '</div></div>'
            : '<div class="field"><label>Target Host</label><input type="text" name="target_host" value="' . $this->escape((string) $forward['target_host']) . '" required></div>';
        $body = '
            <div class="hero">
              <div>
                <h2>Edit Forward</h2>
                <p class="muted">Change your backend target settings without changing the admin-defined route package.</p>
              </div>
            </div>
            <div class="card">
              <form method="post" action="/tenant/services/' . $serviceId . '/forward/' . $forwardId . '/edit">
                <input type="hidden" name="csrf_token" value="' . $csrf . '">
                <div class="form-grid">
                  <div class="field"><label>Current Forward Route</label><div class="value-chip">' . $this->escape((string) ($forward['forward_route_label'] ?? '')) . '</div></div>
                  ' . ($nativeAllowed || !empty($forward['native_outbound_enabled']) ? '<div class="field"><label><input type="checkbox" name="native_outbound" value="1" id="native-outbound-toggle"' . (!empty($forward['native_outbound_enabled']) ? ' checked' : '') . '> Native outbound</label><div class="field-note">Native target is generated by the panel.</div></div>' : '') . '
                  ' . str_replace('name="target_host"', 'name="target_host" id="native-target-host"', $targetHostField) . '
                  <div class="field"><label>Target Port</label><input type="number" name="target_port" id="native-target-port" value="' . (int) $forward['target_port'] . '" required></div>
                  <div class="field"><label>Local Listen Port</label><input type="number" name="listen_port" value="' . (int) $forward['listen_port'] . '"></div>
                  <div class="field"><label>Transport</label><select name="transport_mode"><option value="tcp"' . ((string) $forward['transport_mode'] === 'tcp' ? ' selected' : '') . '>TCP</option><option value="tcp+udp"' . ((string) $forward['transport_mode'] === 'tcp+udp' ? ' selected' : '') . '>TCP + UDP</option><option value="udp"' . ((string) $forward['transport_mode'] === 'udp' ? ' selected' : '') . '>UDP</option></select></div>
                </div>
                <div class="actions" style="margin-top:20px;">
                  <button class="button" type="submit">Save Forward</button>
                  <a class="button ghost" href="/tenant/services/' . $serviceId . '">Cancel</a>
                </div>
              </form>
              ' . ($nativeAllowed || !empty($forward['native_outbound_enabled']) ? '<script>(function(){const toggle=document.getElementById("native-outbound-toggle");const host=document.getElementById("native-target-host");const port=document.getElementById("native-target-port");function sync(){const native=toggle.checked;host.disabled=native;port.disabled=native;host.required=!native;port.required=!native;}toggle.addEventListener("change",sync);sync();}());</script>' : '') . '
            </div>';

            return $this->page('Edit Forward', $body, $user, $this->tenantNav('service', $serviceId));
        } catch (\Throwable $throwable) {
            error_log('tenant forward edit preflight failed: ' . $throwable::class);
            SessionAuth::flash('error', $this->forwardCreateFlashMessage($throwable));
            return Response::redirect('/tenant/services');
        }
    }

    public function saveForwardEdit(Request $request, array $params): Response
    {
        $serviceId = 0;
        $forwardId = 0;

        try {
            $user = SessionAuth::currentUser($this->db);
            if ($user === null || (string) $user['role'] !== 'tenant') {
                return Response::redirect('/login');
            }

            $serviceId = $this->idParam($params);
            $forwardId = (int) ($params['fid'] ?? 0);
            SessionAuth::validateCsrf($request);
            $forward = $this->db->fetchOne(
                'SELECT f.*, s.user_id, s.status AS service_status
                 FROM forwards f
                 INNER JOIN services s ON s.id = f.service_id
                 WHERE f.id = :id AND f.service_id = :service_id AND f.enabled = 1 LIMIT 1',
                ['id' => $forwardId, 'service_id' => $serviceId]
            );
            if ($forward === null || (int) $forward['user_id'] !== (int) $user['id']) {
                throw new ApiException('forward_not_found', 404);
            }

            $this->assertTenantServiceActive($forward);
            $this->assertTenantForwardFeature($serviceId, 'allow_manage_forwards', 'feature_denied_manage_forwards');
            $changes = ForwardLineApiContract::normalizeLegacyUpdate(
                $this->normalizeTenantForwardEditPayload($request->body())
            );
            $payload = ForwardLineApiContract::mergePatch($forward, $changes);
            $structural = array_key_exists('native_outbound', $changes)
                || (array_key_exists('transport_mode', $changes)
                    && (string) $payload['transport_mode'] !== (string) ($forward['transport_mode'] ?? 'tcp'))
                || (array_key_exists('listen_port', $changes) && (int) $changes['listen_port'] === 0);
            if ($structural) {
                $this->provisioning->updateForward(
                    $forwardId,
                    $payload,
                    'tenant:' . (int) $user['id'],
                    (int) ($forward['api_revision'] ?? 1),
                    ['user_id' => (int) $user['id'], 'feature' => 'allow_manage_forwards']
                );
            } else {
                $this->provisioning->updateForwardNonStructural(
                    $forwardId,
                    $payload,
                    'tenant:' . (int) $user['id'],
                    (int) ($forward['api_revision'] ?? 1),
                    ['user_id' => (int) $user['id'], 'feature' => 'allow_manage_forwards']
                );
            }
            SessionAuth::flash('success', 'Forward updated.');
            return Response::redirect('/tenant/services/' . $serviceId);
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', $this->forwardCreateFlashMessage($throwable));
        }

        return $serviceId > 0 && $forwardId > 0
            ? Response::redirect('/tenant/services/' . $serviceId . '/forward/' . $forwardId . '/edit')
            : Response::redirect('/tenant/services');
    }

    public function forwardWizard(Request $request, array $params): Response
    {
        $serviceId = $this->idParam($params);
        return Response::redirect('/tenant/services/' . $serviceId . '/create-forward');
    }

    public function saveClient(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $services = $this->db->fetchAll('SELECT id FROM services WHERE user_id = :user_id', ['user_id' => $user['id']]);
            $features = $services !== [] ? ($this->provisioning->serviceSummary((int) $services[0]['id'])['effective_features'] ?? []) : [];
            if (empty($features['allow_manage_clients'])) {
                throw new ApiException('feature_denied_manage_clients', 403);
            }

            $allowedGroups = $this->provisioning->availableClientGroupsForUser((int) $user['id']);
            $groupId = (int) $request->input('group_id', 0);
            if ($allowedGroups !== [] && $groupId > 0) {
                $allowedGroupIds = array_map(static fn(array $group): int => (int) $group['id'], $allowedGroups);
                if (!in_array($groupId, $allowedGroupIds, true)) {
                    throw new ApiException('client_group_not_allowed', 422);
                }
            }

            $currentClientProfiles = count($this->provisioning->listClientsForUser((int) $user['id']));
            $maxClientProfiles = $this->provisioning->maxClientProfilesForTenantOwnedClients((int) $user['id']);
            $clientId = (int) $request->input('id', 0);
            $isCreate = $clientId <= 0;

            // Security: a tenant may only edit a client it owns. saveClient()
            // loads/updates purely by id, so without this an attacker could POST
            // id=<victim client> and take the client (and its bound forwards)
            // over by resetting owner_user_id to themselves.
            if (!$isCreate) {
                $ownedClient = $this->db->fetchOne(
                    'SELECT id FROM clients WHERE id = :id AND owner_user_id = :user_id LIMIT 1',
                    ['id' => $clientId, 'user_id' => $user['id']]
                );
                if ($ownedClient === null) {
                    throw new ApiException('client_not_found', 404);
                }
            }

            // Security: the online device limit is a billed entitlement. It is
            // clamped to the owner's plan default, never taken raw from the form.
            $planDeviceLimit = $this->provisioning->defaultClientOnlineDeviceLimitForTenant((int) $user['id']);
            $deviceLimit = min(max(1, (int) $request->input('online_device_limit', 1)), $planDeviceLimit);

            if ($isCreate) {
                if ($currentClientProfiles >= $maxClientProfiles) {
                    throw new ApiException(
                        'tenant_client_profile_limit_reached',
                        422,
                        ['message' => 'This package allows up to ' . $maxClientProfiles . ' client profiles.']
                    );
                }
            }
            $selectedCarriersInput = $request->input('selected_carriers', []);
            $selectedCarrierParts = is_array($selectedCarriersInput) ? $selectedCarriersInput : [$selectedCarriersInput];
            $selectedCarriers = [];
            foreach ($selectedCarrierParts as $value) {
                foreach (explode(',', (string) $value) as $carrier) {
                    $carrier = strtolower(trim($carrier));
                    if ($carrier !== '') {
                        $selectedCarriers[] = $carrier;
                    }
                }
            }
            $selectedCarriers = array_values(array_unique($selectedCarriers));
            $runtimePlatformScope = strtolower(trim((string) $request->input('runtime_platform_scope', 'all')));
            if (!in_array($runtimePlatformScope, ['all', 'linux'], true)) {
                $runtimePlatformScope = 'all';
            }

            $this->provisioning->saveClient([
                'id' => $request->input('id'),
                'owner_user_id' => $user['id'],
                'group_id' => $groupId,
                'name' => $request->input('name'),
                'display_host' => $request->input('display_host'),
                'bind_ip' => $request->input('bind_ip'),
                'nic_name' => $request->input('nic_name'),
                'port_range_start' => $request->input('port_range_start'),
                'port_range_end' => $request->input('port_range_end'),
                'online_device_limit' => $deviceLimit,
                'traffic_multiplier' => $request->input('traffic_multiplier', '1.00'),
                'default_endpoint_mode' => $request->input('default_endpoint_mode', 'slave_group'),
                'default_node_group_key' => $request->input('default_node_group_key'),
                'default_forward_chain_id' => $request->input('default_forward_chain_id'),
                'agent_config_json' => $request->input('agent_config_json'),
                'status' => $request->input('status', 'active'),
                'allow_paid_lines' => $request->input('allow_paid_lines', 0),
                'selected_carriers' => $selectedCarriers,
                'runtime_platform_scope' => $runtimePlatformScope,
                'allow_custom_endpoints' => 0,
                'force_slave_mode' => 0,
                'allow_forward_chain' => 1,
                'allow_latency_test' => 1,
                'allow_inbound_proxy' => 0,
            ]);
            SessionAuth::flash('success', 'Client saved.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to save client: ' . $this->displayThrowableMessage($throwable));
        }

        return Response::redirect('/tenant/clients');
    }

    public function requestClientDiagnostics(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $clientId = $this->idParam($params);
            $client = $this->db->fetchOne('SELECT * FROM clients WHERE id = :id AND owner_user_id = :user_id LIMIT 1', ['id' => $clientId, 'user_id' => $user['id']]);
            if ($client === null) {
                throw new ApiException('client_not_found', 404);
            }
            $debugCollection = (new DebugCollectionStore($this->db, $this->config))->create($clientId);
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
            SessionAuth::flash('success', 'Diagnostics requested. Debug collection expires at ' . (string) ($debugCollection['expires_at'] ?? '') . '.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to request diagnostics: ' . $throwable->getMessage());
        }

        return Response::redirect('/tenant/clients');
    }

    public function requestClientUpdate(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $clientId = $this->idParam($params);
            $client = $this->db->fetchOne('SELECT * FROM clients WHERE id = :id AND owner_user_id = :user_id LIMIT 1', ['id' => $clientId, 'user_id' => $user['id']]);
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

        return Response::redirect('/tenant/clients');
    }

    public function deleteClient(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $services = $this->db->fetchAll('SELECT id FROM services WHERE user_id = :user_id', ['user_id' => $user['id']]);
            $features = $services !== [] ? ($this->provisioning->serviceSummary((int) $services[0]['id'])['effective_features'] ?? []) : [];
            if (empty($features['allow_delete_clients'])) {
                throw new ApiException('feature_denied_delete_clients', 403);
            }

            $clientId = $this->idParam($params);
            $client = $this->db->fetchOne('SELECT * FROM clients WHERE id = :id AND owner_user_id = :user_id LIMIT 1', ['id' => $clientId, 'user_id' => $user['id']]);
            if ($client === null) {
                throw new ApiException('client_not_found', 404);
            }
            $result = $this->provisioning->deleteClient($clientId);
            SessionAuth::flash(
                'success',
                'Client deleted.'
                . ((int) ($result['detached_disabled_forwards'] ?? 0) > 0
                    ? ' Detached ' . (int) $result['detached_disabled_forwards'] . ' disabled forward reference(s).'
                    : '')
            );
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to delete client: ' . $this->displayThrowableMessage($throwable));
        }

        return Response::redirect('/tenant/clients');
    }

    public function createForward(Request $request, array $params): Response
    {
        $serviceId = 0;
        try {
            $user = SessionAuth::currentUser($this->db);
            if ($user === null || (string) $user['role'] !== 'tenant') {
                return Response::redirect('/login');
            }

            $serviceId = $this->idParam($params);
            SessionAuth::validateCsrf($request);
            $service = $this->db->fetchOne('SELECT * FROM services WHERE id = :id AND user_id = :user_id LIMIT 1', [
                'id' => $serviceId,
                'user_id' => $user['id'],
            ]);
            if ($service === null) {
                throw new ApiException('service_not_found', 404);
            }
            $this->assertTenantServiceActive($service);
            $summary = $this->provisioning->serviceSummary($serviceId);
            if (empty(($summary['effective_features'] ?? [])['allow_manage_forwards'])) {
                throw new ApiException('feature_denied_manage_forwards', 403);
            }
            $forwardLimit = $this->provisioning->forwardLimitForService($serviceId);
            $this->assertTenantForwardQuota($serviceId, $forwardLimit);

            $payload = ForwardLineApiContract::normalizeLegacyCreate(
                $this->normalizeTenantForwardPayload($request->body())
            );
            $this->provisioning->createForward(
                $serviceId,
                $payload,
                'tenant',
                null,
                null,
                $forwardLimit,
                ['user_id' => (int) $user['id'], 'feature' => 'allow_manage_forwards']
            );
            SessionAuth::flash('success', 'Forward created and queued for provisioning.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', $this->forwardCreateFlashMessage($throwable));
        }

        return $serviceId > 0
            ? Response::redirect('/tenant/services/' . $serviceId)
            : Response::redirect('/tenant/services');
    }

    public function generateEnrollmentToken(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        $serviceId = $this->idParam($params);
        try {
            SessionAuth::validateCsrf($request);
            $service = $this->db->fetchOne('SELECT * FROM services WHERE id = :id AND user_id = :user_id LIMIT 1', [
                'id' => $serviceId,
                'user_id' => $user['id'],
            ]);
            if ($service === null) {
                throw new ApiException('service_not_found', 404);
            }
            $summary = $this->provisioning->serviceSummary($serviceId);
            if (empty(($summary['effective_features'] ?? [])['allow_custom_slaves'])) {
                throw new ApiException('feature_denied_custom_slaves', 403);
            }

            $token = bin2hex(random_bytes(32));
            $this->db->insert('node_enrollment_tokens', [
                'token' => $token,
                'service_id' => $serviceId,
                'created_by_user_id' => (int) $user['id'],
                'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);

            $masterUrl = $this->agentBaseUrl();
            $artifactUrl = $this->artifactBaseUrl();
            $installCmd = 'curl -fsSL ' . $artifactUrl . '/artifacts/install-slave.sh | bash -s -- --master ' . $masterUrl . ' --artifact-base ' . $artifactUrl . ' --token ' . $token;
            SessionAuth::flash('success', 'Enrollment token generated (valid 24h). Install command: <code>' . $this->escape($installCmd) . '</code>');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to generate token: ' . $this->displayThrowableMessage($throwable));
        }

        return Response::redirect('/tenant/services/' . $serviceId);
    }

    public function removeUserNode(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        $nodeId = $this->idParam($params);
        try {
            SessionAuth::validateCsrf($request);
            $node = $this->db->fetchOne(
                'SELECT n.*, s.user_id, s.id AS service_id
                 FROM nodes n
                 INNER JOIN services s ON s.id = n.owner_service_id
                 WHERE n.id = :id AND n.enrollment_type = :enrollment_type LIMIT 1',
                ['id' => $nodeId, 'enrollment_type' => 'user_enrolled']
            );
            if ($node === null || (int) $node['user_id'] !== (int) $user['id']) {
                throw new ApiException('node_not_found', 404);
            }

            $this->db->execute('UPDATE nodes SET status = :status, updated_at = :updated_at WHERE id = :id', [
                'status' => 'inactive',
                'updated_at' => gmdate('Y-m-d H:i:s'),
                'id' => $nodeId,
            ]);
            SessionAuth::flash('success', 'Node removed.');
            return Response::redirect('/tenant/services/' . (int) $node['service_id']);
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to remove node: ' . $this->displayThrowableMessage($throwable));
        }

        return Response::redirect('/tenant/services');
    }

    public function deleteForward(Request $request, array $params): Response
    {
        try {
            $user = SessionAuth::currentUser($this->db);
            if ($user === null || (string) $user['role'] !== 'tenant') {
                return Response::redirect('/login');
            }

            $forwardId = $this->idParam($params);
            SessionAuth::validateCsrf($request);
            $forward = $this->db->fetchOne(
                'SELECT f.*, s.user_id, s.status AS service_status
                 FROM forwards f
                 INNER JOIN services s ON s.id = f.service_id
                 WHERE f.id = :id AND f.enabled = 1 LIMIT 1',
                ['id' => $forwardId]
            );
            if ($forward === null || (int) $forward['user_id'] !== (int) $user['id']) {
                throw new ApiException('forward_not_found', 404);
            }
            $this->assertTenantServiceActive($forward);
            $summary = $this->provisioning->serviceSummary((int) $forward['service_id']);
            if (empty(($summary['effective_features'] ?? [])['allow_delete_forwards'])) {
                throw new ApiException('feature_denied_delete_forwards', 403);
            }

            $this->provisioning->deleteForward(
                $forwardId,
                'tenant:' . (int) $user['id'],
                (int) ($forward['api_revision'] ?? 1),
                ['user_id' => (int) $user['id'], 'feature' => 'allow_delete_forwards']
            );
            SessionAuth::flash('success', 'Forward deleted.');
            return Response::redirect('/tenant/services/' . (int) $forward['service_id']);
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', $this->forwardCreateFlashMessage($throwable));
        }

        return Response::redirect('/tenant/forwards');
    }

    public function toggleForward(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        $serviceId = $this->idParam($params);
        $redirect = '/tenant/services/' . $serviceId;
        try {
            SessionAuth::validateCsrf($request);
            $forwardId = (int) ($params['fid'] ?? 0);
            if ($forwardId <= 0) {
                throw new ApiException('forward_not_found', 404);
            }
            $action = strtolower(trim((string) $request->input('action', '')));
            if (!in_array($action, ['start', 'pause'], true)) {
                throw new ApiException('invalid_forward_action', 422);
            }

            $forward = $this->db->fetchOne(
                'SELECT f.*, s.user_id, s.status AS service_status
                 FROM forwards f
                 INNER JOIN services s ON s.id = f.service_id
                 WHERE f.id = :id AND f.service_id = :service_id AND f.user_deleted_at IS NULL LIMIT 1',
                ['id' => $forwardId, 'service_id' => $serviceId]
            );
            if ($forward === null || (int) $forward['user_id'] !== (int) $user['id']) {
                throw new ApiException('forward_not_found', 404);
            }
            $redirect = '/tenant/services/' . (int) $forward['service_id'];
            $this->assertTenantServiceActive($forward);
            $summary = $this->provisioning->serviceSummary((int) $forward['service_id']);
            if (empty(($summary['effective_features'] ?? [])['allow_manage_forwards'])) {
                throw new ApiException('feature_denied_manage_forwards', 403);
            }

            $this->provisioning->setForwardEnabled(
                $forwardId,
                $action === 'start',
                'tenant:' . (int) $user['id'],
                (int) ($forward['api_revision'] ?? 1),
                ['user_id' => (int) $user['id'], 'feature' => 'allow_manage_forwards']
            );
            SessionAuth::flash('success', $action === 'start' ? 'Forward started.' : 'Forward paused.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', $this->forwardCreateFlashMessage($throwable));
        }

        return Response::redirect($redirect);
    }

    public function regenerateClientToken(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        $serviceId = $this->idParam($params);
        try {
            SessionAuth::validateCsrf($request);
            $service = $this->db->fetchOne('SELECT * FROM services WHERE id = :id AND user_id = :user_id LIMIT 1', [
                'id' => $serviceId,
                'user_id' => $user['id'],
            ]);
            if ($service === null) {
                throw new ApiException('service_not_found', 404);
            }
            $summary = $this->provisioning->serviceSummary($serviceId);
            if (empty(($summary['effective_features'] ?? [])['allow_rotate_client_token'])) {
                throw new ApiException('feature_denied_rotate_client_token', 403);
            }

            $this->provisioning->regenerateClientToken($serviceId);
            SessionAuth::flash('success', 'Client token rotated.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to rotate client token: ' . $throwable->getMessage());
        }

        return Response::redirect('/tenant/services/' . $serviceId);
    }

    public function slaveGroupsPage(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        $groups = $this->provisioning->listUserSlaveGroups((int) $user['id']);
        $csrf = $this->escape(SessionAuth::csrfToken());

        $groupCards = '';
        foreach ($groups as $group) {
            $isOwner = (string) ($group['my_role'] ?? '') === 'owner';
            $memberList = '';
            foreach ($group['members'] as $member) {
                $memberList .= '<span class="badge info">' . $this->escape((string) $member['email']);
                if ($isOwner && (int) $member['user_id'] !== (int) $user['id']) {
                    $memberList .= ' <form class="inline" method="post" action="/tenant/slave-groups/' . (int) $group['id'] . '/kick">
                        <input type="hidden" name="csrf_token" value="' . $csrf . '">
                        <input type="hidden" name="target_user_id" value="' . (int) $member['user_id'] . '">
                        <button type="submit" style="background:none;border:none;color:var(--danger);cursor:pointer;font-size:12px;">x</button>
                    </form>';
                }
                $memberList .= '</span> ';
            }

            $nodeList = '';
            foreach ($group['shared_nodes'] as $node) {
                $nodeList .= $this->escape((string) $node['name']) . ' (' . $this->escape((string) $node['public_ip']) . '), ';
            }
            $nodeList = rtrim($nodeList, ', ') ?: 'None';

            $actions = '';
            if ($isOwner) {
                $actions = '<div class="muted" style="margin-top:10px;">Invite Code: <strong>' . $this->escape((string) $group['invite_code']) . '</strong> '
                    . $this->renderCopyButton('Copy', (string) $group['invite_code']) . '</div>';
            } else {
                $actions = '<form class="inline" method="post" action="/tenant/slave-groups/' . (int) $group['id'] . '/leave" style="margin-top:10px;">
                    <input type="hidden" name="csrf_token" value="' . $csrf . '">
                    <button type="submit" class="button danger">Leave Group</button>
                </form>';
            }

            $roleLabel = $isOwner ? 'Owner' : 'Member';
            $groupCards .= '<section class="card" style="margin-bottom:14px;">
                <h3>' . $this->escape((string) $group['name']) . ' <span class="badge info">' . $roleLabel . '</span></h3>
                <div class="muted">Members: ' . $memberList . '</div>
                <div class="muted" style="margin-top:8px;">Shared Slaves: ' . $this->escape($nodeList) . '</div>
                ' . $actions . '
            </section>';
        }

        if ($groupCards === '') {
            $groupCards = '<div class="muted">You have not created or joined any slave groups yet.</div>';
        }

        // User-owned nodes with sharing controls
        $ownedNodes = $this->db->fetchAll(
            'SELECT n.id, n.name, n.public_ip, n.status, n.sharing_mode, n.user_slave_group_id
             FROM nodes n
             INNER JOIN services s ON s.id = n.owner_service_id
             WHERE s.user_id = :uid
             ORDER BY n.name ASC',
            ['uid' => (int) $user['id']]
        );

        $ownedGroupOptions = [];
        foreach ($groups as $g) {
            if ((string) ($g['my_role'] ?? '') === 'owner') {
                $ownedGroupOptions[] = $g;
            }
        }

        $nodeRows = '';
        foreach ($ownedNodes as $oNode) {
            $sharingTd = '<form method="post" action="/tenant/nodes/' . (int) $oNode['id'] . '/sharing" class="inline">
                <input type="hidden" name="csrf_token" value="' . $csrf . '">
                <input type="hidden" name="sharing_mode" value="private">
                <input type="hidden" name="user_slave_group_id" value="0">
                <select onchange="var v=this.value.split(\':\');this.form.sharing_mode.value=v[0];this.form.user_slave_group_id.value=v[1]||0;this.form.submit();">
                    <option value="private:0"' . ((string) ($oNode['sharing_mode'] ?? 'private') === 'private' ? ' selected' : '') . '>Private</option>';
            foreach ($ownedGroupOptions as $og) {
                $val = 'group_shared:' . (int) $og['id'];
                $isSelected = (string) ($oNode['sharing_mode'] ?? '') === 'group_shared' && (int) ($oNode['user_slave_group_id'] ?? 0) === (int) $og['id'];
                $sharingTd .= '<option value="' . $this->escape($val) . '"' . ($isSelected ? ' selected' : '') . '>Share to: ' . $this->escape((string) $og['name']) . '</option>';
            }
            $sharingTd .= '</select>
            </form>';

            $nodeRows .= '<tr>
                <td>' . $this->escape((string) $oNode['name']) . '</td>
                <td>' . $this->escape((string) $oNode['public_ip']) . '</td>
                <td><span class="badge ' . ((string) $oNode['status'] === 'active' ? 'success' : 'danger') . '">' . $this->escape((string) $oNode['status']) . '</span></td>
                <td>' . $sharingTd . '</td>
            </tr>';
        }

        $ownedNodesSection = '';
        if ($nodeRows !== '') {
            $ownedNodesSection = '<section class="card" style="margin-top:18px;">
                <h3>My Slaves — Sharing Controls</h3>
                <p class="muted">Change sharing mode for your owned slaves. Private = only you can use. Share to a group = all group members can use.</p>
                <div class="table-wrap">
                  <table>
                    <thead><tr><th>Name</th><th>IP</th><th>Status</th><th>Sharing</th></tr></thead>
                    <tbody>' . $nodeRows . '</tbody>
                  </table>
                </div>
            </section>';
        }

        $body = '
            <div class="hero">
              <div>
                <h2>Node Groups</h2>
                <p class="muted">Create sharing groups, invite others, and share your nodes with group members.</p>
              </div>
            </div>
            <div class="grid two">
              <section class="card">
                <h3>Create New Group</h3>
                <form method="post" action="/tenant/slave-groups">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <input type="hidden" name="action" value="create">
                  <div class="field"><label>Group Name</label><input type="text" name="name" placeholder="My Team" required></div>
                  <div class="actions"><button class="button" type="submit">Create Group</button></div>
                </form>
              </section>
              <section class="card">
                <h3>Join Group by Invite Code</h3>
                <form method="post" action="/tenant/slave-groups/join">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <div class="field"><label>Invite Code</label><input type="text" name="invite_code" placeholder="abc123def456" required></div>
                  <div class="actions"><button class="button" type="submit">Join Group</button></div>
                </form>
              </section>
            </div>
            <div style="margin-top:18px;">' . $groupCards . '</div>
            ' . $ownedNodesSection;

        return $this->page('Node Groups', $body, $user, $this->tenantNav('slave-groups'));
    }

    public function saveSlaveGroup(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }
        try {
            SessionAuth::validateCsrf($request);
            $name = trim((string) $request->input('name', ''));
            if ($name === '') {
                throw new \Leikwan\Master\Exceptions\ApiException('name_required', 422);
            }
            $this->provisioning->createUserSlaveGroup((int) $user['id'], $name);
            SessionAuth::flash('success', 'Group created.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed: ' . $throwable->getMessage());
        }
        return Response::redirect('/tenant/slave-groups');
    }

    public function joinSlaveGroup(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }
        try {
            SessionAuth::validateCsrf($request);
            $code = trim((string) $request->input('invite_code', ''));
            $this->provisioning->joinUserSlaveGroup((int) $user['id'], $code);
            SessionAuth::flash('success', 'Joined group successfully.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed: ' . $throwable->getMessage());
        }
        return Response::redirect('/tenant/slave-groups');
    }

    public function leaveSlaveGroup(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }
        try {
            SessionAuth::validateCsrf($request);
            $groupId = $this->idParam($params);
            $this->provisioning->leaveUserSlaveGroup((int) $user['id'], $groupId);
            SessionAuth::flash('success', 'Left group.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed: ' . $throwable->getMessage());
        }
        return Response::redirect('/tenant/slave-groups');
    }

    public function kickSlaveGroupMember(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }
        try {
            SessionAuth::validateCsrf($request);
            $groupId = $this->idParam($params);
            $targetUserId = (int) $request->input('target_user_id', 0);
            $this->provisioning->kickUserSlaveGroupMember((int) $user['id'], $groupId, $targetUserId);
            SessionAuth::flash('success', 'Member removed.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed: ' . $throwable->getMessage());
        }
        return Response::redirect('/tenant/slave-groups');
    }

    public function updateNodeSharing(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }
        try {
            SessionAuth::validateCsrf($request);
            $nodeId = $this->idParam($params);
            $mode = trim((string) $request->input('sharing_mode', 'private'));
            $groupId = (int) $request->input('user_slave_group_id', 0);
            if ($mode === 'group_shared' && $groupId <= 0) {
                throw new \Leikwan\Master\Exceptions\ApiException('group_required', 422);
            }
            $this->provisioning->updateNodeSharing($nodeId, (int) $user['id'], $mode, $mode === 'group_shared' ? $groupId : null);
            $modeLabel = $mode === 'group_shared' ? 'shared to group' : 'private';
            SessionAuth::flash('success', 'Sharing updated: node set to ' . $modeLabel . '.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed: ' . $throwable->getMessage());
        }
        return Response::redirect('/tenant/slave-groups');
    }

    /** @param array<string, mixed> $serviceOrForward */
    private function assertTenantServiceActive(array $serviceOrForward): void
    {
        $status = (string) ($serviceOrForward['status'] ?? $serviceOrForward['service_status'] ?? '');
        if ($status !== 'active') {
            throw new ApiException('service_inactive', 409);
        }
    }

    /** @param array<string, mixed> $features */
    private function assertTenantForwardFeature(int $serviceId, string $feature, string $errorCode): void
    {
        $summary = $this->provisioning->serviceSummary($serviceId);
        $features = $summary['effective_features'] ?? [];
        if (!is_array($features) || empty($features[$feature])) {
            throw new ApiException($errorCode, 403);
        }
    }

    private function assertTenantForwardQuota(int $serviceId, ?int $limit = null): void
    {
        $limit ??= $this->provisioning->forwardLimitForService($serviceId);
        $active = $this->db->fetchOne(
            'SELECT COUNT(*) AS active_forward_count
             FROM forwards
             WHERE service_id = :service_id AND enabled = 1',
            ['service_id' => $serviceId]
        );
        if ((int) ($active['active_forward_count'] ?? 0) >= $limit) {
            throw new ApiException('forward_quota_exceeded', 409);
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeTenantForwardPayload(array $payload): array
    {
        $targetSource = strtolower(trim((string) ($payload['target_source'] ?? '')));
        if ($targetSource === '') {
            $targetSource = 'manual';
        }
        $transportInput = strtolower(trim((string) ($payload['transport_mode'] ?? 'tcp')));

        $listenPort = $payload['listen_port'] ?? null;
        if ($listenPort === '' || $listenPort === null) {
            $listenPort = null;
        } else {
            $listenPort = (int) $listenPort;
        }

        $normalized = [
            'forward_route_id' => max(0, (int) ($payload['forward_route_id'] ?? 0)),
            'listen_port' => $listenPort,
            'target_host' => trim((string) ($payload['target_host'] ?? '')),
            'target_port' => max(0, (int) ($payload['target_port'] ?? 0)),
            'target_source' => $targetSource,
            'transport_mode' => match ($transportInput) {
                'udp' => 'udp',
                'tcp+udp', 'udp+tcp', 'both' => 'tcp+udp',
                'tcp' => 'tcp',
                default => $transportInput,
            },
            'native_outbound' => in_array(strtolower(trim((string) ($payload['native_outbound'] ?? ''))), ['1', 'true', 'on'], true),
        ];

        if ($targetSource === 'service_ip') {
            $normalized['target_service_id'] = max(0, (int) ($payload['target_service_id'] ?? 0));
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeTenantForwardEditPayload(array $payload): array
    {
        $listenPort = $payload['listen_port'] ?? null;
        if ($listenPort === '' || $listenPort === null) {
            $listenPort = null;
        } else {
            $listenPort = (int) $listenPort;
        }

        $transportInput = strtolower(trim((string) ($payload['transport_mode'] ?? 'tcp')));
        $normalized = [
            'listen_port' => $listenPort,
            'target_port' => max(0, (int) ($payload['target_port'] ?? 0)),
            'transport_mode' => match ($transportInput) {
                'udp' => 'udp',
                'tcp+udp', 'udp+tcp', 'both' => 'tcp+udp',
                'tcp' => 'tcp',
                default => $transportInput,
            },
            'native_outbound' => in_array(strtolower(trim((string) ($payload['native_outbound'] ?? ''))), ['1', 'true', 'on'], true),
        ];
        if (array_key_exists('target_host', $payload)) {
            $normalized['target_host'] = trim((string) $payload['target_host']);
        }
        return $normalized;
    }

    /**
     * @param array<string, mixed> $service
     */
    private function isBondReady(array $service): bool
    {
        $serviceToken = trim((string) ($service['client_token'] ?? ''));
        if ($serviceToken !== '') {
            return true;
        }

        $tokenSecret = trim((string) ($this->config->get('BOND_TOKEN_SECRET', '') ?? ''));
        return $tokenSecret !== '';
    }

    private function defaultBondRemotePort(): int
    {
        $legacyPort = (int) ($this->config->getInt('BOND_AGGREGATOR_PORT', 0) ?? 0);
        if ($legacyPort > 0) {
            return $legacyPort;
        }

        $defaultPort = (int) ($this->config->getInt('BOND_DEFAULT_REMOTE_PORT', 9000) ?? 9000);
        return $defaultPort > 0 ? $defaultPort : 9000;
    }

    /**
     * @param array<int, array<string, mixed>> $points
     */
    private function renderSidecarPointOptions(array $points, string $selectedValue = ''): string
    {
        $options = '<option value="">Manual entry</option>';
        foreach ($points as $point) {
            if (!is_array($point)) {
                continue;
            }

            $id = (int) ($point['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $selected = ((string) $id === $selectedValue) ? ' selected' : '';
            $options .= '<option value="' . $id . '"' . $selected
                . ' data-host="' . $this->escape(trim((string) ($point['sidecar_host'] ?? ''))) . '"'
                . ' data-port="' . $this->sidecarBondListenPort($point) . '"'
                . ' data-bond-port="' . $this->sidecarBondListenPort($point) . '"'
                . ' data-mptcp-port="' . $this->sidecarMptcpListenPort($point) . '">'
                . $this->escape($this->sidecarPointOptionLabel($point))
                . '</option>';
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $point
     */
    private function sidecarPointOptionLabel(array $point): string
    {
        $name = trim((string) ($point['name'] ?? 'Sidecar'));
        $host = trim((string) ($point['sidecar_host'] ?? ''));
        $scope = (int) ($point['owner_user_id'] ?? 0) > 0 ? 'Mine' : 'Shared';
        $target = $host !== '' ? $host . ' (' . $this->sidecarPortSummary($point) . ')' : '';

        if ($target === '') {
            return $name . ' [' . $scope . ']';
        }

        return $name . ' @ ' . $target . ' [' . $scope . ']';
    }

    /**
     * @param array<int, array<string, mixed>> $points
     * @return array<string, mixed>|null
     */
    private function selectedSidecarPoint(array $points, string $selectedValue): ?array
    {
        $selectedId = (int) $selectedValue;
        if ($selectedId <= 0) {
            return null;
        }

        foreach ($points as $point) {
            if (is_array($point) && (int) ($point['id'] ?? 0) === $selectedId) {
                return $point;
            }
        }

        return null;
    }

    private function renderBondSidecarBindingScript(
        string $modeSelectId,
        string $pointSelectId,
        string $hostInputId,
        string $portInputId,
        ?string $hostFieldId = null,
        ?string $portFieldId = null,
        ?string $summaryId = null
    ): string {
        return '
            <script>
              (function () {
                var modeSelect = document.getElementById(' . json_encode($modeSelectId) . ');
                var pointSelect = document.getElementById(' . json_encode($pointSelectId) . ');
                var hostInput = document.getElementById(' . json_encode($hostInputId) . ');
                var portInput = document.getElementById(' . json_encode($portInputId) . ');
                var hostField = ' . ($hostFieldId !== null ? 'document.getElementById(' . json_encode($hostFieldId) . ')' : 'null') . ';
                var portField = ' . ($portFieldId !== null ? 'document.getElementById(' . json_encode($portFieldId) . ')' : 'null') . ';
                var summary = ' . ($summaryId !== null ? 'document.getElementById(' . json_encode($summaryId) . ')' : 'null') . ';

                if (!modeSelect || !pointSelect || !hostInput || !portInput) {
                  return;
                }

                function selectedPoint() {
                  var option = pointSelect.options[pointSelect.selectedIndex];
                  if (!option || !option.value) {
                    return null;
                  }
                  return option;
                }

                function resetManualFields() {
                  hostInput.value = "";
                  portInput.value = portInput.dataset.defaultPort || "";
                }

                function isSidecarMode() {
                  var mode = (modeSelect.value || "").toLowerCase();
                  return mode === "bond" || mode === "mptcp";
                }

                function selectedPortForMode(option) {
                  var mode = (modeSelect.value || "").toLowerCase();
                  if (!option) {
                    return portInput.dataset.defaultPort || "";
                  }
                  if (mode === "mptcp") {
                    return option.dataset.mptcpPort || option.dataset.port || portInput.dataset.defaultPort || "";
                  }
                  return option.dataset.bondPort || option.dataset.port || portInput.dataset.defaultPort || "";
                }

                function updateSummary(transportMode, option) {
                  if (!summary) {
                    return;
                  }
                  if (!transportMode) {
                    summary.style.display = "none";
                    summary.textContent = "";
                    return;
                  }
                  summary.style.display = "block";
                  summary.textContent = option
                    ? "Using saved point: " + option.text
                    : "Manual entry: fill the target transport host and port.";
                }

                function updateFields(transportMode, option) {
                  if (hostField) {
                    hostField.style.display = transportMode && !option ? "" : "none";
                  }
                  if (portField) {
                    portField.style.display = transportMode && !option ? "" : "none";
                  }
                }

                function refresh() {
                  var transportMode = isSidecarMode();
                  var option = selectedPoint();
                  if (transportMode && option) {
                    hostInput.value = option.dataset.host || "";
                    portInput.value = selectedPortForMode(option);
                  } else if (!option) {
                    resetManualFields();
                  }
                  updateFields(transportMode, option);
                  updateSummary(transportMode, option);
                }

                modeSelect.addEventListener("change", refresh);
                pointSelect.addEventListener("change", refresh);
                refresh();
              })();
            </script>';
    }

    private function renderBondSelectionInstallHelper(
        string $helperId,
        string $modeSelectId,
        string $portInputId,
        string $masterUrl,
        string $artifactBaseUrl,
        string $serviceToken,
        int $defaultPort,
    ): string {
        return '
            <div id="' . $this->escape($helperId) . '" class="flash danger" style="display:none; margin-top:16px;">
              <strong>Bond or MPTCP needs a target transport endpoint.</strong><br>
              <span class="muted">Use an installed target transport endpoint, then come back and pick it here.</span>
            </div>
            <script>
              (function () {
                var helper = document.getElementById(' . json_encode($helperId) . ');
                var modeSelect = document.getElementById(' . json_encode($modeSelectId) . ');
                if (!helper || !modeSelect) {
                  return;
                }

                function updateHelper() {
                  var enabled = (modeSelect.value || "").toLowerCase() === "bond";
                  helper.style.display = enabled ? "block" : "none";
                }

                modeSelect.addEventListener("change", updateHelper);
                updateHelper();
              })();
            </script>';
    }


    private function forwardCreateFlashMessage(\Throwable $throwable): string
    {
        if ($throwable instanceof ApiException) {
            $message = $throwable->getMessage();
            if (in_array($message, [
                'route_unavailable',
                'no_available_nodes',
                'leigod_route_no_healthy_candidate',
                'forward_route_has_no_enabled_combination',
            ], true)) {
                return 'The selected route is currently unavailable.';
            }
            return match ($message) {
                'validation_failed', 'unknown_fields', 'invalid_forward_payload' => 'The forward settings are invalid.',
                'forward_quota_exceeded' => 'This service has reached its forward limit.',
                'forward_storage_quota_exceeded' => 'This service has reached its retained forward history limit. Contact support to review old records.',
                'feature_denied_manage_forwards', 'feature_denied_delete_forwards' => 'Your plan or account does not allow this forward operation.',
                'service_inactive' => 'This service is inactive.',
                'forward_version_conflict' => 'This forward changed in another request. Reload it and try again.',
                'forward_target_not_allowed' => 'This route only accepts target IP addresses on its allowlist.',
                'user_auth_failed' => 'Your session is no longer active. Sign in again.',
                default => 'The forward operation could not be completed.',
            };
        }

        error_log('tenant forward operation failed: ' . $throwable::class);
        return 'The forward operation could not be completed.';
    }

    /**
     * @param array<int, string> $parts
     * @return array{0:int,1:string,2:string}
     */
    private function runLocalCommand(array $parts): array
    {
        if (!function_exists('proc_open')) {
            throw new ApiException('proc_open_unavailable', 500);
        }

        $escaped = array_map(static fn(string $part): string => escapeshellarg($part), $parts);
        $command = implode(' ', $escaped);
        $descriptorSpec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptorSpec, $pipes, dirname(__DIR__, 3));
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tenantNav(string $active, ?int $serviceId = null): array
    {
        return $this->tenantNavigationItems($active);
    }

    /** @param array<string, mixed> $service */
    private function tenantServiceStatusBadge(array $service): string
    {
        $status = ServiceStatus::effective($service);
        [$label, $class] = match ($status) {
            'active' => ['Active', 'success'],
            'pending' => ['Pending activation', 'warning'],
            'suspended' => ['Suspended', 'danger'],
            'traffic_suspended' => ['Out of traffic', 'danger'],
            'expired' => ['Expired', 'danger'],
            default => [$status !== '' ? $status : 'Unknown', ''],
        };
        return '<span class="badge ' . $class . '">' . $this->escape($label) . '</span>';
    }

    /** @return array<string, mixed>|null */
    private function tenantUser(): ?array
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) ($user['role'] ?? '') !== 'tenant') {
            return null;
        }
        return $user;
    }

    /** @return array{0:int,1:string} */
    private function ownedTenantDevice(array $user, Request $request): array
    {
        $clientId = (int) $request->input('client_id', 0);
        $uuidValue = trim((string) $request->input('device_uuid', ''));
        $idValue = trim((string) $request->input('device_id', ''));
        if ($uuidValue !== '' && $idValue !== '' && !hash_equals($uuidValue, $idValue)) {
            throw new ApiException('device_id_invalid', 422);
        }
        $deviceUuid = $uuidValue !== '' ? $uuidValue : $idValue;
        if ($clientId <= 0 || $deviceUuid === '' || strlen($deviceUuid) > 191 || preg_match('/[\x00-\x20\x7f]/', $deviceUuid) === 1) {
            throw new ApiException('invalid_device', 400);
        }
        foreach ($this->accessPolicy->tenantDeviceRows((int) $user['id']) as $device) {
            if ((int) ($device['client_id'] ?? 0) === $clientId
                && hash_equals($deviceUuid, (string) ($device['device_uuid'] ?? ''))) {
                return [$clientId, $deviceUuid];
            }
        }
        throw new ApiException('device_not_owned', 403);
    }

    private function whmcsIdentityService(): WhmcsIdentityService
    {
        return new WhmcsIdentityService($this->config, $this->db);
    }

    private function formatTrafficBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return round($bytes / 1024 / 1024 / 1024, 2) . ' GB';
        }
        if ($bytes >= 1024 * 1024) {
            return round($bytes / 1024 / 1024, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }

    private function formatTrafficPercent(int $usedBytes, int $limitBytes): string
    {
        if ($limitBytes <= 0) {
            return '-';
        }
        return round(min(100, ($usedBytes / $limitBytes) * 100), 2) . '%';
    }

    private function hasSuspendedForwards(array $user): bool
    {
        $count = (int) ($this->db->fetchOne(
             'SELECT COUNT(*) AS total FROM forwards f
              INNER JOIN services s ON s.id = f.service_id
              WHERE s.user_id = :user_id
                AND f.enabled = 0
                AND f.user_deleted_at IS NULL
                AND f.balance_suspended_at IS NOT NULL',
            ['user_id' => (int) $user['id']]
        )['total'] ?? 0);
        return $count > 0;
    }

    private function buildBillingUsageBreakdown(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT n.id AS node_id, n.name, n.region, SUM(t.bytes_in + t.bytes_out) AS total_bytes
             FROM traffic_usage_hourly t
             INNER JOIN service_endpoints e ON e.id = t.endpoint_id
             INNER JOIN forwards f ON f.id = e.forward_id
             INNER JOIN services s ON s.id = f.service_id
             INNER JOIN nodes n ON n.id = t.node_id
             WHERE s.user_id = :user_id
               AND t.period_start >= :cycle_start
             GROUP BY n.id, n.name, n.region
             ORDER BY total_bytes DESC',
            [
                'user_id' => $userId,
                'cycle_start' => (new \DateTimeImmutable('first day of this month', new \DateTimeZone('UTC')))->format('Y-m-d 00:00:00'),
            ]
        );

        $result = [];
        foreach ($rows as $row) {
            $nodeId = (int) ($row['node_id'] ?? 0);
            $bytes = (int) ($row['total_bytes'] ?? 0);
            $price = $this->provisioning->resolveTrafficPrice($nodeId, 'slave');
            $pricePerGb = $price !== null ? (float) $price['price_per_gb'] : 0;
            $currency = $price !== null ? $price['currency'] : 'CNY';
            $gb = $bytes / (1024 * 1024 * 1024);
            $result[] = [
                'name' => $row['name'] ?? '',
                'region' => $row['region'] ?? '',
                'bytes' => $bytes,
                'price_per_gb' => $pricePerGb > 0 ? number_format($pricePerGb, 4) : 'Free',
                'currency' => $currency,
                'cost' => $gb * $pricePerGb,
            ];
        }
        return $result;
    }
}
