# Linux Ultra HA Release 2026.09.10.1

## Scope

- Runtime fix commit: `d4e87ba39eb281952f139f460fa97fbda1a2a425`.
- Installer manifest correction: `57da0ee8`.
- Linux agent source and artifact mirror SHA-256:
  `934cacad24ec7e6518814d2a0514c6fe40bf5e669577d80cbb3d344dd7020675`
  (316962 bytes).
- No PHP/API issuance, authentication, schema or lease payload changes.
- Windows, macOS, Android payloads and manifest entries were preserved.
- Unrelated dirty files were excluded. No force push or GitHub history merge.

## Root Causes And Fixes

1. The existing lease API accepts four forwards per request. Linux sent five,
   losing forward 180, then deleted its local runtime as invalid. Requests now
   use batches of four. Failed/partial batches cannot discard successful ones.
2. Material rotation changes policy generation. Linux hashed that generation
   as static configuration and restarted healthy services. Generation-only
   changes now hot-refresh over the existing control socket. Legacy signatures
   migrate only when on-disk service/configuration contents and POSIX file modes
   match. Static target/port/digest changes and explicit restarts remain effective.
3. Health checks tried to restart absent or stale non-Ultra units. Restart checks
   now require the correct owned Ultra service and existing managed files.
4. The test host had an old updater without `--artifact-base`. The panel also
   served old installers; two local manifest hashes used CRLF rather than Git's
   LF payloads. Committed LF installers and matching metadata are now published.
5. Client 3's authoritative panel configuration omitted the CDN fallback, removing
   the local fallback on every pull. Only that client's `panel_mirror_urls` was
   updated, after a row backup. Cloudflare remains primary, Aliyun is secondary.

Transient lease failures retain existing processes, not indefinite authorization:
the Go runtime already enforces actual policy and lease expiration. No arbitrary
180-second disconnection threshold was added.

## Verification

- Windows-host Python client suite: 427 passed, 19 subtests passed.
- WSL focused Ultra tests including POSIX migration permissions: 18 passed.
- WSL guarded deployment tool: 13 passed.
- Four PHP Linux Ultra/artifact contract tests: passed.
- Source and mirrored agent bytes, version, size and SHA-256 match.
- Go suite could not start because the checkout lacks its `../../opengfw`
  replacement dependency. No Go source or binary was changed by this release.
- Both public CDNs returned the new manifest, agent, updater and installer:
  HTTP 200, exact SHA-256 and byte counts verified.
- Authenticated config pulls Cloudflare -> Aliyun -> Cloudflare returned the same
  five forwards in 2.05s, 1.66s and 1.81s.
- Linux update state reports success and installed version 2026.09.10.1.
- Existing forwards 158/159/170/171 retained their pre-upgrade PIDs. Forward 180
  recovered and exposes TCP and UDP on port 54544.
- Steady-state canary from 04:56:04 to 05:01:10 +08:00 (308.1 seconds including
  API checks): 11 samples, all five forwards ready in every sample, all ten
  TCP/UDP listeners present, agent active, all five PIDs unchanged. Panel
  generations for 159/170/171 changed during the window, while runtime logs
  recorded repeated four-candidate lease selection without service restarts.

## Backup And Evidence

- Panel runtime release:
  `/root/codex-backups/linux-ultra-ha-20260910-934cacad`.
- Panel installer release and client-3 config backup:
  `/root/codex-backups/linux-ha-installer-sync-20260910`.
- Linux test host agent/helper/config backup:
  `/root/codex-backups/linux-ultra-ha-20260910-934cacad`.
- Panel artifact transaction completed at 2026-09-10 04:46:44 +08:00 without
  restarting panel services.
- Linux agent/helper replacement occurred at 04:53:10 +08:00. The recovered
  updater then refreshed installer-managed files and restarted only the agent;
  existing Ultra services remained running.
- Credential-free committed source archive SHA-256:
  `ed78b48eb21f63fff295e1da4fec9c4913ddaeefcee2e8bd5cf38a0c600a3acc`.
- Local working evidence: `.tmp/linux-ha-release-20260910/`.
- Panel postflight confirmed exactly three manifest entries changed:
  `client_agent`, `client_agent_installer`, `client_agent_update`; inspected
  API/controller hashes remained unchanged. Temporary stage archived then removed.

This is a bounded service/listener/control-plane canary, not a guarantee of
zero packet loss under every provider failure or proof of long-lived customer
application sessions. Unrelated CT GRE IPv6 bind failures were not modified.
