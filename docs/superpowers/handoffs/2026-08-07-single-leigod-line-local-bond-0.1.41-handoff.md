# Single LeiGod Line Local Bond 0.1.41 Implementation and Release Handoff

Date: 2026-08-07 (Asia/Taipei)

Status: implementation complete, release gates passed, committed, and release artifacts synchronized. Production feature gates remain intentionally disabled.

Sensitive credentials, tokens, private keys, and raw session material are intentionally omitted.

## 1. Objective and final conclusion

The source requirement was:

- reuse one physical LeiGod line several times, such as `693,693` or `693,693,693`;
- create several local Linux bond legs that all enter that same local LeiGod SOCKS line;
- combine those legs into one ordered TCP stream;
- improve single-application throughput without materially worsening latency, jitter, or reliability.

The implementation direction was already present in the Linux hybrid branch, but the real client pull path could not activate a local-only group. This release fixes that activation boundary and publishes `leikwan-bond` and `leikwan-pathd` `0.1.41`.

The local-only runtime is now representable and validated in source and published artifacts. It is not activated on existing production clients because all four feature gates remain false and the lease/profile tables remain empty.

## 2. Root cause

The repeated local lane representation itself was not the primary defect. A single catalog line with `max_parallel_lanes=3` was already expanded into distinct logical route IDs such as:

```text
693
1006932
1006933
```

Each logical route creates an independent TCP connection to the same loopback LeiGod SOCKS authority.

The actual blockers were:

1. `ProvisioningService::assignedForwardConfigsForClient()` discarded a forward when `endpoints=[]` before the Linux hybrid preflight could approve a local-only candidate.
2. `LeigodHybridRuntimeService::forForward()` required an eligible slave-side group and returned `null` when only repeated local lanes existed.
3. `pathd.rs` required at least two execution groups, so one physical local line with several logical lanes was rejected.
4. The earlier tests called the hybrid service directly or used a pre-existing lease, so they did not prove the real cold-start client pull path.
5. The old `leigod-forward` helper generated `--default-strategy latency_best`, which underused the repeated lanes for bulk traffic.

## 3. Implemented boundary

### 3.1 Panel and lease behavior

The panel now permits a local-only hybrid runtime only when all of these conditions hold:

- exactly one approved local physical LeiGod lease is present;
- the line expands to two through four logical route IDs;
- route IDs are unique;
- lease owner, generation, fence, profile, and physical-line authority are valid;
- a local/node authority conflict on the same physical line fails closed;
- a normal endpointless forward that is not approved by hybrid preflight is still discarded.

The cold-start path is covered:

```text
client pull
  -> endpointless forward survives only after local-only preflight approval
  -> broker creates a pending fenced lease
  -> health/fence activation authorizes the lease
  -> next pull emits the pathd runtime with repeated local route IDs
```

### 3.2 `leikwan-pathd` behavior

A single execution group is accepted only when:

- every leg is loopback SOCKS;
- every leg belongs to the same local physical LeiGod line;
- there are at least two unique logical routes;
- the configured lane cap is not exceeded;
- lease, fence, route, and authority checks all pass.

The following remain rejected:

- direct TCP legs;
- a single lane;
- multiple local physical lines disguised as one group;
- duplicate route IDs;
- expired or mismatched lease/fence generations;
- local/node authority conflicts.

### 3.3 Operator tools

`leigod-forward/scripts/leigod_bond.py` now renders `speed_max` as the default bond strategy while retaining latency-first handling for interactive traffic.

`leigod-forward/scripts/lat_probe.py` now supports `--json` and reports:

- p50, p95, p99, and maximum latency;
- sent, received, timeout, and effective-loss counts;
- timeout and loss percentages.

## 4. Source integration and commits

The first local-only implementation commits were:

```text
fcd4c0f fix(leigod): allow local-only parallel line runtime
127943f build(bond): publish 0.1.41 local-only runtime
```

