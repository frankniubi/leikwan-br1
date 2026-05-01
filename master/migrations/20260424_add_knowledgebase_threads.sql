CREATE TABLE IF NOT EXISTS knowledgebase_threads (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    author_user_id BIGINT UNSIGNED NULL,
    title VARCHAR(190) NOT NULL,
    slug VARCHAR(190) NOT NULL,
    excerpt TEXT NULL,
    body_html MEDIUMTEXT NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'draft',
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 100,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_knowledgebase_threads_author FOREIGN KEY (author_user_id) REFERENCES users(id),
    UNIQUE KEY uniq_knowledgebase_threads_slug (slug),
    KEY idx_knowledgebase_threads_reader (status, is_pinned, sort_order, published_at),
    KEY idx_knowledgebase_threads_updated (updated_at)
);

INSERT INTO knowledgebase_threads (
    author_user_id, title, slug, excerpt, body_html, status, is_pinned, sort_order, published_at, created_at, updated_at
)
SELECT
    NULL,
    'Getting Started: First Forward Checklist',
    'getting-started-first-forward-checklist',
    'A click-by-click first-forward guide based on the tenant panel: Create Forward, field meanings, save result, and first verification.',
    '<h2>Getting Started: First Forward Checklist / 第一條轉發檢查清單</h2>
<p><strong>English:</strong> This article follows the actual tenant workflow. Use it when you want to create one working forward first, before changing advanced routing assumptions.</p>
<p><strong>中文繁體：</strong>這篇按照租戶實際看到的面板流程撰寫。目標是先做出第一條可用的轉發，不要一開始就猜底層線路或改進階設定。</p>

<h3>1. Open the Create Forward form / 進入新增轉發表單</h3>
<ol>
  <li>Open the <strong>Tenant Panel</strong>. / 打開 <strong>Tenant Panel</strong>。</li>
  <li>Enter the service page that needs a forward. / 進入需要建立轉發的服務頁。</li>
  <li>Click <strong>Create Forward</strong>. / 點擊 <strong>Create Forward</strong>。</li>
</ol>
<p>If no route package appears, stop and ask the administrator to publish a forward combination for your plan. / 如果沒有可選的路由包，先停止操作，請管理員為你的方案發布可用的 forward combination。</p>

<h3>2. Choose the options / 需要選擇什麼選項</h3>
<ul>
  <li><strong>Forward Combination</strong>: this is the admin-defined route package. Choose the package name that matches your service or location requirement. The hidden slave, race, bond, and sidecar details are handled by the panel and plan configuration. / <strong>Forward Combination</strong> 是管理員預先設定的線路包。選擇符合你服務或地區需求的名稱即可；隱藏的 slave、race、bond、sidecar 拓撲由面板與方案配置處理。</li>
  <li><strong>Target Host</strong>: this is the backend host that the route should finally reach. It can be an internal IP, a domain, or <code>127.0.0.1</code> when the backend is on the runtime-side machine itself. / <strong>Target Host</strong> 是轉發最終要連到的後端主機，可以是內網 IP、域名；如果後端就在執行端本機，通常填 <code>127.0.0.1</code>。</li>
  <li><strong>Target Port</strong>: this is the backend service port, such as <code>80</code>, <code>443</code>, <code>22</code>, <code>25565</code>, or your application port. / <strong>Target Port</strong> 是後端服務的連接埠，例如 <code>80</code>、<code>443</code>、<code>22</code>、<code>25565</code> 或你的應用程式連接埠。</li>
  <li><strong>Local Listen Port</strong>: leave it blank for the panel to auto-assign a port from the client line range. Only fill it when you must keep a fixed port on the client side. / <strong>Local Listen Port</strong> 可留空，讓面板從 Client 線路範圍自動分配；只有你必須固定 Client 端口時才手動填寫。</li>
  <li><strong>Transport</strong>: use <code>TCP</code> for websites, SSH, control panels, APIs, and most apps. Use <code>TCP + UDP</code> or <code>UDP</code> only when the application really needs UDP traffic. / <strong>Transport</strong>：網站、SSH、面板、API 和大部分應用選 <code>TCP</code>；只有應用真的需要 UDP 時才選 <code>TCP + UDP</code> 或 <code>UDP</code>。</li>
