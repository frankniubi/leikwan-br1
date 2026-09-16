# Session Handoff — Knowledge Base, API Docs, GitHub Publish, Localization and Homepage

Date: 2026-09-14 (Asia/Taipei)
Workspace: `C:\Users\frankniubi\Downloads\leikwan-br1`
Head at handoff: `b5aed88a5e4c3b9d13b3d97510676717046dff69`

> Credentials are deliberately **not** recorded in this file. Panel access, database
> credentials and the GitHub egress proxy are held by the operator; this document
> names the hosts and paths only.

---

## 1. What this session was asked to do, in order

| # | Request (verbatim intent) | Outcome |
| --- | --- | --- |
| 1 | Finish the knowledge base rewrite; shorter, friendlier text; make the preview look like a real knowledge base rather than a "kindergarten website" | Rewrote 5 remaining topics in 3 locales, replaced the guide styling with a documentation skin, shipped |
| 2 | "The Native Outbound thing, and the API docs — the API docs can be professional" | Wrote the Native Outbound reference, refreshed the v1 API doc, found an unfixed defect |
| 3 | "Just push it" / "pushing to the server is enough" | Published a sanitized snapshot commit to GitHub |
| 4 | "i18n shows different things on different pages; the language selection should not change with the language; the homepage is ugly, redesign it, but back it up first so we can roll back" | Unified the language control, took a backup tag plus a panel backup, redesigned the homepage |
| 5 | "The homepage is still ugly — be bold, use your claude-fable-5 skills" | Rebuilt the hero as a dark route board; ran a fresh-context design verifier |
| 6 | "Too much text, too few graphics. There is more than one departure point now; there are single lines and BGP; you can put the carriers' logos in" | Replaced the prose with a drawn network: an SVG route map, departure chips, line-type glyphs, carrier chips |

---

## 2. Work item 1 — Knowledge base rebuild

### Problem
The guides read like internal engineering notes, and the article rendering was two
unrelated things: the reader page used one inline stylesheet, while the editor's
preview was a separate page with a 52px headline and a blue banner. The product had
also retired the connection-mode and Sidecar concepts, but the guides still taught
them.

### Shipped
- **New shared documentation skin** `master/public/assets/knowledgebase-docs.css`:
  three-column layout (topic navigation, article, "on this page" rail), numbered
  step lists, callout blocks, code chips, framed diagrams, captioned screenshots.
- **New `master/src/Support/KnowledgebaseOutline.php`** — derives the reading-order
  rail from the article's `h2` elements.
- **New `master/src/Support/KnowledgebasePreviewDocument.php`** — the editor preview
  now renders the same stylesheet and the same article markup as the reader page.
- Removed the conflicting knowledge base block from `master/public/assets/bridge-modern.css`;
  the guides now own their own typography (one stub rule keeps the shared page rhythm).
- Retuned the Mermaid runtime: sage/grey theme variables, compact flowchart spacing,
  natural sizing instead of stretching to the container width.
- **Rewrote 5 topics × 3 locales** in short user language: install-client,
  getting-started-first-forward-checklist, connect-and-verify,
  troubleshooting-common-mistakes, glossary. Together with the two rewritten earlier
  (five-minute overview, no-GUI clients) that is 7 topics × 3 locales = 21 articles.
- `topics.php` went from 9 to 7 topics; the retired connection-mode and Sidecar
  guides were **archived, not deleted** (live rows are `status='draft'`, old links
  still resolve). Sort order renumbered 10/20/30/40/50/60/70.
- Fixed the tenant help note that still advertised "Choose a connection mode" and
  "Set up Bond and Sidecar".
- Regenerated the seed migration and updated every dependent test
  (catalogue, seed regression, migration behaviour, reader regression, tenant links,
  visual fixture, login-method and bridge-modern surface tests).

### Published to production
Panel files replaced, and live article rows refreshed with a guarded per-topic,
per-locale `UPDATE`. Verified live: 7 published topics per locale, all 21 articles
render 6 `h2` sections, 6-item outline, 1 diagram (glossary 0), 1 screenshot.

Article body sizes now: English 2 744–2 821 chars for the five rewritten topics,
Simplified Chinese 1 430–1 779, Traditional Chinese 1 446–1 790.

Commit: `a62d6ca1`.

