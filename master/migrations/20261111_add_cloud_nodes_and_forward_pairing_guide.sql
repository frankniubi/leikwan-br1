-- Guarded new knowledgebase topic: cloud nodes and forward pairing.

INSERT INTO knowledgebase_topics (topic_key, is_pinned, sort_order, created_at, updated_at)
SELECT 'cloud-nodes-and-forward-pairing', 0, 80, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
WHERE NOT EXISTS (
    SELECT 1
    FROM knowledgebase_topics AS existing_topic
    WHERE existing_topic.topic_key = 'cloud-nodes-and-forward-pairing'
);

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'en', NULL, 'How Cloud Nodes Pair with Forwards', 'A forward and a cloud node are one-to-one paired by port: they must match exactly, or reuse a node only when protocol and password also match.', '<p>A forward carries packets from your local listen port to a remote target server, while a cloud node is a saved server configuration that your client can use directly. Understanding how they pair is key to setting up forwards correctly.</p>

<h2>Two Concepts</h2>
<p><strong>A forward</strong> is a routing rule: from a local listen port on your device, deliver packets to a specific port on a remote target server on the internet.</p>
<p><strong>A cloud node</strong> is a saved server configuration that a client can use directly. Think of a node as a snapshot of "target server details": it records the server address, port, protocol, password, and other parameters needed to connect.</p>

<h2>The Core Rule</h2>
<p><strong>A forward is paired with exactly one node: the local port and node are one-to-one. Multiple forwards should not share the same node, except when they fully meet the reuse conditions below.</strong></p>

<h2>When You Can Reuse the Same Node</h2>
<p>Another forward can reuse a node only if all three of these elements match the node exactly:</p>
<ol>
<li><strong>Target server port</strong> — for example, both are 30522.</li>
<li><strong>Protocol</strong> — for example, both are Shadowsocks 2022.</li>
<li><strong>Password</strong> — the authentication key to log in to that server.</li>
</ol>
<p>If even one item differs, you must create a new forward and a new node. Reusing a node with mismatched settings always fails.</p>

<h2>Example: What Cannot Be Reused</h2>
<table>
<thead>
<tr><th>Forward</th><th>Target</th><th>Protocol</th><th>Password</th><th>Can reuse Node A?</th></tr>
</thead>
<tbody>
<tr><td>Forward A</td><td>node.example.com:30522</td><td>SS2022</td><td>Password X</td><td>—</td></tr>
<tr><td>Forward B</td><td>node.example.com:30522</td><td>SS2022</td><td>Password Y (≠X)</td><td><strong>No</strong> — password differs → create new forward B and node B</td></tr>
<tr><td>Forward C</td><td>node.example.com:30522</td><td>VLESS</td><td>Password X</td><td><strong>No</strong> — protocol differs → create new forward C and node C</td></tr>
<tr><td>Forward D</td><td>node.example.com:30523</td><td>SS2022</td><td>Password X</td><td><strong>No</strong> — port differs → create new forward D and node D</td></tr>
</tbody>
</table>

<h2>Automatic Pairing: How the Panel Matches Them</h2>
<p>The panel automatically finds a matching node based on the forward''s target (server and port). If a node''s server and port match the forward''s target, they pair automatically. This is convenient, but automatic pairing <strong>does not verify protocol or password match</strong>. Before reusing a node, you must verify yourself: all three elements really are identical.</p>

<h2>Common Mistakes</h2>
<table>
<thead>
<tr><th>Symptom</th><th>Root Cause</th></tr>
</thead>
<tbody>
<tr><td>One forward never connects, others work fine</td><td>Reused the same node, but the target port, protocol, or password does not match</td></tr>
<tr><td>Changed a forward''s target port, but it still does not connect</td><td>Did not update the paired node''s settings; the node still points to the old port</td></tr>
<tr><td>Deleted a node, the forward still exists, now it does not work</td><td>The forward lost its paired node, but the forward itself remains; there is no available node anymore</td></tr>
<tr><td>Created two forwards to the same target, one drops constantly</td><td>Both forwards are paired with the same node, but password or protocol does not match one of them</td></tr>
</tbody>
</table>

