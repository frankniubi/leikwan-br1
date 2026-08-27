# macOS Client Handoff - 2026-08-27

## Delivery status

The macOS client implementation and native packages are complete on branch
`codex/macos-client`. The current source/package revision is commit
`6d16344fa1c907e88ee678b0aeab2bda2e6a2efb`.

Apple Silicon and Intel packages were built on a real macOS arm64 host.
The Intel package was cross-compiled and executed through Rosetta 2. The
artifacts are copied to `artifacts/macos/` locally and their metadata is merged
into `artifacts/versions.json`.

Production panel publication is the remaining release operation. No panel
database migration or application overlay has been performed in this handoff.

## User contract

The Mac binary accepts exactly these effective modes:

- `current_mode` with `single`
- `current_mode` with `bond`
- Ultra policy v2

Every other received mode is discarded before forward state, runtime startup,
or health processing and immediately raises this exact notice:

```text
The mode of this forward is not supported in MacOS binary. 此转发的模式尚未在MacOS客户端支援.
```

The client supports login, owned-client selection, config pull/revision
handling, forward reconciliation, start/stop, health, heartbeat and usage
reporting, diagnostics upload, tray operation, LaunchAgent autostart, and
architecture-aware application updates. The Connections view adds client-id +
token login, structured Shadowsocks/VLESS/WireGuard node editing, local proxy
mode, full-device VPN mode, and automatic direct bypasses for panel/forward
control-plane addresses. Protocol, cipher, transport, connection mode, and
TLS are selector/toggle controls rather than free-form protocol text.

## Ultra confidentiality boundary

- A `macos-desktop` pull receives a scrubbed policy-v2 contract, not provider
  inventory, reusable line tokens, or legacy line fields.
- Provider material enters through a short-lived, nonce-bound lease and is
  handed to `ultra-client` over stdin and an owner-only Unix socket.
- Provider endpoint and credentials are not placed in arguments, environment
  variables, persisted forward state, logs, diagnostics, crash dumps, update
  cache, the app bundle, DMG, or update ZIP.
- Sensitive buffers are bounded and cleared after handoff. Core dumps are
  disabled; memory locking is best effort.
- A local administrator, debugger, kernel compromise, or process-memory reader
  can still inspect secrets while they are actively in use. This design
  prevents static extraction and accidental persistence, not privileged live
  memory inspection.

The required topology remains `client -> slave -> sidecar`; this work does not
introduce direct client-to-sidecar connectivity.

## Update behavior

The client selects the update entry for its running architecture, verifies
declared byte size and SHA-256, rejects ZIP traversal and malformed bundles,
validates bundle identity/version/helper hashes, and stages replacement next to
the installed app. It retains the old bundle for rollback during the swap. If
the installation is not writable, it opens the verified DMG and reports a
manual replacement instead of claiming an automatic update succeeded.

The unsigned build will show normal macOS Gatekeeper confirmation. Signing and
notarization are intentionally outside this release because no Apple Developer
identity is available.

## Native artifacts

| Architecture | Installer | Bytes / SHA-256 | Update archive | Bytes / SHA-256 | Status |
| --- | --- | --- | --- | --- | --- |
| Apple Silicon | `leikwan-bridge-arm64.dmg` | 74,767,188 / `5a3ae824a74cc045e4b51457fd54d3340bbefe680bf88b0c0701a55764e475ca` | `leikwan-bridge-update-arm64.zip` | 65,611,325 / `951d120a3a75886a6a539212e5dd1fd5a8f4443935b0051115e0801e98537607` | native build and real SS/VLESS/WireGuard config checks passed |
| Intel | `leikwan-bridge-x64.dmg` | 77,984,264 / `908c7816b96c2c9ea788839f7efbc60cd206c426deb5f34c3be09b693bc88f36` | `leikwan-bridge-update-x64.zip` | 68,723,493 / `ced3279e3ac7cf911611a1c75444a56118a7b083768306e918e508ae073e943a` | Rosetta execution and real SS/VLESS/WireGuard config checks passed |

Metadata files:

