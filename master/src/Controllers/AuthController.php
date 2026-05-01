<?php
declare(strict_types=1);

namespace Leikwan\Master\Controllers;

use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;
use Leikwan\Master\Support\PasswordResetService;
use Leikwan\Master\Support\SessionAuth;
use Leikwan\Master\Support\KnowledgebaseService;
use Leikwan\Master\Support\TenantRegistrationService;
use Leikwan\Master\Support\WhmcsIdentityService;

final class AuthController extends BaseController
{
    public function home(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);

        $brandName = $this->escape($this->displayBrandName());
        $adminCount = (int) ($this->db->fetchOne('SELECT COUNT(*) AS total FROM users WHERE role = :role', ['role' => 'admin'])['total'] ?? 0);

        $heroPrimary = '<a class="button" href="/login">Sign In</a>';
        $heroSecondary = '<a class="button ghost" href="/register">Create Account</a>';
        $consoleCardTitle = 'Tenant Panel';
        $consoleCardBody = 'Account, plans, forwards, client and sidecar.';
        $consoleCardAction = '<a class="button" href="/login">Open Panel</a>';

        if ($user !== null) {
            $dashboardPath = $this->escape($this->dashboardPathForUser($user));
            $dashboardLabel = $this->escape($this->dashboardLabelForUser($user));
            $heroPrimary = '<a class="button" href="' . $dashboardPath . '">Open ' . $dashboardLabel . '</a>';
            $heroSecondary = (string) ($user['role'] ?? '') === 'admin'
                ? '<a class="button ghost" href="/admin/tenants">Manage Tenants</a>'
                : '<a class="button ghost" href="/tenant/forwards">Forward Config</a>';
            $consoleCardTitle = 'Current Session';
            $consoleCardBody = 'Jump back into the console you were using.';
            $consoleCardAction = $heroPrimary;
        }

        $opsCard = $adminCount === 0
            ? '<section class="surface-card entry-card">
                <span class="card-kicker">Bootstrap</span>
                <h3>Create the first admin</h3>
                <p>Finish the first setup once, then everything continues in the web console.</p>
                <pre>php master/scripts/create_user.php --email admin@example.com --password ChangeMe123! --role admin --name "Platform Admin"</pre>
              </section>'
            : '<section class="surface-card entry-card">
                <span class="card-kicker">Admin Console</span>
                <h3>Plans, tenants, nodes</h3>
                <p>Operator work stays in the admin console and does not share the tenant flow.</p>
                <div class="actions"><a class="button ghost" href="/login">Operator Sign In</a></div>
              </section>';

        $body = '
            <section class="landing-hero">
              <div class="hero-copy">
                <span class="eyebrow">LeiKwan Bridge</span>
                <h1>Lightweight IXP control plane.</h1>
                <p>' . $brandName . ' for account, plans, forwards, client delivery and sidecar management.</p>
                <div class="hero-actions">' . $heroPrimary . $heroSecondary . '</div>
              </div>
              <section class="surface-card surface-note">
                <span class="card-kicker">Overview</span>
                <h3>Short homepage by design</h3>
                <p>Use this page as the front door. Real work continues inside the tenant or admin console.</p>
              </section>
            </section>
            <section class="entry-grid">
              <section class="surface-card entry-card">
                <span class="card-kicker">' . $this->escape($consoleCardTitle) . '</span>
                <h3>' . $this->escape($consoleCardTitle) . '</h3>
                <p>' . $this->escape($consoleCardBody) . '</p>
                <div class="actions">' . $consoleCardAction . '</div>
              </section>
              ' . $opsCard . '
              <section class="surface-card entry-card">
                <span class="card-kicker">Docs</span>
                <h3>Knowledgebase</h3>
                <p>Quick start, routing modes and troubleshooting in one place.</p>
                <div class="actions"><a class="button ghost" href="/knowledgebase">Open Docs</a></div>
              </section>
            </section>';

        return $this->publicPage('LeiKwan Bridge', $body, $user);

        $brandName = $this->escape($this->displayBrandName());
        $productName = $this->escape('LeiKwan Bridge');
        $tenantCount = (int) ($this->db->fetchOne('SELECT COUNT(*) AS total FROM users WHERE role = :role', ['role' => 'tenant'])['total'] ?? 0);
        $serviceCount = (int) ($this->db->fetchOne('SELECT COUNT(*) AS total FROM services WHERE status = :status', ['status' => 'active'])['total'] ?? 0);
        $nodeCount = (int) ($this->db->fetchOne('SELECT COUNT(*) AS total FROM nodes')['total'] ?? 0);
        $planCount = (int) ($this->db->fetchOne('SELECT COUNT(*) AS total FROM service_plans')['total'] ?? 0);
        $adminCount = (int) ($this->db->fetchOne('SELECT COUNT(*) AS total FROM users WHERE role = :role', ['role' => 'admin'])['total'] ?? 0);

        $heroPrimary = '<a class="button" href="/login">Sign In</a>';
        $heroSecondary = '<a class="button ghost" href="/register">Create Account</a>';
        $entryCard = '
            <section class="surface-card">
              <span class="card-kicker">Get Started</span>
              <h3>Public entry for operators and tenants</h3>
              <p>Sign in, open the right console, and keep the detailed work inside the panel.</p>
              <div class="portal-actions">
                <a class="button" href="/register">Create Tenant Account</a>
                <a class="button secondary" href="/knowledgebase">Knowledgebase</a>
              </div>
            </section>';

        if ($user !== null) {
            $dashboardPath = $this->escape($this->dashboardPathForUser($user));
            $dashboardLabel = $this->escape($this->dashboardLabelForUser($user));
            $heroPrimary = '<a class="button" href="' . $dashboardPath . '">Open ' . $dashboardLabel . '</a>';
            $heroSecondary = (string) ($user['role'] ?? '') === 'admin'
                ? '<a class="button secondary" href="/tenant">Tenant Area</a>'
                : '<a class="button secondary" href="/tenant/forwards">转发配置</a>';
            $entryCard = '
                <section class="surface-card">
                  <span class="card-kicker">Session</span>
                  <h3>Your console is ready</h3>
                  <p>The homepage is now intentionally short. Continue the actual work inside your console.</p>
                  <div class="portal-actions">' . $heroPrimary . $heroSecondary . '</div>
                </section>';
        }

