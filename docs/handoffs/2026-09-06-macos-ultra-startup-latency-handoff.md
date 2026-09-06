# macOS Ultra startup latency handoff
## Finding

The Mac client does use the same lease and candidate-quality selection model as Windows. It requests an Ultra lease batch, starts one local `ultra-client` per active forward, sends the lease batch through the Unix control socket, and waits for the runtime's listener. With multiple candidate leases, the runtime probes candidates and selects the best usable path rather than blindly choosing the first item.

The customer log showed a real startup regression in the UI contract:

- Forwards `158`, `170`, `159` and `171` started at 14:16:41, 14:18:00, 14:19:19 and 14:20:39. Each waited about 75 seconds before timing out.
- Forward `180` was then stopped because its cached policy had expired.
- A retry started four forwards concurrently at 14:22:11 and they became ready at 14:23:14. The fifth became ready at 14:23:47. This is why the button eventually changed to `Stop`, but after several minutes of an opaque `Working` overlay.
- `Working` therefore meant a blocking startup pipeline, not a failed route. The later `Stop` button meant the connection was ready and could be stopped.

## Fixes

- `ConfigPollingService` and `DesktopRuntimeSession` execute synchronous runtime/lease operations on thread-pool workers. Initial Unix lease IPC no longer inherits a UI `SynchronizationContext`, removing the UI-thread wait cycle observed during first connect.
- Mac candidate selection remains enabled. `MacUltraRuntimeHost` now requests a 3-second, 6-sample quality window with four probe workers instead of the old 30-second/30-sample/1-second settings. It retains `auto_select=true`, `probe_target=1.1.1.1:53`, UDP prewarm and the 5-second UDP keepalive.
- Initial lease delivery is bounded to 20 seconds. A stuck provider or runtime fails fast instead of holding the UI indefinitely. The controller also bounds the full connect transaction to 45 seconds.
- The UI now displays `Signing in`, `Starting routes`, `Starting routes 2/5`, and `Refreshing routes`; progress is emitted after each forward completes.
- Failed process-group creation on macOS now falls back to signaling the exact owned PID. This prevents orphaned `ultra-client` children after a failed stop and avoids stale listeners delaying the next start.
- A forward row reports actual runtime state after start instead of unconditionally changing to `running` when the call returns.

## Verification

- Targeted Core tests: `23/23` passed, including Unix lease IPC completion under a synchronization context and bounded concurrent route preparation.
- Mac tests: `73/73` passed, including candidate bootstrap settings, PID fallback, progress display and existing Mac behavior.
- Existing Core full suite: `292 passed, 1 failed`; the failure is the pre-existing `WindowsProxyConnectionServiceTests.ProcessModeUsesFallbackPortAndCanReconnectWhenPreferredPortIsBusy` port-allocation environment case (`proxy_port_unavailable`), outside this Mac change. No Windows source was changed.
- The earlier Mac log and redacted evidence are in `.tmp/macos-working-latency-20260906/client-redacted.log`.
- The new managed Mac candidate was built on the arm64 test Mac with the current source and installed as `1.0.10`; package signing, DMG layout and ZIP extraction checks passed. The test Mac has no Developer ID identity, so ad-hoc signature integrity is verified but default Gatekeeper trust is not.
- The test Mac has no usable Keychain identity in non-interactive SSH, so an automated authenticated five-forward startup timing run could not be claimed. The user's earlier authenticated log remains the timing evidence for the old behavior. A manual test from the desktop login session is required to measure the new route-ready time with the user's credentials.

## Candidate

Candidate managed source commit is the working tree change after `56d7726a`; candidate package version is `1.0.10`. It is not in the production manifest. Do not update Windows, Android, Linux or PHP for this latency-only change.

Before production publication, capture from the user's interactive Mac session:

1. Time from Connect to first `preparing_routes:0/N` and each subsequent progress event.
2. Time to every `ultra listener ready=true` event, with forward IDs and no credentials.
3. Whether all five listeners are ready within the 45-second controller bound.
4. `codesign --verify --deep --strict`, Gatekeeper result and first launch. A warning-free internet install still requires Developer ID signing and notarization, which are currently unavailable.

## Implementation Update

- Commit pending from this handoff: Mac/Core startup-latency changes after `56d7726a`. Candidate version is `1.0.10`; production remains `1.0.8`.
- `UnixUltraLeaseClient` and the two bounded runtime-operation schedulers now use `ConfigureAwait(false)`/thread-pool execution. This is specifically to prevent a UI synchronization-context cycle while the runtime waits for its Unix control-socket acknowledgement.
- Mac auto-select bootstrap is `3s / 6 samples / 500ms / 4 workers`; lease delivery is bounded to 20 seconds and the full controller connect to 45 seconds. The runtime still receives all candidates and performs quality ranking.
- Mac process supervision records whether `setpgid` succeeded and signals the exact PID when it did not. The old log had orphaned `ultra-client` children after `process_group_create_failed`; this fallback is covered by a regression test.
- UI progress is emitted as `preparing_config`, `preparing_routes:0/N`, then `preparing_routes:X/N`, and is rendered in the overlay as `Signing in`, `Starting routes`, or `Starting routes X/N`. A completed `Stop` button is the disconnect action, not a startup failure.
- Fresh verification: Core `293/293` and Mac `73/73` passed; focused changed Core tests `23/23` passed. The one earlier Core failure was transient Windows proxy-port environment contention and was green on the subsequent full run.
- Test Mac package build/install measurement could not use the user's lease credentials: non-interactive Keychain access returned empty agent/session tokens, so no real authenticated new-start timing is claimed. The old authenticated log is the evidence being improved. The candidate is installed on the test Mac and ready for an interactive user-session measurement.
