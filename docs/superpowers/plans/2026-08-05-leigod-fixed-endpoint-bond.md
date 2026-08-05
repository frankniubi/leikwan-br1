# LeiGod Fixed-Endpoint Bond Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Productize deterministic multi-line `leikwan-bond` behind one pinned LeiGod slave endpoint while preserving the public `host:port` and failing closed on ambiguous leg identity.

**Architecture:** Reuse the unused hello-header `stream_id` as an additive wire `route_id`, carry it from a new `LegSpec.leg_route_id`, and let `leigod_forward` mode `bond` select one exact local SOCKS candidate before forwarding the untouched LBND stream. The panel publishes one physical endpoint plus two to eight logical legs, and the Linux runtime reuses one authenticated ingress helper for all logical connections. Existing bond scheduling stays authoritative; fixed-bond-only readiness and retry settings improve cold startup without changing legacy defaults.

**Tech Stack:** Rust/Tokio/Clap (`leikwan-bond`), Python 3/asyncio/unittest (relay, slave agent, Linux client runtime), PHP 8 regression scripts (panel provisioning), JSON artifact manifests, WSL musl release builds.

---

## File Map

- Modify `wstunnel-main/leikwan-bond/src/frame.rs`: encode/decode hello wire route identity.
- Modify `wstunnel-main/leikwan-bond/src/transport.rs`: parse `leg_route_id` independently from `endpoint_id`.
- Modify `wstunnel-main/leikwan-bond/src/session.rs`: build per-leg hellos, fixed-bond readiness/retry, and route-aware snapshots.
- Modify `wstunnel-main/leikwan-bond/src/main.rs`: expose bounded readiness/retry CLI flags.
- Modify `slave-agent/leigod_forward_relay.py`: validate and route LBND hello connections in `mode=bond`.
- Modify `slave-agent/tests/test_leigod_forward_relay.py`: deterministic demux and malformed-header regression coverage.
- Modify `slave-agent/slave_agent.py`: validate `mode=bond` publish payloads and emit strict runtime config.
- Modify `slave-agent/tests/test_slave_leigod_forward_publish.py`: agent contract tests.
- Modify `master/src/Support/ProvisioningService.php`: point gate, exact-node reconciliation, slave publish contract, and client logical-leg contract.
- Create `master/tests/leigod_fixed_endpoint_bond_contract_test.php`: focused end-to-end provisioning regression fixture.
- Modify `master/tests/provisioning_specific_node_persistence_regression_test.php`: preserve existing ungated combination behavior while covering the gated exception.
- Modify `client-agent/install_forward_runtime.py`: one ingress helper plus N route-tagged logical legs.
- Create `client-agent/tests/test_leigod_fixed_endpoint_bond_runtime.py`: Linux runtime command-shape tests.
- Synchronize `artifacts/install-forward-runtime.py`, `artifacts/slave-agent.py`, and `artifacts/versions.json` only after source tests pass. The relay artifact route reads `slave-agent/leigod_forward_relay.py` directly; there is no second relay mirror file.
- Modify `wstunnel-main/leikwan-bond/Cargo.toml`: bump the accepted Linux release version after all local gates pass.

### Task 1: Add Backward-Compatible Hello Route Identity

**Files:**
- Modify: `wstunnel-main/leikwan-bond/src/frame.rs:54-59,98-104,260-307,350-end`
- Modify: `wstunnel-main/leikwan-bond/src/transport.rs:49-62,81-186,554-end`

- [ ] **Step 1: Write failing frame and URI parser tests**

Add these tests before changing production structs:

```rust
#[tokio::test]
async fn hello_route_id_roundtrips_in_header_without_changing_payload() {
    let (mut left, mut right) = duplex(4096);
    let frame = WireFrame::Hello(HelloFrame {
        session_id: Uuid::nil(),
        expected_links: 5,
        route_id: 453,
        token: String::from("token"),
    });

    write_frame(&mut left, &frame).await.unwrap();
    let decoded = read_frame(&mut right).await.unwrap();

    match decoded {
        WireFrame::Hello(hello) => {
            assert_eq!(hello.route_id, 453);
            assert_eq!(hello.expected_links, 5);
            assert_eq!(hello.token, "token");
        }
        other => panic!("unexpected frame: {other:?}"),
    }
}
```

Extend `transport.rs` tests:

