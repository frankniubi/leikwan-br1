# macOS 1.0.14 native rule-set startup fix

## Observed failure

The test Mac was running 1.0.13. At 17:25:30 native control and lease delivery
succeeded for forward 180; at 17:25:37 startup failed with
`connection_rule_set_unavailable`.

A bounded live probe reused the test client's identity and requested fresh
`180 / CAN -> HKG / intl_only` leases without exposing credentials. With the
old remote rule-set configuration:

- Restricted: sing-box exited during `initial rule-set: geosite-cn` with
  `socks5: request rejected, code=1` while downloading from GitHub over HTTPS.
- Unrestricted: the same download failed with `unexpected EOF`.

Restricted native leases allow only ports 1000-65535. Sending rule-set HTTPS
downloads or DoH on 443 through that relay contradicts its authenticated policy.
Even unrestricted routing must not require a successful live GitHub download
before a local connection can start.

## Changes

- Embed upstream `geoip-cn.srs` and `geosite-cn.srs` in the Mac assembly.
- Verify pinned SHA-256 hashes and atomically materialize versioned files under
  `state/native-rule-sets`. Existing valid files are reused; corrupt cache is repaired.
- MacConnectionService supplies these paths to the config builder, producing
  `type=local`, `format=binary` rule sets without URL/download detour fields.
- Preserve geoip/geosite tags and all routing rules, including restricted low-port
  direct routing, scope binding, and unrestricted policy semantics.
- Restricted DNS follows the low-port direct policy. Mainland-to-international
  restricted DNS uses direct AliDNS DoH; the direct Cloudflare probe timed out.
  Unrestricted DNS behavior was retained. An experimental UDP DNS alternative
  did not improve the live test and was not included.
- Upstream notices and GPL license files are bundled with the application.
  Data snapshots refresh with app releases, not during connection startup.

Rule-set hashes:

| Data | Bytes | SHA-256 |
| --- | ---: | --- |
| geoip-cn.srs | 34074 | 0acf5dad38fba9db2dade29ce5e4edc6902220944f30628ae46ed16cb0ec5edd |
| geosite-cn.srs | 55507 | f657669be37bf136f97e6c0cacede9bf735523427abd21d58ac34ca5ec5d92db |

## Verification

- Mac tests: 93 passed; Core tests: 294 passed; no failures or skips.
- New tests were observed failing with remote rules and the old restricted DNS,
  then passing after the fix. Existing route contracts and ordinary-mode tests remain.
- Final live probe with the production fix code: both restricted and unrestricted
  sing-box remained running, and each returned HTTP 200 for an HTTPS request.
- Probe `EXIT=137` is from the test harness explicitly terminating its isolated
  sing-box after the bounded test, not an unexpected runtime crash.
- Earlier unrestricted probes included intermittent EOF/TLS failures. One final
  successful request does not establish sustained availability of every lease.
- Privileged TUN probe was attempted with auto-route disabled; it returned
  `vpn_permission_required`. Full TUN/UDP/WebRTC acceptance remains unproved.
- Both architectures passed package layout and strict/deep codesign verification.
  Signatures remain ad-hoc and are not Developer ID/notarization acceptance.

## Test-machine update

Installed on `100.103.253.21` at `/Applications/LeiKwan Bridge.app`; version 1.0.14.
GUI PID 19944 and ordinary Ultra children were verified after installation.
The system proxy remained disabled; no probe processes were left running.

Previous DLL SHA-256:
`d7c1a5a0f1dc7c2bbbcca698ac118fe678b7d0258a692121ba795eb8dfdd7c98`.
Installed DLL SHA-256, matching local build:
`d285b2e9c174caaac7af8b5e95184509ba3f31be717716cb150b491b85c20dea`.

Verified backup:
`/Users/leikwan/leikwan-native-package-1.0.9/rollback-1.0.13-rule-sets-20260906T174750.app`.

## Candidate artifacts

Local directory: `artifacts/macos-rule-set-1.0.14/`.
Remote build directory: `/Users/leikwan/leikwan-native-package-1.0.9/artifacts-1.0.14`.

| File | Bytes | SHA-256 |
| --- | ---: | --- |
| leikwan-bridge-arm64.dmg | 76752538 | 00c5dbce5c1a93e7dfdda6be5dad7f38ce80946a2be869ff432b33aef3901611 |
| leikwan-bridge-update-arm64.zip | 68380015 | 0cb1bd99c6b3103fe475cfe19f5386b196b0fcb267fe74a02781da66eb39a9b7 |
| leikwan-bridge-x64.dmg | 81430904 | 608ce91d66abbebce19efcf9e69a3f2685a827a3d5d2ce4ee30cb11c893339f1 |
| leikwan-bridge-update-x64.zip | 72656609 | 0abca056385658952036a76240bef3a7b384900fbe9163a74f4728244a87a2cf |

Production manifest and payloads were not changed. Manifest SHA-256 remained
`8374f63ca32ad7b50d9d600c7903f6cda9e9c53f632b99277f443ed8c08da70f`.
Windows/Android/PHP/Linux files were not included in this fix.

## Evidence

Under `.tmp/macos-rule-set-20260906/`:

- `read-state.log`: original app failure and installed version.
- `live-repro.log`: original remote rule-set failure in both policies.
- `live-local-rules.log`: rule-set fix exposed restricted DNS rejection.
- `live-dns-paths.log`: verified restricted direct DNS correction.
- `live-final-verified.log`: final code, both policies HTTP 200.
- `live-tun.log`: explicit privileged TUN acceptance blocker.
- `install-1.0.14.log`, `final-state.log`: backup, hashes, signature, active process.
- `test-results/mac-rule-set-final.trx`, `test-results/core-rule-set.trx`.

Do not use the earlier `live-final.log` as final acceptance: a diagnostic harness
compile failed before that run and it used the older probe DLL. The later
`live-final-verified.log` followed a successful build and updated assembly transfer.
