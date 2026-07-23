# Bridge Lite Multi-Theme Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the black-only Bridge Lite UI with multiple selectable themes and a non-black default.

**Architecture:** Keep this as a frontend-only change in `bridge-lite/bridge-lite/public/app.html`. Add a theme contract test that checks the selector, theme IDs, persistence key, non-black default variables, and legacy black-token removal. Store the user choice in browser `localStorage` and apply it via `document.documentElement.dataset.theme`.

**Tech Stack:** Single-file HTML/CSS/JavaScript, PHP CLI contract tests.

---

### Task 1: Theme contract test

**Files:**
- Create: `bridge-lite/bridge-lite/tests/bridge_lite_frontend_theme_options_test.php`

- [ ] Write a PHP test that reads `public/app.html` and asserts the four theme options exist, default theme is clean light, persistence uses `bl_theme`, and legacy black-only tokens are removed from default CSS.
- [ ] Run the test and confirm it fails before implementation.

### Task 2: Frontend multi-theme implementation

**Files:**
- Modify: `bridge-lite/bridge-lite/public/app.html`

- [ ] Replace root dark variables with light default variables.
- [ ] Add `business-blue`, `warm`, and `low-light` variable blocks.
- [ ] Add login/app theme selectors.
- [ ] Add JavaScript `detectTheme`, `setTheme`, and `renderThemeSelectors` using `localStorage` key `bl_theme`.
- [ ] Replace hardcoded dark SVG/topbar/scrim tokens with variables or light-friendly colors.

### Task 3: Verification and deploy

**Files:**
- No additional production files.

- [ ] Run the new theme test and existing frontend contract tests.
- [ ] Run PHP syntax across `src`, `public`, and `tests`, then run all PHP tests.
- [ ] Package and deploy only `bridge-lite/bridge-lite` to `/www/wwwroot/in.leikwanhost.com`.
- [ ] Verify remote hash and public HTTP for `/`, `/api/health`, and sensitive path 404s.
