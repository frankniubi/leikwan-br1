<?php
declare(strict_types=1);

use Leikwan\Master\Support\MermaidSourcePolicy;

require_once __DIR__ . '/../src/Support/MermaidSourcePolicy.php';

function failCatalog(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertCatalog(bool $condition, string $message): void
{
    if (!$condition) {
        failCatalog($message);
    }
}

function assertCatalogSame(mixed $expected, mixed $actual, string $message): void
{
    if ($actual !== $expected) {
        failCatalog($message . '; expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/** @return array<int, DOMElement> */
function catalogElementsByTag(string $html, string $tagName): array
{
    $document = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    try {
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><!doctype html><html><body><div id="catalog-root">'
                . $html
                . '</div></body></html>',
            LIBXML_HTML_NODEFDTD
        );
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    assertCatalog($loaded === true, 'article HTML must parse');

    $root = $document->getElementById('catalog-root');
    assertCatalog($root instanceof DOMElement, 'article HTML wrapper must parse');

    $elements = [];
    foreach ($root->getElementsByTagName($tagName) as $element) {
        if ($element instanceof DOMElement) {
            $elements[] = $element;
        }
    }
    return $elements;
}

/**
 * @return array{nodes: array<string, string>, edges: array<int, string>, labels: array<string, string>}
 */
function catalogMermaidGraph(string $source, string $context): array
{
    $nodes = [];
    $edges = [];
    $labels = [];
    foreach (preg_split('/\R/u', $source) ?: [] as $line) {
        $line = trim($line);
        if (preg_match('/^([A-Z])(?:\[([^\]]+)\]|\{([^}]+)\})?\s+(?:-->|--\s+(.+?)\s+-->)\s+([A-Z])(?:\[([^\]]+)\]|\{([^}]+)\})?$/u', $line, $match) !== 1) {
            continue;
        }

        $from = $match[1];
        $to = $match[5];
        $fromLabel = trim(($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? ''));
        $toLabel = trim(($match[6] ?? '') !== '' ? $match[6] : ($match[7] ?? ''));
        if ($fromLabel !== '') {
            assertCatalog(!isset($nodes[$from]) || $nodes[$from] === $fromLabel, $context . ': conflicting Mermaid node ' . $from);
            $nodes[$from] = $fromLabel;
        }
        if ($toLabel !== '') {
            assertCatalog(!isset($nodes[$to]) || $nodes[$to] === $toLabel, $context . ': conflicting Mermaid node ' . $to);
            $nodes[$to] = $toLabel;
        }

        $edge = $from . '>' . $to;
        $edges[] = $edge;
        if (isset($match[4]) && trim($match[4]) !== '') {
            $labels[$edge] = trim($match[4]);
        }
    }

    return ['nodes' => $nodes, 'edges' => $edges, 'labels' => $labels];
}

$manifestPath = __DIR__ . '/../resources/knowledgebase/topics.php';
assertCatalog(is_file($manifestPath), 'knowledgebase topic manifest is missing');
$topics = require $manifestPath;
assertCatalog(is_array($topics), 'knowledgebase topic manifest must return an array');

$expectedLocales = ['en', 'zh-Hans', 'zh-TW'];
$expectedHeadings = [
    'en' => [
        'What you will accomplish',
        'Before you begin',
        'Steps',
        'How to know it worked',
        'If it did not work',
        'Related guides',
    ],
    'zh-Hans' => [
        '你将完成什么',
        '开始前准备',
        '操作步骤',
        '如何确认成功',
        '如果没有成功',
        '相关指南',
    ],
    'zh-TW' => [
        '你將完成什麼',
        '開始前準備',
        '操作步驟',
        '如何確認成功',
        '如果沒有成功',
        '相關指南',
    ],
];
$expectedSectionRoles = ['outcome', 'prerequisites', 'steps', 'success', 'failure', 'related'];
$headingRoles = [];
foreach ($expectedHeadings as $locale => $localeHeadings) {
    $headingRoles[$locale] = array_combine($localeHeadings, $expectedSectionRoles);
}
$expectedTopics = [
    'getting-started-five-minute-overview' => [
        'is_pinned' => 1,
        'sort_order' => 10,
        'en' => [
            'title' => 'Get Connected in Five Minutes',
            'excerpt' => 'Follow the shortest path from an active package to a verified connection.',
        ],
        'zh-Hans' => [
            'title' => '五分钟完成首次连接',
            'excerpt' => '按最短路径完成套餐、转发、客户端安装和连接验证。',
        ],
        'zh-TW' => [
            'title' => '五分鐘完成首次連線',
            'excerpt' => '按最短路徑完成方案、轉發、用戶端安裝和連線驗證。',
        ],
    ],
    'install-client' => [
        'is_pinned' => 0,
        'sort_order' => 20,
        'en' => [
            'title' => 'Install the Client',
            'excerpt' => 'Install and sign in on Windows, Android, or Linux, then confirm the client is online.',
        ],
        'zh-Hans' => [
            'title' => '安装客户端',
            'excerpt' => '在 Windows、Android 或 Linux 上安装并登录，然后确认客户端在线。',
        ],
        'zh-TW' => [
            'title' => '安裝用戶端',
            'excerpt' => '在 Windows、Android 或 Linux 上安裝並登入，然後確認用戶端在線。',
        ],
    ],
    'getting-started-first-forward-checklist' => [
        'is_pinned' => 0,
        'sort_order' => 30,
        'en' => [
            'title' => 'Create Your First Forward',
            'excerpt' => 'Choose a route package, enter the destination, save, and identify the assigned local port.',
        ],
        'zh-Hans' => [
            'title' => '创建第一条转发',
            'excerpt' => '选择线路方案、填写目标地址并记下系统分配的本地端口。',
        ],
        'zh-TW' => [
            'title' => '建立第一條轉發',
            'excerpt' => '選擇線路方案、填寫目標位址並記下系統分配的本機連接埠。',
        ],
    ],
    'connect-and-verify' => [
        'is_pinned' => 0,
        'sort_order' => 40,
        'en' => [
            'title' => 'Connect and Verify',
            'excerpt' => 'Choose the correct client address, test the service, and confirm that the route is working.',
        ],
        'zh-Hans' => [
            'title' => '连接并验证',
            'excerpt' => '选择正确的客户端地址，测试服务并确认转发已经工作。',
        ],
        'zh-TW' => [
            'title' => '連線並驗證',
            'excerpt' => '選擇正確的用戶端位址，測試服務並確認轉發已經運作。',
        ],
    ],
    'troubleshooting-common-mistakes' => [
        'is_pinned' => 0,
        'sort_order' => 50,
        'en' => [
            'title' => 'Troubleshoot Common Problems',
            'excerpt' => 'Start from the symptom you can see and follow the shortest safe recovery path.',
        ],
        'zh-Hans' => [
            'title' => '排查常见问题',
            'excerpt' => '从看得见的症状开始，按最短且安全的顺序恢复连接。',
        ],
        'zh-TW' => [
            'title' => '排查常見問題',
            'excerpt' => '從看得見的症狀開始，按最短且安全的順序恢復連線。',
        ],
    ],
    'glossary' => [
        'is_pinned' => 0,
        'sort_order' => 60,
        'en' => [
            'title' => 'Glossary',
            'excerpt' => 'Plain-language definitions for the product and network terms used in the guides.',
        ],
        'zh-Hans' => [
            'title' => '名词解释',
            'excerpt' => '用通俗语言解释指南中出现的产品和网络术语。',
        ],
        'zh-TW' => [
            'title' => '名詞解釋',
            'excerpt' => '用淺白語言解釋指南中出現的產品和網路術語。',
        ],
    ],
    'no-gui-clients' => [
        'is_pinned' => 0,
        'sort_order' => 70,
        'en' => [
            'title' => 'Run the Client Without a GUI',
            'excerpt' => 'Install the windowless client on Windows or macOS as a service or daemon, verify it, and remove it again.',
        ],
        'zh-Hans' => [
            'title' => '无界面运行客户端',
            'excerpt' => '在 Windows 或 macOS 上把无界面客户端安装为服务或守护进程，验证并卸载。',
        ],
        'zh-TW' => [
            'title' => '無介面執行用戶端',
            'excerpt' => '在 Windows 或 macOS 上把無介面用戶端安裝為服務或守護程序，驗證並移除。',
        ],
    ],
];

assertCatalogSame(array_keys($expectedTopics), array_keys($topics), 'topic keys and order must be exact');
assertCatalogSame(7, count($topics), 'catalog must contain exactly seven topics');

$requiredArticleText = [
    'getting-started-five-minute-overview' => [
        'ultra',
        'local port',
        '127.0.0.1',
        'running',
        'active plan',
        'Clients page',
    ],
    'install-client' => [
        'Download Windows App',
        'Copy Install Command',
        'arm64 Android app',
        'Android 8.0+',
        'Connection successful!',
        'Connected',
        'Running',
        'Clients page',
        'recent sync',
    ],
    'getting-started-first-forward-checklist' => [
        'Forward Route',
        'Target Host',
        'Target Port',
        'Local Listen Port',
        'Transport',
        'TCP',
        'TCP + UDP',
        'UDP',
        'Save',
        'assigned local port',
        'provisioning',
        'recent client sync',
    ],
    'connect-and-verify' => [
        '127.0.0.1',
        'reachable Windows or Linux client-host address',
        'firewall',
        'security group',
        'NAT',
        'Android',
        'loopback-only',
        'target logs',
        'actual application protocol',
    ],
    'troubleshooting-common-mistakes' => [
        'recent sync',
        'address correct',
        'port allowed',
        'forward and route',
        'target service from its own host',
        'View Diagnostics',
        'support',
        'random topology changes',
    ],
    'glossary' => [
        'Active plan',
        'Forward',
        'Forward Route',
        'Target Host',
        'Target Port',
        'Local Listen Port',
        'Transport',
        'Client',
        'Running',
        'Recent sync',
        'Ultra',
        'Application verification',
    ],
    'no-gui-clients' => [
        'Client ID',
        'Agent Token',
        'stays switched on',
        'Applications',
        'leikwan status',
        'leikwan restart',
        'leikwan update',
        'leikwan update --check',
        'leikwan logs -n 50',
        'leikwan doctor',
        'leikwan stop',
        'online',
    ],
];

$forbiddenFirstForwardText = [
    'en' => ['Connection Mode', 'Forward Combination', 'slave', 'leg', 'runtime'],
    'zh-Hans' => ['连接模式', '转发组合', 'Slave', '链路', '运行时'],
    'zh-TW' => ['連線模式', '轉發組合', 'Slave', '鏈路', '執行階段'],
];
$requiredLocalizedArticleText = [
    'zh-Hans' => [
        'getting-started-five-minute-overview' => ['ultra', '线路', '转发', '客户端', '本机端口', '127.0.0.1', 'running', '套餐'],
        'install-client' => ['Windows', 'Android', 'Linux', 'Download Windows App', 'Copy Install Command', 'arm64', 'Android 8.0 以上', 'Connection successful!', 'Connected', 'Running', '客户端', '最近同步', '面板邮箱和密码'],
        'getting-started-first-forward-checklist' => ['Create Forward', 'Forward Route', 'Target Host', 'Target Port', 'Local Listen Port', 'Transport', 'TCP', 'TCP + UDP', 'UDP', '保存', '预配', '最近同步', '有效套餐'],
        'connect-and-verify' => ['127.0.0.1', '可访问的 Windows 或 Linux 客户端主机地址', '防火墙', '安全组', 'NAT', 'Android', '只监听回环地址', '实际应用协议', '目标日志'],
        'troubleshooting-common-mistakes' => ['最近同步', '地址和端口正确吗', '端口已放行', '转发和线路', '从目标服务自己的主机测试目标服务', 'View Diagnostics', '支持', '随机修改拓扑', '预配中'],
        'glossary' => ['有效套餐', '转发', 'Forward Route', 'Target Host', 'Target Port', 'Local Listen Port', 'Transport', '客户端', 'Running', '最近同步', 'ultra', '应用验证'],
        'no-gui-clients' => ['Client ID', 'Agent Token', '长期开机', '应用程序', 'leikwan status', 'leikwan restart', 'leikwan update --check', 'leikwan logs -n 50', 'leikwan doctor', 'leikwan stop', '在线'],
    ],
    'zh-TW' => [
        'getting-started-five-minute-overview' => ['ultra', '線路', '轉發', '用戶端', '本機連接埠', '127.0.0.1', 'running', '方案'],
        'install-client' => ['Windows', 'Android', 'Linux', 'Download Windows App', 'Copy Install Command', 'arm64', 'Android 8.0 以上', 'Connection successful!', 'Connected', 'Running', '用戶端', '最近同步', '面板電子郵件和密碼'],
        'getting-started-first-forward-checklist' => ['Create Forward', 'Forward Route', 'Target Host', 'Target Port', 'Local Listen Port', 'Transport', 'TCP', 'TCP + UDP', 'UDP', '儲存', '佈建', '最近同步', '有效方案'],
        'connect-and-verify' => ['127.0.0.1', '可連到的 Windows 或 Linux 用戶端主機位址', '防火牆', '安全群組', 'NAT', 'Android', '只監聽迴送位址', '實際應用協定', '目標服務記錄'],
        'troubleshooting-common-mistakes' => ['最近同步', '位址和連接埠正確嗎', '連接埠已開放', '轉發和線路', '從目標服務所在主機測試目標服務', 'View Diagnostics', '支援', '隨意變更拓撲', '佈建中'],
        'glossary' => ['有效方案', '轉發', 'Forward Route', 'Target Host', 'Target Port', 'Local Listen Port', 'Transport', '用戶端', 'Running', '最近同步', 'ultra', '應用程式驗證'],
        'no-gui-clients' => ['Client ID', 'Agent Token', '長期開機', '應用程式', 'leikwan status', 'leikwan restart', 'leikwan update --check', 'leikwan logs -n 50', 'leikwan doctor', 'leikwan stop', '在線'],
    ],
];

$forbiddenLocalizedArticleText = [
    'zh-Hans' => [
        'install-client' => ['Android 保证支持 Race', 'Android 保证支持 MPTCP'],
        'connect-and-verify' => ['任意公网 IP 都可用', 'Android 跨设备监听'],
    ],
    'zh-TW' => [
        'install-client' => ['Android 保證支援 Race', 'Android 保證支援 MPTCP'],
        'connect-and-verify' => ['任何公用 IP 都可以使用', 'Android 跨裝置監聽'],
    ],
];

$localizedDiagramRequirements = [
    'zh-Hans' => [
        'getting-started-five-minute-overview' => ['从套餐到可用线路', '打开面板', '创建一条转发', '安装应用', '登录并连接', '把游戏指向本机端口', '开始游戏'],
        'install-client' => ['客户端安装路径', '选择设备', '下载 Windows 应用', '安装 arm64 Android 应用', '复制 Linux 安装命令', '客户端显示 Connected 和 Running'],
        'getting-started-first-forward-checklist' => ['第一条转发的表单路径', '打开有效套餐', '选择 Forward Route', '填写 Target Host 和 Target Port', '选择 Transport', '记下分配的本地端口'],
        'connect-and-verify' => ['选择客户端连接地址', '游戏在哪台设备上运行？', '与客户端同机', '使用 127.0.0.1 和本地端口', '另一台设备', '使用客户端主机地址'],
        'troubleshooting-common-mistakes' => ['从症状开始排查', '客户端同步正常吗？', '重启客户端再试', '改用 127.0.0.1 或客户端主机地址', '从目标服务自己的主机测试目标服务', '打开 View Diagnostics 并联系支持'],
        'no-gui-clients' => ['不用屏幕的电脑设置流程', '选择电脑', '哪个系统', '先装应用，再运行一条命令', '解压下载包，再运行一条命令', '确认显示 running', '在面板看到设备在线'],
    ],
    'zh-TW' => [
        'getting-started-five-minute-overview' => ['從方案到可用線路', '打開面板', '建立一條轉發', '安裝應用程式', '登入並連線', '把遊戲指向本機連接埠', '開始遊戲'],
        'install-client' => ['用戶端安裝路徑', '選擇裝置', '下載 Windows App', '安裝 arm64 Android App', '複製 Linux 安裝指令', '用戶端顯示 Connected 和 Running'],
        'getting-started-first-forward-checklist' => ['第一條轉發的表單路徑', '開啟有效方案', '選擇 Forward Route', '填寫 Target Host 和 Target Port', '選擇 Transport', '記下分配的本機連接埠'],
        'connect-and-verify' => ['選擇用戶端連線位址', '遊戲在哪台裝置上執行？', '與用戶端同機', '使用 127.0.0.1 和本機連接埠', '另一台裝置', '使用用戶端主機位址'],
        'troubleshooting-common-mistakes' => ['從症狀開始排查', '用戶端同步正常嗎？', '重新啟動用戶端再試', '改用 127.0.0.1 或用戶端主機位址', '從目標服務所在主機測試目標服務', '開啟 View Diagnostics 並聯絡支援'],
        'no-gui-clients' => ['不用螢幕的電腦設定流程', '選擇電腦', '哪個系統', '先裝應用程式，再執行一條指令', '解壓縮下載包，再執行一條指令', '確認顯示 running', '在面板看到裝置在線'],
    ],
];
$allTopicKeys = array_keys($expectedTopics);
$totalDiagrams = 0;
$diagramGraphs = [];

foreach ($topics as $topicKey => $topic) {
    assertCatalog(is_array($topic), $topicKey . ': topic entry must be an array');
    assertCatalogSame(
        ['is_pinned', 'sort_order', 'articles'],
        array_keys($topic),
        $topicKey . ': topic fields must be exact'
    );
    assertCatalogSame($expectedTopics[$topicKey]['is_pinned'], $topic['is_pinned'], $topicKey . ': pinned flag');
    assertCatalogSame($expectedTopics[$topicKey]['sort_order'], $topic['sort_order'], $topicKey . ': sort order');
    assertCatalog(is_array($topic['articles']), $topicKey . ': articles must be an array');

    foreach ($expectedLocales as $locale) {
        assertCatalog(isset($topic['articles'][$locale]) && is_array($topic['articles'][$locale]), $topicKey . ': missing ' . $locale . ' metadata');
        $article = $topic['articles'][$locale];
        assertCatalogSame(['title', 'excerpt', 'body'], array_keys($article), $topicKey . ': ' . $locale . ' metadata fields must be exact');
        assertCatalogSame($expectedTopics[$topicKey][$locale]['title'], $article['title'], $topicKey . ': ' . $locale . ' title');
        assertCatalogSame($expectedTopics[$topicKey][$locale]['excerpt'], $article['excerpt'], $topicKey . ': ' . $locale . ' excerpt');
        assertCatalogSame($locale . '/' . $topicKey . '.html', $article['body'], $topicKey . ': ' . $locale . ' body path');

        $bodyPath = __DIR__ . '/../resources/knowledgebase/' . $article['body'];
        assertCatalog(is_file($bodyPath) && is_readable($bodyPath), $topicKey . ': missing or unreadable ' . $locale . ' body file');
        $body = file_get_contents($bodyPath);
        assertCatalog(is_string($body) && trim($body) !== '', $topicKey . ': empty ' . $locale . ' body');
        assertCatalog(preg_match('//u', $body) === 1, $topicKey . ': body must be valid UTF-8');

        foreach (['English:', '中文：', '中文繁體：', '<script', 'onclick', '<iframe', 'cdn.jsdelivr.net', 'unpkg.com/mermaid', 'mermaid@'] as $forbidden) {
            assertCatalog(!str_contains(strtolower($body), strtolower($forbidden)), $topicKey . ': forbidden body text ' . $forbidden);
        }
        assertCatalog(preg_match('/\b(?:token|password|route key|private key|session material)\s*[:=]\s*\S+/i', strip_tags($body)) !== 1, $topicKey . ': body appears to expose a secret value');

        $h2Elements = catalogElementsByTag($body, 'h2');
        $headings = array_map(static fn (DOMElement $heading): string => trim($heading->textContent), $h2Elements);
        assertCatalogSame($expectedHeadings[$locale], $headings, $topicKey . ': ' . $locale . ' h2 headings must appear exactly once and in order');
        $normalizedRoles = array_map(static fn (string $heading): string => $headingRoles[$locale][$heading], $headings);
        assertCatalogSame($expectedSectionRoles, $normalizedRoles, $topicKey . ': ' . $locale . ' section roles must match every language');

        assertCatalog(str_contains($body, '<ol>'), $topicKey . ': actions must be presented as short ordered steps');
        if ($topicKey !== 'glossary') {
            $images = catalogElementsByTag($body, 'img');
            assertCatalog($images !== [], $topicKey . ': operation screenshot is required');
            foreach ($images as $image) {
                $src = $image->getAttribute('src');
                assertCatalog(
                    preg_match('~^/assets/knowledgebase/screenshots/tenant/[0-9]{2}-[a-z-]+\.png$~D', $src) === 1,
                    $topicKey . ': invalid screenshot path ' . $src
                );
                assertCatalog(trim($image->getAttribute('alt')) !== '', $topicKey . ': screenshot alt text is required');
                assertCatalog($image->hasAttribute('width') && $image->hasAttribute('height'), $topicKey . ': screenshot dimensions are required');
                $imagePath = __DIR__ . '/../public' . $src;
                assertCatalog(is_file($imagePath) && filesize($imagePath) > 0, $topicKey . ': screenshot file is missing ' . $src);
            }
        }
        if ($locale === 'en') {
            assertCatalog(str_contains($body, '<strong>Stop'), $topicKey . ': failure guidance must include a safe stop');
            foreach ($requiredArticleText[$topicKey] as $requiredText) {
                assertCatalog(str_contains($body, $requiredText), $topicKey . ': missing required beginner guidance: ' . $requiredText);
            }
        } else {
            assertCatalog(str_contains($body, '<strong>先停止') || str_contains($body, '<strong>請先停止'), $topicKey . ': ' . $locale . ' failure guidance must include a safe stop');
            if ($locale === 'zh-Hans') {
                assertCatalog(str_contains($body, '连接') && str_contains($body, '转发') && str_contains($body, '客户端'), $topicKey . ': zh-Hans must naturally use representative terminology');
                foreach (['連線', '轉發', '用戶端'] as $forbiddenTerm) {
                    assertCatalog(!str_contains(strip_tags($body), $forbiddenTerm), $topicKey . ': zh-Hans contains Traditional-only prose: ' . $forbiddenTerm);
                }
            } else {
                assertCatalog(str_contains($body, '連線') && str_contains($body, '轉發') && str_contains($body, '用戶端'), $topicKey . ': zh-TW must naturally use representative terminology');
                foreach (['连接', '转发', '客户端'] as $forbiddenTerm) {
                    assertCatalog(!str_contains(strip_tags($body), $forbiddenTerm), $topicKey . ': zh-TW contains Simplified-only prose: ' . $forbiddenTerm);
                }
            }
            foreach ($requiredLocalizedArticleText[$locale][$topicKey] as $requiredText) {
                assertCatalog(str_contains($body, $requiredText), $topicKey . ': ' . $locale . ' missing required factual guidance: ' . $requiredText);
            }
            foreach ($forbiddenLocalizedArticleText[$locale][$topicKey] ?? [] as $forbiddenText) {
                assertCatalog(!str_contains($body, $forbiddenText), $topicKey . ': ' . $locale . ' contains prohibited claim: ' . $forbiddenText);
            }
        }

        $diagrams = MermaidSourcePolicy::extract($body);
        $expectedDiagramCount = $topicKey === 'glossary' ? 0 : 1;
        assertCatalogSame($expectedDiagramCount, count($diagrams), $topicKey . ': Mermaid diagram count');
        MermaidSourcePolicy::assertPreviewable($body);
        $totalDiagrams += count($diagrams);

        if ($diagrams !== []) {
            assertCatalog(str_starts_with($diagrams[0], "flowchart TD\n"), $topicKey . ': Mermaid direction');
            assertCatalog(preg_match('/^accTitle:\s*\S.+$/m', $diagrams[0]) === 1, $topicKey . ': Mermaid accTitle');
            assertCatalog(preg_match('/^accDescr:\s*\S.+$/m', $diagrams[0]) === 1, $topicKey . ': Mermaid accDescr');

            $graph = catalogMermaidGraph($diagrams[0], $topicKey . ': ' . $locale);
            $diagramGraphs[$topicKey][$locale] = $graph;
            if ($locale !== 'en') {
                assertCatalogSame($diagramGraphs[$topicKey]['en']['edges'], $graph['edges'], $topicKey . ': ' . $locale . ' Mermaid edges must match English topology');
                assertCatalogSame(array_keys($diagramGraphs[$topicKey]['en']['nodes']), array_keys($graph['nodes']), $topicKey . ': ' . $locale . ' Mermaid nodes must match English topology');
                assertCatalogSame(array_keys($diagramGraphs[$topicKey]['en']['labels']), array_keys($graph['labels']), $topicKey . ': ' . $locale . ' Mermaid branch labels must match English topology');
                foreach ($localizedDiagramRequirements[$locale][$topicKey] as $localizedText) {
                    assertCatalog(str_contains($diagrams[0], $localizedText), $topicKey . ': ' . $locale . ' Mermaid text is not fully localized: ' . $localizedText);
                }
                foreach (['accTitle: Five', 'accDescr: Start', 'Active package', 'Create one Forward', 'Choose device', 'Operating system', 'Locate the application', 'What outcome do you need', 'Target service', 'Application request failed', 'Recent sync?'] as $englishDiagramText) {
                    assertCatalog(!str_contains($diagrams[0], $englishDiagramText), $topicKey . ': ' . $locale . ' Mermaid contains English user-facing text: ' . $englishDiagramText);
                }
            }

            $preElements = catalogElementsByTag($body, 'pre');
            assertCatalogSame(1, count($preElements), $topicKey . ': must have one pre element');
            assertCatalogSame('mermaid', $preElements[0]->getAttribute('class'), $topicKey . ': Mermaid must use the exact trusted class');
            $next = $preElements[0]->nextSibling;
            while ($next !== null && !$next instanceof DOMElement) {
                $next = $next->nextSibling;
            }
            assertCatalog($next instanceof DOMElement && strtolower($next->tagName) === 'p' && trim($next->textContent) !== '', $topicKey . ': ordinary prose must immediately explain the diagram conclusion');
        }

        $linkedTopicKeys = [];
        foreach (catalogElementsByTag($body, 'a') as $link) {
            $href = $link->getAttribute('href');
            assertCatalog(preg_match('/^\/knowledgebase\?thread=([a-z0-9-]+)$/D', $href, $match) === 1, $topicKey . ': invalid guide link ' . $href);
            $linkedTopicKey = $match[1];
            assertCatalog(in_array($linkedTopicKey, $allTopicKeys, true), $topicKey . ': unknown related guide link ' . $linkedTopicKey);
            $linkedTopicKeys[] = $linkedTopicKey;
        }
        if ($topicKey !== 'glossary') {
            assertCatalog($linkedTopicKeys !== [], $topicKey . ': must link at least one related guide');
        }
    }
}

assertCatalogSame(21, count($topics) * count($expectedLocales), 'catalog must contain 21 localized articles');
assertCatalogSame(18, $totalDiagrams, 'catalog must preserve exactly 18 Mermaid diagrams across 21 articles');
foreach ($expectedLocales as $locale) {
    assertCatalogSame(6, count(array_filter(
        $diagramGraphs,
        static fn (array $localizedGraphs): bool => isset($localizedGraphs[$locale])
    )), 'catalog must preserve six Mermaid diagrams for ' . $locale);
    $glossaryBody = file_get_contents(__DIR__ . '/../resources/knowledgebase/' . $locale . '/glossary.html');
    assertCatalog(is_string($glossaryBody), $locale . ' glossary body must be readable');
    assertCatalogSame(0, count(MermaidSourcePolicy::extract($glossaryBody)), $locale . ' glossary must not contain a Mermaid diagram');
}

$firstForwardBody = file_get_contents(__DIR__ . '/../resources/knowledgebase/en/getting-started-first-forward-checklist.html');
assertCatalog(is_string($firstForwardBody), 'first Forward body must be readable');
foreach ($forbiddenFirstForwardText as $locale => $forbiddenTerms) {
    $firstForwardBody = file_get_contents(__DIR__ . '/../resources/knowledgebase/' . $locale . '/getting-started-first-forward-checklist.html');
    assertCatalog(is_string($firstForwardBody), $locale . ' first Forward body must be readable');
    foreach ($forbiddenTerms as $forbidden) {
        assertCatalog(!str_contains(strip_tags($firstForwardBody), $forbidden), $locale . ' first Forward guide must not expose ' . $forbidden);
    }
}

$generatorPath = __DIR__ . '/../scripts/build_knowledgebase_seed.php';
assertCatalog(is_file($generatorPath) && is_readable($generatorPath), 'knowledgebase seed generator is missing');
$generator = file_get_contents($generatorPath);
assertCatalog(is_string($generator) && trim($generator) !== '', 'knowledgebase seed generator is empty');
foreach ([
    "['en', 'zh-Hans', 'zh-TW']",
    'exactly 7 topics',
    'all required locale metadata and body files',
    '--output=',
    '20260729_seed_multilingual_knowledgebase.sql',
    'CURRENT_TIMESTAMP',
    'str_replace("\'", "\'\'", $value)',
    'INSERT INTO knowledgebase_topics',
    'INSERT INTO knowledgebase_articles',
    'FROM knowledgebase_threads AS legacy',
    'FROM knowledgebase_topics AS existing_topic',
    'FROM knowledgebase_articles AS existing_article',
    'WHERE NOT EXISTS',
    'topic.topic_key COLLATE utf8mb4_general_ci = legacy.slug COLLATE utf8mb4_general_ci',
    'legacy.is_pinned, legacy.sort_order, legacy.created_at, legacy.updated_at',
    "topic.id, 'en', legacy.author_user_id, legacy.title, legacy.excerpt, legacy.body_html",
    'legacy.status, legacy.published_at, legacy.created_at, legacy.updated_at',
    "existing_article.locale = 'en'",
    "legacy.slug NOT IN ('getting-started-first-forward-checklist', 'connection-modes-single-race-bond', 'bond-sidecar-setup', 'troubleshooting-common-mistakes')",
] as $requiredGeneratorText) {
    assertCatalog(str_contains($generator, $requiredGeneratorText), 'generator contract missing: ' . $requiredGeneratorText);
}
foreach (['UTC_TIMESTAMP', 'ON DUPLICATE KEY', 'ON CONFLICT', 'INSERT OR IGNORE', 'UPDATE knowledgebase_articles', 'DELETE FROM knowledgebase_threads', 'UPDATE knowledgebase_threads', 'DROP TABLE knowledgebase_threads', 'build timestamp'] as $forbiddenGeneratorText) {
    assertCatalog(!str_contains($generator, $forbiddenGeneratorText), 'generator contains forbidden SQL or nondeterminism: ' . $forbiddenGeneratorText);
}

assertCatalogSame(2, substr_count($generator, 'foreach ($requiredLocales as $locale)'), 'generator must validate and emit every required locale');
assertCatalogSame(18, 6 * 3, 'generator default article count contract');

echo "ok\n";
