<?php
declare(strict_types=1);

namespace Leikwan\Master\Controllers;

use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;
use Leikwan\Master\Support\ApiException;
use Leikwan\Master\Support\BondLegReputationService;
use Leikwan\Master\Support\ClientOnlineSessionService;
use Leikwan\Master\Support\ProvisioningService;
use Leikwan\Master\Support\SessionAuth;
use Leikwan\Master\Support\WhmcsIdentityService;

final class TenantController extends BaseController
{
    public function __construct(
        \Leikwan\Master\Config $config,
        \Leikwan\Master\Database\Database $db,
        private ProvisioningService $provisioning,
    ) {
        parent::__construct($config, $db);
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
        $sidecarCount = count($this->provisioning->listSidecarPointsForUser((int) $user['id']));
        $purchasePlans = $this->provisioning->listPurchasablePlans();
        $linked = !empty($user['whmcs_client_id']);
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
                <h3>最近套餐</h3>
                <div><strong>' . $this->escape((string) ($latestService['name'] ?? 'Service')) . '</strong></div>
                <div class="muted">状态: ' . $this->escape((string) ($latestService['status'] ?? 'unknown')) . '</div>
                <div class="muted">到期: ' . $this->escape((string) ($latestService['expire_at'] ?? '-')) . '</div>
                <div class="actions">
                  <a class="button" href="/tenant-services-internal/' . (int) ($latestService['id'] ?? 0) . '">打开详情</a>
                  <a class="button ghost" href="/tenant/forwards">查看全部转发</a>
                </div>
              </section>'
            : '<section class="card">
                <h3>最近套餐</h3>
                <p class="muted">还没有已开通套餐。先去 <strong>套餐</strong> 页面购买，再进入 <strong>转发配置</strong> 建立规则。</p>
                <div class="actions">
                  <a class="button" href="/tenant/plans">查看套餐</a>
                  <a class="button ghost" href="/tenant/account">先处理账户</a>
                </div>
              </section>';

        $body = '
            <div class="hero">
              <div>
                <h2>介绍</h2>
                <p class="muted">这里是新的 tenant panel 入口。先检查账户与套餐，再进入转发、接入点和边车配置。</p>
              </div>
            </div>
            <div class="grid three">
              <section class="card"><div class="muted">账户状态</div><div class="stats">' . ($linked ? '已绑定' : '未绑定') . '</div></section>
              <section class="card"><div class="muted">已开通套餐</div><div class="stats">' . (int) ($serviceSummary['total'] ?? 0) . '</div></section>
              <section class="card"><div class="muted">可用套餐</div><div class="stats">' . count($purchasePlans) . '</div></section>
              <section class="card"><div class="muted">活跃服务</div><div class="stats">' . (int) ($serviceSummary['active_total'] ?? 0) . '</div></section>
              <section class="card"><div class="muted">接入点</div><div class="stats">' . $clientCount . '</div></section>
              <section class="card"><div class="muted">边车</div><div class="stats">' . $sidecarCount . '</div></section>
            </div>
            <div class="grid two" style="margin-top:20px;">
              <section class="card">
                <h3>建议顺序</h3>
                <ol class="list">
                  <li>到账户页面确认余额、绑定 Buy 账户。</li>
                  <li>到套餐页面购买或查看当前服务。</li>
                  <li>到转发配置为服务新增 Forward。</li>
                  <li>需要自带线路时，再配置 Client 与 Sidecar。</li>
                </ol>
                <div class="actions">
                  <a class="button" href="/tenant/account">打开账户</a>
                  <a class="button ghost" href="/tenant/plans">打开套餐</a>
                  <a class="button ghost" href="/tenant/forwards">打开转发配置</a>
                </div>
              </section>
              <section class="card">
                <h3>面板结构</h3>
                <div class="stack">
                  <div><strong>介绍</strong><div class="muted">总览入口与操作顺序。</div></div>
                  <div><strong>账户</strong><div class="muted">绑定、余额、转账记录。</div></div>
                  <div><strong>套餐</strong><div class="muted">购买套餐、查看当前服务。</div></div>
                  <div><strong>转发配置</strong><div class="muted">为服务添加或管理 Forward。</div></div>
                  <div><strong>接入点 (Client)</strong><div class="muted">自有线路、安装命令、诊断。</div></div>
                  <div><strong>Transport Endpoints</strong><div class="muted">Advanced transport endpoint and target access details.</div></div>
                  <div><strong>知识库</strong><div class="muted">使用说明与排障入口。</div></div>
                </div>
              </section>
            </div>
            <div class="grid two" style="margin-top:20px;">
              ' . $latestServiceCard . '
              <section class="card">
                <h3>下一步</h3>
                <p class="muted">如果你刚完成登录，最常见的路径是：账户绑定 -> 购买套餐 -> 创建 Forward -> 按需安装 Client 或 Sidecar。</p>
                <div class="actions">
                  <a class="button" href="/knowledgebase">打开知识库</a>
                  <a class="button ghost" href="/tenant/clients">管理 Client</a>
                  <a class="button ghost" href="/tenant" . "/transport-endpoints">管理 Sidecar</a>
                </div>
              </section>
            </div>';

        return $this->page('介绍', $body, $user, $this->tenantNav('intro'));
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

        $totalUsedBytes = 0;
        $totalLimitBytes = 0;
        $totalEstimatedBilling = 0.0;
        $rows = '';
        foreach ($services as $service) {
            $statusClass = ((string) $service['status'] === 'active') ? 'success' : 'danger';
            $billing = $this->provisioning->serviceBillingOverview((int) $service['id']);
            $totalEstimatedBilling += (float) ($billing['estimated_total_amount'] ?? 0);
            $trafficUsedBytes = max(0, (int) ($billing['current_cycle_used_bytes'] ?? 0));
            $trafficLimitGb = ((string) ($billing['mode'] ?? 'monthly_bundle') === 'monthly_bundle')
                ? max(0, (int) ($billing['included_traffic_gb'] ?? 0))
                : 0;
            $trafficLimitBytes = $trafficLimitGb > 0 ? $trafficLimitGb * 1024 * 1024 * 1024 : 0;
            $trafficRemainingBytes = ((string) ($billing['mode'] ?? 'monthly_bundle') === 'monthly_bundle')
                ? max(0, $trafficLimitBytes - $trafficUsedBytes)
                : 0;
            $usageLabel = ((string) ($billing['mode'] ?? 'monthly_bundle') === 'monthly_bundle')
                ? $this->formatTrafficPercent($trafficUsedBytes, $trafficLimitBytes)
                : 'metered';
            $totalUsedBytes += $trafficUsedBytes;
            $totalLimitBytes += $trafficLimitBytes;
            $rows .= '<tr>
                <td>' . (int) $service['id'] . '</td>
                <td><a href="/tenant-services-internal/' . (int) $service['id'] . '"><strong>' . $this->escape((string) $service['name']) . '</strong></a></td>
                <td><span class="badge ' . $statusClass . '">' . $this->escape((string) $service['status']) . '</span></td>
                <td>' . $this->escape((string) $service['whmcs_service_id']) . '</td>
                <td>' . $this->escape((string) ($service['plan_name'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($billing['mode_label'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($billing['estimated_total_formatted'] ?? '')) . '</td>
                <td>' . $this->escape((string) $service['expire_at']) . '</td>
                <td>' . (int) $service['bandwidth_limit_mbps'] . ' Mbps</td>
                <td>' . (((string) ($billing['mode'] ?? 'monthly_bundle') === 'monthly_bundle') ? ((int) ($billing['included_traffic_gb'] ?? 0) . ' GB / month') : 'metered') . '</td>
                <td>' . $this->formatTrafficBytes($trafficUsedBytes) . '</td>
                <td>' . (((string) ($billing['mode'] ?? 'monthly_bundle') === 'monthly_bundle') ? $this->formatTrafficBytes($trafficRemainingBytes) : '-') . '</td>
                <td>' . $this->escape($usageLabel) . '</td>
                <td>
                    <div class="actions">
                        <a class="button" href="/tenant-services-internal/' . (int) $service['id'] . '/create-forward">Add Forward</a>
                        <a class="button ghost" href="/tenant-services-internal/' . (int) $service['id'] . '">Manage</a>
                    </div>
                </td>
            </tr>';
        }

        $emptyState = $services === []
            ? '<section class="card">
                <h3>No Service Yet</h3>
                <p class="muted">There is nothing to configure yet because no service has been activated for this tenant.</p>
                <p class="muted">Ask the admin to activate a service first. After that, this page will show an <strong>Add Forward</strong> entry for each service.</p>
              </section>'
            : '';

        $totalRemainingBytes = max(0, $totalLimitBytes - $totalUsedBytes);
        $body = '
            <div class="hero">
              <div>
                <h2>转发配置</h2>
                <p class="muted">这里集中管理服务、Forward 和当前流量占用。每个服务都可以继续进入详情页做更细的调整。</p>
              </div>
            </div>
            <div class="grid three">
              <section class="card"><div class="muted">Services</div><div class="stats">' . count($services) . '</div></section>
              <section class="card"><div class="muted">Used Traffic</div><div class="stats">' . $this->escape($this->formatTrafficBytes($totalUsedBytes)) . '</div></section>
              <section class="card"><div class="muted">Remaining Traffic</div><div class="stats">' . $this->escape($this->formatTrafficBytes($totalRemainingBytes)) . '</div></section>
              <section class="card"><div class="muted">Estimated This Cycle</div><div class="stats">CNY ' . $this->escape(number_format($totalEstimatedBilling, 2)) . '</div></section>
            </div>
            ' . $emptyState . '
            <section class="card" style="margin-top:20px;">
              <h3>服务与 Forward 入口</h3>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr><th>ID</th><th>Name</th><th>Status</th><th>WHMCS</th><th>Plan</th><th>Billing Mode</th><th>Current Bill</th><th>Expire At</th><th>Bandwidth</th><th>Traffic Limit</th><th>Used Traffic</th><th>Remaining</th><th>Usage</th><th>Actions</th></tr>
                  </thead>
                  <tbody>' . $rows . '</tbody>
                </table>
              </div>
            </section>';

        return $this->page('转发配置', $body, $user, $this->tenantNav('forwards'));
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
        $balanceColor = 'var(--success)';
        $balanceLabel = 'Active';
        if ($localBalance <= 0) {
            $balanceColor = 'var(--danger)';
            $balanceLabel = 'Suspended';
        } elseif ($localBalance <= (float) ($user['wallet_low_balance_threshold'] ?? 5.00)) {
            $balanceColor = '#c96b32';
            $balanceLabel = 'Low';
        }
        $remoteBalance = $remote !== null ? (float) ($remote['credit'] ?? 0) : 0.0;
        $remoteCurrency = $remote !== null
            ? strtoupper(trim((string) ($remote['currency_code'] ?? $localCurrency)))
            : $localCurrency;
        $stats = $remote !== null && is_array($remote['stats'] ?? null) ? $remote['stats'] : [];
        $products = $remote !== null && is_array($remote['products'] ?? null) ? $remote['products'] : [];
        $purchasePlans = $this->provisioning->listPurchasablePlans();
        if ($rows === '') {
            $rows = '<tr><td colspan="7" class="muted">No forward rules yet. Create your first forward from the button above.</td></tr>';
        }

        $csrf = $this->escape(SessionAuth::csrfToken());
        $createForwardNotice = $availableForwardCombinations === []
            ? '<div class="flash danger">No route package is available for this service yet. Ask admin to publish a forward combination first.</div>'
            : '<div class="flash success">Choose one route package, then fill your backend target.</div>';

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

        $body = '
            <div class="hero">
              <div>
                <h2>账户</h2>
                <p class="muted">处理绑定、余额和转账记录。套餐购买已经拆到单独的 <strong>套餐</strong> 页面。</p>
              </div>
              <div class="actions" style="margin-top:0;">
                <a class="button ghost" href="/tenant/plans">前往套餐</a>
              </div>
            </div>
            ' . $syncNotice . '
            <div class="grid three">
              <section class="card"><div class="muted">Panel Balance</div><div class="stats" style="color:' . $balanceColor . ';">' . $this->escape($localCurrency) . ' ' . $this->escape(number_format($localBalance, 2)) . '</div><span class="badge" style="background:' . $balanceColor . ';color:#fff;margin-top:10px;display:inline-block;">' . $this->escape($balanceLabel) . '</span>'
            . ($localBalance > 0 && $this->hasSuspendedForwards($user) ? '<form method="post" action="/tenant/account/reactivate" style="margin-top:10px;"><input type="hidden" name="csrf_token" value="' . $csrf . '"><button class="button" type="submit" style="width:100%;">Reactivate All Forwards</button></form>' : '') . '</section>
              <section class="card"><div class="muted">Buy Balance</div><div class="stats">' . ($remote !== null ? $this->escape($remoteCurrency) . ' ' . $this->escape(number_format($remoteBalance, 2)) : '-') . '</div></section>
              <section class="card"><div class="muted">Binding Status</div><div class="stats">' . ($linked ? 'Bound' : 'Not Bound') . '</div></section>
              <section class="card"><div class="muted">Active Products</div><div class="stats">' . (int) ($stats['productsnumactive'] ?? 0) . '</div></section>
              <section class="card"><div class="muted">Unpaid Invoices</div><div class="stats">' . (int) ($stats['numunpaidinvoices'] ?? 0) . '</div></section>
              <section class="card"><div class="muted">Open Tickets</div><div class="stats">' . (int) ($stats['numtickets'] ?? 0) . '</div></section>
            </div>
            <div class="grid two" style="margin-top:20px;">
              ' . $bindSection . '
              ' . $transferSection . '
            </div>
            ' . $usageTable . '
            <section class="card" style="margin-top:20px;">
              <h3>Buy Station Products</h3>
              <div class="table-wrap">
                <table>
                  <thead><tr><th>ID</th><th>Name</th><th>Status</th><th>Billing Cycle</th><th>Next Due</th><th>Amount</th></tr></thead>
                  <tbody>' . $productRows . '</tbody>
                </table>
              </div>
            </section>
            <section class="card" style="margin-top:20px;">
              <h3>Balance Transfer History</h3>
              <div class="table-wrap">
                <table>
                  <thead><tr><th>Direction</th><th>Amount</th><th>Panel Balance</th><th>Buy Balance</th><th>Created At</th></tr></thead>
                  <tbody>' . $transactionRows . '</tbody>
                </table>
              </div>
            </section>';

        return $this->page('账户', $body, $user, $this->tenantNav('account'));
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
            $statusClass = ((string) ($service['status'] ?? '') === 'active') ? 'success' : 'danger';
            $currentBandwidth = (int) ($billing['current_bandwidth_mbps'] ?? $service['bandwidth_limit_mbps'] ?? 0);
            $totalBandwidth += max(0, $currentBandwidth);
            $totalEstimatedBilling += (float) ($billing['estimated_total_amount'] ?? 0);
            $trafficPolicy = ((string) ($billing['mode'] ?? 'monthly_bundle') === 'monthly_bundle')
                ? ((int) ($billing['included_traffic_gb'] ?? 0) . ' GB / month')
                : 'metered';

            $serviceRows .= '<tr>
                <td><a href="/tenant-services-internal/' . (int) $service['id'] . '"><strong>' . $this->escape((string) ($service['name'] ?? 'Service')) . '</strong></a></td>
                <td><span class="badge ' . $statusClass . '">' . $this->escape((string) ($service['status'] ?? 'unknown')) . '</span></td>
                <td>' . $this->escape((string) ($service['plan_name'] ?? '')) . '</td>
                <td>' . $currentBandwidth . ' Mbps</td>
                <td>' . $this->escape($trafficPolicy) . '</td>
                <td>' . $this->escape((string) ($billing['estimated_total_formatted'] ?? '')) . '</td>
                <td>' . $this->escape((string) ($service['expire_at'] ?? '')) . '</td>
                <td>
                  <div class="actions">
                    <a class="button ghost" href="/tenant-services-internal/' . (int) $service['id'] . '">Details</a>
                    <a class="button" href="/tenant-services-internal/' . (int) $service['id'] . '/forward-wizard">Create Forward</a>
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
                ? '<form method="post" action="/tenant/plans/purchase" style="margin-top:16px;">
                    <input type="hidden" name="csrf_token" value="' . $csrf . '">
                    <input type="hidden" name="plan_id" value="' . $planId . '">
                    <div class="form-grid">
                      <div class="field"><label>Service Name</label><input type="text" name="service_name" placeholder="Optional. Leave empty to auto-generate a name."></div>
                      <div class="field"><label>Bandwidth Mbps</label><input type="number" min="1" name="bandwidth_limit_mbps" placeholder="Optional. Leave empty to use the package base bandwidth."></div>
                    </div>
                    <div class="actions"><button class="button" type="submit">Purchase Package</button><a class="button secondary" href="/tenant/plans">Cancel</a></div>
                  </form>'
                : '<div class="actions" style="margin-top:18px;"><a class="button" href="/tenant/plans?buy=' . $planId . '#plan-' . $planId . '">Order Now</a></div>';

            $planCards .= '<section class="card" id="plan-' . $planId . '" style="display:flex;flex-direction:column;justify-content:space-between;min-height:340px;">
                <div class="grid two storefront-card-head" style="align-items:flex-start;gap:22px;">
                  <div>
                    <h3 style="font-size:2rem;margin-bottom:14px;">' . $this->escape((string) ($plan['name'] ?? 'Plan')) . '</h3>
                    ' . $this->tenantPlanFeatureListHtml($plan) . '
                  </div>
                  <div class="storefront-price-block" style="text-align:right;min-width:220px;">
                    <div class="muted">Starting from</div>
                    <div class="stats">' . $this->escape($planCurrency) . ' ' . $this->escape($planPrice) . '</div>
                    <div>Monthly</div>
                    <div class="muted" style="margin-top:8px;">Traffic Reset ' . $this->escape($planCurrency) . ' ' . $this->escape($resetPrice) . '</div>
                    <div class="muted">Included Traffic: ' . (int) ($plan['default_traffic_limit_gb'] ?? 0) . ' GB</div>
                    <div class="muted">Included Clients: ' . $includedClients . '</div>
                    <div class="muted">Client Profiles: ' . $maxClientProfiles . '</div>
                    <div class="muted">Devices per Client: ' . $deviceLimit . '</div>
                  </div>
                </div>
                ' . $inlineForm . '
              </section>';
        }
        if ($planCards === '') {
            $planCards = '<section class="card"><h3>No package available</h3><p class="muted">No visible package is available right now. Contact the administrator if you expected to self-purchase.</p></section>';
        }