        $stats = [
            ['label' => 'Online Nodes', 'value' => $nodeCount],
            ['label' => 'Active Services', 'value' => $serviceCount],
            ['label' => 'Tenant Accounts', 'value' => $tenantCount],
            ['label' => 'Plans Ready', 'value' => $planCount],
        ];
        $statCards = '';
        foreach ($stats as $stat) {
            $statCards .= '<section class="surface-card">'
                . '<div class="kpi-label">' . $this->escape($stat['label']) . '</div>'
                . '<div class="kpi-value">' . (int) $stat['value'] . '</div>'
                . '</section>';
        }

        $secondaryCard = $adminCount === 0
            ? '<section class="surface-card">
                <span class="card-kicker">First-Time Setup</span>
                <h3>Create the first admin</h3>
                <p>Finish the initial bootstrap once, then return to the web console.</p>
                <pre>php master/scripts/create_user.php --email admin@example.com --password ChangeMe123! --role admin --name "Platform Admin"</pre>
              </section>'
            : '<section class="surface-card">
                <span class="card-kicker">Console Map</span>
                <h3>Three clear work areas</h3>
                <ul class="list">
                  <li>Homepage: entry and sign-in.</li>
                  <li>Admin Console: plans, tenants, nodes, rules.</li>
                  <li>Tenant Panel: account, plans, forwards, client, sidecar.</li>
                </ul>
              </section>';

        $body = '
            <div class="landing-stack">
              <section class="landing-hero">
                <div class="hero-copy">
                  <span class="eyebrow">' . $productName . '</span>
                  <h1>IXP forwarding, simplified.</h1>
                  <p>' . $brandName . ' keeps access, tenant delivery and traffic operations inside one control plane.</p>
                  <div class="hero-actions">' . $heroPrimary . $heroSecondary . '</div>
                  <div class="status-row">
                    <span class="status-pill">IXP Access</span>
                    <span class="status-pill">Tenant Delivery</span>
                    <span class="status-pill">Traffic Operations</span>
                  </div>
                </div>
                <section class="surface-card">
                  <span class="panel-kicker">Fast Entry</span>
                  <h3>Short homepage, deeper consoles</h3>
                  <div class="hero-list">
                    <div class="step-item">
                      <span class="step-index">1</span>
                      <div class="step-copy">
                        <strong>Sign in</strong>
                        <span>Use panel credentials.</span>
                      </div>
                    </div>
                    <div class="step-item">
                      <span class="step-index">2</span>
                      <div class="step-copy">
                        <strong>Open the right console</strong>
                        <span>Admin and tenant flows stay separate.</span>
                      </div>
                    </div>
                    <div class="step-item">
                      <span class="step-index">3</span>
                      <div class="step-copy">
                        <strong>Operate from there</strong>
                        <span>Homepage no longer carries the long product story.</span>
                      </div>
                    </div>
                  </div>
                </section>
              </section>
              <section class="kpi-grid">' . $statCards . '</section>
              <section class="portal-grid">
                ' . $entryCard . '
                ' . $secondaryCard . '
              </section>
            </div>';

        return $this->publicPage('LeiKwan Bridge', $body, $user);
        $brandName = $this->escape($this->displayBrandName());
        $productName = $this->escape('利群會聚 LeiKwan Bridge');

        $tenantCount = (int) ($this->db->fetchOne('SELECT COUNT(*) AS total FROM users WHERE role = :role', ['role' => 'tenant'])['total'] ?? 0);
        $serviceCount = (int) ($this->db->fetchOne('SELECT COUNT(*) AS total FROM services WHERE status = :status', ['status' => 'active'])['total'] ?? 0);
        $nodeCount = (int) ($this->db->fetchOne('SELECT COUNT(*) AS total FROM nodes')['total'] ?? 0);
        $planCount = (int) ($this->db->fetchOne('SELECT COUNT(*) AS total FROM service_plans')['total'] ?? 0);
        $adminCount = (int) ($this->db->fetchOne('SELECT COUNT(*) AS total FROM users WHERE role = :role', ['role' => 'admin'])['total'] ?? 0);

        $heroPrimary = '<a class="button" href="/login">Sign In</a>';
        $heroSecondary = '<a class="button ghost" href="/register">Create Account</a>';
        $entryCard = '
            <section class="surface-card">
              <span class="card-kicker">适用场景</span>
              <h3>适合需要稳定接入与合规管控的业务</h3>
              <ul class="list">
                <li>面向需要解决 IXP 入网难、出网路径复杂、跨地域接入门槛高的业务场景。</li>
                <li>面向需要把租户、服务、线路、节点权限拆开管理的交付型平台。</li>
                <li>面向需要把流量路径、客户端配置、诊断与更新统一收口的运维团队。</li>
              </ul>
              <div class="portal-actions">
                <a class="button" href="/register">注册成为 Tenant</a>
                <a class="button secondary" href="/login">已有账号登录</a>
              </div>
            </section>';

        if ($user !== null) {
            $dashboardPath = $this->escape($this->dashboardPathForUser($user));
            $dashboardLabel = $this->escape($this->dashboardLabelForUser($user));
            $heroPrimary = '<a class="button" href="' . $dashboardPath . '">Open ' . $dashboardLabel . '</a>';
            $heroSecondary = (string) ($user['role'] ?? '') === 'admin'
                ? '<a class="button secondary" href="/tenant">Tenant Area</a>'
                : '<a class="button secondary" href="/tenant/clients">My Clients</a>';
            $entryCard = '
                <section class="surface-card">
                  <span class="card-kicker">当前入口</span>
                  <h3>你的会话已经就绪</h3>
                  <p>首页现在负责说明产品定位，真正的运维和租户动作则留在各自控制台里完成。</p>
                  <div class="portal-actions">' . $heroPrimary . $heroSecondary . '</div>
                </section>';
        }

