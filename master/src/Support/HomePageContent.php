<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

/**
 * Homepage copy, written once per locale and rendered server-side.
 *
 * The page is localized before it reaches the browser, so it reads correctly in the
 * visitor's language without waiting for the client-side patch, and the document
 * language matches the text. The copy stays in the same shape in every locale:
 * a hero, an at-a-glance panel, the three setup steps, the path, and one call to
 * action.
 */
final class HomePageContent
{
    /**
     * @return array<string, mixed>
     */
    private static function strings(string $locale): array
    {
        $copy = [
            'en' => [
                'kicker' => 'Control plane',
                'title_tail' => 'Bridge control plane',
                'lede' => 'One account, one line, and a port you control. Create a forward, install the app, and play through a route that stays steady.',
                'primary' => 'Open the console',
                'secondary' => 'Read the guides',
                'status_label' => 'Service at a glance',
                'status_head' => 'At a glance',
                'status' => [
                    ['Every line uses ultra', 'Nothing to choose. The route stays steady while you play.'],
                    ['Windows, Android, Linux', 'The same account signs in on every device you play on.'],
                    ['About five minutes', 'From an active plan to a line you can use.'],
                ],
                'steps_label' => '01 / Steps',
                'steps_title' => 'How a line is built.',
                'steps_lede' => 'Three steps. The panel remembers the state between them.',
                'steps' => [
                    ['Create a forward', 'Choose a route, enter the address your game connects to, and note the local port.'],
                    ['Install the app', 'Sign in on the device you play on, then leave it running.'],
                    ['Connect and play', 'Point the game at 127.0.0.1 and the local port.'],
                ],
                'path_label' => '02 / Path',
                'path_title' => 'Where your traffic goes.',
                'path_lede' => 'The app carries the line from your device to the route you picked, and the route reaches the destination you configured.',
                'path' => [
                    ['Your device', 'The app runs here'],
                    ['Bridge route', 'The line you pick in the panel'],
                    ['Your game or service', 'The destination you configure'],
                ],
                'closing_label' => '03 / Start',
                'closing_title' => 'Ready when you are.',
                'closing_lede' => 'Sign in to create your first forward, or read the five-minute guide first.',
                'console_link' => 'Open the console',
                'guides_link' => 'Read the guides',
            ],
            'zh-Hans' => [
                'kicker' => '控制平面',
                'title_tail' => '桥接控制台',
                'lede' => '一个账号，一条线路，一个你自己掌握的端口。创建转发、安装客户端，然后走一条稳定的线路进游戏。',
                'primary' => '进入控制台',
                'secondary' => '阅读使用指南',
                'status_label' => '服务概览',
                'status_head' => '一眼看完',
                'status' => [
                    ['所有线路都用 ultra', '不需要选模式，打游戏时线路保持稳定。'],
                    ['Windows、Android、Linux', '同一个账号，每台设备都能登录。'],
                    ['大约五分钟', '从有效套餐到一条能用的线路。'],
                ],
                'steps_label' => '01 / 三个步骤',
                'steps_title' => '一条线路是这样建起来的。',
                'steps_lede' => '三步而已，中间的状态由面板替你记着。',
                'steps' => [
                    ['创建一条转发', '选线路，填游戏连接的地址，记下面板分配的本机端口。'],
                    ['安装客户端', '在你玩游戏那台设备上登录，然后让它一直开着。'],
                    ['连接并开始游戏', '把游戏指向 127.0.0.1 和那个本机端口。'],
                ],
                'path_label' => '02 / 路径',
                'path_title' => '流量从哪里走。',
                'path_lede' => '客户端把你设备上的线路带到选定的那条线路，再由它到达你配置的目标。',
                'path' => [
                    ['你的设备', '客户端在这里运行'],
                    ['桥接线路', '你在面板上选的那条'],
                    ['游戏或服务', '你配置的目标'],
                ],
                'closing_label' => '03 / 开始',
                'closing_title' => '随时可以开始。',
                'closing_lede' => '登录后创建第一条转发，或者先花五分钟读一遍上手指南。',
                'console_link' => '进入控制台',
                'guides_link' => '阅读使用指南',
            ],
            'zh-TW' => [
                'kicker' => '控制平面',
                'title_tail' => '橋接控制台',
                'lede' => '一個帳號，一條線路，一個你自己掌握的連接埠。建立轉發、安裝用戶端，然後走一條穩定的線路進遊戲。',
                'primary' => '進入控制台',
                'secondary' => '閱讀使用指南',
                'status_label' => '服務概覽',
                'status_head' => '一眼看完',
                'status' => [
                    ['所有線路都用 ultra', '不需要選模式，玩遊戲時線路保持穩定。'],
                    ['Windows、Android、Linux', '同一個帳號，每台裝置都能登入。'],
                    ['大約五分鐘', '從有效方案到一條能用的線路。'],
                ],
                'steps_label' => '01 / 三個步驟',
                'steps_title' => '一條線路是這樣建起來的。',
                'steps_lede' => '三步而已，中間的狀態由面板替你記著。',
                'steps' => [
                    ['建立一條轉發', '選線路，填遊戲連的位址，記下面板分配的本機連接埠。'],
                    ['安裝用戶端', '在你玩遊戲那台裝置上登入，然後讓它一直開著。'],
                    ['連線並開始遊戲', '把遊戲指向 127.0.0.1 和那個本機連接埠。'],
                ],
                'path_label' => '02 / 路徑',
                'path_title' => '流量從哪裡走。',
                'path_lede' => '用戶端把你裝置上的線路帶到選定的那條線路，再由它到達你設定的目標。',
                'path' => [
                    ['你的裝置', '用戶端在這裡執行'],
                    ['橋接線路', '你在面板上選的那條'],
                    ['遊戲或服務', '你設定的目標'],
                ],
                'closing_label' => '03 / 開始',
                'closing_title' => '隨時可以開始。',
                'closing_lede' => '登入後建立第一條轉發，或者先花五分鐘讀一遍上手指南。',
                'console_link' => '進入控制台',
                'guides_link' => '閱讀使用指南',
            ],
        ];

        return $copy[$locale] ?? $copy['en'];
    }