<h2>Related Guides</h2>
<p>After reading this, return to <a href="/knowledgebase?thread=getting-started-first-forward-checklist">Create Your First Forward</a> to confirm your forward is correct. If the forward''s target address, port, or protocol are wrong, see <a href="/knowledgebase?thread=troubleshooting-common-mistakes">Troubleshoot Common Problems</a>.</p>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'cloud-nodes-and-forward-pairing'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'en'
);

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-Hans', NULL, '云端节点与转发如何配对', '一条转发与一个云端节点一一对应：仅当目标端口相同、协议相同、密码相同时，才可复用同一节点；否则必须新建转发和节点。', '<p>一条转发把本地监听端口的数据包转送到目标机器，而云端节点是你在 App 里可以直接选用的服务器配置。理解它们之间的配对规则，是把转发用对的关键。</p>

<h2>两个概念</h2>
<p><strong>转发</strong>是一条通路规则：从你的设备上的本地监听端口，把数据包转送到互联网上某个远端目标机器的特定端口。</p>
<p><strong>云端节点</strong>是一个被保存的服务器配置，客户端可以直接使用。你可以把节点看作「转发目标机器的细节」的一个快照：它记录了目标服务器地址、端口、连接协议、密码等参数。</p>

<h2>核心规则</h2>
<p><strong>一条转发绑定一个节点，端口与节点一一对应。多个转发不应共用同一个节点，除非它们完全满足下面的复用条件。</strong></p>

<h2>什么时候才能复用同一个节点</h2>
<p>只有当另一条转发的以下三个要素与该节点 <strong>全部相同</strong> 时，才可以复用：</p>
<ol>
<li><strong>目标机器的端口</strong>：比如都是 30522。</li>
<li><strong>连接协议</strong>：比如都是 Shadowsocks 2022。</li>
<li><strong>密码</strong>：登录该服务器的认证密钥。</li>
</ol>
<p>只要有一项不同，就必须新建一条转发并新建一个节点。重用节点必然导致不通。</p>

<h2>实例：什么不能复用</h2>
<table>
<thead>
<tr><th>转发</th><th>目标</th><th>协议</th><th>密码</th><th>能否复用节点 A？</th></tr>
</thead>
<tbody>
<tr><td>转发 A</td><td>node.example.com:30522</td><td>SS2022</td><td>密码 X</td><td>—</td></tr>
<tr><td>转发 B</td><td>node.example.com:30522</td><td>SS2022</td><td>密码 Y（≠X）</td><td><strong>不能</strong>，密码不同 → 必须新建转发 B 和节点 B</td></tr>
<tr><td>转发 C</td><td>node.example.com:30522</td><td>VLESS</td><td>密码 X</td><td><strong>不能</strong>，协议不同 → 必须新建转发 C 和节点 C</td></tr>
<tr><td>转发 D</td><td>node.example.com:30523</td><td>SS2022</td><td>密码 X</td><td><strong>不能</strong>，端口不同 → 必须新建转发 D 和节点 D</td></tr>
</tbody>
</table>

<h2>自动配对：面板如何帮你匹配</h2>
<p>面板会根据转发的「目标机器:端口」自动寻找相匹配的节点（「节点地址:节点端口」）。这样可以帮你省事，但自动配对 <strong>不会检查协议和密码是否一致</strong>。因此，复用节点前，你必须自己验证：三个要素都确实相同。</p>

<h2>常见错误</h2>
<table>
<thead>
<tr><th>症状</th><th>根本原因</th></tr>
</thead>
<tbody>
<tr><td>某条转发始终连不上，其他转发正常</td><td>复用了同一个节点，但目标端口、协议或密码其中之一不匹配</td></tr>
<tr><td>改了转发的目标端口，但转发仍连不上</td><td>没有相应修改配对的节点信息；节点仍然指向旧端口</td></tr>
<tr><td>删除了一个节点，转发却仍在，现在用不了</td><td>转发失去了配对的节点，但转发本身还存在，只是没有可用节点了</td></tr>
<tr><td>新建两条相同目标的转发，其中一条频繁掉线</td><td>两条转发都配对了同一个节点，但密码或协议不匹配其中某一条</td></tr>
</tbody>
</table>

<h2>相关指南</h2>
<p>看完这篇，回到<a href="/knowledgebase?thread=getting-started-first-forward-checklist">创建第一条转发</a>确认转发建立无误。如果转发本身的目标地址、端口或协议有问题，请参考<a href="/knowledgebase?thread=troubleshooting-common-mistakes">排查常见问题</a>。</p>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'cloud-nodes-and-forward-pairing'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-Hans'
);

