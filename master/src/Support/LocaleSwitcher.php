<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

/**
 * The language control, rendered the same way on every page.
 *
 * The visible control never changes with the selected language: each option is an
 * endonym, so the language a visitor is looking for is always spelled the way that
 * language spells itself, and the label is a globe glyph rather than a translated
 * word. Only the invisible accessible name follows the current language, so a
 * screen reader still announces the control in the reader's own language.
 */
final class LocaleSwitcher
{
    /** @var array<string, string> */
    private const ACCESSIBLE_NAMES = [
        'en' => 'Language',
        'zh-Hans' => '语言',
        'zh-TW' => '語言',
    ];

    /** @var array<string, string> Endonyms, identical in every locale. */
    private const OPTIONS = [
        'en' => 'English',
        'zh-Hans' => '简体中文',
        'zh-TW' => '繁體中文',
    ];

    private const GLOBE = '<svg class="locale-globe" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false">'
        . '<circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.6"/>'
        . '<path d="M3 12h18M12 3c2.4 2.8 2.4 15.2 0 18M12 3c-2.4 2.8-2.4 15.2 0 18" fill="none" stroke="currentColor" stroke-width="1.6"/>'
        . '</svg>';

    public static function html(?string $locale = null): string
    {
        $current = LocaleResolver::normalize((string) $locale) ?? LocaleResolver::DEFAULT_LOCALE;
        $accessibleName = self::ACCESSIBLE_NAMES[$current] ?? self::ACCESSIBLE_NAMES['en'];

        $options = '';
        foreach (self::OPTIONS as $value => $text) {
            $options .= '<option value="' . $value . '"' . ($value === $current ? ' selected' : '') . '>' . $text . '</option>';
        }

        return '<div class="locale-switch">'
            . self::GLOBE
            . '<select id="locale-switcher" class="locale-select" data-locale="' . $current . '" aria-label="' . self::escape($accessibleName) . '">'
            . $options
            . '</select>'
            . '</div>';
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
