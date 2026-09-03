# Windows Native Outbound Port Policy and OpenGFW Design

Date: 2026-09-03
Status: approved direction, implementation pending

## Purpose

Extend the Windows native outbound path without creating a second filtering
daemon or a platform-specific packet driver. The existing sing-box/TUN and
local SOCKS5 paths must share one policy contract and one `ultra-client.exe`
relay data plane.

## Constraints

- The current `master` branch is the baseline. Existing dirty worktree files
  are unrelated and must remain untouched.
- The panel must keep native outbound invisible unless the assigned plan
  enables it.
- Native outbound is an Ultra forward backed by a panel-issued short-lived
  lease. Provider identity remains server-side.
- Default native policy proxies destination ports `1000-65535` for both TCP
  and UDP. Ports `0-999` are direct and are never sent through native relay.
- A plan may additionally enable `allow_native_outbound_unrestricted` (UI:
  `No-review full route` / `无审全路由`). This remains false by default and
  requires an explicit client opt-in. When enabled and opted in, all ports
  are proxied and OpenGFW filtering is disabled.
- The normal native policy keeps OpenGFW FET/Trojan filtering enabled. Only
  high-confidence TCP analyzer positives are rejected. Analyzer errors and
  inconclusive results fail open. UDP is never protocol-inspected, preserving
  normal UDP game traffic.
- The panel native-forward form exposes a native-outbound selector. When it
  is selected, target host and target port are read-only/gray and the server
  assigns `127.0.0.1:<generated-port>`. Only one active native forward is
  allowed for a canonical direction.
- Release binaries remain one integrated `ultra-client.exe` and continue the
  existing stripped/garbled release process. This provides modest reverse-
  engineering resistance but is not a security boundary.

## Alternatives considered

1. **Integrated relay inspection (selected).** Import only OpenGFW analyzer
   packages into `tools/ultra-client`, feed TCP bytes from the existing relay,
   and carry the filter/port policy in the authenticated lease. This covers
   both SOCKS and TUN because both terminate at the same local SOCKS endpoint,
   adds no driver, and keeps operational ownership in one binary.
2. **WinDivert/WFP packet filter.** Provides packet visibility below SOCKS,
   but requires a separate driver/process, installer signing and lifecycle,
   and a second policy boundary. It conflicts with the integrated-data-plane
   requirement and increases failure modes.
3. **Linux-only OpenGFW.** Reuses the existing NFQUEUE implementation but
   cannot enforce the Windows requirement and leaves native Windows traffic
   unfiltered. Rejected.

## Data model and wire contract

### Plan entitlement

Add `allow_native_outbound_unrestricted` to the existing plan feature-flag
map. It is false in `defaultFeatureFlags()`. User-level feature flags cannot
elevate it. The existing `allow_native_outbound` remains the prerequisite.

### Forward fields

Add nullable forward fields:

- `native_outbound_enabled` (boolean, default false)
- `native_direction_key` (canonical direction string, nullable)

The API accepts `native_outbound` on create/update. The server derives the
direction from the selected Ultra combination, verifies plan entitlement and
`tcp+udp`, and ignores/rejects caller-supplied target values for native rows.
The generated target port is collision-checked in a bounded high port range.
On disable/delete, the direction key is cleared so a replacement can be
created; the transaction and a unique active-direction check prevent races.

### Capability

The existing `native_outbound` capability remains absent when no carrier is
eligible. Eligible carrier entries gain `unrestricted_allowed`; the selected
carrier determines the top-level `unrestricted_allowed` value. Older clients
ignore the optional field.

### Lease

Native lease requests carry `route_policy` (`restricted` or `unrestricted`).
The panel accepts `unrestricted` only when both plan flags are true and the
client explicitly requests it. The response echoes the policy and the relay
validates it. Include the policy in the native scope digest (new digest
version, with an explicit compatibility rejection for old unrestricted
claims) so a client cannot downgrade/upgrade a lease by editing route JSON.

