# LeiKwan Host Android vNext Design

## Goal

Upgrade `leikwan-android` into the current LeiKwan Host Android client. The
client authenticates with the Panel-issued `client_id + agent_token` pair,
supports only the allowed traffic families, receives authoritative device
control commands, and protects Panel-delivered server addresses from untrusted
devices.

The implementation covers the Android application and the Panel contract it
depends on. It does not change the required topology: `client -> slave ->
sidecar` remains intact for ordinary LeiGod forwarding, and Android must never
connect directly to a sidecar.

## Scope

### Included

- Replace Android email/password login with Windows-parity `Client ID + Agent
  Token` enrollment.
- Stop storing a user password or a legacy desktop-session token on Android.
- Report a stable Android device identity, name, platform, client version,
  Android release, and server-derived source IP to the Panel.
- Implement persistent Panel device states: `active`, `kicked`, and `banned`.
- Process a Panel kick or ban while the VPN is running by stopping the VPN and
  every owned runtime, wiping local credentials, and returning to enrollment.
- Enforce trusted-device enrollment with local root/emulator rejection plus
  server-verified Android Keystore attestation before configuration or Ultra
  material is released.
- Permit only `backend_mode=current_mode` with `client_mode=single` or
  `client_mode=bond`, and the dedicated `backend_mode=ultra` family. Reject
  `cf_mode`, `leigod_mode`, and every other mode.
- Add Android Ultra policy-v2 parsing, one-shot lease retrieval, Android
  `ultra-client` runtime packaging, and private Unix-socket lease injection.
- Support direct Shadowsocks, VLESS, WireGuard, and SOCKS5 proxy nodes.
- Compile all runtime, upstream-proxy, WireGuard-peer, and Ultra lease
  endpoint/CIDR reachability into non-overridable `direct` rules.
- Replace choice-like free-text UI with selectors and remove endpoint details
  from the normal tunnel UI.

### Explicitly excluded

- Native Android browser OIDC, device authorization flow, or password login.
  OIDC remains the Panel Web login; the authenticated Panel account exposes the
  client credentials used by this app, exactly as Windows does.
- Legacy `cf_mode`, `race`, `mptcp`, or any fallback rendering of unsupported
  modes.
- A client-visible Ultra endpoint, provider credential, policy generation, or
  lease diagnostics screen.
- Direct client-to-sidecar connectivity, sidecar topology changes, or new
  public listener ranges.

## Product Decisions

1. **Unsupported means absent.** A raw forward is parsed only to determine
   whether it belongs to a supported family. Unsupported entries are dropped
   before the catalog reaches state, selection, logs visible to users, cards,
   badges, counters, or empty-state text. There is no "unsupported mode"
   warning or count.
2. **The Panel token is the login credential.** `client_id` is validated
   against the bearer token during enrollment. The app never asks for Panel
   email/password and never calls `/api/client/login` or `/api/client/select`.
3. **The Panel derives client IP.** Android sends no asserted public IP. The
   Panel records the trusted reverse-proxy source IP after validating its proxy
   chain.
4. **Local checks are not the trust boundary.** Root/emulator heuristics stop
   ordinary unsafe launches early. The Panel additionally requires a verified
   Android Keystore attestation for configuration pull, heartbeat, and Ultra
   lease access; a patched APK cannot obtain an Ultra lease with the agent
   token alone.
5. **Ultra credentials are memory-only.** The static Ultra runner config has
   no provider IP, user, password, or lease. These fields travel once over a
   private Unix domain socket and are wiped from Kotlin and Go buffers as soon
   as they are no longer needed.
6. **Kick and ban are authoritative.** A kick revokes the current Android
   device session and requires explicit user enrollment before it can resume.
   A ban cannot be cleared by enrollment and blocks every client API path.

## Compatibility Contract

### Supported forward families

The effective Android catalog is determined in this order:

1. `backend_mode=cf_mode` is dropped.
2. `backend_mode=ultra` is accepted only when all of these hold:
   - `current_mode`/`client_mode` resolves to `single`;
   - no sidecar, aggregation, bond ingress, endpoint list, or legacy runtime
     fields are present;
   - the Panel provides a valid unexpired `ultra_access` policy-v2 object;
   - the device holds valid trusted-device state.
