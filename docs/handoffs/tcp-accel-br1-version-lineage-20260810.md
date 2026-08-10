# BR1 tcp-accel Local Version Lineage - 2026-08-10

This file was generated before changing existing client/slave/sidecar integration logic. It records local branch/worktree inputs and the selection rule used for this additive tcp-accel pilot. No credentials are included.

## Active worktree

- path: `C:\Users\frankniubi\Downloads\leikwan-br1\.worktrees\tcp-accel-pilot-20260810`
- branch: `codex/leigod-tcp-accel-pilot`
- HEAD: `813392e`
- base: `master` at `813392e`

## Candidate branch verdicts

| Candidate | HEAD | Latest commit | Verdict |
| --- | --- | --- | --- |
| `master` | `813392e` | 2026-08-09 17:46:53 +0800 813392e chore: sync slave-agent artifact and bump version to 2026.08.09.1 | authoritative baseline for this pilot |
| `codex/sidecar-shared-wg-peers` | `127943f` | 2026-08-07 11:16:06 +0800 127943f build(bond): publish 0.1.41 local-only runtime | newer local role/runtime lineage; compare selectively, do not whole-branch merge |
| `codex/master-hybrid-runtime-20260807` | `db6a731` | 2026-08-07 18:18:26 +0800 db6a731 docs: define unordered WireGuard datagram bond | newer local role/runtime lineage; compare selectively, do not whole-branch merge |
| `codex/leigod-unordered-dgram-bond-20260807` | `de63851` | 2026-08-08 17:05:56 +0800 de63851 fix(uot): only penalize legs that close with an error | newer local role/runtime lineage; compare selectively, do not whole-branch merge |
| `codex/uot-feedback-scheduler-20260808` | `7a2cb2e` | 2026-08-09 16:52:15 +0800 7a2cb2e fix(uot): apply 7 scheduler stability fixes + SOCKS5 leg support from leigod-forward | already mostly ancestor of master for current agent/runtime files; scheduler evidence not a prerequisite for tcp-accel pilot |
| `codex/master-local-only-leigod-20260807` | `c771b61` | 2026-08-07 11:43:15 +0800 c771b61 feat(leigod): publish linux hybrid runtime contract | older/specialized lineage; use only if a concrete tested fix is identified |

## Relevant file hashes by candidate

### `master`

| File | sha256/first12 | last touching commit |
| --- | --- | --- |
| `client-agent/client_agent.py` | `8f0138eb1f21` | 2026-08-08 21:11:58 +0800 8f0ec31 feat(uot): add feedback-driven scheduler |
| `slave-agent/slave_agent.py` | `ee84250b0b0d` | 2026-08-09 17:33:09 +0800 49b20dd Merge branch 'master' into frontend-skin-v2 |
| `sidecar-agent/sidecar_agent.py` | `989aa1b2a821` | 2026-08-08 21:11:58 +0800 8f0ec31 feat(uot): add feedback-driven scheduler |
| `install/install-client-agent.sh` | `418aa2d06935` | 2026-08-08 21:11:58 +0800 8f0ec31 feat(uot): add feedback-driven scheduler |
| `install/install-slave.sh` | `060fc3094c55` | 2026-07-27 16:58:19 +0800 833646e feat: deliver leigod race runtimes atomically |
| `install/install-bond-sidecar.sh` | `323b5ebd6383` | 2026-08-08 21:11:58 +0800 8f0ec31 feat(uot): add feedback-driven scheduler |
| `client-agent/install_forward_runtime.py` | `3d91c8ce9fbd` | 2026-08-08 21:11:58 +0800 8f0ec31 feat(uot): add feedback-driven scheduler |
| `client-agent/leikwan_mptcp_client.py` | `26e4114873d3` | 2026-06-06 00:11:42 +0800 68b7e7f fix(leigod): pin overlay WG MTU so MPTCP bulk data fits the leigod path |
| `client-agent/leikwan_uot.py` | `5f581087c800` | 2026-05-29 15:36:09 +0800 85078de feat: snapshot slave-preconnect workflow |
| `client-agent/leikwan_uot_bond.py` | `c35018a952d3` | 2026-08-09 16:52:15 +0800 7a2cb2e fix(uot): apply 7 scheduler stability fixes + SOCKS5 leg support from leigod-forward |
| `slave-agent/leigod_forward_relay.py` | `1b96fc73623a` | 2026-07-27 16:41:49 +0800 a2a7975 fix: isolate leigod relay protocol health |
| `sidecar-agent/leikwan_mptcp_sidecar.py` | `d1cb2beb23d2` | 2026-05-17 22:28:16 +0800 c760834 feat: automate self-leg runtime selection |