INSERT INTO knowledgebase_articles (
    topic_id, locale, author_user_id, title, excerpt, body_html,
    status, published_at, created_at, updated_at
)
SELECT
    topic.id, 'zh-TW', NULL, '雲端節點與轉發如何配對', '一條轉發與一個雲端節點一一對應：僅當目標連接埠相同、協議相同、密碼相同時，才可複用同一節點；否則必須新建轉發和節點。', '<p>一條轉發把本機連接埠的資料包轉送到目標機器，而雲端節點是你在 App 裡可以直接選用的伺服器設定。理解它們之間的配對規則，是把轉發用對的關鍵。</p>

<h2>兩個概念</h2>
<p><strong>轉發</strong>是一條通路規則：從你的設備上的本機連接埠，把資料包轉送到網際網路上某個遠端目標機器的特定連接埠。</p>
<p><strong>雲端節點</strong>是一個被保存的伺服器設定，用戶端可以直接使用。你可以把節點看作「轉發目標機器的細節」的一個快照：它記錄了目標伺服器位址、連接埠、連線協議、密碼等參數。</p>

<h2>核心規則</h2>
<p><strong>一條轉發綁定一個節點，連接埠與節點一一對應。多個轉發不應共用同一個節點，除非它們完全滿足下面的複用條件。</strong></p>

<h2>什麼時候才能複用同一個節點</h2>
<p>只有當另一條轉發的以下三個要素與該節點 <strong>全部相同</strong> 時，才可以複用：</p>
<ol>
<li><strong>目標機器的連接埠</strong>：比如都是 30522。</li>
<li><strong>連線協議</strong>：比如都是 Shadowsocks 2022。</li>
<li><strong>密碼</strong>：登入該伺服器的認證密鑰。</li>
</ol>
<p>只要有一項不同，就必須新建一條轉發並新建一個節點。重用節點必然導致不通。</p>

<h2>實例：什麼不能複用</h2>
<table>
<thead>
<tr><th>轉發</th><th>目標</th><th>協議</th><th>密碼</th><th>能否複用節點 A？</th></tr>
</thead>
<tbody>
<tr><td>轉發 A</td><td>node.example.com:30522</td><td>SS2022</td><td>密碼 X</td><td>—</td></tr>
<tr><td>轉發 B</td><td>node.example.com:30522</td><td>SS2022</td><td>密碼 Y（≠X）</td><td><strong>不能</strong>，密碼不同 → 必須新建轉發 B 和節點 B</td></tr>
<tr><td>轉發 C</td><td>node.example.com:30522</td><td>VLESS</td><td>密碼 X</td><td><strong>不能</strong>，協議不同 → 必須新建轉發 C 和節點 C</td></tr>
<tr><td>轉發 D</td><td>node.example.com:30523</td><td>SS2022</td><td>密碼 X</td><td><strong>不能</strong>，連接埠不同 → 必須新建轉發 D 和節點 D</td></tr>
</tbody>
</table>

<h2>自動配對：面板如何幫你匹配</h2>
<p>面板會根據轉發的「目標機器:連接埠」自動尋找相匹配的節點（「節點位址:節點連接埠」）。這樣可以幫你省事，但自動配對 <strong>不會檢查協議和密碼是否一致</strong>。因此，複用節點前，你必須自己驗證：三個要素都確實相同。</p>

<h2>常見錯誤</h2>
<table>
<thead>
<tr><th>症狀</th><th>根本原因</th></tr>
</thead>
<tbody>
<tr><td>某條轉發始終連不上，其他轉發正常</td><td>複用了同一個節點，但目標連接埠、協議或密碼其中之一不匹配</td></tr>
<tr><td>改了轉發的目標連接埠，但轉發仍連不上</td><td>沒有相應修改配對的節點資訊；節點仍然指向舊連接埠</td></tr>
<tr><td>刪除了一個節點，轉發卻仍在，現在用不了</td><td>轉發失去了配對的節點，但轉發本身還存在，只是沒有可用節點了</td></tr>
<tr><td>新建兩條相同目標的轉發，其中一條頻繁掉線</td><td>兩條轉發都配對了同一個節點，但密碼或協議不匹配其中某一條</td></tr>
</tbody>
</table>

<h2>相關指南</h2>
<p>看完這篇，回到<a href="/knowledgebase?thread=getting-started-first-forward-checklist">建立第一條轉發</a>確認轉發建立無誤。如果轉發本身的目標位址、連接埠或協議有問題，請參考<a href="/knowledgebase?thread=troubleshooting-common-mistakes">排查常見問題</a>。</p>
',
    'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM knowledgebase_topics AS topic
WHERE topic.topic_key = 'cloud-nodes-and-forward-pairing'
  AND NOT EXISTS (
      SELECT 1
      FROM knowledgebase_articles AS existing_article
      WHERE existing_article.topic_id = topic.id
        AND existing_article.locale = 'zh-TW'
);

