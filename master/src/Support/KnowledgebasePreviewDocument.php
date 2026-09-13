<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

/**
 * Renders the editorial preview of a knowledge base article.
 *
 * The preview is a standalone document, but it is deliberately built from the
 * same markup and the same stylesheet as the reader page so an author reviews
 * exactly what a customer will read.
 */
final class KnowledgebasePreviewDocument
{
    private const BANNERS = [
        'en' => 'Preview — not published',
        'zh-Hans' => '预览 — 尚未发布',
        'zh-TW' => '預覽 — 尚未發佈',
    ];

    private const STYLESHEET = '/assets/knowledgebase-docs.css?v=20260914a';

    private const CHROME_STYLE = <<<'CSS'
:root { color-scheme: light; }
body.kb-preview-page { margin: 0; background: #eef2ef; font-family: Inter, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif; }
.kb-preview-bar { position: sticky; top: 0; z-index: 5; display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 11px 24px; border-bottom: 1px solid #e1e8e2; background: #ffffff; font-size: 13px; }
.kb-preview-flag { display: inline-flex; align-items: center; gap: 9px; color: #15221c; font-weight: 650; }
.kb-preview-dot { width: 7px; height: 7px; border-radius: 50%; background: #e05b45; }
.kb-preview-locale { color: #69776f; font-family: "Spline Sans Mono", "SFMono-Regular", Consolas, monospace; font-size: 11px; letter-spacing: 0.08em; text-transform: uppercase; }
.kb-preview-main { display: grid; grid-template-columns: minmax(0, 1fr) minmax(140px, 164px); gap: 28px; align-items: start; width: min(1080px, calc(100% - 40px)); margin: 28px auto 64px; }
.kb-preview-main .kb-article { box-shadow: 0 14px 34px rgba(21, 34, 28, 0.05) !important; }
@media (max-width: 1120px) {
  .kb-preview-main { grid-template-columns: minmax(0, 1fr); width: min(880px, calc(100% - 40px)); }
  .kb-preview-main .kb-toc { display: none; }
}
@media (max-width: 620px) {
  .kb-preview-main { width: 100%; margin: 0; }
  .kb-preview-main .kb-article { border: 0 !important; border-radius: 0 !important; }
}
CSS;

    public function render(string $locale, string $title, string $excerpt, string $body): string
    {
        $banner = self::BANNERS[$locale] ?? self::BANNERS['en'];
        $outlineLabel = match ($locale) {
            'zh-Hans' => '本页内容',
            'zh-TW' => '本頁內容',
            default => 'On this page',
        };
        $outline = KnowledgebaseOutline::apply($body, $outlineLabel);

        $stylesheet = self::STYLESHEET;
        $chromeStyle = self::CHROME_STYLE;
        $safeLocale = $this->escape($locale);
        $safeBanner = $this->escape($banner);
        $safeTitle = $this->escape($title);
        $safeExcerpt = $this->escape($excerpt);
        $outlineBody = $outline['body'];
        $outlineToc = $outline['toc'];
        $diagramAssets = str_contains($body, 'class="mermaid"')
            ? '<script src="/assets/vendor/mermaid/mermaid.min.js?v=11.12.0"></script>'
                . '<script src="/assets/knowledgebase-mermaid.js?v=20260914a"></script>'
            : '';

        return <<<HTML
            <!doctype html>
            <html lang="{$safeLocale}">
            <head>
              <meta charset="utf-8">
              <meta name="viewport" content="width=device-width, initial-scale=1">
              <title>{$safeBanner}</title>
              <link rel="stylesheet" href="{$stylesheet}">
              <style>{$chromeStyle}</style>
            </head>
            <body class="kb-preview-page">
              <header class="kb-preview-bar">
                <span class="kb-preview-flag"><span class="kb-preview-dot"></span>{$safeBanner}</span>
                <span class="kb-preview-locale">{$safeLocale}</span>
              </header>
              <main class="kb-preview-main kb-docs">
                <article class="kb-article">
                  <div class="kb-article-meta"><span class="kb-pill">{$safeLocale}</span></div>
                  <h1>{$safeTitle}</h1>
                  <p class="kb-article-lead">{$safeExcerpt}</p>
                  <div class="kb-article-body">{$outlineBody}</div>
                </article>
                {$outlineToc}
              </main>
              {$diagramAssets}
            </body>
            </html>
            HTML;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