        $body = '
            <div class="hero">
              <div>
                <h2>Packages</h2>
                <p class="muted">Use this storefront to compare packages first, then open an inline order form to set service name and bandwidth before purchase.</p>
              </div>
              <div class="actions" style="margin-top:0;">
                <a class="button ghost" href="/tenant/forwards">Forward Config</a>
              </div>
            </div>
            <div class="grid three">
              <section class="card"><div class="muted">Current Services</div><div class="stats">' . count($services) . '</div></section>
              <section class="card"><div class="muted">Visible Packages</div><div class="stats">' . count($purchasePlans) . '</div></section>
              <section class="card"><div class="muted">Total Bandwidth</div><div class="stats">' . $totalBandwidth . ' Mbps</div></section>
              <section class="card"><div class="muted">Current Cycle Estimate</div><div class="stats">CNY ' . $this->escape(number_format($totalEstimatedBilling, 2)) . '</div></section>
            </div>
            <section class="card" style="margin-top:20px;">
              <h3>Purchase Package</h3>
              <p class="muted">Choose the package that fits your usage, then open its inline order form to set service name and bandwidth before purchase.</p>
              <div class="grid two">' . $planCards . '</div>
            </section>
            <section class="card" style="margin-top:20px;">
              <h3>Current Services</h3>
              <div class="table-wrap">
                <table>
                  <thead><tr><th>Name</th><th>Status</th><th>Plan</th><th>Current Bandwidth</th><th>Traffic Policy</th><th>Current Bill</th><th>Expire At</th><th>Actions</th></tr></thead>
                  <tbody>' . $serviceRows . '</tbody>
                </table>
              </div>
            </section>';