-- Cross-link the pairing guide from two existing guides. Guarded by the
-- shipped body hash so an administrator-edited article is never overwritten.
UPDATE knowledgebase_articles
SET body_html = '<p>A forward is one line you draw on the panel: where your game or service lives, and which local port your device should use to reach it.</p>

<h2>What you will accomplish</h2>
<p>One saved forward with an assigned local port. The next guide needs that port, so write it down.</p>

<h2>Before you begin</h2>
<ul>
<li>An active plan, so the panel lets you save a forward.</li>
<li>The address and port your game or service really listens on.</li>
<li>Which transport it needs: TCP, UDP, or both. Games are usually UDP.</li>
</ul>

<h2>Steps</h2>
<ol>
<li><strong>Open My Services and click New forward on your package.</strong> The Create Forward button on the service details page opens the same form.</li>
<li><strong>Pick a Forward Route.</strong> Take the line closest to the destination. Every line uses ultra, so there is nothing else to decide.</li>
<li><strong>Fill in Target Host and Target Port.</strong> This is the address the game itself connects to, not the address of the site you downloaded it from.</li>
<li><strong>Choose the Transport</strong> your service needs: TCP, UDP, or TCP + UDP.</li>
<li><strong>Click Create Forward.</strong> Leave Local Listen Port empty so the panel assigns one, unless you have a reason to pick it yourself.</li>
<li><strong>Write down the assigned local port.</strong> After saving you land on the service details page; the new forward is in the Forward Rules table and the Local Listen column is its port.</li>
</ol>
<pre class="mermaid">
flowchart TD
accTitle: First forward form path
accDescr: Open an active plan, pick the forward route, fill in the destination, save, then note the assigned local port
A[Open an active plan] --> B[Pick a Forward Route]
B --> C[Fill in Target Host and Target Port]
C --> D[Choose the Transport]
D --> E[Save]
E --> F[Wait until the forward is ready]
F --> G[Note the assigned local port]
</pre>
<p>The form only asks for the route, the destination, the transport, and the local port. The rest is filled in for you.</p>
<figure><img src="/assets/knowledgebase/screenshots/tenant/02-forward-form-en.png" width="1440" height="960" alt="Create Forward form with the route, target host, target port and transport fields"><figcaption>The Create Forward form. Only four fields need your attention.</figcaption></figure>

<h2>How to know it worked</h2>
<p>In Forward Rules the row reads <strong>Running</strong>, Local Listen has a number, and Endpoint Status no longer says <strong>No endpoint assigned yet</strong>. Then check that the device that will carry this forward is online on the Clients page.</p>

<h2>If it did not work</h2>
<p><strong>Stop</strong> if the panel refuses to save. Three usual reasons: the plan is not active any more, the target host or port is not right, or the local port you typed is already in use - clear it and let the panel suggest one again.</p>

<h2>Related guides</h2>
<p>Next, <a href="/knowledgebase?thread=connect-and-verify">connect and verify</a>. If the app is not installed yet, start with <a href="/knowledgebase?thread=install-client">install the client</a>. To understand how forwards and nodes pair, read <a href="/knowledgebase?thread=cloud-nodes-and-forward-pairing">how cloud nodes pair with forwards</a>.</p>
',
    updated_at = CURRENT_TIMESTAMP
WHERE locale = 'en'
  AND topic_id = (SELECT id FROM knowledgebase_topics WHERE topic_key = 'getting-started-first-forward-checklist')
  AND SHA2(body_html, 256) = '4e8662d19589858c74949e2f42ed401b2b4d29bddea72f930607d44c645d083b';

UPDATE knowledgebase_articles
SET body_html = '<p>一条转发就是你在控制台上画的一条线：你的游戏或服务在哪里，以及本机用哪个端口去连它。</p>

<h2>你将完成什么</h2>
<p>一条保存好的转发，加上一个分配给你的本地端口。下一篇指南要用这个端口，先记下来。</p>

<h2>开始前准备</h2>
<ul>
<li>一个使用中的套餐，控制台才允许保存转发。</li>
<li>你的游戏或服务真正监听的地址和端口。</li>
<li>它需要哪种协议：TCP、UDP 还是两者都要。游戏一般是 UDP。</li>
</ul>