</ul>

<h3>3. Save and read the result / 儲存後看結果</h3>
<ol>
  <li>Click <strong>Create Forward</strong> to save. / 點擊 <strong>Create Forward</strong> 儲存。</li>
  <li>Return to the service page and find the new forward card. / 回到服務頁，找到新的轉發卡片。</li>
  <li>Write down the shown local listen port. This is the port you will use later when connecting through the Client. / 記下卡片顯示的 local listen port；之後透過 Client 連線時會用到這個端口。</li>
</ol>

<h3>First-use rule / 第一次使用的原則</h3>
<p>For the first forward, only change the visible form fields above. Do not adjust sidecar, hidden route, or advanced runtime assumptions unless support tells you to. / 第一條轉發只改上面看得到的表單欄位；除非客服或管理員要求，不要自行改 sidecar、隱藏路由或進階 runtime 假設。</p>',
    'published',
    1,
    10,
    UTC_TIMESTAMP(),
    UTC_TIMESTAMP(),
    UTC_TIMESTAMP()
WHERE NOT EXISTS (
    SELECT 1 FROM knowledgebase_threads WHERE slug = 'getting-started-first-forward-checklist'
);

INSERT INTO knowledgebase_threads (
    author_user_id, title, slug, excerpt, body_html, status, is_pinned, sort_order, published_at, created_at, updated_at
)
SELECT
    NULL,
    'Connection Modes: Single, Race, Bond',
    'connection-modes-single-race-bond',
    'Tenant-facing explanation of Single, Race, and Bond, centered on the Forward Combination experience rather than internal topology.',
    '<h2>Connection Modes: Single, Race, Bond / 連線模式：Single、Race、Bond</h2>
<p><strong>English:</strong> In daily tenant use, you normally do not assemble modes by hand. You select a <strong>Forward Combination</strong>, and that package already contains the mode and line design chosen by the administrator.</p>
<p><strong>中文繁體：</strong>租戶日常使用時，通常不需要自己組裝模式。你選擇的是 <strong>Forward Combination</strong>；這個線路包已經包含管理員配置好的模式與線路設計。</p>

<h3>Single / 單線</h3>
<ul>
  <li><strong>What you experience:</strong> the simplest route package with the fewest moving parts. / <strong>實際體驗：</strong>最單純、最少變數的線路包。</li>
  <li><strong>When to use:</strong> first setup, normal websites, SSH, panels, APIs, and services where stability is more important than path experiments. / <strong>適合：</strong>第一次設定、一般網站、SSH、面板、API，以及重視穩定而非測線路的服務。</li>
  <li><strong>How to choose:</strong> select the Single-style Forward Combination, fill Target Host and Target Port, then save. / <strong>怎麼選：</strong>選擇 Single 類型的 Forward Combination，填 Target Host 與 Target Port，然後儲存。</li>
</ul>

<h3>Race / 競速</h3>
<ul>
  <li><strong>What you experience:</strong> the client can try multiple available paths and use the path that responds best. / <strong>實際體驗：</strong>Client 會嘗試多條可用路徑，使用反應較好的路徑。</li>
  <li><strong>What it is not:</strong> Race is not bandwidth aggregation. It is mainly redundancy and path selection. / <strong>它不是什麼：</strong>Race 不是疊加頻寬，主要是冗餘與路徑選擇。</li>
  <li><strong>When to use:</strong> when your plan offers multiple paths and you want failover-like behavior without Bond sidecar requirements. / <strong>適合：</strong>方案提供多條線路，想要類似故障切換的效果，但不想處理 Bond sidecar 條件。</li>
</ul>

<h3>Bond / 聚合</h3>
<ul>
  <li><strong>What you experience:</strong> an advanced package that may use multiple legs through the configured runtime path. / <strong>實際體驗：</strong>進階線路包，可能透過配置好的 runtime 路徑使用多條 leg。</li>
  <li><strong>Requirement:</strong> Bond depends on the plan, client runtime, and sometimes a sidecar point being ready. / <strong>要求：</strong>Bond 依賴方案、Client runtime，有時也依賴 sidecar point 已就緒。</li>
  <li><strong>How to verify:</strong> after installing the Client, open <strong>View Diagnostics</strong> on the client or forward card and check that Bond legs are healthy instead of guessing from the package name. / <strong>如何確認：</strong>安裝 Client 後，在 Client 或轉發卡片點 <strong>View Diagnostics</strong>，看 Bond legs 是否健康，不要只靠線路包名稱猜測。</li>
