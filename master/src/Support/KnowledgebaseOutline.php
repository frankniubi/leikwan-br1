<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

/**
 * Builds the "on this page" reading order for a knowledge base article.
 *
 * The reader and the editorial preview share this helper so an author sees the
 * same outline a customer will see. Only the body markup is touched: headings are
 * given stable anchors and the outline is rendered next to the article.
 */
final class KnowledgebaseOutline
{
    private const MAX_ITEMS = 12;

    /**
     * @return array{body: string, toc: string, items: array<int, array{id: string, text: string}>}
     */
    public static function apply(string $body, string $label): array
    {
        $items = [];
        $index = 0;
        $withAnchors = preg_replace_callback(
            '~<h2>(.*?)</h2>~su',
            static function (array $match) use (&$items, &$index): string {
                $index++;
                $text = trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($text === '') {
                    return $match[0];
                }
                $id = 'kb-section-' . $index;
                $items[] = ['id' => $id, 'text' => $text];
                return '<h2 id="' . $id . '">' . $match[1] . '</h2>';
            },
            $body
        );
        if (!is_string($withAnchors)) {
            return ['body' => $body, 'toc' => '', 'items' => []];
        }

        if (count($items) < 2) {
            return ['body' => $withAnchors, 'toc' => '', 'items' => $items];
        }
        if (count($items) > self::MAX_ITEMS) {
            $items = array_slice($items, 0, self::MAX_ITEMS);
        }

        $links = '';
        foreach ($items as $item) {
            $links .= '<a href="#' . $item['id'] . '">' . self::escape($item['text']) . '</a>';
        }

        $toc = '<nav class="kb-toc" aria-label="' . self::escape($label) . '">'
            . '<h3>' . self::escape($label) . '</h3>'
            . '<div class="kb-toc-links">' . $links . '</div>'
            . '</nav>';

        return ['body' => $withAnchors, 'toc' => $toc, 'items' => $items];
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
