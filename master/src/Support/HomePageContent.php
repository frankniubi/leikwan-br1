<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

/**
 * Homepage for the Leikwan Bridge public surface.
 *
 * The page is a specification plate, not a brochure: one peer mesh drawn on a real
 * coast, then pictograms, numerals and single-word labels. The promise is the
 * destination the visitor already owns — not a vendor node, and not 127.0.0.1.
 *
 * The network is a symmetric MESH. Every node is a peer of every other node and can carry
 * traffic in either direction.
 */
final class HomePageContent
{
    /**
     * The mesh nodes, ordered west to east so the plate, the index and the
     * connection board all read in the same geographic order.
     *
     * @var array<int, array{code:string,name:array<string,string>,lon:float,lat:float,utc:float}>
     */
    private const NODES = [
        ['code' => 'FRA', 'name' => ['en' => 'Frankfurt', 'zh-Hans' => '法兰克福', 'zh-TW' => '法蘭克福'], 'lon' => 95.00, 'lat' => 44.00, 'utc' => 1.0],
        ['code' => 'MOW', 'name' => ['en' => 'Moscow', 'zh-Hans' => '莫斯科', 'zh-TW' => '莫斯科'], 'lon' => 100.40, 'lat' => 49.60, 'utc' => 3.0],
        ['code' => 'SIN', 'name' => ['en' => 'Singapore', 'zh-Hans' => '新加坡', 'zh-TW' => '新加坡'], 'lon' => 103.82, 'lat' => 1.35, 'utc' => 8.0],
        ['code' => 'KWE', 'name' => ['en' => 'Guiyang', 'zh-Hans' => '贵阳', 'zh-TW' => '貴陽'], 'lon' => 106.63, 'lat' => 26.65, 'utc' => 8.0],
        ['code' => 'CAN', 'name' => ['en' => 'Guangzhou', 'zh-Hans' => '广州', 'zh-TW' => '廣州'], 'lon' => 113.26, 'lat' => 23.13, 'utc' => 8.0],
        ['code' => 'HKG', 'name' => ['en' => 'Hong Kong', 'zh-Hans' => '香港', 'zh-TW' => '香港'], 'lon' => 114.17, 'lat' => 22.32, 'utc' => 8.0],
        ['code' => 'PEK', 'name' => ['en' => 'Beijing', 'zh-Hans' => '北京', 'zh-TW' => '北京'], 'lon' => 116.41, 'lat' => 39.90, 'utc' => 8.0],
        ['code' => 'FOC', 'name' => ['en' => 'Fuzhou', 'zh-Hans' => '福州', 'zh-TW' => '福州'], 'lon' => 119.30, 'lat' => 26.08, 'utc' => 8.0],
        ['code' => 'SHA', 'name' => ['en' => 'Shanghai', 'zh-Hans' => '上海', 'zh-TW' => '上海'], 'lon' => 121.47, 'lat' => 31.23, 'utc' => 8.0],
        ['code' => 'TPE', 'name' => ['en' => 'Taipei', 'zh-Hans' => '台北', 'zh-TW' => '台北'], 'lon' => 121.23, 'lat' => 25.08, 'utc' => 8.0],
        ['code' => 'SEL', 'name' => ['en' => 'Seoul', 'zh-Hans' => '首尔', 'zh-TW' => '首爾'], 'lon' => 126.98, 'lat' => 37.57, 'utc' => 9.0],
        ['code' => 'TYO', 'name' => ['en' => 'Tokyo', 'zh-Hans' => '东京', 'zh-TW' => '東京'], 'lon' => 139.69, 'lat' => 35.69, 'utc' => 9.0],
        ['code' => 'LAX', 'name' => ['en' => 'Los Angeles', 'zh-Hans' => '洛杉矶', 'zh-TW' => '洛杉磯'], 'lon' => 154.80, 'lat' => 34.20, 'utc' => -8.0],
    ];

    /**
     * The operators behind every line. The official SVG wordmarks under /assets/carriers/
     * are used for China Telecom, Unicom and Mobile, while BGP uses an SVG glyph.
     *
     * One word per operator: the legal name and the short name were the same string
     * twice, which is the kind of repetition this page no longer carries.
     *
     * @var array<int, array{key:string,mark:string,label:array<string,string>}>
     */
    private const CARRIERS = [
        ['key' => 'ct', 'mark' => 'CT', 'label' => ['en' => 'Telecom', 'zh-Hans' => '电信', 'zh-TW' => '電信']],
        ['key' => 'cu', 'mark' => 'CU', 'label' => ['en' => 'Unicom', 'zh-Hans' => '联通', 'zh-TW' => '聯通']],
        ['key' => 'cm', 'mark' => 'CM', 'label' => ['en' => 'Mobile', 'zh-Hans' => '移动', 'zh-TW' => '移動']],
        ['key' => 'bgp', 'mark' => 'BGP', 'label' => ['en' => 'BGP', 'zh-Hans' => 'BGP', 'zh-TW' => 'BGP']],
    ];

    /**
     * The native clients, as a glyph and one word. The names are proper nouns and
     * stay identical in every locale, so they live here rather than in the copy.
     *
     * @var array<int, array{key:string,label:string}>
     */
    private const PLATFORMS = [
        ['key' => 'windows', 'label' => 'Windows'],
        ['key' => 'macos', 'label' => 'macOS'],
        ['key' => 'android', 'label' => 'Android'],
        ['key' => 'linux', 'label' => 'Linux'],
    ];

    /** The transport protocols every peer forwards. */
    private const PROTOCOLS = ['TCP', 'UDP'];

    /** Chart crop: 95-150 degrees east, 50 north to 10 south. */
    private const LON_MIN = 93.0;
    private const LON_SPAN = 62.0;
    private const LAT_TOP = 55.0;
    private const LAT_SPAN = 55.0;
    private const PLATE_W = 960.0;
    private const PLATE_H = 480.0;
    private const PLATE_RATIO = 2.04;
    private const EARTH_RADIUS_KM = 6371.0088;

