# sh36 MPTCP Leg Priority GitHub sync handoff

Date: 2026-05-19
Repo: `C:\Users\frankniubi\Downloads\leikwan-br1`
Branch: `master`
Live client target: `root@sh36.ctyun.mcbar.cc`

## What was done

Implemented and live-verified MPTCP Leg Priority for the Tokyo CF-WG path on sh36.

The shipped behavior is:

- The client-self CF-WG leg wins primary when it is selected as the best MPTCP leg.
- Rented helper MPTCP legs are kept as standby/backup instead of being removed.
- The rendered MPTCP config marks the CF-WG endpoint as `leg_role=primary` and helper endpoints as `leg_role=standby`, `backup_only=true`.
- Kernel MPTCP receives the helper leg with the `backup` flag.

Code areas touched:

- `client-agent/client_agent.py`
  - Candidate selection now annotates MPTCP leg roles.
  - If the selected primary is a self-leg, later helper candidates become standby backups.
  - Existing CF-WG bootstrap/health behavior is preserved so helper-only probe failures do not churn the selected self-leg runtime.
- `client-agent/install_forward_runtime.py`
  - Preferred CF-WG/private self-leg endpoint is rendered first.
  - Helper MPTCP endpoints and underlay legs are retained as standby backup entries.
  - CF-WG rendering keeps the local UDP tunnel target separate from the CF dial endpoint.
- `artifacts/versions.json`
  - Updated hashes/sizes for the current client-agent artifacts.
- Tests updated under `client-agent/tests/` for the leg-priority and CF-WG render behavior.

## Live sync already completed

Panel sync target:

- `root@10.0.10.196:/www/wwwroot/v3.leikwanhost.com/leikwan-v3`

sh36 sync target:

- `root@sh36.ctyun.mcbar.cc`

No work was done on `172.21.255.189`.

Backups created live:

- Panel: `/root/leikwan-mptcp-leg-priority-panel-20260519-005901`
- sh36: `/root/leikwan-mptcp-leg-priority-sh36-20260519-005901`

Live hashes after sync:

```text
8d6f19849d01a624ee6a6cc13cc8ff31d7cc571b43ea30a0fbeab72620bc93fa  client_agent.py
f88c4c2f763a0d58ba49a4ea4f1a874eecac51cf3a54a4bc84c63bb22f5f9c24  install_forward_runtime.py
```

## Live verification evidence

On sh36, forward `47` is active and rendered with the desired priority shape:

```text
subflow_limit = 2
mptcp_endpoints[0] = 10.204.53.41 dev lw-cfwg-47, primary, backup_only=false
mptcp_endpoints[1] = 10.203.201.177 dev lw-mptcp-47-0, standby, backup_only=true
```

Kernel MPTCP endpoint state:

```text
10.204.53.41 id 221 subflow dev lw-cfwg-47
10.203.201.177 id 222 subflow backup dev lw-mptcp-47-0
```

WireGuard state stayed alive during watch:

```text
lw-cfwg-47 latest handshake refreshed
WG transfer increased during the watch and during iperf
```

Smoke performance through forward `47`:

```text
iperf3 -c 127.0.0.1 -p 11035 -R -t 10
receiver: 274 MBytes / 230 Mbits/sec
sender:   304 MBytes / 244 Mbits/sec, 51 retransmits
```

## Local verification

Before this GitHub sync, local client-agent verification passed:

```text
python -m py_compile client-agent/client_agent.py client-agent/install_forward_runtime.py client-agent/leikwan_mptcp_client.py
python -m unittest discover -s client-agent/tests -p 'test_*.py'
```

The full discovery run reported:

```text
Ran 186 tests in 7.624s
OK
```

A fresh verification run should still be done immediately before the final `git push`.

## What to do next

1. Reduce the noisy no-op apply loop on sh36.
   - The service stays stable and logs `unit_changed=False script_changed=False`, but the agent still reapplies forward `47/59` roughly every 40 seconds.
   - Likely next target: compare pulled payload/applied-state fields and stop treating observation-only or health-only changes as a reason to rerender.
2. Decide how much IP hiding is practical.
   - `wg show` always displays WireGuard peer endpoint IP:PORT by design.
   - For CF-WG, the displayed endpoint is currently local `127.0.0.1:40047`, which is acceptable.
   - For real slave IP exposure, hide/redact in panel UI, logs, exported configs, and docs. Do not try to make upstream WireGuard CLI lie.
3. Clean stale global MPTCP endpoints only after confirming they are not used by another active forward.
   - Observed extras: `10.203.206.17 id 231`, `10.95.1.3 id 239`.
4. Keep testing future sh36 rollout on `root@sh36.ctyun.mcbar.cc`; do not switch back to `172.21.255.189` unless explicitly asked.

## Rollback

Panel rollback:

```bash
cp -a /root/leikwan-mptcp-leg-priority-panel-20260519-005901/client_agent.py /www/wwwroot/v3.leikwanhost.com/leikwan-v3/client-agent/client_agent.py
cp -a /root/leikwan-mptcp-leg-priority-panel-20260519-005901/install_forward_runtime.py /www/wwwroot/v3.leikwanhost.com/leikwan-v3/client-agent/install_forward_runtime.py
cp -a /root/leikwan-mptcp-leg-priority-panel-20260519-005901/versions.json /www/wwwroot/v3.leikwanhost.com/leikwan-v3/artifacts/versions.json
```

sh36 rollback:

```bash
cp -a /root/leikwan-mptcp-leg-priority-sh36-20260519-005901/leikwan-client-agent.py /usr/local/bin/leikwan-client-agent.py
cp -a /root/leikwan-mptcp-leg-priority-sh36-20260519-005901/install_forward_runtime.py /usr/local/bin/install_forward_runtime.py
chmod 0755 /usr/local/bin/leikwan-client-agent.py /usr/local/bin/install_forward_runtime.py
systemctl restart leikwan-client-agent.service
```