<h2>操作步骤</h2>
<ol>
<li><strong>打开「我的服务」，在套餐卡片上点「新建转发」。</strong>在服务详情页里点「新建转发」也是同一个表单。</li>
<li><strong>选择线路。</strong>挑离目标最近的那条线路。所有线路都用 ultra，这里不需要再决定别的。</li>
<li><strong>填写目标地址和目标端口。</strong>这里要填游戏自己连的地址，不是你下载游戏的网站地址。</li>
<li><strong>选择协议</strong>，也就是你的服务需要的传输方式：TCP、UDP 或 TCP + UDP。</li>
<li><strong>点「新建转发」保存。</strong>本地监听端口留空就行，系统会自动分配，除非你有特别的理由要指定。</li>
<li><strong>记下分配给你的本地端口。</strong>保存后会回到服务详情页，新转发出现在「转发规则」表里，「本地端口」一列就是它。</li>
</ol>
<pre class="mermaid">
flowchart TD
accTitle: 第一条转发的表单路径
accDescr: 打开使用中的套餐，选择线路，填写目标和协议，保存后记下分配的本地端口
A[打开我的服务] --> B[点新建转发]
B --> C[选择线路]
C --> D[填写目标地址和目标端口]
D --> E[选择协议]
E --> F[保存]
F --> G[记下分配的本地端口]
</pre>
<p>表单只问线路、目标、协议和本地端口，其余都替你填好了。</p>
<figure><img src="/assets/knowledgebase/screenshots/tenant/02-forward-form-zh-hans.png" width="1440" height="960" alt="新建转发表单，包含线路、目标地址、目标端口、本地监听端口和协议"><figcaption>「新建转发」表单，真正要你决定的只有四个字段。</figcaption></figure>

<h2>如何确认成功</h2>
<p>在「转发规则」表里，这一行的状态是「运行中」，「本地端口」已经有数字，「节点状态」不再一直显示「正在分配节点」。接着去「客户端」页面，确认准备承载这条转发的设备出现在「在线设备」里。</p>

<h2>如果没有成功</h2>
<p>控制台不让保存时，<strong>先停止</strong>，别反复点。三个常见原因：套餐已经到期；目标地址或端口填错；你填的本地端口已经被占用——清空它，让系统重新分配一个。如果「线路」下拉框是空的，说明你的套餐还没有开放线路，请联系客服。</p>

<h2>相关指南</h2>
<p>接着做<a href="/knowledgebase?thread=connect-and-verify">连接并验证</a>。客户端还没装的话，先看<a href="/knowledgebase?thread=install-client">安装客户端</a>。要了解转发和节点的配对规则，看<a href="/knowledgebase?thread=cloud-nodes-and-forward-pairing">云端节点与转发如何配对</a>。</p>
',
    updated_at = CURRENT_TIMESTAMP
WHERE locale = 'zh-Hans'
  AND topic_id = (SELECT id FROM knowledgebase_topics WHERE topic_key = 'getting-started-first-forward-checklist')
  AND SHA2(body_html, 256) = '05a39bd5cf9162a31f9aeb3f3182e2c6bb6c3829f3003af85ce9234468507ce2';

UPDATE knowledgebase_articles
SET body_html = '<p>一條轉發就是你在控制台上畫的一條線：你的遊戲或服務在哪裡，以及本機用哪個連接埠去連它。</p>

<h2>你將完成什麼</h2>
<p>一條儲存好的轉發，加上一個分配給你的本機連接埠。下一篇指南要用這個連接埠，先記下來。</p>

<h2>開始前準備</h2>
<ul>
<li>一個使用中的套餐，控制台才允許儲存轉發。</li>
<li>你的遊戲或服務真正監聽的位址和連接埠。</li>
<li>它需要哪種協定：TCP、UDP 還是兩者都要。遊戲一般是 UDP。</li>
</ul>