    /**
     * @return array<string, mixed>
     */
    private static function strings(string $locale): array
    {
        $copy = [
            'en' => [
                'hero_badge' => 'MESH · {n} PEERS LIVE',
                'title_zh' => '直达目的地',
                'title_en' => 'Your destination.',
                'hero_sub' => 'A transit mesh that lands at the destination you pick.',
                'primary' => 'Enter Console',
                'hero_guide_cta' => 'How it works',
                'platforms_label' => 'Client platforms',
                'map_label' => 'Mesh of {n} peers across East Asia',
                'atlas_kicker' => '01 · MESH',
                'atlas_note' => '{n} PEERS · GREAT-CIRCLE · BIDIRECTIONAL',
                'spec_kicker' => '02 · SPEC',
                'spec_title' => 'Your mesh. Your destination.',
                'stat_nodes' => 'NODES',
                'stat_carriers' => 'CARRIERS',
                'stat_protocols' => 'PROTOCOLS',
                'stat_platforms' => 'PLATFORMS',
                'carriers_kicker' => '03 · CARRIERS',
                'carriers_title' => 'Direct landing.',
                'carriers_note' => 'Telecom, Unicom, Mobile and BGP.',
                'steps_kicker' => '04 · START',
                'steps_title' => 'Three steps to the destination.',
                'step_1_title' => 'Console',
                'step_1_note' => 'Pick your line',
                'step_2_title' => 'Forward',
                'step_2_note' => 'Name the destination',
                'step_3_title' => 'Connect',
                'step_3_note' => 'Land and play',
                'closing_title' => 'Go to your destination.',
                'console_link' => 'Enter Console',
                'guides_link' => 'Guides',
            ],
            'zh-Hans' => [
                'hero_badge' => '网状在线 · {n} 个节点',
                'title_zh' => '直达目的地',
                'title_en' => 'Your destination.',
                'hero_sub' => '选一条线，流量直达你的目的地。',
                'primary' => '进入控制台',
                'hero_guide_cta' => '了解运作方式',
                'platforms_label' => '客户端平台',
                'map_label' => '{cn} 个对等节点组成的东亚网状网络',
                'atlas_kicker' => '01 · 网状',
                'atlas_note' => '{cn} 个节点 · 大圆航线 · 双向互通',
                'spec_kicker' => '02 · 规格',
                'spec_title' => '你的网，你的目的地。',
                'stat_nodes' => '节点',
                'stat_carriers' => '线路',
                'stat_protocols' => '协议',
                'stat_platforms' => '平台',
                'carriers_kicker' => '03 · 线路',
                'carriers_title' => '当地直出。',
                'carriers_note' => '电信、联通、移动与 BGP。',
                'steps_kicker' => '04 · 上手',
                'steps_title' => '三步直达目的地。',
                'step_1_title' => '控制台',
                'step_1_note' => '选择线路',
                'step_2_title' => '转发',
                'step_2_note' => '写上目的地',
                'step_3_title' => '连接',
                'step_3_note' => '落地即玩',
                'closing_title' => '去你的目的地。',
                'console_link' => '进入控制台',
                'guides_link' => '指南',
            ],
            'zh-TW' => [
                'hero_badge' => '網狀上線 · {n} 個節點',
                'title_zh' => '直達目的地',
                'title_en' => 'Your destination.',
                'hero_sub' => '選一條線，流量直達你的目的地。',
                'primary' => '進入控制台',
                'hero_guide_cta' => '了解運作方式',
                'platforms_label' => '用戶端平台',
                'map_label' => '{cn} 個對等節點組成的東亞網狀網路',
                'atlas_kicker' => '01 · 網狀',
                'atlas_note' => '{cn} 個節點 · 大圓航線 · 雙向互通',
                'spec_kicker' => '02 · 規格',
                'spec_title' => '你的網，你的目的地。',
                'stat_nodes' => '節點',
                'stat_carriers' => '線路',
                'stat_protocols' => '協定',
                'stat_platforms' => '平台',
                'carriers_kicker' => '03 · 線路',
                'carriers_title' => '當地直出。',
                'carriers_note' => '電信、聯通、移動與 BGP。',
                'steps_kicker' => '04 · 上手',
                'steps_title' => '三步直達目的地。',
                'step_1_title' => '控制台',
                'step_1_note' => '選擇線路',
                'step_2_title' => '轉發',
                'step_2_note' => '寫上目的地',
                'step_3_title' => '連線',
                'step_3_note' => '落地即玩',
                'closing_title' => '去你的目的地。',
                'console_link' => '進入控制台',
                'guides_link' => '指南',
            ],
        ];

        $copy = $copy[$locale] ?? $copy['en'];

        // Every count in the copy comes from the node list itself.
        $count = count(self::NODES);
        $replace = ['{n}' => (string) $count, '{cn}' => self::chineseNumber($count)];
        array_walk_recursive($copy, static function (string &$value) use ($replace): void {
            $value = strtr($value, $replace);
        });

        return $copy;
    }

    /**
     * The Chinese locales spell the peer count out: a bare digit in a Chinese
     * sentence reads as machine output, and only these small numbers occur.
     */
    private static function chineseNumber(int $value): string
    {
        $words = [
            10 => '十', 11 => '十一', 12 => '十二', 13 => '十三', 14 => '十四',
            15 => '十五', 16 => '十六', 17 => '十七', 18 => '十八', 19 => '十九',
            20 => '二十',
        ];

        return $words[$value] ?? (string) $value;
    }

    /**
     * @param array{code:string,name:array<string,string>,lon:float,lat:float,utc:float} $node
     */
    private static function placeName(array $node, string $locale): string
    {
        return $node['name'][$locale] ?? $node['name']['en'];
    }