</ul>

<h3>Practical decision rule / 實用判斷規則</h3>
<p>Do not choose a package only because the name sounds faster. Choose the Forward Combination assigned for your plan and use case. If the first forward must be proven quickly, start with the simplest available package, then move to Race or Bond after the basic service works.</p>
<p>不要只因為名稱聽起來比較快就選。請選管理員為你的方案與用途分配的 Forward Combination。如果要快速確認第一條轉發是否可用，先從最單純的可用線路開始，基本服務通了再切到 Race 或 Bond。</p>',
    'published',
    0,
    20,
    UTC_TIMESTAMP(),
    UTC_TIMESTAMP(),
    UTC_TIMESTAMP()
WHERE NOT EXISTS (
    SELECT 1 FROM knowledgebase_threads WHERE slug = 'connection-modes-single-race-bond'
);

INSERT INTO knowledgebase_threads (
    author_user_id, title, slug, excerpt, body_html, status, is_pinned, sort_order, published_at, created_at, updated_at
)
SELECT
    NULL,
    'Bond Sidecar Setup',
    'bond-sidecar-setup',
    'Separate Bond/Sidecar article for tenants: create sidecar point, install the sidecar client, then use it from a Bond-capable forward.',
    '<h2>Bond Sidecar Setup / Bond Sidecar 設定</h2>
<p><strong>English:</strong> Keep this as a separate article because Bond with a sidecar has a different operational flow from a normal forward. In this environment, the intended path is <code>client -&gt; slave -&gt; sidecar</code>; the sidecar is not a direct client target.</p>
<p><strong>中文繁體：</strong>Bond 搭配 Sidecar 的操作流程與一般轉發不同，所以獨立成篇。在此環境中，預期拓撲是 <code>client -&gt; slave -&gt; sidecar</code>；sidecar 不是讓 Client 直接連的目標。</p>

<h3>1. Create the sidecar point / 建立 Sidecar Point</h3>
<ol>
  <li>Open <strong>Tenant -&gt; Sidecars</strong>. / 打開 <strong>Tenant -&gt; Sidecars</strong>。</li>
  <li>Click or use the <strong>Create Sidecar Point</strong> form. / 使用 <strong>Create Sidecar Point</strong> 表單。</li>
  <li>Fill <strong>Name</strong>: a label you can recognize later, for example the site or machine name. / 填 <strong>Name</strong>：之後能辨識的位置或機器名稱。</li>
  <li>Fill <strong>Sidecar Host</strong>: the C-side address reachable by the slave path. Do not treat it as the public address that end users type. / 填 <strong>Sidecar Host</strong>：slave 路徑能到達的 C 端地址；不要把它當成終端使用者要輸入的公開地址。</li>
  <li>Fill <strong>Bond Listen Port</strong>: use the value provided by your plan or administrator. Do not randomize it when your plan has a fixed port range. / 填 <strong>Bond Listen Port</strong>：使用方案或管理員提供的值；如果方案有固定端口範圍，不要隨機填。</li>
  <li>Save the sidecar point. / 儲存 Sidecar Point。</li>
</ol>

<h3>2. Install the sidecar runtime / 安裝 Sidecar Runtime</h3>
<ol>
  <li>Find the new card in <strong>Sidecar Point List</strong>. / 在 <strong>Sidecar Point List</strong> 找到新卡片。</li>
  <li>Open <strong>Install Command</strong> and copy the full command. / 打開 <strong>Install Command</strong>，複製完整指令。</li>
  <li>Paste it on the sidecar machine and run it as root or with sudo. / 在 sidecar 機器上貼上並用 root 或 sudo 執行。</li>
  <li>Return to the panel and wait for <strong>Last Heartbeat</strong>, <strong>Agent Version</strong>, and <strong>Last Status</strong> to update. / 回到面板，等待 <strong>Last Heartbeat</strong>、<strong>Agent Version</strong>、<strong>Last Status</strong> 更新。</li>
