<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

/**
 * Homepage copy, written once per locale and rendered server-side.
 *
 * The page leads with a drawing rather than prose: a route map of the departure
 * cities, the line types (single carrier or BGP), and the carriers behind them.
 * Text is kept to short labels so the page survives a glance.
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
                'title' => 'Leave from six cities. Land where you play.',
                'lede' => 'Single-carrier or BGP lines, one app on your device.',
                'primary' => 'Open the console',
                'secondary' => 'Read the guides',
                'map_label' => 'Departure cities reaching six regions',
                'map_hub' => 'Bridge',
                'map_hub_sub' => 'BGP / SINGLE',
                'origins_label' => 'Departure cities',
                'origins' => ['Shanghai', 'Beijing', 'Guangzhou', 'Fuzhou', 'Guiyang', 'Hong Kong'],
                'destinations' => ['Tokyo', 'Singapore', 'Frankfurt', 'Los Angeles', 'Moscow', 'Hong Kong'],
                'lines_label' => 'Line types',
                'lines' => [
                    ['single', 'Single line', 'One carrier end to end: Telecom, Unicom, or Mobile.'],
                    ['bgp', 'BGP multi-line', 'Every carrier at once, routed by BGP.'],
                ],
                'carriers_label' => 'Carriers',
                'carriers' => [
                    ['ct', 'CT', 'China Telecom'],
                    ['cu', 'CU', 'China Unicom'],
                    ['cm', 'CM', 'China Mobile'],
                    ['bgp', 'BGP', 'Multi-line'],
                ],
                'closing_title' => 'Ready when you are.',
                'closing_lede' => 'Create a forward in the panel, install the app, and play.',
                'console_link' => 'Open the console',
                'guides_link' => 'Read the guides',
            ],
            'zh-Hans' => [
                'title' => '六个出发地，直达你要去的服务器。',
                'lede' => '单线或 BGP 多线，客户端一个就够。',
                'primary' => '进入控制台',
                'secondary' => '阅读使用指南',
                'map_label' => '六个出发地连接六个方向',
                'map_hub' => '桥接',
                'map_hub_sub' => 'BGP / 单线',
                'origins_label' => '出发地',
                'origins' => ['上海', '北京', '广州', '福州', '贵阳', '香港'],
                'destinations' => ['东京', '新加坡', '法兰克福', '洛杉矶', '莫斯科', '香港'],
                'lines_label' => '线路类型',
                'lines' => [
                    ['single', '单线', '全程一家运营商：电信、联通或移动。'],
                    ['bgp', 'BGP 多线', '三网合一，由 BGP 选路。'],
                ],
                'carriers_label' => '运营商',
                'carriers' => [
                    ['ct', '电', '中国电信'],
                    ['cu', '联', '中国联通'],
                    ['cm', '移', '中国移动'],
                    ['bgp', 'BGP', '多线'],
                ],
                'closing_title' => '随时可以开始。',
                'closing_lede' => '在面板里创建一条转发，装上客户端就能用。',
                'console_link' => '进入控制台',
                'guides_link' => '阅读使用指南',
            ],
            'zh-TW' => [
                'title' => '六個出發地，直達你要去的伺服器。',
                'lede' => '單線或 BGP 多線，用戶端一個就夠。',
                'primary' => '進入控制台',
                'secondary' => '閱讀使用指南',
                'map_label' => '六個出發地連接六個方向',
                'map_hub' => '橋接',
                'map_hub_sub' => 'BGP / 單線',
                'origins_label' => '出發地',
                'origins' => ['上海', '北京', '廣州', '福州', '貴陽', '香港'],
                'destinations' => ['東京', '新加坡', '法蘭克福', '洛杉磯', '莫斯科', '香港'],
                'lines_label' => '線路類型',
                'lines' => [
                    ['single', '單線', '全程一家電信商：電信、聯通或移動。'],
                    ['bgp', 'BGP 多線', '三網合一，由 BGP 選路。'],
                ],
                'carriers_label' => '電信商',
                'carriers' => [
                    ['ct', '電', '中國電信'],
                    ['cu', '聯', '中國聯通'],
                    ['cm', '移', '中國移動'],
                    ['bgp', 'BGP', '多線'],
                ],
                'closing_title' => '隨時可以開始。',
                'closing_lede' => '在面板裡建立一條轉發，裝上用戶端就能用。',
                'console_link' => '進入控制台',
                'guides_link' => '閱讀使用指南',
            ],
        ];

        return $copy[$locale] ?? $copy['en'];
    }

    /**
     * The hero drawing: departure cities on the left, destinations on the right,
     * joined through the bridge hub in the middle.
     *
     * @param array<int, string> $origins
     * @param array<int, string> $destinations
     */
    private static function routeMap(array $origins, array $destinations, string $hub, string $hubSub, string $label): string
    {
        $count = max(count($origins), count($destinations));
        $top = 34;
        $step = $count > 1 ? 232 / ($count - 1) : 0;
        $middle = 150;

        $stems = '';
        $nodes = '';

        foreach ($origins as $index => $city) {
            $y = round($top + $step * $index, 1);
            $nodes .= '<circle class="rk-dot" cx="8" cy="' . $y . '" r="3.4"></circle>'
                . '<text class="rk-label" x="20" y="' . ($y + 4) . '">' . self::e($city) . '</text>';
            $stems .= '<path class="rk-link" d="M142 ' . $y
                . ' C 168 ' . $y . ', 178 ' . $middle . ', 194 ' . $middle . '"></path>';
        }

        foreach ($destinations as $index => $city) {
            $y = round($top + $step * $index, 1);
            $nodes .= '<circle class="rk-dot rk-dot-end" cx="394" cy="' . $y . '" r="3.4"></circle>'
                . '<text class="rk-label" x="406" y="' . ($y + 4) . '">' . self::e($city) . '</text>';
            $stems .= '<path class="rk-link" d="M326 ' . $middle
                . ' C 352 ' . $middle . ', 372 ' . $y . ', 394 ' . $y . '"></path>';
        }

        return '<svg class="bridge-home-map" viewBox="0 0 560 300" role="img" aria-label="' . self::e($label) . '">'
            . '<g class="rk-links">' . $stems . '</g>'
            . '<rect class="rk-hub" x="194" y="118" width="132" height="64" rx="14"></rect>'
            . '<text class="rk-hub-label" x="260" y="146">' . self::e($hub) . '</text>'
            . '<text class="rk-hub-sub" x="260" y="164">' . self::e($hubSub) . '</text>'
            . '<g class="rk-nodes">' . $nodes . '</g>'
            . '</svg>';
    }

    public static function render(string $brand, string $consoleHref, ?string $locale = null): string
    {
        $current = LocaleResolver::normalize((string) $locale) ?? LocaleResolver::DEFAULT_LOCALE;
        $copy = self::strings($current);
        $href = self::e($consoleHref);

        $origins = '';
        foreach ($copy['origins'] as $city) {
            $origins .= '<li class="bridge-home-chip">' . self::e($city) . '</li>';
        }

        $lines = '';
        foreach ($copy['lines'] as [$kind, $name, $detail]) {
            $glyph = $kind === 'bgp'
                ? '<span class="bridge-home-diagram bridge-home-diagram--bgp" aria-hidden="true"><i></i><i></i><i></i></span>'
                : '<span class="bridge-home-diagram" aria-hidden="true"><i></i></span>';
            $lines .= '<li class="bridge-home-line-card">'
                . $glyph
                . '<div><strong>' . self::e($name) . '</strong><p>' . self::e($detail) . '</p></div>'
                . '</li>';
        }

        $carriers = '';
        foreach ($copy['carriers'] as [$code, $glyph, $name]) {
            $carriers .= '<li class="bridge-home-carrier bridge-home-carrier--' . $code . '">'
                . '<span class="bridge-home-carrier-mark" aria-hidden="true">' . self::e($glyph) . '</span>'
                . '<span class="bridge-home-carrier-name">' . self::e($name) . '</span>'
                . '</li>';
        }

        return '<section class="bridge-home-hero" aria-labelledby="bridge-home-title">'
            . '<div class="bridge-home-hero-inner">'
            . '<div class="bridge-home-hero-copy">'
            . '<p class="bridge-home-kicker">' . self::e($brand) . '</p>'
            . '<h1 id="bridge-home-title">' . self::e($copy['title']) . '</h1>'
            . '<p class="bridge-home-lede">' . self::e($copy['lede']) . '</p>'
            . '<div class="bridge-home-actions">'
            . '<a class="bridge-home-primary" href="' . $href . '">' . self::e($copy['primary']) . '<span aria-hidden="true">&rarr;</span></a>'
            . '<a class="bridge-home-link" href="/knowledgebase">' . self::e($copy['secondary']) . '</a>'
            . '</div>'
            . '</div>'
            . '<div class="bridge-home-hero-art">'
            . self::routeMap($copy['origins'], $copy['destinations'], $copy['map_hub'], $copy['map_hub_sub'], $copy['map_label'])
            . '</div>'
            . '</div>'
            . '</section>'
            . '<section class="bridge-home-network" aria-label="' . self::e($copy['origins_label']) . '">'
            . '<div class="bridge-home-network-block">'
            . '<p class="bridge-home-label">' . self::e($copy['origins_label']) . '</p>'
            . '<ul class="bridge-home-chips">' . $origins . '</ul>'
            . '</div>'
            . '<div class="bridge-home-network-block">'
            . '<p class="bridge-home-label">' . self::e($copy['lines_label']) . '</p>'
            . '<ul class="bridge-home-line-list">' . $lines . '</ul>'
            . '</div>'
            . '<div class="bridge-home-network-block">'
            . '<p class="bridge-home-label">' . self::e($copy['carriers_label']) . '</p>'
            . '<ul class="bridge-home-carriers">' . $carriers . '</ul>'
            . '</div>'
            . '</section>'
            . '<section class="bridge-home-closing">'
            . '<div class="bridge-home-closing-inner">'
            . '<div class="bridge-home-closing-copy">'
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