<h2>操作步驟</h2>
<ol>
<li><strong>開啟「我的服務」，在套餐卡片上點「新建轉發」。</strong>在服務詳情頁裡點「新建轉發」也是同一個表單。</li>
<li><strong>選擇線路。</strong>挑離目標最近的那條線路。所有線路都用 ultra，這裡不需要再決定別的。</li>
<li><strong>填寫目標位址和目標連接埠。</strong>這裡要填遊戲自己連的位址，不是你下載遊戲的網站位址。</li>
<li><strong>選擇協定</strong>，也就是你的服務需要的傳輸方式：TCP、UDP 或 TCP + UDP。</li>
<li><strong>點「新建轉發」儲存。</strong>本機監聽連接埠留空就行，系統會自動分配，除非你有特別的理由要指定。</li>
<li><strong>記下分配給你的本機連接埠。</strong>儲存後會回到服務詳情頁，新轉發出現在「轉發規則」表裡，「本機連接埠」一列就是它。</li>
</ol>
<pre class="mermaid">
flowchart TD
accTitle: 第一條轉發的表單路徑
accDescr: 開啟使用中的套餐，選擇線路，填寫目標和協定，儲存後記下分配的本機連接埠
A[開啟我的服務] --> B[點新建轉發]
B --> C[選擇線路]
C --> D[填寫目標位址和目標連接埠]
D --> E[選擇協定]
E --> F[儲存]
F --> G[記下分配的本機連接埠]
</pre>
<p>表單只問線路、目標、協定和本機連接埠，其餘都替你填好了。</p>
<figure><img src="/assets/knowledgebase/screenshots/tenant/02-forward-form-zh-tw.png" width="1440" height="960" alt="新建轉發表單，包含線路、目標位址、目標連接埠、本機監聽連接埠和協定"><figcaption>「新建轉發」表單，真正要你決定的只有四個欄位。</figcaption></figure>

<h2>如何確認成功</h2>
<p>在「轉發規則」表裡，這一行的狀態是「執行中」，「本機連接埠」已經有數字，「節點狀態」不再一直顯示「正在分配節點」。接著去「用戶端」頁面，確認準備承載這條轉發的裝置出現在「線上裝置」裡。</p>

<h2>如果沒有成功</h2>
<p>控制台不讓儲存時，<strong>先停止</strong>，別反覆點。三個常見原因：套餐已經到期；目標位址或連接埠填錯；你填的本機連接埠已經被佔用——清空它，讓系統重新分配一個。如果「線路」下拉框是空的，說明你的套餐還沒有開放線路，請聯絡客服。</p>

<h2>相關指南</h2>
<p>接著做<a href="/knowledgebase?thread=connect-and-verify">連線並驗證</a>。用戶端還沒裝的話，先看<a href="/knowledgebase?thread=install-client">安裝用戶端</a>。要瞭解轉發和節點的配對規則，看<a href="/knowledgebase?thread=cloud-nodes-and-forward-pairing">雲端節點與轉發如何配對</a>。</p>
',
    updated_at = CURRENT_TIMESTAMP
WHERE locale = 'zh-TW'
  AND topic_id = (SELECT id FROM knowledgebase_topics WHERE topic_key = 'getting-started-first-forward-checklist')
  AND SHA2(body_html, 256) = '3f13f835c38421403226a74b54ce3158ebbf6ceb10e5ee682b6cb3f8974bcd88';

UPDATE knowledgebase_articles
SET body_html = '<p>When a game cannot connect, it is almost always one of three things. Check them in this order and you are usually back in a minute or two.</p>

<h2>What you will accomplish</h2>
<p>Find which of the three usual causes is yours, fix it, and get back into the game.</p>

<h2>Before you begin</h2>
<ul>
<li>The panel open, and the app running on the device you play on.</li>
<li>The guide you followed, in case you need the local port again.</li>
</ul>

<h2>Steps</h2>
<ol>
<li><strong>Is the client online?</strong> The online devices list at the bottom of the Clients page should show this device. If it does not, restart the app, wait a few seconds and reload; if the online limit is full, kick a device you no longer use.</li>
<li><strong>Is the address correct?</strong> Same device as the client means 127.0.0.1 plus the local port; another device means the client host address.</li>
<li><strong>Is the port allowed?</strong> Only the port you actually use has to be open through the firewall, security group, or NAT.</li>
<li><strong>Are the forward and route healthy?</strong> In Forward Rules the row should read Running and Endpoint Status should not stay on "No endpoint assigned yet"; the target service should answer when you test it from its own host.</li>
<li><strong>Still stuck?</strong> Send support the forward ID, the device you used, and roughly when it failed.</li>
</ol>
<pre class="mermaid">
flowchart TD
accTitle: Working from the symptom
accDescr: Start from the failing game and check the client sync, the address, then the target service
A[The game cannot connect] --> B[Is the client in sync?]
B -- No --> C[Restart the app and try again]
B -- Yes --> D[Is the address and port correct?]
D -- No --> E[Use 127.0.0.1 or the client host address]
E --> C
D -- Yes --> F[Test the target service from its own host]
F --> G[Contact support with the forward ID]
</pre>
<p>Work down the list once, in order. Skipping a step usually means coming back to it later.</p>
<figure><img src="/assets/knowledgebase/screenshots/tenant/05-online-devices-en.png" width="1440" height="960" alt="Online devices list on the Clients page with each device IP, system and app version"><figcaption>Online devices lists every signed-in device; if yours is missing, the forward will not work.</figcaption></figure>

