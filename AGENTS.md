# gstack

- For all web browsing, use the `/browse` skill from gstack. Never use `mcp__claude-in-chrome__*` tools.
- Available gstack skills: /office-hours, /plan-ceo-review, /plan-eng-review, /plan-design-review, /design-consultation, /design-shotgun, /design-html, /review, /ship, /land-and-deploy, /canary, /benchmark, /browse, /connect-chrome, /qa, /qa-only, /design-review, /setup-browser-cookies, /setup-deploy, /retro, /investigate, /document-release, /codex, /cso, /autoplan, /plan-devex-review, /devex-review, /careful, /freeze, /guard, /unfreeze, /gstack-upgrade, /learn

## Default branch baseline for AI/debug work

- Unless the user explicitly says otherwise, treat `master` as the authoritative baseline branch for this repo.
- Start any new debug/fix/feature work from the current `master`, or branch/worktree from `master`.
- Do not base new work on old topic branches or stale worktrees unless the user explicitly asks for that branch.
- `codex/android-single-bond-linux-parity` may be kept aligned as a mirror branch, but `master` is the default source of truth for future agent changes.

## Ultra Mode Hard Redline

- Never involve LeiKwan slave/CDN hosts in Ultra mode analysis, diagnostics, deployment, SSH access, or data-path descriptions.
- Ultra TCP path: `client -> leased Ultra provider relay -> target`.
- External-UoT UDP path: `client -> leased Ultra provider relay -> external UoT sidecar -> UDP target`.
- A leased Ultra provider relay is not a LeiKwan slave. Do not call it a slave or inspect `cantons.txt` / `shanghais.txt` for Ultra mode work.
- For Ultra mode, access the client, panel, and sidecar directly. If direct access is unavailable, report that exact blocker instead of introducing a slave hop.
