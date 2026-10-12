# Desktop cloud nodes (Windows / macOS): shared Core API

**Date:** 2026-10-12
**Branch:** `feat/desktop-win-ui-cloud-nodes` (based on `feat/hosted-node-pairing`)
**Design this builds on:** `docs/plans/2026-10-12-hosted-user-node-pairing.md` (panel + Android).
The product owner answered its open question #2: desktop clients get cloud nodes too.

## 1. Panel: who may call `/api/client/hosted-nodes*`

`HostedNodeController::trustedClient` now admits the official Windows and macOS clients next to attested
Android devices. Linux headless is refused until the Linux agent has a nodes feature (no client there would
use the node credentials). A desktop request passes exactly the identity its config pull relies on:

| Check | Android | Windows / macOS |
|---|---|---|
| Client agent token (`Authorization: Bearer`) | yes | yes |
| Access policy (geo lock, blacklist) | yes | yes |
| Device trust (`DeviceControlService::requireActive`: enrolled, attested, live `X-Device-Session`) | yes | n/a (desktop has no attestation) |
| Explicit per-install `X-Device-Id` of the platform's own format | `android-client-<id>-<32hex>` | `windows-client-<client id>-<16hex>` (must match the client), `macos-client-<32hex>` |
| Online device limit (`client_device_sessions`, kickable in `/tenant/clients`) | yes | yes |

- `X-Device-Platform` must start with `android`, `windows` or `macos` (the desktop apps send
  `windows-desktop` / `macos-desktop`). Anything else, including the Linux agent's `linux`: `403 device_untrusted`.
- A request that presents an attested Android device id is always held to the Android gate, whatever
  platform it claims.
- The server-derived `legacy-*` id is never accepted for desktop, so every call is a visible device session.
- Hosted nodes give the forward core no new capability; the panel only stores the user's own nodes.
- **macOS:** `MacPlatformIdentityProvider` already sends `macos-desktop` + `macos-client-<32hex>`; no change needed.

Tests: `master/tests/hosted_node_client_api_test.php` (desktop section), `hosted_node_contract_test.php`.

## 2. Core namespace `LeikwanBridge.Core.Nodes`

