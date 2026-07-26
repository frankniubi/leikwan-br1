# Readable Web Panel Repair Design

## Objective

Make the `master/` server-rendered Web frontend readable for repeated operator work. The selected direction is a readable workbench: dark navigation chrome, a bright working surface, larger operational type, fewer high-value columns, explicit state language, and labelled mobile rows.

The change covers the public shell, authentication pages, Admin pages, Tenant pages, shared tables, status output, and the Web locale patcher. It does not change routes, authorization, CSRF, database contracts, provisioning, agent payloads, transport topology, Desktop WPF, or Android.

## Evidence And Root Causes

- `master/public/assets/leikwan-v2.css` uses 7.5px micro labels, 11px secondary text, 13.5px body text, compressed headings, and dark-on-dark navigation for a console that contains dense operational data.
- `BaseController` contains visible mojibake in a dead client-mode branch, while controllers and locale dictionaries contain raw internal enums and meaning-destroying translations.
- `master/public/i18n/patch.js` matches complete English text nodes only. It cannot translate server-composed count strings and does not update the document language; late DOM updates can remain in English.
- `master/public/index.php` and `master/public/install.php` still reference deleted legacy stylesheets, leaving failure and install pages unstyled after the v2 cutover.
- Status mapping does not give operators a distinct human explanation for pending, offline, stale, or error states.

## Design

### 1. Shared visual system

Keep one v2 stylesheet and the existing class vocabulary. Change the tokens and shared component rules so that:

- console body data is at least 14px, supporting text is at least 12px, and headers are at least 11px;
- headings use normal tracking, readable CJK fallback, and a consistent line height;
- the page background is a quiet warm neutral, while `pg-console` and data sheets are white working surfaces;
- navigation remains dark but uses 13-14px links, stronger active state, and readable group labels;
- tables use 52-54px primary rows, primary plus secondary values in one cell, tabular numerals, sticky headers where bounded scrolling exists, and no page-level horizontal overflow;
- controls have stable dimensions, visible focus, short labels, and one row-menu trigger rather than permanent action clusters;
- mobile core lists become labelled structured rows at the existing responsive breakpoint; non-core tables remain horizontally scrollable when restructuring would change behavior;
- status is always a text label plus symbol/shape plus accessible color, with `unknown` and `offline` remaining distinct.

No decorative card matrix, gradient background, new framework, build step, or behavioral CSS selector is introduced.

### 2. Human-readable server output

Use existing shared helpers instead of editing every controller independently:

- remove dead mojibake/dead branches from shared rendering helpers;
- add shared human status labels and explanations for resource and endpoint health values;
- map `offline` and endpoint health states through the existing severity vocabulary;
- replace raw enum presentation in the core list and forward/endpoint surfaces with a stable label and one-line explanation;
- point bootstrap/install failure pages at `leikwan-v2.css`.

The helpers escape all dynamic values and preserve the existing form actions and data fields.

### 3. Locale patching and glossary hygiene

Keep English as the source key because the current PHP views are English-keyed, but make patching resilient:

- update `<html lang>` on locale selection;
- patch text and approved attributes without overwriting non-empty user input;
- support stable token patterns for server-composed count/status text where exact matching is impossible;
- remove or replace mojibake keys and zero-width-space variants so they cannot become dead translation entries;
- curate the high-impact operational glossary in both `zh-Hans.json` and `zh-TW.json`: ports, Slave, Bond, bond leg, Forward, chain, endpoint, session, traffic, weight, runtime, ticket, and install/update actions;
- move newly exposed hardcoded controller messages to locale keys instead of emitting Simplified Chinese directly from PHP.

Brand and protocol names such as LeiKwan, LeiGod, MPTCP, WireGuard, SNI, Sidecar, Bond, and Forward remain stable technical terms unless a locale already has an established product label.

### 4. Verification

Add focused source/behavior guards for the shared shell, failure-page asset references, status vocabulary, locale language updates, and user-input preservation. Run PHP syntax checks and the relevant existing PHP tests. Serve the panel locally and verify representative public, login, Admin, and Tenant pages at 1440px and 390px with no console errors or page-level horizontal overflow.

## Compatibility And Rollback

The change is limited to presentation, text rendering, locale patching, and shared view helpers. Existing routes, form names, permissions, CSRF tokens, persistence, and runtime payloads remain untouched. Reverting the focused commit restores the previous v2 skin without requiring a database or deployment migration.
