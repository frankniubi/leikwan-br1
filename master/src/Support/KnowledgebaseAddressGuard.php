<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use InvalidArgumentException;
use Leikwan\Master\Database\Database;

/**
 * Keeps real server addresses out of the knowledgebase.
 *
 * Knowledgebase articles are read by every tenant, so an example that quotes a
 * live node, sidecar or forward target publishes that address. Content may only
 * name documentation addresses (RFC 5737 / RFC 3849 ranges, example.com,
 * .invalid, .test), private and loopback ranges, and the public panel hosts
 * tenants already see on the Clients page. Everything else is rejected:
 *
 * - any public IPv4 or IPv6 literal;
 * - any host under an operator zone that is not one of the public panel hosts;
 * - any hostname the panel itself stores as infrastructure or a forward target.
 */
final class KnowledgebaseAddressGuard
{
    /** Public panel hosts that tenants already type into their clients. */
    private const PUBLIC_HOSTS = [
        'leikwanhost.com',
        'www.leikwanhost.com',
        'v3.leikwanhost.com',
        'v3-mirror.leikwanhost.com',
        'buy.leikwanhost.com',
        'api-leikwanbridge.nbwly.com',
    ];

    /** Zones whose hosts are ours; every host under them must be in PUBLIC_HOSTS. */
    private const OPERATOR_ZONES = [
        'leikwanhost.com',
        'nbwly.com',
        'mcbar.cc',
    ];

    /** Columns that hold hostnames of nodes, sidecars, CDN fronts and forward targets. */
    private const HOST_COLUMNS = [
        ['service_endpoints', 'public_host'],
        ['service_endpoints', 'cdn_host'],
        ['sidecar_points', 'sidecar_host'],
        ['node_groups', 'ingress_cdn_host'],
        ['node_groups', 'ingress_sni_host'],
        ['forwards', 'target_host'],
        ['forwards', 'sni_host'],
        ['forwards', 'cdn_host'],
        ['forwards', 'bond_remote_host'],
        ['leigod_lines', 'tcp_probe_host'],
        ['leigod_lines', 'udp_probe_host'],
        ['bond_leg_reputation_current', 'host'],
    ];

    /** IPv4 ranges that are safe to print: unspecified, private, CGNAT, loopback, link-local, documentation, benchmark, multicast, reserved. */
    private const SAFE_IPV4_CIDRS = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/3',
    ];

    /** IPv6 prefixes that are safe to print: unspecified/loopback, ULA, link-local, multicast, documentation. */
    private const SAFE_IPV6_CIDRS = ['::/127', 'fc00::/7', 'fe80::/10', 'ff00::/8', '2001:db8::/32'];

    /** @var array<string, true>|null */
    private ?array $knownHosts = null;

    public function __construct(private ?Database $db = null)
    {
    }

    /**
     * @throws InvalidArgumentException when any of the texts names a real server address
     */
    public function assertClean(string ...$texts): void
    {
        $leaks = $this->findLeaks(...$texts);
        if ($leaks === []) {
            return;
        }

        throw new InvalidArgumentException(
            'Knowledgebase content must not contain real server addresses: ' . implode(', ', $leaks)
            . '. Use documentation examples such as node.example.com or 203.0.113.10 instead.'
        );
    }

    /**
     * @return list<string> the offending addresses, de-duplicated, in order of appearance
     */
    public function findLeaks(string ...$texts): array
    {
        $leaks = [];
        foreach ($texts as $text) {
            // Scan the markup and its decoded text, so addresses inside attributes
            // and addresses written with entities are both seen.
            $decoded = html_entity_decode(strip_tags(str_replace('<', ' <', $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            foreach ([$text, $decoded] as $haystack) {
                foreach ($this->addressesIn($haystack) as $address) {
                    $leaks[$address] = true;
                }
            }
        }

        return array_keys($leaks);
    }

    /**
     * @return list<string>
     */
    private function addressesIn(string $text): array
    {
        $found = [];

        if (preg_match_all('/(?<![\d.])(\d{1,3}(?:\.\d{1,3}){3})(?![\d]|\.\d)/', $text, $matches)) {
            foreach ($matches[1] as $candidate) {
                if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                    && !$this->inCidrs($candidate, self::SAFE_IPV4_CIDRS)) {
                    $found[] = $candidate;
                }
            }
        }

        if (preg_match_all('/(?<![0-9A-Za-z:])([0-9A-Fa-f]{0,4}(?::[0-9A-Fa-f]{0,4}){2,7})(?![0-9A-Za-z:])/', $text, $matches)) {
            foreach ($matches[1] as $candidate) {
                if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                    && !$this->inCidrs($candidate, self::SAFE_IPV6_CIDRS)) {
                    $found[] = strtolower($candidate);
                }
            }
        }

        $hostPattern = '/(?<![A-Za-z0-9.\-])((?:[A-Za-z0-9](?:[A-Za-z0-9\-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z][A-Za-z0-9\-]{0,62})(?![A-Za-z0-9\-]|\.[A-Za-z0-9])/';
        if (preg_match_all($hostPattern, $text, $matches)) {
            foreach ($matches[1] as $candidate) {
                $host = strtolower($candidate);
                if ($this->isLeakedHost($host)) {
                    $found[] = $host;
                }
            }
        }

        return $found;
    }

    private function isLeakedHost(string $host): bool
    {
        if (in_array($host, self::PUBLIC_HOSTS, true) || self::isDocumentationHost($host)) {
            return false;
        }
        foreach (self::OPERATOR_ZONES as $zone) {
            if ($host === $zone || str_ends_with($host, '.' . $zone)) {
                return true;
            }
        }

        return isset($this->knownHosts()[$host]);
    }

    private static function isDocumentationHost(string $host): bool
    {
        foreach (['example.com', 'example.net', 'example.org'] as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }
        foreach (['.example', '.invalid', '.test', '.localhost', '.local'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hostnames the panel stores for its own infrastructure and for tenants'
     * forward targets. IP literals are left out because every public IP is
     * rejected anyway.
     *
     * @return array<string, true>
     */
    private function knownHosts(): array
    {
        if ($this->knownHosts !== null) {
            return $this->knownHosts;
        }

        $this->knownHosts = [];
        if ($this->db === null) {
            return $this->knownHosts;
        }

        foreach (self::HOST_COLUMNS as [$table, $column]) {
            try {
                $rows = $this->db->fetchAll(
                    "SELECT DISTINCT {$column} AS host FROM {$table} WHERE {$column} LIKE '%.%'"
                );
            } catch (\Throwable) {
                // Older schemas and test databases lack some of these tables.
                continue;
            }
            foreach ($rows as $row) {
                $host = strtolower(rtrim(trim((string) ($row['host'] ?? '')), '.'));
                if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false || !str_contains($host, '.')) {
                    continue;
                }
                $this->knownHosts[$host] = true;
            }
        }

        return $this->knownHosts;
    }

    /**
     * @param list<string> $cidrs
     */
    private function inCidrs(string $ip, array $cidrs): bool
    {
        $address = inet_pton($ip);
        if ($address === false) {
            return false;
        }
        foreach ($cidrs as $cidr) {
            [$network, $bits] = explode('/', $cidr);
            $prefix = inet_pton($network);
            if ($prefix === false || strlen($prefix) !== strlen($address)) {
                continue;
            }
            $bits = (int) $bits;
            $bytes = intdiv($bits, 8);
            if (strncmp($address, $prefix, $bytes) !== 0) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($address[$bytes]) & $mask) === (ord($prefix[$bytes]) & $mask)) {
                return true;
            }
        }

        return false;
    }
}