### `codex/sidecar-shared-wg-peers`

| File | sha256/first12 | last touching commit |
| --- | --- | --- |
| `client-agent/client_agent.py` | `f5b0e2965a59` | 2026-08-06 15:51:21 +0800 48284ae feat(client): reconcile and validate hybrid pathd leases |
| `slave-agent/slave_agent.py` | `48ad5d414dae` | 2026-08-06 05:42:40 +0800 0bde14c build(pathd): publish pinned linux hybrid runtime |
| `sidecar-agent/sidecar_agent.py` | `3b8c0983ba4e` | 2026-08-05 19:25:25 +0800 42e3844 feat(sidecar): apply adaptive bond policy to reverse traffic |
| `install/install-client-agent.sh` | `f780d1630c2a` | 2026-08-06 05:42:40 +0800 0bde14c build(pathd): publish pinned linux hybrid runtime |
| `install/install-slave.sh` | `91fcc36b35fe` | 2026-08-05 19:10:56 +0800 d90e887 feat(slave): install adaptive bond artifact |
| `install/install-bond-sidecar.sh` | `68e23937a6bc` | 2026-06-03 12:55:16 +0800 aacfdcb feat(lguot): CPU-efficient UoT carrier — source, binary, artifact serving |
| `client-agent/install_forward_runtime.py` | `88594240c92d` | 2026-08-06 05:24:22 +0800 fdfd5fe feat(client): reconcile the linux path daemon |
| `client-agent/leikwan_mptcp_client.py` | `26e4114873d3` | 2026-06-06 00:11:42 +0800 68b7e7f fix(leigod): pin overlay WG MTU so MPTCP bulk data fits the leigod path |
| `client-agent/leikwan_uot.py` | `5f581087c800` | 2026-05-29 15:36:09 +0800 85078de feat: snapshot slave-preconnect workflow |
| `client-agent/leikwan_uot_bond.py` | `51a9bbd4b916` | 2026-05-01 15:32:37 +0800 435ab78 chore: consolidate latest leikwan snapshot |
| `slave-agent/leigod_forward_relay.py` | `cdd10403d951` | 2026-08-06 04:42:58 +0800 07a2edc feat(slave): publish flat hybrid leigod relays |
| `sidecar-agent/leikwan_mptcp_sidecar.py` | `d1cb2beb23d2` | 2026-05-17 22:28:16 +0800 c760834 feat: automate self-leg runtime selection |

### `codex/master-hybrid-runtime-20260807`

