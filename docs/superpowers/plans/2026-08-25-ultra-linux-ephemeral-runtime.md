# Ultra Linux Ephemeral Runtime Implementation Plan

> **Status:** Implemented on `codex/ultra-policy-v2`; focused Go/Python tests and
> Linux artifact rebuild completed.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (\`- [ ]\`) syntax.

**Goal:** Make the Linux Ultra runtime consume Panel policy and one-time provider leases through an authenticated local control socket without writing line information to disk.

**Architecture:** The systemd service starts with static forward policy only and owns a root-only Unix domain control socket. \`client_agent.py\` obtains a v2 lease from Panel and sends it over that socket. The Go process keeps the provider endpoint and temporary credential in memory, opens the fixed-target listener only after policy/lease validation, and closes all sessions when policy expires or is revoked.

**Prerequisite:** Complete
`2026-08-25-ultra-client-remove-cf-mode.md`. The Linux Client must reject and
clean `cf_mode` before Ultra v2 runtime work is enabled; Ultra must never use
`cf_mode` as a fallback.

**Tech Stack:** Go, Unix domain sockets, systemd, nftables, Python 3, JSON framing, existing \`tools/ultra-client\` tests and client-agent tests.

---

## File Map

Create:

- \`tools/ultra-client/policy.go\` — policy, generation, expiry, target digest, and hard-limit validation.
- \`tools/ultra-client/lease.go\` — memory-only provider lease, credential wiping, and one-time lease validation.
- \`tools/ultra-client/control.go\` — bounded Unix socket control protocol and atomic policy/lease replacement.
- \`tools/ultra-client/policy_test.go\` — policy and hard-limit tests.
- \`tools/ultra-client/lease_test.go\` — lease validation and memory-store tests.
- \`tools/ultra-client/control_test.go\` — socket framing, authentication, size, and replay tests.

Modify:

- \`tools/ultra-client/main.go\` — replace file-backed material with \`PolicyStore\`, control-socket lifecycle, dynamic limiters, and cancellable sessions.
- \`tools/ultra-client/status.go\` — remove relay IP/line fields and expose opaque selection references only.
- \`tools/ultra-client/main_test.go\` — update startup and stop tests.
- \`tools/ultra-client/status_test.go\` — update credential-free status assertions.
- \`tools/ultra-client/selector.go\` — rank server-approved lease candidates without persisting line metadata.
- \`client-agent/client_agent.py\` — request v2 leases, send them over the local socket, and never persist lease-bearing forward state.
- \`client-agent/install_forward_runtime.py\` — render static policy config and control socket only.
- \`client-agent/tests/test_ultra_client.py\` — v2 lease request and no-persistence tests.
- \`client-agent/tests/test_ultra_runtime.py\` — static config and systemd hardening tests.
- \`client-agent/tests/test_ultra_status.py\` — credential-free status tests.
- \`client-agent/tests/test_ultra_renewal_schedule.py\` — policy/lease renewal and expiry tests.

## Runtime Wire Contracts

### Static config

\`/etc/ultra/forward-N.json\` contains only non-secret fields:

~~~json
{
  "forward_id": 42,
  "listen_host": "127.0.0.1",
  "listen_port": 12345,
  "target": "203.0.113.50:443",
  "transport": "tcp+udp",
  "control_socket": "/run/ultra/forward-42.control.sock",
  "status_path": "/run/ultra/forward-42.status.json",
  "max_tcp_connections": 512,
  "udp_queue_packets": 64,
  "connect_timeout_seconds": 5
}
~~~

The static config must not contain \`line_ips\`, \`ip\`, \`port\`, \`user\`,
\`credential\`, \`password\`, \`sealed_material\`, or \`relay_ip\`.

### Local control frame

The agent sends a bounded JSON frame over the root-only Unix socket:

~~~text
uint32_be payload_length (1..65536)
JSON payload
~~~

The socket is mode \`0600\`, owned by the runtime user, and accepts only one
frame per connection. The runtime rejects a second frame with the same lease
nonce or an older policy generation. It never writes the received frame to disk.

## Task 1: Add Policy and Lease Memory Types

**Files:**

- Create: \`tools/ultra-client/policy.go\`
- Create: \`tools/ultra-client/lease.go\`
- Test: \`tools/ultra-client/policy_test.go\`
- Test: \`tools/ultra-client/lease_test.go\`

- [ ] **Step 1: Write failing policy tests.** Cover valid policy, wrong protocol,
  expired policy, zero forward ID, target digest mismatch, rate above the Panel
  ceiling, quota underflow, and generation rollback.

~~~go
if err := policy.ValidateTarget("203.0.113.50:443"); err != nil { t.Fatal(err) }
if err := policy.AcceptReplacement(older); !errors.Is(err, ErrPolicyRollback) { t.Fatal(err) }
if limiter.Allow(21*1024*1024) { t.Fatal("quota overrun accepted") }
~~~

- [ ] **Step 2: Run the tests and verify they fail.**

~~~powershell
(cd tools/ultra-client && go test . -run 'TestPolicy|TestLease' -count=1)
~~~

Expected: FAIL because the new types do not exist.

- [ ] **Step 3: Implement \`Policy\` and \`ProviderLease\`.** Use \`[]byte\` for
temporary user/password buffers inside the lease store, copy only for the
immediate provider handshake, and expose no JSON marshal method for the
credential-bearing type.

~~~go
type Policy struct {
    Protocol            string    \`json:"protocol"\`
    ForwardID           int       \`json:"forward_id"\`
    PolicyGeneration    string    \`json:"policy_generation"\`
    SessionTicket       string    \`json:"session_ticket"\`
    ExpiresAt           time.Time \`json:"expires_at"\`
    Transport           string    \`json:"transport"\`
    RateLimitMbps       int64     \`json:"rate_limit_mbps"\`
    QuotaRemainingBytes int64     \`json:"quota_remaining_bytes"\`
    TargetDigest        string    \`json:"target_digest"\`
}

type ProviderLease struct {
    LeaseID          string
    PolicyGeneration string
    ExpiresAt        time.Time
    IP               string
    Port             int
    User             []byte
    Password         []byte
    NonceHash        string
}
~~~

- [ ] **Step 4: Implement replacement and wipe rules.** \`PolicyStore.Replace()\`
rejects older generations and expired leases, atomically swaps the policy and
lease, and calls \`wipe()\` on the previous \`User\` and \`Password\` byte slices.
A store snapshot returns a read-only copy with credentials copied only for a
single dial operation.

- [ ] **Step 5: Run policy and lease tests.**

~~~powershell
(cd tools/ultra-client && go test . -run 'TestPolicy|TestLease' -count=1)
~~~

Expected: PASS.

- [ ] **Step 6: Commit the memory contract.**

~~~powershell
git add tools/ultra-client/policy.go tools/ultra-client/lease.go tools/ultra-client/policy_test.go tools/ultra-client/lease_test.go
git commit -m "feat: add Ultra policy and ephemeral lease types"
~~~

## Task 2: Add the Bounded Local Control Socket

**Files:**

- Create: \`tools/ultra-client/control.go\`
- Create: \`tools/ultra-client/control_test.go\`
- Modify: \`tools/ultra-client/main.go\`

- [ ] **Step 1: Write failing control tests.** Cover payload length \`0\`,
payloads over 64 KiB, partial reads, invalid JSON, stale generation, duplicate
nonce, peer permission failure, and a valid replacement.

~~~go
if err := writeFrame(conn, make([]byte, 65537)); !errors.Is(err, ErrFrameTooLarge) { t.Fatal(err) }
if got := store.LastNonce(); got != wantNonce { t.Fatalf("nonce=%q", got) }
~~~

- [ ] **Step 2: Run the tests and verify failure.**

~~~powershell
(cd tools/ultra-client && go test . -run TestControl -count=1)
~~~

Expected: FAIL because the frame and control server do not exist.

- [ ] **Step 3: Implement the frame reader/writer.** Use \`io.ReadFull\` for the
four-byte length and payload, reject lengths outside \`1..65536\`, set a two
second deadline, and close the connection after one frame.

- [ ] **Step 4: Implement \`ControlServer\`.** Listen on the configured Unix
socket, remove only a stale socket owned by the current runtime, chmod \`0600\`,
accept at most four concurrent control handlers, and call
\`PolicyStore.Replace()\` after validating the session ticket and nonce.

- [ ] **Step 5: Add shutdown handling.** \`ControlServer.Close()\` closes the
listener, unblocks \`Accept\`, waits for handlers, unlinks the socket, and
leaves no credential-bearing file.

- [ ] **Step 6: Run control tests.**

~~~powershell
(cd tools/ultra-client && go test . -run TestControl -count=1)
~~~

Expected: PASS.

- [ ] **Step 7: Commit the control socket.**

~~~powershell
git add tools/ultra-client/control.go tools/ultra-client/control_test.go tools/ultra-client/main.go
git commit -m "feat: add bounded Ultra runtime control socket"
~~~

## Task 3: Move Linux Agent from Material Files to Lease Delivery

**Files:**

- Modify: \`client-agent/client_agent.py\`
- Modify: \`client-agent/install_forward_runtime.py\`
- Test: \`client-agent/tests/test_ultra_client.py\`
- Test: \`client-agent/tests/test_ultra_runtime.py\`

- [ ] **Step 1: Add failing source-contract tests.** Assert that the v2 path
calls \`/api/client/ultra/lease\`, writes no \`_ultra_material\` to
\`applied.json\`, and renders a \`control_socket\` but no \`access_file\` or
\`sealed_material\`.

~~~python
assert "/api/client/ultra/lease" in source
assert "_ultra_material" not in persisted_apply_source
assert '"control_socket"' in rendered_config
assert '"sealed_material"' not in rendered_config
~~~

- [ ] **Step 2: Run focused Python tests and verify failure.**

~~~powershell
python -m pytest client-agent/tests/test_ultra_client.py client-agent/tests/test_ultra_runtime.py -q
~~~

Expected: FAIL because the current implementation requests \`/materials\` and
renders an access file.

- [ ] **Step 3: Replace \`resolve_ultra_materials()\` with
\`resolve_ultra_leases()\`.** Send at most four \`{forward_id, session_ticket,
policy_generation, nonce, target_digest, transport}\` requests. Keep lease
values only in the current \`sync_once()\` call; do not put them in
\`self.latest_forwards\`, \`applied.json\`, or \`pull_state\`.

- [ ] **Step 4: Add \`send_ultra_lease()\`.** Connect to the runtime control socket,
encode one bounded frame, retry three times with 100/300/900 ms backoff, and
clear the Python lease dictionary in a \`finally\` block after send. A missing
lease must stop the old runtime before returning an error.

- [ ] **Step 5: Render static Ultra units.** Change \`render_ultra_runtime()\` to
write only the static JSON and systemd unit. Use:

~~~ini
ExecStart=/usr/local/bin/ultra-client --config /etc/ultra/forward-N.json
KillMode=control-group
Restart=on-failure
RestartSec=5
LimitCORE=0
NoNewPrivileges=true
PrivateTmp=true
ProtectHome=true
ProtectSystem=strict
ReadWritePaths=/run/ultra /var/lib/ultra
ExecStopPost=/usr/bin/rm -f /run/ultra/forward-N.control.sock /run/ultra/forward-N.status.json
~~~

The unit must not contain a provider endpoint, username, password, or lease.

- [ ] **Step 6: Make apply ordering explicit.** Start the unit, wait for the
control socket, send the lease, then require the local listener. On policy
revoke or missing lease, stop the unit and remove its control/status socket.

- [ ] **Step 7: Run the focused tests.**

~~~powershell
python -m pytest client-agent/tests/test_ultra_client.py client-agent/tests/test_ultra_runtime.py -q
~~~

Expected: PASS, including no-persistence and no-secret systemd assertions.

- [ ] **Step 8: Commit agent delivery.**

~~~powershell
git add client-agent/client_agent.py client-agent/install_forward_runtime.py client-agent/tests/test_ultra_client.py client-agent/tests/test_ultra_runtime.py
git commit -m "feat: deliver Ultra leases over local control socket"
~~~

## Task 4: Replace Go Material Files and Enforce Dynamic Policy

**Files:**

- Modify: \`tools/ultra-client/main.go\`
- Modify: \`tools/ultra-client/status.go\`
- Modify: \`tools/ultra-client/main_test.go\`
- Modify: \`tools/ultra-client/status_test.go\`
- Test: \`tools/ultra-client/policy_test.go\`

- [ ] **Step 1: Add failing startup tests.** Assert the runtime refuses to open
the listener before receiving a valid policy/lease, rejects an expired lease,
and stops on generation revoke.

- [ ] **Step 2: Remove Client-side \`Entry\`, \`decryptMaterial\`, and
\`buildHandshake\` inputs from persistent config.** Provider handshake code may
still exist in the runtime because direct provider mode requires it, but it
must consume only the in-memory \`ProviderLease\` and must not be reachable from
JSON config loading.

- [ ] **Step 3: Add dynamic \`PolicyLimiter\`.** Enforce both rate and quota on
every inbound/outbound copy path. The API must be:

~~~go
type PolicyLimiter interface {
    Consume(n int) error
    Replace(rateMbps int64, quotaBytes int64, generation string) error
    Revoke(generation string)
}
~~~

\`Consume\` returns \`ErrPolicyStopped\` for expiry, revocation, or quota zero.
Lower limits replace immediately; higher local values are never accepted.

- [ ] **Step 4: Gate listener and provider dialing.** \`serveTCP\` and
\`serveUDP\` obtain a current policy/lease snapshot before accepting or dialing.
A missing/expired lease closes the local connection without trying another
legacy route.

- [ ] **Step 5: Fix UDP association ownership.** Replace the global
\`lastClient\` pointer with a bounded map keyed by local association ID and
client address. Each association has an idle timer, maximum packet count, and
close callback. A response can only return to the association that created it.

- [ ] **Step 6: Add unified cancellation.** Use a root context in \`main()\`;
SIGTERM/SIGINT/SIGHUP policy replacement and control socket close all listeners,
provider TCP/UDP sockets, probes, reload workers, and status writers. Wait for
the worker group before exit.

- [ ] **Step 7: Remove line-bearing status.** Change \`SelectionStatus\` from
\`RelayIP\` to \`SelectionRef\`; reject IP/line fields during JSON normalization.
Keep only \`forward_id\`, state, zone, RTT, opaque reference, timestamp, and
bounded error code.

- [ ] **Step 8: Run Go tests.**

~~~powershell
(cd tools/ultra-client && go test ./... -count=1)
~~~

Expected: PASS, including startup gating, dynamic quota/rate, UDP association,
credential-free status, and cancellation tests.

- [ ] **Step 9: Commit the runtime.**

~~~powershell
git add tools/ultra-client/main.go tools/ultra-client/status.go tools/ultra-client/main_test.go tools/ultra-client/status_test.go tools/ultra-client/policy_test.go
git commit -m "feat: enforce Ultra policy and ephemeral leases in Linux runtime"
~~~

## Task 5: Harden Linux Installation and Accounting Hooks

**Files:**

- Modify: \`client-agent/client_agent.py\`
- Modify: \`client-agent/install_forward_runtime.py\`
- Test: \`client-agent/tests/test_ultra_status.py\`
- Test: \`client-agent/tests/test_ultra_renewal_schedule.py\`

- [ ] **Step 1: Add failing cleanup tests.** Simulate manual stop, inactive
config, expired policy, and service removal. Assert \`systemctl stop\`, control
socket removal, status removal, nft rule removal, and no persisted lease.

- [ ] **Step 2: Make stop terminal.** \`stop_forward()\` must stop/disable both
old and new Ultra unit names, remove control/status files, delete nft rules,
and clear the in-memory lease map before deleting applied state.

- [ ] **Step 3: Make renewal fail closed.** When Panel renewal fails, preserve a
running session only until the current policy/lease expiry. At expiry call
\`stop_forward()\`; do not restart the unit with stale state.

- [ ] **Step 4: Keep nft usage independent.** Continue
\`lw_client_<id>_<dir>_<proto>\` counters for Linux accounting, but remove them
on stop and do not serialize provider information in \`usage_state.json\`.

- [ ] **Step 5: Run Python tests.**

~~~powershell
python -m pytest client-agent/tests/test_ultra_status.py client-agent/tests/test_ultra_renewal_schedule.py -q
~~~

Expected: PASS.

- [ ] **Step 6: Commit Linux lifecycle hardening.**

~~~powershell
git add client-agent/client_agent.py client-agent/install_forward_runtime.py client-agent/tests/test_ultra_status.py client-agent/tests/test_ultra_renewal_schedule.py
git commit -m "fix: fail closed and clean Ultra Linux sessions"
~~~

## Linux Self-Review and Acceptance

Run:

~~~powershell
rg -n "sealed_material|line_ips|relay_ip|credential|password" tools/ultra-client client-agent/install_forward_runtime.py client-agent/tests/test_ultra_*.py
(cd tools/ultra-client && go test ./... -count=1)
python -m pytest client-agent/tests/test_ultra_*.py -q
~~~

Credential strings may appear only in tests that explicitly construct an
in-memory lease or in provider-handshake code. No serialized config, status,
unit, or persisted state may contain them. Confirm the ELF is built separately
with the release plan's PIE/strip command and that no runtime path mentions
slave, sidecar, Gateway, or \`18443\`.

Also run the `cf_mode` removal gate. Expected Client-side matches are limited
to explicit rejection logic and rejection tests; no renderer, selector, health
branch, command generator, or fallback may execute `cf_mode`.
