<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

/**
 * Validation and canonical form for a user's own proxy node that the panel
 * hosts for a client (Shadowsocks, SOCKS5, VLESS).
 *
 * The node stays the user's: the panel only stores it and hands it back to
 * the client's trusted devices, which pair it with a service forward and dial
 * it through 127.0.0.1:<forward listen port>. Nothing here ever reaches a
 * forward or relay.
 *
 * normalize() accepts either a share link ("uri") or structured fields and
 * returns one canonical array. toShareUri() renders that array as the share
 * link the Android ProxyUriParser already imports, so a hosted node behaves
 * exactly like one the user pasted on the device.
 *
 * No mb_* functions: production PHP has no mbstring.
 */
final class HostedNodeSpec
{
    public const PROTOCOL_SHADOWSOCKS = 'shadowsocks';
    public const PROTOCOL_SOCKS5 = 'socks5';
    public const PROTOCOL_VLESS = 'vless';
    public const PROTOCOLS = [self::PROTOCOL_SHADOWSOCKS, self::PROTOCOL_SOCKS5, self::PROTOCOL_VLESS];

    public const MAX_URI_BYTES = 4096;
    public const MAX_NAME_BYTES = 96;

    /** AEAD and 2022 methods sing-box supports. Legacy stream ciphers are refused. */
    public const SS_METHODS = [
        '2022-blake3-aes-128-gcm',
        '2022-blake3-aes-256-gcm',
        '2022-blake3-chacha20-poly1305',
        'aes-128-gcm',
        'aes-192-gcm',
        'aes-256-gcm',
        'chacha20-ietf-poly1305',
        'xchacha20-ietf-poly1305',
    ];

    /** sing-box plugin names; simple-obfs is the SIP003 alias of obfs-local. */
    public const SS_PLUGINS = ['obfs-local', 'v2ray-plugin'];

    public const VLESS_SECURITIES = ['none', 'tls', 'reality'];
    public const VLESS_TRANSPORTS = ['tcp', 'ws', 'grpc', 'httpupgrade'];
    public const VLESS_FLOWS = ['', 'xtls-rprx-vision'];
    public const VLESS_ALPN = ['h2', 'http/1.1', 'h3'];
    public const UTLS_FINGERPRINTS = [
        'chrome', 'firefox', 'edge', 'safari', '360', 'qq', 'ios', 'android', 'random', 'randomized',
    ];

    /**
     * @param array<string, mixed> $input either ['uri' => '...'] or structured fields
     * @return array{protocol:string,name:string,server:string,port:int,settings:array<string,mixed>}
     * @throws ApiException 422 hosted_node_invalid with a non-secret 'field'
     */
    public static function normalize(array $input): array
    {
        $uri = trim((string) ($input['uri'] ?? ''));
        if ($uri !== '') {
            $node = self::parseUri($uri);
            $name = trim((string) ($input['name'] ?? ''));
            if ($name !== '') {
                $node['name'] = $name;
            }
            return self::validate($node);
        }

        $protocol = self::protocolAlias((string) ($input['protocol'] ?? ''));
        $settings = [];
        if ($protocol === self::PROTOCOL_SHADOWSOCKS) {
            $settings = [
                'method' => (string) ($input['method'] ?? ''),
                'password' => (string) ($input['password'] ?? ''),
                'plugin' => (string) ($input['plugin'] ?? ''),
                'plugin_opts' => (string) ($input['plugin_opts'] ?? ''),
                'udp_over_tcp' => self::truthy($input['udp_over_tcp'] ?? false),
            ];
        } elseif ($protocol === self::PROTOCOL_SOCKS5) {
            $settings = [
                'username' => (string) ($input['username'] ?? ''),
                'password' => (string) ($input['password'] ?? ''),
            ];
        } elseif ($protocol === self::PROTOCOL_VLESS) {
            $settings = [
                'uuid' => (string) ($input['uuid'] ?? ''),
                'flow' => (string) ($input['flow'] ?? ''),
                'security' => (string) ($input['security'] ?? 'none'),
                'sni' => (string) ($input['sni'] ?? ''),
                'alpn' => $input['alpn'] ?? '',
                'fingerprint' => (string) ($input['fingerprint'] ?? $input['fp'] ?? ''),
                'insecure' => self::truthy($input['insecure'] ?? false),
                'public_key' => (string) ($input['public_key'] ?? $input['pbk'] ?? ''),
                'short_id' => (string) ($input['short_id'] ?? $input['sid'] ?? ''),
                'transport' => (string) ($input['transport'] ?? 'tcp'),
                'path' => (string) ($input['path'] ?? ''),
                'host' => (string) ($input['host'] ?? ''),
                'service_name' => (string) ($input['service_name'] ?? ''),
            ];
        }

        return self::validate([
            'protocol' => $protocol,
            'name' => (string) ($input['name'] ?? ''),
            'server' => (string) ($input['server'] ?? ''),
            'port' => $input['port'] ?? 0,
            'settings' => $settings,
        ]);
    }

