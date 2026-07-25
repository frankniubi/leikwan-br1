<?php
declare(strict_types=1);

namespace Leikwan\Master\Support;

final class PrecisionGridUi
{
    /** @param array<int, array<string, mixed>> $items */
    public static function groupNavigation(array $items): string
    {
        $groups = [];
        foreach ($items as $item) {
            $label = (string) ($item['label'] ?? '');
            $group = (string) ($item['group'] ?? self::groupForLabel($label));
            $groups[$group][] = $item;
        }

        $html = '';
        foreach ($groups as $group => $groupItems) {
            $html .= '<section class="pg-nav-section" data-nav-group="' . self::escape($group) . '">';
            $html .= '<h2 class="pg-nav-heading">' . self::escape($group) . '</h2><nav class="pg-nav-list">';
            foreach ($groupItems as $item) {
                $active = !empty($item['active']);
                $html .= '<a class="nav-link' . ($active ? ' active' : '') . '" href="'
                    . self::escape((string) ($item['href'] ?? '#')) . '"'
                    . ($active ? ' aria-current="page"' : '') . '>'
                    . self::escape((string) ($item['label'] ?? '')) . '</a>';
            }
            $html .= '</nav></section>';
        }
        return $html;
    }

    public static function pageHeader(string $eyebrow, string $title, string $actionsHtml = '', string $statusHtml = ''): string
    {
        return '<header class="pg-page-header"><div><div class="pg-eyebrow">' . self::escape($eyebrow)
            . '</div><h1>' . self::escape($title) . '</h1>' . $statusHtml . '</div>'
            . ($actionsHtml !== '' ? '<div class="pg-header-actions">' . $actionsHtml . '</div>' : '') . '</header>';
    }

    /** @param array<int, array<string, scalar|null>> $metrics */
    public static function metricStrip(array $metrics): string
    {
        $html = '<dl class="pg-metric-strip">';
        foreach ($metrics as $metric) {
            $state = self::escape((string) ($metric['state'] ?? 'neutral'));
            $html .= '<div class="pg-metric"><dt>' . self::escape((string) ($metric['label'] ?? ''))
                . '</dt><dd class="status" data-state="' . $state . '">'
                . self::escape((string) ($metric['value'] ?? '')) . '</dd></div>';
        }
        return $html . '</dl>';
    }

    public static function emptyState(string $title, string $commandHtml = ''): string
    {
        return '<div class="pg-empty-state"><p>' . self::escape($title) . '</p>' . $commandHtml . '</div>';
    }

    public static function sectionHeader(string $title, string $meta = ''): string
    {
        return '<header class="pg-section-header"><h2>' . self::escape($title) . '</h2>'
            . ($meta !== '' ? '<span>' . self::escape($meta) . '</span>' : '') . '</header>';
    }

    private static function groupForLabel(string $label): string
    {
        return match ($label) {
            'Overview', 'Services', 'Clients' => 'Operations',
            'Tenants', 'Registration Codes', 'User Groups' => 'Customers',
            'Plans' => 'Products',
            'Transport Endpoints', 'Slave Groups', 'Slave Nodes', 'Client Groups', 'Credentials' => 'Infrastructure',
            'Knowledgebase' => 'Support',
            'Tenant Area', 'Admin Console', 'Homepage' => 'Context',
            'Account', 'Plans and Billing' => 'Account',
            default => 'Workspace',
        };
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