- `leikwan-bridge-arm64-metadata.json` SHA-256 `7f5e1d89986fe0410505e749c99f1a1107281e6bd15dc0d7c39688a071c5b0ea`.
- `leikwan-bridge-x64-metadata.json` SHA-256 `c3888ca3f8ae669f93097174b3dc5931421ab03de7e78229b79e44581a03607d`.

## Commits

1. `ea2ee36` - strict Mac mode allowlist and fixed unsupported notice
2. `841005b` - scrubbed Mac Ultra policy-v2 panel contract
3. `7939048` - memory-only Ultra runtime hardening
4. `37ddec9` - Unix process and Ultra runtime hosts
5. `3ca99c8` - Mac paths, identity, Keychain, autostart, and port services
6. `e29d377` - shared desktop lifecycle and Windows parity
7. `888c4bb` - Avalonia desktop shell
8. `d9a006c` - verified automatic/manual update flow
9. `6611d5d` - panel artifact routes and tenant download surface
10. `e02d619` - dual-architecture native packaging
11. `f47430e` - native CI, screenshots, lock files, and Ultra canary leak gate
12. `57c1ee6` - portable native build gates
13. `5da06a8` - pinned peeled wstunnel commit
14. `6c6b9b1` - locked dual-RID restore
15. `a048ea8` - package the UOT client binary
16. `ac87181` - short Ultra fixture socket path
17. `f7f9752` - client-id + token login and native proxy connections
18. `46f0fde` - absolute native output paths for reproducible packaging
19. `f425a4f` - real sing-box config validation tests
20. `a27f56d` - migrate sniff to current route action
21. `1e65221` - gVisor-enabled sing-box and modern DNS server formats
22. `6d16344` - connection status styling and selector-based node editor handoff

## Verification evidence

Passed on 2026-08-27 for the current revision:

- .NET Core Release tests: 181 passed, 0 failed.
- macOS Release tests: 44 passed, 0 failed.
- Real arm64 and x86_64 `sing-box check` passed for Shadowsocks, VLESS, and
  WireGuard profiles; both helpers include `with_gvisor` for WireGuard.
- Both architecture builds produced six correctly typed Mach-O helpers,
  self-contained app bundles, DMGs, and update ZIPs.
- Go Ultra tests: passed with Go 1.27.
- Locked Rust tests: 176 passed, 0 failed.
- Focused PHP contracts, packaging fixture, and WSL Ultra fixture: passed.
- Native arm64 build, bundle/DMG/update ZIP architecture checks, DMG mount,
  and arm64 dynamic Ultra canary leak gate: passed.
- Native x86_64 build, bundle/DMG/update ZIP architecture checks, DMG mount,
  Rosetta execution, and x86_64 dynamic Ultra canary leak gate: passed.
- The dynamic canary checked argv/environment, runtime state, logs, update
  cache, update ZIP, mounted DMG, crash reports, and post-stop cleanup.
- Local metadata hash/size checks passed for all four packages; the four
  entries are present in `artifacts/versions.json`.
- Changed-file secret scan and workflow/shell syntax validation passed.

Not completed on this host:

- The native screenshot helper could launch the app through LaunchServices,
  but the SSH-launched capture process could not see the GUI window. macOS
  Screen Recording/GUI-session permission is required for the eight screenshot
  files in CI; no screenshot is represented as passed here.
- GitHub Actions execution and `actionlint` remain CI-side checks.

## Publication and rollback

The panel already defines these artifact routes:

- `/artifacts/macos/leikwan-bridge-arm64.dmg`
- `/artifacts/macos/leikwan-bridge-x64.dmg`
- `/artifacts/macos/leikwan-bridge-update-arm64.zip`
- `/artifacts/macos/leikwan-bridge-update-x64.zip`

Before publication, create an application backup excluding `.env`, a validated
MySQL backup, and a release archive. Preserve server-only `.env` and state,
require exact remote SHA/size equality, run migration status checks, then run
HTTP canaries for the manifest and all four artifact routes. Roll back by
restoring the recorded application backup and retaining the prior artifact
files if any canary fails.