3. Ordinary forwarding is accepted only when `backend_mode=current_mode` and
   its `client_mode` resolves exactly to `single` or `bond`.
4. Every other backend or resolved mode is dropped.

`ForwardMode` remains an internal representation for process planning, but
Android must not map `race`, `mptcp`, aliases, or unknown strings to `single`.
The parser reports an unsupported sentinel and the catalog builder discards it.

### Android enrollment

The default Panel URL is release-configured and not editable during enrollment.
The enrollment screen has exactly these manual fields:

- `Client ID`: positive integer.
- `Agent Token`: masked secret.

The app also accepts a one-time Android enrollment code via an App Link or QR
scanner. The code exchanges for the same pair after device attestation and is
immediately invalidated. It never places a raw agent token in an install URL.

`POST /api/client/devices/enroll` uses bearer authentication plus:

- request body `client_id`, `device_id`, `attestation_proof`, and optional
  one-time enrollment code;
- headers `X-Device-Id`, `X-Device-Name`, `X-Device-Platform`, and
  `X-Client-Version`;
- Android release in `os_release`.

The response contains a short-lived device session token and its expiry. The
agent token remains encrypted locally only so an explicit later enrollment can
be performed; the response never includes a user password.

### Device identity and trust

`AndroidDeviceIdentity` creates a non-exportable Android Keystore signing key
on first enrollment. The device ID is:

```
android-client-{clientId}-{lowercase(SHA-256(spki_der))[0..31]}
```

The raw Android ID, serial number, and IMEI are never sent or stored. The
Panel stores the device ID and public-key hash, and the UI displays only a
short non-secret device label.

The enrollment handshake is:

1. Client requests an attestation challenge using the agent token and device
   ID.
2. Panel returns a single-use, short-lived nonce bound to client and device.
3. Android creates or attests the Keystore key with that nonce, sends the
   certificate chain and public-key hash, and locally verifies that the key is
   hardware-backed where the device reports that capability.
4. Panel verifies the Android attestation chain, nonce, package certificate
   digest, boot state, and locked-device state. It issues a short-lived device
   session only on success.

The Panel rejects missing, expired, replayed, emulator, unlocked-boot, or
software-only attestations. Existing enrolled devices are re-attested before
device-session expiry and before every Ultra lease. A local `DeviceSecurity`
gate separately rejects debug/test-keys, known root managers, `su` paths,
debuggable builds, emulator fingerprints, QEMU properties, and unsupported
ABI. This local gate runs before enrollment, pull, VPN start, and lease
refresh.

### Device metadata and IP

An OkHttp interceptor supplies the identity headers to every Panel request.
The User-Agent is `LeiKwan-Host-Android/{version}`. The Panel persists:

- `device_id`, device label, platform, client version, Android release, and
  user agent;
- the source IP from a trusted reverse-proxy resolver, never a body field;
- attestation state, public-key hash, last attested time, and device session
  expiry.

`ClientOnlineSessionService` must accept proxy IP headers only when the direct
peer belongs to configured trusted proxy CIDRs. Otherwise it records the
socket remote address. Spoofable `X-Forwarded-For` is ignored from untrusted
peers.

## Panel Device Control

### Stored state

Add `client_device_controls` keyed by `(client_id, device_id)` with:

- `state`: `active`, `kicked`, or `banned`;
- monotonically increasing `command_version`;
- `reason_code`, `changed_by_user_id`, `changed_at`, and `acknowledged_at`;
- attestation public-key hash and trust/session expiry;
- normal created/updated timestamps.

`client_device_sessions` remains the online-observation table. A control row
outlives a deleted online session, so a kicked or banned device cannot recreate
its session merely by making another request.

### Endpoints and enforcement

All authenticated Android paths call a shared `DeviceControlService` before
returning a payload:

- `POST /api/client/devices/enroll`
- `POST /api/client/heartbeat`
- `GET /api/client/configs/pull`
- `POST /api/client/ultra/lease`
- `POST /api/client/devices/ack`

`kicked` returns `409 device_kicked` with `command_version`; `banned` returns
`403 device_banned`; missing or invalid trust returns `403 device_untrusted`.
No path leaks configs, lease material, or runtime state after a control
rejection.

