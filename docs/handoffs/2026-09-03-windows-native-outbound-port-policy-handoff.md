# Windows Native Outbound Port Policy Handoff

Date: 2026-09-03
Scope: Windows client, Panel contract, and the shared Ultra relay
Status: implemented and locally verified; not deployed

## Source and artifact identity

- Baseline: `master` was used as the source of truth. The worktree contained
  unrelated dirty files; they were preserved and were not included in this
  feature.
- Feature head: `9dcbe35008047f78487e7e7e434e48b0aa943bd3`
- Windows GUI version built: `0.1.20.4`
- Release GUI output: `LeikwanBridge.Desktop/src/LeikwanBridge.App/bin/Release/net8.0-windows/win-x64/`
- Windows GUI entry point: `LeikwanBridge.Desktop/src/LeikwanBridge.App/bin/Release/net8.0-windows/win-x64/LeikwanBridge.App.exe`
- GUI `LeikwanBridge.App.exe` SHA-256:
  `2AFDAC5EFE64033045448DBE8230FE0A7D17088C647D0C63ECE37E340E1B38E7`
- GUI `LeikwanBridge.App.dll` SHA-256:
  `0D1A1C16E2FE9FD23308C29EE382897569758629C25A2DE3D1F9F1AD6B09EE57`
- Run the EXE with the complete containing directory; do not copy only the
  entry point.
- Windows amd64 relay test artifact:
  `artifacts/ultra/ultra-client_windows_amd64_native-policy-20260903.exe`
- Relay size: `6,867,968` bytes
- Relay SHA-256:
  `22522C6A91E5441E246AF1C4A7E00887F498F87C1C95B2CF99A90FF378074A98`
- Relay build: `GOOS=windows GOARCH=amd64 CGO_ENABLED=0`,
  `-trimpath -s -w -buildid=`. Two direct builds produced the same hash.
- `garble v0.17.0` was attempted but Windows Defender blocked its temporary
  executable as malware/PUA. Do not describe the checked artifact as garbled;
  it is stripped and symbol-reduced. A clean build host may run the existing
  garble release process later.

No server upload, production deploy, installer replacement, or update-push
operation was performed in this handoff.

## Product contract

### Plan entitlements

The existing plan flag remains the first gate:

- `allow_native_outbound`: native capability is visible only when true.
- `allow_native_outbound_unrestricted`: defaults to false and is valid only
  when `allow_native_outbound` is also true. User-level flags cannot elevate
  this plan-only permission.

The unrestricted label in the Panel is `Unrestricted native full route / 无审全路由`.

### Forward persistence

The `forwards` table now has:

- `native_outbound_enabled TINYINT(1) NOT NULL DEFAULT 0`
- `native_direction_key VARCHAR(64) NULL`

`native_direction_key` is a normalized direction key derived from the selected
  Ultra forward combination. A unique service/direction index plus a
  transaction-level check allow only one active native forward per direction.
The add/edit API accepts `native_outbound`; the server remains authoritative:

- Native rows require Ultra and `tcp+udp`.
- Caller-supplied target host/port are ignored/rejected for native rows.
- The server assigns `127.0.0.1:<random-port>` (collision checked in
  `20000-60000`).
- Pause/delete release the direction key.
- Balance suspension also releases the key; balance reactivation reacquires it
  or leaves the conflicting forward paused.

The capability is omitted when no eligible native carrier exists. Eligible
carrier entries include `unrestricted_allowed`; the top-level value reflects
the selected carrier only. Provider hostnames, credentials, and the upstream
provider identity are not published in capability or static client config.

## Lease and wire contract

Native lease requests are POSTed to `/api/client/ultra/lease` and contain one
forward entry with:

```json
{
  "purpose": "native_outbound",
  "forward_id": 158,
  "session_ticket": "...",
  "policy_generation": "<64-char-lower-hex>",
  "nonce": "<lower-hex>",
  "target_digest": "<digest>",
  "transport": "tcp+udp",
  "route_scope": "cn_only | intl_only | global",
  "route_policy": "restricted | unrestricted",
  "selection_mode": "single",
  "max_candidates": 1
}
```

The native protocol is `leikwan-ultra-native-socks5-v1`. A response echoes
`route_scope` and `route_policy` and carries `scope_digest`, endpoint,
credentials, lease expiry, quota, and selection metadata.

Digest compatibility:

- Restricted legacy clients may use the existing v1 digest.
- Unrestricted requests must use the v2 digest, whose canonical input adds
  the normalized route policy (`native-outbound:v2`).
- The Panel and relay reject an unrestricted claim with the old digest.
- Missing `route_policy` is interpreted as restricted for old clients.

## Windows behavior

The HTTP, Process, and TUN modes all use the same loopback native relay and
the same route policy. The GUI native card is visible only for an eligible
capability. The unrestricted checkbox is hidden unless the selected carrier
has `unrestricted_allowed=true`, and it is off by default.

### Restricted (default)

- TCP and UDP destination ports `1-999` are sent to `direct`.
- TCP and UDP destination ports `1000-65535` are eligible for native routing.
- `cn_only`: China `geoip-cn`/`geosite-cn` high ports use native proxy;
  everything else follows the direct final route.
