# macOS Client Handoff - 2026-08-27

## Delivery status

The macOS client implementation and native packages are complete on branch
`codex/macos-client`. The source is based on `master` commit
`56ad059b36addd2e92ac221ce9cfde51e40fe260`; the final source/package scripts
are at commit `ac87181ca7689746c0bd352d53ba6c9428d1aef6`.

Apple Silicon and Intel packages were built on a real macOS 26.6.2 arm64 host.
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
architecture-aware application updates.

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
| Apple Silicon | `leikwan-bridge-arm64.dmg` | 64,828,949 / `b031a06d9b8d2d9fc66abf563eea452bd4079a9a96a6e195a001d4804bb88946` | `leikwan-bridge-update-arm64.zip` | 56,662,846 / `8c5307ec8d2dc93a9210a5a77abafc8dd530976bf2cbf86d3ff8048f213c1f04` | native build and leak gate passed |
| Intel | `leikwan-bridge-x64.dmg` | 67,419,754 / `7a65557d768c1410af589d827a95cbadc4df9ff3e481e89c4cf7ff877a898be5` | `leikwan-bridge-update-x64.zip` | 59,262,696 / `10e5e9d6ca1019338f8b6ef24d9dfeb7bf46eca33415feeb3465febece304cd9` | Rosetta native execution and leak gate passed |

Metadata files:

- `leikwan-bridge-arm64-metadata.json` SHA-256 `7861c588cd7417bf4fb8ad199f55a25af0eb566f45d6404442650aa667ba01d3`, 2,092 bytes.
- `leikwan-bridge-x64-metadata.json` SHA-256 `2c69b946df9ce4090babf01fad7cfbae38135fa4417262bfe63ba289e947f571`, 2,085 bytes.

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

## Verification evidence

Passed on 2026-08-27:

- .NET solution Release tests: 246 passed, 0 failed (`Core` 180, Windows App
  33, Mac 33).
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