| File | sha256/first12 | last touching commit |
| --- | --- | --- |
| `client-agent/client_agent.py` | `f5b0e2965a59` | 2026-08-06 15:51:21 +0800 48284ae feat(client): reconcile and validate hybrid pathd leases |
| `slave-agent/slave_agent.py` | `127990bff6e5` | 2026-08-07 15:56:47 +0800 62c71de feat(bond): negotiate startup warmup and wire fast-start |
| `sidecar-agent/sidecar_agent.py` | `455889d93737` | 2026-08-07 15:56:47 +0800 62c71de feat(bond): negotiate startup warmup and wire fast-start |
| `install/install-client-agent.sh` | `f780d1630c2a` | 2026-08-06 05:42:40 +0800 0bde14c build(pathd): publish pinned linux hybrid runtime |
| `install/install-slave.sh` | `91fcc36b35fe` | 2026-08-05 19:10:56 +0800 d90e887 feat(slave): install adaptive bond artifact |
| `install/install-bond-sidecar.sh` | `68e23937a6bc` | 2026-06-03 12:55:16 +0800 aacfdcb feat(lguot): CPU-efficient UoT carrier — source, binary, artifact serving |
| `client-agent/install_forward_runtime.py` | `88594240c92d` | 2026-08-06 05:24:22 +0800 fdfd5fe feat(client): reconcile the linux path daemon |
| `client-agent/leikwan_mptcp_client.py` | `26e4114873d3` | 2026-06-06 00:11:42 +0800 68b7e7f fix(leigod): pin overlay WG MTU so MPTCP bulk data fits the leigod path |
| `client-agent/leikwan_uot.py` | `5f581087c800` | 2026-05-29 15:36:09 +0800 85078de feat: snapshot slave-preconnect workflow |
| `client-agent/leikwan_uot_bond.py` | `51a9bbd4b916` | 2026-05-01 15:32:37 +0800 435ab78 chore: consolidate latest leikwan snapshot |
| `slave-agent/leigod_forward_relay.py` | `cdd10403d951` | 2026-08-06 04:42:58 +0800 07a2edc feat(slave): publish flat hybrid leigod relays |
| `sidecar-agent/leikwan_mptcp_sidecar.py` | `d1cb2beb23d2` | 2026-05-17 22:28:16 +0800 c760834 feat: automate self-leg runtime selection |

### `codex/leigod-unordered-dgram-bond-20260807`

| File | sha256/first12 | last touching commit |
| --- | --- | --- |
| `client-agent/client_agent.py` | `f5b0e2965a59` | 2026-08-06 15:51:21 +0800 48284ae feat(client): reconcile and validate hybrid pathd leases |
| `slave-agent/slave_agent.py` | `127990bff6e5` | 2026-08-07 15:56:47 +0800 62c71de feat(bond): negotiate startup warmup and wire fast-start |
| `sidecar-agent/sidecar_agent.py` | `455889d93737` | 2026-08-07 15:56:47 +0800 62c71de feat(bond): negotiate startup warmup and wire fast-start |
| `install/install-client-agent.sh` | `f780d1630c2a` | 2026-08-06 05:42:40 +0800 0bde14c build(pathd): publish pinned linux hybrid runtime |
| `install/install-slave.sh` | `91fcc36b35fe` | 2026-08-05 19:10:56 +0800 d90e887 feat(slave): install adaptive bond artifact |
| `install/install-bond-sidecar.sh` | `68e23937a6bc` | 2026-06-03 12:55:16 +0800 aacfdcb feat(lguot): CPU-efficient UoT carrier — source, binary, artifact serving |
| `client-agent/install_forward_runtime.py` | `88594240c92d` | 2026-08-06 05:24:22 +0800 fdfd5fe feat(client): reconcile the linux path daemon |
| `client-agent/leikwan_mptcp_client.py` | `26e4114873d3` | 2026-06-06 00:11:42 +0800 68b7e7f fix(leigod): pin overlay WG MTU so MPTCP bulk data fits the leigod path |
| `client-agent/leikwan_uot.py` | `5f581087c800` | 2026-05-29 15:36:09 +0800 85078de feat: snapshot slave-preconnect workflow |
| `client-agent/leikwan_uot_bond.py` | `51a9bbd4b916` | 2026-05-01 15:32:37 +0800 435ab78 chore: consolidate latest leikwan snapshot |
| `slave-agent/leigod_forward_relay.py` | `cdd10403d951` | 2026-08-06 04:42:58 +0800 07a2edc feat(slave): publish flat hybrid leigod relays |
| `sidecar-agent/leikwan_mptcp_sidecar.py` | `d1cb2beb23d2` | 2026-05-17 22:28:16 +0800 c760834 feat: automate self-leg runtime selection |

