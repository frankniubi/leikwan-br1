<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

final class KnowledgebaseHtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'h2', 'h3', 'h4', 'p', 'ul', 'ol', 'li', 'strong', 'em', 'a', 'code', 'pre',
        'blockquote', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'br', 'img',
    ];

    private const ALLOWED_ATTRIBUTES = [
        'a' => ['href', 'title'],
        'img' => ['src', 'alt', 'width', 'height'],
        'pre' => ['class'],
    ];

    private const DANGEROUS_TAGS = ['script', 'style', 'iframe', 'object', 'embed'];

    public function sanitize(string $html): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML(
                '<?xml encoding="UTF-8"><!doctype html><html><body><div id="knowledgebase-sanitizer-root">'
                    . $html
                    . '</div></body></html>',
                LIBXML_HTML_NODEFDTD
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $document->getElementById('knowledgebase-sanitizer-root');
        if (!$root instanceof DOMElement) {
            return '';
        }

        $this->sanitizeChildren($root);

        $result = '';
        foreach (iterator_to_array($root->childNodes, false) as $child) {
            $result .= $document->saveHTML($child);
        }
        return $result;
    }

    private function sanitizeChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes, false) as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($node->tagName);
            if (in_array($tag, self::DANGEROUS_TAGS, true)) {
                $parent->removeChild($node);
                continue;
            }

            $this->sanitizeChildren($node);
            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                while ($node->firstChild !== null) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);
                continue;
            }

            $this->sanitizeAttributes($node, $tag);
        }
    }

    private function sanitizeAttributes(DOMElement $element, string $tag): void
    {
        $allowed = self::ALLOWED_ATTRIBUTES[$tag] ?? [];
        foreach (iterator_to_array($element->attributes, false) as $attribute) {
            $name = strtolower($attribute->name);
            if (!in_array($name, $allowed, true)) {
                $element->removeAttributeNode($attribute);
            }
        }

        if ($tag === 'pre' && $element->hasAttribute('class')) {
            $class = preg_replace('/\s+/u', ' ', trim($element->getAttribute('class')));
            if ($class !== 'mermaid') {
                $element->removeAttribute('class');
            } else {
                $element->setAttribute('class', 'mermaid');
            }
        }

        if ($tag === 'a' && $element->hasAttribute('href') && !$this->isAllowedLink($element->getAttribute('href'))) {
            $element->removeAttribute('href');
        }

        if ($tag === 'img' && $element->hasAttribute('src') && !$this->isAllowedImage($element->getAttribute('src'))) {
            $element->removeAttribute('src');
        }
    }

    private function isAllowedLink(string $url): bool
    {
        $decoded = $this->decodedUrl($url);
        if ($decoded === null) {
            return false;
        }

        return str_starts_with($decoded, '/')
            || str_starts_with($decoded, 'https://')
            || str_starts_with($decoded, 'http://')
            || str_starts_with($decoded, 'mailto:');
    }

    private function isAllowedImage(string $url): bool
    {
        $decoded = $this->decodedUrl($url);
        if ($decoded === null) {
            return false;
        }

        return str_starts_with($decoded, '/uploads/knowledgebase/')
            || str_starts_with($decoded, 'https://')
            || str_starts_with($decoded, 'http://');
    }

    private function decodedUrl(string $url): ?string
    {
        $decoded = trim($url);
        for ($i = 0; $i < 5; $i++) {
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }

        if ($decoded === '' || str_contains($decoded, '\\') || str_starts_with($decoded, '//')) {
            return null;
        }
        if (preg_match('/[\x00-\x20\x7f]/', $decoded) === 1) {
            return null;
        }

        $path = preg_split('/[?#]/', $decoded, 2)[0];
        if (preg_match('~(?:^|/)\.\.(?:/|$)~', $path) === 1) {
            return null;
        }

        return $decoded;
    }
}
