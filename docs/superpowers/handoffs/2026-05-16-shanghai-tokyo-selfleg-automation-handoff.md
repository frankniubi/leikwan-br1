# 2026-05-16 Shanghai -> Tokyo Self-Leg / Multi-Slave-Group Automation Handoff

> Local-only handoff. Contains sensitive credentials and live environment details. Do **not** commit or paste outside this workspace unless explicitly intended.

## Objective

Finish Shanghai -> Tokyo self-leg / client-as-a-slave behavior and multiple-slave-group forward-combination behavior end-to-end so the real client uses the best locally-chosen CF/self-leg path when it beats slave helpers, while preserving helper-group functionality and achieving a stable fast working `forward 47` runtime.

## Current conclusion in one paragraph

The self-leg architecture is now largely in place: panel can publish a self-leg candidate, client can locally choose a self-leg CF edge, and multiple-slave-group helper metadata is still preserved. The remaining blocker is not missing logic anymore but the **quality and ranking of the self-leg CF candidate set versus the real Shanghai helper path** on the live client `114.80.33.50`. The live client now receives a self-leg candidate set and can probe it, but the currently winning self-leg probe still measures worse than the best rented Shanghai helper (`~150ms` vs `68.9ms` in the latest real pull/probe snapshot), so runtime still correctly keeps the rented helper as primary and does not render `direct_legs` in the live `forward 47` MPTCP config.

---

## Sensitive access / credentials

### Panel
- Host: `root@10.0.10.196`
- Password: `Frank1212.`
- Repo path on host: `/www/wwwroot/v3.leikwanhost.com/leikwan-v3`

### Real client in scope
- Host: `root@114.80.33.50`
- Password: `Frank20021212.`

### Other client / local runtime info from `leikwan-v3.txt`
- Local Linux client: `root@172.21.255.189`
- Password: `Frank1212.`
- WSL root password: `Frank1212.`

### Sidecar
- SSH: `root@161.248.136.183:14950`
- Password: `GK@Sp7z%T!s4*w5P`
- Sidecar ingress mentioned in note: `161.248.136.183:14969`
- Original note says this ingress is intended for slave-side access path.

### Shanghai slaves
- `47.116.11.144` / `root` / `@XwDQ6H5mgMXbrGD`
- `47.116.134.3` / `root` / `RQRUbOkeh7ZpCkDa`
- `47.116.104.15` / `root` / `U3I+sVL^%IXyqMX0`
- `8.133.170.123` / `root` / `TnJKTGse+JMJFR%t`
- `101.133.142.65` / `root` / `b+z@Qk860a+6UsVW`

### Canton / Guangzhou slaves (left alone unless explicitly needed)
- `8.138.186.81` / `root` / `^XaL5MezM3pQ`
- `8.138.248.48` / `root` / `Ri*Qn7a=QwM6`

---

## High-level work already done

### 1. Panel / provisioning side
Implemented or partly implemented in source:
- forward-combination self-leg policy fields
- helper-group metadata propagation
- self-leg candidate publish shape
- self-leg websocket target tuple update toward `2053 + nrt1.nbwly.com`
- self-leg candidate-set propagation (`self_leg_cf_candidates`)

Key file:
- `master/src/Support/ProvisioningService.php`

### 2. Client side
Implemented in source:
- self-leg local candidate selection logic
- self-leg websocket-path probing
- local edge ranking helpers
- forward-specific self-leg CF best-IP override support
- panel-published self-leg candidate-set support
- self-leg MPTCP render support

Key files:
- `client-agent/client_agent.py`
- `client-agent/install_forward_runtime.py`
- `client-agent/leikwan_mptcp_client.py`

### 3. Live client recovery / validation work
Already verified earlier in this thread/work:
- updater loop issues were previously fixed enough that the client can pull configs and run diagnostics
- `forward 47` listener came back at one point
- later work moved from control-plane bugs to actual data-plane / self-leg selection problems

### 4. Multi-slave-group preservation
Still present in source / not intentionally regressed:
- `origin_group_key`
- `origin_group_order`
- `helper_group_cap`
- `forward_combination_helper_node_group_keys`

This means the multiple helper-group path still exists while self-leg work was added.

---

## Current important source state

