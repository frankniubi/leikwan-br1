# macOS native relay control fix

## Root cause

`native_relay_control_unavailable` came from the manual native-outbound path (`NativeOutboundRelayHost`), not the ordinary Ultra auto-selection path. On macOS the host generated:

`/Users/leikwan/Library/Application Support/LeiKwan/ClientAgent/runtime/native-180-<guid>/control.sock`

The test machine measured this as 136 UTF-8 bytes. The bundled `ultra-client` immediately exited with `listen unix ...: bind: invalid argument`; the host then waited three seconds and reported the misleading generic error. The runtime never reached lease delivery.

A controlled test with the same arm64 helper and identical native configuration used `/tmp/lk-n-<guid>/control.sock` (31 bytes): the Unix socket appeared in approximately 38 ms. This proves the failure was the macOS `sockaddr_un` path limit, not candidate selection, lease validity, provider reachability or UDP keepalive.

## Fix

`NativeOutboundRelayHost` now keeps the runtime JSON/status directory under the existing private Application Support state, but places only the Unix control socket in a short randomized directory under `/tmp`. The directory is created mode 700, the helper creates the socket mode 600, and the directory is removed during all normal stop paths. A UTF-8 path-length guard fails explicitly and removes the newly created directory rather than attempting an invalid bind.

The Windows named-pipe path is unchanged. Lease framing, route policy, candidate list, and native SOCKS5 protocol are unchanged.

## Verification

- Core tests: `294/294` passed.
- Mac tests: `73/73` passed.
- New `NativeOutboundRelayHostTests.StartCandidates_UsesShortPrivateUnixControlPath` verifies the generated path stays under the injected private socket root, is below the Unix limit, becomes visible before lease delivery, and is removed on stop.
- Existing Mac package/signature/layout tests remain green.
- Candidate Mac `1.0.11` was built with this change and installed on test host `100.103.253.21` as `/Applications/LeiKwan Bridge.app`; Apple strict/deep signature verification passed.
- The test host currently has no Keychain-loaded agent credentials in the SSH test session. Therefore no authenticated end-to-end native lease/control test was claimed. Run the native-outbound button from the interactive desktop session to prove the production credential path.
- Prior old-package evidence: long control path failed `bind: invalid argument`; short path succeeded in 38 ms. Preserve `.tmp/macos-working-latency-20260906/probe-native-control.py` and its remote output if further investigation is needed.

## Release state

This is a Mac-only candidate fix. Production remains Mac `1.0.8`; Android, Windows, Linux and PHP artifacts are untouched. Publish only after the interactive test host confirms the native-outbound control socket and local proxy traffic. Do not infer this fix from the ordinary Ultra listener logs alone.

Implementation commit: pending in the current checkout.