### `codex/uot-feedback-scheduler-20260808`

| File | sha256/first12 | last touching commit |
| --- | --- | --- |
| `client-agent/client_agent.py` | `8f0138eb1f21` | 2026-08-08 21:11:58 +0800 8f0ec31 feat(uot): add feedback-driven scheduler |
| `slave-agent/slave_agent.py` | `b9d904257990` | 2026-08-04 14:27:52 +0800 4460f70 fix(slave): reap orphan sidecar carriers |
| `sidecar-agent/sidecar_agent.py` | `989aa1b2a821` | 2026-08-08 21:11:58 +0800 8f0ec31 feat(uot): add feedback-driven scheduler |
| `install/install-client-agent.sh` | `418aa2d06935` | 2026-08-08 21:11:58 +0800 8f0ec31 feat(uot): add feedback-driven scheduler |
| `install/install-slave.sh` | `060fc3094c55` | 2026-07-27 16:58:19 +0800 833646e feat: deliver leigod race runtimes atomically |
| `install/install-bond-sidecar.sh` | `323b5ebd6383` | 2026-08-08 21:11:58 +0800 8f0ec31 feat(uot): add feedback-driven scheduler |
| `client-agent/install_forward_runtime.py` | `3d91c8ce9fbd` | 2026-08-08 21:11:58 +0800 8f0ec31 feat(uot): add feedback-driven scheduler |
| `client-agent/leikwan_mptcp_client.py` | `26e4114873d3` | 2026-06-06 00:11:42 +0800 68b7e7f fix(leigod): pin overlay WG MTU so MPTCP bulk data fits the leigod path |
| `client-agent/leikwan_uot.py` | `5f581087c800` | 2026-05-29 15:36:09 +0800 85078de feat: snapshot slave-preconnect workflow |
| `client-agent/leikwan_uot_bond.py` | `c35018a952d3` | 2026-08-09 16:52:15 +0800 7a2cb2e fix(uot): apply 7 scheduler stability fixes + SOCKS5 leg support from leigod-forward |
| `slave-agent/leigod_forward_relay.py` | `1b96fc73623a` | 2026-07-27 16:41:49 +0800 a2a7975 fix: isolate leigod relay protocol health |
| `sidecar-agent/leikwan_mptcp_sidecar.py` | `d1cb2beb23d2` | 2026-05-17 22:28:16 +0800 c760834 feat: automate self-leg runtime selection |

### `codex/master-local-only-leigod-20260807`

| File | sha256/first12 | last touching commit |
| --- | --- | --- |
| `client-agent/client_agent.py` | `4324a51b5c65` | 2026-08-04 19:19:00 +0800 27168d5 build(linux): publish sidecar frontend runtime artifacts |
| `slave-agent/slave_agent.py` | `988acb61ab69` | 2026-08-07 11:43:15 +0800 be0bc29 feat(leigod): fence managed line execution |
| `sidecar-agent/sidecar_agent.py` | `5e07d54a8394` | 2026-08-04 13:31:28 +0800 eca69f9 fix: protect shared wireguard lifecycle |
| `install/install-client-agent.sh` | `3e2a9a676b55` | 2026-07-28 00:48:30 +0800 e37575d fix: record installer hash for piped setup |
| `install/install-slave.sh` | `060fc3094c55` | 2026-07-27 16:58:19 +0800 833646e feat: deliver leigod race runtimes atomically |
| `install/install-bond-sidecar.sh` | `68e23937a6bc` | 2026-06-03 12:55:16 +0800 aacfdcb feat(lguot): CPU-efficient UoT carrier — source, binary, artifact serving |
| `client-agent/install_forward_runtime.py` | `95b9da49c3d5` | 2026-08-04 20:10:07 +0800 06ed04c fix(client): drop unsupported single leg flag |
| `client-agent/leikwan_mptcp_client.py` | `26e4114873d3` | 2026-06-06 00:11:42 +0800 68b7e7f fix(leigod): pin overlay WG MTU so MPTCP bulk data fits the leigod path |
| `client-agent/leikwan_uot.py` | `5f581087c800` | 2026-05-29 15:36:09 +0800 85078de feat: snapshot slave-preconnect workflow |
| `client-agent/leikwan_uot_bond.py` | `51a9bbd4b916` | 2026-05-01 15:32:37 +0800 435ab78 chore: consolidate latest leikwan snapshot |
| `slave-agent/leigod_forward_relay.py` | `1b96fc73623a` | 2026-07-27 16:41:49 +0800 a2a7975 fix: isolate leigod relay protocol health |
| `sidecar-agent/leikwan_mptcp_sidecar.py` | `d1cb2beb23d2` | 2026-05-17 22:28:16 +0800 c760834 feat: automate self-leg runtime selection |

