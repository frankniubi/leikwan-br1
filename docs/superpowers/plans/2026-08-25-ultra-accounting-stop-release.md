# Ultra Accounting, Stop Cleanup, Release, and Acceptance Implementation Plan

> **Status:** Implemented on `codex/ultra-policy-v2`; accounting, immediate stop
> actions, cleanup, and artifact gates are in place. Live canary/signing remain
> deployment-time gates.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended; fresh worker per task) or superpowers:executing-plans. Steps use checkbox (- [ ]) syntax.

**Goal:** Make Panel accounting and lifecycle enforcement authoritative across Linux and Windows, then prove no persistent line information, no SOCKS listener, correct stop cleanup, and reproducible signed artifacts.

**Architecture:** Client runtimes enforce a Panel-provided rate/quota lease locally and report monotonic per-forward counters. Panel accepts counters idempotently, updates existing service traffic accounting, revokes policy generation on exhaustion or suspension, and returns a stop action. Release gates verify source, hash, and signature; canaries test the direct provider path without involving slave or sidecar.

**Tech Stack:** PHP/MySQL, existing Panel heartbeat and billing tables, Python/nftables, Go, .NET, PowerShell, Authenticode, ss, lsof, tcpdump, dig, and bounded load tests.

---

## File Map

Create:

- master/src/Support/UltraUsageService.php
- master/migrations/20260825_add_ultra_usage_cursors.sql
- master/tests/ultra_usage_idempotency_test.php
- master/tests/ultra_policy_stop_action_test.php
- client-agent/tests/test_ultra_usage_accounting.py
- LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/UltraUsageSnapshotTests.cs
- docs/reports/2026-08-25-ultra-v2-acceptance-report.md

Modify:

- master/src/Controllers/ClientAgentController.php
- master/src/Support/UltraClientStatusService.php
- master/src/Support/ProvisioningService.php
- master/src/Bootstrap.php
- client-agent/client_agent.py
- LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/HeartbeatService.cs
- LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/HeartbeatPayload.cs
- tools/ultra-client/status.go
- scripts/build-windows-client-setup.ps1
- scripts/windows-client-install-helper.ps1
- LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/WindowsAppUpdateService.cs
- focused existing tests for heartbeat, artifact distribution, and no-slave contracts

## Task 1: Add Idempotent Per-Forward Usage Cursors

**Files:** migration, UltraUsageService.php, Bootstrap.php, and the two PHP
usage tests.

- [ ] Step 1: Write a migration source test asserting a non-secret
  ultra_usage_cursors table with client_id, device_hash, forward_id,
  last_sequence, bytes_in, bytes_out, policy_generation, and timestamps, and no
  provider fields.
- [ ] Step 2: Run:

~~~powershell
php master/tests/ultra_usage_idempotency_test.php
~~~

Expected: FAIL because the table and service do not exist.
- [ ] Step 3: Add this idempotent table:

~~~sql
CREATE TABLE IF NOT EXISTS ultra_usage_cursors (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL,
    device_hash CHAR(64) NOT NULL,
    forward_id BIGINT UNSIGNED NOT NULL,
    last_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0,
    bytes_in BIGINT UNSIGNED NOT NULL DEFAULT 0,
    bytes_out BIGINT UNSIGNED NOT NULL DEFAULT 0,
    policy_generation CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_ultra_usage_scope (client_id, device_hash, forward_id)
);
~~~

- [ ] Step 4: Implement UltraUsageService::applyHeartbeat(). Accept at most
  32 entries, require positive forward ID and sequence, reject negative or
  decreasing cumulative counters, compute deltas from the cursor, and update
  the cursor with a compare-and-swap condition on last_sequence.
- [ ] Step 5: For accepted deltas, update the existing service
  traffic_used_bytes, billing_cycle_traffic_used_bytes, and hourly usage path
  exactly once. Reuse the existing billing-cycle synchronization and
  traffic-limit suspension behavior in ProvisioningService; do not add a
  second billing table.
- [ ] Step 6: Return only credential-free actions:

~~~php
[
    'forward_id' => $forwardId,
    'action' => 'stop',
    'reason' => 'traffic_limit',
    'policy_generation' => $newGeneration,
]
~~~

- [ ] Step 7: Run tests covering duplicate sequence, reordered sequence, counter
  reset, two forwards, and quota suspension. Expected: PASS.
- [ ] Step 8: Commit:

~~~powershell
git add master/migrations/20260825_add_ultra_usage_cursors.sql master/src/Support/UltraUsageService.php master/tests/ultra_usage_idempotency_test.php master/tests/ultra_policy_stop_action_test.php
git commit -m "feat: make Ultra usage reporting idempotent"
~~~

## Task 2: Make Heartbeat Policy Actions Immediate

**Files:** ClientAgentController.php, Bootstrap.php, Python/C# heartbeat clients,
and their tests.

- [ ] Step 1: Add failing controller tests that send cumulative Ultra usage,
  receive a stop action after quota exhaustion, and assert the response contains
  no provider or line fields.
