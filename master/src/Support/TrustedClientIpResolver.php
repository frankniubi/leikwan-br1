<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Http\Request;

final class TrustedClientIpResolver
{
    /** @var array<int, array{network: string, prefix: int}> */
    private array $trustedProxyCidrs = [];

    /**
     * @param array<int, string>|string|null $trustedProxyCidrs
     */
    public function __construct(array|string|null $trustedProxyCidrs = null)
    {
        if ($trustedProxyCidrs === null) {
            $environmentValue = getenv('TRUSTED_PROXY_CIDRS');
            $trustedProxyCidrs = $environmentValue === false ? '' : $environmentValue;
        }

        $values = is_array($trustedProxyCidrs)
            ? $trustedProxyCidrs
            : explode(',', $trustedProxyCidrs);
        foreach ($values as $value) {
            $cidr = $this->parseCidr((string) $value);
            if ($cidr !== null) {
                $this->trustedProxyCidrs[] = $cidr;
            }
        }
    }

    public function resolve(Request $request): ?string
    {
        $remotePeer = $request->remoteAddress();
        if ($remotePeer === null) {
            return null;
        }

        $remoteAddress = $this->normalizeAddress($remotePeer);
        if ($remoteAddress === null) {
            return null;
        }

        if (!$this->isTrustedProxy($remoteAddress)) {
            return $remoteAddress;
        }

        $forwardedAddress = trim((string) ($request->header('x-forwarded-for') ?? ''));
        if ($forwardedAddress !== '') {
            $firstForwarded = trim((string) explode(',', $forwardedAddress, 2)[0]);
            return $this->normalizeAddress($firstForwarded) ?? $remoteAddress;
        }

        $realAddress = trim((string) ($request->header('x-real-ip') ?? ''));
        return $this->normalizeAddress($realAddress) ?? $remoteAddress;
    }

    private function isTrustedProxy(string $address): bool
    {
        foreach ($this->trustedProxyCidrs as $cidr) {
            if ($this->matchesCidr($address, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{network: string, prefix: int}|null
     */
    private function parseCidr(string $value): ?array
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $parts = explode('/', $value, 2);
        $address = $this->normalizeAddress($parts[0]);
        if ($address === null) {
            return null;
        }

        $packed = inet_pton($address);
        if ($packed === false) {
            return null;
        }

        $maximumPrefix = strlen($packed) * 8;
        $prefix = count($parts) === 2 ? filter_var($parts[1], FILTER_VALIDATE_INT) : $maximumPrefix;
        if ($prefix === false || $prefix < 0 || $prefix > $maximumPrefix) {
            return null;
        }

        return [
            'network' => $packed,
            'prefix' => $prefix,
        ];
    }

    /**
     * @param array{network: string, prefix: int} $cidr
     */
    private function matchesCidr(string $address, array $cidr): bool
    {
        $packed = inet_pton($address);
        if ($packed === false || strlen($packed) !== strlen($cidr['network'])) {
            return false;
        }

        $wholeBytes = intdiv($cidr['prefix'], 8);
        if ($wholeBytes > 0 && substr($packed, 0, $wholeBytes) !== substr($cidr['network'], 0, $wholeBytes)) {
            return false;
        }

        $remainingBits = $cidr['prefix'] % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;
        return (ord($packed[$wholeBytes]) & $mask) === (ord($cidr['network'][$wholeBytes]) & $mask);
    }

    private function normalizeAddress(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || filter_var($value, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = inet_pton($value);
        if ($packed !== false && strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\x00", 10) . "\xff\xff") {
            return inet_ntop(substr($packed, 12));
        }

        return $value;
    }
}