## Runtime flow

1. Windows client pulls capability and forward metadata. The native option is
   rendered only when capability is present; unrestricted checkbox is hidden
   unless the selected carrier permits it and defaults off.
2. Client requests a native lease for the selected forward, scope and policy.
   The panel re-reads assignment, plan flags and direction before issuing it.
3. `WindowsProxyConnectionService` starts the existing loopback
   `NativeOutboundRelayHost` and passes the policy in its JSON config. It then
   builds sing-box config pointing the SOCKS outbound at that loopback port.
4. `WindowsProxyConfigBuilder` emits a route rule for `port_range 1:999` to
   `direct` before scope rules when policy is restricted. Scope rules for
   `cn_only`, `intl_only` and `global` apply to `1000:65535` TCP/UDP. DNS
   hijack and resolver rules remain before the port gate as required by the
   existing split-DNS design.
5. The relay enforces the same destination-port gate for defense in depth.
   Restricted TCP streams pass bytes through an embedded inspector that uses
   OpenGFW `FETAnalyzer` and `TrojanAnalyzer`. A confirmed positive closes the
   stream with a policy error; `maybe`, unsupported direction, short samples,
   and analyzer errors continue normally. UDP datagrams skip analyzers.
6. Unrestricted policy omits the low-port direct rule and inspector entirely;
   all ports use the selected route scope and native relay.

## OpenGFW integration details

Only platform-neutral analyzer packages are imported. The Linux NFQUEUE/iptables
packages are not linked into the Windows build. Analyzer state is per TCP
connection and bounded by each analyzer's `Limit()`. Inspection is performed
incrementally on bytes in both directions before forwarding a bounded prefix;
after the analyzers become negative or exhausted, the stream is pass-through.
No generic TLS rule is added. This intentionally prioritizes game continuity;
TCP game traffic that happens to match the experimental analyzers remains a
known residual risk and is covered by fixture tests.

## GUI behavior

- Native outbound stays invisible for non-entitled plans.
- The native selector appears in the panel add-rule form only when the plan
  allows native outbound.
- Selecting it disables target host/port inputs, displays the generated
  loopback target, and shows the direction conflict if one is already active.
- The Windows client displays the existing native card. The unrestricted
  checkbox is entitlement-gated, off by default, and its state is persisted
  only locally for the selected forward.

## Compatibility and failure behavior

- Missing optional fields preserve old clients and ordinary forwards.
- Restricted is the default whenever the lease omits `route_policy`.
- A stale or unauthorized unrestricted request fails closed at lease issuance;
  the client falls back to restricted only after a fresh authorized lease, not
  by mutating an existing lease.
- Provider endpoint and credentials never enter the client capability or
  static config. They remain in the one-shot lease and relay memory.
- Rollback is removing the new optional fields/route rules and reverting the
  binary to the prior release; no destructive data migration is required.

## Acceptance tests

- PHP: default flags, plan-only entitlement, native selector normalization,
  generated target, one-direction uniqueness, lease policy/digest and privacy.
- .NET: restricted and unrestricted JSON for HTTP, Process and TUN; route
  ordering; `1:999` direct and `1000:65535` native; entitlement-gated GUI;
  relay policy propagation.
- Go: policy parsing/defaults, port gate, FET/Trojan positive blocks,
  inconclusive/error fail-open, ordinary TLS/HTTP pass, UDP bypasses analyzers,
  and representative game fixtures pass.
- Run sing-box config checks against generated restricted and unrestricted TUN
  configurations, then run the full Go/.NET/PHP test suites relevant to the
  touched modules.

## Known limitation

No SOCKS-level implementation can identify every TCP game protocol perfectly.
The conservative analyzer threshold and untouched UDP path minimize impact;
the acceptance report must call out any TCP false-positive fixture rather than
claiming universal game compatibility.