```rust
#[test]
fn parse_leg_spec_keeps_endpoint_and_leg_route_identity_separate() {
    let spec = parse_leg_spec(
        "tcp://127.0.0.1:20962?priority=10&endpoint_id=184&leg_route_id=453",
    )
    .expect("parse");
    assert_eq!(spec.endpoint_id, Some(184));
    assert_eq!(spec.leg_route_id, Some(453));
}

#[test]
fn parse_leg_spec_rejects_zero_leg_route_id() {
    let error = parse_leg_spec("tcp://127.0.0.1:20962?leg_route_id=0")
        .expect_err("zero route must fail");
    assert!(error.to_string().contains("leg_route_id"));
}
```

- [ ] **Step 2: Run the focused tests and verify RED**

Run:

```powershell
wsl -d Ubuntu -- bash -lc "cd '/mnt/c/Users/frankniubi/Downloads/leikwan-br1/.worktrees/sidecar-shared-wg-peers' && cargo test --manifest-path wstunnel-main/leikwan-bond/Cargo.toml hello_route_id_roundtrips_in_header_without_changing_payload parse_leg_spec_keeps_endpoint_and_leg_route_identity_separate parse_leg_spec_rejects_zero_leg_route_id"
```

Expected: compilation fails because `HelloFrame.route_id` and `LegSpec.leg_route_id` do not exist.

- [ ] **Step 3: Implement the minimal wire and parser fields**

Use these exact shape changes:

```rust
pub struct HelloFrame {
    pub session_id: Uuid,
    pub expected_links: u16,
    pub route_id: u32,
    pub token: String,
}
```

In `WireFrame::header`:

```rust
Self::Hello(hello) => {
    let payload_len = 16usize + 2usize + 2usize + hello.token.len();
    (FrameKind::Hello, 0, 0, 0, hello.route_id, payload_len as u32)
}
```

Pass the decoded header value into hello decoding:

```rust
FrameKind::Hello => decode_hello(payload, stream_id),
```

and set it on `HelloFrame` without changing payload bytes. Add to `LegSpec`:

```rust
pub leg_route_id: Option<u32>,
```

Parse only positive `u32` values; return `bail!("invalid leg_route_id in bond leg URI: {trimmed}")` for zero, overflow, or malformed values.

- [ ] **Step 4: Run frame and transport tests and verify GREEN**

Run:

```powershell
wsl -d Ubuntu -- bash -lc "cd '/mnt/c/Users/frankniubi/Downloads/leikwan-br1/.worktrees/sidecar-shared-wg-peers' && cargo test --manifest-path wstunnel-main/leikwan-bond/Cargo.toml frame::tests transport::tests"
```

Expected: all frame and transport unit tests pass.

- [ ] **Step 5: Commit the wire contract**

```powershell
git add wstunnel-main/leikwan-bond/src/frame.rs wstunnel-main/leikwan-bond/src/transport.rs
git commit -m "feat(bond): carry deterministic leg route identity"
```

### Task 2: Send Per-Leg Route Identity on Initial and Reconnected Sessions

**Files:**
- Modify: `wstunnel-main/leikwan-bond/src/session.rs:559-600,1469-1730`

- [ ] **Step 1: Add a failing pure hello-construction test**

Introduce the wished-for helper in the test first:

```rust
#[test]
fn hello_for_spec_uses_leg_route_id_and_shared_session_fields() {
    let spec = parse_leg_spec(
        "tcp://127.0.0.1:20962?endpoint_id=184&leg_route_id=455",
    )
    .unwrap();
    let session_id = Uuid::nil();
    let frame = hello_for_spec(&spec, session_id, 5, "secret");
    match frame {
        WireFrame::Hello(hello) => {
            assert_eq!(hello.session_id, session_id);
            assert_eq!(hello.expected_links, 5);
            assert_eq!(hello.route_id, 455);
            assert_eq!(hello.token, "secret");
        }
        other => panic!("unexpected frame: {other:?}"),
    }
}
```

- [ ] **Step 2: Run the test and verify RED**

Run the named test. Expected: failure because `hello_for_spec` does not exist.

- [ ] **Step 3: Implement per-spec hello construction**

Add:

```rust
fn hello_for_spec(
    spec: &LegSpec,
    session_id: Uuid,
    expected_links: u16,
    token: &str,
) -> WireFrame {
    WireFrame::Hello(HelloFrame {
        session_id,
        expected_links: expected_links.max(1),
        route_id: spec.leg_route_id.unwrap_or(0),
        token: token.to_string(),
    })
}
```

Replace the shared TCP hello with per-spec construction in both initial tasks and
`run_client_leg_connector`. Do the same for every connected UDP spec. Extend `OpenLeg::with_metadata`
to accept and retain `leg_route_id`, then copy it into `LegState`.

