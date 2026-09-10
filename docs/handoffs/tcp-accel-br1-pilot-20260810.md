# BR1 tcp-accel Pilot Handoff - 2026-08-10

## What changed

- Added `tools/tcp-accel/` from `leigod-forward` for the Python split-TCP accelerator; `tcp_accel.py` is an exact hash match with the source project.
- Added BR1-specific `slave-agent/leigod_tcp_accel_bridge.py` for the required `client -> slave -> sidecar` topology. It uses SOCKS5 CONNECT through slave LeiGod lines and rejects slave listener ports outside `7000-9000`.
- Added `install/install-tcp-accel.sh` and chained it from new client/slave/sidecar installers. The installer installs Debian 12 dependencies (`python3 curl ca-certificates coreutils iproute2`) and only writes files/systemd units; it does not start pilot traffic.
- Published artifact routes and manifest entries for `/artifacts/leikwan-tcp-accel.py`, `/artifacts/leikwan-tcp-accel-bridge.py`, and `/artifacts/install-tcp-accel.sh`.

## Version/source boundary

- Version lineage audit: `docs/handoffs/tcp-accel-br1-version-lineage-20260810.md`.
- Base branch for this worktree: `master` `813392e`.
- Current worktree branch: `codex/leigod-tcp-accel-pilot`.
- Local newer BR1 branches were treated as comparison inputs only, not merged wholesale.

## Artifact identity

- `tcp_accel_runtime` `tools/tcp-accel/tcp_accel.py` sha256=`74bc214921365b84dca68110272effa7671087b0b5286fabfb3bd40a620376dc` size=`47382` actual_sha_match=`True`
- `tcp_accel_bridge_runtime` `slave-agent/leigod_tcp_accel_bridge.py` sha256=`3745c9c5c0032bd505fc6a79b517e8168f99bca57b6142f2d326138e3fd90e38` size=`15268` actual_sha_match=`True`
- `tcp_accel_installer` `install/install-tcp-accel.sh` sha256=`8a6752604bac264374a15765aa23f2477e729ea719967f9e7400eb5e9350286a` size=`8389` actual_sha_match=`True`
- `client_agent_installer` `install/install-client-agent.sh` sha256=`37761e7c0415d8eab7b44bbba09c43186002ffbf690bc89514b999811434d0ed` size=`20294` actual_sha_match=`True`
- `slave_installer` `install/install-slave.sh` sha256=`8b9fe30c52311be93d98411d6cc5184882cf0c61d595258939e325d4889e6f11` size=`17667` actual_sha_match=`True`
- `bond_sidecar_installer` `install/install-bond-sidecar.sh` sha256=`8f0065011b1dd083722ddcf53179c257bbd5880f0b78166dfda00f5f0a185b8e` size=`8942` actual_sha_match=`True`

Upstream copied runtime:

- leigod-forward `tools/tcp-accel/tcp_accel.py` sha256=`74bc214921365b84dca68110272effa7671087b0b5286fabfb3bd40a620376dc`
- BR1 `tools/tcp-accel/tcp_accel.py` sha256=`74bc214921365b84dca68110272effa7671087b0b5286fabfb3bd40a620376dc`

## Verification run

- `python -m pytest client-agent/tests/test_tcp_accel_installers.py slave-agent/tests/test_tcp_accel_bridge.py -q` -> `7 passed, 5 subtests passed`.
- `php master/tests/tcp_accel_artifact_distribution_test.php` -> `ok`.
- `bash tools/tcp-accel/selftest_debian12.sh` -> `SELFTEST_OK`, `Ran 9 tests ... OK`.
- `bash -lc 'for f in install/install-tcp-accel.sh install/install-client-agent.sh install/install-slave.sh install/install-bond-sidecar.sh; do bash -n "$f" || exit 1; done'` -> exit 0.
- `php -l master/src/Bootstrap.php; php -l master/src/Controllers/ArtifactController.php; php -l master/src/Support/LeigodOnlyArtifactSourceBuildMap.php` -> no syntax errors.
- `python -m py_compile slave-agent/leigod_tcp_accel_bridge.py tools/tcp-accel/tcp_accel.py` -> exit 0.
- Existing artifact/helper regression tests passed: `leigod_forward_helper_artifact_distribution_test.php`, `artifact_versions_manifest_consistency_test.php`, `artifact_versions_manifest_route_test.php`, `artifact_source_build_provenance_guard_test.php`, plus selected client/slave helper pytest tests.

Known baseline failures still present in the broader client-agent subset and not introduced by this change:

- `client-agent/tests/test_uot_bond_runtime.py::UotBondRuntimeTests::test_feedback_scheduler_requires_native_runtime_before_socket_setup`.
- `client-agent/tests/test_mptcp_bond_regressions.py::BondAndMptcpRegressionTests::test_bond_udp_uot_sidepath_renders_tcp_primary_and_udp_aux_unit`.

## Pilot activation notes

1. Sidecar: write `/etc/leigod/tcp-accel/server.env`, then enable `leigod-tcp-accel-server.service` on the sidecar host.
2. Slave: write `/etc/leigod/tcp-accel/bridge-<name>.json` with listeners in `7000-9000`, then enable `leigod-tcp-accel-bridge@<name>.service` on the slave.
3. Client: write `/etc/leigod/tcp-accel/client-<name>.json` where `remotes` are slave bridge listeners only, then enable `leigod-tcp-accel-client@<name>.service` on the client.
4. Rollback: disable the tcp-accel units and restore the original listener; no production listener is changed by installation alone.

## Live FRA pilot update - 2026-08-11 01:42 Asia/Taipei

- New FRA sidecar point `frankfurt-fra1` (`sidecar_points.id=7`) is on `89.144.30.80`; panel status `ok`.
- Forward `121` remains `listen_port=5201 -> 89.144.30.119:5201`, `forward_route_id=11`, `forward_combination_id=9`, `bond_sidecar_point_id=7`.
- Installed runtime hash on sidecar, all three slave bridges, and `sh36` client: `74bc214921365b84dca68110272effa7671087b0b5286fabfb3bd40a620376dc`.
- Slave bridge listeners are `101.133.142.65:8230`, `47.116.134.3:8230`, `111.229.217.7:8230`; all are inside the required `7000-9000` range.
- `sh36` now has tcp-accel listening on both `0.0.0.0:5201` and pilot `0.0.0.0:15201`; the previous `leikwan-client-121.service` was neutralized with a systemd drop-in so it no longer owns port `5201`.
- 30s reverse iperf3 (`iperf3 -R`) evidence from `sh36`:
  - Direct `89.144.30.119:5201`: receive `69.369 Mbps`, steady average `76.619 Mbps`, retransmits `10492`.
  - New leigod/tcp-accel `127.0.0.1:5201`: receive `288.484 Mbps`, steady average `287.270 Mbps`, steady min `199.964 Mbps`, retransmits `2268`.
- Normal upload direction is not the win condition for this pilot yet: a 10s upload through `127.0.0.1:5201` delivered only about `4.699 Mbps` received at the far side while direct path fluctuated. Keep using/recommending this pilot for reverse/download-style TCP acceleration unless upload behavior is separately reworked.
