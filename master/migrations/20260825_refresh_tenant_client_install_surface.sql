-- Guarded tenant Client install guidance refresh. The cutoff keeps newer operator edits intact.
UPDATE knowledgebase_articles
SET title = 'Install the Client',
    excerpt = 'Install Windows or Linux and confirm concurrent online status.',
    body_html = '<h2>Install</h2><p>Open <strong>Tenant → Clients</strong>.</p><ol><li><strong>Windows:</strong> choose <strong>Download installer</strong> and sign in in the app.</li><li><strong>Linux:</strong> choose <strong>Copy command</strong> and run it once on the target host with root-equivalent permission. The command contains a secret token; never share it.</li><li>Return to the page and check <strong>Maximum online</strong>.</li></ol>',
    status = 'published',
    updated_at = CURRENT_TIMESTAMP
WHERE topic_id IN (SELECT id FROM knowledgebase_topics WHERE topic_key = 'install-client')
  AND locale = 'en'
  AND status = 'published'
  AND updated_at < '2026-08-25 00:00:00';

UPDATE knowledgebase_articles
SET title = '安装 Client',
    excerpt = '安装 Windows 或 Linux，并确认同时在线状态。',
    body_html = '<h2>安装</h2><p>打开 <strong>Tenant → Clients</strong>。</p><ol><li><strong>Windows：</strong>选择 <strong>下载 installer</strong>，然后在 App 中登录。</li><li><strong>Linux：</strong>选择 <strong>复制命令</strong>，以相当于 root 的权限在目标主机运行一次。命令包含秘密 token，不要分享。</li><li>返回页面查看 <strong>最大在线</strong>。</li></ol>',
    status = 'published',
    updated_at = CURRENT_TIMESTAMP
WHERE topic_id IN (SELECT id FROM knowledgebase_topics WHERE topic_key = 'install-client')
  AND locale = 'zh-Hans'
  AND status = 'published'
  AND updated_at < '2026-08-25 00:00:00';

UPDATE knowledgebase_articles
SET title = '安裝 Client',
    excerpt = '安裝 Windows 或 Linux，並確認同時在線狀態。',
    body_html = '<h2>安裝</h2><p>開啟 <strong>Tenant → Clients</strong>。</p><ol><li><strong>Windows：</strong>選擇 <strong>下載 installer</strong>，然後在 App 中登入。</li><li><strong>Linux：</strong>選擇 <strong>複製命令</strong>，以等同 root 的權限在目標主機執行一次。命令包含秘密 token，不要分享。</li><li>返回頁面查看 <strong>最大在線</strong>。</li></ol>',
    status = 'published',
    updated_at = CURRENT_TIMESTAMP
WHERE topic_id IN (SELECT id FROM knowledgebase_topics WHERE topic_key = 'install-client')
  AND locale = 'zh-TW'
  AND status = 'published'
  AND updated_at < '2026-08-25 00:00:00';