| Type | Purpose |
|---|---|
| `UserNode` | Platform-neutral SS / SOCKS5 / VLESS node. `LocalId` (device id), `HostedId` (panel id, null = local-only), `IsHosted`, `IsCloudSupported` (panel would accept it: AEAD/2022 SS methods, obfs-local / v2ray-plugin), `DisplayProtocol`, `DisplayEndpoint`, `Clone()`. |
| `UserNodeProtocol` | `"ss"`, `"socks5"`, `"vless"`, `Normalize()`, method / plugin / security / transport lists. |
| `UserNodeShareLink` | `TryParse(uri, out node, out error)`, `Parse`, `ParseMany(text)` (lines or base64 subscription), `Format(node)` → canonical link (same parameters as the panel's `HostedNodeSpec::toShareUri`). Matches Android `ProxyUriParser` incl. the VLESS fixes (query/fragment decoded once, IPv6 brackets stripped, `type=raw` = tcp). Errors are stable `node_*` codes, never the link. |
| `UserNodeIdentity` | `Key(node)` connection identity (ignores name, uTLS fp, ALPN); `WithoutHostedDuplicates`. |
| `IHostedNodeApi` + DTOs (`HostedNodesResponse`, `HostedNodeDto`, `HostedNodePairingDto`, `HostedNodeBatchResult`, `HostedNodeUploadRequest`, `HostedNodeBatchRequest`, `HostedNodeBatchEntry`, `HostedNodeUpdateRequest`) | The six endpoints. **`MasterApiClient` implements `IHostedNodeApi`** (same headers + mirror failover as the pull). Refusals throw `HostedNodeException(Code, Field, StatusCode)`; the online-device limit throws `DeviceLimitReachedException`; an old panel (404 HTML) → `hosted_nodes_unsupported`. |
| `IUserNodeRepository` | The device's node list: `LoadAsync`, `SaveAsync(node)` (LocalId 0 = insert), `DeleteAsync(localId)`. |
| `SecretStoreUserNodeRepository(ISecretStore, account = "user-nodes")` | Node list as one JSON value in the platform `ISecretStore` (macOS: `MacKeychainSecretStore`, with its `MacFileSecretStore` fallback). 64 nodes / 512 KiB max. |
| `WindowsProxyProfileNodeRepository(IWindowsProxyProfileHost)` | Windows: the nodes ARE the saved proxy profiles (DPAPI file, `WindowsProxyProfileStore`). `WindowsProxyConnectionService` implements `IWindowsProxyProfileHost`. Static `ToNode(profile)` / `ToProfile(node, existing)`. |
| `IHostedNodeSyncStateStore` → `JsonFileHostedNodeSyncStateStore(path)`, `InMemoryHostedNodeSyncStateStore` | Last synced client id, `hosted_nodes_revision`, resolved pairings. No credentials, plain JSON. |
| `HostedNodePairing(ForwardId, LocalNodeId, HostedNodeId, Source)` | Panel pairing resolved to a local node; `Source` = `explicit` / `target_match`. |
| `HostedNodeSyncService` | Two-way sync (below). |
| `UserNodeOutbound` | `ForForward(node, forwardListenPort)` → sing-box outbound `JsonObject` (tag `proxy`) dialing `127.0.0.1:<port>`; `RewriteForForward(profile, port)`. |

`ClientMeta.HostedNodesRevision` (`client.hosted_nodes_revision` in the pull): `null` = panel predates
hosted nodes, `""` = client hosts none.

### Constructing the sync service (macOS)

```csharp
using LeikwanBridge.Core.Nodes;

var nodes = new SecretStoreUserNodeRepository(secretStore);          // MacKeychainSecretStore
var state = new JsonFileHostedNodeSyncStateStore(Path.Combine(paths.Root, "hosted-nodes-state.json"));
var sync  = new HostedNodeSyncService(
    api: () => currentMasterApiClient,      // Func<IHostedNodeApi?>; null while signed out → "hosted_nodes_offline"
    repository: nodes,
    stateStore: state,
    clientId: () => signedInClientId);      // 0 while signed out
await sync.InitializeAsync();

// After every successful config pull:
await sync.SyncIfRevisionChangedAsync(pull.Client?.HostedNodesRevision);   // never throws for panel/network errors
```

If the macOS app keeps its existing `MacConnectionProfile` list as "the nodes", write a small
`IUserNodeRepository` adapter over `IMacConnectionService` like `WindowsProxyProfileNodeRepository`
(Mac profiles today have no `hosted_id`, SOCKS5 or VLESS; either add them or use
`SecretStoreUserNodeRepository` as a separate node list and `UserNodeOutbound.ForForward` to build the outbound).

### Operations (all serialized; write-through)

| Method | Behavior |
|---|---|
| `SyncIfRevisionChangedAsync(revision)` | Fetches + materializes when the revision or the signed-in client changed. Records "nothing hosted" without a fetch. Failures → `Status.LastError`, retried on the next pull. |
| `RefreshAsync()` | "Refresh from cloud". |
| `UploadAsync(localId, pairForwardId?)` | Upload one node; the same local row becomes the hosted row (or is dropped if the panel already hosts it on this device). |
| `UploadAllLocalAsync()` → `HostedBulkUploadResult(Uploaded, AlreadyHosted, Unsupported, Failed, Failures[])` | "Sync all to cloud", batches of 32. Unsupported (legacy ciphers, other protocols) stay local. |
| `SaveAsync(node)` | Hosted → panel `PUT` first, then the returned listing; local → repository. |
| `DeleteAsync(localId)` | Hosted → panel `DELETE` (all devices of the client); local → repository. |
| `PushPairingAsync(forwardId, localId)` → bool | Hosted node: pair on the panel (all devices). Local-only node: returns false, keep the pick on this device. |
| `ClearPairingAsync(forwardId)` | Clears the explicit pairing (panel falls back to target match). |
| `ImportAsync(text)` → `UserNodeImportResult(Added, Duplicates, Invalid, FirstError)` | Paste one or many links; skips nodes already on the device; never uploads. |
| `PairingFor(forwardId)`, `Pairings`, `Status` | Current state. |

### Events / observable state

- `StatusChanged(HostedNodeSyncStatus)` — `IsBusy`, `ClientId`, `Revision`, `LastSyncedAt`, `LastError` (stable code), `Pairings`.
- `NodesChanged()` — the local node list changed; reload it.
- `PanelPairingsChanged(IReadOnlyList<HostedNodePairing>)` — pairings that appeared or now point at another
  node since the previous sync (console or another device changed them). Apply them as the forward's
  selected node; unchanged pairings are not repeated, so a local-only pick on this device survives.

Events fire on the thread that ran the operation (a thread-pool thread after `ConfigureAwait(false)`);
marshal to the UI thread.

Conflict rule (same as Android, §7.4 of the design doc): no offline queue; the panel's arrival order is the
only ordering (last writer wins); a delete anywhere deletes everywhere.

## 3. How a paired node is used on desktop (decision)

**Decision: reuse the local sing-box proxy core the desktop apps already run, exactly like Android.**

Both desktop apps already have the Android model built in:

- Windows: `WindowsProxyConnectionService.StartAsync(..., forwardListenPort)` runs sing-box with the user's
  saved profile rewritten to `127.0.0.1:<forward listen port>` (SNI kept), with a per-forward profile pick
  (`AgentConfig.ProxyProfileIdsByForwardId`). The sing-box inbound listens on `127.0.0.1` only (HTTP/mixed),
  or is a TUN / per-process redirect — never a LAN listener.
- macOS: `MacConnectionService.StartRoutedAsync(mode, profileId, forwardListenPort, ...)` does the same.

So a hosted node is materialized as a normal local node (on Windows: a saved profile with `hosted_id`), and
a panel pairing becomes the forward's selected node. "Connect" then works unchanged: forward + paired node →
sing-box dials `127.0.0.1:<listen port>` → forward → the user's node. Nothing new listens anywhere, so this
adds no open-proxy surface; the forward listener and the Ultra lease gating are unchanged.

Rejected alternatives: showing the share link for the local port (the app already runs the core, so making
the user wire third-party software to the forward port would be a regression and exactly the "outside
software uses Ultra" pattern the product rules discourage); a new proxy core (unnecessary).

Windows additions that make hosted nodes run: SOCKS5 outbound (`socks5` profile protocol, RFC 1929 auth),
Shadowsocks SIP003 plugins (`obfs-local`, `v2ray-plugin`), VLESS `httpupgrade`, REALITY defaults to uTLS
`chrome`, and the ws/httpupgrade `Host` keeps the real host when dialing `127.0.0.1`.

## 3a. Forward listeners: loopback unless inbound proxy is allowed

The pull's `client.allow_inbound_proxy` (from `clients.allow_inbound_proxy`, default 0; absent on older panels)
decides where desktop forward listeners bind. `ConfigPollingService` copies it onto every
`ForwardConfig.AllowInboundProxy`; `ForwardConfig.ListenHost` / `WstunnelListenHost` give the bind address:

- false (default): `127.0.0.1` for bond (tcp/udp), the UDP UoT sidepath, MPTCP and single/race wstunnel.
- true: all interfaces (`0.0.0.0` / `[::]`), the previous behavior.
- Ultra listeners stay on `127.0.0.1` either way.

Toggling the flag changes the panel config revision (only for enabled clients) and the forward signature, so the
runtime rebinds. UIs should show any "other devices on your LAN can connect" hint only when
`ClientMeta.AllowInboundProxy` is true.

## 4. Windows wiring (for reference)

- `MainViewModel` builds `HostedNodeSyncService(() => _api, new WindowsProxyProfileNodeRepository(_proxyService), new JsonFileHostedNodeSyncStateStore(<state dir>/hosted-nodes-state.json), () => active client id)`.
- After each pull it calls `SyncIfRevisionChangedAsync(pull.Client?.HostedNodesRevision)`.
- `PanelPairingsChanged` → `AgentConfig.ProxyProfileIdsByForwardId[forward] = localId`.
- Picking a hosted node for a forward on the Nodes / Services page → `PushPairingAsync`.