<h2>How to know it worked</h2>
<p>The app shows the line as running, and Usage this cycle in Forward Rules goes up again while you play.</p>

<h2>If it did not work</h2>
<p><strong>Stop</strong> before changing anything else. Do not change settings at random - it hides the real cause and makes the next round slower. If all four checks are clean, contact support; that is the fastest path from there.</p>

<h2>Related guides</h2>
<p><a href="/knowledgebase?thread=connect-and-verify">Connect and verify</a>, or <a href="/knowledgebase?thread=getting-started-first-forward-checklist">create your first forward</a> if the row itself looks wrong. If node pairing is the issue, read <a href="/knowledgebase?thread=cloud-nodes-and-forward-pairing">how cloud nodes pair with forwards</a>.</p>
',
    updated_at = CURRENT_TIMESTAMP
WHERE locale = 'en'
  AND topic_id = (SELECT id FROM knowledgebase_topics WHERE topic_key = 'troubleshooting-common-mistakes')
  AND SHA2(body_html, 256) = 'a0e3346059edf1746a23fbec08f2f3d50a52565e633d63832620acb1d3d88185';

UPDATE knowledgebase_articles
SET body_html = '<p>游戏连不上的时候，几乎都是这三个原因之一。按顺序查一遍，通常一两分钟就能回到游戏里。</p>

<h2>你将完成什么</h2>
<p>找出这三个常见原因里属于你的那一个，修好它，重新进游戏。</p>

<h2>开始前准备</h2>
<ul>
<li>打开控制台，并且保证你玩游戏那台设备上的客户端在运行。</li>
<li>你之前跟着做的那篇指南，万一要再查一次本地端口。</li>
</ul>

<h2>操作步骤</h2>
<ol>
<li><strong>客户端在线吗？</strong>「客户端」页面底部的「在线设备」里应该能看到这台设备。没有的话，重启客户端，等几秒再刷新页面；如果「最大在线」已经满了，先在列表里把不用的设备「踢下线」。</li>
<li><strong>地址是否正确？</strong>和客户端同一台设备就用 127.0.0.1 加本地端口；不同设备就用客户端主机地址。</li>
<li><strong>端口已放行吗？</strong>只需要放行你真正用的那个端口，防火墙、安全组或 NAT 上放行一条就够。</li>
<li><strong>转发和线路正常吗？</strong>在服务详情页的「转发规则」里，这一行的状态应该是「运行中」，「节点状态」不应该一直停在「正在分配节点」；从目标服务自己的主机测试目标服务时也应该有回应。</li>
<li><strong>还是不行？</strong>把转发的编号、用的是哪台设备、出问题的大概时间一起发给客服。</li>
</ol>
<pre class="mermaid">
flowchart TD
accTitle: 从症状开始排查
accDescr: 从游戏连不上开始，依次检查客户端在线、地址，最后检查目标服务
A[游戏连不上] --> B[客户端在线吗？]
B -- 否 --> C[重启客户端再试]
B -- 是 --> D[地址和端口正确吗？]
D -- 否 --> E[改用 127.0.0.1 或客户端主机地址]
E --> C
D -- 是 --> F[从目标服务自己的主机测试目标服务]
F --> G[带上转发编号联系客服]
</pre>
<p>从头到尾走一遍，别跳步。跳过的步骤通常最后还要回来补。</p>
<figure><img src="/assets/knowledgebase/screenshots/tenant/05-online-devices-zh-hans.png" width="1440" height="960" alt="客户端页面的在线设备列表，显示设备 IP、系统和客户端版本"><figcaption>「在线设备」列出当前登录的每台设备；设备不在这里，转发就不会通。</figcaption></figure>

<h2>如何确认成功</h2>
<p>客户端显示这条线路 running，你玩的时候「转发规则」里的「本期用量」又开始增加。</p>

<h2>如果没有成功</h2>
<p>在改别的东西之前，<strong>先停止</strong>。不要随手改一堆设置——那样只会盖住真正的原因，让下一次排查更慢。上面四项都没问题的话，直接联系客服，这是从这里出发最快的路。</p>

<h2>相关指南</h2>
<p>回到<a href="/knowledgebase?thread=connect-and-verify">连接并验证</a>；如果那一行本身看着就不对，看<a href="/knowledgebase?thread=getting-started-first-forward-checklist">创建第一条转发</a>。如果是节点配对导致的问题，看<a href="/knowledgebase?thread=cloud-nodes-and-forward-pairing">云端节点与转发如何配对</a>。</p>
',
    updated_at = CURRENT_TIMESTAMP