- [ ] **Step 4: Run the full Rust test suite**

Run:

```powershell
wsl -d Ubuntu -- bash -lc "cd '/mnt/c/Users/frankniubi/Downloads/leikwan-br1/.worktrees/sidecar-shared-wg-peers' && cargo test --manifest-path wstunnel-main/leikwan-bond/Cargo.toml"
```

Expected: all current tests pass, including existing ordinary sessions with route ID zero.

- [ ] **Step 5: Commit per-leg hello construction**

```powershell
git add wstunnel-main/leikwan-bond/src/session.rs
git commit -m "feat(bond): preserve route identity across leg reconnects"
```

### Task 3: Add Deterministic `leigod_forward` Bond Demultiplexing

**Files:**
- Modify: `slave-agent/tests/test_leigod_forward_relay.py`
- Modify: `slave-agent/leigod_forward_relay.py:20-128,131-162,500-660`

- [ ] **Step 1: Add LBND test helpers and failing behavior tests**

Add this helper to the relay test:

```python
def bond_hello_header(leg_route_id: int, payload_len: int = 20) -> bytes:
    return (
        b"LBND"
        + bytes([2, 1])
        + b"\x00\x00"
        + struct.pack(">QQII", 0, 0, leg_route_id, payload_len)
    )
```

Make `FakeSocksServer` append each accepted CONNECT to `self.connect_targets`. Add tests that:

```python
async def test_bond_routes_fragmented_hello_to_exact_candidate(self) -> None:
    async with TcpEchoServer() as target, FakeSocksServer(451) as first, FakeSocksServer(453) as second:
        first_row = first.candidate(1, 10) | {"leg_route_id": 451}
        second_row = second.candidate(2, 20) | {"leg_route_id": 453}
        runtime = await self.start_runtime(self.config("bond", target.address, [first_row, second_row]))
        reader, writer = await asyncio.open_connection(*runtime.tcp_address)
        header = bond_hello_header(453, 0)
        writer.write(header[:7])
        await writer.drain()
        writer.write(header[7:] + b"payload")
        await writer.drain()
        self.assertEqual(header + b"payload", await reader.readexactly(len(header) + 7))
        self.assertEqual([], first.connect_targets)
        self.assertEqual([target.address], second.connect_targets)
        writer.close()
        await writer.wait_closed()
```

Add these named tests, each with one direct assertion:

```python
async def test_bond_rejects_zero_and_unknown_routes_without_socks_connect(self): ...
async def test_bond_rejects_wrong_magic_version_and_kind(self): ...
async def test_bond_rejects_oversized_hello_payload_length(self): ...
async def test_bond_header_timeout_closes_without_socks_connect(self): ...
async def test_bond_route_connect_failure_does_not_fall_back(self): ...
def test_bond_config_rejects_duplicate_routes_one_candidate_and_udp(self): ...
```

For every rejection, wait for EOF and assert both fake SOCKS `connect_targets` lists remain empty;
for exact-route failure, set only the addressed fake server's `fail_connect=True` and assert the
other list remains empty.

- [ ] **Step 2: Run relay tests and verify RED**

Run:

```powershell
python -m unittest slave-agent/tests/test_leigod_forward_relay.py -v
```

Expected: new tests fail because `bond` and `leg_route_id` are rejected.

- [ ] **Step 3: Implement strict config validation and header parsing**

Add constants:

```python
LBND_MAGIC = b"LBND"
LBND_VERSION = 2
LBND_HELLO_KIND = 1
LBND_HEADER_LEN = 32
LBND_MAX_HELLO_PAYLOAD = 8192
BOND_HEADER_TIMEOUT_SECONDS = 3.0
```

Allow `leg_route_id` in `CandidateConfig`, require it for bond candidates, and require two to eight
unique values. Accept `mode in {"single", "race", "bond"}`; require `transport == "tcp"` for bond.

Add:

```python
async def read_bond_hello_route_id(reader: asyncio.StreamReader) -> tuple[int, bytes]:
    header = await asyncio.wait_for(reader.readexactly(LBND_HEADER_LEN), BOND_HEADER_TIMEOUT_SECONDS)
    if header[:4] != LBND_MAGIC or header[4] != LBND_VERSION or header[5] != LBND_HELLO_KIND:
        raise RuntimeError("invalid LBND hello header")
    leg_route_id = struct.unpack(">I", header[24:28])[0]
    payload_len = struct.unpack(">I", header[28:32])[0]
    if leg_route_id == 0:
        raise RuntimeError("LBND hello leg route is missing")
    if payload_len > LBND_MAX_HELLO_PAYLOAD:
        raise RuntimeError("LBND hello payload is too large")
    return leg_route_id, header
```