    public static function render(string $brand, string $consoleHref, ?string $locale = null): string
    {
        $current = LocaleResolver::normalize((string) $locale) ?? LocaleResolver::DEFAULT_LOCALE;
        $copy = self::strings($current);

        $statusItems = '';
        foreach ($copy['status'] as [$headline, $detail]) {
            $statusItems .= '<li><strong>' . self::e($headline) . '</strong><span>' . self::e($detail) . '</span></li>';
        }

        $stepCards = '';
        foreach ($copy['steps'] as $index => [$title, $detail]) {
            $stepCards .= '<article class="bridge-home-route-card">'
                . '<span class="bridge-home-route-index">' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) . '</span>'
                . '<strong>' . self::e($title) . '</strong>'
                . '<p>' . self::e($detail) . '</p>'
                . '</article>';
        }

        $pathNodes = '';
        foreach ($copy['path'] as $index => [$title, $detail]) {
            $pathNodes .= '<div class="bridge-home-route-node">'
                . '<span class="bridge-home-route-index">' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) . '</span>'
                . '<span><strong>' . self::e($title) . '</strong><small>' . self::e($detail) . '</small></span>'
                . '</div>';
        }

        $href = self::e($consoleHref);

        return '<section class="bridge-home-hero" aria-labelledby="bridge-home-title">'
            . '<div class="bridge-home-hero-copy">'
            . '<p class="bridge-home-kicker">' . self::e($copy['kicker']) . '</p>'
            . '<h1 id="bridge-home-title">' . self::e($brand) . ' <span>' . self::e($copy['title_tail']) . '</span></h1>'
            . '<p class="bridge-home-lede">' . self::e($copy['lede']) . '</p>'
            . '<div class="bridge-home-actions">'
            . '<a class="button bridge-home-primary" href="' . $href . '">' . self::e($copy['primary']) . ' <span aria-hidden="true">&rarr;</span></a>'
            . '<a class="bridge-home-link" href="/knowledgebase">' . self::e($copy['secondary']) . '</a>'
            . '</div>'
            . '</div>'
            . '<aside class="bridge-home-status" aria-label="' . self::e($copy['status_label']) . '">'
            . '<p class="bridge-home-status-head">' . self::e($copy['status_head']) . '</p>'
            . '<ul class="bridge-home-status-list">' . $statusItems . '</ul>'
            . '</aside>'
            . '</section>'
            . '<section class="bridge-home-route-catalog" aria-labelledby="bridge-home-steps-title">'
            . '<header class="bridge-home-catalog-heading">'
            . '<span>' . self::e($copy['steps_label']) . '</span>'
            . '<h2 id="bridge-home-steps-title">' . self::e($copy['steps_title']) . '</h2>'
            . '<p>' . self::e($copy['steps_lede']) . '</p>'
            . '</header>'
            . '<div class="bridge-home-route-grid">' . $stepCards . '</div>'
            . '</section>'
            . '<section id="network" class="bridge-home-route" aria-labelledby="bridge-home-path-title">'
            . '<header class="bridge-home-section-heading">'
            . '<span>' . self::e($copy['path_label']) . '</span>'
            . '<h2 id="bridge-home-path-title">' . self::e($copy['path_title']) . '</h2>'
            . '<p>' . self::e($copy['path_lede']) . '</p>'
            . '</header>'
            . '<div class="bridge-home-route-flow">' . $pathNodes . '</div>'
            . '</section>'
            . '<section class="bridge-home-footer-grid">'
            . '<div class="bridge-home-footer-copy">'
            . '<span>' . self::e($copy['closing_label']) . '</span>'
            . '<h2>' . self::e($copy['closing_title']) . '</h2>'
            . '<p>' . self::e($copy['closing_lede']) . '</p>'
            . '</div>'
            . '<div class="bridge-home-footer-links">'
            . '<a href="' . $href . '"><span>' . self::e($copy['console_link']) . '</span><span aria-hidden="true">&rarr;</span></a>'
            . '<a href="/knowledgebase"><span>' . self::e($copy['guides_link']) . '</span><span aria-hidden="true">&rarr;</span></a>'
            . '</div>'
            . '</section>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