WHERE locale = 'zh-Hans'
  AND topic_id = (SELECT id FROM knowledgebase_topics WHERE topic_key = 'troubleshooting-common-mistakes')
  AND SHA2(body_html, 256) = '9e100872d402b537b8e74664c034b791bd9ca453380f60b867f63d8d4614c2f1';

UPDATE knowledgebase_articles
SET body_html = '<p>遊戲連不上的時候，幾乎都是這三個原因之一。按順序查一遍，通常一兩分鐘就能回到遊戲裡。</p>

<h2>你將完成什麼</h2>
<p>找出這三個常見原因裡屬於你的那一個，修好它，重新進遊戲。</p>

<h2>開始前準備</h2>
<ul>
<li>開啟控制台，並且保證你玩遊戲那臺裝置上的用戶端在執行。</li>
<li>你之前跟著做的那篇指南，萬一要再查一次本機連接埠。</li>
</ul>

<h2>操作步驟</h2>
<ol>
<li><strong>用戶端線上嗎？</strong>「用戶端」頁面底部的「線上裝置」裡應該能看到這臺裝置。沒有的話，重啟用戶端，等幾秒再重新整理頁面；如果「最大線上」已經滿了，先在列表裡把不用的裝置「踢下線」。</li>
<li><strong>位址是否正確？</strong>和用戶端同一臺裝置就用 127.0.0.1 加本機連接埠；不同裝置就用用戶端主機位址。</li>
<li><strong>連接埠已放行嗎？</strong>只需要放行你真正用的那個連接埠，防火牆、安全組或 NAT 上放行一條就夠。</li>
<li><strong>轉發和線路正常嗎？</strong>在服務詳情頁的「轉發規則」裡，這一行的狀態應該是「執行中」，「節點狀態」不應該一直停在「正在分配節點」；從目標服務自己的主機測試目標服務時也應該有回應。</li>
<li><strong>還是不行？</strong>把轉發的編號、用的是哪臺裝置、出問題的大概時間一起發給客服。</li>
</ol>
<pre class="mermaid">
flowchart TD
accTitle: 從症狀開始排查
accDescr: 從遊戲連不上開始，依次檢查用戶端線上、位址，最後檢查目標服務
A[遊戲連不上] --> B[用戶端線上嗎？]
B -- 否 --> C[重啟用戶端再試]
B -- 是 --> D[位址和連接埠正確嗎？]
D -- 否 --> E[改用 127.0.0.1 或用戶端主機位址]
E --> C
D -- 是 --> F[從目標服務自己的主機測試目標服務]
F --> G[帶上轉發編號聯絡客服]
</pre>
<p>從頭到尾走一遍，別跳步。跳過的步驟通常最後還要回來補。</p>
<figure><img src="/assets/knowledgebase/screenshots/tenant/05-online-devices-zh-tw.png" width="1440" height="960" alt="用戶端頁面的線上裝置列表，顯示裝置 IP、系統和用戶端版本"><figcaption>「線上裝置」列出當前登入的每臺裝置；裝置不在這裡，轉發就不會通。</figcaption></figure>

<h2>如何確認成功</h2>
<p>用戶端顯示這條線路 running，你玩的時候「轉發規則」裡的「本期用量」又開始增加。</p>

<h2>如果沒有成功</h2>
<p>在改別的東西之前，<strong>先停止</strong>。不要隨手改一堆設定——那樣只會蓋住真正的原因，讓下一次排查更慢。上面四項都沒問題的話，直接聯絡客服，這是從這裡出發最快的路。</p>

<h2>相關指南</h2>
<p>回到<a href="/knowledgebase?thread=connect-and-verify">連線並驗證</a>；如果那一行本身看著就不對，看<a href="/knowledgebase?thread=getting-started-first-forward-checklist">建立第一條轉發</a>。如果是節點配對導致的問題，看<a href="/knowledgebase?thread=cloud-nodes-and-forward-pairing">雲端節點與轉發如何配對</a>。</p>
',
    updated_at = CURRENT_TIMESTAMP
WHERE locale = 'zh-TW'
  AND topic_id = (SELECT id FROM knowledgebase_topics WHERE topic_key = 'troubleshooting-common-mistakes')
  AND SHA2(body_html, 256) = '89bec193388f329f1cae07ee1e5d854c936c543b4df929a17a69f2f3df693736';