- `intl_only`: China high ports are direct; the final route is native proxy.
- `global`: high ports use native proxy unless the client explicitly enables
  the existing global China bypass option.
- A `resolve` rule runs before scoped native routing. Remote rule-set files are
  downloaded through `direct` for `cn_only` and through the native proxy for
  `intl_only`, avoiding the previous polluted/slow bootstrap path.

The relay repeats the `1000` minimum before dialing or creating a UDP
association. This is defense in depth if a client sends an invalid route JSON.

### Unrestricted (explicit opt-in)

- All destination ports `1-65535` use the selected route scope.
- The low-port direct rule is omitted.
- The OpenGFW inspector is disabled (`native_filter_profile=off`).

### Integrated OpenGFW filtering

Restricted TCP streams are inspected inside the existing `ultra-client.exe`
process using only platform-neutral OpenGFW analyzers:

- `FETAnalyzer` and `TrojanAnalyzer` are fed incrementally per connection.
- Only a confirmed `yes` result closes the stream with
  `native_opengfw_blocked`.
- `maybe`, short samples, unsupported direction, analyzer exhaustion, panic,
  or malformed analyzer output fail open.
- UDP is never protocol-inspected, preserving normal game UDP traffic.
- There is no WinDivert/WFP driver and no second filter daemon.

The analyzer is intentionally conservative. A TCP game flow that happens to
match one of these experimental signatures remains a residual false-positive
risk; this is documented and covered by fixtures rather than claimed away.

## Android and macOS integration checklist

The Panel and relay contract is platform-neutral. Android/macOS clients should
implement the following without inventing a second policy vocabulary:

1. Parse `native_outbound` capability only when `enabled=true`; render no
   native option otherwise.
2. Use the carrier's `route_scopes`, `default_route_scope`, `direction`, and
   `unrestricted_allowed`. `native_direction` on a forward is the server's
   normalized key; use the carrier `direction` string for display.
3. Default `route_policy` to `restricted`. Send `unrestricted` only after an
   explicit user action and only when the carrier allows it.
4. Compute v1 digest for restricted compatibility and v2 digest for
   unrestricted. Validate the response policy and digest before starting the
   relay.
5. Pass these fields into the integrated relay config:
   `native_socks5=true`, `native_route_policy`, `native_port_min=1000`, and
   `native_filter_profile` (`opengfw_tcp` or `off`).
6. Apply the same route semantics to SOCKS and TUN. In restricted mode add a
   TCP+UDP `1:999 -> direct` rule before the scope rule; scope high ports are
   `1000:65535`. In unrestricted mode omit that gate.
7. Keep provider endpoint and credentials in the short-lived lease/relay
   memory only. Never put them in capability, persistent profile JSON, or UI
   telemetry.
8. Keep one active native forward per service/direction and surface
   `native_direction_conflict` as a recoverable UI error.

The shared Go source is under `tools/ultra-client`. Android x86_64 and ARM64
builds should use the existing `scripts/build-ultra-android.ps1` pipeline after
their client-side wiring is complete; this Windows-only handoff does not claim
new Android/macOS binaries.

## Verification evidence

All commands below were run from the repository root unless noted.

- Go: `gofmt -l .` produced no file names; `go test ./...` passed; `go vet ./...`
  passed.
- PHP contract/mutation/lease tests passed:
  `native_outbound_policy_contract_test.php`,
  `native_outbound_forward_mutation_test.php`,
  `native_outbound_plan_entitlement_test.php`,
  `forward_line_api_contract_test.php`, `forward_line_api_mutation_test.php`,
  `native_outbound_pull_visibility_test.php`,
  `native_outbound_lease_contract_test.php`,
  `ultra_policy_lease_contract_test.php`,
  `android_ultra_policy_v2_contract_test.php`,
  `forward_reactivation_behavior_test.php`, and
  `forward_reactivation_security_regression_test.php`.
- .NET Core: `278` tests passed, including the explicit unrestricted lease
  request/response and v2 digest contract.
- .NET GUI: `48` tests passed.
- sing-box-backed config tests: `29` `WindowsProxyConfigBuilderTests` passed
  with `artifacts/windows/proxy-runtime/sing-box.exe` version `1.13.19`,
  including restricted/unrestricted `cn_only` and `intl_only` TUN checks.
- Release GUI build: `dotnet build ... -c Release -r win-x64 --no-restore`
  completed with `0` warnings and `0` errors.

PHP CLI emitted the pre-existing `Invalid date.timezone value 'UTC+8'` warning;
the assertions still passed. One Core dynamic-port test was transiently
affected by a concurrent test process, then passed when rerun alone and in the
final full run.

## Operations and rollback

Apply `master/migrations/20260903_add_native_outbound_policy.sql` before using
the new Panel fields. The migration is additive. To roll back behavior, set
both native plan flags false, stop issuing native leases, and run the previous
relay/client binaries; the nullable columns may remain for forward-compatible
rollback. Do not delete the columns while an updated Panel binary is running.

The feature commits are grouped by layer in the `master` history after
`a163c2b`: Panel contract/persistence, lease authorization, integrated relay,
Windows route policy, GUI entitlement, and the final balance-suspension fix.