---

## 3. Work item 2 — Native Outbound and the API documentation

### Delivered
- **New `docs/api/native-outbound.md`** — availability preconditions each mapped to
  its own failure code, plan feature flags (`allow_native_outbound`,
  `allow_native_outbound_unrestricted`), server-assigned target ownership, direction
  identity and its per-service uniqueness (`uniq_forwards_native_direction`), create
  and patch shapes, the client runtime contract, the added failure codes, and
  operational checks.
- **Updated `docs/api/user-forward-lines-v1.md`** — the v1 contract has accepted
  `native_outbound` on create/patch and presented `native_outbound` +
  `native_direction` on the resource since the native work landed on 2026-09-01,
  but the document (last written 2026-08-02) never mentioned either field. Added both,
  added the native error codes, and reworded the tenant boundary away from retired
  Sidecar/MPTCP vocabulary.
- `master/README.md` — indexed the new document, and fixed an unrelated broken link
  that pointed at a local Windows path instead of the repository file.

Every statement was read out of the code before it was written; the verified contract
facts are listed in `docs/reports/2026-09-14-api-docs-and-native-outbound.md`.

### Defect found and NOT fixed (open)
The v1 patch path cannot turn native outbound **on** for an existing forward, and it
fails in a way that looks like success.

`ForwardLineApiController::patch` chooses the structural or non-structural
provisioning path from a condition that covers `forward_route_id`, `target_source`,
`target_service_id`, a changed `transport_mode` and `listen_port = 0` — but **not**
`native_outbound`. So `PATCH {"native_outbound": true}` takes the non-structural
path, which writes only `listen_port`, `target_host`, `target_port`,
`transport_mode`. It never writes `native_outbound_enabled` or
`native_direction_key`, while `mergePatch` has already replaced the target with
`127.0.0.1`.

Reproduced against the real contract classes with an ordinary forward
(`target_host = 203.0.113.9`, `target_port = 443`, `native_outbound_enabled = 0`):

```text
changes={"native_outbound":true}
structural=false
merged.target_host=127.0.0.1
merged.target_port=443
merged has native_outbound_enabled key: no
```

The caller receives `200` with `native_outbound: false`, and the forward now points
at loopback — the self-referencing shape behind the 2026-09-11 forward 180 incident.
The panel's own form does not have this problem: `TenantController` includes
`native_outbound` in its structural decision.

**Proposed fix (one line plus a test):**
1. Add `array_key_exists('native_outbound', $changes)` to the structural condition in
   `ForwardLineApiController::patch`, routing the change through `saveForward` and
   its native validation.
2. Add a regression test asserting that the patch either records the native type and
   direction or fails with a stable code, and never leaves an ordinary forward
   pointing at `127.0.0.1`.

The API reference documents the current behaviour honestly (creates a native forward
or uses the panel form) rather than describing the broken path as supported.

Commit: `d7bd2385`.

---

## 4. Work item 3 — Publishing to GitHub

The remote is a curated, sanitized snapshot branch. `master` and `origin/master`
had diverged (local 232 ahead, 8 behind) and **a direct push must not be attempted**:
the local history contains two obsolete ~135 MB Windows installer blobs from around
commits `8c057a24` and `c9d8c37c`, over GitHub's 100 MB limit. This constraint was
already documented in the remote-only handoff
`docs/handoffs/2026-09-04-github-device-migration-handoff.md`.

### What was published
A single snapshot commit whose parent is the previous remote tip, whose tree is the
current curated local source:

```text
19661f80709e733e1a11d661a4e96e14f42809b0   chore: synchronize sanitized source snapshot
parent    = 66150a11b67dd9d0b2feeaeaabb976c4fb25ebc3
files     = 2118   (remote previously held 1834)
max blob  = 90,284,379 bytes
push      = fast-forward, no force, no history rewrite
```

The shared `master` checkout was **not** reset, rebased, or rewritten. Verified with
proxied `git ls-remote`: remote `refs/heads/master` = `19661f80`.

### Excluded (1610 paths)
| Category | Content |
| --- | --- |
| macOS build scratch | `artifacts/macos/publish-*` — six version directories, 1350 files |
| Windows staging | `artifacts/windows/_setup_stage-*`, `publish-stale-*` — including two 163 MB executables |
| Test scratch | `**/TestResults/**` — four 165–212 MB testhost hang dumps |
| Stale planning | `.superpowers/`, root `PUBLISH-PLAN.md` |