The authoritative baseline is `master`. A partial replay of only the hybrid commits was rejected because `b9fc803` depends on earlier fixed-endpoint and adaptive bond fields that are not present in plain `master`.

The complete dependency history was merged into a clean branch from `master`:

```text
branch: codex/master-hybrid-runtime-20260807
commit: 90dcc654c21713084b47f637494c62f5bb697675
parent 1: 11550550b3c6c36d0878443c79dd5814aa260a48
parent 2: 127943ff18be2d92a7b54dc253672127192d248c
subject: merge: integrate Linux hybrid LeiGod runtime
```

The sole merge conflict was `wstunnel-main/leikwan-bond/src/session.rs`. The resolved file is byte-identical to the validated `127943f` version, which already contains both master-equivalent ACK failover fixes and TCP_NODELAY handling.

The separate `leigod-forward` directory has no `.git` metadata. Its script and manifest changes therefore cannot be committed there; the exact local and live hashes are recorded below.

## 5. Release verification

The master-based integration worktree passed the repository release gate:

```text
10 stages passed in 649.6s
```

| Stage | Result | Time |
| --- | --- | ---: |
| git diff check | passed | 0.4s |
| focused PHP hybrid tests | passed | 7.9s |
| all tracked PHP lint | passed | 19.3s |
| focused Python suites | passed | 2.0s |
| broad client and slave Python suites | passed | 190.5s |
| Rust `leikwan-bond` tests | passed | 334.9s |
| Rust `leikwan-ingress` tests | passed | 50.8s |
| real-process Linux hybrid integration | passed | 36.5s |
| artifact hash and source mirrors | passed | 6.3s |
| Windows and Android contract regressions | passed | 1.0s |

Additional tool verification:

```text
python -m unittest tests.test_leigod_forward -v
75 tests passed in 119.5s
```

After the local artifact manifest update, both Python files compiled from source and the JSON manifest parsed with 18 exact entries.

## 6. Published 0.1.41 artifacts

| Artifact | Version | Bytes | SHA256 |
| --- | --- | ---: | --- |
| `leikwan-bond_linux_amd64` | `0.1.41` | 9,881,608 | `a7ec41102dfcb0c446b6d7c8fe412abb84270d34b26512b871a9aa2937ea62be` |
| `leikwan-pathd_linux_amd64` | `0.1.41` | 9,398,488 | `5d6f5f2ab8d514508793a95676afec775bb89b9d201aaf975c278ce3f2ef6a08` |

Both are x86-64 musl static PIE executables. The committed manifest, binary bytes, source versions, and CLI `--version` output agree.

## 7. Controlled same-line A/B evidence

The temporary A/B environment used one real LeiGod line repeatedly. Production route `20013` was not replaced during the experiment.

### 7.1 Latency

| Case | p50 | p95 | Effective loss |
| --- | ---: | ---: | ---: |
| fixed direct `693` | 140.986 ms | 141.880 ms | 0% |
| one local bond lane | - | 143.815 ms | 0% |
| repeated two lanes | - | 139.501 ms | 0% |
| repeated three lanes | - | 139.785 ms | 0% |

The repeated two- and three-lane cases did not exceed the accepted fixed-line p95 in that comparison window.

### 7.2 Throughput

In the simultaneous comparison window:

| Case | Aggregate throughput |
| --- | ---: |
| fixed direct `693` | 141.930 Mbit/s |
| repeated two lanes | 288.615 Mbit/s |
| repeated three lanes | 353.300 Mbit/s |

Directional trials were variable, as expected for one shared physical line. The most relevant strategy comparison showed `speed_max` reaching 92.998 Mbit/s forward where the comparable `latency_best` run reached 48.997 Mbit/s. A separate two-lane `latency_best` reverse run reached 321.775 Mbit/s.

### 7.3 Failure behavior