- [ ] **Step 4: Implement exact-route TCP handling without target probes**

Build `self._bond_candidates_by_route`. In bond mode, skip startup, periodic, and reload
`selector.probe_once()`. Route `handle_tcp` to `handle_bond_tcp`, which reads the header, resolves one
candidate, calls `open_socks_connect` only for it, writes the untouched header, and uses the existing
bounded copy in both directions. Unknown or failed routes close the connection.

- [ ] **Step 5: Run relay tests and verify GREEN**

Run the relay suite again. Expected: all existing and new tests pass.

- [ ] **Step 6: Commit deterministic demux**

```powershell
git add slave-agent/leigod_forward_relay.py slave-agent/tests/test_leigod_forward_relay.py
git commit -m "feat(slave): demultiplex fixed bond legs by route identity"
```

### Task 4: Validate the Slave Publish Contract

**Files:**
- Modify: `slave-agent/tests/test_slave_leigod_forward_publish.py`
- Modify: `slave-agent/slave_agent.py:3818-3968`

- [ ] **Step 1: Write failing publish tests**

Add a `mode="bond"` case with two candidates containing `leg_route_id` and assert:

```python
self.assertEqual("bond", config["mode"])
self.assertEqual("tcp", config["transport"])
self.assertEqual([451, 453], [row["leg_route_id"] for row in config["candidates"]])
self.assertEqual(
    {"combination_id", "line_id", "leg_route_id", "socks_host", "socks_port", "sort_order"},
    set(config["candidates"][0]),
)
```

Add rejection tests for one leg, duplicate leg route, and route/line mismatch. Add a positive case
where application `transport_mode=tcp+udp` still emits relay `transport=tcp`, because UDP bond frames
also use the TCP LBND carrier.

- [ ] **Step 2: Run the agent test and verify RED**

Run:

```powershell
python -m unittest slave-agent/tests/test_slave_leigod_forward_publish.py -v
```

Expected: `invalid leigod relay mode` or missing runtime route fields.

- [ ] **Step 3: Implement strict bond validation**

Accept `mode=bond`, require two candidates, require `leg_route_id == line_id`, and emit
`leg_route_id` into both metadata and runtime config. For bond, map any supported application
transport (`tcp`, `udp`, or `tcp+udp`) to relay `transport=tcp`; keep the top-level application
transport unchanged. Keep the existing SHA, local-health freshness, and exact SOCKS port checks.

- [ ] **Step 4: Run slave-agent focused tests**

Run:

```powershell
python -m unittest slave-agent/tests/test_slave_leigod_forward_publish.py slave-agent/tests/test_leigod_forward_relay.py -v
```

Expected: all tests pass.

- [ ] **Step 5: Commit slave validation**

```powershell
git add slave-agent/slave_agent.py slave-agent/tests/test_slave_leigod_forward_publish.py
git commit -m "feat(slave): validate fixed-endpoint bond relay payloads"
```

### Task 5: Publish One Physical Endpoint and Logical Fixed Legs

**Files:**
- Create: `master/tests/leigod_fixed_endpoint_bond_contract_test.php`
- Modify: `master/tests/provisioning_specific_node_persistence_regression_test.php`
- Modify: `master/src/Support/ProvisioningService.php:611-650,5019-5134,5403-5426,7138-7201,7745-7878,9358-9437`

- [ ] **Step 1: Write the focused failing PHP contract test**

Create a fixture for one `leigod_mode/bond` forward. Seed point host
`198.51.100.25`, bond port `18501`, node `15`, public host `203.0.113.15`, public port `7003`, and:

```php
'custom_endpoint_ids_json' => json_encode(['specific_node_id' => 15]),
'bond_sidecar_point_agent_config_json' => json_encode([
    'fixed_endpoint_leigod_bond' => true,
]),
```

one physical endpoint on node 15, point bond port `18501`, and five fresh line candidates. Assert:

```php
assertSame(1, count($config['endpoints'] ?? []), 'one physical endpoint');
assertSame(true, $config['bonding']['fixed_endpoint'] ?? null, 'fixed endpoint flag');
assertSame(
    [447, 451, 453, 455, 456],
    array_column($config['bonding']['fixed_endpoint_legs'] ?? [], 'leg_route_id'),
    'logical leg routes'
);
assertSame(5, $config['bonding']['preferred_initial_legs'] ?? null, 'preferred legs');
assertSame(2, $config['bonding']['minimum_initial_legs'] ?? null, 'minimum legs');
```

