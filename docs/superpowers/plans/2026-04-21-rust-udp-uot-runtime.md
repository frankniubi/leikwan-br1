# Rust UDP UoT Runtime Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the current Python UDP UoT data-plane runtimes with Rust binaries while preserving the existing `client -> slave -> sidecar` topology, plain TCP mux route-key contract, weighted leg auth, and runtime health behavior.

**Architecture:** Keep the current JSON config schemas and service topology, but move the hot packet loop, frame codec, merge buffer, and leg penalty logic into a new Rust crate under `wstunnel-main/`. Build two drop-in binaries, `leikwan-uot-bond` and `leikwan-udp-uot-merge`, so the agent/control-plane changes stay small and the live rollout remains sidecar-first then client. Do not introduce `phantun` in this migration; that remains a separate future tunnel-engine experiment.

**Tech Stack:** Rust 2021, `tokio`, `bytes`, `serde`, `serde_json`, `clap`, `anyhow`, `hmac`, `sha2`, `base64`, existing `leikwan-ingress` plain TCP mux path, systemd-managed binaries, panel bullseye build chain.

---

## File Structure

### New files to create
- `wstunnel-main/leikwan-uot/Cargo.toml`
  - New crate manifest for the Rust UDP UoT runtime
- `wstunnel-main/leikwan-uot/src/lib.rs`
  - Shared exports for frame/config/runtime modules
- `wstunnel-main/leikwan-uot/src/frame.rs`
  - Shared binary frame codec used by both client and sidecar
- `wstunnel-main/leikwan-uot/src/config.rs`
  - JSON config loaders for client and sidecar runtimes
- `wstunnel-main/leikwan-uot/src/merge.rs`
  - Shared reorder/merge buffer implementation
- `wstunnel-main/leikwan-uot/src/client_runtime.rs`
  - Rust replacement for `client-agent/leikwan_uot_bond.py`
- `wstunnel-main/leikwan-uot/src/sidecar_runtime.rs`
  - Rust replacement for `sidecar-agent/leikwan_udp_uot_merge.py`
- `wstunnel-main/leikwan-uot/src/bin/leikwan-uot-bond.rs`
  - Client-side runtime entrypoint
- `wstunnel-main/leikwan-uot/src/bin/leikwan-udp-uot-merge.rs`
  - Sidecar-side runtime entrypoint

### Existing files to modify
- `wstunnel-main/Cargo.toml`
  - Add the new workspace member
- `client-agent/install_forward_runtime.py`
  - Stop rendering Python execs for UDP UoT; render the Rust binary instead
- `sidecar-agent/sidecar_agent.py`
  - Stop rendering Python execs for UDP UoT merge; render the Rust binary instead
- `client-agent/tests/test_mptcp_bond_regressions.py`
  - Update runtime/service rendering assertions
- `client-agent/tests/test_uot_bond_runtime.py`
  - Port the behavior tests to hit the Rust design surface where possible, or retire the Python-only expectations once Rust parity tests exist
- `sidecar-agent/tests/test_udp_uot_merge_runtime.py`
  - Same as above for sidecar behavior parity
- `docs/BOND_OPERATIONS.md`
  - Document that UDP UoT is now Rust-based and built on bullseye

### Existing files to inspect while implementing
- `client-agent/leikwan_uot_bond.py`
  - Current client behavior reference
- `sidecar-agent/leikwan_udp_uot_merge.py`
  - Current sidecar behavior reference
- `wstunnel-main/leikwan-ingress/src/client.rs`
  - Plain TCP mux open/ping contract
- `wstunnel-main/leikwan-bond/src/token.rs`
  - Existing token verification logic patterns
- `docs/superpowers/plans/2026-04-20-udp-uot-bad-leg-health.md`
  - Current rollout/verification sequence

---

### Task 1: Add the Rust crate and shared frame/config modules

**Files:**
- Modify: `wstunnel-main/Cargo.toml`
- Create: `wstunnel-main/leikwan-uot/Cargo.toml`
- Create: `wstunnel-main/leikwan-uot/src/lib.rs`
- Create: `wstunnel-main/leikwan-uot/src/frame.rs`
- Create: `wstunnel-main/leikwan-uot/src/config.rs`

- [ ] **Step 1: Write the failing shared-module tests first**

Create `wstunnel-main/leikwan-uot/src/frame.rs` with these tests at the bottom:

