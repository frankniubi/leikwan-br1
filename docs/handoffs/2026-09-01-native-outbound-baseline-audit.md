# Native Outbound Baseline Audit

**Audited:** 2026-09-01 (Asia/Taipei)

## Decision

Use the repository `master` worktree as the implementation baseline:

```text
HEAD: 96dba6cbc2d7eba76220a17a979900fcc241fcd7
Subject: docs: plan native outbound implementation
```

No registered worktree contains a relevant implementation commit later than
this `master` commit. Divergent branches with branch-only commits are older or
also behind `master`; they are historical WIP and were not copied. The
Windows-workflow-parity worktree has uncommitted changes and remains untouched.

## Relevant Worktrees

Counts are computed independently as `master..worktree` (ahead) and
`worktree..master` (behind), avoiding symmetric-count ambiguity for divergent
histories.

| Worktree | HEAD | Latest commit (local time) | Ahead | Behind | Dirty files | Decision |
|---|---|---:|---:|---:|---:|---|
| repository `master` | `96dba6cb` | 2026-09-01 01:59 | 0 | 0 | 163 | baseline; existing dirty files preserved |
| `.worktrees/android-final-compatible` | `ef494775` | 2026-08-29 15:03 | 10 | 65 | 0 | older divergent Android WIP; do not copy |
| `C:/tmp/lkb-android-renewal` | `c84d5d80` | 2026-08-29 18:26 | 1 | 37 | 0 | older divergent Android WIP; do not copy |
| `.worktrees/android-host-vnext` | `744aa8bc` | 2026-08-27 19:34 | 0 | 102 | 0 | older branch; do not copy |
| `.worktrees/macos-client` | `31b262de` | 2026-08-28 23:01 | 0 | 79 | 0 | older branch; do not copy |
| `.worktrees/ultra-policy-v2` | `fe993238` | 2026-08-25 18:37 | 0 | 153 | 0 | older branch; do not copy |
| `.claude/worktrees/windows-workflow-parity` | `33ca7c12` | 2026-06-01 00:46 | 196 | 749 | 24 | dirty historical WIP; untouched |
| `.claude/worktrees/android-routing-workflow` | `230bb0a0` | 2026-05-29 15:36 | 186 | 749 | 0 | older divergent WIP; do not copy |

The remaining registered worktrees are older snapshots or unrelated topic
branches. Their latest commits are also earlier than `master` and no relevant
native-outbound implementation was found in the scoped search.

## Commands

```powershell
git worktree list --porcelain
git rev-list --count refs/heads/master..HEAD
git rev-list --count HEAD..refs/heads/master
git log -1 --format='%ci|%H|%s'
git status --porcelain
```

## Safety Boundary

- No worktree files were modified, staged, or copied during this audit.
- The current `master` dirty worktree is preserved exactly; implementation
  commits must stage only feature files.
- Credentials and provider connection values are intentionally absent.