### Panel source currently contains
In `master/src/Support/ProvisioningService.php`:
- `publishedSelfLegCfCandidates(...)`
- self-leg candidate publish includes:
  - `dial_host`
  - `dial_connect_ip`
  - `self_leg_cf_candidates`
  - `dial_port`
  - `cf_sni_host`
  - `target_sidecar_port`
  - `target_sidecar_bond_port`
  - `target_sidecar_mptcp_port`
- `buildForwardCandidatePool(...)` still includes rented helper candidates with:
  - `origin_group_key`
  - `origin_group_order`

### Client source currently contains
In `client-agent/client_agent.py`:
- `resolve_self_leg_connect_ip(...)`
- `expand_self_leg_cf_candidate_ips(...)`
- `self_leg_candidate_edge_latency_ms(...)`
- `probe_self_leg_websocket_path(...)`
- `self_leg_direct_cf_connect_probe(...)`

### Live panel host currently showed
When inspected from client pulls late in the session, the real client finally received:
```json
{
  "candidate_kind": "self_leg",
  "dial_host": "m-jp-4.scipnet.info",
  "dial_connect_ip": "",
  "self_leg_cf_candidates": [
    "104.16.0.3",
    "162.159.39.4",
    "162.159.39.7",
    "162.159.39.6",
    "162.159.39.29",
    "162.159.39.8"
  ],
  "dial_port": 2053,
  "cf_sni_host": "nrt1.nbwly.com",
  "path_prefix": "07289d1f5ed25399",
  "target_sidecar_port": 8899,
  "target_sidecar_mptcp_port": 8898
}
```

That was a major milestone: the live payload finally carried a multi-edge self-leg set instead of a single chosen IP.

---

## Latest verified local tests

### Passed latest
Run:
```bash
python -m unittest client-agent.tests.test_self_leg_best_ip_override client-agent.tests.test_highloss_self_leg_runtime_regression client-agent.tests.test_runtime_preference_mptcp_bond_fallback client-agent.tests.test_self_leg_candidate_policy -v
```
Result at latest successful run before budget wrap:
- `24` tests passed
- later expanded run reached `26` tests passing after self-leg candidate-set propagation and local candidate-set handling changes were added

### Other simple regression checks that passed during this session
- `php master/tests/provisioning_self_leg_payload_regression_test.php`
- `php master/tests/provisioning_forward_combination_helper_groups_regression_test.php`

### Important caveat
Near the end of work, the client-side logic around self-leg candidate ranking was still being refined. Use the current source as authority and rerun the full local test set before claiming final completion.

---

## Most important live evidence gathered

### Live real client pull (`114.80.33.50`) eventually showed
Self-leg candidate with:
- `dial_port = 2053`
- `cf_sni_host = nrt1.nbwly.com`
- `target_sidecar_port = 8899`
- `self_leg_cf_candidates = [...]`

### Live self-leg probe snapshots
Observed at different phases:
- old bad path examples:
  - `8443 + m-jp-4.scipnet.info` often led to `404`
- later improved tuple:
  - `2053 + nrt1.nbwly.com`
  - probe became structurally valid
- however, latest real measured self-leg probe still came out roughly:
  - `~150.7 ms`
- best rented Shanghai helper remained roughly:
  - `68.9 ms`

### Consequence in live runtime
Because self-leg still measured slower than the rented helper, the client correctly kept rented helper as selected primary. Latest observed live rendered `forward 47` still had:
- `bond_mode = ""`
- `direct_legs = []`
- rented helper underlay remained primary

So the final goal is **not** achieved yet.

---

## What has been fixed versus what is still broken

### Fixed / improved
- client-as-a-slave mode exists in code
- self-leg candidate exists in live payload
- self-leg can carry a candidate set, not just one IP
- client can locally choose from panel-published candidates
- helper-group / multi-slave-group metadata still preserved
- self-leg websocket tuple moved away from old bad `8443 / m-jp-4`-only publish model toward `2053 / nrt1` style contract

### Still broken / incomplete
- real `forward 47` still does **not** select self-leg over rented helper in live conditions
- live candidate set quality / ranking is still not enough to beat the best Shanghai helper path
- stable fast `11035` runtime on the self-leg path is not verified
- full automation for brand-new forwards / republish is not proven end-to-end