### Scans run before pushing
- No reachable blob over 95 MB; no forbidden credential filenames; no token patterns
  (`AKIA…`, `ghp_…`, `sk-…`, `xox…`).
- Private-key marker hits were inspected individually and are all benign: the CI
  denylist fixture in `.github/workflows/macos-client.yml`, the TLS-key upload
  placeholder and error strings in `AdminController` / `SslCertificateService`, and
  an i18n dictionary entry that maps the marker string.
- `leikwan-android/signing/leikwan-android-release-lineage.pem` was included: its own
  README states "no secrets; required for every build".

### Note
This publish does not move the local branch. Local `master` remains **236 commits
ahead and 9 behind** `origin/master`; that divergence is normal for this repository
and every future publish needs the same snapshot treatment.

---

## 5. Work item 4 — Localization consistency and the language control

### Root causes found (three, not one)
1. **Two different switchers.** `BaseController::localeSwitcherHtml()` emitted
   `English / Simplified Chinese / Traditional Chinese`; `install.php` hand-rolled a
   second copy with `EN` instead. The installer and the site could never match.
2. **The control translated itself.** `patch.js` patches text nodes from the locale
   dictionary, and that dictionary contains `Language`, `English`,
   `Simplified Chinese`, `Traditional Chinese`. On a Chinese page the control became
   `语言 [简体中文]`, on Traditional `語言 [繁體中文]` — the selector changed with the
   language it was selecting, and the option list stopped being readable.
3. **The document language was hardcoded.** `<html lang="en">` appeared in both page
   shells and in `install.php`, so a Chinese page declared itself English to browsers,
   fonts and screen readers until JavaScript corrected it.

### Fixed
- **New `master/src/Support/LocaleSwitcher.php`** — one implementation used by both
  shells and by `install.php`. Options are endonyms (`English`, `简体中文`, `繁體中文`)
  and the visible label is an inline globe glyph, so the visible control is identical
  in every language. Only the invisible accessible name is localized.
- `patch.js` now skips the whole `.locale-switch` subtree, in both text and
  attribute patching.
- **New `LocaleResolver::fromGlobals()`** so the shells can resolve the locale before
  rendering; `<html lang>` is now the resolved locale.
- Removed a dangling footer hint ("Language can also be changed from the top bar.")
  that pointed at nothing and carried a translation entry.

Verified live: all three locales emit `<html lang="en|zh-Hans|zh-TW">` and the
identical option list `English|简体中文|繁體中文`.

---

## 6. Work items 5 and 6 — Homepage redesign

### Before
A Chinese-only page served to every visitor: a light card hero, a hardcoded
"Shanghai to five directions" route catalogue, retired Slave / Sidecar vocabulary in
the path diagram, mixed-language headings, centred section headings against a
left-aligned hero, and a large empty area at the foot.

### After (three iterations, each deployed)
1. `e77cc2bd` — server-rendered per-locale copy, first restructure.
2. `acb29945` — bold redraw: full-bleed ink hero carrying the route catalogue as a
   board with mono codes and hairline rows, editorial body with large numerals, a
   second ink band to close.
3. `f6371540` — the promise became the headline and the brand the eyebrow; one filled
   action per screen (the top-bar sign-in became a secondary pill); step numerals
   moved to the accent colour (they were 1.8:1 on white); the section labels stopped
   repeating the step numbering; muted ink-surface text raised for contrast.
4. `b5aed88a` — **the prose was replaced by a drawing.**

### The current homepage
- **Hero: an SVG route map.** Six departure cities on the left fan through the bridge
  hub into six destinations on the right. Drawn, not described.
- **Departure chips** — the six ingress zones the panel actually reports.
- **Line types** — two cards with a glyph each: a single bar for 单线, three coloured
  bars (telecom blue / unicom red / mobile teal) for BGP 多线.
- **Carrier chips** — one per carrier, each in its conventional brand colour with a
  monogram tile.
- **Closing band** — one dark band with the two actions.
- Roughly half the copy of the previous version was removed.

