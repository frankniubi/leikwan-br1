# Ultra Panel Policy and Ephemeral Line Lease Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax and must be executed in order.

**Goal:** Replace persistent Client-side Ultra line material with short-lived in-memory leases while making Panel rate, quota, expiry, revocation, accounting, and stop behavior authoritative on Linux and Windows.

**Architecture:** Keep the existing Panel-integrated Ultra control plane and direct Client-to-provider data path. The Panel issues a signed policy grant and one-time provider lease; Linux and Windows use the lease only in memory and never persist line information. No slave, sidecar, independent data gateway, new public port, or automatic provider fallback is introduced.

**Tech Stack:** PHP 8.3, MySQL migrations, Python 3, Go standard library, systemd/nftables, .NET/WPF, Windows Job Objects, PowerShell/Inno Setup, PHPUnit-style standalone PHP tests, pytest, Go tests, and `dotnet test`.

---

## Plan Decomposition

The approved specification spans independent subsystems. Execute the following
plans in order; each plan has its own tests and can be reviewed independently:

1. [Remove `cf_mode` from Linux and Windows Clients](2026-08-25-ultra-client-remove-cf-mode.md)
2. [Panel policy and lease control plane](2026-08-25-ultra-panel-policy-ephemeral-line-lease-control-plane.md)
3. [Linux ephemeral Ultra runtime](2026-08-25-ultra-linux-ephemeral-runtime.md)
4. [Windows Ultra runtime parity](2026-08-25-ultra-windows-runtime.md)
5. [Accounting, stop cleanup, release, and acceptance](2026-08-25-ultra-accounting-stop-release.md)

The `cf_mode` Client removal plan lands first. The control-plane plan must then
land before either Ultra runtime plan. The accounting and release plan is the
final gate and must run against both clients.

## Non-Negotiable Invariants

- Ultra has no slave or sidecar dependency.
- There is no independent data Gateway or new public listener.
- Client local forwarding remains fixed-target and is not SOCKS5.
- Panel policy may reduce a local setting but may never be raised locally.
- Provider IP, port, username, password, line ID, and raw material never enter
  persistent Client state, command lines, environment variables, logs, status,
  heartbeat, or admin HTML.
- Provider information may exist only in memory while an active direct session
  needs it; a local administrator can still inspect that memory.
- Expiry, quota exhaustion, service suspension, forward disable, generation
  change, and device revocation are hard stop conditions.
- Stop is not complete until listeners, provider sockets, child processes,
  temporary IPC, and credential-bearing state are gone.
- Existing supported `current_mode` and `leigod_mode` behavior remains
  unchanged.
- Linux and Windows Clients reject `cf_mode`, stop any previously applied
  `cf_mode` forward, and never fall through to another runtime.
- Panel/server-side `cf_mode` compatibility remains outside the Client removal
  boundary.

## Worktree Rules

This repository is dirty before this work. Before every implementation commit:

```powershell
git status --short
git diff -- <owned paths>
git diff --cached
```

Stage only files owned by the current task. Never reset, restore, stash, or
rewrite unrelated changes. Do not include credentials from `leikwan-v3.txt`,
`cantons.txt`, or `shanghais.txt` in code, tests, plans, or commits.

## Dependency Gates

### Gate 0: Client backend retirement

**Status: completed in `codex/ultra-policy-v2`.**

Linux and Windows must reject `cf_mode`, stop previously applied `cf_mode`
forwards, and pass supported-mode regression tests before Ultra v2 work begins.
This gate does not alter Panel or server-side historical `cf_mode` behavior.

### Gate A: Control contract

The Panel must issue `leikwan-ultra-policy-v2`, reject stale/replayed lease
requests, and stop issuing grants for inactive or expired services before
clients consume the new fields.

### Gate B: Linux runtime

The Linux Client must first reject and clean `cf_mode`. The Linux helper then
must consume policy v2, hold provider information only in memory, enforce
rate/quota/expiry, and pass stop cleanup tests before Windows parity is enabled.

### Gate C: Windows runtime

The Windows Client must reject and clean `cf_mode`. The shared helper and
Windows supervisor must then provide the same policy and stop semantics,
including Job Object ownership and heartbeat counters.

### Gate D: Production acceptance

Only after credential scans, packet captures, quota/expiry canaries, accounting
idempotency, and listener/process cleanup pass may the Panel feature flag issue
v2 leases to production forwards.

## Cross-Plan Verification Commands

Run these from the repository root after the relevant plan:

```powershell
python -m pytest client-agent/tests/test_ultra_client.py client-agent/tests/test_ultra_runtime.py client-agent/tests/test_ultra_renewal_schedule.py client-agent/tests/test_ultra_status.py -q
(cd tools/ultra-client && go test ./...)
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --no-restore
```

Expected result is zero failures. The full Windows solution test command is run
only after the Windows plan because the current worktree contains unrelated
Windows changes.

## Rollback Boundary

Rollback disables v2 lease issuance and stops affected Ultra forwards. It does
not restore persistent provider material to Client and does not silently fall
back to a legacy provider path. Existing non-Ultra modes remain available.
