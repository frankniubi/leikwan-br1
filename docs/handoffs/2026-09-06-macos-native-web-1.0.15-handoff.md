# macOS 1.0.15 native web and reconnect candidate

## Scope and findings

The user reported Google/X failing in unrestricted mode, followed by a generic
native-start failure when switching from TUN to HTTP. Test host is
`100.103.253.21`; installed baseline was 1.0.14.

- The GUI log confirms Vpn data-plane readiness at 17:52:27. After the user
  stopped it, no Proxy startup phase appeared; the old UI did not log lease-stage
  exceptions, so the exact exception for that click is not recoverable.
- `DesktopRuntimeSession` requested leases with revision-cached tickets. Ordinary
  logs already contained `409 lease_scope_invalid`. The native path now pulls a
  fresh policy and retries this exact 409 once without restarting ordinary routes.
  This addresses the stale-ticket defect, but does not retroactively prove the
  exception from the unlogged click.
- StopConnectionAsync reset the user's unrestricted checkbox. It now clears
  active-session ownership while retaining the selected policy. Entitlement
  revocation still resets unauthorized choices.
- Same live unrestricted relay: Google failed when handed the domain directly
  to relay/provider DNS. With sing-box remote DoH resolution before the SOCKS
  handoff it returned HTTP 200. X's complete redirect chain returned HTTP 200.
- Global native mode previously omitted the resolve action and selected the
  system resolver. Unrestricted global now uses the same remote resolution as
  intl_only; restricted-global routing behavior remains unchanged.
- Tests with a deliberately incorrect pre-resolved IP still failed even with
  normal sniff+resolve. This matters to TUN because the test Mac also has
  Tailscale and LAN DNS. A route `override_destination` experiment was rejected
  by the pinned runtime and was NOT included.

## TUN DNS candidate

The privileged lifetime wrapper now registers a unique temporary macOS resolver
through a persistent scutil session once the TUN address exists. It points default
DNS to `172.19.0.2`, where the existing TUN DNS hijack consumes it. The key is
session-temporary and disappears when the scutil owner exits; network service and
Tailscale DNS preferences are not overwritten. Readiness requires its dictionary.

The local DNS transport inside TUN now uses explicit direct UDP to `223.5.5.5`
to avoid recursing into the system resolver just redirected to the tunnel.
Native DNS uses ipv4_only, consistent with the current native relay IPv4 path.
Cache flushes are requested on resolver setup/teardown.

**This privileged DNS setup is not fully accepted yet.** The SSH TUN test returned
`vpn_permission_required` before resolver registration. Dictionary syntax,
flushing of redirected output before process exit, and unit contracts were
verified. Administrator creation, resolver priority against Tailscale, actual TUN
traffic, and automatic removal require an interactive Mac test. Do not publish
this candidate as a fully validated TUN release.

## Verification

- Mac: 102 passed, 0 failed, 0 skipped.
- Core: 296 passed, 0 failed, 0 skipped.
- Red/green tests cover global remote resolution, retaining unrestricted across
  stop/start, fresh native tickets, one bounded 409 refresh, numeric resolver
  commands, no command injection, and explicit TUN DNS recursion prevention.
- Real MacConnectionService HTTP mode, two consecutive starts and stops:
  first startup 440ms; second 317ms (lease acquisition excluded).
  Google HTTP 200 twice (84429 / 84543 bytes); X followed redirects and returned
  HTTP 200 twice (32673 bytes). Both stops returned Disconnected.
- No claim that a curl HTTP 200 establishes every browser resource, UDP/WebRTC,
  all lease candidates, or sustained availability.
- Both architecture packages passed existing layout and strict/deep codesign
  checks. They remain ad-hoc signed, not notarized.

## Installed test version

Version 1.0.15 installed and running as GUI PID 25438.
Backup: `/Users/leikwan/leikwan-native-package-1.0.9/rollback-1.0.14-web-20260906T182851.app`.

| Assembly | SHA-256 |
| --- | --- |
| Previous Mac DLL | d285b2e9c174caaac7af8b5e95184509ba3f31be717716cb150b491b85c20dea |
| Installed Mac DLL | 611b7d0833ce0f451439f1d09bed478a6641ac2dfbbc9d2edd26034410301773 |
| Installed Core DLL | bb72e8a4d4a46ef9ae3fac524b7dfa4ea858a6b3d128b011e423e2d695bd5955 |

Final state: HTTP/HTTPS/SOCKS system proxies disabled, no LeiKwan temporary DNS
keys and no WebProbe/MacProbe/osascript processes left running. Ordinary Ultra
listeners were ready. Final installed hashes matched local publish outputs.

## Artifacts and evidence

Candidate files in `artifacts/macos-native-web-1.0.15/`:

| File | Bytes | SHA-256 |
| --- | ---: | --- |
| leikwan-bridge-arm64.dmg | 76752149 | 90ff44c9df58ebe7b4ade6c1929239cd3270beda7d8a41e74a8d50d38408fd34 |
| leikwan-bridge-update-arm64.zip | 68381756 | 9226cd30c03d9d618f7144ff4b466671a19aade3c986d6b3ea016b048e51e4ca |
| leikwan-bridge-x64.dmg | 81434373 | 2678a1a8ffcba538bb783f004a12c871744f0dc18aa19acaf93f89d39bd2257e |
| leikwan-bridge-update-x64.zip | 72658346 | e937d5cdffd6e72f124df635737ce24870eb2cb7dd63a38e5999a3300bab6c31 |

Evidence under `.tmp/macos-web-20260906/`: `live-web-probe.log`,
`live-web-resolve.log`, `live-web-ip-override.log`, `http-reconnect-proof.log`,
`tun-dns-proof.log`, `install-1.0.15.log`, `final-state.log`, and TRX under `tests/`.
Initial verbose curl logs contain public website cookies and must not be published
without redaction. Later probe logging redacts Set-Cookie responses.

Production manifest unchanged (SHA-256
`8374f63ca32ad7b50d9d600c7903f6cda9e9c53f632b99277f443ed8c08da70f`).
No production deployment and no Windows/Android/PHP/Linux payload changes.
The shared DesktopRuntimeSession change is used by Mac bootstrap; no Windows
runtime selection/config code was changed. Unrelated dirty files were left alone.