</ol>

<h3>3. Use it from a Bond forward / 在 Bond 轉發中使用</h3>
<ol>
  <li>Create a forward and choose a Bond-capable <strong>Forward Combination</strong>. / 建立轉發並選擇支援 Bond 的 <strong>Forward Combination</strong>。</li>
  <li>If the form exposes a sidecar selector, choose the sidecar point you created. / 如果表單提供 sidecar 選擇器，選剛建立的 sidecar point。</li>
  <li>Set <strong>Target Host</strong> and <strong>Target Port</strong> to the backend service behind the sidecar. If the backend runs on the sidecar machine itself, Target Host is usually <code>127.0.0.1</code>. / <strong>Target Host</strong> 與 <strong>Target Port</strong> 填 sidecar 後方的後端服務；如果後端就在 sidecar 機器本機，Target Host 通常填 <code>127.0.0.1</code>。</li>
  <li>Save, then install or refresh the Client and check <strong>View Diagnostics</strong>. / 儲存後安裝或刷新 Client，並查看 <strong>View Diagnostics</strong>。</li>
</ol>

<h3>Common checks / 常見檢查</h3>
<ul>
  <li>If <strong>Last Heartbeat</strong> is empty, the sidecar runtime has not connected back to the panel yet. / 如果 <strong>Last Heartbeat</strong> 是空的，表示 sidecar runtime 尚未回連面板。</li>
  <li>If the sidecar is shared or read-only, do not edit it unless the administrator assigned it to you. / 如果 sidecar 是共享或唯讀，不要自行修改，除非管理員已分配給你。</li>
  <li>Before changing the forward, confirm the backend service is listening from the sidecar machine. / 在改轉發前，先確認後端服務在 sidecar 機器上真的有監聽。</li>
</ul>',
    'published',
    0,
    30,
    UTC_TIMESTAMP(),
    UTC_TIMESTAMP(),
    UTC_TIMESTAMP()
WHERE NOT EXISTS (
    SELECT 1 FROM knowledgebase_threads WHERE slug = 'bond-sidecar-setup'
);

INSERT INTO knowledgebase_threads (
    author_user_id, title, slug, excerpt, body_html, status, is_pinned, sort_order, published_at, created_at, updated_at
)
SELECT
    NULL,
    'Troubleshooting Common Mistakes',
    'troubleshooting-common-mistakes',
    'Practical tenant troubleshooting: install Client on Windows/Linux/Android and connect through 127.0.0.1 or the Client Public IP correctly.',
    '<h2>Troubleshooting Common Mistakes / 常見錯誤排查</h2>
<p><strong>English:</strong> After a forward exists, most connection problems come from the Client install step or from using the wrong address to connect. Follow the operating system section first, then check the address section.</p>
<p><strong>中文繁體：</strong>轉發建立後，多數連不上是 Client 安裝流程錯誤，或連線時用了錯的地址。先看對應系統的安裝方式，再看「如何使用轉發後的服務」。</p>

<h3>Install Client - Windows / 安裝 Client - Windows</h3>
<ol>
  <li>Open <strong>Tenant -&gt; Clients</strong>. / 打開 <strong>Tenant -&gt; Clients</strong>。</li>
  <li>Click <strong>Download Windows App</strong>. / 點擊 <strong>Download Windows App</strong>。</li>
  <li>Install and open the Windows app. / 安裝並開啟 Windows App。</li>
  <li>On the client card, copy <strong>Client ID</strong> and <strong>Token</strong>. / 在 client 卡片複製 <strong>Client ID</strong> 與 <strong>Token</strong>。</li>
  <li>Paste them into the Windows app and sign in. / 貼上到 Windows App 並登入。</li>
</ol>