Invoke `publishTaskPayloadForForward` and assert the slave backend target is loopback, the real relay
target is the sidecar public bond target, mode is bond, and no static sidecar-uplink payload remains:

```php
assertSame('127.0.0.1:48316', $payload['target'] ?? null, 'slave loopback target');
assertSame('bond', $payload['leigod_forward']['mode'] ?? null, 'relay mode');
assertSame('198.51.100.25:18501', $payload['leigod_forward']['target'] ?? null, 'real target');
assertSame([], $payload['sidecar_uplink'] ?? null, 'no competing static uplink');
```

- [ ] **Step 2: Add gated pin-preservation and ungated-regression assertions**

In `provisioning_specific_node_persistence_regression_test.php`, add one gated LeiGod bond combination
case that retains `specific_node_id`, while keeping the existing assertion that an ordinary
combination clears stale exact-node metadata.

- [ ] **Step 3: Run both PHP tests and verify RED**

Run:

```powershell
wsl -d Ubuntu -- bash -lc "cd '/mnt/c/Users/frankniubi/Downloads/leikwan-br1/.worktrees/sidecar-shared-wg-peers' && php master/tests/leigod_fixed_endpoint_bond_contract_test.php && php master/tests/provisioning_specific_node_persistence_regression_test.php"
```

Expected: fixed endpoint flag/legs are absent and the combination pin is cleared.

- [ ] **Step 4: Implement a single activation predicate**

Add `usesFixedEndpointLeigodBond(array $forward): bool` that requires:

```php
$backendMode === 'leigod_mode'
&& $clientMode === 'bond'
&& $pointId > 0
&& $this->configValueEnabled($pointConfig['fixed_endpoint_leigod_bond'] ?? false)
&& $this->specificNodeIdFromCustomEndpointIds($forward['custom_endpoint_ids_json'] ?? null) !== null
```

Resolve point config from the joined row when available. Otherwise fetch
`SELECT agent_config_json FROM sidecar_points WHERE id = :id LIMIT 1`, decode it with
`decodeJsonAssoc`, and evaluate it with `configValueEnabled`, matching the existing
`usesSingleRaceSidecarFrontend` behavior.

- [ ] **Step 5: Preserve and validate the exact-node pin only for the gated shape**

Capture a requested/persisted selector such as `_user_node_15` before combination normalization. For an enabled
fixed bond retain it; for every other combination continue clearing it. Extend LeiGod eligibility
checks to the gated bond shape and require at least two candidates on that exact node.

In reconciliation, choose the pinned node before `pickFastestSlavesForCombination`; if it is missing
or ineligible, publish no replacement node.

- [ ] **Step 6: Build slave and client contracts from one candidate list**

For fixed bond, call `candidatesForForwardOnNode` once, require 2-8 candidates, add
`leg_route_id=line_id`, set slave `leigod_forward.mode=bond`, set the backend target to the stable
loopback relay, and set relay real target from `resolveSidecarDirectTarget($forward, 'bond')`.

Before returning client config, filter fixed-bond endpoint rows to the pinned node and require exactly
one usable physical endpoint; stale rows being destroyed must never become client legs. Extend
`buildBondClientConfig` with:

```php
'fixed_endpoint' => true,
'fixed_endpoint_legs' => array_map(static fn(array $candidate): array => [
    'leg_route_id' => (int) $candidate['line_id'],
    'priority' => 10,
], $candidates),
'preferred_initial_legs' => count($candidates),
'minimum_initial_legs' => min(2, count($candidates)),
'initial_leg_wait_ms' => 3000,
'leg_reconnect_backoff_ms' => 5000,
'leg_reconnect_backoff_max_ms' => 60000,
'quic_enabled' => false,
'quic_via_slave' => false,
```

- [ ] **Step 7: Run focused and adjacent PHP tests and verify GREEN**

Run the two new/focused tests plus:

```powershell
wsl -d Ubuntu -- bash -lc "cd '/mnt/c/Users/frankniubi/Downloads/leikwan-br1/.worktrees/sidecar-shared-wg-peers' && php master/tests/provisioning_leigod_dedicated_contract_regression_test.php && php master/tests/publish_dedicated_leigod_candidates_test.php && php master/tests/bond_leigod_leg_cooldown_publish_test.php"
```

Expected: all scripts print `ok` or `OK` and exit zero.

- [ ] **Step 8: Commit the panel contract**