        $stats = [
            ['label' => 'Online Nodes', 'value' => $nodeCount, 'hint' => '在线边缘节点与 IXP 接入资源'],
            ['label' => 'Active Services', 'value' => $serviceCount, 'hint' => '已经交付并处于启用状态的服务'],
            ['label' => 'Tenant Accounts', 'value' => $tenantCount, 'hint' => '被平台统一管理的租户账号'],
            ['label' => 'Plans Ready', 'value' => $planCount, 'hint' => '可直接绑定交付策略的套餐方案'],
        ];

        $statCards = '';
        foreach ($stats as $stat) {
            $statCards .= '<section class="surface-card">'
                . '<div class="kpi-label">' . $this->escape($stat['label']) . '</div>'
                . '<div class="kpi-value">' . (int) $stat['value'] . '</div>'
                . '<p>' . $this->escape($stat['hint']) . '</p>'
                . '</section>';
        }

        $featureCards = '
            <section class="surface-card">
              <span class="card-kicker">IXP Access</span>
              <h3>把复杂接入抽象成可交付的资源</h3>
              <p>利群會聚 LeiKwan Bridge 用节点组、前向链路、套餐方案和客户端能力，去拆掉 IXP 入网过程里的人工拼装和分散配置。</p>
            </section>
            <section class="surface-card">
              <span class="card-kicker">Traffic Compliance</span>
              <h3>把合规要求落成可执行的流量规则</h3>
              <p>从租户、服务到节点组与出口路径，平台把流量放行、默认路由、诊断与限制统一收在控制面里，方便审计和回溯。</p>
            </section>
            <section class="surface-card">
              <span class="card-kicker">Delivery</span>
              <h3>把交付、运维与更新变成一条链</h3>
              <p>从服务激活、客户端配置、远程安装，到节点更新与诊断结果回传，整条生命周期都可以在同一套平台里推进。</p>
            </section>';

        $secondaryCard = $adminCount === 0
            ? '<section class="surface-card">
                <span class="card-kicker">First-Time Setup</span>
                <h3>先创建首个管理账号</h3>
                <p>如果这是第一次部署，需要先从 CLI 创建管理员，然后再进入控制台。</p>
                <pre>php master/scripts/create_user.php --email admin@example.com --password ChangeMe123! --role admin --name "Platform Admin"</pre>
              </section>'
            : '<section class="surface-card">
                <span class="card-kicker">Platform Composition</span>
                <h3>从控制面到边缘节点的一体化结构</h3>
                <ul class="list">
                  <li>Admin Console 负责套餐、租户、节点、证书、链路和默认策略。</li>
                  <li>Tenant Console 负责服务、转发、客户端与诊断视图。</li>
                  <li>Client Agent 与 Slave Node 负责把控制面配置落到真实流量路径上。</li>
                </ul>
              </section>';

        $body = '
            <section class="landing-hero">
              <div class="hero-copy">
                <span class="eyebrow">' . $productName . '</span>
                <h1>解决 IXP 入网难与流量合规问题</h1>
                <p>' . $brandName . ' 面向需要稳定接入、清晰路由控制与合规交付的业务场景。利群會聚 LeiKwan Bridge 通过控制面、租户面板、客户端与边缘节点协同，把接入、交付、运维和流量治理收拢进一套平台。</p>
                <div class="hero-actions">' . $heroPrimary . $heroSecondary . '</div>
                <div class="status-row">
                  <span class="status-pill">IXP 接入抽象</span>
                  <span class="status-pill">流量合规治理</span>
                  <span class="status-pill">租户化交付</span>
                </div>
              </div>
              <section class="surface-card">
                <span class="panel-kicker">v3 Core</span>
                <h3>不是单页面板，而是一套可运营的交付结构。</h3>
                <div class="hero-list">
                  <div class="step-item">
                    <span class="step-index">1</span>
                    <div class="step-copy">
                      <strong>控制面与转发面分离</strong>
                      <span>平台负责策略、权限与配置，节点和客户端负责把真实流量跑起来。</span>
                    </div>
                  </div>
                  <div class="step-item">
                    <span class="step-index">2</span>
                    <div class="step-copy">
                      <strong>按租户与方案交付能力</strong>
                      <span>不同租户、套餐、客户端组和节点组可以拿到不同的默认路线与权限边界。</span>
                    </div>
                  </div>
                  <div class="step-item">
                    <span class="step-index">3</span>
                    <div class="step-copy">
                      <strong>把诊断和更新纳入日常运维</strong>
                      <span>不只负责开通，还负责后续的节点状态、客户端同步、故障排查与版本更新。</span>
                    </div>
                  </div>
                </div>
              </section>
            </section>
            <section id="overview" class="kpi-grid">' . $statCards . '</section>
            <section id="solution" class="feature-grid">' . $featureCards . '</section>
            <section class="portal-grid">
              ' . $entryCard . '
              ' . $secondaryCard . '
            </section>';