    /**
     * The mark for one operator. China Telecom, Unicom and Mobile use the
     * official wordmarks under /assets/carriers/. BGP has no public mark, so it
     * stays a geometric glyph.
     */
    private static function carrierMark(string $key): string
    {
        $official = [
            'ct' => '/assets/carriers/ct.svg',
            'cu' => '/assets/carriers/cu.svg',
            'cm' => '/assets/carriers/cm.svg',
        ];
        if (isset($official[$key])) {
            return '<img class="bridge-home-carrier-svg" src="' . self::e($official[$key]) . '" alt="">';
        }

        return '<svg class="bridge-home-carrier-svg" viewBox="0 0 48 24" fill="none" aria-hidden="true" focusable="false">'
            . '<path d="M6 5h9l5 7h13" stroke="currentColor" stroke-width="2.4" stroke-linecap="square"/>'
            . '<path d="M6 12h28" stroke="currentColor" stroke-width="2.4" stroke-linecap="square"/>'
            . '<path d="M6 19h9l5-7" stroke="currentColor" stroke-width="2.4" stroke-linecap="square"/>'
            . '<circle cx="34" cy="12" r="3" fill="currentColor"/>'
            . '</svg>';
    }

    /**
     * @return array{0:float,1:float}
     */
    private static function px(float $lon, float $lat, float $lonOffset = 0.0): array
    {
        $x = (($lon + $lonOffset) - self::LON_MIN) / self::LON_SPAN * (self::PLATE_W - 104.0) + 52.0;
        $y = (self::LAT_TOP - $lat) / self::LAT_SPAN * (self::PLATE_H - 32.0) + 16.0;

        return [round($x, 1), round($y, 1)];
    }