```powershell
git add master/src/Support/ProvisioningService.php master/tests/leigod_fixed_endpoint_bond_contract_test.php master/tests/provisioning_specific_node_persistence_regression_test.php
git commit -m "feat(panel): publish pinned fixed-endpoint leigod bond"
```

### Task 6: Render One Linux Ingress Helper and N Logical Legs

**Files:**
- Create: `client-agent/tests/test_leigod_fixed_endpoint_bond_runtime.py`
- Modify: `client-agent/install_forward_runtime.py:185-209,730-1050`

- [ ] **Step 1: Write a failing Linux runtime command-shape test**

Build one forward with one physical endpoint and five fixed legs. Assert:

```python
self.assertEqual(1, script.count("leikwan-ingress client --listen"))
self.assertEqual(1, script.count("--remote 203.0.113.15:7003"))
self.assertEqual(2, script.count("leikwan-bond client --protocol"))
for leg_route_id in (447, 451, 453, 455, 456):
    self.assertEqual(2, script.count(f"leg_route_id={leg_route_id}"))
self.assertIn("--preferred-initial-legs 5", script)
self.assertIn("--minimum-initial-legs 2", script)
self.assertIn("--initial-leg-wait-ms 3000", script)
self.assertIn("--leg-reconnect-backoff-ms 5000", script)
self.assertIn("--leg-reconnect-backoff-max-ms 60000", script)
```

Also assert duplicate/zero route IDs and more than eight legs raise `RuntimeError`, while ordinary
bond rendering is byte-for-byte unchanged in the relevant command fragments.

- [ ] **Step 2: Run the new test and verify RED**

Run:

```powershell
python -m unittest client-agent/tests/test_leigod_fixed_endpoint_bond_runtime.py -v
```

Expected: one physical endpoint produces one leg and no fixed readiness flags.

- [ ] **Step 3: Implement fixed-leg normalization and shared helper rendering**

Add a helper that returns validated logical rows:

```python
def fixed_endpoint_legs(bond: dict) -> list[dict]:
    if not truthy(bond.get("fixed_endpoint", False)):
        return []
    rows = bond.get("fixed_endpoint_legs", [])
    if not isinstance(rows, list) or not 2 <= len(rows) <= 8:
        raise RuntimeError("fixed endpoint bond requires between two and eight logical legs")
    normalized = []
    seen = set()
    for row in rows:
        leg_route_id = int_or_default(row.get("leg_route_id") if isinstance(row, dict) else 0, 0)
        if leg_route_id <= 0 or leg_route_id in seen:
            raise RuntimeError("fixed endpoint bond leg routes must be unique and positive")
        seen.add(leg_route_id)
        normalized.append({"leg_route_id": leg_route_id, "priority": int_or_default(row.get("priority"), 10)})
    return normalized
```

For the fixed shape, require exactly one endpoint, start one ingress helper/listener, and render all
TCP and UDP carrier leg URIs to that same local listener with distinct `leg_route_id` query values.

- [ ] **Step 4: Run Linux runtime tests and verify GREEN**

Run the new test plus existing sidecar frontend and bond runtime tests. Expected: all pass.

- [ ] **Step 5: Commit Linux rendering**

```powershell
git add client-agent/install_forward_runtime.py client-agent/tests/test_leigod_fixed_endpoint_bond_runtime.py
git commit -m "feat(client): expand fixed endpoint into logical bond legs"
```

### Task 7: Add Fixed-Bond Initial Readiness and Bounded Reconnect

**Files:**
- Modify: `wstunnel-main/leikwan-bond/src/main.rs:24-140,200-end`
- Modify: `wstunnel-main/leikwan-bond/src/session.rs:35-70,1469-1730,5504-end`

- [ ] **Step 1: Add failing CLI/default tests**

Parse these flags and assert their values:

```text
--preferred-initial-legs 5
--minimum-initial-legs 2
--initial-leg-wait-ms 3000
--leg-reconnect-backoff-ms 5000
--leg-reconnect-backoff-max-ms 60000
```

Add a default test asserting legacy values remain preferred=2, minimum=1, wait=600 ms, and reconnect
initial/max=30 minutes.

- [ ] **Step 2: Add failing readiness/backoff unit tests**

Extract pure helpers and test:

```rust
assert!(initial_leg_quorum_met(2, 5, 2));
assert!(!initial_leg_quorum_met(1, 5, 2));
assert_eq!(bounded_retry_backoff(Duration::from_secs(5), Duration::from_secs(60), 0), Duration::from_secs(5));
assert_eq!(bounded_retry_backoff(Duration::from_secs(5), Duration::from_secs(60), 4), Duration::from_secs(60));
```

