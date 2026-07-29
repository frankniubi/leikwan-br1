<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;

final class MermaidSourcePolicy
{
    /** @return array<int, string> */
    public static function extract(string $bodyHtml): array
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML(
                '<?xml encoding="UTF-8"><!doctype html><html><body><div id="mermaid-source-root">'
                    . $bodyHtml
                    . '</div></body></html>',
                LIBXML_HTML_NODEFDTD
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $document->getElementById('mermaid-source-root');
        if (!$root instanceof DOMElement) {
            return [];
        }

        $sources = [];
        foreach ($root->getElementsByTagName('pre') as $pre) {
            $class = preg_replace('/\s+/u', ' ', trim($pre->getAttribute('class')));
            if ($class !== 'mermaid') {
                continue;
            }
            $source = preg_replace("/\r\n?/", "\n", $pre->textContent);
            $sources[] = trim($source);
        }
        return $sources;
    }

    public static function digest(string $bodyHtml): string
    {
        return hash('sha256', implode("\n---\n", self::extract($bodyHtml)));
    }

    public static function assertPreviewable(string $bodyHtml): void
    {
        foreach (self::extract($bodyHtml) as $source) {
            if (preg_match('/^flowchart\s+(?:TD|LR)\b/', $source) !== 1) {
                throw new InvalidArgumentException('Mermaid diagrams must use flowchart TD or flowchart LR.');
            }
            if (preg_match('/^accTitle:\s*\S.*$/m', $source) !== 1) {
                throw new InvalidArgumentException('Each Mermaid diagram needs accTitle.');
            }
            if (preg_match('/^accDescr:\s*\S.*$/m', $source) !== 1) {
                throw new InvalidArgumentException('Each Mermaid diagram needs accDescr.');
            }
            if (preg_match('/%%\{|\bclick\s|javascript:|<script\b|<iframe\b/i', $source) === 1) {
                throw new InvalidArgumentException('Mermaid directives and active content are not allowed.');
            }
            if (strlen($source) > 10_000) {
                throw new InvalidArgumentException('Each Mermaid diagram must be at most 10000 bytes.');
            }
        }
    }

    public static function assertPublishable(string $bodyHtml, string $browserDigest): void
    {
        self::assertPreviewable($bodyHtml);
        if (self::extract($bodyHtml) === []) {
            return;
        }

        $browserDigest = trim($browserDigest);
        if (preg_match('/^[a-f0-9]{64}$/', $browserDigest) !== 1
            || !hash_equals(self::digest($bodyHtml), $browserDigest)) {
            throw new InvalidArgumentException('Mermaid validation digest does not match the article diagrams.');
        }
    }
}