<h3>Install Client - Linux / 安裝 Client - Linux</h3>
<ol>
  <li>Open <strong>Tenant -&gt; Clients</strong> and find the client card you want to run. / 打開 <strong>Tenant -&gt; Clients</strong>，找到要執行的 client 卡片。</li>
  <li>Open <strong>Linux Install</strong>. / 打開 <strong>Linux Install</strong>。</li>
  <li>Click <strong>Copy Install Command</strong>. / 點擊 <strong>Copy Install Command</strong>。</li>
  <li>Paste the command into the Linux terminal and run it as root or with sudo. / 把指令貼上到 Linux 終端機，用 root 或 sudo 執行。</li>
  <li>Return to the panel and check <strong>Installed Agent</strong>, <strong>Last Sync</strong>, and <strong>View Diagnostics</strong>. / 回到面板查看 <strong>Installed Agent</strong>、<strong>Last Sync</strong>、<strong>View Diagnostics</strong>。</li>
</ol>

<h3>Install Client - Android / 安裝 Client - Android</h3>
<ol>
  <li>Open <strong>Tenant -&gt; Clients</strong>. / 打開 <strong>Tenant -&gt; Clients</strong>。</li>
  <li>Click <strong>Download Android App</strong> and install the APK. / 點擊 <strong>Download Android App</strong> 並安裝 APK。</li>
  <li>Sign in with <strong>Client ID</strong> and <strong>Token</strong>, or use the <strong>Android Import String</strong> if the app provides an import option. / 使用 <strong>Client ID</strong> 與 <strong>Token</strong> 登入；如果 App 有匯入選項，也可使用 <strong>Android Import String</strong>。</li>
  <li>Return to the panel and confirm the client shows a recent sync. / 回到面板確認 client 有近期同步紀錄。</li>
</ol>

<h3>How To Use The Forward / 如何使用轉發後的服務</h3>
<ul>
  <li><strong>Local use:</strong> if the app you are using is on the same machine as the Client runtime, change the service address to <code>127.0.0.1</code> and use the forward local listen port. Example: <code>127.0.0.1:LOCAL_PORT</code>. / <strong>本機使用：</strong>如果使用服務的程式與 Client runtime 在同一台機器，把連線地址改成 <code>127.0.0.1</code>，端口填轉發卡片上的 local listen port。例如 <code>127.0.0.1:LOCAL_PORT</code>。</li>
  <li><strong>External use:</strong> if another device needs to connect through this Client, use the <strong>Client Public IP</strong> and the same local listen port. Example: <code>CLIENT_PUBLIC_IP:LOCAL_PORT</code>. / <strong>外部使用：</strong>如果其他設備要透過這台 Client 連線，地址直接寫 <strong>Client 的公網 IP</strong>，端口仍然使用同一個 local listen port。例如 <code>CLIENT_PUBLIC_IP:LOCAL_PORT</code>。</li>
  <li>For external use, the Client machine firewall and cloud security group must allow inbound traffic to that local listen port. / 外部使用時，Client 機器防火牆與雲端安全組必須放行該 local listen port 的入站流量。</li>
</ul>

<h3>Do not confuse these two addresses / 不要混淆這兩種地址</h3>
<ul>
  <li><strong>Target Host</strong> is the backend destination that the route reaches after entering the tunnel. Users normally do not type this address in their app. / <strong>Target Host</strong> 是流量進入隧道後要到達的後端目的地；一般使用者不會在 App 裡輸入這個地址。</li>
  <li><strong>Client listen address</strong> is what you actually connect to: <code>127.0.0.1</code> for same-machine use, or the Client Public IP for external devices. / <strong>Client listen address</strong> 才是實際連線地址：同機使用填 <code>127.0.0.1</code>，外部設備使用填 Client Public IP。</li>
</ul>

<h3>When it still fails / 還是不通時</h3>
<ol>
  <li>Open <strong>View Diagnostics</strong> and check whether the client has recent sync and healthy routes. / 打開 <strong>View Diagnostics</strong>，確認 client 有近期同步且路由健康。</li>
  <li>Confirm the backend is listening on the configured Target Host and Target Port. / 確認後端真的在設定的 Target Host 與 Target Port 監聽。</li>
  <li>For external use, test the Client machine firewall before changing the forward. / 外部使用時，先測 Client 機器防火牆，再考慮修改轉發。</li>
</ol>',
    'published',
    0,
    40,
    UTC_TIMESTAMP(),
    UTC_TIMESTAMP(),
    UTC_TIMESTAMP()
WHERE NOT EXISTS (
    SELECT 1 FROM knowledgebase_threads WHERE slug = 'troubleshooting-common-mistakes'
);