- [ ] **Step 3: Run focused Rust tests and verify RED**

Expected: flags, fields, and helpers are missing.

- [ ] **Step 4: Extend `SessionConfig` and CLI with validated bounds**

Add duration/count fields. Reject minimum zero, minimum greater than preferred, an initial retry
above its max, and zero wait/backoff values. At session open, clamp the effective preferred count to
the actual spec count so legacy one-leg frontends keep working; reject only when the minimum exceeds
the actual spec count.

- [ ] **Step 5: Replace the hard-coded 600 ms / two-leg bootstrap for configured fixed bonds**

Pass `&SessionConfig` into `connect_client_tcp_transport_legs`. Collect acknowledgments until the
preferred count is ready, all tasks finish, or the bounded initial wait expires. Return success only
when minimum quorum is met; otherwise abort remaining tasks and return an error containing
`initial bond leg quorum`.

- [ ] **Step 6: Implement per-spec jittered exponential reconnect**

Replace `CLIENT_LEG_RETRY_BACKOFF` use in the connector with per-spec failure attempts and
`bounded_retry_backoff`. Apply up to 20% deterministic jitter derived from spec index, reset attempts
after hello ACK, and retain 30-minute defaults for ordinary configs.

- [ ] **Step 7: Run full Rust and Linux runtime tests**

Expected: all Rust tests and fixed runtime command-shape tests pass.

- [ ] **Step 8: Commit readiness and reconnect behavior**

```powershell
git add wstunnel-main/leikwan-bond/src/main.rs wstunnel-main/leikwan-bond/src/session.rs client-agent/install_forward_runtime.py client-agent/tests/test_leigod_fixed_endpoint_bond_runtime.py
git commit -m "feat(bond): wait for fixed leg readiness and retry boundedly"
```

### Task 8: Add Route-Aware Runtime Observability

**Files:**
- Modify: `wstunnel-main/leikwan-bond/src/session.rs:213-228,417-437,559-600,4140-4270,5504-end`
- Modify: `slave-agent/leigod_forward_relay.py:131-162,620-670`
- Modify: `slave-agent/tests/test_leigod_forward_relay.py`

- [ ] **Step 1: Write failing snapshot assertions**

Extend the Rust snapshot test so a fake leg with route 453 serializes:

```json
{
  "leg_route_id": 453,
  "initial_ready": true,
  "added_late": false,
  "reconnect_count": 0,
  "scheduled_bytes": 0,
  "cooldown_count": 0
}
```

Extend relay tests so state contains bounded per-route counters and no token/config material.

- [ ] **Step 2: Run focused tests and verify RED**

Expected: snapshot/state fields are absent.

- [ ] **Step 3: Carry bootstrap and route metadata into `OpenLeg`/`LegState`**

Record `initial_connect_ms`, `hello_ack_ms`, whether a leg was in the initial returned set, whether it
was added late, and reconnect count. Increment `scheduled_bytes` for data frames and
`cooldown_count` in the existing cooldown function. Serialize route and counters in
`SnapshotLegView`.

- [ ] **Step 4: Record relay route counters atomically**

For bond connections record accepted, active, connect successes/failures, bytes up/down, and the last
bounded error per `leg_route_id`. Keep at most one row per configured route and reuse the atomic
state-file replacement.

- [ ] **Step 5: Run Rust and relay suites and verify GREEN**

Expected: all tests pass and state JSON contains no secret values.

- [ ] **Step 6: Commit observability**

```powershell
git add wstunnel-main/leikwan-bond/src/session.rs slave-agent/leigod_forward_relay.py slave-agent/tests/test_leigod_forward_relay.py
git commit -m "feat(bond): expose fixed leg bootstrap and route counters"
```

### Task 9: Synchronize Sources, Build Release, and Run Local Gates

**Files:**
- Modify: `wstunnel-main/leikwan-bond/Cargo.toml`
- Modify: `artifacts/install-forward-runtime.py`
- Modify: `artifacts/slave-agent.py`
- Modify: `artifacts/wstunnel/leikwan-bond_linux_amd64`
- Modify: `artifacts/versions.json`

- [ ] **Step 1: Bump source versions**

Set `leikwan-bond` to `0.1.31`, increment `slave-agent/slave_agent.py` and relay date versions, and
increment the `install_forward_runtime` manifest version. Do not change the client-agent or Windows
artifact versions in this Linux canary.

- [ ] **Step 2: Synchronize generated artifact mirrors mechanically**