- [ ] Step 2: Inject UltraUsageService into ClientAgentController and parse a
  bounded ultra_usage array. Keep the existing generic client usage path for
  non-Ultra modes; do not add Ultra bytes to it a second time.
- [ ] Step 3: Return:

~~~json
{
  "ok": true,
  "ultra_actions": [
    {
      "forward_id": 42,
      "action": "stop",
      "reason": "traffic_limit",
      "policy_generation": "opaque-generation"
    }
  ]
}
~~~

- [ ] Step 4: Update Python client_agent.py to process ultra_actions before the
  next normal sync, call stop_forward(forward_id, delete_files=True), and clear
  the in-memory lease. A malformed action is ignored; an action for an
  unassigned forward is not executed.
- [ ] Step 5: Update Windows MasterApiClient and ConfigPollingService to process
  the same action list immediately and call ForwardRuntimeManager.StopForward.
  Do not wait for HealthCheckService.
- [ ] Step 6: Add tests for inactive service, expiry, traffic suspension, device
  revocation, and malformed action. Run:

~~~powershell
php master/tests/ultra_policy_stop_action_test.php
python -m pytest client-agent/tests/test_ultra_usage_accounting.py -q
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter FullyQualifiedName~ConfigPollingServiceTests --no-restore
~~~

Expected: PASS.
- [ ] Step 7: Commit:

~~~powershell
git add master/src/Controllers/ClientAgentController.php master/src/Bootstrap.php client-agent/client_agent.py LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/MasterApiClient.cs LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ConfigPollingService.cs master/tests/ultra_policy_stop_action_test.php client-agent/tests/test_ultra_usage_accounting.py
git commit -m "fix: apply Ultra stop actions immediately"
~~~

## Task 3: Complete Linux and Windows Usage Snapshots

**Files:** Linux status/runtime, Python agent, Windows heartbeat/state, and tests.

- [ ] Step 1: Write failing tests asserting each forward reports a monotonic
  sequence, bytes in/out, and policy generation, but never provider IP, line ID,
  username, password, or raw material.
- [ ] Step 2: Add Go status fields:

~~~go
type UsageStatus struct {
    ForwardID int
    Sequence uint64
    BytesIn uint64
    BytesOut uint64
    PolicyGeneration string
}
~~~

- [ ] Step 3: Make the Go runtime update counters at the same copy points as
  rate/quota enforcement. Write bounded status snapshots at most once per five
  seconds and flush a final stopped state without line fields.
- [ ] Step 4: Make Python collect_ultra_status() and collect_usage_totals() read
  only the new fields. Do not derive Ultra billing from a status relay IP or
  from a second nft delta.
- [ ] Step 5: Make the Windows helper expose the same snapshot over its
  credential-free control/status channel. HeartbeatService reads snapshots and
  sends monotonic deltas with one sequence per forward.
- [ ] Step 6: Run:

~~~powershell
(cd tools/ultra-client && go test ./... -count=1)
python -m pytest client-agent/tests/test_ultra_status.py client-agent/tests/test_ultra_usage_accounting.py -q
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter FullyQualifiedName~HeartbeatServiceTests --no-restore
~~~

Expected: PASS.
- [ ] Step 7: Commit:

