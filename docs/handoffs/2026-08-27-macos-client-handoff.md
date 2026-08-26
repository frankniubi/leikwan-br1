# macOS Client Handoff - 2026-08-27

## Delivery status

The macOS client implementation is complete on branch `codex/macos-client`,
based on `master` commit `56ad059b36addd2e92ac221ce9cfde51e40fe260`.
Source, tests, packaging, and native CI gates are committed locally.

Native delivery is not yet proven. This Windows workstation cannot create or
run a genuine macOS app bundle or DMG, the branch has not been pushed, and the
macOS GitHub Actions workflow has not run. Therefore no DMG is represented as
user-ready, and no artifact SHA-256 or byte size is recorded here.

No production panel deployment, artifact publication, branch push, migration,
or live infrastructure change was performed.

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

## Expected native artifacts

| Architecture | Installer | Update archive | Metadata | Status |
| --- | --- | --- | --- | --- |
| Apple Silicon | `leikwan-bridge-arm64.dmg` | `leikwan-bridge-update-arm64.zip` | `leikwan-bridge-arm64-metadata.json` | pending native CI |
| Intel | `leikwan-bridge-x64.dmg` | `leikwan-bridge-update-x64.zip` | `leikwan-bridge-x64-metadata.json` | pending native CI |

The workflow uploads eight real-window screenshots per architecture: login,
dashboard, forwards, and settings at `1440x900` and `1024x768`. A final job
rejects missing/empty artifacts, metadata mismatches, duplicate cross-arch
package/helper hashes, incomplete screenshots, and high-risk secret literals.

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

## Verification evidence

Passed locally on 2026-08-27:

- `dotnet test LeikwanBridge.Desktop/LeikwanBridge.Desktop.sln -c Release`:
  246 passed, 0 failed (`Core` 180, Windows `App` 33, Mac 33).
- `go test ./... -count=1` in `tools/ultra-client`: passed.
- `cargo test -p leikwan-bond -p leikwan-ingress -p leikwan-uot --locked`:
  176 passed, 0 failed. Existing compiler warnings remain non-fatal.
- Five focused PHP contracts: all returned `ok`. The workstation PHP emitted
  its existing invalid `date.timezone=UTC+8` startup warning.
- `scripts/tests/test-macos-client-packaging.sh`: passed under WSL.
- `scripts/test-macos-ultra-leaks.sh --fixture`: passed under WSL.
- Both architecture build dry-runs and shell syntax checks: passed.
- Workflow YAML parse and all 14 embedded shell blocks: passed local syntax
  validation.
- Changed-file secret scan: 123 changed paths scanned with no private-key,
  long token, bearer-token, or project credential-file match.
- Exact unsupported-mode notice was found in production policy and regression
  tests.

Not proven locally:

- native Mach-O compilation for either architecture;
- native Avalonia launch, tray, Keychain, LaunchAgent, or updater execution;
- DMG creation/mount, update ZIP launch, native UI screenshot inspection, and
  dynamic process/disk/crash canary scan;
- GitHub `actionlint` (the local terminal could not reach the Go module proxy);
- final artifact SHA-256, byte sizes, and user acceptance on real Mac hardware.

These are fail-closed gates in `.github/workflows/macos-client.yml`; both matrix
jobs and the cross-architecture verification job must pass before the DMGs are
described or published as user-ready.

## Next operation

Push `codex/macos-client` to a non-production remote branch and run the `macOS
client` workflow. Download both architecture artifacts, inspect the uploaded
screenshots, verify the combined metadata, then perform one Apple Silicon and
one Intel user smoke test. Publication requires a separate explicit action.

## Rollback

No live rollback is needed because nothing was deployed. To abandon the source
work, delete the local topic branch/worktree. If these commits are later merged,
revert the commits above in reverse order. No database migration or server-only
state needs restoration.
