<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use Leikwan\Master\Http\Request;

final class LocaleResolver
{
    public const DEFAULT_LOCALE = 'en';
    public const COOKIE_NAME = 'leikwan_locale';
    public const SUPPORTED = ['en', 'zh-Hans', 'zh-TW'];

    public static function resolve(Request $request): string
    {
        $query = $request->query();
        if (array_key_exists('lang', $query)) {
            if (!is_scalar($query['lang'])) {
                return self::DEFAULT_LOCALE;
            }

            return self::normalize((string) $query['lang']) ?? self::DEFAULT_LOCALE;
        }

        $cookieHeader = $request->header('cookie', '') ?? '';
        foreach (explode(';', $cookieHeader) as $part) {
            [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
            if (trim($name) !== self::COOKIE_NAME) {
                continue;
            }

            return self::normalize(rawurldecode($value)) ?? self::DEFAULT_LOCALE;
        }

        return self::DEFAULT_LOCALE;
    }

    public static function normalize(string $locale): ?string
    {
        return in_array($locale, self::SUPPORTED, true) ? $locale : null;
    }
}
