<?php
declare(strict_types=1);

namespace Leikwan\Master\Controllers;

use Leikwan\Master\Config;
use Leikwan\Master\Database\Database;
use Leikwan\Master\Http\Request;
use Leikwan\Master\Http\Response;
use Leikwan\Master\Support\KnowledgebaseHtmlSanitizer;
use Leikwan\Master\Support\KnowledgebaseService;
use Leikwan\Master\Support\LocaleResolver;
use Leikwan\Master\Support\PasswordResetService;
use Leikwan\Master\Support\SessionAuth;
use Leikwan\Master\Support\OidcClient;
use Leikwan\Master\Support\TenantRegistrationService;
use Leikwan\Master\Support\WhmcsIdentityService;

final class AuthController extends BaseController
{
    private const START_HERE_TOPIC_KEYS = [
        'getting-started-five-minute-overview',
        'install-client',
        'getting-started-first-forward-checklist',
        'connect-and-verify',
    ];

    private ?OidcClient $oidcClient;

    public function __construct(Config $config, Database $db, ?OidcClient $oidcClient = null)
    {
        parent::__construct($config, $db);
        $this->oidcClient = $oidcClient;
    }

    public function home(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        $routeCatalog = [
            ['index' => '01', 'label' => '沪日', 'destination' => 'TOKYO', 'note' => '东亚 · 东京'],
            ['index' => '02', 'label' => '沪韩', 'destination' => 'SEOUL', 'note' => '东亚 · 首尔'],
            ['index' => '03', 'label' => '沪德', 'destination' => 'FRANKFURT', 'note' => '欧洲 · 法兰克福'],
            ['index' => '04', 'label' => '沪俄', 'destination' => 'MOSCOW', 'note' => '欧亚 · 莫斯科'],
            ['index' => '05', 'label' => '沪港', 'destination' => 'HONG KONG', 'note' => '大湾区 · 香港'],
        ];
        $routeCards = '';
        foreach ($routeCatalog as $route) {
            $routeCards .= '<div class="bridge-home-route-card">'
                . '<span class="bridge-home-route-index">' . $this->escape($route['index']) . '</span>'
                . '<strong>' . $this->escape($route['label']) . '</strong>'
                . '<span class="bridge-home-route-destination">SHANGHAI &rarr; ' . $this->escape($route['destination']) . '</span>'
                . '<small>' . $this->escape($route['note']) . '</small>'
                . '</div>';
        }

        $consoleHref = $user === null
            ? '/login'
            : $this->escape($this->dashboardPathForUser($user));

        $body = '
            <section class="bridge-home-hero" aria-labelledby="bridge-home-title">
              <div class="bridge-home-hero-copy">
                <div class="bridge-home-kicker"><span>BRIDGE CONTROL</span><span>控制平面 / 01</span></div>
                <h1 id="bridge-home-title">利群會聚 <span>Bridge Control</span></h1>
                <p class="bridge-home-lede">分布式传输，清晰可控。<span>让每一条可用路径，都能被看见、管理和验证。</span></p>
                <div class="bridge-home-actions">
                  <a class="button bridge-home-primary" href="' . $consoleHref . '">进入控制台 <span aria-hidden="true">&rarr;</span></a>
                  <a class="bridge-home-link" href="#network">查看网络路径 <span aria-hidden="true">&darr;</span></a>
                </div>
                <div class="bridge-home-path-note" aria-label="服务路径">
                  <span>CLIENT</span><i aria-hidden="true">/</i><span>SLAVE</span><i aria-hidden="true">/</i><span>SIDECAR</span><i aria-hidden="true">/</i><span>TARGET</span>
                </div>
              </div>
              <aside class="bridge-home-status" aria-label="固定路线目录">
                <div class="bridge-home-status-head"><span>ROUTE CATALOG</span><span class="bridge-home-status-mark" aria-hidden="true"></span></div>
                <strong>上海出发</strong>
                <p>沪日、沪韩、沪德、沪俄、沪港</p>
                <div class="bridge-home-status-rule"></div>
                <div class="bridge-home-status-meta"><span>ROUTE SET</span><span>CURATED</span></div>
              </aside>
            </section>
            <section class="bridge-home-route-catalog" aria-labelledby="route-catalog-title">
              <header class="bridge-home-catalog-heading">
                <span>01 / ROUTE CATALOG</span>
                <h2 id="route-catalog-title">从上海出发，连接五个方向。</h2>
                <p>按目的地整理基础线路，让每个方向都清晰、易读、可进入控制台继续配置。</p>
              </header>
              <div class="bridge-home-route-grid" aria-label="沪日、沪韩、沪德、沪俄、沪港固定线路目录">' . $routeCards . '</div>
            </section>
            <section id="network" class="bridge-home-network" aria-labelledby="network-title">
              <header class="bridge-home-section-heading">
                <span>02 / 传输路径</span>
                <h2 id="network-title">一条服务，多条路径。</h2>
                <p>客户端在本地选择方向，控制平面负责路径编排、聚合端点和目标服务的可见性。</p>
              </header>
              <div class="bridge-home-route" role="img" aria-label="客户端、Slave 节点、Sidecar、目标服务的传输路径">
                <div class="bridge-home-route-node"><span class="bridge-home-route-index">01</span><span><strong>客户端</strong><small>本地选择路径</small></span></div>
                <div class="bridge-home-route-node"><span class="bridge-home-route-index">02</span><span><strong>Slave 节点</strong><small>分布式接入</small></span></div>
                <div class="bridge-home-route-node"><span class="bridge-home-route-index">03</span><span><strong>Sidecar</strong><small>聚合与转发</small></span></div>
                <div class="bridge-home-route-node"><span class="bridge-home-route-index">04</span><span><strong>目标服务</strong><small>最终目的地</small></span></div>
              </div>
            </section>
            <section class="bridge-home-footer-grid">
              <div class="bridge-home-footer-copy">
                <span>03 / 运行原则</span>
                <h2>每条路径都应该能被解释。</h2>
                <p>节点状态、端点配置和服务拓扑集中在一个控制平面内，方便排查、调整和复核。</p>
              </div>
              <div class="bridge-home-footer-links">
                <a href="' . $consoleHref . '"><span>进入控制台</span><span aria-hidden="true">&rarr;</span></a>
                <a href="/knowledgebase"><span>打开知识库</span><span aria-hidden="true">&rarr;</span></a>
              </div>
            </section>';

        return $this->publicPage($this->displayBrandName(), $body, $user, 'bridge-home');
    }

