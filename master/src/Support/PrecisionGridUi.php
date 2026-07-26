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

    /** @param array<int, string> $allowed */
    public static function normalizeView(?string $value, array $allowed, string $fallback): string
    {
        $value = strtolower(trim((string) $value));
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    /** @param array<int, array{key:string,label:string,href:string}> $tabs */
    public static function viewTabs(array $tabs, string $active): string
    {
        $html = '<nav class="pg-view-tabs" role="tablist" aria-label="Page views">';
        foreach ($tabs as $tab) {
            $selected = $tab['key'] === $active;
            $html .= '<a role="tab" data-view="' . self::escape($tab['key']) . '" aria-selected="'
                . ($selected ? 'true' : 'false') . '" class="pg-view-tab' . ($selected ? ' active' : '')
                . '" href="' . self::escape($tab['href']) . '">' . self::escape($tab['label']) . '</a>';
        }
        return $html . '</nav>';
    }

    /**
     * Status is encoded three ways at once -- shape, colour and text.
     *
     * The v2 palette is achromatic, so colour carries less signal than it did.
     * None of the three encodings is optional: shape survives colourblindness
     * and grayscale, text survives everything.
     *
     * `data-shape` is emitted on both elements on purpose. The wrapper's copy
     * drives the colour, which the label inherits through `currentColor`; the
     * dot's copy drives the geometry. Dropping either one looks like harmless
     * de-duplication and silently disables half the encoding.
     *
     * The vocabulary is deliberately only what the call sites emit today. An
     * earlier version carried ten further aliases -- `healthy`, `online`, `warn`,
     * `down`, `offline` and friends -- which was neither minimal nor complete:
     * every one of them was unreachable, while `deleting`, `disabled`, `suspended`
     * and `inactive`, which are real values of `nodes.status`, were missing. A
     * half-complete alias list hides exactly the states most likely to arrive
     * next. `unknown` makes them visible instead. When a call site starts
     * emitting a new state, add it here together with the decision about what
     * that state should look like -- that decision is the work, not the arm.
     */
    public static function status(string $label, string $state, string $description = ''): string
    {
        $shape = match (strtolower(trim($state))) {
            'ok' => 'circle',
            'warning' => 'triangle',
            'error' => 'square',
            default => 'unknown',
        };
        $accessibleLabel = $description === '' ? $label : $label . ': ' . $description;

        return '<span class="pg-status" data-state="' . self::escape($state)
            . '" data-shape="' . self::escape($shape) . '" aria-label="' . self::escape($accessibleLabel) . '">'
            . '<i class="pg-status-dot" data-shape="' . self::escape($shape) . '" aria-hidden="true"></i>'
            . self::escape($label)
            . ($description !== '' ? '<small class="pg-status-description">' . self::escape($description) . '</small>' : '')
            . '</span>';
    }

    public static function resourceCell(string $primary, string $secondary = ''): string
    {
        return '<span class="pg-resource-cell"><strong class="pg-resource-primary">' . self::escape($primary) . '</strong>'
            . ($secondary !== '' ? '<small class="pg-resource-secondary">' . self::escape($secondary) . '</small>' : '')
            . '</span>';
    }

    public static function rowMenu(string $label, string $commandsHtml): string
    {
        return '<details class="pg-row-menu"><summary aria-label="' . self::escape($label)
            . '">&#8226;&#8226;&#8226;</summary><div class="pg-row-menu-panel">' . $commandsHtml . '</div></details>';
    }

    public static function detailSection(string $title, string $bodyHtml, string $description = ''): string
    {
        return '<section class="pg-detail-section"><header><h2>' . self::escape($title) . '</h2>'
            . ($description !== '' ? '<p>' . self::escape($description) . '</p>' : '')
            . '</header><div class="pg-detail-body">' . $bodyHtml . '</div></section>';
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