The tenant client page replaces delete-only kick with three CSRF-protected
actions for the tenant's own client: kick, ban, and unban. Each action writes
the control row, revokes the active device session and Ultra policy generation,
and creates an audit event. Ban remains in effect until explicit unban.

Heartbeat responses add an optional `device_action` object:

```
{
  "command_version": 12,
  "action": "kick|ban",
  "reason": "tenant_action"
}
```

The Android foreground control loop acknowledges the command best-effort only
after it has stopped the VPN. Server-side state never depends on receiving the
acknowledgement.

## Android Runtime

### Control loop

`LeikwanVpnService` owns a single coroutine while the VPN is active. Every 30
seconds it sends the authenticated heartbeat, evaluates `device_action` and
control errors, and refreshes an expiring Ultra lease before its configured
refresh skew. The loop uses exponential retry for transient network failures;
it never retries a `device_kicked`, `device_banned`, or `device_untrusted`
response.

On an authoritative control result, `SessionRevocationCoordinator` performs
this sequence atomically:

1. Stop libbox VPN service.
2. Stop every owned ordinary, route-rule, and Ultra runtime process.
3. Wipe in-memory lease buffers and remove static Ultra config/socket/status
   files.
4. Delete device-session and agent-token preferences.
5. Persist only a non-secret user-facing reason and navigate to enrollment.
6. Send `POST /api/client/devices/ack` if the network remains available.

### Ultra runtime

The existing Go `tools/ultra-client` is cross-compiled for Android
`arm64-v8a`, packaged as both a native library asset and an executable runtime
asset, and resolved through `RuntimeBinaryInstaller`. `abiFilters` is pinned
to `arm64-v8a`; no x86 emulator binary is shipped.

`UltraForwardRunner` creates an app-private static config containing only:

- forward ID;
- local loopback listen host/port;
- target and transport;
- private control-socket path;
- non-secret bounded runtime limits.

After the process listener is ready, `UltraLeaseClient` validates the
`ultra_access` policy, requests a one-shot lease, and writes a framed lease to
the private Unix socket. It validates the framed reply, zeros request/response
byte arrays, and never serializes the lease to Prefs, Room, crash reports, or
logs. Lease failure stops the just-created Ultra process before any VPN traffic
is routed to it.

Ultra is started as a local `runtimeOnlyProxy` listener, so no user proxy-node
selection is required. The sing-box final outbound points to its loopback
listener. The provider IP is not represented in UI state or a static config.

### Direct reachability set

Replace `RuntimeContext.bypassHosts` with a structured
`ProtectedConnectivitySet`:

- `directHosts`: ordinary forward endpoints, CDN/SNI hosts, all active
  Shadowsocks/VLESS/SOCKS5 upstream hosts, and every WireGuard peer endpoint;
- `directCidrs`: explicit provider `/32` or `/128`, and Panel-provided,
  validated short-lived Ultra `direct_ip_cidrs`.

The Panel validates that an Ultra CIDR is a real IP network, is bounded to a
provider-approved range, and is not a default route. The Android policy parser
rejects a malformed or broad range. The set is emitted before all user rules,
country rules, custom bypass rules, and proxy defaults. It therefore cannot be
overridden by users and prevents a WireGuard or Ultra endpoint from entering
its own VPN tunnel.

### Proxy protocols

The direct-proxy importer and editor support exactly `ss`, `vless`,
`wireguard`, and `socks5` for this release. SOCKS5 accepts `socks://` and
`socks5://` URIs, including percent-decoded credentials and IPv6 hosts, and
compiles to sing-box `type=socks`, `version=5`, server/port, optional username,
and optional password. Existing VMess imports are not removed from storage in
this upgrade, but they are not selectable as a new direct proxy protocol.

## User Experience

### Enrollment and blocked devices

The default first screen is enrollment, not a password form. It presents Client
ID and Agent Token, a QR import action, validation errors, and a link back to
the Panel client-credentials page. No editable mode, endpoint, or Panel URL
field appears there.

A rooted, emulator, or unsupported-ABI device sees a blocking screen before
the app renders credentials or cached tunnel data. It cannot continue to the
tunnels, proxies, or settings screens.

### Tunnels