    public function loginPage(Request $request, array $params): Response
    {
        $user = SessionAuth::currentUser($this->db);
        if ($user !== null) {
            return Response::redirect('/');
        }

        $adminCount = (int) ($this->db->fetchOne('SELECT COUNT(*) AS total FROM users WHERE role = :role', ['role' => 'admin'])['total'] ?? 0);
        $csrf = $this->escape(SessionAuth::csrfToken());
        $showLegacyLogin = (string) $request->input('legacy', '') === '1';
        $buyLoginMethod = '
            <span class="login-method-pill login-method-pill--disabled" aria-disabled="true">
              <span class="login-method-copy">
                <strong>使用LeiKwan Buy登录 (Via LeiKwan Buy)</strong>
              </span>
            </span>';
        if ($this->oidcClient !== null && $this->oidcClient->isConfigured()) {
            $providerName = $this->escape($this->oidcClient->providerName());
            $buyLoginMethod = '
                <a class="login-method-pill login-method-pill--buy" href="/auth/oidc/start" title="Via ' . $providerName . '">
                  <span class="login-method-copy">
                    <strong>使用LeiKwan Buy登录 (Via LeiKwan Buy)</strong>
                  </span>
                  <span class="login-method-arrow" aria-hidden="true">&rarr;</span>
                </a>';
        }
        $loginMethods = '
            <div class="login-methods">
              ' . $buyLoginMethod . '
              <a class="login-method-pill login-method-pill--legacy" href="/login?legacy=1">
                <span class="login-method-copy">
                  <strong>使用会聚账户(旧) (Via LeiKwan Bridge (Legacy))</strong>
                </span>
                <span class="login-method-arrow" aria-hidden="true">&rarr;</span>
              </a>
            </div>';
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

        if ($showLegacyLogin) {
            $body = '
                <section class="auth-grid">
                  <section class="surface-card auth-card">
                    <span class="card-kicker">旧账户登录</span>
                    <h2>使用会聚账户(旧)</h2>
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
                        <a class="button ghost" href="/login">返回登录方式</a>
                        <a class="button secondary" href="/">Back Home</a>
                      </div>
                    </form>
                  </section>
                  ' . $sideCard . '
                </section>';
        } else {
            $body = '
                <section class="auth-grid login-method-grid">
                  <section class="surface-card auth-card login-method-card">
                    <span class="card-kicker">Sign In</span>
                    <h2>选择登录方式</h2>
                    ' . $loginMethods . '
                    <div class="actions login-method-actions">
                      <a class="button secondary" href="/">Back Home</a>
                    </div>
                  </section>
                </section>';
        }

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
        $locale = LocaleResolver::resolve($request);
        $topics = $service->listPublishedTopics($locale);
        $selectedTopicKey = trim((string) $request->input('thread', ''));
        $selectedTopic = $selectedTopicKey !== ''
            ? $service->findPublishedTopicByKey($selectedTopicKey, $locale)
            : null;

        if ($selectedTopic === null && $topics !== []) {
            $selectedTopic = $topics[0];
            $selectedTopicKey = (string) ($selectedTopic['topic_key'] ?? '');
        }

        $startHereCards = '';
        $learnAsNeededCards = '';
        foreach ($topics as $topic) {
            $topicKey = (string) ($topic['topic_key'] ?? '');
            $isActive = $topicKey === $selectedTopicKey;
            $updatedAt = trim((string) ($topic['updated_at'] ?? ''));
            $topicCard = '<a class="kb-thread-card' . ($isActive ? ' active' : '') . '" href="/knowledgebase?thread=' . urlencode($topicKey) . '">'
                . '<div class="kb-thread-meta">'
                . (((int) ($topic['is_pinned'] ?? 0) === 1) ? '<span class="kb-pill">Pinned</span>' : '')
                . ($updatedAt !== '' ? '<span class="kb-updated"><span>Updated</span> <time>' . $this->escape($updatedAt) . '</time></span>' : '')
                . '</div>'
                . '<strong>' . $this->escape((string) ($topic['title'] ?? 'Untitled guide')) . '</strong>'
                . '<p>' . $this->escape((string) ($topic['excerpt'] ?? '')) . '</p>'
                . '</a>';
            if (in_array($topicKey, self::START_HERE_TOPIC_KEYS, true)) {
                $startHereCards .= $topicCard;
            } else {
                $learnAsNeededCards .= $topicCard;
            }
        }

        $topicGroups = '';
        $startHereLabel = 'Start here';
        $learnAsNeededLabel = 'Learn as needed';
        if ($startHereCards !== '') {
            $topicGroups .= '<section class="kb-topic-group"><h3>' . $startHereLabel . '</h3><div class="kb-thread-list">' . $startHereCards . '</div></section>';
        }
        if ($learnAsNeededCards !== '') {
            $topicGroups .= '<section class="kb-topic-group"><h3>' . $learnAsNeededLabel . '</h3><div class="kb-thread-list">' . $learnAsNeededCards . '</div></section>';
        }
        if ($topicGroups === '') {
            $topicGroups = '<div class="card"><p class="muted">No published guides yet.</p></div>';
        }

        $adminButton = (string) ($user['role'] ?? '') === 'admin'
            ? '<a class="button secondary" href="/admin/knowledgebase">Open guide editor</a>'
            : '';

        $articleHtml = '<section class="card"><h3>No published guides yet.</h3><p class="muted">An administrator can publish the first user guide.</p></section>';
        $mermaidAssets = '';
        if ($selectedTopic !== null) {
            $sanitizer = new KnowledgebaseHtmlSanitizer();
            $articleBody = $sanitizer->sanitize((string) ($selectedTopic['body_html'] ?? ''));
            $hasMermaid = str_contains($articleBody, '<pre class="mermaid">');
            if ($hasMermaid) {
                $diagramErrorLabel = $this->escape('Diagram could not be rendered. The source is shown below.');
                $articleBody = str_replace(
                    '<pre class="mermaid">',
                    '<pre class="mermaid" data-error-label="' . $diagramErrorLabel . '">',
                    $articleBody
                );
                $mermaidAssets = '<script src="/assets/vendor/mermaid/mermaid.min.js?v=11.12.0" defer></script>'
                    . '<script src="/assets/knowledgebase-mermaid.js?v=20260729" defer></script>';
            }
            $usedFallback = (bool) ($selectedTopic['used_fallback'] ?? false);
            $fallbackNotice = $usedFallback
                ? '<p class="kb-fallback-notice">This topic is not available in your selected language. Showing English.</p>'
                : '';
            $articleHtml = '<article class="kb-article">'
                . '<div class="kb-article-meta">'
                . (((int) ($selectedTopic['is_pinned'] ?? 0) === 1) ? '<span class="kb-pill">Pinned</span>' : '')
                . '<span><span>Updated</span> <time>' . $this->escape((string) ($selectedTopic['updated_at'] ?? '')) . '</time></span>'
                . '</div>'
                . $fallbackNotice
                . '<h2>' . $this->escape((string) ($selectedTopic['title'] ?? 'User Guide')) . '</h2>'
                . '<div class="kb-article-body">' . $articleBody . '</div>'
                . '</article>';
        }

        $body = $this->knowledgebaseStyles() . '
            <section class="kb-shell">
              <section class="kb-hero">
                <div>
                  <span class="kb-eyebrow">User Guides</span>
                  <h2>Practical guides for setting up and using your service</h2>
                  <p class="muted">Start with the essentials, then open another topic only when you need it.</p>
                </div>
                <div class="actions">
                  ' . $adminButton . '
                </div>
              </section>
              <section class="kb-layout">
                <aside class="kb-sidebar">' . $topicGroups . '</aside>
                <div class="kb-content">' . $articleHtml . '</div>
              </section>
            </section>' . $mermaidAssets;

        return $this->page('User Guides', $body, $user, $this->knowledgebaseNav($user));
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
            return Response::redirect('/login?legacy=1');
        }