---

## Key assessment points for the next engineer / next session

Use these checkpoints to decide whether the system is actually finished:

### A. Panel payload correctness
For the **real** `114.80.33.50` client pull of `forward 47`, confirm self-leg contains:
- `dial_port = 2053`
- `cf_sni_host = nrt1.nbwly.com`
- non-empty `self_leg_cf_candidates`
- correct `target_sidecar_port = 8899`
- correct `target_sidecar_mptcp_port = 8898`

### B. Client-side local choice correctness
On `114.80.33.50`, with the current `client_agent.py`, confirm:
- self-leg probe iterates candidate set, not only one IP
- the chosen `dial_connect_ip` is the best successful edge from the set
- measured self-leg latency is **better than** the best rented helper if the path is truly better

### C. Runtime render correctness
After applying `forward 47`, confirm:
- `/etc/leikwan/mptcp-forward-47.json` contains non-empty `direct_legs`
- `bond_mode = "tcp"`
- `remote_host = 127.0.0.1`
- `remote_port = <local direct leg port>`
- `/usr/local/bin/leikwan-client-start-47.sh` uses the winning edge IP and correct SNI/path/port

### D. Live traffic correctness
Then verify:
- `systemctl is-active leikwan-client-47.service` = active
- `ss -ltnp` shows `127.0.0.1:11035`
- `iperf3 -c 127.0.0.1 -p 11035 -R -b 300M`
  - should be stable
  - should materially beat old `~63 Mbps / 80ms+` behavior

### E. Multiple-slave-group preservation
Verify no regression in helper behavior:
- helper-group metadata still present in payload
- rented helper candidates preserve `origin_group_key` and `origin_group_order`
- helper escalation / cap behavior still works when self-leg loses

---

## Concrete next steps

1. **Re-check the live real client pull** for `forward 47`
   - verify the candidate set is still there after any republish

2. **Finish the self-leg ranking semantics in `client-agent/client_agent.py`**
   The remaining area is the final practical ranking/return semantics for:
   - `expand_self_leg_cf_candidate_ips(...)`
   - `self_leg_candidate_edge_latency_ms(...)`
   - `probe_self_leg_websocket_path(...)`
   - `self_leg_direct_cf_connect_probe(...)`

3. **Re-sync only the needed client file**
   - `/usr/local/bin/leikwan-client-agent.py`

4. **Re-run live probe-only helper**
   Existing temp helpers referenced during work:
   - `/tmp/probe_only47.py`
   - `/tmp/final_probe_apply47.py`
   - `/tmp/live_pull_selfleg.py`

5. **Only after selection really flips to self-leg**, restart `leikwan-client-47.service` and validate throughput and listener stability.

---

## Suggested quick commands for continuation

### Local tests
```bash
python -m unittest client-agent.tests.test_self_leg_best_ip_override client-agent.tests.test_highloss_self_leg_runtime_regression client-agent.tests.test_runtime_preference_mptcp_bond_fallback client-agent.tests.test_self_leg_candidate_policy -v
php master/tests/provisioning_self_leg_payload_regression_test.php
php master/tests/provisioning_forward_combination_helper_groups_regression_test.php
```

### Sync panel file
```bash
pscp master/src/Support/ProvisioningService.php root@10.0.10.196:/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Support/ProvisioningService.php
plink root@10.0.10.196 "php -l /www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/Support/ProvisioningService.php"
```

### Sync client file
```bash
pscp client-agent/client_agent.py root@114.80.33.50:/usr/local/bin/leikwan-client-agent.py
```

### Live real-client pull / probe scripts already staged during work
- `/tmp/live_pull_selfleg.py`
- `/tmp/probe_only47.py`
- `/tmp/final_probe_apply47.py`

If missing, re-stage from local workspace before use.

---

## Final honest status at handoff time

The system is **closer** to full automation than before:
- panel no longer needs to hard-decide only one self-leg IP in principle
- client is capable of local edge choice
- multiple-slave-group helper behavior remains intact

But the actual requested end state is **not yet proven complete** because live `forward 47` still ends up preferring the rented helper path. The remaining frontier is now narrow and concrete: **candidate-set quality and ranking on the real client**.
