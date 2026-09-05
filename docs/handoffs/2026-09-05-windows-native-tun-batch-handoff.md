# Windows Native TUN Batch Lease Handoff

Date: 2026-09-05 (Asia/Taipei)
Scope: Windows native outbound in TUN mode. Android, macOS, PHP, and unrelated dirty files were not modified by this release work.

## Release candidate

- Desktop app: `0.1.20.9`
- Ultra Windows runtime: `2026.09.05.1`
- Public installer path: `artifacts/windows/leikwan-bridge-setup.exe`
- Public runtime path: `artifacts/ultra/ultra-client_windows_amd64.exe`
- Setup size/hash: `69437952` / `52808cd3145085f5aec37706b4ee36dba66be41c1b78e547294c5191e7a5c5e7`
- Runtime size/hash: `6887424` / `62174c051552d4b127c7a5d7fd692512f5ab9afa59520b6463e28c3da5454f4b`

The manifest entries in `artifacts/versions.json` are scoped to these two Windows artifacts. Existing Android/macOS entries and pre-existing manifest drift were left untouched.

## Behavioral fix

1. The desktop client performs an uncached full config pull immediately before requesting native leases. This avoids using a rotated `UltraAccess` ticket held by an older UI snapshot.
2. Native lease requests use the bounded auto-selection contract (`selection_mode=auto`, `max_candidates=4`) and retain the forward, route scope, route policy, and scope digest binding.
3. The Windows native relay accepts a lease batch and probes/selects a usable candidate while retaining the control channel for refresh.
4. Native TCP half-close handling, UDP association host/port isolation, and periodic keepalive prevent idle UDP mappings and unrelated UDP flows from closing the shared native session.
5. Restricted native DNS is explicitly UDP with `ipv4_only`; the restricted rule-set download detour is `direct`.
6. Refresh uses the same fresh-grant path and preserves the active runtime until a replacement candidate is usable.

## Acceptance evidence

- Go tests: `go test ./... -count=1 -timeout=180s` passed (`7/7`).
- Windows Core tests: `dotnet test ...LeikwanBridge.Core.Tests.csproj --no-restore -c Release` passed (`282/282`).
- Local installed app/runtime hashes matched the build; installed app state reported `0.1.20.9`.
- TUN adapter `LeiKwan Bridge` / `sing-tun Tunnel` was `Up`.
- Headed Twilio Network Test (`https://networktest.twilio.com/`) under `forward 180`, `CAN -> HKG`, `intl_only`, `unrestricted`, TUN mode:
  - Run 1: TURN UDP/TCP/TLS and bandwidth passed.
  - Run 2: TURN UDP/TCP/TLS and bandwidth passed.
  - Run 3: TURN UDP/TCP/TLS and bandwidth passed.
- Raw browser evidence: `.tmp/twilio-native-tun-20260905.log`.
- Local install evidence: `.tmp/local-native-tun-install-20260905-153653.log` and backup `.tmp/local-native-tun-backup-20260905-153653`.

## Requirements for Android/macOS parity

- Pull a fresh policy snapshot immediately before every native lease grant; do not trust a cached session ticket merely because the config revision is unchanged.
- Use the same bounded batch request (one to four candidates), preserve `forward_id`, direction, route scope, route policy, and scope digest, and wipe lease credentials after handoff/stop.
- Probe real UDP quality before activation. A simple NAT type check is insufficient; acceptance must include a real TURN UDP result from the Twilio Network Test or equivalent end-to-end UDP test.
- Keep one selected candidate sticky for existing flows, and only replace it after the new candidate is ready. Refresh/reconnect must be idempotent and must not tear down the working TUN before replacement succeeds.
- Keep the topology `client -> slave -> sidecar`; do not introduce direct client-to-sidecar paths. Unix platforms should use their existing Unix control socket/batch wire rather than Windows named-pipe details.
- Validate both TCP and UDP data paths, DNS packet handling, idle keepalive, half-close behavior, and sleep/resume before claiming platform acceptance.
- Do not claim full native TUN/BridgeOptions acceptance on Android while the matching `io/nekohasekai/libbox/BridgeOptions.class` is absent from the AAR.

## Deployment boundary

This handoff is for the Windows native TUN batch/fresh-grant release. Production publication should replace only the Windows setup, Windows Ultra runtime, and their manifest fields, using backup-first atomic deployment and post-install/public hash checks. Do not overwrite Android/macOS artifacts while applying this handoff.