### Data used (read from the panel, not invented)
`ultra_zones` reports six ingress zones: **Shanghai, Beijing, Guangzhou, Fuzhou,
Guiyang, Hong Kong**. `UltraInventoryService::ROUTE_CLASSES` is
`['bgp', 'single_telecom', 'single_unicom', 'single_mobile', 'wireguard', 'direct',
'relay', 'generic', 'unknown']` and `ISP_ALIASES` maps 电信/联通/移动/通用(BGP). Eleven
destinations are configured. The previous homepage's "Shanghai and five directions"
was stale.

### A fresh-context design verifier
Per the `subagent-orchestration` pattern from `kpab/claude-fable-5-skills` (which —
note — is a set of working disciplines, not a design skill), a clean-context subagent
was given the specification and the renders and asked to report discrepancies. It
found, correctly:
- the zh-TW path lede contained a duplicated phrase ("再由它由它到達");
- the phone rule targeted `.bridge-home-closing` instead of the inner grid, so the
  closing band never stacked on a phone;
- the headline said nothing a gamer would understand;
- the step numerals failed contrast and two numbering levels collided;
- two filled buttons competed on the first screen.

All five were fixed and shipped in `f6371540`. Its remaining suggestions were
declined with reasons: the homepage is deliberately excluded from the shared
`bridge-modern.css` skin and therefore carries its own tokens; and the brand string
is the panel-wide registered name.

---

## 7. Backups and rollback anchors

| Anchor | Location |
| --- | --- |
| Homepage + i18n source, before the redesign | git tag `backup/homepage-i18n-pre-redesign-20260914` → `d7bd2385400afc56230aaa085dd829f4ed5107c6` |
| Panel files before the redesign | `/root/leikwan-br1-backups/homepage-pre-redesign/` (492K: both controllers, install.php, three stylesheets, the whole i18n directory) |
| Knowledge base article rows before the refresh | `/root/leikwan-br1-backups/kb-docs-rows/rows-before.sql` |
| Knowledge base source files | `/root/leikwan-br1-backups/kb-docs-<timestamp>/kb-docs-before.tgz` |
| Archived-guide decision record | `/root/leikwan-br1-backups/kb-archive-before.txt` |
| Five-minute overview before its rewrite | `/root/leikwan-br1-backups/kb-landing-before.txt` |
| Published snapshot commit | local ref `refs/heads/publish/sanitized-20260914` → `19661f80709e733e1a11d661a4e96e14f42809b0` |

Rollback of the source: `git checkout backup/homepage-i18n-pre-redesign-20260914 -- master/`
and re-upload the affected files. Rollback of the homepage and language control alone:
copy the files back from `/root/leikwan-br1-backups/homepage-pre-redesign/`.

---

## 8. Not done / open

1. **The v1 native-outbound patch defect** (§3) — documented, reproduced, not fixed.
   This is the highest-value open item; it is a one-line change plus a test.
2. **The publish handoff on the remote was not updated.** The established process ends
   by recording the push result in `docs/handoffs/2026-09-04-github-device-migration-handoff.md`,
   which exists only on the remote. The operator said pushing was enough, so no second
   commit was made.
3. **Local `master` is still 236 ahead / 9 behind `origin/master`** and every future
   publish needs the same snapshot treatment described in §4.
4. **Carrier logos are monogram tiles, not real logos.** No licensed artwork exists in
   the repository and reproducing trademarks is not something to improvise. Drop files
   at `master/public/assets/carriers/{ct,cu,cm,bgp}.svg` and the chips can use them.