        return $this->page('濂楅', $body, $user, $this->tenantNav('plans'));
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
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $balance = (float) ($user['wallet_balance'] ?? 0);
            if ($balance <= 0) {
                throw new \Leikwan\Master\Exceptions\ApiException('insufficient_balance', 400);
            }

            $this->db->execute(
                'UPDATE forwards SET enabled = 1
                 WHERE service_id IN (SELECT id FROM services WHERE user_id = :user_id AND status = :status)',
                ['user_id' => (int) $user['id'], 'status' => 'active']
            );
            $this->db->execute(
                'UPDATE users SET wallet_warning_sent = 0 WHERE id = :id',
                ['id' => (int) $user['id']]
            );
            SessionAuth::flash('success', 'All forwards have been reactivated.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Reactivation failed: ' . $throwable->getMessage());
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
            return Response::redirect('/tenant-services-internal/' . (int) ($service['id'] ?? 0));
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

        return Response::redirect('/tenant-services-internal/' . $serviceId);
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

        return Response::redirect('/tenant-services-internal/' . $serviceId);
    }

    public function clientsPage(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        $services = $this->db->fetchAll('SELECT id FROM services WHERE user_id = :user_id', ['user_id' => $user['id']]);
        $featureBase = $services !== [] ? $this->provisioning->serviceSummary((int) $services[0]['id']) : null;
        $features = $featureBase['effective_features'] ?? $this->provisioning->defaultFeatureFlags();
        $canManageClients = !empty($features['allow_manage_clients']);
        $clients = $this->provisioning->listClientsForUser((int) $user['id']);
        $currentClientProfiles = count($clients);
        $maxClientProfiles = $this->provisioning->maxClientProfilesForTenantOwnedClients((int) $user['id']);
        $clientProfileSummary = '<div class="flash info">Current Client Profiles: ' . $currentClientProfiles . ' / ' . $maxClientProfiles . '. This package allows up to ' . $maxClientProfiles . ' client profiles.</div>';
        $allowedGroups = $this->provisioning->availableClientGroupsForUser((int) $user['id']);
        $chains = $this->provisioning->availableForwardChainsForService((int) ($services[0]['id'] ?? 0));
        $editId = (int) $request->input('edit', 0);
        $editing = null;
        foreach ($clients as $client) {
            if ((int) $client['id'] === $editId) {
                $editing = $client;
                break;
            }
        }
        $clientProfileCapReached = $currentClientProfiles >= $maxClientProfiles && $editing === null;
        $overviewClient = $editing ?? ($clients[0] ?? null);

        $latestClientAgentVersion = $this->artifactVersion('client_agent');
        $onlineSessionService = new ClientOnlineSessionService($this->db);
        $clientCards = '';
        foreach ($clients as $client) {
            $diagStatus = strtolower(trim((string) ($client['last_diag_status'] ?? '')));
            $diagBadgeClass = match ($diagStatus) {
                'ok' => 'success',
                'error' => 'danger',
                'requested' => 'info',
                default => 'info',
            };
            $diagLabel = $diagStatus !== '' ? $diagStatus : 'not run';
            $diagDialogId = 'client-diag-' . (int) $client['id'];
            $clientWithReputation = $client + [
                'bond_leg_reputation_rows' => [],
                'bond_leg_reputation_events' => [],
            ];
            $diagDialog = $this->renderTenantClientDiagnosticsDialog($clientWithReputation, $diagDialogId);

            $agentVersion = trim((string) ($client['agent_version'] ?? ''));
            $lastSyncAt = trim((string) ($client['last_sync_at'] ?? ''));
            $updateRequestedAt = trim((string) ($client['agent_update_requested_at'] ?? ''));
            $updateBadgeClass = 'info';
            $updateLabel = 'not installed';
            if ($agentVersion !== '') {
                if ($latestClientAgentVersion !== '' && $agentVersion === $latestClientAgentVersion) {
                    $updateBadgeClass = 'success';
                    $updateLabel = $updateRequestedAt !== '' ? 'updated' : 'up to date';
                } elseif ($updateRequestedAt !== '') {
                    $requestedTs = strtotime($updateRequestedAt) ?: 0;
                    $syncTs = strtotime($lastSyncAt) ?: 0;
                    if ($syncTs > 0 && $requestedTs > 0 && $syncTs >= ($requestedTs + 120)) {
                        $updateBadgeClass = 'danger';
                        $updateLabel = 'update not applied';
                    } else {
                        $updateBadgeClass = 'info';
                        $updateLabel = 'update requested';
                    }
                } else {
                    $updateBadgeClass = 'danger';
                    $updateLabel = $latestClientAgentVersion !== '' ? 'outdated' : 'installed';
                }
            }

            $activeSessions = $onlineSessionService->activeSessionsForClient((int) ($client['id'] ?? 0));
            $onlineCount = count($activeSessions);
            $onlineLimit = max(1, (int) ($client['online_device_limit'] ?? 1));
            $onlineSummary = $onlineCount . ' / ' . $onlineLimit;
            $onlineRows = '';
            foreach ($activeSessions as $session) {
                $label = trim((string) ($session['device_name'] ?? ''));
                if ($label === '') {
                    $label = trim((string) ($session['platform'] ?? 'Device'));
                }
                $onlineRows .= '<li>'
                    . $this->escape($label)
                    . '<span class="muted" style="margin-left:8px;">'
                    . $this->escape((string) ($session['last_seen_at'] ?? ''))
                    . '</span></li>';
            }
            if ($onlineRows === '') {
                $onlineRows = '<li class="muted">No active device right now.</li>';
            }

            $clientCards .= '<section class="card">
                <div class="hero" style="margin-bottom:14px;">
                  <div>
                    <h3 style="margin-bottom:6px;">' . $this->escape((string) $client['name']) . '</h3>
                    <div class="muted">' . $this->escape((string) $client['display_host']) . '</div>
                  </div>
                  <div class="actions" style="margin-top:0;">
                    <span class="badge ' . $updateBadgeClass . '">App: ' . $this->escape($updateLabel) . '</span>
                    <span class="badge info">Online devices: ' . $this->escape($onlineSummary) . '</span>
                  </div>
                </div>
                <div class="grid two">
                  <div>
                    <div><span class="muted">Group:</span> ' . $this->escape((string) ($client['group_name'] ?? '')) . '</div>
                    <div><span class="muted">Last Sync:</span> ' . $this->escape($lastSyncAt !== '' ? $lastSyncAt : 'never') . '</div>
                    <div><span class="muted">Installed App:</span> ' . $this->escape($agentVersion !== '' ? $agentVersion : 'not installed') . '</div>
                  </div>
                  <div>
                    <div><span class="muted">Online device limit:</span> ' . $onlineLimit . '</div>
                    <div><span class="muted">Diagnostics:</span> ' . $this->escape($diagLabel) . '</div>
                    <div><span class="muted">Last Update Request:</span> ' . $this->escape($updateRequestedAt !== '' ? $updateRequestedAt : 'none') . '</div>
                  </div>
                </div>
                <details style="margin-top:14px;">
                  <summary>Linux Install</summary>
                  ' . $this->renderTenantClientLinuxInstall($client) . '
                </details>
                <details style="margin-top:14px;">
                  <summary>Online devices</summary>
                  <ul class="list" style="margin-top:10px;">' . $onlineRows . '</ul>
                </details>
                <details style="margin-top:14px;">
                  <summary>Advanced defaults</summary>
                  <div class="muted" style="margin-top:10px;">These are only defaults for future forwards. They are not required for app install.</div>
                  <div style="margin-top:12px;"><span class="muted">Default Route:</span> ' . $this->escape($this->endpointModeLabel((string) ($client['default_endpoint_mode'] ?? 'slave_group'))) . '</div>
                  <div><span class="muted">Default Slave Group:</span> ' . $this->escape((string) ($client['default_node_group_key'] ?? '')) . '</div>
                  <div><span class="muted">Default Chain:</span> ' . $this->escape((string) ($client['default_forward_chain_name'] ?? '')) . '</div>
                </details>
                <div class="actions">
                  ' . ($canManageClients ? '<a class="button ghost" href="/tenant/clients?edit=' . (int) $client['id'] . '">Edit</a>' : '') . '
                  <form class="inline" method="post" action="/tenant/clients/' . (int) $client['id'] . '/diagnose">
                    <input type="hidden" name="csrf_token" value="' . $this->escape(SessionAuth::csrfToken()) . '">
                    <button type="submit" class="button secondary">Run Diagnostics</button>
                  </form>
                  <button type="button" class="button ghost" data-diag-dialog="' . $this->escape($diagDialogId) . '">View Diagnostics</button>
                  <form class="inline" method="post" action="/tenant/clients/' . (int) $client['id'] . '/request-update">
                    <input type="hidden" name="csrf_token" value="' . $this->escape(SessionAuth::csrfToken()) . '">
                    <button type="submit" class="button secondary">Request Update</button>
                  </form>
                  <form class="inline" method="post" action="/tenant/clients/' . (int) $client['id'] . '/delete">
                    <input type="hidden" name="csrf_token" value="' . $this->escape(SessionAuth::csrfToken()) . '">
                    <button type="submit" class="button danger"' . (empty($features['allow_delete_clients']) ? ' disabled' : '') . '>Delete</button>
                  </form>
                </div>
                <div class="muted" style="margin-top:14px;">Open <strong>View Diagnostics</strong> to check whether runtime links are healthy and whether the tunnel was established. Slave addresses are hidden in that window. Reference only. Runtime already adapts automatically when a link degrades. You usually do not need to take action unless the line itself is failing.</div>
                ' . $diagDialog . '
              </section>';
        }

        $groupOptions = '<option value="">Select an allowed client group</option>';
        foreach ($allowedGroups as $group) {
            $selected = $editing !== null && (int) ($editing['group_id'] ?? 0) === (int) $group['id'] ? ' selected' : '';
            $groupOptions .= '<option value="' . (int) $group['id'] . '"' . $selected . '>' . $this->escape((string) $group['name']) . '</option>';
        }
        $chainOptions = '<option value="">No default chain</option>';
        foreach ($chains as $chain) {
            $selected = $editing !== null && (int) ($editing['default_forward_chain_id'] ?? 0) === (int) $chain['id'] ? ' selected' : '';
            $chainOptions .= '<option value="' . (int) $chain['id'] . '"' . $selected . '>' . $this->escape((string) $chain['name']) . '</option>';
        }
        $selectedCarrierValues = implode(',', array_values(array_filter(array_map(
            static fn(mixed $value): string => strtolower(trim((string) $value)),
            $this->decodeJsonList($editing['selected_carriers_json'] ?? null)
        ), static fn(string $value): bool => $value !== '')));
        $runtimePlatformScope = strtolower(trim((string) ($editing['runtime_platform_scope'] ?? 'all')));

        $notice = !$canManageClients
            ? '<div class="flash danger">Your plan/account is not allowed to manage self-provided clients.</div>'
            : '<div class="flash success">You can add your own client lines here. Admin-provided lines will still appear inside service pages when authorized. Installing a client app does not require route defaults.</div>';

        $clientFormSection = '';
        if ($canManageClients && $clientProfileCapReached) {
            $clientFormSection = '<section class="card"><h3>Create Client</h3><div class="flash danger">This package allows up to ' . $maxClientProfiles . ' client profiles.</div></section>';
        } elseif ($canManageClients) {
            $clientFormSection = '<section class="card">
                <h3>' . ($editing !== null ? 'Edit Client' : 'Create Client') . '</h3>
                <p class="muted">Tenants only need a clear name, a display host, and an installable client. Keep advanced routing defaults collapsed unless you really need them.</p>
                <form method="post" action="/tenant/clients">
                  <input type="hidden" name="csrf_token" value="' . $this->escape(SessionAuth::csrfToken()) . '">
                  <input type="hidden" name="id" value="' . (int) ($editing['id'] ?? 0) . '">
                  <div class="form-grid">
                    <div class="field"><label>Name</label><input type="text" name="name" value="' . $this->escape((string) ($editing['name'] ?? '')) . '" required></div>
                    <div class="field"><label>Display Host</label><input type="text" name="display_host" value="' . $this->escape((string) ($editing['display_host'] ?? '')) . '" required><p class="muted">Tenant-facing display label only. It does not drive slave SNI / CDN routing.</p></div>
                    <div class="field"><label>Client Group</label><select name="group_id">' . $groupOptions . '</select></div>
                    <div class="field"><label>Status</label><select name="status"><option value="active"' . (((string) ($editing['status'] ?? 'active')) === 'active' ? ' selected' : '') . '>active</option><option value="disabled"' . (((string) ($editing['status'] ?? '')) === 'disabled' ? ' selected' : '') . '>disabled</option></select></div>
                    <div class="field"><label>Online device limit</label><input type="number" min="1" name="online_device_limit" value="' . $this->escape((string) ($editing['online_device_limit'] ?? '1')) . '" required><p class="muted">Limit the number of simultaneously online client devices for this client.</p></div>
                    <div class="field"><label>Allow Paid Lines</label><input type="checkbox" name="allow_paid_lines" value="1"' . ((int) ($editing['allow_paid_lines'] ?? 0) === 1 ? ' checked' : '') . '><p class="muted">Allow runtime selection of paid lines for this profile.</p></div>
                    <div class="field"><label>Selected Carriers</label><input type="text" name="selected_carriers[]" value="' . $this->escape($selectedCarrierValues) . '" placeholder="ct,cu"><p class="muted">Optional comma-separated carrier codes for Linux profile runtime selection.</p></div>
                    <div class="field"><label>Runtime Platform Scope</label><select name="runtime_platform_scope"><option value="all"' . ($runtimePlatformScope === 'linux' ? '' : ' selected') . '>all</option><option value="linux"' . ($runtimePlatformScope === 'linux' ? ' selected' : '') . '>linux</option></select></div>
                  </div>
                  <details style="margin-top:14px;">
                    <summary>Advanced client settings</summary>
                    <div class="form-grid" style="margin-top:14px;">
                      <div class="field"><label>Bind IP</label><input type="text" name="bind_ip" value="' . $this->escape((string) ($editing['bind_ip'] ?? '')) . '"></div>
                      <div class="field"><label>NIC Name</label><input type="text" name="nic_name" value="' . $this->escape((string) ($editing['nic_name'] ?? '')) . '"></div>
                      <div class="field"><label>Port Range Start</label><input type="number" name="port_range_start" value="' . $this->escape((string) ($editing['port_range_start'] ?? '')) . '"><p class="muted">Optional. When this range is set, the panel auto-assigns forward ports from it.</p></div>
                      <div class="field"><label>Port Range End</label><input type="number" name="port_range_end" value="' . $this->escape((string) ($editing['port_range_end'] ?? '')) . '"><p class="muted">Optional. Keep the range wide enough for future forwards.</p></div>
                      <div class="field"><label>Traffic Multiplier</label><input type="number" step="0.01" name="traffic_multiplier" value="' . $this->escape((string) ($editing['traffic_multiplier'] ?? '1.00')) . '"></div>
                      <div class="field"><label>Default Route Mode</label>
                        <select name="default_endpoint_mode">' . $this->renderEndpointModeOptions((string) ($editing['default_endpoint_mode'] ?? 'slave_group')) . '</select>
                      </div>
                      <div class="field"><label>Default Slave Group Key</label><input type="text" name="default_node_group_key" value="' . $this->escape((string) ($editing['default_node_group_key'] ?? '')) . '"><p class="muted">Optional. Used as the default route suggestion when you create a forward later.</p></div>
                      <div class="field"><label>Default Forward Chain</label><select name="default_forward_chain_id">' . $chainOptions . '</select><p class="muted">Optional advanced default. Leave empty for the simplest setup.</p></div>
                      <div class="field"><label>Agent Config JSON</label><textarea name="agent_config_json" placeholder="{&quot;poll_interval_seconds&quot;:60}">' . $this->escape((string) ($editing['agent_config_json'] ?? '')) . '</textarea><p class="muted">Merged into the installed client-agent config on the next sync. Useful for poll interval, log level or moving the panel domain.</p></div>
                    </div>
                  </details>
                  <div class="actions">
                    <button class="button" type="submit">Save Client</button>
                    ' . ($editing !== null ? '<a class="button secondary" href="/tenant/clients">Cancel</a>' : '') . '
                  </div>
                </form>
              </section>';
        }

        $body = '
            <div class="hero">
              <div>
                <h2>Client Profiles</h2>
                <p class="muted">Keep this simple. Install Windows or Android from the top of this page; use Linux Install only for Linux hosts.</p>
              </div>
            </div>
            <section class="card" style="margin-bottom:20px;">
              <h3>Quick start</h3>
              <p class="muted">Install the client app first. Windows and Android use app download; Linux hosts use the per-client Linux Install command below.</p>
              <div class="actions">
                ' . $this->renderCommandLink('Download Windows App', $this->windowsClientAppUrl()) . '
                ' . $this->renderCommandLink('Download Android App', $this->androidClientApkUrl()) . '
              </div>
            </section>
            ' . $notice . '
            ' . $clientProfileSummary . '
            <div class="grid two">
              ' . $clientFormSection . '
              <section class="card">
                <h3>My Client List</h3>
                <p class="muted">Each client card shows only what a tenant needs first: install state, online count, and a few actions. Open details only when needed.</p>
                <div class="stack">' . ($clientCards !== '' ? $clientCards : '<div class="muted">No client yet.</div>') . '</div>
              </section>
            </div>
            <script>
              (function () {
                document.addEventListener("click", function (event) {
                  var button = event.target.closest("[data-diag-dialog]");
                  if (!button) {
                    return;
                  }
                  var dialogId = button.getAttribute("data-diag-dialog");
                  var dialog = dialogId ? document.getElementById(dialogId) : null;
                  if (!dialog) {
                    return;
                  }
                  if (typeof dialog.showModal === "function") {
                    dialog.showModal();
                  } else {
                    dialog.setAttribute("open", "open");
                  }
                });

                document.querySelectorAll("dialog.diag-dialog").forEach(function (dialog) {
                  dialog.addEventListener("click", function (event) {
                    var rect = dialog.getBoundingClientRect();
                    var inside = rect.top <= event.clientY && event.clientY <= rect.bottom
                      && rect.left <= event.clientX && event.clientX <= rect.right;
                    if (!inside) {
                      dialog.close();
                    }
                  });
                });
              })();
            </script>';

        return $this->page('接入点 (Client)', $body, $user, $this->tenantNav('clients'));
    }


    /**
     * @param array<string, mixed> $client
     */
    protected function renderTenantClientLinuxInstall(array $client): string
    {
        $installCommand = $this->clientAgentInstallCommand($client);

        return '<section class="card" style="margin-top:14px;">'
            . '<h4 style="margin:0 0 8px;">Linux Install</h4>'
            . '<div class="muted command-lead">Run this only on the Linux host that will run the client-agent.</div>'
            . $this->renderCommandPanel(
                'Linux Install',
                $installCommand,
                [
                    $this->renderCommandLink('Open Install Link', $this->clientAgentInstallerUrl()),
                    $this->renderCopyButton('Copy Install Command', $installCommand),
                ],
                'Manual/local install for this client on the Linux host that will run the client-agent.'
            )
            . '</section>';
    }

    public function sidecarsPage(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        $points = $this->provisioning->listSidecarPointsForUser((int) $user['id']);
        $editId = (int) $request->input('edit', 0);
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
                  ' . ($canManage ? '<a class="button ghost" href="/tenant" . "/transport-endpoints?edit=' . (int) $point['id'] . '">Edit</a>
                  <form class="inline" method="post" action="/tenant" . "/transport-endpoints/' . (int) $point['id'] . '/request-update">
                    <input type="hidden" name="csrf_token" value="' . $this->escape(SessionAuth::csrfToken()) . '">
                    <button type="submit" class="button secondary">Request Update</button>
                  </form>
                  <form class="inline" method="post" action="/tenant" . "/transport-endpoints/' . (int) $point['id'] . '/delete">
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

        $body = '
            <div class="hero">
              <div>
                <h2>Transport Endpoints</h2>
                <p class="muted">这里管理 Bond 目标侧的边车入口。每个 target transport endpoint 代表一台 C 侧机器和它的监听端口。</p>
              </div>
            </div>
            <div class="grid two">
              <section class="card">
                <h3>' . ($editing !== null ? 'Edit Transport Endpoint' : 'Create Transport Endpoint') . '</h3>
                <p class="muted">Tenant-owned target transport endpoints can be installed and monitored directly here. Admin-shared target transport endpoints will also appear in this list and can be selected in bond forwards.</p>
                <form method="post" action="/tenant" . "/transport-endpoints">
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
                    ' . ($editing !== null ? '<a class="button secondary" href="/tenant" . "/transport-endpoints">Cancel</a>' : '') . '
                  </div>
                </form>
              </section>
              <section class="card">
                <h3>Transport Endpoint List</h3>
                <div class="stack">' . $cards . '</div>
              </section>
            </div>';

        return $this->page('Transport Endpoints', $body, $user, $this->tenantNav('transport-endpoints'));
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
                'UPDATE target transport_points
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
            SessionAuth::flash('success', 'Sidecar update requested. The installed target transport heartbeat will pull the latest panel-hosted version automatically.');
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
        $rows = '';
        $agentBaseUrl = $this->agentBaseUrl();
        $artifactBaseUrl = $this->artifactBaseUrl();
        foreach ($summary['forwards'] as $forward) {
            $configPreview = $this->provisioning->buildClientConfig((int) $forward['id']);
            $configPreview['endpoints'] = array_map(static function (array $endpoint): array {
                return [
                    'endpoint_id' => (int) ($endpoint['endpoint_id'] ?? 0),
                    'node' => (string) ($endpoint['node'] ?? ''),
                    'priority' => (int) ($endpoint['priority'] ?? 0),
                    'weight' => (int) ($endpoint['weight'] ?? 0),
                    'health_status' => (string) ($endpoint['health_status'] ?? ''),
                ];
            }, (array) ($configPreview['endpoints'] ?? []));
            $configJson = json_encode(
                $configPreview,
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            );
            $runtimeManagementHtml = '<div class="muted" style="margin-top:12px;">Manage client profiles and runtime installs from <a href="/tenant/clients"><strong>Client Profiles</strong></a>.</div>';
            if (in_array($this->canonicalClientMode((string) ($forward['client_mode'] ?? 'single')), ['bond', 'mptcp'], true) && !empty($forward['bond_remote_host'])) {
                $runtimeManagementHtml .= '<div class="muted">Current target transport target: '
                    . $this->escape((string) $forward['bond_remote_host'])
                    . ':'
                    . (int) (($forward['bond_remote_port'] ?? 0) ?: $this->defaultBondRemotePort())
                    . '</div>';
            }

            $endpointHtml = '';
            $activeEndpointCount = 0;
            foreach ($forward['endpoints'] as $endpoint) {
                $healthClass = ((string) $endpoint['health_status'] === 'active') ? 'success' : 'info';
                if ((string) $endpoint['health_status'] === 'active') {
                    $activeEndpointCount++;
                }
                $endpointHtml .= '<div><span class="badge ' . $healthClass . '">' . $this->escape((string) $endpoint['health_status']) . '</span> '
                    . $this->escape((string) ($endpoint['node_name'] ?? $endpoint['node'] ?? 'Slave endpoint')) . '</div>';
            }
            if ($endpointHtml === '') {
                $endpointHtml = '<div class="muted">No endpoint assigned yet</div>';
            } else {
                $endpointHtml = '<div class="muted">Total: ' . count($forward['endpoints']) . ' | Active: ' . $activeEndpointCount . '</div>' . $endpointHtml;
            }

            $targetCell = $this->escape((string) $forward['target_host']) . ':' . (int) $forward['target_port'];
            if (in_array($this->canonicalClientMode((string) ($forward['client_mode'] ?? 'single')), ['bond', 'mptcp'], true) && !empty($forward['bond_remote_host'])) {
                if (!empty($forward['bond_sidecar_point_name'])) {
                    $targetCell .= '<div class="muted">point ' . $this->escape((string) $forward['bond_sidecar_point_name']) . '</div>';
                }
                $targetCell .= '<div class="muted">target transport ' . $this->escape((string) $forward['bond_remote_host']) . ':' . (int) ($forward['bond_remote_port'] ?? 0) . '</div>';
            }

            $rows .= '<tr>
                <td>' . (int) $forward['id'] . '</td>
                <td>' . (int) $forward['listen_port'] . '</td>
                <td>' . $this->escape((string) ($forward['forward_combination_name'] ?? '')) . '</td>
                <td>' . $targetCell . '</td>
                <td>' . $this->escape($this->transportModeLabel((string) $forward['transport_mode'])) . '</td>
                <td>' . $endpointHtml . '</td>
                <td>
                    <div class="actions">' .
                        (!empty($features['allow_delete_forwards'])
                            ? '<form class="inline" method="post" action="/tenant/forwards/' . (int) $forward['id'] . '/delete">
                                <input type="hidden" name="csrf_token" value="' . $this->escape(SessionAuth::csrfToken()) . '">
                                <button type="submit" class="button danger">Delete</button>
                              </form>'
                            : '<span class="muted">Delete disabled by policy</span>') .
                    '</div>' .
                    $runtimeManagementHtml .
                    (!empty($features['allow_view_client_config'])
                        ? '<details style="margin-top:12px;">
                            <summary>View client config</summary>
                            <pre>' . $this->escape($configJson) . '</pre>
                          </details>'
                        : '') . '
                </td>
            </tr>';
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="7" class="muted">No forward rules yet. Create your first forward from the button above.</td></tr>';
        }

        $defaultHost = $this->escape((string) $this->config->get('DEFAULT_CDN_HOST', 'edge.example.com'));
        $defaultEndpointCount = 1;
        $defaultClientMode = 'single';
        $defaultBondRemotePort = $this->defaultBondRemotePort();
        $csrf = $this->escape(SessionAuth::csrfToken());
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
                <form class="inline" method="post" action="/tenant-services-internal/' . (int) $service['id'] . '/enroll-node>
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <button class="button" type="submit">Generate Node Enrollment Token</button>
                </form>
              </div>
            </section>';
        }

        $body = '
            <div class="hero">
              <div>
                <h2>' . $this->escape((string) $service['name']) . '</h2>
                <p class="muted">Status: ' . $this->escape((string) $service['status']) . ' | Expire At: ' . $this->escape((string) $service['expire_at']) . '</p>
              </div>
              <div class="actions">
                <a class="button ghost" href="/tenant/forwards">返回转发配置</a>
              </div>
            </div>
            <div class="grid two">
              <section class="card">
                <h3>Service Info</h3>
                <p class="muted">WHMCS Service ID: ' . $this->escape((string) $service['whmcs_service_id']) . '</p>
                <p class="muted">Plan: ' . $this->escape((string) ($summary['plan_name'] ?? '')) . '</p>
                <p class="muted">Allowed Client Groups: ' . $this->escape(implode(', ', array_map(static fn(array $group): string => (string) $group['name'], $allowedClientGroups))) . '</p>
                <p class="muted">Bandwidth Limit: ' . (int) $service['bandwidth_limit_mbps'] . ' Mbps</p>
                <p class="muted">Traffic Policy: ' . (((string) ($billing['mode'] ?? 'monthly_bundle') === 'monthly_bundle') ? ((int) ($billing['included_traffic_gb'] ?? 0) . ' GB restored every billing month') : 'metered by monthly cycle') . '</p>
                <p class="muted">Current Cycle Used Traffic: ' . round(((int) ($billing['current_cycle_used_bytes'] ?? 0)) / 1024 / 1024 / 1024, 2) . ' GB</p>
                <p class="muted">Lifetime Used Traffic: ' . round(((int) $service['traffic_used_bytes']) / 1024 / 1024 / 1024, 2) . ' GB</p>
                <div class="flash success" style="margin-top:16px;">
                  <strong>Flexible Package</strong><br>
                  Base Bandwidth: ' . (int) ($billing['base_bandwidth_mbps'] ?? $service['bandwidth_limit_mbps']) . ' Mbps<br>
                  Current Bandwidth: ' . (int) ($billing['current_bandwidth_mbps'] ?? $service['bandwidth_limit_mbps']) . ' Mbps<br>
                  Bandwidth Add-on: +' . (int) ($billing['bandwidth_step_mbps'] ?? 0) . ' Mbps => ' . $this->escape((string) ($billing['bandwidth_step_price_formatted'] ?? 'CNY 0.00')) . ' each month<br>
                  Current Extra Bandwidth: ' . (int) ($billing['extra_bandwidth_mbps'] ?? 0) . ' Mbps (' . (int) ($billing['extra_bandwidth_steps'] ?? 0) . ' step)<br>
                  Current Bandwidth Add-on: ' . $this->escape((string) ($billing['bandwidth_addon_formatted'] ?? 'CNY 0.00')) . '<br>
                  Included Clients: ' . (int) ($billing['included_client_count'] ?? 0) . '<br>
                  Current Explicit Clients In Use: ' . (int) ($billing['active_client_count'] ?? 0) . '<br>
                  Extra Client Charge: ' . $this->escape((string) ($billing['extra_client_price_formatted'] ?? 'CNY 0.00')) . ' each month<br>
                  Current Extra Client Charge: ' . $this->escape((string) ($billing['client_addon_formatted'] ?? 'CNY 0.00')) . '
                </div>
                <form method="post" action="/tenant-services-internal/' . (int) $service['id'] . '/flexible-options" style="margin-top:12px;">
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
                <div class="flash success" style="margin-top:16px;">
                  <strong>Billing This Cycle</strong><br>
                  Mode: ' . $this->escape((string) ($billing['mode_label'] ?? 'Monthly Bundle')) . '<br>
                  Base Fee: ' . $this->escape((string) ($billing['base_fee_formatted'] ?? 'CNY 0.00')) . '<br>
                  Traffic Charge: ' . $this->escape((string) ($billing['traffic_charge_formatted'] ?? 'CNY 0.00')) . '<br>
                  Bandwidth Add-on: ' . $this->escape((string) ($billing['bandwidth_addon_formatted'] ?? 'CNY 0.00')) . '<br>
                  Client Add-on: ' . $this->escape((string) ($billing['client_addon_formatted'] ?? 'CNY 0.00')) . '<br>
                  ' . (((string) ($billing['mode'] ?? 'monthly_bundle')) === 'monthly_bundle'
                    ? 'Included Traffic: ' . (int) ($billing['included_traffic_gb'] ?? 0) . ' GB / month<br>Remaining Included Traffic: ' . $this->escape((string) (($billing['remaining_included_gb'] ?? 0))) . ' GB<br>'
                    : 'Usage Price: ' . $this->escape((string) ($billing['traffic_price_per_gb_formatted'] ?? 'CNY 0.0000 / GB')) . '<br>Billable Traffic: ' . $this->escape((string) ($billing['billable_gb'] ?? 0)) . ' GB<br>') . '
                  Current Estimate: ' . $this->escape((string) ($billing['estimated_total_formatted'] ?? 'CNY 0.00')) . '<br>
                  Billing Cycle: ' . $this->escape((string) ($billing['cycle_started_at'] ?? '')) . ' -> ' . $this->escape((string) ($billing['cycle_ends_at'] ?? '')) . '
                </div>
                <div class="flash success" style="margin-top:16px;">
                  <strong>Traffic Reset</strong><br>
                  Reset Price: ' . $this->escape($trafficResetCurrency) . ' ' . $this->escape(number_format($trafficResetPrice, 2)) . '<br>
                  This action resets <code>Current Cycle Used Traffic</code> back to 0 for this service. Lifetime traffic stays unchanged.
                </div>
                <form method="post" action="/tenant-services-internal/' . (int) $service['id'] . '/reset-traffic" style="margin-top:12px;">
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
                <h3 style="margin-top:18px;">Available Lines</h3>
                <ul class="list">' . $availableClientHtml . '</ul>
                <p class="muted">If there is no line here, admin still needs to create a client and authorize it through your plan.</p>
                <div class="flash success" style="margin-top:16px;">
                  <strong>Fastest way to get your first forward working:</strong><br>
                  1. Open <code>Create Forward</code>.<br>
                  2. Pick one published <code>Forward Combination</code>.<br>
                  3. Fill target host and target port.<br>
                  4. Save. The panel keeps the backend route package fixed.
                </div>
                <div class="actions">
                  <a class="button" href="/tenant-services-internal/' . (int) $service['id'] . '/create-forward">Create New Forward</a>
                  <a class="button ghost" href="/knowledgebase?thread=getting-started-first-forward-checklist">Knowledgebase</a>
                  <form class="inline" method="post" action="/tenant-services-internal/' . (int) $service['id'] . '/regenerate-client-token">
                    <input type="hidden" name="csrf_token" value="' . $csrf . '">
                    <button class="button secondary" type="submit" ' . (empty($features['allow_rotate_client_token']) ? 'disabled' : '') . '>Rotate Client Token</button>
                  </form>
                </div>
                <pre>' . $this->escape((string) $service['client_token']) . '</pre>
              </section>
              <section class="card">
                <h3>Create Forward</h3>
                <p class="muted">Choose one route package, then fill your backend target on the dedicated create page.</p>
                <p class="field-note">Need help? <a href="/knowledgebase?thread=getting-started-first-forward-checklist">Quick Start</a> ? <a href="/knowledgebase?thread=connection-modes-single-race-bond">Advanced Options</a> ? <a href="/knowledgebase?thread=troubleshooting-common-mistakes">Usage Patterns</a></p>
                ' . (empty($features['allow_manage_forwards']) ? '<div class="flash danger">Forward creation is disabled for your plan/account.</div>' : '') . '
                ' . $createForwardNotice . '
                <div class="actions" style="margin-top:12px;">
                  <a class="button" href="/tenant-services-internal/' . (int) $service['id'] . '/create-forward">Create Forward</a>
                  <a class="button ghost" href="/knowledgebase?thread=getting-started-first-forward-checklist">Knowledgebase</a>
                </div>
              </section>
            </div>
            <section class="card" style="margin-top:20px;">
              <h3>Forward Rules</h3>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr><th>ID</th><th>Local Listen</th><th>Forward Combination</th><th>Target</th><th>Transport</th><th>Endpoint Status</th><th>Actions</th></tr>
                  </thead>
                  <tbody>' . $rows . '</tbody>
                </table>
              </div>
            </section>'
            . $mySlavesSection;

        return $this->page('服务详情', $body, $user, $this->tenantNav('service', $serviceId));
    }

    public function forwardWizardRedirect(Request $request, array $params): Response
    {
        $serviceId = $this->idParam($params);
        return Response::redirect('/tenant-services-internal/' . $serviceId . '/create-forward');
    }

    public function quickCreateForward(Request $request, array $params): Response
    {
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

        $summary = $this->provisioning->serviceSummary($serviceId);
        $features = $summary['effective_features'] ?? [];
        $availableForwardCombinations = is_array($summary['available_forward_combinations'] ?? null) ? $summary['available_forward_combinations'] : [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                SessionAuth::validateCsrf($request);
                if (empty($features['allow_manage_forwards'])) {
                    throw new ApiException('feature_denied_manage_forwards', 403);
                }
                $payload = $this->normalizeTenantForwardPayload($request->body());
                $this->provisioning->createForward($serviceId, $payload, 'tenant');
                SessionAuth::flash('success', 'Forward created.');
                return Response::redirect('/tenant-services-internal/' . $serviceId);
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
              <form method="post" action="/tenant-services-internal/' . $serviceId . '/create-forward">
                <input type="hidden" name="csrf_token" value="' . $csrf . '">
                <input type="hidden" name="target_source" value="service_ip">
                <input type="hidden" name="target_service_id" value="' . $serviceId . '">
                <div class="form-grid">
                  <div class="field"><label>Forward Route</label><select name="forward_route_id" required>' . $routeOptions . '</select></div>
                  <div class="field">
                    <label>Target IP</label>
                    <input type="text" value="' . $this->escape((string) ($service['server_public_ip'] ?? 'pending sync')) . '" readonly>
                    <p class="muted">This forward uses the purchased server IP automatically. Update the service sync if the IP is stale.</p>
                  </div>
                  <div class="field"><label>Target Port</label><input type="number" name="target_port" placeholder="443" required></div>
                  <div class="field"><label>Local Listen Port</label><input type="number" name="listen_port" placeholder="auto from line range"></div>
                  <div class="field"><label>Transport</label><select name="transport_mode"><option value="tcp">TCP</option><option value="tcp+udp">TCP + UDP</option><option value="udp">UDP</option></select></div>
                </div>
                <div class="field-note" id="forward-combination-help">Choose one admin-defined route package. The panel keeps routing details hidden until you create the forward.</div>
                <div class="actions" style="margin-top:20px;">
                  <button class="button" type="submit" ' . (empty($features['allow_manage_forwards']) || $availableForwardCombinations === [] ? 'disabled' : '') . '>Create Forward</button>
                  <a class="button ghost" href="/tenant-services-internal/' . $serviceId . '">Cancel</a>
                </div>
              </form>
            </div>';

        return $this->page('Create Forward', $body, $user, $this->tenantNav('wizard', $serviceId));
    }

    public function editForward(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        $serviceId = $this->idParam($params);
        $forwardId = (int) ($params['fid'] ?? 0);
        $forward = $this->db->fetchOne(
            'SELECT f.*, s.user_id, combo.tenant_label AS forward_combination_label
             FROM forwards f
             INNER JOIN services s ON s.id = f.service_id
             LEFT JOIN forward_combinations combo ON combo.id = f.forward_combination_id
             WHERE f.id = :id AND f.service_id = :service_id LIMIT 1',
            ['id' => $forwardId, 'service_id' => $serviceId]
        );
        if ($forward === null || (int) $forward['user_id'] !== (int) $user['id']) {
            throw new ApiException('forward_not_found', 404);
        }

        $csrf = $this->escape(SessionAuth::csrfToken());
        $body = '
            <div class="hero">
              <div>
                <h2>Edit Forward</h2>
                <p class="muted">Change your backend target settings without changing the admin-defined route package.</p>
              </div>
            </div>
            <div class="card">
              <form method="post" action="/tenant-services-internal/' . $serviceId . '/forward/' . $forwardId . '/edit">
                <input type="hidden" name="csrf_token" value="' . $csrf . '">
                <div class="form-grid">
                  <div class="field"><label>Current Forward Combination</label><div class="value-chip">' . $this->escape((string) ($forward['forward_combination_label'] ?? '')) . '</div></div>
                  <div class="field"><label>Target Host</label><input type="text" name="target_host" value="' . $this->escape((string) $forward['target_host']) . '" required></div>
                  <div class="field"><label>Target Port</label><input type="number" name="target_port" value="' . (int) $forward['target_port'] . '" required></div>
                  <div class="field"><label>Local Listen Port</label><input type="number" name="listen_port" value="' . (int) $forward['listen_port'] . '"></div>
                  <div class="field"><label>Transport</label><select name="transport_mode"><option value="tcp"' . ((string) $forward['transport_mode'] === 'tcp' ? ' selected' : '') . '>TCP</option><option value="tcp+udp"' . ((string) $forward['transport_mode'] === 'tcp+udp' ? ' selected' : '') . '>TCP + UDP</option><option value="udp"' . ((string) $forward['transport_mode'] === 'udp' ? ' selected' : '') . '>UDP</option></select></div>
                </div>
                <div class="actions" style="margin-top:20px;">
                  <button class="button" type="submit">Save Forward</button>
                  <a class="button ghost" href="/tenant-services-internal/' . $serviceId . '">Cancel</a>
                </div>
              </form>
            </div>';

        return $this->page('Edit Forward', $body, $user, $this->tenantNav('service', $serviceId));
    }

    public function saveForwardEdit(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        $serviceId = $this->idParam($params);
        $forwardId = (int) ($params['fid'] ?? 0);

        try {
            SessionAuth::validateCsrf($request);
            $forward = $this->db->fetchOne(
                'SELECT f.*, s.user_id
                 FROM forwards f
                 INNER JOIN services s ON s.id = f.service_id
                 WHERE f.id = :id AND f.service_id = :service_id LIMIT 1',
                ['id' => $forwardId, 'service_id' => $serviceId]
            );
            if ($forward === null || (int) $forward['user_id'] !== (int) $user['id']) {
                throw new ApiException('forward_not_found', 404);
            }

            $payload = $this->normalizeTenantForwardEditPayload($request->body());
            $this->provisioning->updateForwardNonStructural($forwardId, $payload, 'tenant');
            SessionAuth::flash('success', 'Forward updated.');
            return Response::redirect('/tenant-services-internal/' . $serviceId);
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to update forward: ' . $this->displayThrowableMessage($throwable));
        }

        return Response::redirect('/tenant-services-internal/' . $serviceId . '/forward/' . $forwardId . '/edit');
    }

    public function forwardWizard(Request $request, array $params): Response
    {
        $serviceId = $this->idParam($params);
        return Response::redirect('/tenant-services-internal/' . $serviceId . '/create-forward');
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
            $isCreate = (int) $request->input('id', 0) <= 0;

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
                'online_device_limit' => $request->input('online_device_limit', 1),
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
            if (empty(($summary['effective_features'] ?? [])['allow_manage_forwards'])) {
                throw new ApiException('feature_denied_manage_forwards', 403);
            }

            $payload = $this->normalizeTenantForwardPayload($request->body());
            $this->provisioning->createForward($serviceId, $payload, 'tenant');
            SessionAuth::flash('success', 'Forward created and queued for provisioning.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', $this->forwardCreateFlashMessage($throwable));
        }

        return Response::redirect('/tenant-services-internal/' . $serviceId);
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

        return Response::redirect('/tenant-services-internal/' . $serviceId);
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
            return Response::redirect('/tenant-services-internal/' . (int) $node['service_id']);
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to remove node: ' . $this->displayThrowableMessage($throwable));
        }

        return Response::redirect('/tenant/services');
    }

    public function deleteForward(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null || (string) $user['role'] !== 'tenant') {
            return Response::redirect('/login');
        }

        $forwardId = $this->idParam($params);
        try {
            SessionAuth::validateCsrf($request);
            $forward = $this->db->fetchOne(
                'SELECT f.*, s.user_id
                 FROM forwards f
                 INNER JOIN services s ON s.id = f.service_id
                 WHERE f.id = :id LIMIT 1',
                ['id' => $forwardId]
            );
            if ($forward === null || (int) $forward['user_id'] !== (int) $user['id']) {
                throw new ApiException('forward_not_found', 404);
            }
            $summary = $this->provisioning->serviceSummary((int) $forward['service_id']);
            if (empty(($summary['effective_features'] ?? [])['allow_delete_forwards'])) {
                throw new ApiException('feature_denied_delete_forwards', 403);
            }

            $this->provisioning->deleteForward($forwardId, 'tenant');
            SessionAuth::flash('success', 'Forward deleted.');
            return Response::redirect('/tenant-services-internal/' . (int) $forward['service_id']);
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Failed to delete forward: ' . $throwable->getMessage());
        }

        return Response::redirect('/tenant/forwards');
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

        return Response::redirect('/tenant-services-internal/' . $serviceId);
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
                <h2>Slave Groups</h2>
                <p class="muted">Create sharing groups, invite others, and share your slaves with group members.</p>
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

        return $this->page('Slave Groups', $body, $user, $this->tenantNav('slave-groups'));
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

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeTenantForwardPayload(array $payload): array
    {
        $targetSource = strtolower(trim((string) ($payload['target_source'] ?? '')));
        if ($targetSource !== 'service_ip') {
            $targetSource = 'manual';
        }

        return [
            'forward_combination_id' => max(0, (int) ($payload['forward_combination_id'] ?? 0)),
            'forward_route_id' => max(0, (int) ($payload['forward_route_id'] ?? 0)),
            'listen_port' => max(0, (int) ($payload['listen_port'] ?? 0)),
            'target_host' => trim((string) ($payload['target_host'] ?? '')),
            'target_port' => max(0, (int) ($payload['target_port'] ?? 0)),
            'target_source' => $targetSource,
            'target_service_id' => $targetSource === 'service_ip' ? max(0, (int) ($payload['target_service_id'] ?? 0)) : 0,
            'transport_mode' => match (strtolower(trim((string) ($payload['transport_mode'] ?? 'tcp')))) {
                'udp' => 'udp',
                'tcp+udp', 'udp+tcp', 'both' => 'tcp+udp',
                default => 'tcp',
            },
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeTenantForwardEditPayload(array $payload): array
    {
        return [
            'listen_port' => max(0, (int) ($payload['listen_port'] ?? 0)),
            'target_host' => trim((string) ($payload['target_host'] ?? '')),
            'target_port' => max(0, (int) ($payload['target_port'] ?? 0)),
            'transport_mode' => match (strtolower(trim((string) ($payload['transport_mode'] ?? 'tcp')))) {
                'udp' => 'udp',
                'tcp+udp', 'udp+tcp', 'both' => 'tcp+udp',
                default => 'tcp',
            },
        ];
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

                function updateSummary(sidecarMode, option) {
                  if (!summary) {
                    return;
                  }
                  if (!sidecarMode) {
                    summary.style.display = "none";
                    summary.textContent = "";
                    return;
                  }
                  summary.style.display = "block";
                  summary.textContent = option
                    ? "Using saved point: " + option.text
                    : "Manual entry: fill the target transport host and port.";
                }

                function updateFields(sidecarMode, option) {
                  if (hostField) {
                    hostField.style.display = target transportMode && !option ? "" : "none";
                  }
                  if (portField) {
                    portField.style.display = target transportMode && !option ? "" : "none";
                  }
                }

                function refresh() {
                  var target transportMode = isSidecarMode();
                  var option = selectedPoint();
                  if (sidecarMode && option) {
                    hostInput.value = option.dataset.host || "";
                    portInput.value = selectedPortForMode(option);
                  } else if (!option) {
                    resetManualFields();
                  }
                  updateFields(sidecarMode, option);
                  updateSummary(sidecarMode, option);
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
            $details = $throwable->details();

            if ($message === 'node_group_not_allowed') {
                $allowed = implode(', ', $details['allowed_node_groups'] ?? []);
                return 'This service is not allowed to use that node group. Allowed groups: ' . $allowed;
            }
            if ($message === 'line_client_required') {
                return 'Please choose a line client before creating the rule.';
            }
            if ($message === 'line_client_not_allowed') {
                return 'This service is not allowed to use the selected line client.';
            }
            if ($message === 'line_client_group_not_allowed') {
                return 'This service is not allowed to use the selected line client group.';
            }
            if ($message === 'feature_denied_manage_forwards') {
                return 'Your plan or account is not allowed to create or modify forwards.';
            }
        if ($message === 'bond_transport_not_supported') {
            return 'The selected target transport transport is not supported by the currently installed runtime yet. Update the client runtime and try again.';
        }
            if ($message === 'bond_not_configured') {
                return 'Bond / MPTCP transport mode is not ready yet. This service needs a usable client token, or admin must configure BOND_TOKEN_SECRET as a fallback.';
            }
            if ($message === 'bond_remote_target_required') {
                return 'Bond / MPTCP transport mode needs a target-side target transport host and port. Fill in Sidecar Host and Sidecar Port first.';
            }
            if ($message === 'bond_sidecar_point_not_allowed') {
                return 'The selected target transport endpoint is not available for this service. Pick one of your own target transport endpoints or an admin-shared point.';
            }
            if ($message === 'no_available_nodes') {
                $available = implode(', ', $details['available_node_groups'] ?? []);
                $missingHosts = $details['nodes_missing_public_host'] ?? [];
                if (is_array($missingHosts) && $missingHosts !== []) {
                    $names = implode(', ', array_map(static fn(array $node): string => (string) ($node['name'] ?? ('#' . (string) ($node['id'] ?? ''))), $missingHosts));
                    return 'The selected slave group has nodes, but they do not have a usable public IP/host yet: ' . $names . '. Fix the slave public IP or public host first.';
                }

                return 'No online slave is currently available in the selected group. Available groups now: ' . $available;
            }

            return 'Failed to create forward: ' . $message;
        }

        return 'Failed to create forward: ' . $throwable->getMessage();
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
        $nav = [
            ['href' => '/tenant', 'label' => '介绍', 'active' => $active === 'intro'],
            ['href' => '/tenant/account', 'label' => '账户', 'active' => $active === 'account'],
            ['href' => '/tenant/plans', 'label' => '套餐', 'active' => $active === 'plans'],
            ['href' => '/tenant/forwards', 'label' => '转发配置', 'active' => in_array($active, ['forwards', 'service', 'wizard'], true)],
            ['href' => '/tenant/clients', 'label' => '接入点 (Client)', 'active' => $active === 'clients'],
            ['href' => '/tenant-transport-endpoints', 'label' => 'Transport Endpoints', 'active' => $active === 'transport-endpoints'],
            ['href' => '/tenant/slave-groups', 'label' => 'Slave 群组', 'active' => $active === 'slave-groups'],
            ['href' => '/knowledgebase', 'label' => '知识库', 'active' => $active === 'knowledgebase'],
        ];

        if ($serviceId !== null) {
            $nav[] = [
                'href' => '/tenant-services-internal/' . $serviceId,
                'label' => '服务详情',
                'active' => $active === 'service',
            ];

            if ($active === 'wizard') {
                $nav[] = [
                    'href' => '/tenant-services-internal/' . $serviceId . '/create-forward',
                    'label' => '创建 Forward',
                    'active' => true,
                ];
            }
        }

        return $nav;
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
             WHERE s.user_id = :user_id AND f.enabled = 0',
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