    /**
     * @param array<string, mixed> $node
     * @return array{protocol:string,name:string,server:string,port:int,settings:array<string,mixed>}
     */
    public static function validate(array $node): array
    {
        $protocol = self::protocolAlias((string) ($node['protocol'] ?? ''));
        if (!in_array($protocol, self::PROTOCOLS, true)) {
            self::invalid('protocol');
        }
        $server = self::normalizeServer((string) ($node['server'] ?? ''));
        $port = self::normalizePort($node['port'] ?? 0);
        $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];

        $settings = match ($protocol) {
            self::PROTOCOL_SHADOWSOCKS => self::validateShadowsocks($settings),
            self::PROTOCOL_SOCKS5 => self::validateSocks5($settings),
            default => self::validateVless($settings),
        };

        $name = self::normalizeName((string) ($node['name'] ?? ''));
        if ($name === '') {
            $name = self::defaultName($server, $port);
        }

        return [
            'protocol' => $protocol,
            'name' => $name,
            'server' => $server,
            'port' => $port,
            'settings' => $settings,
        ];
    }

    /**
     * Canonical share link, readable by the Android ProxyUriParser.
     *
     * @param array{protocol:string,name:string,server:string,port:int,settings:array<string,mixed>} $node
     */
    public static function toShareUri(array $node): string
    {
        $host = self::uriHost((string) $node['server']);
        $port = (int) $node['port'];
        $fragment = '#' . rawurlencode((string) $node['name']);
        $settings = $node['settings'];

        if ($node['protocol'] === self::PROTOCOL_SHADOWSOCKS) {
            $userInfo = rtrim(strtr(base64_encode($settings['method'] . ':' . $settings['password']), '+/', '-_'), '=');
            $query = [];
            if (($settings['plugin'] ?? '') !== '') {
                $plugin = (string) $settings['plugin'];
                if (($settings['plugin_opts'] ?? '') !== '') {
                    $plugin .= ';' . $settings['plugin_opts'];
                }
                $query[] = 'plugin=' . rawurlencode($plugin);
            }
            if (!empty($settings['udp_over_tcp'])) {
                $query[] = 'uot=1';
            }
            return 'ss://' . $userInfo . '@' . $host . ':' . $port
                . ($query !== [] ? '?' . implode('&', $query) : '') . $fragment;
        }

        if ($node['protocol'] === self::PROTOCOL_SOCKS5) {
            $auth = '';
            if (($settings['username'] ?? '') !== '') {
                $auth = rawurlencode((string) $settings['username']) . ':' . rawurlencode((string) $settings['password']) . '@';
            }
            return 'socks5://' . $auth . $host . ':' . $port . $fragment;
        }

        $query = ['encryption' => 'none', 'type' => (string) $settings['transport'], 'security' => (string) $settings['security']];
        foreach ([
            'flow' => 'flow',
            'sni' => 'sni',
            'fingerprint' => 'fp',
            'public_key' => 'pbk',
            'short_id' => 'sid',
            'path' => 'path',
            'host' => 'host',
            'service_name' => 'serviceName',
        ] as $field => $param) {
            $value = (string) ($settings[$field] ?? '');
            if ($value !== '') {
                $query[$param] = $value;
            }
        }
        if (($settings['alpn'] ?? []) !== []) {
            $query['alpn'] = implode(',', (array) $settings['alpn']);
        }
        if (!empty($settings['insecure'])) {
            $query['allowInsecure'] = '1';
        }
        $pairs = [];
        foreach ($query as $key => $value) {
            $pairs[] = $key . '=' . rawurlencode($value);
        }
        return 'vless://' . rawurlencode((string) $settings['uuid']) . '@' . $host . ':' . $port
            . '?' . implode('&', $pairs) . $fragment;
    }

    /**
     * Non-secret view for the tenant console and API listings.
     *
     * @param array{protocol:string,name:string,server:string,port:int,settings:array<string,mixed>} $node
     * @return array<string, mixed>
     */
    public static function publicSummary(array $node): array
    {
        $settings = $node['settings'];
        $summary = [
            'protocol' => $node['protocol'],
            'name' => $node['name'],
            'server' => $node['server'],
            'port' => $node['port'],
        ];
        if ($node['protocol'] === self::PROTOCOL_SHADOWSOCKS) {
            $summary['method'] = (string) $settings['method'];
            $summary['plugin'] = (string) $settings['plugin'];
        } elseif ($node['protocol'] === self::PROTOCOL_SOCKS5) {
            $summary['auth'] = ($settings['username'] ?? '') !== '';
        } else {
            $summary['security'] = (string) $settings['security'];
            $summary['transport'] = (string) $settings['transport'];
        }
        return $summary;
    }

    /**
     * @return array{protocol:string,name:string,server:string,port:int,settings:array<string,mixed>}
     */
    public static function parseUri(string $uri): array
    {
        if (strlen($uri) > self::MAX_URI_BYTES || preg_match('/[\x00-\x1f\x7f]/', $uri) === 1) {
            self::invalid('uri');
        }
        $scheme = strtolower((string) strstr($uri, '://', true));
        return match ($scheme) {
            'ss' => self::parseShadowsocksUri($uri),
            'socks', 'socks5' => self::parseSocksUri($uri),
            'vless' => self::parseVlessUri($uri),
            default => self::invalid('uri'),
        };
    }

    /** @return array<string, mixed> */
    private static function parseShadowsocksUri(string $uri): array
    {
        $rest = substr($uri, 5);
        [$main, $fragment] = array_pad(explode('#', $rest, 2), 2, '');
        [$authority, $query] = array_pad(explode('?', $main, 2), 2, '');
        $authority = rtrim($authority, '/');

        if (str_contains($authority, '@')) {
            $at = strrpos($authority, '@');
            $userInfo = substr($authority, 0, (int) $at);
            $hostPort = substr($authority, (int) $at + 1);
            $decoded = self::base64Decode($userInfo);
            if ($decoded !== null && str_contains($decoded, ':') && preg_match('/[\x00-\x1f\x7f]/', $decoded) !== 1) {
                $credentials = $decoded;
            } else {
                $credentials = rawurldecode($userInfo);
            }
        } else {
            // Legacy form: ss://BASE64(method:password@host:port)
            $decoded = self::base64Decode($authority);
            if ($decoded === null || !str_contains($decoded, '@')) {
                self::invalid('uri');
            }
            $at = strrpos($decoded, '@');
            $credentials = substr($decoded, 0, (int) $at);
            $hostPort = substr($decoded, (int) $at + 1);
        }
        if (!str_contains($credentials, ':')) {
            self::invalid('password');
        }
        [$method, $password] = explode(':', $credentials, 2);
        [$server, $port] = self::splitHostPort($hostPort);

        $settings = ['method' => $method, 'password' => $password, 'plugin' => '', 'plugin_opts' => '', 'udp_over_tcp' => false];
        foreach (self::queryPairs($query) as [$key, $value]) {
            if ($key === 'plugin') {
                $settings['plugin'] = trim((string) strstr($value . ';', ';', true));
                $settings['plugin_opts'] = trim((string) substr((string) strstr($value, ';'), 1));
            } elseif ($key === 'uot' || $key === 'udp_over_tcp') {
                $settings['udp_over_tcp'] = self::truthy($value);
            }
        }

        return [
            'protocol' => self::PROTOCOL_SHADOWSOCKS,
            'name' => rawurldecode($fragment),
            'server' => $server,
            'port' => $port,
            'settings' => $settings,
        ];
    }

    /** @return array<string, mixed> */
    private static function parseSocksUri(string $uri): array
    {
        $rest = substr($uri, (int) strpos($uri, '://') + 3);
        [$main, $fragment] = array_pad(explode('#', $rest, 2), 2, '');
        $main = rtrim((string) strstr($main . '?', '?', true), '/');
        $username = '';
        $password = '';
        $hostPort = $main;
        if (str_contains($main, '@')) {
            $at = strrpos($main, '@');
            $userInfo = substr($main, 0, (int) $at);
            $hostPort = substr($main, (int) $at + 1);
            if (!str_contains($userInfo, ':')) {
                // v2rayN form: socks://BASE64(user:pass)@host:port
                $decoded = self::base64Decode($userInfo);
                if ($decoded !== null && str_contains($decoded, ':') && preg_match('/[\x00-\x1f\x7f]/', $decoded) !== 1) {
                    $userInfo = rawurlencode((string) strstr($decoded, ':', true)) . ':'
                        . rawurlencode(substr((string) strstr($decoded, ':'), 1));
                }
            }
            [$rawUser, $rawPass] = array_pad(explode(':', $userInfo, 2), 2, '');
            $username = rawurldecode($rawUser);
            $password = rawurldecode($rawPass);
        }
        [$server, $port] = self::splitHostPort($hostPort);
        return [
            'protocol' => self::PROTOCOL_SOCKS5,
            'name' => rawurldecode($fragment),
            'server' => $server,
            'port' => $port,
            'settings' => ['username' => $username, 'password' => $password],
        ];
    }

    /** @return array<string, mixed> */
    private static function parseVlessUri(string $uri): array
    {
        $rest = substr($uri, 8);
        [$main, $fragment] = array_pad(explode('#', $rest, 2), 2, '');
        [$authority, $query] = array_pad(explode('?', $main, 2), 2, '');
        $authority = rtrim($authority, '/');
        if (!str_contains($authority, '@')) {
            self::invalid('uuid');
        }
        $at = strrpos($authority, '@');
        $uuid = rawurldecode(substr($authority, 0, (int) $at));
        [$server, $port] = self::splitHostPort(substr($authority, (int) $at + 1));

        $params = [];
        foreach (self::queryPairs($query) as [$key, $value]) {
            $params[$key] = $value;
        }
        return [
            'protocol' => self::PROTOCOL_VLESS,
            'name' => rawurldecode($fragment),
            'server' => $server,
            'port' => $port,
            'settings' => [
                'uuid' => $uuid,
                'flow' => (string) ($params['flow'] ?? ''),
                'security' => (string) ($params['security'] ?? 'none'),
                'sni' => (string) ($params['sni'] ?? $params['peer'] ?? ''),
                'alpn' => (string) ($params['alpn'] ?? ''),
                'fingerprint' => (string) ($params['fp'] ?? ''),
                'insecure' => self::truthy($params['allowInsecure'] ?? $params['insecure'] ?? false),
                'public_key' => (string) ($params['pbk'] ?? ''),
                'short_id' => (string) ($params['sid'] ?? ''),
                'transport' => (string) ($params['type'] ?? 'tcp'),
                'path' => (string) ($params['path'] ?? ''),
                'host' => (string) ($params['host'] ?? ''),
                'service_name' => (string) ($params['serviceName'] ?? ''),
                'encryption' => (string) ($params['encryption'] ?? 'none'),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private static function validateShadowsocks(array $settings): array
    {
        $method = strtolower(trim((string) ($settings['method'] ?? '')));
        if (!in_array($method, self::SS_METHODS, true)) {
            self::invalid('method');
        }
        $password = (string) ($settings['password'] ?? '');
        if ($password === '' || strlen($password) > 512 || preg_match('/[\x00-\x1f\x7f]/', $password) === 1) {
            self::invalid('password');
        }
        if (str_starts_with($method, '2022-')) {
            // 2022 methods take base64 PSKs of the cipher's key length; a
            // multi-user server takes "iPSK:uPSK".
            $keyLength = $method === '2022-blake3-aes-128-gcm' ? 16 : 32;
            foreach (explode(':', $password) as $psk) {
                $raw = base64_decode($psk, true);
                if (!is_string($raw) || strlen($raw) !== $keyLength) {
                    self::invalid('password');
                }
            }
        }

        $plugin = strtolower(trim((string) ($settings['plugin'] ?? '')));
        if ($plugin === 'simple-obfs') {
            $plugin = 'obfs-local';
        }
        if ($plugin !== '' && !in_array($plugin, self::SS_PLUGINS, true)) {
            self::invalid('plugin');
        }
        $pluginOpts = trim((string) ($settings['plugin_opts'] ?? ''));
        if ($plugin === '') {
            $pluginOpts = '';
        }
        if (strlen($pluginOpts) > 512 || preg_match('/[\x00-\x1f\x7f]/', $pluginOpts) === 1) {
            self::invalid('plugin_opts');
        }

        return [
            'method' => $method,
            'password' => $password,
            'plugin' => $plugin,
            'plugin_opts' => $pluginOpts,
            'udp_over_tcp' => self::truthy($settings['udp_over_tcp'] ?? false),
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private static function validateSocks5(array $settings): array
    {
        $username = (string) ($settings['username'] ?? '');
        $password = (string) ($settings['password'] ?? '');
        // RFC 1929: both fields 1..255 bytes; no auth means both empty.
        if ($username === '' && $password !== '') {
            self::invalid('username');
        }
        foreach (['username' => $username, 'password' => $password] as $field => $value) {
            if (strlen($value) > 255 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                self::invalid($field);
            }
        }
        if ($username !== '' && $password === '') {
            self::invalid('password');
        }
        return ['username' => $username, 'password' => $password];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private static function validateVless(array $settings): array
    {
        $uuid = strtolower(trim((string) ($settings['uuid'] ?? '')));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid) !== 1) {
            self::invalid('uuid');
        }
        $encryption = strtolower(trim((string) ($settings['encryption'] ?? 'none')));
        if ($encryption !== '' && $encryption !== 'none') {
            self::invalid('encryption');
        }
        $security = strtolower(trim((string) ($settings['security'] ?? 'none')));
        if ($security === '') {
            $security = 'none';
        }
        if (!in_array($security, self::VLESS_SECURITIES, true)) {
            self::invalid('security');
        }
        $transport = strtolower(trim((string) ($settings['transport'] ?? 'tcp')));
        if ($transport === '' || $transport === 'raw') {
            $transport = 'tcp';
        }
        if (!in_array($transport, self::VLESS_TRANSPORTS, true)) {
            self::invalid('transport');
        }
        $flow = strtolower(trim((string) ($settings['flow'] ?? '')));
        if (!in_array($flow, self::VLESS_FLOWS, true)) {
            self::invalid('flow');
        }
        if ($flow !== '' && ($security === 'none' || $transport !== 'tcp')) {
            // Vision needs TLS or REALITY over a raw TCP stream.
            self::invalid('flow');
        }

        $sni = strtolower(trim((string) ($settings['sni'] ?? '')));
        if ($sni !== '' && !self::isHostname($sni) && !self::isIpLiteral($sni)) {
            self::invalid('sni');
        }
        $fingerprint = strtolower(trim((string) ($settings['fingerprint'] ?? '')));
        if ($fingerprint !== '' && !in_array($fingerprint, self::UTLS_FINGERPRINTS, true)) {
            self::invalid('fingerprint');
        }
        $alpnRaw = $settings['alpn'] ?? [];
        $alpn = [];
        foreach (is_array($alpnRaw) ? $alpnRaw : explode(',', (string) $alpnRaw) as $value) {
            $value = strtolower(trim((string) $value));
            if ($value === '') {
                continue;
            }
            if (!in_array($value, self::VLESS_ALPN, true)) {
                self::invalid('alpn');
            }
            if (!in_array($value, $alpn, true)) {
                $alpn[] = $value;
            }
        }
        $insecure = self::truthy($settings['insecure'] ?? false);
        $publicKey = trim((string) ($settings['public_key'] ?? ''));
        $shortId = strtolower(trim((string) ($settings['short_id'] ?? '')));

        if ($security === 'reality') {
            // REALITY: X25519 public key (base64url, 32 bytes), an even-length
            // hex short id of up to 16 chars, a real server name, and uTLS.
            if (preg_match('/^[A-Za-z0-9_-]{43}$/', $publicKey) !== 1) {
                self::invalid('public_key');
            }
            if ($shortId !== '' && preg_match('/^(?:[0-9a-f]{2}){1,8}$/', $shortId) !== 1) {
                self::invalid('short_id');
            }
            if ($sni === '' || !self::isHostname($sni)) {
                self::invalid('sni');
            }
            if ($fingerprint === '') {
                $fingerprint = 'chrome';
            }
            $insecure = false;
        } else {
            $publicKey = '';
            $shortId = '';
        }
        if ($security === 'none') {
            $sni = '';
            $alpn = [];
            $fingerprint = '';
            $insecure = false;
        }

        $path = trim((string) ($settings['path'] ?? ''));
        $host = strtolower(trim((string) ($settings['host'] ?? '')));
        $serviceName = trim((string) ($settings['service_name'] ?? ''));
        if ($transport === 'ws' || $transport === 'httpupgrade') {
            if ($path === '') {
                $path = '/';
            }
            if ($path[0] !== '/' || strlen($path) > 512 || preg_match('/[\x00-\x20\x7f]/', $path) === 1) {
                self::invalid('path');
            }
            if ($host !== '' && !self::isHostname($host) && !self::isIpLiteral($host)) {
                self::invalid('host');
            }
            $serviceName = '';
        } elseif ($transport === 'grpc') {
            if ($serviceName === '' || strlen($serviceName) > 255 || preg_match('/^[A-Za-z0-9._\/-]+$/', $serviceName) !== 1) {
                self::invalid('service_name');
            }
            $path = '';
            $host = '';
        } else {
            $path = '';
            $host = '';
            $serviceName = '';
        }

        return [
            'uuid' => $uuid,
            'flow' => $flow,
            'security' => $security,
            'sni' => $sni,
            'alpn' => $alpn,
            'fingerprint' => $fingerprint,
            'insecure' => $insecure,
            'public_key' => $publicKey,
            'short_id' => $shortId,
            'transport' => $transport,
            'path' => $path,
            'host' => $host,
            'service_name' => $serviceName,
        ];
    }

    private static function normalizeServer(string $server): string
    {
        $server = strtolower(trim($server));
        if (str_starts_with($server, '[') && str_ends_with($server, ']')) {
            $server = substr($server, 1, -1);
        }
        if ($server === '' || strlen($server) > 253) {
            self::invalid('server');
        }
        if (self::isIpLiteral($server)) {
            // The client dials 127.0.0.1:<forward port>, and the forward
            // carries the bytes to this address; loopback, unspecified and
            // link-local addresses can never be a reachable node.
            $packed = inet_pton($server);
            $isV4 = strlen((string) $packed) === 4;
            if (($isV4 && (str_starts_with($server, '127.') || str_starts_with($server, '0.') || str_starts_with($server, '169.254.')))
                || (!$isV4 && ($packed === inet_pton('::1') || $packed === inet_pton('::') || str_starts_with($server, 'fe80:')))) {
                self::invalid('server');
            }
            return $isV4 ? $server : (string) inet_ntop((string) $packed);
        }
        if (!self::isHostname($server) || $server === 'localhost' || str_ends_with($server, '.localhost')) {
            self::invalid('server');
        }
        return $server;
    }

    private static function normalizePort(mixed $port): int
    {
        if (is_string($port)) {
            $port = trim($port);
            if (preg_match('/^\d{1,5}$/', $port) !== 1) {
                self::invalid('port');
            }
        }
        if (!is_int($port) && !is_string($port)) {
            self::invalid('port');
        }
        $value = (int) $port;
        if ($value < 1 || $value > 65535) {
            self::invalid('port');
        }
        return $value;
    }

    private static function normalizeName(string $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $name) ?? '');
        if ($name === '') {
            return '';
        }
        if (preg_match('//u', $name) !== 1) {
            self::invalid('name');
        }
        if (strlen($name) > self::MAX_NAME_BYTES) {
            // Trim to whole UTF-8 characters without mbstring.
            preg_match('/^.{0,' . self::MAX_NAME_BYTES . '}/su', substr($name, 0, self::MAX_NAME_BYTES + 3), $match);
            $name = (string) ($match[0] ?? '');
            while (strlen($name) > self::MAX_NAME_BYTES) {
                $name = (string) preg_replace('/.$/su', '', $name);
            }
            $name = trim($name);
        }
        return $name;
    }

    private static function defaultName(string $server, int $port): string
    {
        return (str_contains($server, ':') ? '[' . $server . ']' : $server) . ':' . $port;
    }

    /** @return array{0:string,1:int} */
    private static function splitHostPort(string $hostPort): array
    {
        $hostPort = trim($hostPort);
        if (str_starts_with($hostPort, '[')) {
            $close = strpos($hostPort, ']');
            if ($close === false || ($hostPort[$close + 1] ?? '') !== ':') {
                self::invalid('server');
            }
            return [substr($hostPort, 1, $close - 1), self::normalizePort(substr($hostPort, $close + 2))];
        }
        $colon = strrpos($hostPort, ':');
        if ($colon === false) {
            self::invalid('port');
        }
        return [substr($hostPort, 0, $colon), self::normalizePort(substr($hostPort, $colon + 1))];
    }

    /** @return list<array{0:string,1:string}> */
    private static function queryPairs(string $query): array
    {
        $pairs = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $pairs[] = [rawurldecode($key), rawurldecode($value)];
        }
        return $pairs;
    }

    private static function base64Decode(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^[A-Za-z0-9+\/_=-]+$/', $value) !== 1) {
            return null;
        }
        $normalized = strtr(rtrim($value, '='), '-_', '+/');
        $normalized .= str_repeat('=', (4 - strlen($normalized) % 4) % 4);
        $decoded = base64_decode($normalized, true);
        return is_string($decoded) && $decoded !== '' ? $decoded : null;
    }

    private static function uriHost(string $server): string
    {
        return str_contains($server, ':') ? '[' . $server . ']' : $server;
    }

    public static function protocolAlias(string $protocol): string
    {
        $protocol = strtolower(trim($protocol));
        return match ($protocol) {
            'ss' => self::PROTOCOL_SHADOWSOCKS,
            'socks' => self::PROTOCOL_SOCKS5,
            default => $protocol,
        };
    }

    public static function isIpLiteral(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    public static function isHostname(string $value): bool
    {
        if ($value === '' || strlen($value) > 253 || !str_contains($value, '.')) {
            return false;
        }
        foreach (explode('.', $value) as $label) {
            if (preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)$/', $label) !== 1) {
                return false;
            }
        }
        // A dotted all-numeric name is a malformed IPv4 address, not a host.
        return preg_match('/^[0-9.]+$/', $value) !== 1;
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function invalid(string $field): never
    {
        throw new ApiException('hosted_node_invalid', 422, ['field' => $field]);
    }
}
