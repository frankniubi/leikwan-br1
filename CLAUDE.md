# gstack

- For all web browsing, use the `/browse` skill from gstack. Never use `mcp__claude-in-chrome__*` tools.
- Available gstack skills: /office-hours, /plan-ceo-review, /plan-eng-review, /plan-design-review, /design-consultation, /design-shotgun, /design-html, /review, /ship, /land-and-deploy, /canary, /benchmark, /browse, /connect-chrome, /qa, /qa-only, /design-review, /setup-browser-cookies, /setup-deploy, /retro, /investigate, /document-release, /codex, /cso, /autoplan, /plan-devex-review, /devex-review, /careful, /freeze, /guard, /unfreeze, /gstack-upgrade, /learn

## Default branch baseline for AI/debug work

- Unless the user explicitly says otherwise, treat `master` as the authoritative baseline branch for this repo.
- Start any new debug/fix/feature work from the current `master`, or branch/worktree from `master`.
- Do not base new work on old topic branches or stale worktrees unless the user explicitly asks for that branch.
- `codex/android-single-bond-linux-parity` may be kept aligned as a mirror branch, but `master` is the default source of truth for future agent changes.