        return $this->publicPage('利群會聚 LeiKwan Bridge', $body, $user);
    }

    public function loginPage(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user !== null) {
            return Response::redirect('/');
        }

        $adminCount = (int) ($this->db->fetchOne('SELECT COUNT(*) AS total FROM users WHERE role = :role', ['role' => 'admin'])['total'] ?? 0);
        $csrf = $this->escape(SessionAuth::csrfToken());
        $sideCard = $adminCount === 0
            ? '<section class="surface-card auth-side">
                <span class="card-kicker">Setup</span>
                <h3>Admin setup is still pending</h3>
                <p>Finish the first admin bootstrap before opening the panel to regular users.</p>
                <pre>php master/scripts/create_user.php --email admin@example.com --password ChangeMe123! --role admin --name "Platform Admin"</pre>
              </section>'
            : '<section class="surface-card auth-side">
                <span class="card-kicker">Before You Sign In</span>
                <h3>Keep it simple</h3>
                <ul class="list">
                  <li>Use your panel email and password.</li>
                  <li>Buy station binding happens after email verification.</li>
                  <li>If needed, reset the password from the link below.</li>
                </ul>
                <div class="actions"><a class="button ghost" href="/forgot-password">Forgot Password</a></div>
              </section>';

        $body = '
            <section class="auth-grid">
              <section class="surface-card auth-card">
                <span class="card-kicker">Sign In</span>
                <h2>Welcome back</h2>
                <p class="muted">Use your panel account to continue.</p>
                <form method="post" action="/login">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <div class="form-grid single">
                    <div class="field">
                      <label>Email</label>
                      <input type="email" name="email" placeholder="you@example.com" required>
                    </div>
                    <div class="field">
                      <label>Password</label>
                      <input type="password" name="password" placeholder="Your password" required>
                    </div>
                  </div>
                  <div class="actions">
                    <button class="button" type="submit">Sign In</button>
                    <a class="button ghost" href="/register">Create Account</a>
                    <a class="button secondary" href="/">Back Home</a>
                  </div>
                </form>
              </section>
              ' . $sideCard . '
            </section>';

        return $this->publicPage($this->displayBrandName() . ' Sign In', $body);

        $adminCount = (int) ($this->db->fetchOne('SELECT COUNT(*) AS total FROM users WHERE role = :role', ['role' => 'admin'])['total'] ?? 0);
        $csrf = $this->escape(SessionAuth::csrfToken());

        $setupCard = $adminCount === 0
            ? '<section class="surface-card">
                <span class="card-kicker">Site Setup</span>
                <h3>The site is still being initialized</h3>
                <p>This panel is not ready for end users yet. If you are the operator, finish the initial setup first and then come back here.</p>
              </section>'
            : '<section class="surface-card">
                <span class="card-kicker">Quick Guide</span>
                <h3>Choose the next step that matches your account</h3>
                <ul class="list">
                  <li>Use the email and password form if you already have a panel account.</li>
                  <li>After email verification, the system will try to auto-bind the same email in Buy station.</li>
                  <li>If your Buy station uses another email, bind it later from Account &amp; Billing.</li>
                </ul>
              </section>';

        $bindingCard = '<section class="surface-card">
                <span class="card-kicker">Buy Station Binding</span>
                <h2>Bind after verifying your email</h2>
                <p class="muted">After your panel account finishes email verification, the system will automatically try to bind a Buy station account with the same email. If your Buy station uses another email, sign in first and then bind it from <strong>Account &amp; Billing</strong>.</p>
              </section>';

        $body = '
            <section class="auth-hero">
              <div>
                <span class="eyebrow">Account Access</span>
                <h1>Sign in to your workspace</h1>
                <p>Use your panel email and password to sign in. Buy station accounts are now linked after email verification, instead of logging in with a Buy station password here.</p>
              </div>
            </section>
            <section class="auth-grid">
              <section class="surface-card">
                <span class="card-kicker">Panel Login</span>
                <h2>Sign in with your panel password</h2>
                <p class="muted">Use this after creating your panel account and verifying your email address.</p>
                <form method="post" action="/login">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <div class="form-grid">
                    <div class="field">
                      <label>Email</label>
                      <input type="email" name="email" placeholder="you@example.com" required>
                    </div>
                    <div class="field">
                      <label>Password</label>
                      <input type="password" name="password" placeholder="Enter your panel password" required>
                    </div>
                  </div>
                  <div class="actions">
                    <button class="button" type="submit">Sign In</button>
                    <a class="button ghost" href="/register">Create Account</a>
                    <a class="button ghost" href="/forgot-password">Forgot Password</a>
                    <a class="button secondary" href="/">Back Home</a>
                  </div>
                </form>
              </section>
              <div style="display:flex;flex-direction:column;gap:18px;">
                ' . $bindingCard . '
                ' . $setupCard . '
              </div>
            </section>';

        return $this->publicPage($this->displayBrandName() . ' Sign In', $body);
    }

    public function knowledgebasePage(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user === null) {
            SessionAuth::flash('error', 'Please sign in to access the knowledgebase.');
            return Response::redirect('/login');
        }

        $service = new KnowledgebaseService($this->db);
        $threads = $service->listPublishedThreads();
        $selectedSlug = trim((string) $request->input('thread', ''));
        $selectedThread = $selectedSlug !== ''
            ? $service->findPublishedThreadBySlug($selectedSlug)
            : null;

        if ($selectedThread === null && $threads !== []) {
            $selectedThread = $threads[0];
            $selectedSlug = (string) ($selectedThread['slug'] ?? '');
        }

        $threadCards = '';
        foreach ($threads as $thread) {
            $isActive = (string) ($thread['slug'] ?? '') === $selectedSlug;
            $updatedAt = trim((string) ($thread['updated_at'] ?? ''));
            $threadCards .= '<a class="kb-thread-card' . ($isActive ? ' active' : '') . '" href="/knowledgebase?thread=' . urlencode((string) ($thread['slug'] ?? '')) . '">'
                . '<div class="kb-thread-meta">'
                . (((int) ($thread['is_pinned'] ?? 0) === 1) ? '<span class="kb-pill">Pinned</span>' : '')
                . ($updatedAt !== '' ? '<span class="kb-updated">Updated ' . $this->escape($updatedAt) . '</span>' : '')
                . '</div>'
                . '<strong>' . $this->escape((string) ($thread['title'] ?? 'Untitled')) . '</strong>'
                . '<p>' . $this->escape((string) ($thread['excerpt'] ?? '')) . '</p>'
                . '</a>';
        }

        $adminButton = (string) ($user['role'] ?? '') === 'admin'
            ? '<a class="button secondary" href="/admin/knowledgebase">Open admin editor</a>'
            : '';

        $articleHtml = $selectedThread !== null
            ? '<article class="kb-article">'
                . '<div class="kb-article-meta">'
                . (((int) ($selectedThread['is_pinned'] ?? 0) === 1) ? '<span class="kb-pill">Pinned</span>' : '')
                . '<span>Slug: ' . $this->escape((string) ($selectedThread['slug'] ?? '')) . '</span>'
                . '</div>'
                . '<h2>' . $this->escape((string) ($selectedThread['title'] ?? 'Knowledgebase')) . '</h2>'
                . '<div class="kb-article-body">' . (string) ($selectedThread['body_html'] ?? '') . '</div>'
                . '</article>'
            : '<section class="card"><h3>No published threads yet.</h3><p class="muted">Admins can create the first knowledgebase thread from the admin panel.</p></section>';

        $body = $this->knowledgebaseStyles() . '
            <section class="kb-shell">
              <section class="kb-hero">
                <div>
                  <span class="kb-eyebrow">Knowledgebase / Docs</span>
                  <h2>Threaded operational docs for admins and tenants</h2>
                  <p class="muted">Read the starter threads, open the exact topic you need, and keep future updates as normal panel-managed content instead of one giant static page.</p>
                </div>
                <div class="actions">
                  ' . $adminButton . '
                </div>
              </section>
              <section class="kb-layout">
                <aside class="kb-sidebar">
                  <h3>Latest Threads</h3>
                  <div class="kb-thread-list">' . ($threadCards !== '' ? $threadCards : '<div class="card"><p class="muted">No published threads yet.</p></div>') . '</div>
                </aside>
                <div class="kb-content">' . $articleHtml . '</div>
              </section>
            </section>';

        return $this->page('Knowledgebase', $body, $user, $this->knowledgebaseNav($user));
    }

    public function registerPage(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user !== null) {
            return Response::redirect('/');
        }

        $settings = $this->registrationService()->registrationSettings();
        $csrf = $this->escape(SessionAuth::csrfToken());
        $inviteField = $settings['require_invite_code']
            ? '<div class="field"><label>Invite Code</label><input type="text" name="invite_code" placeholder="LKH-XXXXXX" required></div>'
            : '';
        $policyText = $settings['require_invite_code']
            ? 'This site currently requires an invite code.'
            : 'Open registration is enabled, but email verification is still required.';

        $body = '
            <section class="auth-grid">
              <section class="surface-card auth-card">
                <span class="card-kicker">Create Account</span>
                <h2>Start with one account</h2>
                <p class="muted">Create the panel account first. Verification happens by email.</p>
                <form method="post" action="/register">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <div class="form-grid">
                    <div class="field">
                      <label>Display Name</label>
                      <input type="text" name="name" placeholder="Your name" required>
                    </div>
                    <div class="field">
                      <label>Email</label>
                      <input type="email" name="email" placeholder="you@example.com" required>
                    </div>
                    <div class="field">
                      <label>Password</label>
                      <input type="password" name="password" placeholder="At least 8 characters" required>
                    </div>
                    <div class="field">
                      <label>Confirm Password</label>
                      <input type="password" name="password_confirm" placeholder="Repeat the password" required>
                    </div>
                    ' . $inviteField . '
                  </div>
                  <div class="actions">
                    <button class="button" type="submit">Create Account</button>
                    <a class="button ghost" href="/login">Sign In</a>
                    <a class="button secondary" href="/">Back Home</a>
                  </div>
                </form>
              </section>
              <section class="surface-card auth-side">
                <span class="card-kicker">Next</span>
                <h3>After registration</h3>
                <ul class="list">
                  <li>Open the verification email.</li>
                  <li>Sign in with the same panel account.</li>
                  <li>Bind billing later from Account if needed.</li>
                </ul>
                <p class="muted">' . $this->escape($policyText) . '</p>
              </section>
            </section>';

        return $this->publicPage($this->displayBrandName() . ' Account Registration', $body);

        $settings = $this->registrationService()->registrationSettings();
        $csrf = $this->escape(SessionAuth::csrfToken());
        $inviteField = $settings['require_invite_code']
            ? '<div class="field"><label>Invite Code</label><input type="text" name="invite_code" placeholder="LKH-XXXXXX" required></div>'
            : '';

        $body = '
            <section class="auth-hero">
              <div>
                <span class="eyebrow">Create Account</span>
                <h1>Create your workspace account</h1>
                <p>Register with your email, verify it once, and then sign in to manage your services, client lines and balances.</p>
              </div>
            </section>
            <section class="auth-grid">
              <section class="surface-card">
                <span class="card-kicker">Account Signup</span>
                <h2>Create a new panel account</h2>
                <p class="muted">We will send a verification email before the account becomes active.</p>
                <form method="post" action="/register">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <div class="form-grid">
                    <div class="field">
                      <label>Display Name</label>
                      <input type="text" name="name" placeholder="How should we address you?" required>
                    </div>
                    <div class="field">
                      <label>Email</label>
                      <input type="email" name="email" placeholder="you@example.com" required>
                    </div>
                    <div class="field">
                      <label>Password</label>
                      <input type="password" name="password" placeholder="Use at least 8 characters" required>
                    </div>
                    <div class="field">
                      <label>Confirm Password</label>
                      <input type="password" name="password_confirm" placeholder="Enter the same password again" required>
                    </div>
                    ' . $inviteField . '
                  </div>
                  <div class="actions">
                    <button class="button" type="submit">Create Account</button>
                    <a class="button secondary" href="/login">Back to Login</a>
                  </div>
                </form>
              </section>
              <div style="display:flex;flex-direction:column;gap:18px;">
                <section class="surface-card">
                  <span class="card-kicker">What Happens Next</span>
                  <ul class="list">
                    <li>Submit the form and wait for the verification email.</li>
                    <li>Open the email and click the verification link.</li>
                    <li>After verification, the system will try to bind the same email in Buy station automatically.</li>
                    <li>Then sign in from the login page and continue to your workspace.</li>
                  </ul>
                </section>
                <section class="surface-card">
                  <span class="card-kicker">Registration Policy</span>
                  <p>' . ($settings['require_invite_code']
                    ? 'This site currently needs an invite code before a new account can be created.'
                    : 'This site currently allows open registration, but email verification is still required.') . '</p>
                </section>
              </div>
            </section>';

        return $this->publicPage($this->displayBrandName() . ' Account Registration', $body);
    }

    public function register(Request $request, array $params): Response
    {
        try {
            SessionAuth::validateCsrf($request);
            $this->registrationService()->registerTenant([
                'name' => $request->input('name'),
                'email' => $request->input('email'),
                'password' => $request->input('password'),
                'password_confirm' => $request->input('password_confirm'),
                'invite_code' => $request->input('invite_code'),
            ]);
            SessionAuth::flash('success', 'Your account request has been submitted. Please check your email and finish verification before signing in.');
            return Response::redirect('/login');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Registration failed: ' . $this->displayThrowableMessage($throwable));
            return Response::redirect('/register');
        }
    }

    public function verifyEmail(Request $request, array $params): Response
    {
        try {
            $user = $this->registrationService()->verifyEmailToken((string) $request->input('token', ''));
            $message = 'Email verification completed. You can sign in now.';
            if ($this->whmcsIdentityService()->isConfigured()) {
                try {
                    $boundUser = $this->whmcsIdentityService()->tryDirectBindForUser((int) ($user['id'] ?? 0));
                    if ($boundUser !== null) {
                        $message = 'Email verification completed, and your Buy station account with the same email has been bound automatically. You can sign in now.';
                    } else {
                        $message = 'Email verification completed. No Buy station account with the same email was found. You can sign in now and bind another Buy station email from Account & Billing later.';
                    }
                } catch (\Throwable $bindingThrowable) {
                    $message = 'Email verification completed. Automatic Buy station binding could not be completed right now: '
                        . $bindingThrowable->getMessage()
                        . '. You can still sign in and bind it later from Account & Billing.';
                }
            }
            SessionAuth::flash('success', $message);
            return Response::redirect('/login');
        } catch (\Throwable $throwable) {
            $body = '
                <section class="surface-card">
                  <span class="card-kicker">Verification Failed</span>
                  <h2>Unable to verify email</h2>
                  <p class="muted">' . $this->escape($this->displayThrowableMessage($throwable)) . '</p>
                  <div class="actions">
                    <a class="button" href="/register">Back to Register</a>
                    <a class="button secondary" href="/login">Back to Login</a>
                  </div>
                </section>';

            return $this->publicPage($this->displayBrandName() . ' Email Verification', $body);
        }
    }

    public function forgotPasswordPage(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user !== null) {
            return Response::redirect('/');
        }

        $csrf = $this->escape(SessionAuth::csrfToken());
        $body = '
            <section class="auth-hero">
              <div>
                <span class="eyebrow">Password Reset</span>
                <h1>Reset your panel password</h1>
                <p>Enter the email address of your panel account. If the account exists, we will send a password reset link to your mailbox.</p>
              </div>
            </section>
            <section class="auth-grid">
              <section class="surface-card">
                <span class="card-kicker">Reset Link</span>
                <h2>Send me a reset email</h2>
                <form method="post" action="/forgot-password">
                  <input type="hidden" name="csrf_token" value="' . $csrf . '">
                  <div class="form-grid">
                    <div class="field">
                      <label>Email</label>
                      <input type="email" name="email" placeholder="you@example.com" required>
                    </div>
                  </div>
                  <div class="actions">
                    <button class="button" type="submit">Send Reset Link</button>
                    <a class="button secondary" href="/login">Back to Login</a>
                  </div>
                </form>
              </section>
              <div style="display:flex;flex-direction:column;gap:18px;">
                <section class="surface-card">
                  <span class="card-kicker">Tips</span>
                  <ul class="list">
                    <li>The reset link is only sent to active panel accounts.</li>
                    <li>If your Buy station uses another email, bind it later from Account &amp; Billing after you sign in.</li>
                    <li>If you do not receive the email, check your spam folder first.</li>
                  </ul>
                </section>
              </div>
            </section>';

        return $this->publicPage($this->displayBrandName() . ' Password Reset', $body);
    }

    public function requestPasswordReset(Request $request, array $params): Response
    {
        try {
            SessionAuth::validateCsrf($request);
            $this->passwordResetService()->requestReset((string) $request->input('email', ''));
            SessionAuth::flash('success', 'If that email belongs to an active account, a password reset link has been sent.');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Unable to send the reset email: ' . $this->displayThrowableMessage($throwable));
        }

        return Response::redirect('/forgot-password');
    }

    public function resetPasswordPage(Request $request, array $params): Response
    {
        $token = (string) $request->input('token', '');
        $csrf = $this->escape(SessionAuth::csrfToken());

        try {
            $details = $this->passwordResetService()->tokenDetails($token);
            $name = $this->escape((string) ($details['user']['name'] ?? 'User'));
            $body = '
                <section class="auth-hero">
                  <div>
                    <span class="eyebrow">Choose A New Password</span>
                    <h1>Reset password for ' . $name . '</h1>
                    <p>Enter your new password below. This link can only be used once.</p>
                  </div>
                </section>
                <section class="auth-grid">
                  <section class="surface-card">
                    <span class="card-kicker">New Password</span>
                    <h2>Set a new password</h2>
                    <form method="post" action="/reset-password">
                      <input type="hidden" name="csrf_token" value="' . $csrf . '">
                      <input type="hidden" name="token" value="' . $this->escape($token) . '">
                      <div class="form-grid">
                        <div class="field">
                          <label>New Password</label>
                          <input type="password" name="password" placeholder="Use at least 8 characters" required>
                        </div>
                        <div class="field">
                          <label>Confirm New Password</label>
                          <input type="password" name="password_confirm" placeholder="Enter the same password again" required>
                        </div>
                      </div>
                      <div class="actions">
                        <button class="button" type="submit">Save New Password</button>
                        <a class="button secondary" href="/login">Back to Login</a>
                      </div>
                    </form>
                  </section>
                </section>';

            return $this->publicPage($this->displayBrandName() . ' Reset Password', $body);
        } catch (\Throwable $throwable) {
            $body = '
                <section class="surface-card">
                  <span class="card-kicker">Reset Link Unavailable</span>
                  <h2>We cannot use this reset link</h2>
                  <p class="muted">' . $this->escape($this->displayThrowableMessage($throwable)) . '</p>
                  <div class="actions">
                    <a class="button" href="/forgot-password">Request A New Link</a>
                    <a class="button secondary" href="/login">Back to Login</a>
                  </div>
                </section>';

            return $this->publicPage($this->displayBrandName() . ' Reset Password', $body);
        }
    }

    public function resetPassword(Request $request, array $params): Response
    {
        try {
            SessionAuth::validateCsrf($request);
            $this->passwordResetService()->resetPassword(
                (string) $request->input('token', ''),
                (string) $request->input('password', ''),
                (string) $request->input('password_confirm', '')
            );
            SessionAuth::flash('success', 'Your password has been updated. You can sign in now.');
            return Response::redirect('/login');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Unable to reset password: ' . $this->displayThrowableMessage($throwable));
            return Response::redirect('/reset-password?token=' . urlencode((string) $request->input('token', '')));
        }
    }

    public function login(Request $request, array $params): Response
    {
        SessionAuth::validateCsrf($request);

        $email = strtolower(trim((string) $request->input('email', '')));
        $password = (string) $request->input('password', '');
        $user = $this->db->fetchOne('SELECT * FROM users WHERE email = :email LIMIT 1', ['email' => $email]);

        if ($user !== null && (string) ($user['status'] ?? '') === 'pending_verification') {
            SessionAuth::flash('error', 'This account is waiting for email verification. Please open the verification email first.');
            return Response::redirect('/login');
        }

        if (
            $user === null ||
            (string) $user['status'] !== 'active' ||
            !is_string($user['password_hash']) ||
            $user['password_hash'] === '' ||
            !password_verify($password, (string) $user['password_hash'])
        ) {
            SessionAuth::flash('error', 'Email or password is incorrect, or this account has not set a local password yet.');
            return Response::redirect('/login');
        }

        SessionAuth::login($user);
        SessionAuth::flash('success', 'Signed in successfully.');
        return Response::redirect('/');
    }

    public function requestBuyBinding(Request $request, array $params): Response
    {
        $currentUser = SessionAuth::currentUser($this->db);
        if ($currentUser === null || (string) ($currentUser['role'] ?? '') !== 'tenant') {
            return Response::redirect('/login');
        }

        try {
            SessionAuth::validateCsrf($request);
            $service = $this->whmcsIdentityService();
            if (!$service->isConfigured()) {
                throw new \RuntimeException('Buy station binding is not configured on this panel.');
            }

            $result = $service->requestBinding(
                (int) ($currentUser['id'] ?? 0),
                (string) $request->input('whmcs_email', '')
            );

            if ((string) ($result['mode'] ?? '') === 'direct') {
                SessionAuth::flash('success', 'Buy station account bound successfully. The email matched directly, so no ticket verification was needed.');
                return Response::redirect('/tenant/account');
            }

            $ticketLabel = $result['ticket_tid'] !== '' ? '#' . $result['ticket_tid'] : '#' . (int) $result['ticket_id'];
            SessionAuth::flash('success', 'Verification ticket ' . $ticketLabel . ' has been created. Open it in your Buy station account, copy the code, and finish binding.');
            return Response::redirect('/buy-binding/verify?challenge=' . urlencode((string) $result['public_token']));
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Buy station binding failed: ' . $this->displayThrowableMessage($throwable));
            return Response::redirect('/tenant/account');
        }
    }

    public function buyBindingVerifyPage(Request $request, array $params): Response
    {
        $challengeToken = (string) $request->input('challenge', '');
        $viewer = SessionAuth::currentUser($this->db);
        $csrf = $this->escape(SessionAuth::csrfToken());

        try {
            $details = $this->whmcsIdentityService()->challengeDetails($challengeToken);
            $challenge = is_array($details['challenge'] ?? null) ? $details['challenge'] : [];
            $user = is_array($details['user'] ?? null) ? $details['user'] : [];
            $ticketLabel = trim((string) ($challenge['ticket_tid'] ?? '')) !== ''
                ? '#' . $this->escape((string) $challenge['ticket_tid'])
                : '#' . (int) ($challenge['ticket_id'] ?? 0);

            $body = '
                <section class="auth-hero">
                  <div>
                    <span class="eyebrow">Buy Station Binding</span>
                    <h1>Finish linking your Buy station account</h1>
                    <p>We created a verification ticket for ' . $this->escape((string) ($challenge['whmcs_email'] ?? '')) . '. Open that ticket, copy the code, and submit it here.</p>
                  </div>
                </section>
                <section class="auth-grid">
                  <section class="surface-card">
                    <span class="card-kicker">Verification Code</span>
                    <h2>Enter the code from the ticket</h2>
                    <p class="muted">Ticket ' . $ticketLabel . ' expires at ' . $this->escape((string) ($challenge['expires_at'] ?? '')) . ' UTC.</p>
                    <form method="post" action="/buy-binding/verify">
                      <input type="hidden" name="csrf_token" value="' . $csrf . '">
                      <input type="hidden" name="challenge" value="' . $this->escape($challengeToken) . '">
                      <div class="form-grid">
                        <div class="field">
                          <label>Verification Code</label>
                          <input type="text" name="code" placeholder="6-digit code" required>
                        </div>
                      </div>
                      <div class="actions">
                        <button class="button" type="submit">Verify And Bind</button>
                        <a class="button secondary" href="' . ($viewer !== null ? '/tenant/account' : '/login') . '">Back</a>
                      </div>
                    </form>
                  </section>
                  <div style="display:flex;flex-direction:column;gap:18px;">
                    <section class="surface-card">
                      <span class="card-kicker">Destination Account</span>
                      <h3>' . $this->escape((string) ($user['name'] ?? 'Panel User')) . '</h3>
                      <p class="muted">After verification, this panel account will be linked to your Buy station identity and the ticket will be closed automatically.</p>
                    </section>
                  </div>
                </section>';

            return $this->publicPage($this->displayBrandName() . ' Buy Station Binding', $body, $viewer);
        } catch (\Throwable $throwable) {
            $body = '
                <section class="surface-card">
                  <span class="card-kicker">Verification Unavailable</span>
                  <h2>We cannot continue this Buy station binding</h2>
                  <p class="muted">' . $this->escape($this->displayThrowableMessage($throwable)) . '</p>
                  <div class="actions">
                    <a class="button" href="' . ($viewer !== null ? '/tenant/account' : '/login') . '">Back</a>
                  </div>
                </section>';

            return $this->publicPage($this->displayBrandName() . ' Buy Station Binding', $body, $viewer);
        }
    }

    public function verifyBuyBinding(Request $request, array $params): Response
    {
        try {
            SessionAuth::validateCsrf($request);
            $user = $this->whmcsIdentityService()->verifyLoginChallenge(
                (string) $request->input('challenge', ''),
                (string) $request->input('code', '')
            );
            SessionAuth::login($user);
            SessionAuth::flash('success', 'Your Buy station account has been linked successfully.');
            return Response::redirect('/tenant/account');
        } catch (\Throwable $throwable) {
            SessionAuth::flash('error', 'Buy station binding verification failed: ' . $this->displayThrowableMessage($throwable));
            return Response::redirect('/buy-binding/verify?challenge=' . urlencode((string) $request->input('challenge', '')));
        }
    }

    public function logout(Request $request, array $params): Response
    {
        SessionAuth::validateCsrf($request);
        SessionAuth::logout();
        SessionAuth::flash('success', 'Signed out successfully.');
        return Response::redirect('/');
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    private function knowledgebaseNav(array $user): array
    {
        if ((string) ($user['role'] ?? '') === 'admin') {
            return $this->adminNavigationItems('knowledgebase');
        }

        return [
            ['href' => '/tenant', 'label' => 'Intro'],
            ['href' => '/tenant/account', 'label' => 'Account'],
            ['href' => '/tenant/plans', 'label' => 'Plans'],
            ['href' => '/tenant/forwards', 'label' => 'Forwards'],
            ['href' => '/tenant/clients', 'label' => 'Clients'],
            ['href' => '/tenant/sidecars', 'label' => 'Sidecars'],
            ['href' => '/tenant/slave-groups', 'label' => 'Slave Groups'],
            ['href' => '/knowledgebase', 'label' => 'Knowledgebase / Docs', 'active' => true],
        ];
    }

    private function knowledgebaseStyles(): string
    {
        return <<<'HTML'
<style>
  .kb-shell {
    display: flex;
    flex-direction: column;
    gap: 20px;
  }
  .kb-hero,
  .kb-article,
  .kb-sidebar,
  .kb-thread-card {
    border-radius: 24px;
    border: 1px solid rgba(229,231,235,0.94);
    background: rgba(255,255,255,0.96);
    box-shadow: var(--shadow);
  }
  .kb-hero {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    padding: 28px;
  }
  .kb-eyebrow,
  .kb-pill {
    display: inline-flex;
    align-items: center;
    padding: 7px 11px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
  }
  .kb-eyebrow {
    background: #111827;
    color: #fff;
  }
  .kb-pill {
    background: #eef2ff;
    color: #3730a3;
  }
  .kb-layout {
    display: grid;
    grid-template-columns: minmax(280px, 340px) minmax(0, 1fr);
    gap: 20px;
    align-items: start;
  }
  .kb-sidebar {
    padding: 22px;
  }
  .kb-thread-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-top: 14px;
  }
  .kb-thread-card {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 16px;
    color: inherit;
    text-decoration: none;
  }
  .kb-thread-card.active {
    border-color: #111827;
    transform: translateY(-1px);
  }
  .kb-thread-card p {
    margin: 0;
    color: var(--muted);
    line-height: 1.6;
  }
  .kb-thread-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    justify-content: space-between;
  }
  .kb-updated {
    color: var(--muted);
    font-size: 12px;
  }
  .kb-content {
    display: flex;
    flex-direction: column;
    gap: 20px;
  }
  .kb-article {
    padding: 28px;
  }
  .kb-article h2,
  .kb-sidebar h3 {
    margin: 0;
    font-family: "Iowan Old Style", "Palatino Linotype", "Book Antiqua", Georgia, serif;
  }
  .kb-article-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
    margin-bottom: 14px;
    color: var(--muted);
    font-size: 12px;
  }
  .kb-article-body {
    display: grid;
    gap: 14px;
    line-height: 1.72;
  }
  .kb-article-body h2,
  .kb-article-body h3,
  .kb-article-body p,
  .kb-article-body ul,
  .kb-article-body ol {
    margin: 0;
  }
  @media (max-width: 980px) {
    .kb-layout {
      grid-template-columns: 1fr;
    }
  }
</style>
HTML;
    }

    private function registrationService(): TenantRegistrationService
    {
        return new TenantRegistrationService($this->config, $this->db);
    }

    private function passwordResetService(): PasswordResetService
    {
        return new PasswordResetService($this->config, $this->db);
    }

    private function whmcsIdentityService(): WhmcsIdentityService
    {
        return new WhmcsIdentityService($this->config, $this->db);
    }
}