```rust
#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn encode_and_decode_frame_round_trip() {
        let frame = DataFrame {
            version: 1,
            msg_type: MsgType::Data,
            flags: 0,
            session_id: 7,
            seq: 11,
            payload: b"hello".to_vec(),
        };
        let encoded = encode_frame(&frame);
        let decoded = decode_frame(&encoded).expect("decode");
        assert_eq!(decoded.session_id, 7);
        assert_eq!(decoded.seq, 11);
        assert_eq!(decoded.payload, b"hello");
    }

    #[test]
    fn decode_rejects_trailing_bytes() {
        let frame = DataFrame {
            version: 1,
            msg_type: MsgType::Data,
            flags: 0,
            session_id: 5,
            seq: 9,
            payload: b"abc".to_vec(),
        };
        let mut encoded = encode_frame(&frame);
        encoded.extend_from_slice(b"trailing");
        assert!(decode_frame(&encoded).is_err());
    }
}
```

Create `wstunnel-main/leikwan-uot/src/config.rs` with these tests:

```rust
#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn client_config_accepts_existing_json_shape() {
        let raw = r#"{
          "forward_id": 26,
          "listen_udp": "0.0.0.0:11024",
          "route_key": "a43547b6350f72cd.uot",
          "session_token": "udp-token",
          "reorder_window_packets": 4096,
          "reorder_window_bytes": 16777216,
          "reorder_timeout_ms": 25,
          "legs": [
            {
              "endpoint_id": 184,
              "host": "8.163.90.132",
              "port": 7001,
              "auth_token": "token-1",
              "dynamic_priority": 10,
              "health_role": "healthy",
              "weight": 8
            }
          ]
        }"#;
        let parsed: ClientConfig = serde_json::from_str(raw).expect("client config");
        assert_eq!(parsed.forward_id, 26);
        assert_eq!(parsed.legs[0].weight, 8);
    }

    #[test]
    fn sidecar_config_accepts_existing_json_shape() {
        let raw = r#"{
          "listen_host": "0.0.0.0",
          "listen_port": 8999,
          "token_secret": "secret-123",
          "allowed_forward_ids": [26],
          "reorder_window_packets": 4096,
          "reorder_window_bytes": 16777216,
          "reorder_timeout_ms": 25,
          "stats_path": "/var/lib/leikwan-sidecar-4/udp-uot-runtime-stats.json"
        }"#;
        let parsed: SidecarConfig = serde_json::from_str(raw).expect("sidecar config");
        assert_eq!(parsed.listen_port, 8999);
        assert_eq!(parsed.allowed_forward_ids, vec![26]);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run:

```bash
cd wstunnel-main
cargo test -p leikwan-uot frame::tests::encode_and_decode_frame_round_trip -- --exact
cargo test -p leikwan-uot config::tests::client_config_accepts_existing_json_shape -- --exact
```

Expected: FAIL because the new crate does not exist yet.

- [ ] **Step 3: Implement the minimal shared crate**

Update `wstunnel-main/Cargo.toml`:

```toml
[workspace]
members = ["leikwan-bond", "leikwan-ingress", "leikwan-mptcp-relay", "leikwan-uot", "leikwan-client-win/src-tauri"]
resolver = "2"
```

Create `wstunnel-main/leikwan-uot/Cargo.toml`:

```toml
[package]
name = "leikwan-uot"
version = "0.1.0"
edition = "2021"