- controlled one-leg interruption: 60/60 probes succeeded;
- interruption p95: 146.326 ms;
- one isolated maximum outlier: 466.913 ms;
- bulk reverse traffic during the fault: 67.828 Mbit/s with no zero-throughput intervals.

All temporary listeners, bond rules, and test services were removed after the experiment.

## 8. Panel deployment

The release was generated with `git archive` from committed `90dcc65`. The archive contained only 17 allowlisted files: 16 runtime targets plus the candidate `artifacts/versions.json` used as the source for five manifest keys.

Only these runtime paths were replaced:

```text
master/src/Bootstrap.php
master/src/Controllers/AdminController.php
master/src/Controllers/ArtifactController.php
master/src/Controllers/ClientAgentController.php
master/src/Support/LeigodHybridRuntimeService.php
master/src/Support/LeigodLineCatalogService.php
master/src/Support/LeigodOnlyArtifactConsistencyService.php
master/src/Support/LeigodOnlyArtifactSourceBuildMap.php
master/src/Support/ProvisioningService.php
client-agent/client_agent.py
artifacts/client-agent.py
client-agent/install_forward_runtime.py
artifacts/install-forward-runtime.py
install/install-client-agent.sh
artifacts/wstunnel/leikwan-bond_linux_amd64
artifacts/wstunnel/leikwan-pathd_linux_amd64
```

The following already-compatible files were protected by SHA preconditions but were not rewritten:

```text
master/cli/seed-leigod-execution-leases.php
master/migrations/20260806_add_linux_hybrid_leigod_aggregation.sql
master/src/Support/ClientConfigRevisionService.php
master/src/Support/LeigodExecutionLeaseService.php
```

Successful backup:

```text
/root/codex-backups/leikwan-v3-hybrid-0.1.41-20260807T042434Z
```

The backup contains a validated file archive, metadata, the original `.env`, and a validated transactional MySQL dump.

Manifest behavior:

- only `client_agent`, `client_agent_installer`, `install_forward_runtime`, `leikwan_bond_linux_amd64`, and `leikwan_pathd_linux_amd64` were merged;
- manifest SHA changed from `2347e100aa65b1ef3b34af09e5467aaf00b9eb403c8f681efdef2d19f2db4797` to `9563623c0c20a5dd601e5b4a72155e5c2cfc78484de617ea5bb5fc995447c1e7`;
- the non-target canonical hash remained `bfc0f40ad43144c158305b73fc42b48f4de0f9248c222351bcaa5c826f214aed`.

Post-deploy verification:

- origin and public `/api/health`, `/login`, and `/artifacts/versions.json`: HTTP 200;
- public TLS validation without `-k`: HTTP 200;
- fixed and cache-busting artifact URLs: exact SHA256 matches;
- bond and pathd Range requests: HTTP 206;
- `client-manifest.json` publishes the new client agent, installer, bond, and pathd pins;
- client-agent and install-runtime source/mirror pairs are byte-identical;
- `.env` SHA remained `7d92674c8b12b373384ed7805730a3a035d33cb807368f17bba639db9543de30`.

Runtime state remained:

```text
MIGRATIONS_PENDING=0
MIGRATIONS_APPLIED=74
LEIGOD_EXECUTION_LEASES_ENABLED=false
LEIGOD_LINUX_HYBRID_ENABLED=false
LEIGOD_CLIENT_EXECUTION_BROKER_ENABLED=false
CLIENT_CONFIG_WATCH_ENABLED=false
leigod_execution_leases rows=0
leigod_egress_profiles rows=0
```

No service restart or migration was performed.

## 9. `leigod-forward` synchronization

### 9.1 Source host

Successful backup:

```text
/root/codex-backups/leigod-forward-bond-20260807T042829Z
```