The tunnel list contains only Panel-approved effective forwards. Each card
shows service name, user-safe transport label, and connection state. It never
shows target hostname, listen port, provider IP, endpoint CIDR, lease state,
or backend-mode internals. Ultra has the same user-facing start/stop contract
as other selected forwards.

The user does not select a transport mode: the Panel decides `single`, `bond`,
or `ultra`. Any controlled choice, including proxy protocol, Shadowsocks
cipher, VLESS security/transport, WireGuard preset, DNS mode, and routing
outcome, uses a dropdown, segmented selector, switch, or picker. Text fields
remain only for arbitrary endpoints, secrets, keys, usernames, passwords, and
custom rules that cannot be enumerated.

### Proxies

The primary add flow is scan/import URI/subscription. Manual entry is an
advanced flow. Its protocol is a selector, protocol-specific enum values are
selectors, and the UI validates before saving. A SOCKS5 node is selectable
alongside Shadowsocks, VLESS, and WireGuard.

## Failure Handling

- Invalid Client ID/token: show a generic enrollment failure without revealing
  whether a client exists.
- Attestation or device-security failure: do not call config pull or lease;
  show a stable blocked reason.
- Device kick/ban: stop immediately, wipe credentials/leases, and show the
  Panel-provided non-sensitive action reason.
- Expired or rejected Ultra policy/lease: stop only that attempted runtime,
  leave the VPN disconnected, and expose a generic retry state.
- Missing Android Ultra binary, incompatible ABI, socket rejection, or listener
  readiness timeout: fail closed before VPN startup.
- Unsupported raw forward: discard silently as specified in the product
  decision; do not fall back to another mode.

## Testing and Acceptance

### Android unit and Robolectric coverage

- Client ID/token enrollment, legacy password purge, device-ID stability, and
  required identity headers.
- Root/emulator/ABI gates using injectable device signals.
- Strict catalog filtering: `single`, `bond`, valid `ultra` accepted;
  `cf_mode`, `race`, `mptcp`, malformed, and unknown entries absent.
- Ultra policy and lease validation, nonce generation, no lease persistence,
  private socket framing, and failure cleanup.
- Protected connectivity compilation for Ultra provider IP/CIDR and WireGuard
  peer endpoint before user routes.
- SOCKS5 URI parsing, editor serialization, and sing-box outbound generation.
- Kick/ban reducer behavior: stop runtime, wipe state, and do not retry.

### Panel tests

- Trusted and untrusted proxy IP resolution.
- Attestation challenge replay, package-signature mismatch, unlocked boot,
  emulator, and expired-session rejection.
- Client ID/token enrollment mismatch rejection.
- Kick persistence, re-enrollment requirement, ack, ban rejection, and unban.
- No configuration or Ultra lease response after a device-control rejection.
- Android receives policy-v2 Ultra grants only after trusted enrollment.
- Ultra `direct_ip_cidrs` validation and no provider material in ordinary pull.

### Build and device verification

Run:

```powershell
cd leikwan-android
.\gradlew.bat :app:testDebugUnitTest
.\gradlew.bat :app:assembleDebug
.\gradlew.bat :app:connectedDebugAndroidTest
```

The final instrumented run is performed on a non-rooted physical `arm64-v8a`
phone. An emulator, rooted phone, unlocked-boot device, or unsupported ABI is
an expected rejection case, not a valid acceptance device.

Acceptance requires all automated tests above, a physical-device enrollment,
Panel device/IP metadata evidence, kick and ban evidence, a successful
single/bond/Ultra start-stop check, and proof that neither the APK static
assets nor Android app storage contains an Ultra lease credential after stop.

## Rollout and Rollback

1. Deploy Panel schema and read-only device-control/attestation observability
   first, without requiring Android trust for existing Windows/Linux clients.
2. Deploy Android-aware enrollment and strict control enforcement behind an
   Android client-version gate.
3. Ship the Android arm64 APK to a small physical-device cohort.
4. Enable Android Ultra grants only after the cohort passes device trust,
   lease, and direct-route validation.
5. Enable tenant kick/ban controls after the Android acknowledgement path is
   proven.

Rollback disables the Android version gate and Ultra grant eligibility, then
revokes active Android Ultra leases. It does not delete device-control audit
state. The prior APK remains usable only against the prior compatible Panel
contract; no rollback re-enables `cf_mode` or exposes Ultra material to an
untrusted device.