        if (
            $user === null ||
            (string) $user['status'] !== 'active' ||
            !is_string($user['password_hash']) ||
            $user['password_hash'] === '' ||
            !password_verify($password, (string) $user['password_hash'])
        ) {
            SessionAuth::flash('error', 'Email or password is incorrect, or this account has not set a local password yet.');
            return Response::redirect('/login?legacy=1');
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
            ['group' => 'Overview', 'href' => '/tenant', 'label' => 'Overview'],
            ['group' => 'Services', 'href' => '/tenant/forwards', 'label' => 'Forwards'],
            ['group' => 'Services', 'href' => '/tenant/plans', 'label' => 'Packages'],
            ['group' => 'Clients', 'href' => '/tenant/clients', 'label' => 'Clients'],
            ['group' => 'Account', 'href' => '/tenant/account', 'label' => 'Account'],
            ['group' => 'Support', 'href' => '/knowledgebase', 'label' => 'Knowledgebase / Docs', 'active' => true],
            ['group' => 'Advanced', 'href' => '/tenant-transport-endpoints', 'label' => 'Target Endpoints'],
            ['group' => 'Advanced', 'href' => '/tenant/slave-groups', 'label' => 'Node Groups'],
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
  .kb-topic-group + .kb-topic-group {
    margin-top: 24px;
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
  .kb-mermaid-figure { margin: 8px 0; max-width: 100%; overflow: hidden; }
  .kb-mermaid-figure svg { display: block; width: 100%; max-width: 100%; height: auto; }
  .kb-mermaid-error { color: var(--danger, #b42318); font-weight: 700; }
  .kb-mermaid-failed { max-width: 100%; overflow-x: auto; white-space: pre; }
  .kb-fallback-notice { border-left: 4px solid #b7791f; padding: 12px 14px; background: #fff8e6; }
  @media (max-width: 980px) {
    .kb-layout {
      grid-template-columns: 1fr;
    }
  }
  @media (max-width: 520px) {
    .kb-article { padding: 20px 16px; }
    .kb-sidebar { padding: 16px; }
    .kb-mermaid-figure svg { min-width: 0; }
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
