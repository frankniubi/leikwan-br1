# Knowledge base rewrite: ultra-only, user language

Status: planned (spec agreed 2026-09-14). Nothing in this document is published yet.

## Why

1. The guides read like engineering notes: internal identifiers, flags, hash
   checks, service-manager names. Users cannot follow them.
2. The product no longer offers several connection modes. **There is only
   `ultra`.** Single / Race / Bond / MPTCP / Sidecar guidance describes choices
   users cannot make any more, and it is now actively misleading.

## Target topic set (7 topics x 3 locales)

| # | topic key | title (en) | what it answers |
|---|---|---|---|
| 1 | `getting-started-five-minute-overview` | Get Connected in Five Minutes | buy/activate -> add a forward -> install -> connect |
| 2 | `install-client` | Install the App | Windows app, Android app, Linux one-line install |
| 3 | `getting-started-first-forward-checklist` | Create Your First Forward | route, target, transport, assigned local port |
| 4 | `connect-and-verify` | Connect and Verify | point the game/app at the client, prove it works |
| 5 | `troubleshooting-common-mistakes` | Troubleshoot Common Problems | the five things that actually go wrong |
| 6 | `no-gui-clients` | Run the Client Without a GUI | server-style install + `leikwan` command (already rewritten) |
| 7 | `glossary` | Glossary | the handful of words users meet: 线路/转发/客户端/本机端口/ultra |

Retired (delete from the panel, drop from the manifest and the seed):
`connection-modes-single-race-bond`, `bond-sidecar-setup`.

## Style rules (every article, every locale)

1. First paragraph answers "what will I have when I am done" - no product
   internals.
2. Never name an internal thing: bundle, plist, launchd, SCM, systemd, manifest,
   sha256, attestation, netns, MPTCP, "mode", config keys. Say device / line /
   panel / online / update.
3. Every command is "copy this from that page", never "assemble these flags".
4. Six fixed sections (what you accomplish / before you begin / steps / how to
   know it worked / if it did not work / related), one flow diagram, one
   screenshot - the catalog test enforces this and it reads well.
5. Success = two checks (panel shows the device online + one command reports the
   line running). Failure = at most three causes, in likelihood order.
6. Ultra is the only mode: describe what it does for the user ("keeps the line
   steady while you play"), never as a choice between alternatives.

## Mechanical work this needs

* 21 new/rewritten bodies under `master/resources/knowledgebase/{en,zh-Hans,zh-TW}/`.
* `topics.php`: 9 -> 7 topics; drop the two retired keys.
* `master/scripts/build_knowledgebase_seed.php`: `EXPECTED_TOPIC_COUNT` 9 -> 7.
* Regenerate `master/migrations/20260729_seed_multilingual_knowledgebase.sql`
  (write through a temp file: a direct write into the repo can silently fail on
  the Windows build host).
* `master/tests/knowledgebase_content_catalog_test.php`: topic list/order,
  counts (7 topics, 21 articles, 18 diagrams), per-topic required text and
  diagram requirements, localized terminology rules.
* `master/tests/knowledgebase_seed_regression_test.php` and
  `knowledgebase_migration_behavior_test.php`: article/topic counts.
* Panel: insert the rewritten topics, then **archive** the two retired topics
  (set their articles to `draft`, keep the rows for support history) instead of
  deleting them, and refresh the remaining rows in place with a hash-guarded
  UPDATE (before/after dump into `/root/leikwan-br1-backups/`).
* Re-run the download-centre/KB link tests (`headless_client_artifact_test.php`
  asserts the no-GUI guide link and `macos_client_install_surface_test.php`
  asserts the install labels).

## Open question for the operator

Two Bond/Sidecar articles exist in the live knowledge base today. Archiving them
(set to draft, hidden from the list, still reachable by direct link) keeps any
bookmarked URL working; deleting them removes the confusion entirely. Default in
this plan: **archive**.
