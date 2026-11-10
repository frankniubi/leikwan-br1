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
<tr><td>Forward A</td><td>east.nbwly.com:30522</td><td>SS2022</td><td>Password X</td><td>—</td></tr>
<tr><td>Forward B</td><td>east.nbwly.com:30522</td><td>SS2022</td><td>Password Y (≠X)</td><td><strong>No</strong> — password differs → create new forward B and node B</td></tr>
<tr><td>Forward C</td><td>east.nbwly.com:30522</td><td>VLESS</td><td>Password X</td><td><strong>No</strong> — protocol differs → create new forward C and node C</td></tr>
<tr><td>Forward D</td><td>east.nbwly.com:30523</td><td>SS2022</td><td>Password X</td><td><strong>No</strong> — port differs → create new forward D and node D</td></tr>
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
<tr><td>转发 A</td><td>east.nbwly.com:30522</td><td>SS2022</td><td>密码 X</td><td>—</td></tr>
<tr><td>转发 B</td><td>east.nbwly.com:30522</td><td>SS2022</td><td>密码 Y（≠X）</td><td><strong>不能</strong>，密码不同 → 必须新建转发 B 和节点 B</td></tr>
<tr><td>转发 C</td><td>east.nbwly.com:30522</td><td>VLESS</td><td>密码 X</td><td><strong>不能</strong>，协议不同 → 必须新建转发 C 和节点 C</td></tr>
<tr><td>转发 D</td><td>east.nbwly.com:30523</td><td>SS2022</td><td>密码 X</td><td><strong>不能</strong>，端口不同 → 必须新建转发 D 和节点 D</td></tr>
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
<tr><td>轉發 A</td><td>east.nbwly.com:30522</td><td>SS2022</td><td>密碼 X</td><td>—</td></tr>
<tr><td>轉發 B</td><td>east.nbwly.com:30522</td><td>SS2022</td><td>密碼 Y（≠X）</td><td><strong>不能</strong>，密碼不同 → 必須新建轉發 B 和節點 B</td></tr>
<tr><td>轉發 C</td><td>east.nbwly.com:30522</td><td>VLESS</td><td>密碼 X</td><td><strong>不能</strong>，協議不同 → 必須新建轉發 C 和節點 C</td></tr>
<tr><td>轉發 D</td><td>east.nbwly.com:30523</td><td>SS2022</td><td>密碼 X</td><td><strong>不能</strong>，連接埠不同 → 必須新建轉發 D 和節點 D</td></tr>
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
