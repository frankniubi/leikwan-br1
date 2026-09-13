<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

/**
 * Homepage copy, written once per locale and rendered server-side.
 *
 * The page is localized before it reaches the browser, so it reads correctly in the
 * visitor's language without waiting for the client-side patch, and the document
 * language matches the text. Every locale carries the same shape: a dark route
 * board hero, the three setup steps, the path, and one closing call to action.
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
                'lede' => 'One account, one line, a port you control. Create a forward, install the app, and play through a route that holds steady.',
                'primary' => 'Open the console',
                'secondary' => 'Read the guides',
                'facts' => [
                    ['3', 'platforms'],
                    ['1', 'line type'],
                    ['~5 min', 'setup'],
                ],
                'board_head' => 'Route catalog',
                'board_origin' => 'Shanghai',
                'board_note' => 'Pick a direction in the panel; the line stays ultra.',
                'routes' => [
                    ['TYO', 'Tokyo'],
                    ['SEL', 'Seoul'],
                    ['FRA', 'Frankfurt'],
                    ['MOW', 'Moscow'],
                    ['HKG', 'Hong Kong'],
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
                'path_lede' => 'Your device carries the line to the route you picked, and the route reaches the destination you configured.',
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
                'facts' => [
                    ['3', '个平台'],
                    ['1', '种线路'],
                    ['约 5 分钟', '完成设置'],
                ],
                'board_head' => '线路目录',
                'board_origin' => '上海',
                'board_note' => '在面板里选一个方向，线路统一走 ultra。',
                'routes' => [
                    ['TYO', '东京'],
                    ['SEL', '首尔'],
                    ['FRA', '法兰克福'],
                    ['MOW', '莫斯科'],
                    ['HKG', '香港'],
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
                'facts' => [
                    ['3', '個平台'],
                    ['1', '種線路'],
                    ['約 5 分鐘', '完成設定'],
                ],
                'board_head' => '線路目錄',
                'board_origin' => '上海',
                'board_note' => '在面板裡選一個方向，線路統一走 ultra。',
                'routes' => [
                    ['TYO', '東京'],
                    ['SEL', '首爾'],
                    ['FRA', '法蘭克福'],
                    ['MOW', '莫斯科'],
                    ['HKG', '香港'],
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
                'path_lede' => '用戶端把你裝置上的線路帶到選定的那條線路，再由它由它到達你設定的目標。',
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
        $href = self::e($consoleHref);

        $facts = '';
        foreach ($copy['facts'] as [$value, $label]) {
            $facts .= '<li><strong>' . self::e($value) . '</strong><span>' . self::e($label) . '</span></li>';
        }

        $routes = '';
        foreach ($copy['routes'] as $index => [$code, $city]) {
            $routes .= '<li class="bridge-home-board-row">'
                . '<span class="bridge-home-board-code">' . self::e($code) . '</span>'
                . '<span class="bridge-home-board-city">' . self::e($city) . '</span>'
                . '<span class="bridge-home-board-line" aria-hidden="true"></span>'
                . '<span class="bridge-home-board-dot" aria-hidden="true"></span>'
                . '</li>';
        }

        $steps = '';
        foreach ($copy['steps'] as $index => [$title, $detail]) {
            $steps .= '<li class="bridge-home-step">'
                . '<span class="bridge-home-step-index">' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) . '</span>'
                . '<h3>' . self::e($title) . '</h3>'
                . '<p>' . self::e($detail) . '</p>'
                . '</li>';
        }

        $path = '';
        foreach ($copy['path'] as $index => [$title, $detail]) {
            $path .= '<li class="bridge-home-stop">'
                . '<span class="bridge-home-stop-index">' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) . '</span>'
                . '<strong>' . self::e($title) . '</strong>'
                . '<small>' . self::e($detail) . '</small>'
                . '</li>';
        }

        return '<section class="bridge-home-hero" aria-labelledby="bridge-home-title">'
            . '<div class="bridge-home-hero-inner">'
            . '<div class="bridge-home-hero-copy">'
            . '<p class="bridge-home-kicker">' . self::e($copy['kicker']) . '</p>'
            . '<h1 id="bridge-home-title">' . self::e($brand) . '<span>' . self::e($copy['title_tail']) . '</span></h1>'
            . '<p class="bridge-home-lede">' . self::e($copy['lede']) . '</p>'
            . '<div class="bridge-home-actions">'
            . '<a class="bridge-home-primary" href="' . $href . '">' . self::e($copy['primary']) . '<span aria-hidden="true">&rarr;</span></a>'
            . '<a class="bridge-home-link" href="/knowledgebase">' . self::e($copy['secondary']) . '</a>'
            . '</div>'
            . '<ul class="bridge-home-facts">' . $facts . '</ul>'
            . '</div>'
            . '<aside class="bridge-home-board" aria-label="' . self::e($copy['board_head']) . '">'
            . '<div class="bridge-home-board-head"><span>' . self::e($copy['board_head']) . '</span><span class="bridge-home-board-pulse" aria-hidden="true"></span></div>'
            . '<p class="bridge-home-board-origin">' . self::e($copy['board_origin']) . '</p>'
            . '<ul class="bridge-home-board-list">' . $routes . '</ul>'
            . '<p class="bridge-home-board-note">' . self::e($copy['board_note']) . '</p>'
            . '</aside>'
            . '</div>'
            . '</section>'
            . '<section class="bridge-home-steps" aria-labelledby="bridge-home-steps-title">'
            . '<header class="bridge-home-section-head">'
            . '<span>' . self::e($copy['steps_label']) . '</span>'
            . '<h2 id="bridge-home-steps-title">' . self::e($copy['steps_title']) . '</h2>'
            . '<p>' . self::e($copy['steps_lede']) . '</p>'
            . '</header>'
            . '<ol class="bridge-home-step-list">' . $steps . '</ol>'
            . '</section>'
            . '<section id="network" class="bridge-home-path" aria-labelledby="bridge-home-path-title">'
            . '<header class="bridge-home-section-head">'
            . '<span>' . self::e($copy['path_label']) . '</span>'
            . '<h2 id="bridge-home-path-title">' . self::e($copy['path_title']) . '</h2>'
            . '<p>' . self::e($copy['path_lede']) . '</p>'
            . '</header>'
            . '<ol class="bridge-home-path-rail">' . $path . '</ol>'
            . '</section>'
            . '<section class="bridge-home-closing">'
            . '<div class="bridge-home-closing-inner">'
            . '<div class="bridge-home-closing-copy">'
            . '<span>' . self::e($copy['closing_label']) . '</span>'
            . '<h2>' . self::e($copy['closing_title']) . '</h2>'
            . '<p>' . self::e($copy['closing_lede']) . '</p>'
            . '</div>'
            . '<div class="bridge-home-closing-actions">'
            . '<a class="bridge-home-primary" href="' . $href . '">' . self::e($copy['console_link']) . '<span aria-hidden="true">&rarr;</span></a>'
            . '<a class="bridge-home-ghost" href="/knowledgebase">' . self::e($copy['guides_link']) . '<span aria-hidden="true">&rarr;</span></a>'
            . '</div>'
            . '</div>'
            . '</section>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
