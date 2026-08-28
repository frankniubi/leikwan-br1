<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

final class IpAddressMatcher
{
    /**
     * @return array{canonical:string,family:int,packed:string}|null
     */
    public static function canonicalize(string $value): ?array
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (str_contains($value, ':')) {
            $packed = @inet_pton($value);
            if ($packed === false || strlen($packed) !== 16) {
                return null;
            }
            if (substr($packed, 0, 12) === str_repeat("\x00", 10) . "\xff\xff") {
                $mapped = @inet_ntop(substr($packed, 12));
                return is_string($mapped) && $mapped !== ''
                    ? ['canonical' => $mapped, 'family' => 4, 'packed' => substr($packed, 12)]
                    : null;
            }
            $canonical = @inet_ntop($packed);
            return is_string($canonical) && $canonical !== ''
                ? ['canonical' => strtolower($canonical), 'family' => 6, 'packed' => $packed]
                : null;
        }

        if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.(?:0|[1-9][0-9]*)){3}$/', $value) !== 1) {
            return null;
        }
        foreach (explode('.', $value) as $octet) {
            if ((int) $octet > 255) {
                return null;
            }
        }

        $packed = @inet_pton($value);
        if ($packed === false || strlen($packed) !== 4) {
            return null;
        }
        $canonical = @inet_ntop($packed);
        return is_string($canonical) && $canonical !== ''
            ? ['canonical' => $canonical, 'family' => 4, 'packed' => $packed]
            : null;
    }

    /**
     * @return array{canonical:string,family:int,packed:string,start:string,end:string,prefix:int}|null
     */
    public static function canonicalCidr(string $value): ?array
    {
        $parts = explode('/', trim($value));
        if (count($parts) !== 2) {
            return null;
        }

        $address = self::canonicalize((string) $parts[0]);
        $prefixText = trim((string) $parts[1]);
        if ($address === null || $prefixText === '' || preg_match('/^[0-9]+$/', $prefixText) !== 1) {
            return null;
        }

        $maxPrefix = $address['family'] === 4 ? 32 : 128;
        $prefix = (int) $prefixText;
        if ($prefix < 0 || $prefix > $maxPrefix) {
            return null;
        }

        $start = self::maskStart($address['packed'], $prefix);
        $end = self::maskEnd($address['packed'], $prefix);
        $startText = @inet_ntop($start);
        if (!is_string($startText) || $startText === '') {
            return null;
        }

        return [
            'canonical' => strtolower($startText) . '/' . $prefix,
            'family' => $address['family'],
            'packed' => $address['packed'],
            'start' => $start,
            'end' => $end,
            'prefix' => $prefix,
        ];
    }

    public static function contains(string $network, string $candidate): bool
    {
        $candidateAddress = self::canonicalize($candidate);
        if ($candidateAddress === null) {
            return false;
        }

        if (!str_contains(trim($network), '/')) {
            $networkAddress = self::canonicalize($network);
            return $networkAddress !== null
                && $networkAddress['family'] === $candidateAddress['family']
                && hash_equals($networkAddress['packed'], $candidateAddress['packed']);
        }

        $cidr = self::canonicalCidr($network);
        if ($cidr === null || $cidr['family'] !== $candidateAddress['family']) {
            return false;
        }

        $candidateBytes = $candidateAddress['packed'];
        $start = $cidr['start'];
        $end = $cidr['end'];
        for ($index = 0, $length = strlen($candidateBytes); $index < $length; $index++) {
            $byte = ord($candidateBytes[$index]);
            if ($byte < ord($start[$index]) || $byte > ord($end[$index])) {
                return false;
            }
        }
        return true;
    }

    private static function maskStart(string $packed, int $prefix): string
    {
        $bytes = strlen($packed);
        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;
        $masked = $packed;

        for ($index = $fullBytes; $index < $bytes; $index++) {
            $mask = $index === $fullBytes && $remainingBits > 0
                ? (0xff << (8 - $remainingBits)) & 0xff
                : 0;
            $masked[$index] = chr(ord($packed[$index]) & $mask);
        }

        return $masked;
    }

    private static function maskEnd(string $packed, int $prefix): string
    {
        $bytes = strlen($packed);
        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;
        $masked = $packed;

        for ($index = $fullBytes; $index < $bytes; $index++) {
            $mask = $index === $fullBytes && $remainingBits > 0
                ? (0xff << (8 - $remainingBits)) & 0xff
                : 0;
            $masked[$index] = chr(ord($packed[$index]) | (0xff ^ $mask));
        }

        return $masked;
    }
}
