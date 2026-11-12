<?php
declare(strict_types=1);

/**
 * Knowledgebase topics published outside the onboarding guide catalog.
 *
 * topics.php is pinned to the onboarding guides and their fixed section
 * template, so device-specific and reference articles live here instead and
 * are written with scripts/publish_knowledgebase_topics.php rather than a seed
 * migration. Catalog guides may link to these keys.
 */
return [
    'ios-testflight' => [
        'is_pinned' => 0,
        'sort_order' => 25,
        'articles' => [
            'en' => [
                'title' => 'Install the iOS App with TestFlight',
                'excerpt' => 'Request the TestFlight invite on the Clients page, install the app on iPhone or iPad, and sign in with your Client ID and Agent Token.',
                'body' => 'en/ios-testflight.html',
            ],
            'zh-Hans' => [
                'title' => '通过 TestFlight 安装 iOS 版',
                'excerpt' => '在「客户端」页面申请 TestFlight 邀请，在 iPhone 或 iPad 上安装 App，再用客户端 ID 和 Agent Token 登录。',
                'body' => 'zh-Hans/ios-testflight.html',
            ],
            'zh-TW' => [
                'title' => '透過 TestFlight 安裝 iOS 版',
                'excerpt' => '在「用戶端」頁面申請 TestFlight 邀請，在 iPhone 或 iPad 上安裝 App，再用客戶端 ID 和 Agent Token 登入。',
                'body' => 'zh-TW/ios-testflight.html',
            ],
        ],
    ],
];
