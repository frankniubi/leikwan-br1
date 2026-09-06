# macOS 1.0.13 data-plane candidate

## Status

Installed on test Mac `100.103.253.21` as `/Applications/LeiKwan Bridge.app`.
The running GUI was verified as PID 18175, with five ordinary Ultra children.
This is a test candidate, not a production release. The public manifest and
Windows/Android/Linux/PHP payloads were not changed by this fix.

## Confirmed findings

- Old logs reported native `Vpn` and `Proxy` started while sing-box was absent.
  Native relay control and lease delivery were successful and its listener survived.
- The installed sing-box reproducibly rejects `interface_name: "LeiKwan Bridge"`
  with `configure tun interface: bad tun name: LeiKwan Bridge`.
  The bare `utun` prefix also fails. Omitting the field delegates free `utunN`
  allocation to sing-box; this passed name validation and reached the expected
  unprivileged `Connect: operation not permitted` result.
- MacConnectionProcessHost previously returned after 350ms of process survival,
  including time spent waiting for administrator authorization. The service then
  deleted the config and published Connected, without monitoring later exit.
- Ethernet HTTP/HTTPS/SOCKS settings were still enabled for an absent
  `127.0.0.1:2080` listener. Safari logs showed connection refused to that address.
- Earlier attribution to AMFI/ad-hoc signing was premature. The installed signed
  helper executes successfully, including two isolated Proxy startups. Removing
  its signature instead caused SIGKILL. No signature stripping, Gatekeeper change
  or security bypass was applied to the installed app.

## Changes

- Omit the TUN interface name so sing-box allocates a valid available `utunN`.
- Proxy startup waits for its configured loopback listener. TUN startup waits
  for a newly appearing utun interface with the configured tunnel address.
  Configuration remains available during this wait; startup has a bounded timeout.
- Monitor process exit and publish an Error snapshot, stop the native relay,
  and wipe lease material. Event handling is scoped to the active config identity.
- Privileged child lifetime is controlled by a private marker and parent liveness;
  stop requests SIGTERM before bounded escalation instead of leaving a root child.
- Bound stderr capture, classify errors without logging raw endpoints/secrets,
  and preserve these error codes in the UI instead of overwriting them with a
  generic native connection message.
- Stop gracefully and clear only enabled system proxies matching the owned
  loopback address and port. Other proxy endpoints are not disabled.

## Verification

- Mac tests: 85 passed, 0 failed, 0 skipped.
- Core tests: 294 passed, 0 failed, 0 skipped.
- Regression evidence includes failing old interface-name assertion, failing
  Connected-after-exit assertion, delayed readiness, timeout cleanup, intentional
  versus unexpected exit, and exact-match proxy cleanup.
- Real new-host Proxy test: ready in 162ms, config removed after readiness,
  still alive after 10 seconds, stopped cleanly with no remaining test listener.
- Through the new host and existing live native relay: Cloudflare and example.com
  each returned HTTP 200. Direct relay tests also returned HTTP 200 for GitHub.
  An earlier gstatic test timed out; no universal reachability claim is made.
- TUN host test returned `vpn_permission_required` after AppleScript authorization
  error -60007. No full privileged TUN or UDP/WebRTC acceptance is claimed.
- Both DMG architectures passed the existing mounted-layout and strict/deep
  codesign checks. Signatures remain ad-hoc, not Developer ID/notarized.
- The first GUI launch exited while the VM display was asleep, consistent with
  the existing RenderTimer issue. Waking the display and reopening succeeded.

## Install and recovery evidence

Old DLL SHA-256: `91dcd1e2a0c9c83caed1202d32b90c4b48377171b5bb0653d94ea9e9e98decbf`.
New installed DLL SHA-256: `d7c1a5a0f1dc7c2bbbcca698ac118fe678b7d0258a692121ba795eb8dfdd7c98`.
Installed version and DLL hash match local build outputs; bundle signature integrity verified.

Remote app backup:
`/Users/leikwan/leikwan-native-package-1.0.9/rollback-1.0.12-data-plane-20260906T171613.app`.

Remote proxy-settings backup:
`/Users/leikwan/leikwan-native-package-1.0.9/config-probe-20260906/system-proxy-before-20260906T171212`.
Only three stale Ethernet proxy entries matching localhost:2080 were disabled;
Tailscale settings were left unchanged. Final HTTP/HTTPS/SOCKS enable flags are 0.

Local evidence: `.tmp/macos-data-plane-20260906/final-evidence.log`,
`install-1.0.13.log`, `host-proxy-http.log`, `metadata-{arm64,x64}.json`, and
`test-results/{mac,core}-data-plane-final.trx`.

## Candidate artifacts

All files are in `artifacts/macos-data-plane-1.0.13/`, outside canonical production paths.

| File | Bytes | SHA-256 |
| --- | ---: | --- |
| leikwan-bridge-arm64.dmg | 76869416 | c5a997e8d5722a33c2f0ed87117eb4c725359b80cf1116d4f88998c23411813f |
| leikwan-bridge-update-arm64.zip | 68273431 | 6ee2ca681d506624559d2dec202499a9041e0fa169101cecba5def4545a032f9 |
| leikwan-bridge-x64.dmg | 82081801 | 57450851a69c4b311ff1d0748423303150b7eb8fc09be7ddda98ae162b9d3c91 |
| leikwan-bridge-update-x64.zip | 72550019 | a7735555d7403b39b21d897c5d65868c881dab377e35afe0832951b13e311f99 |

## Remaining acceptance

Complete administrator authorization from the interactive Mac desktop, verify
the resulting utun interface and actual native TCP/UDP traffic, then verify stop
removes the root helper/interface and leaves no stale system proxy. Full routing,
DNS, Twilio UDP, sleep/wake, and sustained native traffic are not yet accepted.
Do not publish this candidate merely on the basis of a Connected UI label.