[dependencies]
anyhow = "1"
base64 = "0.22"
bytes = "1"
clap = { version = "4", features = ["derive"] }
hmac = "0.12"
serde = { version = "1", features = ["derive"] }
serde_json = "1"
sha2 = "0.10"
tokio = { version = "1", features = ["full"] }
```

Create `wstunnel-main/leikwan-uot/src/lib.rs`:

```rust
pub mod config;
pub mod frame;
pub mod merge;
pub mod client_runtime;
pub mod sidecar_runtime;
```

Implement the same binary frame layout in `src/frame.rs` and serde config structs in `src/config.rs` matching the current JSON keys exactly.

- [ ] **Step 4: Run the tests to verify they pass**

Run:

```bash
cd wstunnel-main
cargo test -p leikwan-uot frame::tests::encode_and_decode_frame_round_trip -- --exact
cargo test -p leikwan-uot frame::tests::decode_rejects_trailing_bytes -- --exact
cargo test -p leikwan-uot config::tests::client_config_accepts_existing_json_shape -- --exact
cargo test -p leikwan-uot config::tests::sidecar_config_accepts_existing_json_shape -- --exact
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add wstunnel-main/Cargo.toml wstunnel-main/leikwan-uot
git commit -m "feat: scaffold rust udp uot crate"
```

### Task 2: Port the shared merge/reorder buffer to Rust

**Files:**
- Create: `wstunnel-main/leikwan-uot/src/merge.rs`
- Test: `wstunnel-main/leikwan-uot/src/merge.rs`

- [ ] **Step 1: Write the failing merge-buffer tests**

Create tests in `src/merge.rs`:

```rust
#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn merge_buffer_releases_in_order_and_skips_gap_after_timeout() {
        let mut buffer = MergeBuffer::new(16, 4096, 1);
        assert!(buffer.push(4, 2, b"two".to_vec()).is_empty());
        let ready = buffer.push(4, 1, b"one".to_vec());
        assert_eq!(ready.len(), 2);
        assert_eq!(ready[0].seq, 1);
        assert_eq!(ready[1].seq, 2);
        assert!(buffer.push(4, 4, b"four".to_vec()).is_empty());
        let skipped = buffer.flush_timeouts(buffer.session_state.get_mut(&4).unwrap().gap_started_at.unwrap() + 10);
        assert_eq!(skipped.len(), 1);
        assert_eq!(skipped[0].seq, 4);
    }

    #[test]
    fn weighted_selector_honors_weight_and_skips_inactive() {
        let mut selector = WeightedLegSelector::new(vec![
            LegState::new(184, 2, true),
            LegState::new(185, 1, false),
            LegState::new(186, 1, true),
        ]);
        let picks = (0..6).map(|_| selector.pick_leg().unwrap().endpoint_id).collect::<Vec<_>>();
        assert_eq!(picks, vec![184, 184, 186, 184, 184, 186]);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run:

```bash
cd wstunnel-main
cargo test -p leikwan-uot merge::tests::merge_buffer_releases_in_order_and_skips_gap_after_timeout -- --exact
cargo test -p leikwan-uot merge::tests::weighted_selector_honors_weight_and_skips_inactive -- --exact
```

Expected: FAIL because `MergeBuffer` and `WeightedLegSelector` are not implemented yet.

- [ ] **Step 3: Implement the minimal merge/selector logic**

Port the current Python behavior directly:
- buffered packet map keyed by `seq`
- timeout-driven gap flush
- weighted round-robin sequence rebuilt on refresh
- inactive legs skipped, zero-weight legs omitted

- [ ] **Step 4: Run the tests to verify they pass**

Run:

```bash
cd wstunnel-main
cargo test -p leikwan-uot merge::tests::merge_buffer_releases_in_order_and_skips_gap_after_timeout -- --exact
cargo test -p leikwan-uot merge::tests::weighted_selector_honors_weight_and_skips_inactive -- --exact
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add wstunnel-main/leikwan-uot/src/merge.rs
git commit -m "feat: port udp uot merge buffer to rust"
```

### Task 3: Implement the Rust client UoT runtime (`leikwan-uot-bond`)

**Files:**
- Create: `wstunnel-main/leikwan-uot/src/client_runtime.rs`
- Create: `wstunnel-main/leikwan-uot/src/bin/leikwan-uot-bond.rs`
- Test: `wstunnel-main/leikwan-uot/src/client_runtime.rs`

- [ ] **Step 1: Write the failing client-runtime parity tests**

Add tests:

```rust
#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn build_leg_auth_payload_encodes_token_endpoint_and_weight() {
        let payload = build_leg_auth_payload(184, 8, "udp-token");
        let decoded: serde_json::Value = serde_json::from_slice(&payload).expect("json");
        assert_eq!(decoded["token"], "udp-token");
        assert_eq!(decoded["endpoint_id"], 184);
        assert_eq!(decoded["weight"], 8);
    }

    #[test]
    fn penalize_leg_quarantines_after_three_failures() {
        let mut leg = RuntimeLegState::new(184, 8);
        penalize_leg(&mut leg, 1000);
        assert!(leg.weight >= 1);
        penalize_leg(&mut leg, 1100);
        assert!(leg.suppress_until_ms >= 1100);
        penalize_leg(&mut leg, 1200);
        assert_eq!(leg.weight, 0);
        assert!(leg.suppress_until_ms > 1200);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run:

```bash
cd wstunnel-main
cargo test -p leikwan-uot client_runtime::tests::build_leg_auth_payload_encodes_token_endpoint_and_weight -- --exact
cargo test -p leikwan-uot client_runtime::tests::penalize_leg_quarantines_after_three_failures -- --exact
```

Expected: FAIL

- [ ] **Step 3: Implement the Rust client runtime**

Port these behaviors from `client-agent/leikwan_uot_bond.py`:
- plain TCP mux `OPEN`
- auth frame with JSON payload
- per-leg outbound queues
- weighted leg selector
- probation/quarantine state machine
- keepalive frames
- UDP socket session tracking
- stats file output

Create `src/bin/leikwan-uot-bond.rs`:

```rust
use clap::Parser;

#[derive(Parser)]
struct Args {
    #[arg(long)]
    config: String,
}

#[tokio::main]
async fn main() -> anyhow::Result<()> {
    let args = Args::parse();
    leikwan_uot::client_runtime::run_from_path(&args.config).await
}
```

- [ ] **Step 4: Run the targeted tests to verify they pass**

Run:

```bash
cd wstunnel-main
cargo test -p leikwan-uot client_runtime::tests::build_leg_auth_payload_encodes_token_endpoint_and_weight -- --exact
cargo test -p leikwan-uot client_runtime::tests::penalize_leg_quarantines_after_three_failures -- --exact
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add wstunnel-main/leikwan-uot/src/client_runtime.rs wstunnel-main/leikwan-uot/src/bin/leikwan-uot-bond.rs
git commit -m "feat: port client udp uot runtime to rust"
```

### Task 4: Implement the Rust sidecar UoT merge runtime (`leikwan-udp-uot-merge`)

**Files:**
- Create: `wstunnel-main/leikwan-uot/src/sidecar_runtime.rs`
- Create: `wstunnel-main/leikwan-uot/src/bin/leikwan-udp-uot-merge.rs`
- Test: `wstunnel-main/leikwan-uot/src/sidecar_runtime.rs`

- [ ] **Step 1: Write the failing sidecar-runtime parity tests**

Add tests:

```rust
#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parse_leg_auth_payload_accepts_weighted_json_payload() {
        let payload = br#"{"token":"udp-token","endpoint_id":184,"weight":8}"#;
        let parsed = parse_leg_auth_payload(payload).expect("parse");
        assert_eq!(parsed.token, "udp-token");
        assert_eq!(parsed.endpoint_id, 184);
        assert_eq!(parsed.weight, 8);
    }

    #[test]
    fn parse_leg_auth_payload_accepts_legacy_plain_token() {
        let parsed = parse_leg_auth_payload(b"legacy-token").expect("parse");
        assert_eq!(parsed.token, "legacy-token");
        assert_eq!(parsed.endpoint_id, 0);
        assert_eq!(parsed.weight, 1);
    }

    #[test]
    fn apply_endpoint_failure_quarantines_after_three_failures() {
        let mut penalties = EndpointPenalties::default();
        penalties.apply_failure(184, 1000);
        penalties.apply_failure(184, 1100);
        penalties.apply_failure(184, 1200);
        let penalty = penalties.entries.get(&184).expect("penalty");
        assert_eq!(penalty.failures, 3);
        assert!(penalty.suppress_until_ms > 1200);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run:

```bash
cd wstunnel-main
cargo test -p leikwan-uot sidecar_runtime::tests::parse_leg_auth_payload_accepts_weighted_json_payload -- --exact
cargo test -p leikwan-uot sidecar_runtime::tests::apply_endpoint_failure_quarantines_after_three_failures -- --exact
```

Expected: FAIL

- [ ] **Step 3: Implement the Rust sidecar runtime**

Port these behaviors from `sidecar-agent/leikwan_udp_uot_merge.py`:
- token verification
- scope key and session key tracking
- weighted leg registration
- endpoint penalty suppression
- reorder buffer dispatch to target UDP socket
- reverse-path send back to selected leg
- stats file output

Create `src/bin/leikwan-udp-uot-merge.rs`:

```rust
use clap::Parser;

#[derive(Parser)]
struct Args {
    #[arg(long)]
    config: String,
}

#[tokio::main]
async fn main() -> anyhow::Result<()> {
    let args = Args::parse();
    leikwan_uot::sidecar_runtime::run_from_path(&args.config).await
}
```

- [ ] **Step 4: Run the targeted tests to verify they pass**

Run:

```bash
cd wstunnel-main
cargo test -p leikwan-uot sidecar_runtime::tests::parse_leg_auth_payload_accepts_weighted_json_payload -- --exact
cargo test -p leikwan-uot sidecar_runtime::tests::parse_leg_auth_payload_accepts_legacy_plain_token -- --exact
cargo test -p leikwan-uot sidecar_runtime::tests::apply_endpoint_failure_quarantines_after_three_failures -- --exact
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add wstunnel-main/leikwan-uot/src/sidecar_runtime.rs wstunnel-main/leikwan-uot/src/bin/leikwan-udp-uot-merge.rs
git commit -m "feat: port sidecar udp uot runtime to rust"
```

### Task 5: Switch agent/service rendering from Python to Rust binaries

**Files:**
- Modify: `client-agent/install_forward_runtime.py`
- Modify: `sidecar-agent/sidecar_agent.py`
- Test: `client-agent/tests/test_mptcp_bond_regressions.py`

- [ ] **Step 1: Write the failing rendering tests**

Update or add these expectations in `client-agent/tests/test_mptcp_bond_regressions.py`:

```python
        self.assertIn("/usr/local/bin/leikwan-uot-bond --config /etc/leikwan/uot-bond-forward-26.json", udp_unit["service_unit"])
```

And:

```python
            self.assertIn("leikwan-udp-uot-merge", writes[start_script_path])
            self.assertNotIn("/usr/bin/python3 /usr/local/bin/leikwan-udp-uot-merge.py", writes[start_script_path])
```

- [ ] **Step 2: Run the tests to verify they fail**

Run:

```bash
python -m unittest client-agent.tests.test_mptcp_bond_regressions.BondAndMptcpRegressionTests.test_bond_udp_uot_sidepath_renders_tcp_primary_and_udp_aux_unit -v
python -m unittest client-agent.tests.test_sidecar_dual_runtime_regression.SidecarDualRuntimeRegressionTests.test_sidecar_agent_creates_udp_uot_runtime_when_enabled -v
```

Expected: FAIL because the current units still point at Python binaries.

- [ ] **Step 3: Implement the binary path switch**

In `client-agent/install_forward_runtime.py`:
- change default `uot_bond_bin` to `/usr/local/bin/leikwan-uot-bond`
- change `ExecStart` to run the binary directly, no `/usr/bin/python3`

In `sidecar-agent/sidecar_agent.py`:
- change UDP UoT start script to:

```python
f"exec /usr/local/bin/leikwan-udp-uot-merge --config {udp_runtime_path}",
```

- [ ] **Step 4: Run the tests to verify they pass**

Run:

```bash
python -m unittest client-agent.tests.test_mptcp_bond_regressions.BondAndMptcpRegressionTests.test_bond_udp_uot_sidepath_renders_tcp_primary_and_udp_aux_unit -v
python -m unittest client-agent.tests.test_sidecar_dual_runtime_regression.SidecarDualRuntimeRegressionTests.test_sidecar_agent_creates_udp_uot_runtime_when_enabled -v
python -m unittest client-agent.tests.test_mptcp_bond_regressions -v
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add client-agent/install_forward_runtime.py sidecar-agent/sidecar_agent.py client-agent/tests/test_mptcp_bond_regressions.py
git commit -m "feat: switch udp uot services to rust binaries"
```

### Task 6: Full local verification before rollout

**Files:**
- Modify: none
- Test: Rust crate + existing Python management tests

- [ ] **Step 1: Run the Rust UoT crate tests**

Run:

```bash
cd wstunnel-main
cargo test -p leikwan-uot -- --nocapture
```

Expected: all Rust UoT tests pass

- [ ] **Step 2: Run the Python management/regression suites**

Run:

```bash
python -m unittest client-agent.tests.test_mptcp_bond_regressions -v
python -m unittest client-agent.tests.test_uot_bond_runtime -v
python -m unittest sidecar-agent.tests.test_udp_uot_merge_runtime -v
```

Expected:
- management/config tests pass
- any Python runtime tests that are now intentionally obsolete should either be retired in the same diff or replaced with Rust parity tests before this step

- [ ] **Step 3: Run compile/build verification**

Run:

```bash
cd wstunnel-main
cargo build --release -p leikwan-uot
cd ..
python -m py_compile client-agent/install_forward_runtime.py sidecar-agent/sidecar_agent.py client-agent/tests/test_mptcp_bond_regressions.py
```

Expected:
- `cargo build --release -p leikwan-uot` succeeds
- `py_compile` prints nothing

- [ ] **Step 4: Commit the verification gate**

```bash
git add wstunnel-main/leikwan-uot client-agent/install_forward_runtime.py sidecar-agent/sidecar_agent.py client-agent/tests/test_mptcp_bond_regressions.py
git commit -m "test: verify rust udp uot runtime migration"
```

### Task 7: Roll out with the existing bullseye binary discipline

**Files:**
- Modify: remote live copies only

- [ ] **Step 1: Build the Rust UoT binaries on the Debian 11 panel host**

Run on panel:

```bash
export HOME=/root
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3/wstunnel-main
/root/.cargo/bin/cargo test -p leikwan-uot
/root/.cargo/bin/cargo build --release -p leikwan-uot
cp target/release/leikwan-uot-bond /www/wwwroot/v3.leikwanhost.com/leikwan-v3/artifacts/wstunnel/leikwan-uot-bond_linux_amd64
cp target/release/leikwan-udp-uot-merge /www/wwwroot/v3.leikwanhost.com/leikwan-v3/artifacts/wstunnel/leikwan-udp-uot-merge_linux_amd64
```

- [ ] **Step 2: Deploy sidecar Rust merge binary first**

Run:

```bash
scp target/release/leikwan-udp-uot-merge root@138.252.162.120:/root/leikwan-udp-uot-merge.new
ssh -J root@8.163.90.132 root@138.252.162.120 'install -m 0755 /root/leikwan-udp-uot-merge.new /usr/local/bin/leikwan-udp-uot-merge && rm -f /root/leikwan-udp-uot-merge.new && systemctl restart leikwan-bond-sidecar-4-udp-uot.service && sleep 2 && systemctl is-active leikwan-bond-sidecar-4-udp-uot.service'
```

- [ ] **Step 3: Deploy client Rust UoT binary second**

Run:

```bash
scp target/release/leikwan-uot-bond root@172.21.255.189:/root/leikwan-uot-bond.new
ssh root@172.21.255.189 'install -m 0755 /root/leikwan-uot-bond.new /usr/local/bin/leikwan-uot-bond && rm -f /root/leikwan-uot-bond.new && systemctl restart leikwan-client-26-udp-uot.service && sleep 2 && systemctl is-active leikwan-client-26-udp-uot.service'
```

- [ ] **Step 4: Verify live reverse UDP**

Run:

```bash
ssh root@172.21.255.189 'timeout 12 iperf3 -c 127.0.0.1 -p 11024 -b 10M -R -u -t 5'
ssh root@172.21.255.189 'timeout 15 iperf3 -c 127.0.0.1 -p 11024 -b 300M -R -u -t 10'
ssh root@172.21.255.189 'cat /run/leikwan/uot-bond-forward-26.stats.json'
ssh -J root@8.163.90.132 root@138.252.162.120 'cat /var/lib/leikwan-sidecar-4/udp-uot-runtime-stats.json'
```

Expected:
- no worse than the current Python UDP path
- ideally lower jitter/loss and lower `datagrams_dropped_gap`

- [ ] **Step 5: Commit docs after rollout**

```bash
git add docs/BOND_OPERATIONS.md
git commit -m "docs: record rust udp uot runtime rollout"
```

## Not in scope

- replacing the bond TCP runtime with `phantun`
- redesigning the topology away from `client -> slave -> sidecar`
- removing the Python runtimes before the Rust binaries are live and verified
- changing panel schema for UDP UoT

## Self-Review

- Spec coverage: this plan keeps the topology, route-key contract, config schema, and health policy while swapping the UDP hot path to Rust.
- Placeholder scan: no TODO/TBD placeholders remain.
- Type consistency: the migration keeps the existing JSON field names (`route_key`, `session_token`, `endpoint_id`, `weight`, `allowed_forward_ids`, `token_secret`) so control-plane code stays stable.

Plan complete and saved to `docs/superpowers/plans/2026-04-21-rust-udp-uot-runtime.md`. Two execution options:

1. Subagent-Driven (recommended) - I dispatch a fresh subagent per task, review between tasks, fast iteration

2. Inline Execution - Execute tasks in this session using executing-plans, batch execution with checkpoints

Which approach?