## tcp-accel source authority

- source directory: `C:\Users\frankniubi\Downloads\leigod-forward\tools\tcp-accel`
- `tcp_accel.py` sha256=`bbd3be052b85b72c52786bc530349c0b815552a245ba5412f002e1aa0a692c47` size=`44536`
- `install_debian12.sh` sha256=`3dd66919ee1fd4fb47b9a51184b3f665a7903264c53613cc3c33ac8900dc08c1` size=`5290`
- `selftest_debian12.sh` sha256=`3dc4f1d05f93f6b059a385b9bb8dbc4d30cd79c0d490373d59b2e30e356941fa` size=`1227`
- `test_tcp_accel.py` sha256=`ffbec8002f69c8842f67b3e4df57aadd37730d51968bc27d0be8a6ba12454c8d` size=`11445`
- `README.md` sha256=`e4b6759337ef9e024c2859a4b8f0d6e93b3946e17cee6c1800b4bf2c87411a8e` size=`7095`
- `examples/bridge.json` sha256=`6d693d6cbb8ce8f6c5286d2e76948f01c11aadf0413e05b4e6576b2698cb63dc` size=`366`
- `examples/client-through-bridge.json` sha256=`7c7deefee15a470c751564466853880abf4133dfb2b7904742eca09085c1a6d9` size=`641`

## Selection rule

- Start from local `master` and add tcp-accel as a new pilot capability.
- Do not replace current BR1 client/slave/sidecar agents with larger local branches without a direct failing test and evidence gate.
- For this implementation, existing role installer scripts are changed only to preinstall tcp-accel artifacts/units; runtime activation remains opt-in.
- Slave bridge listener configs must validate ports in `7000-9000` and retain `client -> slave -> sidecar` topology.


## Implemented pilot artifact identity

| Artifact | BR1 path | BR1 sha256 | Upstream/source sha256 | Decision |
| --- | --- | --- | --- | --- |
| tcp_accel.py | `tools/tcp-accel/tcp_accel.py` | `bbd3be052b85b72c52786bc530349c0b815552a245ba5412f002e1aa0a692c47` | `bbd3be052b85b72c52786bc530349c0b815552a245ba5412f002e1aa0a692c47` | exact copied runtime |
| install_debian12.sh | `tools/tcp-accel/install_debian12.sh` | `533de9340137a75adef295c2c49b823a267d3396fc946aaa15cf120e36fadd71` | `3dd66919ee1fd4fb47b9a51184b3f665a7903264c53613cc3c33ac8900dc08c1` | adapted to use BR1 `slave-agent/leigod_tcp_accel_bridge.py` instead of leigod-forward `scripts/leigod_bond.py` |
| BR1 installer | `install/install-tcp-accel.sh` | `8a6752604bac264374a15765aa23f2477e729ea719967f9e7400eb5e9350286a` | n/a | artifact-based Debian 12 role installer |
_45c9c5c0032bd505fc6a79b517e8168f99bca57b6142f2d326138e3fd90e38` | n/a | bridge-only helper, no rule manager |