~~~powershell
git add tools/ultra-client/status.go client-agent/client_agent.py LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/HeartbeatService.cs LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/HeartbeatPayload.cs tools/ultra-client/*_test.go client-agent/tests/test_ultra_status.py client-agent/tests/test_ultra_usage_accounting.py LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/UltraUsageSnapshotTests.cs
git commit -m "feat: report bounded Ultra usage snapshots"
~~~

## Task 4: Stop and Cleanup Verification

**Files:** existing Linux stop code, Windows Job Object code, and new tests.

- [ ] Step 1: Add a Linux integration harness that starts a helper on temporary
  loopback ports, sends a valid in-memory lease, opens TCP and UDP, calls
  stop/revoke, and records listener/process/socket/file state.
- [ ] Step 2: Add a Windows integration test using a fake helper that spawns a
  child and opens a loopback listener. Stop the forward and inspect Job Object
  membership, process snapshots, socket enumeration, named pipe, state directory,
  and status directory.
- [ ] Step 3: Require this invariant before reporting stopped:

~~~text
local listener closed
provider TCP and UDP sockets closed
probe/reload workers stopped
child processes stopped
lease/session buffers cleared where possible
no credential-bearing file exists
no line-bearing status exists
nft/accounting rules removed or inactive
~~~

- [ ] Step 4: Run Linux checks on the canary host using read-only commands:

~~~bash
systemctl is-active ultra-forward-N.service
ss -ltnup
find /run/ultra -maxdepth 1 -type f -printf '%f\n'
pgrep -af ultra-client
nft -a list table inet leikwan_meter
~~~

Expected: no active Ultra unit, listener, process, credential-bearing file, or
active forward-specific nft rule.
- [ ] Step 5: Run Windows checks after stopping:

~~~powershell
Get-Process ultra-client -ErrorAction SilentlyContinue
Get-NetTCPConnection -State Listen
Get-ChildItem "$env:ProgramData\Leikwan" -Recurse -File
~~~

Expected: no helper process/listener and no lease-bearing file.
- [ ] Step 6: Commit cleanup tests:

~~~powershell
git add client-agent/tests LeikwanBridge.Desktop/tests tools/ultra-client/*_test.go
git commit -m "test: verify Ultra stop cleanup across platforms"
~~~

## Task 5: Reproducible Build and Signature Gates

**Files:** Go build metadata, Windows build scripts, artifact manifest tests,
and release documentation.

- [ ] Step 1: Add a release test that fails when the served artifact hash/size
  differs from artifacts/versions.json, when a Windows PDB is packaged, or when
  an executable lacks a valid Authenticode signature.
- [ ] Step 2: Build Linux with:

~~~powershell
$env:GOOS="linux"
$env:GOARCH="amd64"
$env:CGO_ENABLED="0"
go build -buildmode=pie -trimpath -buildvcs=false -ldflags="-s -w -buildid=" -o artifacts/ultra/ultra-client_linux_amd64 ./tools/ultra-client
~~~

Record source commit, Go version, SHA-256, and flags. Verify ELF is PIE,
GNU stack is non-executable, and debug/symbol sections are absent.
- [ ] Step 3: Build Windows with the same source and flags, package the helper,
  and sign helper, App/Core binaries, and installer. Fail before publication if
  any signature is not Valid.
- [ ] Step 4: Make WindowsAppUpdateService verify hash and signature before
  atomic replacement, retaining the previous helper until readiness passes.
- [ ] Step 5: Run:

~~~powershell
(cd tools/ultra-client && go test ./... -count=1)
python -m pytest client-agent/tests/test_ultra_client.py client-agent/tests/test_ultra_runtime.py -q
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --no-restore
php master/tests/ultra_client_artifact_distribution_test.php
~~~

Expected: PASS. Do not edit versions.json until final artifacts are independently
hashed and verified.
- [ ] Step 6: Commit only source/build-gate changes. Artifact and manifest updates
  require a separate release commit after hash verification:

~~~powershell
git add scripts/build-windows-client-setup.ps1 scripts/windows-client-install-helper.ps1 LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/WindowsAppUpdateService.cs master/tests/ultra_client_artifact_distribution_test.php
git commit -m "build: enforce signed Ultra artifact releases"
~~~

## Task 6: Functional and Policy Canaries

- [ ] Step 1: Create one isolated Ultra forward per platform using a disposable
  client/session and approved destination. Do not involve slave or sidecar.
- [ ] Step 2: Verify TCP and UDP through the direct provider path using sustained
  receiver throughput, loss, interval stability, and loaded RTT p99.
- [ ] Step 3: Verify policy precedence: local limit above Panel limit is clamped;
  quota exhaustion closes new and active traffic; provider lower limit cannot
  raise Panel limit; generation change revokes the old lease; expiry and disable
  stop within the documented lease/poll bound.
- [ ] Step 4: Capture packets only to prove the documented direct-path boundary.
  The capture may show provider IP and handshake bytes, but no credential is
  written to disk or telemetry. This visibility is accepted under direct mode.
- [ ] Step 5: Probe each local listener with SOCKS5 greeting bytes. It must close
  the connection and never behave as a general proxy.
- [ ] Step 6: Stop each canary and repeat the Linux/Windows cleanup commands.
  Preserve only credential-free evidence.
- [ ] Step 7: Write docs/reports/2026-08-25-ultra-v2-acceptance-report.md with
  source commit, artifact hashes, platform versions, policy values, TCP/UDP
  results, quota/expiry/revocation timestamps, SOCKS probe results, cleanup
  evidence, and test commands. Redact credentials, lease token, line ID,
  provider IP, and session identifiers.
- [ ] Step 8: Scan and commit the redacted report:

~~~powershell
rg -n -i "password|credential|token|line_id|relay_ip|provider_ip|user" docs/reports/2026-08-25-ultra-v2-acceptance-report.md
git add docs/reports/2026-08-25-ultra-v2-acceptance-report.md
git commit -m "docs: record Ultra v2 policy and cleanup acceptance"
~~~

The scan must return no secret-bearing value.

## Final Acceptance Matrix

- Panel grants contain policy only and no persistent line information.
- Lease endpoint is authenticated, one-time, generation-bound, and bounded.
- Linux and Windows store no lease-bearing file.
- No local SOCKS5 listener exists.
- Panel rate limit is enforced in both directions.
- Quota exhaustion, expiry, suspension, disable, and revoke stop the runtime.
- Heartbeat usage is monotonic and idempotent.
- Existing service traffic accounting is updated exactly once.
- Stop leaves no listener, provider socket, child process, credential file, or
  line-bearing status.
- Linux and Windows artifacts are reproducible, hash-matched, and signed.
- Ultra path contains no slave, sidecar, independent Gateway, or new public
  data port.
- Linux and Windows Clients reject `cf_mode`; a previously applied `cf_mode`
  forward is stopped and cleaned, and no Client runtime branch can execute it.
- Panel/server-side historical `cf_mode` compatibility remains unchanged.
- Direct packet visibility and live-memory extraction remain documented residual
  risks, not hidden assumptions.
