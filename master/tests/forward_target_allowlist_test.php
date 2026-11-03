<?php
declare(strict_types=1);

namespace Leikwan\Master\Support {
    class ApiException extends \RuntimeException
    {
        private array $details;

        public function __construct(string $message, int $statusCode = 400, array $details = [])
        {
            parent::__construct($message, $statusCode);
            $this->details = $details;
        }

        public function details(): array
        {
            return $this->details;
        }
    }
}

namespace {
    require __DIR__ . '/../src/Support/ForwardTargetAllowlist.php';

    use Leikwan\Master\Support\ApiException;
    use Leikwan\Master\Support\ForwardTargetAllowlist;

    $failures = 0;
    $check = static function (bool $condition, string $label) use (&$failures): void {
        if (!$condition) {
            $failures++;
            fwrite(STDERR, "FAIL: {$label}\n");
        }
    };
    $throwsCode = static function (callable $fn): ?string {
        try {
            $fn();
        } catch (ApiException $exception) {
            return $exception->getMessage();
        }
        return null;
    };

    // Empty input means "no restriction".
    $check(ForwardTargetAllowlist::normalize('') === null, 'empty input normalizes to null');
    $check(ForwardTargetAllowlist::normalize("  \n# only a comment\n") === null, 'comment-only input normalizes to null');
    $check(!ForwardTargetAllowlist::isRestricted(null), 'null list is unrestricted');
    $check(ForwardTargetAllowlist::allows(null, 'example.com'), 'unrestricted list allows hostnames');
    $check(ForwardTargetAllowlist::allows('', '203.0.113.9'), 'unrestricted list allows any IP');

    // Canonical form: network-aligned, deduplicated, one per line, separators and comments accepted.
    $stored = ForwardTargetAllowlist::normalize("163.53.55.221/22  # moerin\n163.53.52.0/22, 211.161.96.0/20;10.0.0.1\n2400:5f60:0:8::1/64");
    $check($stored === "163.53.52.0/22\n211.161.96.0/20\n10.0.0.1/32\n2400:5f60:0:8::/64", 'canonicalizes and dedupes: ' . var_export($stored, true));
    $check(ForwardTargetAllowlist::count($stored) === 4, 'count reports four networks');
    $check(ForwardTargetAllowlist::normalize(['163.53.52.0/22', '211.161.96.0/20']) === "163.53.52.0/22\n211.161.96.0/20", 'array input accepted');

    // Matching.
    $check(ForwardTargetAllowlist::allows($stored, '163.53.55.221'), 'IP inside /22 allowed');
    $check(ForwardTargetAllowlist::allows($stored, '211.161.96.13'), 'IP inside /20 allowed');
    $check(ForwardTargetAllowlist::allows($stored, '10.0.0.1'), 'host /32 allowed');
    $check(!ForwardTargetAllowlist::allows($stored, '10.0.0.2'), 'neighbour of /32 refused');
    $check(!ForwardTargetAllowlist::allows($stored, '163.53.56.1'), 'IP just past /22 refused');
    $check(!ForwardTargetAllowlist::allows($stored, '161.248.136.18'), 'unrelated IP refused');
    $check(ForwardTargetAllowlist::allows($stored, '2400:5f60:0:8::abcd'), 'IPv6 inside /64 allowed');
    $check(ForwardTargetAllowlist::allows($stored, '[2400:5f60:0:8::abcd]'), 'bracketed IPv6 allowed');
    $check(!ForwardTargetAllowlist::allows($stored, '2400:5f60:0:9::1'), 'IPv6 outside /64 refused');
    $check(!ForwardTargetAllowlist::allows($stored, 'shixp.example.com'), 'hostname refused on restricted list');
    $check(!ForwardTargetAllowlist::allows($stored, ''), 'empty target refused on restricted list');
    $check(!ForwardTargetAllowlist::allows("0.0.0.0/0", '2400:5f60:0:8::1'), 'IPv4 network never matches IPv6 target');
    $check(ForwardTargetAllowlist::allows("0.0.0.0/0", '8.8.8.8'), '0.0.0.0/0 matches any IPv4');

    // Invalid input is a 422 with the offending entry.
    $check($throwsCode(fn() => ForwardTargetAllowlist::normalize("163.53.52.0/33")) === 'invalid_forward_target_allowlist', 'prefix > 32 rejected');
    $check($throwsCode(fn() => ForwardTargetAllowlist::normalize("shixp.cn/24")) === 'invalid_forward_target_allowlist', 'hostname CIDR rejected');
    $check($throwsCode(fn() => ForwardTargetAllowlist::normalize("1.2.3.4/abc")) === 'invalid_forward_target_allowlist', 'non-numeric prefix rejected');
    $check($throwsCode(fn() => ForwardTargetAllowlist::normalize("2400::/129")) === 'invalid_forward_target_allowlist', 'IPv6 prefix > 128 rejected');
    $tooMany = implode("\n", array_map(static fn(int $i): string => '10.' . intdiv($i, 256) . '.' . ($i % 256) . '.0/24', range(0, ForwardTargetAllowlist::MAX_ENTRIES)));
    $check($throwsCode(fn() => ForwardTargetAllowlist::normalize($tooMany)) === 'invalid_forward_target_allowlist', 'over MAX_ENTRIES rejected');

    // The real SHIXP IRR-only list, when present next to the repo, admits both known IX targets.
    $shixp = __DIR__ . '/../../tmp/shixp-20261011/shixp_irr_only_v4.txt';
    if (is_file($shixp)) {
        $list = ForwardTargetAllowlist::normalize((string) file_get_contents($shixp));
        $check(ForwardTargetAllowlist::count($list) === 328, 'SHIXP list has 328 networks (got ' . ForwardTargetAllowlist::count($list) . ')');
        $check(ForwardTargetAllowlist::allows($list, '163.53.55.221'), 'SHIXP list admits 163.53.55.221');
        $check(ForwardTargetAllowlist::allows($list, '211.161.96.13'), 'SHIXP list admits 211.161.96.13');
        $check(!ForwardTargetAllowlist::allows($list, '161.248.136.18'), 'SHIXP list refuses the CNIX Shenzhen address');
    }

    if ($failures > 0) {
        fwrite(STDERR, "{$failures} check(s) failed\n");
        exit(1);
    }
    echo "forward_target_allowlist_test: ok\n";
}
