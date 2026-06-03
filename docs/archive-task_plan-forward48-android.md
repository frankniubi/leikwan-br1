# Forward 48 Auto-Remediation Investigation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Trace why `forward 48` (`32216`) still needs manual operator action, then implement the smallest safe automatic remediation loop or prove the remaining fix must be backend-side.

**Architecture:** Keep the existing `client -> slave -> sidecar -> target` topology. First prove which segment actually violates latency intent. Then map the current code paths that publish policy, evaluate policy, and react to it. If the missing behavior is an unimplemented automatic move/quarantine loop, add it in the narrowest place that already owns path decisions, starting with test-first changes. If the backend itself is inherently too far away, stop short of blind automation and surface the exact boundary where infrastructure/operator input is still required.

**Tech Stack:** Python agents (`client-agent`, `slave-agent`, `sidecar-agent`), PHP panel (`master`), live SSH diagnostics on client/slaves/panel, `unittest`, focused shell probes, markdown planning files.

---

## Phase Status
- [x] Phase 1 - Recover current context and create planning files
- [x] Phase 2 - Deep trace the live `forward 48` latency path and automation boundary
- [x] Phase 3 - Write the implementation plan doc for the automatic remediation gap
- [x] Phase 4 - Execute the implementation plan task by task with verification
- [x] Phase 5 - Summarize shipped behavior, remaining gaps, and best solution

## Constraints / Guardrails
- Guangzhou-Hongkong mode must use a sidecar with a CNIX IP; do not replace it with a public-China path
- CF bond mode must terminate through a real running VM in Japan that hosts the Cloudflare wstunnel
- Bonded path must remain `client -> slave -> sidecar`
- Never optimize by switching to direct `client -> sidecar` in this environment
- Sidecar is the CNIX-facing hop and has the IPLC backend
- Keep topology as `client -> slave -> sidecar -> target`
- Use WSL-preferred SSH path where possible
- Do not expose secrets in user-facing output
- Do not claim automatic remediation exists unless code + live behavior prove it
- If the remaining bottleneck is the backend target itself, do not invent a fake auto-move; document the missing authority/input needed

- [x] Verify and fix the last auto-switch action-layer regression seam (`upstream_realization_mode` persistence in mocked DB harnesses)
- [x] Re-run targeted local panel/slave verification for the sidecar-upstream automation path
- [x] Sync tested-success panel-side changes to `10.0.10.196`
- [x] Verify remote syntax/tests/schema after sync

## 2026-05-30 Android app planning extension

**Goal:** Produce a LeiKwan Android app design/spec and implementation plan grounded in the current `client -> slave -> sidecar -> target` workflow, existing branch/worktree prior art, and NekoBox capabilities, with a must-work default path and optional user-custom routing/split tunneling.

### Phase Status
- [x] Phase 6 - Restore planning context and collect baseline workflow docs
- [x] Phase 7 - Inspect existing worktrees, especially Android- and workflow-parity-related branches
- [x] Phase 8 - Research NekoBox Android architecture, routing, and split-tunneling capabilities
- [x] Phase 9 - Clarify product scope and success criteria with the user
- [x] Phase 10 - Write Android design spec and get user approval
- [ ] Phase 11 - Write Android implementation plan

### Constraints / Guardrails
- Preserve the current slave-backed topology: `client -> selected slave(s) -> sidecar -> target`
- Treat `master` as the authoritative baseline; use other worktrees only as prior art / branch context
- First release must optimize for operational reliability before advanced user customization
- Optional user-custom routing must layer on top of a safe default policy, not replace the default end-to-end path
- Keep Android platform constraints in view: `VpnService`, foreground-service lifecycle, per-app routing limits, battery/background behavior, and device compatibility
- Do not assume the Windows app workflow maps 1:1 to Android without checking each runtime responsibility
