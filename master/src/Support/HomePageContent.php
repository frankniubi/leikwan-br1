<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

/**
 * Homepage for the Leikwan Bridge public surface.
 *
 * High-performance transit mesh and intelligent forwarding control plane.
 * The first screen renders the global peer mesh drawn on a recognizable coast,
 * paired with a clear, authoritative value proposition and bento capabilities grid.
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
     * @var array<int, array{key:string,glyph:string,name:array<string,string>,legal:array<string,string>}>
     */
    private const CARRIERS = [
        ['key' => 'ct', 'glyph' => 'CT', 'name' => ['en' => 'China Telecom', 'zh-Hans' => '中国电信', 'zh-TW' => '中國電信'], 'legal' => ['en' => 'China Telecom', 'zh-Hans' => '中国电信', 'zh-TW' => '中國電信']],
        ['key' => 'cu', 'glyph' => 'CU', 'name' => ['en' => 'China Unicom', 'zh-Hans' => '中国联通', 'zh-TW' => '中國聯通'], 'legal' => ['en' => 'China Unicom', 'zh-Hans' => '中国联通', 'zh-TW' => '中國聯通']],
        ['key' => 'cm', 'glyph' => 'CM', 'name' => ['en' => 'China Mobile', 'zh-Hans' => '中国移动', 'zh-TW' => '中國移動'], 'legal' => ['en' => 'China Mobile', 'zh-Hans' => '中国移动', 'zh-TW' => '中國移動']],
        ['key' => 'bgp', 'glyph' => 'BGP', 'name' => ['en' => 'Multi-line BGP', 'zh-Hans' => 'BGP 多线', 'zh-TW' => 'BGP 多線'], 'legal' => ['en' => 'BGP multi-line', 'zh-Hans' => 'BGP 多线', 'zh-TW' => 'BGP 多線']],
    ];

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
                'hero_badge' => 'PEER MESH · 13 GLOBAL HUBS ONLINE',
                'title_zh' => '直達.',
                'title_en' => 'Direct.',
                'hero_sub' => 'High-Performance Transit Mesh & Forwarding Control Plane',
                'hero_lede' => 'Engineered for sub-millisecond gaming acceleration, application transit, and resilient port forwarding. Full peer mesh with carrier-grade landing.',
                'primary' => 'Enter Console · 进入控制台',
                'hero_guide_cta' => 'Getting Started · 新手指南',
                'platforms_label' => 'Native Runtimes: Windows · macOS · Android · Linux',
                'features_kicker' => 'Capabilities · 核心能力',
                'features_title' => 'Engineered for Speed, Reliability, and Precision.',
                'features_note' => 'From competitive multiplayer to enterprise application transit, LeiKwan Bridge provides transparent routing with zero packet loss.',
                'feat_mesh_title' => 'Any-to-Any Peer Mesh',
                'feat_mesh_desc' => 'Direct bidirectional peer links across 13 global hubs. True full mesh with zero single-point bottleneck.',
                'feat_carriers_title' => 'Carrier-Grade Landing',
                'feat_carriers_desc' => 'China Telecom, China Unicom, China Mobile, and BGP multi-line peers landing directly where the city sits.',
                'feat_transit_title' => 'Ultra-Low Jitter Transit',
                'feat_transit_desc' => 'Optimized transmission paths for TCP and UDP, eliminating packet loss and public network congestion.',
                'feat_runtimes_title' => 'Native Cross-Platform Clients',
                'feat_runtimes_desc' => 'Lightweight background daemons and GUI clients with automated route discovery and silent sync.',
                'map_label' => 'Mesh of {n} peers across East Asia',
                'mesh_label' => 'Mesh',
                'atlas_kicker' => 'Atlas · 全球拓扑',
                'atlas_title' => 'Thirteen peers. One mesh.',
                'atlas_note' => 'Any node reaches any other, in either direction. The panel issues the route. The client takes it from there.',
                'carriers_kicker' => 'Carriers · 运营商专线',
                'carriers_title' => 'Telecom, Unicom, Mobile, BGP.',
                'carriers_note' => 'Single-line or multi-line at the node. Direct landing where the city sits.',
                'steps_kicker' => 'Workflow · 快速上手',
                'steps_title' => 'Three Steps from Subscription to Game.',
                'steps_note' => 'No complex networking setup. Configure in seconds and let the native client handle the rest.',
                'step_1_title' => 'Select Line',
                'step_1_desc' => 'Verify your plan in console and pick the optimal transit line closest to your service.',
                'step_2_title' => 'Create Forward',
                'step_2_desc' => 'Input target server address and port to receive a dedicated local mapping port.',
                'step_3_title' => 'Connect & Accelerate',
                'step_3_desc' => 'Launch client, point your app or game to 127.0.0.1, and enjoy uninterrupted low latency.',
                'closing_kicker' => 'Console · 控制台',
                'closing_title' => 'Create a forward. Install the client.',
                'closing_lede' => 'Windows, macOS, Android and Linux. The apps finish the rest.',
                'console_link' => 'Enter Console · 进入',
                'guides_link' => 'Guides · 指南',
            ],
            'zh-Hans' => [
                'hero_badge' => '全网状对等网络 · 13 个核心枢纽在线',
                'title_zh' => '直達.',
                'title_en' => 'Direct.',
                'hero_sub' => '专业级低延迟专线与流量转发控制平面',
                'hero_lede' => '专为电竞加速、关键业务中继与端口转发打造。全网状对等互联，多线 BGP 智能直出，告别网络拥塞与抖动。',
                'primary' => '进入控制台 · Enter',
                'hero_guide_cta' => '新手指南 · Guide',
                'platforms_label' => '原生客户端：Windows · macOS · Android · Linux',
                'features_kicker' => '核心能力 · Capabilities',
                'features_title' => '专为极致速度、可靠中继与精准调度而生',
                'features_note' => '无论是联机电竞加速还是关键业务中继，利群會聚均可提供高稳定、抗抖动的透明传输。',
                'feat_mesh_title' => '全网状对等互联',
                'feat_mesh_desc' => '东亚与全球 13 大核心节点对等互通，双向直达，无单点瓶颈与额外中转。',
                'feat_carriers_title' => '多线运营商直出',
                'feat_carriers_desc' => '中国电信、中国联通、中国移动专线与 BGP 多线，城市所在地直接出网。',
                'feat_transit_title' => '极致低延迟与抗丢包',
                'feat_transit_desc' => '深度优化 TCP/UDP 数据包转发路径，有效平抑公网抖动与网络重传。',
                'feat_runtimes_title' => '原生全平台客户端',
                'feat_runtimes_desc' => '适配桌面与移动系统，一键登录同步线路，极低系统开销静默守护。',
                'map_label' => '{cn} 个对等节点组成的东亚网状网络',
                'mesh_label' => '网状网络',
                'atlas_kicker' => '图鉴 · Atlas',
                'atlas_title' => '十三个对等节点，一张网。',
                'atlas_note' => '任意节点双向互通。面板下发线路，客户端接着把事情做完。',
                'carriers_kicker' => '线路 · Carriers',
                'carriers_title' => '电信、联通、移动、BGP。',
                'carriers_note' => '单线或多线落在节点当地，在城市所在地直接出网。',
                'steps_kicker' => '快速上手 · Workflow',
                'steps_title' => '三步就绪，即刻开启极速连接',
                'steps_note' => '无需复杂的网络配置经验，五分钟内即可完成转发配置并建立稳定连接。',
                'step_1_title' => '选配套餐与线路',
                'step_1_desc' => '在控制台确认有效套餐，选择离目标服务最近的对等线路。',
                'step_2_title' => '创建转发规则',
                'step_2_desc' => '输入目标服务地址与端口，获取控制台分配的本地映射端口。',
                'step_3_title' => '连接客户端并加速',
                'step_3_desc' => '启动客户端一键连接，将游戏或应用指向 127.0.0.1 即可畅享加速。',
                'closing_kicker' => '控制台 · Console',
                'closing_title' => '建一条转发，装上客户端。',
                'closing_lede' => 'Windows、macOS、Android 与 Linux。应用把剩下的做完。',
                'console_link' => '进入控制台 · Enter',
                'guides_link' => '指南 · Guides',
            ],
            'zh-TW' => [
                'hero_badge' => '全網狀對等網路 · 13 個核心樞紐上線',
                'title_zh' => '直達.',
                'title_en' => 'Direct.',
                'hero_sub' => '專業級低延遲專線與流量轉發控制平面',
                'hero_lede' => '專為電競加速、關鍵業務中繼與連接埠轉發打造。全網狀對等互聯，多線 BGP 智慧直出，告別網路壅塞與抖動。',
                'primary' => '進入控制台 · Enter',
                'hero_guide_cta' => '新手指南 · Guide',
                'platforms_label' => '原生用戶端：Windows · macOS · Android · Linux',
                'features_kicker' => '核心能力 · Capabilities',
                'features_title' => '專為極致速度、可靠中繼與精準調度而生',
                'features_note' => '無論是連線電競加速還是關鍵業務中繼，利群會聚均可提供高穩定、抗抖動的透明傳輸。',
                'feat_mesh_title' => '全網狀對等互聯',
                'feat_mesh_desc' => '東亞與全球 13 大核心節點對等互通，雙向直達，無單點瓶頸與額外中轉。',
                'feat_carriers_title' => '多線電信商直出',
                'feat_carriers_desc' => '中國電信、中國聯通、中國移動專線與 BGP 多線，城市所在地直接出網。',
                'feat_transit_title' => '極致低延遲與抗封包遺失',
                'feat_transit_desc' => '深度最佳化 TCP/UDP 封包轉發路徑，有效平抑公網抖動與網路重傳。',
                'feat_runtimes_title' => '原生全平台用戶端',
                'feat_runtimes_desc' => '適配桌面與行動系統，一鍵登入同步線路，極低系統開銷靜默守護。',
                'map_label' => '{cn} 個對等節點組成的東亞網狀網路',
                'mesh_label' => '網狀網路',
                'atlas_kicker' => '圖鑑 · Atlas',
                'atlas_title' => '十三個對等節點，一張網。',
                'atlas_note' => '任意節點雙向互通。面板下發線路，用戶端接著把事情做完。',
                'carriers_kicker' => '線路 · Carriers',
                'carriers_title' => '電信、聯通、移動、BGP。',
                'carriers_note' => '單線或多線落在節點當地，在城市所在地直接出網。',
                'steps_kicker' => '快速上手 · Workflow',
                'steps_title' => '三步就緒，即刻開啟極速連線',
                'steps_note' => '無需複雜的網路設定經驗，五分鐘內即可完成轉發設定並建立穩定連線。',
                'step_1_title' => '選配套餐與線路',
                'step_1_desc' => '在控制台確認有效方案，選擇離目標服務最近的對等線路。',
                'step_2_title' => '建立轉發規則',
                'step_2_desc' => '輸入目標服務位址與連接埠，取得控制台分配的本機映射連接埠。',
                'step_3_title' => '連線用戶端並加速',
                'step_3_desc' => '啟動用戶端一鍵連線，將遊戲或應用指向 127.0.0.1 即可暢享加速。',
                'closing_kicker' => '控制台 · Console',
                'closing_title' => '建一條轉發，裝上用戶端。',
                'closing_lede' => 'Windows、macOS、Android 與 Linux。應用把剩下的做完。',
                'console_link' => '進入控制台 · Enter',
                'guides_link' => '指南 · Guides',
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

    /** "31.2N" / "121.5E" — one decimal is the honest precision for these cities. */
    private static function coord(float $value, string $positive, string $negative): string
    {
        $hemisphere = $value >= 0 ? $positive : $negative;

        return number_format(abs($value), 1) . $hemisphere;
    }

    /** "UTC+09:00" reads unambiguously in every locale, unlike "GMT+9". */
    private static function utcLabel(float $offset): string
    {
        $sign = $offset < 0 ? '-' : '+';
        $absolute = abs($offset);
        $hours = (int) floor($absolute);
        $minutes = (int) round(($absolute - $hours) * 60.0);

        return 'UTC' . $sign . str_pad((string) $hours, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) $minutes, 2, '0', STR_PAD_LEFT);
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

        $out = '';
        foreach ($far as $path) {
            $out .= '<path class="rk-land rk-land-far" d="' . $path . '"></path>';
        }

        return $out
            . '<path class="rk-land rk-land-near" d="' . $hainan . '"></path>'
            . '<path class="rk-land rk-land-near" d="' . $china . '"></path>';
    }

    /**
     * The plate: graticule, landmasses, mesh links, then nodes.
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
                $links .= '<path class="rk-link rk-link-mesh" d="'
                    . self::greatCirclePath($node['lon'], $node['lat'], $peerNode['lon'], $peerNode['lat']) . '"></path>';
            }
        }

        foreach (self::NODES as $node) {
            [$x, $y] = self::px($node['lon'], $node['lat']);
            $nodes .= '<g class="rk-node">'
                . '<circle class="rk-halo" cx="' . $x . '" cy="' . $y . '" r="10"></circle>'
                . '<circle class="rk-dot" cx="' . $x . '" cy="' . $y . '" r="3.2"></circle>'
                . '</g>';
        }

        // The plate is cropped to the solved frame, so every peer the key lists
        // is actually on screen, and "meet" keeps a rounding difference between
        // this viewBox and the CSS aspect ratio from cropping an edge.
        $frame = self::frame();
        $viewBox = round($frame['x'], 1) . ' ' . round($frame['y'], 1) . ' '
            . round($frame['w'], 1) . ' ' . round($frame['h'], 1);

        return '<svg class="bridge-home-map" viewBox="' . $viewBox . '" preserveAspectRatio="xMidYMid meet" role="img" aria-label="' . self::e((string) $copy['map_label']) . '">'
            . '<g class="rk-chart">' . self::graticule() . '</g>'
            . '<g class="rk-lands">' . self::landMasses() . '</g>'
            . '<g class="rk-links">' . $links . '</g>'
            . '<g class="rk-nodes">' . $nodes . '</g>'
            . '</svg>';
    }

    public static function render(string $brand, string $consoleHref, ?string $locale = null): string
    {
        $current = LocaleResolver::normalize((string) $locale) ?? LocaleResolver::DEFAULT_LOCALE;
        $copy = self::strings($current);
        $href = self::e($consoleHref);

        $atlas = '';
        foreach (self::NODES as $node) {
            $atlas .= '<li class="bridge-home-city">'
                . '<span class="bridge-home-city-code">' . self::e($node['code']) . '</span>'
                . '<span class="bridge-home-city-name">' . self::e(self::placeName($node, $current)) . '</span>'
                . '</li>';
        }

        $carriers = '';
        foreach (self::CARRIERS as $carrier) {
            $carriers .= '<li class="bridge-home-carrier bridge-home-carrier--' . $carrier['key'] . '">'
                . '<span class="bridge-home-carrier-mark" aria-hidden="true">' . self::carrierMark($carrier['key']) . '</span>'
                . '<span class="bridge-home-carrier-name">' . self::e((string) ($carrier['name'][$current] ?? $carrier['name']['en'])) . '</span>'
                . '<span class="bridge-home-carrier-legal">' . self::e($carrier['legal'][$current] ?? $carrier['legal']['en']) . '</span>'
                . '</li>';
        }

        return '<section class="bridge-home-hero" aria-labelledby="bridge-home-title">'
            . '<div class="bridge-home-hero-art">'
            . self::routePlate($copy)
            . '</div>'
            . '<div class="bridge-home-hero-copy">'
            . '<div class="bridge-home-tag">'
            . '<span class="bridge-home-tag-dot" aria-hidden="true"></span>'
            . '<span>' . self::e((string) $copy['hero_badge']) . '</span>'
            . '</div>'
            . '<h1 id="bridge-home-title">'
            . '<span lang="zh-Hant" class="bridge-home-title-zh">' . self::e((string) $copy['title_zh']) . '</span>'
            . '<span lang="en" class="bridge-home-title-en">' . self::e((string) $copy['title_en']) . '</span>'
            . '</h1>'
            . '<p class="bridge-home-hero-sub">' . self::e((string) $copy['hero_sub']) . '</p>'
            . '<p class="bridge-home-hero-lede">' . self::e((string) $copy['hero_lede']) . '</p>'
            . '<div class="bridge-home-hero-actions">'
            . '<a class="bridge-home-primary" href="' . $href . '">' . self::e((string) $copy['primary']) . '</a>'
            . '<a class="bridge-home-ghost" href="/knowledgebase?thread=getting-started-five-minute-overview">' . self::e((string) $copy['hero_guide_cta']) . '</a>'
            . '</div>'
            . '<div class="bridge-home-hero-runtimes">'
            . '<span class="bridge-home-runtimes-label">' . self::e((string) $copy['platforms_label']) . '</span>'
            . '</div>'
            . '</div>'
            . '</section>'
            . '<section class="bridge-home-features" aria-labelledby="features-heading">'
            . '<div class="bridge-home-band">'
            . '<header class="bridge-home-band-head">'
            . '<p class="bridge-home-kicker">' . self::e((string) $copy['features_kicker']) . '</p>'
            . '<h2 id="features-heading">' . self::e((string) $copy['features_title']) . '</h2>'
            . '<p class="bridge-home-note">' . self::e((string) $copy['features_note']) . '</p>'
            . '</header>'
            . '<div class="bridge-home-bento">'
            . '<div class="bridge-home-bento-card bridge-home-line-card">'
            . '<span class="bridge-home-bento-num">01</span>'
            . '<h3>' . self::e((string) $copy['feat_mesh_title']) . '</h3>'
            . '<p>' . self::e((string) $copy['feat_mesh_desc']) . '</p>'
            . '</div>'
            . '<div class="bridge-home-bento-card bridge-home-line-card">'
            . '<span class="bridge-home-bento-num">02</span>'
            . '<h3>' . self::e((string) $copy['feat_carriers_title']) . '</h3>'
            . '<p>' . self::e((string) $copy['feat_carriers_desc']) . '</p>'
            . '</div>'
            . '<div class="bridge-home-bento-card bridge-home-line-card">'
            . '<span class="bridge-home-bento-num">03</span>'
            . '<h3>' . self::e((string) $copy['feat_transit_title']) . '</h3>'
            . '<p>' . self::e((string) $copy['feat_transit_desc']) . '</p>'
            . '</div>'
            . '<div class="bridge-home-bento-card bridge-home-line-card">'
            . '<span class="bridge-home-bento-num">04</span>'
            . '<h3>' . self::e((string) $copy['feat_runtimes_title']) . '</h3>'
            . '<p>' . self::e((string) $copy['feat_runtimes_desc']) . '</p>'
            . '</div>'
            . '</div>'
            . '</div>'
            . '</section>'
            . '<section class="bridge-home-network" aria-label="' . self::e((string) $copy['mesh_label']) . '">'
            . '<div class="bridge-home-band">'
            . '<header class="bridge-home-band-head">'
            . '<p class="bridge-home-kicker">' . self::e((string) $copy['atlas_kicker']) . '</p>'
            . '<h2>' . self::e((string) $copy['atlas_title']) . '</h2>'
            . '<p class="bridge-home-note">' . self::e((string) $copy['atlas_note']) . '</p>'
            . '</header>'
            . '<ul class="bridge-home-cities">' . $atlas . '</ul>'
            . self::plateKey($current)
            . '</div>'
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
            . '<p class="bridge-home-note">' . self::e((string) $copy['steps_note']) . '</p>'
            . '</header>'
            . '<div class="bridge-home-steps-grid">'
            . '<div class="bridge-home-step-item">'
            . '<span class="bridge-home-step-badge">STEP 01</span>'
            . '<h3>' . self::e((string) $copy['step_1_title']) . '</h3>'
            . '<p>' . self::e((string) $copy['step_1_desc']) . '</p>'
            . '</div>'
            . '<div class="bridge-home-step-item">'
            . '<span class="bridge-home-step-badge">STEP 02</span>'
            . '<h3>' . self::e((string) $copy['step_2_title']) . '</h3>'
            . '<p>' . self::e((string) $copy['step_2_desc']) . '</p>'
            . '</div>'
            . '<div class="bridge-home-step-item">'
            . '<span class="bridge-home-step-badge">STEP 03</span>'
            . '<h3>' . self::e((string) $copy['step_3_title']) . '</h3>'
            . '<p>' . self::e((string) $copy['step_3_desc']) . '</p>'
            . '</div>'
            . '</div>'
            . '</div>'
            . '</section>'
            . '<section class="bridge-home-closing">'
            . '<div class="bridge-home-band">'
            . '<p class="bridge-home-kicker">' . self::e((string) $copy['closing_kicker']) . '</p>'
            . '<h2>' . self::e((string) $copy['closing_title']) . '</h2>'
            . '<p class="bridge-home-note">' . self::e((string) $copy['closing_lede']) . '</p>'
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
