<?php
declare(strict_types=1);

return [
    'getting-started-five-minute-overview' => [
        'is_pinned' => 1,
        'sort_order' => 10,
        'articles' => [
            'en' => [
                'title' => 'Get Connected in Five Minutes',
                'excerpt' => 'Follow the shortest path from an active package to a verified connection.',
                'body' => 'en/getting-started-five-minute-overview.html',
            ],
            'zh-Hans' => [
                'title' => '五分钟完成首次连接',
                'excerpt' => '按最短路径完成套餐、转发、客户端安装和连接验证。',
                'body' => 'zh-Hans/getting-started-five-minute-overview.html',
            ],
        ],
    ],
    'install-client' => [
        'is_pinned' => 0,
        'sort_order' => 20,
        'articles' => [
            'en' => [
                'title' => 'Install the Client',
                'excerpt' => 'Install and sign in on Windows, Android, or Linux, then confirm the client is online.',
                'body' => 'en/install-client.html',
            ],
            'zh-Hans' => [
                'title' => '安装客户端',
                'excerpt' => '在 Windows、Android 或 Linux 上安装并登录，然后确认客户端在线。',
                'body' => 'zh-Hans/install-client.html',
            ],
        ],
    ],
    'getting-started-first-forward-checklist' => [
        'is_pinned' => 0,
        'sort_order' => 30,
        'articles' => [
            'en' => [
                'title' => 'Create Your First Forward',
                'excerpt' => 'Choose a route package, enter the destination, save, and identify the assigned local port.',
                'body' => 'en/getting-started-first-forward-checklist.html',
            ],
            'zh-Hans' => [
                'title' => '创建第一条转发',
                'excerpt' => '选择线路方案、填写目标地址并记下系统分配的本地端口。',
                'body' => 'zh-Hans/getting-started-first-forward-checklist.html',
            ],
        ],
    ],
    'connect-and-verify' => [
        'is_pinned' => 0,
        'sort_order' => 40,
        'articles' => [
            'en' => [
                'title' => 'Connect and Verify',
                'excerpt' => 'Choose the correct client address, test the service, and confirm that the route is working.',
                'body' => 'en/connect-and-verify.html',
            ],
            'zh-Hans' => [
                'title' => '连接并验证',
                'excerpt' => '选择正确的客户端地址，测试服务并确认转发已经工作。',
                'body' => 'zh-Hans/connect-and-verify.html',
            ],
        ],
    ],
    'connection-modes-single-race-bond' => [
        'is_pinned' => 0,
        'sort_order' => 50,
        'articles' => [
            'en' => [
                'title' => 'Choose a Connection Mode',
                'excerpt' => 'Understand Single, Race, and Bond by the outcome you need rather than internal topology.',
                'body' => 'en/connection-modes-single-race-bond.html',
            ],
            'zh-Hans' => [
                'title' => '选择连接模式',
                'excerpt' => '按稳定、容错或多路径需求理解 Single、Race 和 Bond。',
                'body' => 'zh-Hans/connection-modes-single-race-bond.html',
            ],
        ],
    ],
    'bond-sidecar-setup' => [
        'is_pinned' => 0,
        'sort_order' => 60,
        'articles' => [
            'en' => [
                'title' => 'Configure a Sidecar',
                'excerpt' => 'Create and verify a Sidecar only when your Bond route package requires one.',
                'body' => 'en/bond-sidecar-setup.html',
            ],
            'zh-Hans' => [
                'title' => '配置 Sidecar',
                'excerpt' => '仅在线路方案明确需要时创建并验证 Sidecar。',
                'body' => 'zh-Hans/bond-sidecar-setup.html',
            ],
        ],
    ],
    'troubleshooting-common-mistakes' => [
        'is_pinned' => 0,
        'sort_order' => 70,
        'articles' => [
            'en' => [
                'title' => 'Troubleshoot Common Problems',
                'excerpt' => 'Start from the symptom you can see and follow the shortest safe recovery path.',
                'body' => 'en/troubleshooting-common-mistakes.html',
            ],
            'zh-Hans' => [
                'title' => '排查常见问题',
                'excerpt' => '从看得见的症状开始，按最短且安全的顺序恢复连接。',
                'body' => 'zh-Hans/troubleshooting-common-mistakes.html',
            ],
        ],
    ],
    'glossary' => [
        'is_pinned' => 0,
        'sort_order' => 80,
        'articles' => [
            'en' => [
                'title' => 'Glossary',
                'excerpt' => 'Plain-language definitions for the product and network terms used in the guides.',
                'body' => 'en/glossary.html',
            ],
            'zh-Hans' => [
                'title' => '名词解释',
                'excerpt' => '用通俗语言解释指南中出现的产品和网络术语。',
                'body' => 'zh-Hans/glossary.html',
            ],
        ],
    ],
];