5. **Homepage and panel design tokens still differ** (radius 10/16px on the homepage
   against the panel's `--radius`). Cosmetic and deliberate for now.
6. **From earlier rounds, still open:** macOS and Windows no-GUI clients apply updates
   only through `leikwan update` and the macOS `start/stop/restart` plus the apply half
   of `update` have not been exercised end to end against an installed service;
   macOS clients still never report `ultra_runtime_fingerprint` in the panel heartbeat
   although the runtime's `status.json` contains it.

---

## 9. Pre-existing failures (not caused by this session)

Confirmed by restoring the pristine files from `HEAD` and re-running:

- `master/tests/i18n_locale_regression_test.php` — reports uncovered text locale
  strings in `AdminController.php` (for example "Any carrier", "Device hash prefix")
  and in `TenantController.php` (the native-outbound field notes). Verified present at
  `HEAD` before any change in this session. The files rewritten here added no new
  uncovered strings.
- `master/tests/knowledgebase_service_test.php` — fails locally because this PHP build
  has no PDO driver; it lacks the sqlite re-exec helper the other database tests use.
- Other known-unrelated panel failures seen earlier: `client_online_limit_wiring_regression_test.php`,
  `client_install_simplification_regression_test.php`,
  `client_config_active_boundary_regression_test.php`, `oidc_client_test.php`.
- Manifest entries with no local file: `gost_linux_amd64`, `leigod_linux_amd64`,
  `mptcp_sidecar_runtime`.

---

## 10. Environment notes and traps

- This PHP build **has no `mbstring`**. Using `mb_strlen()` in the homepage renderer
  returned HTTP 500 until it was removed. `mb_*` calls are not safe here.
- The repository root contains both a branch named `master` and a directory named
  `master`. Git commands must disambiguate (`refs/heads/master` or a trailing `--`).
- Several controllers are **CRLF** on disk (`AdminController`, `BaseController`,
  `TenantController`, `install.php`); patch scripts must preserve the file's own
  newline rather than assuming LF.
- **Never run `git stash` in this repository.** An early investigation ran
  `git stash push -- <paths>`, which failed on untracked pathspecs, and the following
  `git stash pop` then popped an unrelated pre-existing stash
  (`stash@{0}: stray-sidecar-precursor-eb0368b`) and conflicted in
  `sidecar-agent/sidecar_agent.py`. It was restored with
  `git restore --source=HEAD --staged --worktree` and the stash entry was left intact,
  but the safer technique for "compare against HEAD" is copying the file aside and
  using `git checkout -- <path>`.
- Direct file writes into this repository intermittently fail with `Errno 22`,
  `PermissionError [WinError 5]` or `ReplaceFileW EIO`. The reliable pattern is to
  write to a temporary file and `os.replace()` it.
- Nested quotes inside `plink`/`pscp` one-liners get mangled by PowerShell. Write the
  remote command to a `.sh` file, normalise it to LF, `pscp` it, and run
  `plink -m <local file>`.
- Chrome headless ignores small `--window-size` values (a 430px request rendered at a
  512px viewport), which makes narrow-width screenshots look cropped. Measure with
  `--dump-dom` and a script that writes geometry into `document.title` instead of
  eyeballing a screenshot.
- The published GitHub tree omits whole directories that are tracked locally
  (`openwrt/`, `tools/leikwand/` were published for the first time in this session;
  `bridge-lite/`, `NekoBoxForAndroid-main/`, `platform-tools/` are not tracked at all).

---

## 11. Commit index for this session

| Commit | Summary |
| --- | --- |
| `a62d6ca1` | docs(kb): rebuild the user guides as a documentation site |
| `d7bd2385` | docs(api): add the Native Outbound reference and refresh the v1 forward API doc |
| `e77cc2bd` | feat(web): one language control everywhere and a redesigned homepage |
| `acb29945` | feat(web): redraw the homepage as a dark route board over an editorial body |
| `f6371540` | fix(web): put the promise in the headline and fix the phone closing band |
| `b5aed88a` | feat(web): draw the homepage network instead of describing it |

All six are on local `master` and **none are pushed as commits** — the published remote
holds the single squashed snapshot commit `19661f80` instead.

Related documents written this session:

- `docs/api/native-outbound.md` — the Native Outbound reference
- `docs/api/user-forward-lines-v1.md` — refreshed v1 reference
- `docs/reports/2026-09-14-api-docs-and-native-outbound.md` — verified contract facts
  and the reproduced patch defect

---

## 12. Suggested next steps, in order

1. Fix the v1 native-outbound patch defect and add its regression test (§3).
2. Decide whether the six local commits should be squashed into the next sanitized
   snapshot publish, and run that publish (§4).
3. Replace the carrier monogram tiles with licensed logo files (§8.4).
4. Write the i18n dictionary entries for the uncovered `AdminController` and
   `TenantController` strings, which is the only thing keeping the locale regression
   test red (§9).
5. Exercise the macOS no-GUI service lifecycle end to end on the build VM (§8.6).