Copy `client-agent/install_forward_runtime.py` to `artifacts/install-forward-runtime.py` and
`slave-agent/slave_agent.py` to `artifacts/slave-agent.py`. Calculate SHA-256 and sizes directly from
`slave-agent/leigod_forward_relay.py` for its routed artifact entry. Update only those matching
manifest entries.

- [ ] **Step 3: Build the static Linux artifact in WSL**

Run:

```powershell
wsl -d Ubuntu -- bash -lc "cd '/mnt/c/Users/frankniubi/Downloads/leikwan-br1/.worktrees/sidecar-shared-wg-peers/wstunnel-main' && cargo build --release --target x86_64-unknown-linux-musl -p leikwan-bond"
```

Copy the produced binary to `artifacts/wstunnel/leikwan-bond_linux_amd64`, then verify with `file` that
it is static x86-64 and has no GLIBC dependency.

- [ ] **Step 4: Run local release gates serially**

Run:

```powershell
python -m unittest slave-agent/tests/test_leigod_forward_relay.py slave-agent/tests/test_slave_leigod_forward_publish.py -v
python -m unittest client-agent/tests/test_leigod_fixed_endpoint_bond_runtime.py client-agent/tests/test_single_race_sidecar_frontend.py client-agent/tests/test_artifact_versions_consistency.py slave-agent/tests/test_slave_artifact_consistency.py -v
wsl -d Ubuntu -- bash -lc "cd '/mnt/c/Users/frankniubi/Downloads/leikwan-br1/.worktrees/sidecar-shared-wg-peers' && cargo test --manifest-path wstunnel-main/leikwan-bond/Cargo.toml && php master/tests/leigod_fixed_endpoint_bond_contract_test.php && php master/tests/provisioning_specific_node_persistence_regression_test.php && php master/tests/provisioning_leigod_dedicated_contract_regression_test.php && php master/tests/publish_dedicated_leigod_candidates_test.php && php master/tests/bond_versions_manifest_regression_test.php && php master/tests/leigod_forward_helper_artifact_distribution_test.php"
git diff --check
```

Expected: every command exits zero.

- [ ] **Step 5: Commit the release artifact set**

```powershell
git add wstunnel-main/leikwan-bond/Cargo.toml wstunnel-main/leikwan-bond/Cargo.lock artifacts/install-forward-runtime.py artifacts/slave-agent.py artifacts/wstunnel/leikwan-bond_linux_amd64 artifacts/versions.json slave-agent/slave_agent.py slave-agent/leigod_forward_relay.py
git commit -m "release: publish fixed-endpoint bond 0.1.31"
```

- [ ] **Step 6: Verify the commit boundary**

Confirm `git show --stat HEAD` contains only release/source mirrors and the pre-existing handoff
remains the only unrelated dirty file.

### Task 10: Prove Local Integration Before Any Server Change

**Files:**
- Create: `slave-agent/tests/test_leigod_fixed_bond_integration.py`

- [ ] **Step 1: Write the failing process-level integration test**

Start the real relay module with two fake SOCKS servers and a minimal LBND target that reads all hello
frames for one session and replies with `HelloAck`. Launch the built `leikwan-bond` binary with:

```text
--legs tcp://127.0.0.1:{relay_port}?leg_route_id=451,tcp://127.0.0.1:{relay_port}?leg_route_id=453
--preferred-initial-legs 2 --minimum-initial-legs 2 --initial-leg-wait-ms 3000
```

The test allocates `{relay_port}` with `free_loopback_port()` at runtime. Record the route ID observed by each fake SOCKS server and assert the set is
`{451, 453}` even when route 451 delays SOCKS CONNECT by 200 ms.

- [ ] **Step 2: Run the process-level test and verify RED**

Run:

```powershell
python -m unittest slave-agent/tests/test_leigod_fixed_bond_integration.py -v
```

Expected: failure until the release binary contains route-aware hello and readiness flags.

- [ ] **Step 3: Make only test-harness corrections, then verify GREEN**

Do not change production behavior without first adding a narrower failing regression. Once the
release artifact is current, the integration test must pass twice: normal connection order and
delayed/reversed order.

- [ ] **Step 4: Run a local zero/unknown-route smoke**

The same test sends raw hello headers with route zero and `999`; assert EOF, no new SOCKS CONNECT,
and unchanged active route counters.

- [ ] **Step 5: Commit the integration test**

```powershell
git add slave-agent/tests/test_leigod_fixed_bond_integration.py
git commit -m "test: cover fixed bond process integration"
```