    /** Great-circle distance in kilometres from the published coordinates. */
    private static function greatCircleKm(float $lonA, float $latA, float $lonB, float $latB): float
    {
        $phiA = deg2rad($latA);
        $phiB = deg2rad($latB);
        $dPhi = deg2rad($latB - $latA);
        $dLambda = deg2rad($lonB - $lonA);

        $a = sin($dPhi / 2.0) ** 2 + cos($phiA) * cos($phiB) * sin($dLambda / 2.0) ** 2;

        return 2.0 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($a)));
    }

    /**
     * The widest peer-to-peer span in the mesh, as a published figure. The plate
     * draws great circles; this is what one of them actually measures.
     */
    private static function meshSpan(): float
    {
        $span = 0.0;
        foreach (self::NODES as $a) {
            foreach (self::NODES as $b) {
                $span = max($span, self::greatCircleKm($a['lon'], $a['lat'], $b['lon'], $b['lat']));
            }
        }

        return $span;
    }

    /**
     * The page's whole visual vocabulary: 24x24 line pictograms drawn inline, so
     * each one inherits the current colour and costs no extra request.
     */
    private static function icon(string $name): string
    {
        $shapes = [
            // A hub wired to three peers: the mesh itself.
            'nodes' => '<circle cx="12" cy="12" r="2.6"/>'
                . '<circle cx="4.6" cy="5.4" r="2"/><circle cx="19.4" cy="5.4" r="2"/><circle cx="12" cy="20" r="2"/>'
                . '<path d="M6.1 6.7 9.9 10.2M17.9 6.7 14.1 10.2M12 14.6V18"/>',
            // Trunks standing on a quay: carrier-grade landing at the city.
            'landing' => '<path d="M3 20.4h18"/><path d="M6.4 20.4V9.6M12 20.4V4.4M17.6 20.4v-7.4"/>'
                . '<path d="M6.4 9.6h11.2"/>',
            // Two protocols travelling in opposite directions.
            'protocols' => '<path d="M8 4.4v15.2M8 19.6l-3.2-3.2M8 19.6l3.2-3.2"/>'
                . '<path d="M16 19.6V4.4M16 4.4l-3.2 3.2M16 4.4l3.2 3.2"/>',
            // A screen and a handset: the native clients.
            'platforms' => '<rect x="2.6" y="4.4" width="12.4" height="9.6" rx="1.6"/><path d="M5.6 18.4h6.4"/>'
                . '<rect x="16.4" y="9.4" width="5" height="10.2" rx="1.6"/>',
            // Three sliders: the console.
            'console' => '<path d="M4 7h16M4 12h16M4 17h16"/>'
                . '<circle cx="9.4" cy="7" r="2.2"/><circle cx="14.6" cy="12" r="2.2"/><circle cx="8.4" cy="17" r="2.2"/>',
            // Two peers joined by one issued link.
            'forward' => '<circle cx="5.4" cy="12" r="2.4"/><circle cx="18.6" cy="12" r="2.4"/>'
                . '<path d="M8.2 12h7.4M13.2 9.6 15.6 12l-2.4 2.4"/>',
            // A throughput bolt.
            'connect' => '<path d="M13.6 2.6 4.8 13.8h5.8l-1.2 7.6 8.8-11.2h-5.8z"/>',
        ];

        return '<svg class="bridge-home-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . ($shapes[$name] ?? '')
            . '</svg>';
    }

    /**
     * The operating-system marks. Windows keeps its four panes; the other three
     * are neutral device glyphs rather than trademarks.
     */
    private static function platformGlyph(string $key): string
    {
        $shapes = [
            'windows' => '<rect x="3.2" y="3.2" width="7.6" height="7.6"/><rect x="13.2" y="3.2" width="7.6" height="7.6"/>'
                . '<rect x="3.2" y="13.2" width="7.6" height="7.6"/><rect x="13.2" y="13.2" width="7.6" height="7.6"/>',
            'macos' => '<rect x="3.4" y="4.6" width="17.2" height="11.4" rx="1.8"/><path d="M1.6 19.4h20.8"/>',
            'android' => '<rect x="6.8" y="2.4" width="10.4" height="19.2" rx="2.6"/>'
                . '<path d="M10.4 5.4h3.2M10.6 18.6h2.8"/>',
            'linux' => '<rect x="2.6" y="4" width="18.8" height="16" rx="2"/>'
                . '<path d="M6.8 10.4 10 13l-3.2 2.6"/><path d="M12.6 16h4.4"/>',
        ];

        return '<svg class="bridge-home-platform-glyph" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . ($shapes[$key] ?? '')
            . '</svg>';
    }

    /** The client strip: a glyph and one word per platform, never a sentence. */
    private static function platformRow(array $copy): string
    {
        $items = '';
        foreach (self::PLATFORMS as $platform) {
            $items .= '<li class="bridge-home-platform">'
                . self::platformGlyph($platform['key'])
                . '<span class="bridge-home-platform-name">' . self::e($platform['label']) . '</span>'
                . '</li>';
        }

        return '<ul class="bridge-home-platforms" aria-label="' . self::e((string) $copy['platforms_label']) . '">'
            . $items . '</ul>';
    }

    /**
     * The datasheet strip. Every numeral is counted from the constants above, so
     * adding a peer can never leave a stale figure on the page.
     */
    private static function statTiles(array $copy): string
    {
        $tiles = [
            ['nodes', (string) count(self::NODES), (string) $copy['stat_nodes']],
            ['landing', (string) count(self::CARRIERS), (string) $copy['stat_carriers']],
            ['protocols', (string) count(self::PROTOCOLS), (string) $copy['stat_protocols']],
            ['platforms', (string) count(self::PLATFORMS), (string) $copy['stat_platforms']],
        ];

        $items = '';
        foreach ($tiles as [$icon, $value, $label]) {
            $items .= '<li class="bridge-home-stat">'
                . '<span class="bridge-home-stat-icon">' . self::icon($icon) . '</span>'
                . '<span class="bridge-home-stat-figure">'
                . '<span class="bridge-home-stat-value">' . self::e($value) . '</span>'
                . '<span class="bridge-home-stat-label">' . self::e($label) . '</span>'
                . '</span>'
                . '</li>';
        }

        return '<ul class="bridge-home-stats">' . $items . '</ul>';
    }

    /** Three pictograms with a one-line footnote each. */
    private static function stepTiles(array $copy): string
    {
        $steps = [
            ['console', '01', (string) $copy['step_1_title'], (string) $copy['step_1_note']],
            ['forward', '02', (string) $copy['step_2_title'], (string) $copy['step_2_note']],
            ['connect', '03', (string) $copy['step_3_title'], (string) $copy['step_3_note']],
        ];

        $items = '';
        foreach ($steps as [$icon, $index, $title, $note]) {
            $items .= '<li class="bridge-home-step">'
                . '<span class="bridge-home-step-head">'
                . '<span class="bridge-home-step-icon">' . self::icon($icon) . '</span>'
                . '<span class="bridge-home-step-index">' . $index . '</span>'
                . '</span>'
                . '<h3 class="bridge-home-step-title">' . self::e($title) . '</h3>'
                . '<p class="bridge-home-step-note">' . self::e($note) . '</p>'
                . '</li>';
        }

        return '<ul class="bridge-home-steps-grid">' . $items . '</ul>';
    }

    /** "10 420 km" with a space separator, so the unit never crowds the digits. */
    private static function distanceLabel(float $km): string
    {
        $rounded = (int) (round($km / 10.0) * 10);

        return number_format($rounded, 0, '.', ' ') . ' km';
    }

    /**
     * Shortest longitude delta, so a link jumps the antimeridian instead of
     * running backwards across the whole plate.
     */
    private static function shortestLonDelta(float $from, float $to): float
    {
        $delta = $to - $from;
        while ($delta > 180.0) {
            $delta -= 360.0;
        }
        while ($delta < -180.0) {
            $delta += 360.0;
        }

        return $delta;
    }

    /**
     * A great circle drawn as a sampled polyline. Spherical interpolation keeps
     * the curve physically true rather than approximating it with a fixed bow.
     */
    private static function greatCirclePath(float $lonA, float $latA, float $lonB, float $latB, int $segments = 48): string
    {
        $delta = self::shortestLonDelta($lonA, $lonB);
        $lonOffset = 0.0;
        if ($lonA + $delta > self::LON_MIN + self::LON_SPAN) {
            $lonOffset = -360.0;
        } elseif ($lonA + $delta < self::LON_MIN) {
            $lonOffset = 360.0;
        }

        $phiA = deg2rad($latA);
        $lambdaA = deg2rad($lonA);
        $phiB = deg2rad($latB);
        $lambdaB = deg2rad($lonB + $delta * 0.0);

        $d = 2.0 * asin(min(1.0, sqrt(
            sin(($phiB - $phiA) / 2.0) ** 2
            + cos($phiA) * cos($phiB) * sin(($lambdaB - $lambdaA) / 2.0) ** 2
        )));

        if ($d < 1e-9) {
            [$x1, $y1] = self::px($lonA, $latA, $lonOffset);
            [$x2, $y2] = self::px($lonB, $latB, $lonOffset);

            return 'M' . $x1 . ' ' . $y1 . ' L' . $x2 . ' ' . $y2;
        }

        $path = '';
        for ($i = 0; $i <= $segments; $i++) {
            $f = $i / $segments;
            $a = sin((1.0 - $f) * $d) / sin($d);
            $b = sin($f * $d) / sin($d);
            $x = $a * cos($phiA) * cos($lambdaA) + $b * cos($phiB) * cos($lambdaB);
            $y = $a * cos($phiA) * sin($lambdaA) + $b * cos($phiB) * sin($lambdaB);
            $z = $a * sin($phiA) + $b * sin($phiB);

            $lat = rad2deg(atan2($z, sqrt($x * $x + $y * $y)));
            $lon = rad2deg(atan2($y, $x));
            [$px, $py] = self::px($lon, $lat, $lonOffset);
            $path .= ($i === 0 ? 'M' : 'L') . $px . ' ' . $py . ' ';
        }

        return trim($path);
    }

    /**
     * @param array<int, array{0:float,1:float}> $ring
     */
    private static function landPath(array $ring): string
    {
        $pts = [];
        foreach ($ring as $point) {
            $pts[] = self::px($point[0], $point[1]);
        }

        $count = count($pts);
        if ($count < 3) {
            return '';
        }

        $at = static fn (int $i): array => $pts[(($i % $count) + $count) % $count];
        $d = 'M' . $pts[0][0] . ' ' . $pts[0][1];

        for ($i = 0; $i < $count; $i++) {
            [$x0, $y0] = $at($i - 1);
            [$x1, $y1] = $at($i);
            [$x2, $y2] = $at($i + 1);
            [$x3, $y3] = $at($i + 2);

            $c1x = round($x1 + ($x2 - $x0) / 6.0, 1);
            $c1y = round($y1 + ($y2 - $y0) / 6.0, 1);
            $c2x = round($x2 - ($x3 - $x1) / 6.0, 1);
            $c2y = round($y2 - ($y3 - $y1) / 6.0, 1);

            $d .= ' C' . $c1x . ' ' . $c1y . ' ' . $c2x . ' ' . $c2y . ' ' . $x2 . ' ' . $y2;
        }

        return $d . 'Z';
    }

    /**
     * @param array{code:string,name:array<string,string>,lon:float,lat:float,utc:float} $a
     * @param array{code:string,name:array<string,string>,lon:float,lat:float,utc:float} $b
     */
    private static function distanceSquared(array $a, array $b): float
    {
        $scale = cos(deg2rad(($a['lat'] + $b['lat']) / 2.0));
        $dx = ($a['lon'] - $b['lon']) * $scale;
        $dy = $a['lat'] - $b['lat'];

        return $dx * $dx + $dy * $dy;
    }

    /**
     * @param array{code:string,name:array<string,string>,lon:float,lat:float,utc:float} $point
     * @param array<int, array{code:string,name:array<string,string>,lon:float,lat:float,utc:float}> $pool
     * @param array<int, bool> $skip
     * @return array<int, int>
     */
    private static function nearestIn(array $point, array $pool, int $count, array $skip = []): array
    {
        $ranked = [];
        foreach ($pool as $i => $node) {
            if (isset($skip[$i])) {
                continue;
            }
            $ranked[$i] = self::distanceSquared($point, $node);
        }
        asort($ranked);

        return array_slice(array_keys($ranked), 0, $count);
    }

    /**
     * The plate's key: the chart carries codes only, so this is where a code
     * becomes a city.
     */
    private static function plateKey(string $locale): string
    {
        $cells = '';
        foreach (self::NODES as $node) {
            $cells .= '<li><span class="bridge-home-plate-key-code">' . self::e($node['code']) . '</span>'
                . self::e(self::placeName($node, $locale))
                . '</li>';
        }

        return '<ul class="bridge-home-plate-key">' . $cells . '</ul>';
    }

    /**
     * Screen-dependent frame geometry.
     *
     * @return array{x:float,y:float,w:float,h:float,lat_top:float,lat_span:float,aspect:float}
     */
    private static function frame(): array
    {
        $drawW = self::PLATE_W - 2.0 * 52.0;
        $perDegree = (self::PLATE_H - 32.0) / self::LAT_SPAN;

        $latestNode = -90.0;
        $earliestNode = 90.0;
        foreach (self::NODES as $node) {
            $latestNode = max($latestNode, $node['lat']);
            $earliestNode = min($earliestNode, $node['lat']);
        }

        $latBottom = max(0.0, $earliestNode - 1.5);
        $latTop = $latestNode + 2.0;
        $latSpan = $latTop - $latBottom;

        // The frame starts where the projection actually puts lat_top. Anchoring
        // it at the plate edge instead would declare one crop and draw another,
        // which silently hides the southern peers.
        $frameY = 16.0 + (self::LAT_TOP - $latTop) * $perDegree;
        $frameH = $latSpan * $perDegree;

        return [
            'x' => 52.0,
            'y' => $frameY,
            'w' => $drawW,
            'h' => $frameH,
            'lat_top' => $latTop,
            'lat_span' => $latSpan,
            'aspect' => $drawW / $frameH,
        ];
    }

    /**
     * Meridian and parallel lines with printed readings.
     */
    private static function graticule(): string
    {
        $frame = self::frame();
        $out = '';
        $labelledLon = [100, 120, 140];
        $labelledLat = [40, 20];

        for ($lon = (int) ceil(self::LON_MIN / 10.0) * 10; $lon <= self::LON_MIN + self::LON_SPAN; $lon += 10) {
            [$x1] = self::px((float) $lon, 0.0);
            $major = $lon % 30 === 0;
            $out .= '<line class="rk-grid' . ($major ? ' rk-grid-major' : '') . '" x1="' . $x1 . '" y1="' . $frame['y'] . '" x2="' . $x1 . '" y2="' . round($frame['y'] + $frame['h'], 1) . '"></line>';
            if (in_array($lon, $labelledLon, true)) {
                $out .= '<text class="rk-tick" x="' . $x1 . '" y="' . round($frame['y'] - 8.0, 1) . '">' . $lon . '°E</text>';
            }
        }

        for ($lat = (int) floor($frame['lat_top'] / 10.0) * 10; $lat >= (int) ceil(($frame['lat_top'] - $frame['lat_span']) / 10.0) * 10; $lat -= 10) {
            [, $y1] = self::px(0.0, (float) $lat);
            $major = $lat % 20 === 0;
            $out .= '<line class="rk-grid' . ($major ? ' rk-grid-major' : '') . '" x1="' . $frame['x'] . '" y1="' . $y1 . '" x2="' . round($frame['x'] + $frame['w'], 1) . '" y2="' . $y1 . '"></line>';
            if (in_array($lat, $labelledLat, true)) {
                $out .= '<text class="rk-tick rk-tick-lat" x="' . round($frame['x'] - 9.0, 1) . '" y="' . round($y1 + 3.5, 1) . '">' . $lat . '°N</text>';
            }
        }

        $out .= '<rect class="rk-edge" x="' . $frame['x'] . '" y="' . $frame['y'] . '" width="' . round($frame['w'], 1) . '" height="' . round($frame['h'], 1) . '"></rect>';

        return $out;
    }

    private static function landMasses(): string
    {
        $china = self::landPath([
            [120.2, 53.5], [122.5, 53.4], [126.0, 52.8], [130.7, 48.9], [134.8, 48.4],
            [133.0, 45.2], [131.3, 44.9], [131.2, 42.9], [128.0, 42.0], [124.4, 39.9],
            [121.7, 38.9], [119.3, 37.5], [122.7, 37.5], [120.3, 36.0], [119.2, 35.0],
            [120.3, 33.0], [121.9, 31.0], [121.0, 29.5], [121.6, 28.3], [120.0, 26.5],
            [119.3, 26.1], [118.1, 24.4], [116.7, 23.4], [113.5, 22.2], [110.4, 21.2],
            [110.5, 20.2], [109.8, 20.4], [109.6, 21.6], [108.0, 21.5], [106.7, 20.9],
            [105.3, 21.5], [103.0, 22.5], [101.1, 21.2], [99.2, 22.1], [97.5, 23.9],
            [98.7, 25.9], [97.5, 28.3], [98.7, 29.0], [99.0, 30.9], [100.9, 32.5],
            [102.6, 33.9], [103.5, 36.5], [105.8, 39.0], [109.0, 42.5], [111.9, 43.7],
            [115.6, 47.9], [117.8, 49.5],
        ]);
        $hainan = self::landPath([
            [108.7, 19.4], [110.5, 20.1], [111.0, 19.6], [110.6, 18.4], [109.5, 18.2], [108.6, 18.9],
        ]);
        $taiwan = self::landPath([
            [121.0, 25.3], [122.0, 25.0], [121.6, 23.0], [120.9, 22.0], [120.2, 22.6],
            [120.2, 23.8], [120.7, 24.9],
        ]);
        $korea = self::landPath([
            [126.6, 37.8], [127.5, 38.3], [128.4, 38.4], [129.4, 37.5], [129.5, 35.5],
            [129.1, 34.9], [127.7, 34.4], [126.5, 34.4], [126.3, 35.5], [126.6, 36.8], [126.4, 37.5],
        ]);
        $honshu = self::landPath([
            [130.9, 33.9], [131.7, 34.7], [132.5, 33.9], [133.0, 34.4], [135.4, 33.5],
            [136.9, 34.6], [138.3, 34.6], [139.8, 34.9], [140.9, 35.7], [140.6, 36.8],
            [141.0, 38.3], [141.5, 39.5], [141.9, 40.5], [141.5, 41.4], [140.6, 41.4],
            [140.3, 40.2], [139.7, 38.8], [138.5, 37.4], [137.2, 36.9], [136.6, 36.6],
            [135.9, 35.6], [135.0, 35.6], [133.5, 35.5], [132.0, 35.0], [131.0, 34.4],
        ]);
        $kyushu = self::landPath([
            [130.2, 33.6], [131.2, 33.6], [131.7, 32.8], [131.4, 31.4], [130.7, 31.0],
            [130.2, 31.6], [129.8, 32.6],
        ]);
        $shikoku = self::landPath([
            [132.7, 33.5], [134.0, 34.2], [134.7, 33.8], [134.2, 33.2], [133.0, 33.0],
        ]);
        $hokkaido = self::landPath([
            [140.0, 41.7], [140.5, 43.3], [141.7, 45.5], [144.0, 44.4], [145.5, 43.4],
            [143.5, 42.3], [141.8, 42.6], [141.0, 41.4],
        ]);
        $newGuinea = self::landPath([
            [131.0, -1.0], [135.0, -1.5], [141.0, -2.6], [147.0, -5.5], [150.8, -6.6],
            [148.0, -10.0], [143.0, -8.9], [138.0, -8.2], [134.0, -4.5], [132.0, -2.8],
        ]);
        $sulawesi = self::landPath([
            [119.5, 1.5], [121.0, 0.9], [123.0, 0.8], [125.2, 1.7], [124.0, 0.5],
            [121.5, -0.9], [120.5, -2.5], [119.6, -3.5], [118.8, -2.5], [119.0, 0.0],
        ]);
        $indochina = self::landPath([
            [109.5, 21.5], [107.5, 20.5], [106.5, 18.5], [107.2, 16.0], [109.0, 13.5],
            [109.5, 11.3], [105.0, 9.4], [102.0, 12.0], [100.5, 13.4], [100.0, 17.0],
            [100.5, 20.3], [103.0, 22.0], [105.0, 22.6], [108.0, 21.6],
        ]);
        $philippines = self::landPath([
            [120.6, 18.5], [122.2, 18.4], [122.5, 16.0], [124.0, 13.0], [126.6, 7.8],
            [125.4, 5.6], [122.0, 6.9], [120.0, 12.5], [119.8, 16.4],
        ]);
        $borneo = self::landPath([
            [109.5, 1.9], [113.0, 3.2], [117.5, 4.2], [119.0, 2.2], [117.6, 0.0],
            [116.5, -1.5], [114.5, -3.5], [110.2, -3.0], [109.0, -0.8],
        ]);
        $sumatra = self::landPath([
            [95.3, 5.6], [98.7, 3.9], [102.3, 1.0], [105.9, -2.2], [105.0, -5.9],
            [102.2, -5.2], [100.3, -2.5], [97.0, 1.5],
        ]);
        $java = self::landPath([
            [105.2, -6.0], [110.5, -6.4], [114.5, -8.2], [112.0, -8.9], [106.5, -7.4],
        ]);
        $australia = self::landPath([
            [114.0, -22.0], [115.0, -31.5], [119.0, -34.5], [126.0, -32.3], [132.0, -31.5],
            [138.0, -34.0], [141.0, -38.0], [144.0, -38.5], [148.5, -37.5], [150.0, -36.0],
            [153.0, -28.0], [153.5, -25.0], [149.0, -20.5], [145.5, -14.5], [142.5, -10.7],
            [140.0, -17.5], [136.5, -12.2], [132.0, -11.2], [129.5, -14.5], [126.0, -14.0],
            [122.0, -17.5], [117.0, -20.5],
        ]);

        $far = [$australia, $newGuinea, $philippines, $java, $sumatra, $borneo, $sulawesi,
            $indochina, $korea, $honshu, $shikoku, $kyushu, $hokkaido, $taiwan];

        // non-scaling-stroke keeps every coastline the same weight on screen
        // whether the plate is 320 or 550 pixels wide.
        $out = '';
        foreach ($far as $path) {
            $out .= '<path class="rk-land rk-land-far" vector-effect="non-scaling-stroke" d="' . $path . '"></path>';
        }

        return $out
            . '<path class="rk-land rk-land-near" vector-effect="non-scaling-stroke" d="' . $hainan . '"></path>'
            . '<path class="rk-land rk-land-near" vector-effect="non-scaling-stroke" d="' . $china . '"></path>';
    }

    /**
     * The plate: graticule, landmasses, mesh links, nodes, then the codes.
     */
    private static function routePlate(array $copy): string
    {
        $links = '';
        $nodes = '';
        $drawn = [];

        foreach (self::NODES as $index => $node) {
            foreach (self::nearestIn($node, self::NODES, 3, [$index => true]) as $peer) {
                $key = $index < $peer ? $index . '-' . $peer : $peer . '-' . $index;
                if (isset($drawn[$key])) {
                    continue;
                }
                $drawn[$key] = true;
                $peerNode = self::NODES[$peer];
                $links .= '<path class="rk-link rk-link-mesh" vector-effect="non-scaling-stroke" d="'
                    . self::greatCirclePath($node['lon'], $node['lat'], $peerNode['lon'], $peerNode['lat']) . '"></path>';
            }
        }

        foreach (self::NODES as $node) {
            [$x, $y] = self::px($node['lon'], $node['lat']);
            // Tight halo, solid core, white ring: the ring is what keeps the dot
            // readable where several peers land within a few pixels of each other.
            $nodes .= '<g class="rk-node">'
                . '<circle class="rk-halo" cx="' . $x . '" cy="' . $y . '" r="9"></circle>'
                . '<circle class="rk-dot" vector-effect="non-scaling-stroke" cx="' . $x . '" cy="' . $y . '" r="4.6"></circle>'
                . '</g>';
        }

        // The plate is cropped to the solved frame, so every peer the labels name
        // is actually on screen, and "meet" keeps a rounding difference between
        // this viewBox and the CSS aspect ratio from cropping an edge.
        $frame = self::frame();
        $viewBox = round($frame['x'], 1) . ' ' . round($frame['y'], 1) . ' '
            . round($frame['w'], 1) . ' ' . round($frame['h'], 1);
        $description = (string) $copy['map_label'] . ' · ' . self::distanceLabel(self::meshSpan());

        return '<svg class="bridge-home-map" viewBox="' . $viewBox . '" preserveAspectRatio="xMidYMid meet" role="img" aria-label="' . self::e($description) . '">'
            . '<g class="rk-chart">' . self::graticule() . '</g>'
            . '<g class="rk-lands">' . self::landMasses() . '</g>'
            . '<g class="rk-links">' . $links . '</g>'
            . '<g class="rk-nodes">' . $nodes . '</g>'
            . '<g class="rk-labels">' . self::nodeLabels($frame) . '</g>'
            . '</svg>';
    }

    /**
     * The IATA codes, on the plate, next to their dot.
     *
     * The chart carries the three-letter airport codes only — no city names, no
     * localised text — so the ladder tries eight positions around each dot and
     * takes the first that stays inside the solved frame and touches no code
     * already placed. Without that, HKG and CAN — fourteen plate units apart —
     * would print on top of each other.
     */
    private static function nodeLabels(array $frame): string
    {
        $fontSize = 15.0;
        $slots = [
            ['start', 12.0, 5.0], ['end', -12.0, 5.0],
            ['start', 10.0, -11.0], ['end', -10.0, -11.0],
            ['start', 10.0, 19.0], ['end', -10.0, 19.0],
            ['middle', 0.0, -14.0], ['middle', 0.0, 22.0],
        ];

        $out = '';
        $placed = [];

        foreach (self::NODES as $node) {
            [$x, $y] = self::px($node['lon'], $node['lat']);
            // The padding is the breathing room the collision test enforces, so
            // two neighbouring names never render as one run of text.
            $width = self::labelWidth($node['code'], $fontSize) + 12.0;
            $chosen = $slots[0];
            $box = null;

            foreach ($slots as $slot) {
                [$anchor, $dx, $dy] = $slot;
                $left = $anchor === 'end' ? $x + $dx - $width
                    : ($anchor === 'middle' ? $x + $dx - $width / 2.0 : $x + $dx);
                $candidate = [
                    $left,
                    $y + $dy - $fontSize * 0.78,
                    $left + $width,
                    $y + $dy + $fontSize * 0.24,
                ];

                if ($candidate[0] < $frame['x'] + 2.0 || $candidate[2] > $frame['x'] + $frame['w'] - 2.0) {
                    continue;
                }
                if ($candidate[1] < $frame['y'] + 2.0 || $candidate[3] > $frame['y'] + $frame['h'] - 2.0) {
                    continue;
                }
                foreach ($placed as $taken) {
                    if ($candidate[0] < $taken[2] && $candidate[2] > $taken[0]
                        && $candidate[1] < $taken[3] && $candidate[3] > $taken[1]) {
                        continue 2;
                    }
                }

                $chosen = $slot;
                $box = $candidate;
                break;
            }

            if ($box === null) {
                // Every slot is taken: keep the name, take the first position.
                $chosen = $slots[0];
                $box = [
                    $x + $chosen[1],
                    $y + $chosen[2] - $fontSize * 0.78,
                    $x + $chosen[1] + $width,
                    $y + $chosen[2] + $fontSize * 0.24,
                ];
            }
            $placed[] = $box;

            $out .= '<text class="rk-node-label" x="' . round($x + $chosen[1], 1)
                . '" y="' . round($y + $chosen[2], 1)
                . '" text-anchor="' . $chosen[0] . '">' . self::e($node['code']) . '</text>';
        }

        return $out;
    }

    /**
     * Label width in plate units. Latin runs about half an em in this face and
     * CJK a full one; the estimate only has to be good enough to keep two names
     * from touching.
     */
    private static function labelWidth(string $label, float $fontSize): float
    {
        $units = 0.0;
        foreach (preg_split('//u', $label, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $units += strlen($char) > 1 ? 1.0 : 0.55;
        }

        return $units * $fontSize;
    }

    public static function render(string $brand, string $consoleHref, ?string $locale = null): string
    {
        $current = LocaleResolver::normalize((string) $locale) ?? LocaleResolver::DEFAULT_LOCALE;
        $copy = self::strings($current);
        $href = self::e($consoleHref);

        $carriers = '';
        foreach (self::CARRIERS as $carrier) {
            $carriers .= '<li class="bridge-home-carrier bridge-home-carrier--' . $carrier['key'] . '">'
                . '<span class="bridge-home-carrier-mark" aria-hidden="true">' . self::carrierMark($carrier['key']) . '</span>'
                . '<span class="bridge-home-carrier-name">' . self::e((string) ($carrier['label'][$current] ?? $carrier['label']['en'])) . '</span>'
                . '</li>';
        }

        return '<section class="bridge-home-hero" aria-labelledby="bridge-home-title">'
            . '<div class="bridge-home-aurora" aria-hidden="true"></div>'
            . '<div class="bridge-home-gridlines" aria-hidden="true"></div>'
            . '<div class="bridge-home-hero-stage">'
            . '<div class="bridge-home-network">'
            . self::routePlate($copy)
            . '<div class="bridge-home-network-meta">'
            . '<span class="bridge-home-kicker">' . self::e((string) $copy['atlas_kicker']) . '</span>'
            . '<span class="bridge-home-network-note">' . self::e((string) $copy['atlas_note']) . '</span>'
            . '</div>'
            . '</div>'
            . '<div class="bridge-home-hero-inner">'
            . '<div class="bridge-home-hero-copy">'
            . '<p class="bridge-home-tag">'
            . '<span class="bridge-home-tag-dot" aria-hidden="true"></span>'
            . self::e((string) $copy['hero_badge'])
            . '</p>'
            . '<h1 id="bridge-home-title" class="bridge-home-title">'
            . '<span class="bridge-home-title-zh">' . self::e((string) $copy['title_zh']) . '</span>'
            . '<span class="bridge-home-title-en">' . self::e((string) $copy['title_en']) . '</span>'
            . '</h1>'
            . '<p class="bridge-home-hero-sub">' . self::e((string) $copy['hero_sub']) . '</p>'
            . '<div class="bridge-home-hero-actions">'
            . '<a class="bridge-home-primary" href="' . $href . '">' . self::e((string) $copy['primary']) . '</a>'
            . '<a class="bridge-home-ghost" href="/knowledgebase?thread=getting-started-five-minute-overview">' . self::e((string) $copy['hero_guide_cta']) . '</a>'
            . '</div>'
            . self::platformRow($copy)
            . '</div>'
            . '</div>'
            . '</div>'
            . '</section>'
            . self::plateKey($current)
            . '<section class="bridge-home-features" aria-labelledby="spec-heading">'
            . '<div class="bridge-home-band">'
            . '<header class="bridge-home-band-head">'
            . '<p class="bridge-home-kicker">' . self::e((string) $copy['spec_kicker']) . '</p>'
            . '<h2 id="spec-heading">' . self::e((string) $copy['spec_title']) . '</h2>'
            . '</header>'
            . self::statTiles($copy)
            . '</div>'
            . '</section>'
            . '<section class="bridge-home-carrier-band" aria-label="' . self::e((string) $copy['carriers_kicker']) . '">'
            . '<div class="bridge-home-band">'
            . '<header class="bridge-home-band-head">'
            . '<p class="bridge-home-kicker">' . self::e((string) $copy['carriers_kicker']) . '</p>'
            . '<h2>' . self::e((string) $copy['carriers_title']) . '</h2>'
            . '<p class="bridge-home-note">' . self::e((string) $copy['carriers_note']) . '</p>'
            . '</header>'
            . '<ul class="bridge-home-carriers">' . $carriers . '</ul>'
            . '</div>'
            . '</section>'
            . '<section class="bridge-home-steps" aria-labelledby="steps-heading">'
            . '<div class="bridge-home-band">'
            . '<header class="bridge-home-band-head">'
            . '<p class="bridge-home-kicker">' . self::e((string) $copy['steps_kicker']) . '</p>'
            . '<h2 id="steps-heading">' . self::e((string) $copy['steps_title']) . '</h2>'
            . '</header>'
            . self::stepTiles($copy)
            . '</div>'
            . '</section>'
            . '<section class="bridge-home-closing">'
            . '<div class="bridge-home-band">'
            . '<h2>' . self::e((string) $copy['closing_title']) . '</h2>'
            . '<div class="bridge-home-closing-actions">'
            . '<a class="bridge-home-primary" href="' . $href . '">' . self::e((string) $copy['console_link']) . '</a>'
            . '<a class="bridge-home-ghost" href="/knowledgebase">' . self::e((string) $copy['guides_link']) . '</a>'
            . '</div>'
            . '</div>'
            . '</section>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