| File | Old SHA256 | New SHA256 |
| --- | --- | --- |
| `/opt/leigod/scripts/leigod_bond.py` | `3229beaf686bfe46df6fb25c7dc7fe815a19fbc17714e033ace570c20e13deb6` | `a44d70dd62346bfc3c168e67cbe9782135e32a8d24741f74b64ec0514f5947ee` |
| `/opt/leigod/scripts/lat_probe.py` | `81a9dae24bce74b264393f1349bd62541839e962bf620b5deb7964e96038e47e` | `5a4ef4f60929e55277443f7dd37748da3566d369b0dea032aa7fec718b64e4a9` |

Independent verification after deployment:

```text
lgfwd.service=active
leigod@693.service=active
leigod-bond@3.service=inactive
route id=3 tcp_exit=693
route id=3 bond_legs=null
```

No running service was restarted.

### 9.2 Public artifact store

Successful backup:

```text
/root/codex-backups/leigod-forward-artifacts-20260807T043347Z
```

Published fixed URLs:

```text
https://source.mcbar.cc/artifact-god/leigod_bond.py
https://source.mcbar.cc/artifact-god/lat_probe.py
https://source.mcbar.cc/artifact-god/manifest.json
https://source.mcbar.cc/artifact-god/artifact-manifest.json
```

Both manifest files are byte-identical:

```text
bytes: 3554
SHA256: 0964668722c68931f159d52fa2740df312e8058611e3c62754b8d00c2e88cb13
file count: 18
```

The non-target manifest canonical hash remained `abdd2feedf3db6621eed5a036053aeb60254a036d8cb1139fe43c0683f1122e4`.

External Windows-side downloads independently matched:

```text
leigod_bond.py  30587 bytes  a44d70dd62346bfc3c168e67cbe9782135e32a8d24741f74b64ec0514f5947ee
lat_probe.py      6764 bytes  5a4ef4f60929e55277443f7dd37748da3566d369b0dea032aa7fec718b64e4a9
```

The local `leigod-forward/artifact-manifest.json` has the same 18 rows and exact script hashes.

## 10. Deployment failure handling evidence

The guarded process stopped safely on three issues before the final successful panel release:

1. A Base64 delimiter bug failed before mutation; production hashes stayed unchanged.
2. A post-install executable-mode check failed because public binaries intentionally retained mode `0644`; all 16 files and the manifest were restored from backup, then independently compared to the old hashes.
3. One SSH key-exchange reset left only a partial `/tmp` upload; the next upload truncated it, and archive SHA/gzip checks prevented partial-package use.

The first public artifact attempt also rolled back because the storage host could not resolve its own public hostname. Public verification was changed to use HTTPS SNI with a current resolved edge address; the final transaction then passed fixed and cache-busting URL checks. Independent Windows downloads passed afterward.

No failed attempt left a mixed runtime or changed feature gate.

## 11. Activation boundary and topology

This release publishes the implementation but deliberately does not enable it for production clients.

Before activation, an operator must separately decide to:

1. populate and validate the intended LeiGod egress profile;
2. seed or broker the fenced execution lease;
3. enable the four gates in the approved order;
4. canary one Linux client and verify its generated pathd lease/runtime;
5. retain a fast rollback to the current fixed `693` route.

The source production route remains fixed `693`; the active source/target bond binaries used by that route were not replaced, and the bond unit remains inactive.

The topology constraint remains:

```text
client -> slave -> sidecar
```

For the local-only group, each logical leg enters the client's local loopback LeiGod SOCKS process. It does not introduce direct client-to-sidecar connectivity. Any remote sidecar remains reached through the approved slave hop and terminates/reassembles the bond protocol before the target service.

## 12. Final operator state

- source code: committed and fully gated on the master-based integration branch;
- panel source/artifacts: synchronized to `0.1.41` and independently verified;
- `leigod-forward` source scripts: synchronized and syntax-checked;
- public tool artifacts and manifests: synchronized and externally hash-verified;
- current fixed route: unchanged and healthy;
- temporary A/B services: removed;
- production feature gates: intentionally disabled.
