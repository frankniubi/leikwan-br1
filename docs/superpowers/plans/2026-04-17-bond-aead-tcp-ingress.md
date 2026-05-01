# Bond AEAD TCP Ingress Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace only the `client -> slave` WSS ingress used by `bond` mode with a shared-port standard AEAD TCP ingress based on `chacha20-ietf-poly1305`, while preserving existing `leikwan-bond` TCP/UDP semantics and the current `slave -> sidecar` per-leg forwarding model.

**Architecture:** Add a new control-plane flag `bond_ingress_mode=aead_tcp_mux`. In that mode, `client-agent` swaps each per-leg `wstunnel client` process for a new `leikwan-ingress client` helper that opens a raw TCP connection to a shared slave mux on port `7001`. The ingress helper performs an authenticated PSK + ephemeral X25519 handshake, derives per-direction traffic keys with HKDF-SHA256, seals all payload frames with `chacha20-ietf-poly1305`, enforces monotonic sequence numbers and replay windows, and rotates traffic keys on byte/frame thresholds. The slave mux decrypts one leg stream and bridges plaintext bytes to a local `leikwan-ingress backend` service that opens one raw TCP connection to the sidecar target per leg. `leikwan-bond` remains the higher-layer session protocol for both TCP and UDP, so UDP continues to ride inside the existing bond datagram framing over reliable TCP legs.

**Tech Stack:** PHP 8 panel, Python client/slave agents, Rust Tokio helper binary, `x25519-dalek`, `hkdf`, `sha2`, `chacha20poly1305`, existing `leikwan-bond` crate

---

## Security Boundaries

- No Redis spoofing, HTTP spoofing, or application disguises.
- No entropy tuning, low-entropy mode, padding tricks, or traffic shaping for detection avoidance.
- `chacha20-ietf-poly1305` is used as a normal AEAD for confidentiality and integrity only.
- Only the `client -> slave` ingress changes. `slave -> sidecar` remains one outbound TCP connection per accepted leg.
- `leikwan-bond` stays the session protocol and still owns TCP stream striping plus UDP fragment/reassembly semantics.

## Protocol Definition

### 1. Shared-Port Topology

- All bond forwards using `bond_ingress_mode=aead_tcp_mux` share slave public port `7001`.
- Route selection remains per forward via the existing `path_prefix` value, renamed in the ingress layer to `route_key`.
- Each published endpoint gets a route-specific PSK derived from the service token and endpoint id.

### 2. Handshake Sequence

Use a compact four-message PSK + ephemeral handshake:

1. `ClientHello`
   - plaintext
   - fields:
     - `magic = "LKI1"`
     - `version = 1`
     - `command = OPEN | PROBE`
     - `route_key`
     - `conn_id` (128-bit random)
     - `client_time_s`
     - `client_nonce` (96-bit random)
     - `client_ephemeral_pub` (X25519, 32 bytes)
     - `client_mac = HMAC-SHA256(route_key | conn_id | client_time_s | client_nonce | client_ephemeral_pub, ingress_psk)`

2. `ServerHello`
   - plaintext
   - fields:
     - `server_time_s`
     - `server_nonce` (96-bit random)
     - `server_ephemeral_pub` (X25519, 32 bytes)
     - `key_epoch = 0`
     - `server_mac = HMAC-SHA256(transcript_hash | server_time_s | server_nonce | server_ephemeral_pub, ingress_psk)`

3. `ClientFinish`
   - first encrypted frame under `client_to_slave_key`
   - payload:
     - `finish_ok = 1`
     - `requested_mode = OPEN | PROBE`
     - `feature_bits`

4. `ServerFinish`
   - first encrypted frame under `slave_to_client_key`
   - payload:
     - `finish_ok = 1`
     - `backend_ready = 1` for `OPEN`, `0` for `PROBE`
     - `rekey_after_bytes`
     - `rekey_after_frames`

Key derivation:

- `shared_secret = X25519(client_ephemeral, server_ephemeral)`
- `ikm = shared_secret || ingress_psk`
- `handshake_salt = SHA256(ClientHello || ServerHello)`
- `master_secret = HKDF-Extract(handshake_salt, ikm)`
- `client_to_slave_key = HKDF-Expand(master_secret, "c2s key epoch 0", 32)`
- `slave_to_client_key = HKDF-Expand(master_secret, "s2c key epoch 0", 32)`
- `client_nonce_prefix = HKDF-Expand(master_secret, "c2s nonce epoch 0", 4)`
- `slave_nonce_prefix = HKDF-Expand(master_secret, "s2c nonce epoch 0", 4)`

### 3. Nonce Management

- Use the IETF ChaCha20-Poly1305 96-bit nonce.
- Each direction has its own 64-bit `packet_seq`.
- The per-packet nonce is:

```text
nonce = nonce_prefix_4_bytes || packet_seq_be_8_bytes
```

- `packet_seq` starts at `0` immediately after the handshake and increments by exactly `1` per sealed frame.
- Nonce reuse is forbidden. The sender must rekey before `packet_seq` reaches `2^63`.
- The receiver tracks the highest accepted sequence plus a sliding replay window.

### 4. Frame Format

After the handshake, every ingress frame is:

```text
4 bytes  magic = LKIF
1 byte   version = 1
1 byte   frame_type = DATA | FIN | PING | PONG | REKEY | REKEY_ACK | CLOSE
2 bytes  key_epoch
8 bytes  packet_seq
4 bytes  ciphertext_len
N bytes  ciphertext_and_tag
```

AAD:

```text
magic | version | frame_type | key_epoch | packet_seq | ciphertext_len | conn_id
```

This keeps frame integrity bound to the connection identity and epoch.

### 5. Key Rotation

Rotate traffic keys on the first threshold reached:

- `1 GiB` sealed bytes per direction
- `1,000,000` sealed frames per direction
- `15 minutes` elapsed since current epoch start

Rotation mechanism:

1. Sender emits encrypted `REKEY { next_epoch }`.
2. Both sides derive:

```text
next_secret = HKDF-Expand(current_secret, "epoch " || next_epoch, 32)
next_key = HKDF-Expand(next_secret, "traffic key", 32)
next_nonce_prefix = HKDF-Expand(next_secret, "nonce prefix", 4)
```

3. Receiver replies `REKEY_ACK { next_epoch }`.
4. Both sides keep `current_epoch` and `previous_epoch` valid during a short overlap window of `128` packets.
5. Frames from epochs older than `previous_epoch` are dropped.

### 6. Replay Protection

Handshake replay protection:

- Reject `ClientHello` if `client_time_s` is outside a `120s` skew window.
- Cache `(route_key, conn_id, client_nonce)` for `5 minutes`.
- Reject repeated tuples.

Data replay protection:

- Maintain `highest_seq` and a `4096`-packet sliding bitmap per direction per epoch.
- Drop:
  - duplicate `packet_seq`
  - frames older than `highest_seq - 4096`
  - frames with unexpected epoch

### 7. Probe Path

- Health checks no longer use `/_leikwan/readyz/...`.
- `client-agent` sends `command = PROBE` through the same handshake.
- `PROBE` completes the handshake but never opens a backend TCP connection to sidecar.
- Success is `ServerFinish.finish_ok = 1`.

## File Map

- Create: `master/migrations/20260417_add_bond_ingress_mode.sql`
- Modify: `master/migrations/schema.sql`
- Modify: `master/src/Support/ProvisioningService.php`
- Test: `master/tests/provisioning_bond_aead_ingress_test.php`
- Create: `wstunnel-main/leikwan-ingress/Cargo.toml`
- Create: `wstunnel-main/leikwan-ingress/src/lib.rs`
- Create: `wstunnel-main/leikwan-ingress/src/main.rs`
- Create: `wstunnel-main/leikwan-ingress/src/protocol.rs`
- Create: `wstunnel-main/leikwan-ingress/src/crypto.rs`
- Create: `wstunnel-main/leikwan-ingress/src/client.rs`
- Create: `wstunnel-main/leikwan-ingress/src/mux.rs`
- Create: `wstunnel-main/leikwan-ingress/src/backend.rs`
- Test: `wstunnel-main/leikwan-ingress/tests/protocol.rs`
- Test: `wstunnel-main/leikwan-ingress/tests/crypto.rs`
- Modify: `wstunnel-main/Cargo.toml`
- Modify: `slave-agent/slave_agent.py`
- Test: `slave-agent/tests/test_slave_aead_tcp_mux.py`
- Modify: `slave-agent/tests/test_slave_path_prefix_cleanup.py`
- Modify: `client-agent/install_forward_runtime.py`
- Modify: `client-agent/client_agent.py`
- Test: `client-agent/tests/test_mptcp_bond_regressions.py`
- Modify: `install/install-client-agent.sh`
- Modify: `install/install-slave.sh`
- Modify: `artifacts/versions.json`
- Modify: `docs/DEPLOY.md`

### Task 1: Add Control-Plane Support For `aead_tcp_mux`

**Files:**
- Create: `master/migrations/20260417_add_bond_ingress_mode.sql`
- Modify: `master/migrations/schema.sql`
- Modify: `master/src/Support/ProvisioningService.php`
- Test: `master/tests/provisioning_bond_aead_ingress_test.php`

- [ ] **Step 1: Write the failing PHP regression first**

Create `master/tests/provisioning_bond_aead_ingress_test.php`:

```php
<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$fixture = provisioningTestFixture();
$forwardId = createTestForward($fixture, [
    'client_mode' => 'bond',
    'transport_mode' => 'tcp+udp',
    'path_prefix' => 'a43547b6350f72cd',
    'bond_ingress_mode' => 'aead_tcp_mux',
    'public_port' => 7001,
]);

$cfg = $fixture['provisioning']->buildClientConfig($forwardId);
$forward = $cfg['forwards'][0];
$endpoint = $forward['endpoints'][0];
$publish = $fixture['provisioning']->debugPublishTaskPayloadForForward($forwardId, (int) $endpoint['endpoint_id']);

assertSame('aead_tcp_mux', $forward['bonding']['ingress_mode']);
assertSame('aead_tcp_mux', $endpoint['ingress_protocol']);
assertSame(7001, $endpoint['port']);
assertNotEmpty($endpoint['ingress_psk']);
assertSame('aead_tcp_mux', $publish['ingress_protocol']);
assertSame('tcp_relay', $publish['backend_protocol']);
assertNotEmpty($publish['ingress_psk']);
assertSame('a43547b6350f72cd', $publish['route_key']);
```

- [ ] **Step 2: Run the test and verify RED**

Run: `php master/tests/provisioning_bond_aead_ingress_test.php`

Expected: fail because `bond_ingress_mode`, `ingress_protocol`, `route_key`, and `ingress_psk` are not emitted yet.

- [ ] **Step 3: Add the schema field**

Create `master/migrations/20260417_add_bond_ingress_mode.sql`:

```sql
ALTER TABLE forwards
    ADD COLUMN bond_ingress_mode VARCHAR(32) NOT NULL DEFAULT 'wss_path_mux' AFTER bond_remote_port;
```

Update `master/migrations/schema.sql`:

```sql
bond_ingress_mode VARCHAR(32) NOT NULL DEFAULT 'wss_path_mux',
```

- [ ] **Step 4: Persist and normalize the new mode**

In `master/src/Support/ProvisioningService.php`, add:

```php
private function normalizeBondIngressMode(string $value): string
{
    $normalized = trim($value);
    return $normalized === 'aead_tcp_mux' ? 'aead_tcp_mux' : 'wss_path_mux';
}
```

Then in `saveForward(...)`:

```php
$bondIngressMode = $this->normalizeBondIngressMode((string) ($payload['bond_ingress_mode'] ?? 'wss_path_mux'));
```

And include it in insert/update payloads:

```php
'bond_ingress_mode' => $bondIngressMode,
```

- [ ] **Step 5: Emit route key and PSK downlink data**

In `buildClientConfig(...)`, extend bond downlink generation:

```php
$ingressMode = $this->normalizeBondIngressMode((string) ($forward['bond_ingress_mode'] ?? 'wss_path_mux'));
$routeKey = (string) ($forward['path_prefix'] ?? '');
$ingressPsk = hash_hmac(
    'sha256',
    'bond-aead-ingress:' . (int) $endpoint['id'] . ':' . $routeKey,
    (string) ($service['client_token'] ?? $this->config->get('BOND_TOKEN_SECRET', 'bond-default'))
);

$bonding['ingress_mode'] = $ingressMode;
$bonding['route_key'] = $routeKey;
$bonding['ingress_psk'] = $ingressPsk;

if ($ingressMode === 'aead_tcp_mux') {
    $endpoint['port'] = 7001;
    $endpoint['ingress_protocol'] = 'aead_tcp_mux';
    $endpoint['ingress_psk'] = $ingressPsk;
}
```

In `publishTaskPayloadForForward(...)`, add:

```php
'ingress_protocol' => $clientMode === 'bond' && (($forward['bond_ingress_mode'] ?? 'wss_path_mux') === 'aead_tcp_mux')
    ? 'aead_tcp_mux'
    : 'wss_path_mux',
'route_key' => (string) ($forward['path_prefix'] ?? ''),
'backend_protocol' => $clientMode === 'bond' && (($forward['bond_ingress_mode'] ?? 'wss_path_mux') === 'aead_tcp_mux')
    ? 'tcp_relay'
    : 'wss_backend',
'ingress_psk' => hash_hmac(
    'sha256',
    'bond-aead-ingress:' . $endpointId . ':' . (string) ($forward['path_prefix'] ?? ''),
    (string) (($this->db->fetchOne('SELECT client_token FROM services WHERE id = :id LIMIT 1', ['id' => (int) ($forward['service_id'] ?? 0)])['client_token'] ?? '') ?: $this->config->get('BOND_TOKEN_SECRET', 'bond-default'))
),
```

- [ ] **Step 6: Verify GREEN**

Run:

```bash
php master/tests/provisioning_bond_aead_ingress_test.php
php -l master/src/Support/ProvisioningService.php
```

Expected: pass.

- [ ] **Step 7: Commit**

```bash
git add master/migrations/20260417_add_bond_ingress_mode.sql master/migrations/schema.sql master/src/Support/ProvisioningService.php master/tests/provisioning_bond_aead_ingress_test.php
git commit -m "feat(panel): add bond aead tcp ingress downlink mode"
```

### Task 2: Build The `leikwan-ingress` AEAD Helper

**Files:**
- Create: `wstunnel-main/leikwan-ingress/Cargo.toml`
- Create: `wstunnel-main/leikwan-ingress/src/lib.rs`
- Create: `wstunnel-main/leikwan-ingress/src/main.rs`
- Create: `wstunnel-main/leikwan-ingress/src/protocol.rs`
- Create: `wstunnel-main/leikwan-ingress/src/crypto.rs`
- Create: `wstunnel-main/leikwan-ingress/src/client.rs`
- Create: `wstunnel-main/leikwan-ingress/src/mux.rs`
- Create: `wstunnel-main/leikwan-ingress/src/backend.rs`
- Test: `wstunnel-main/leikwan-ingress/tests/protocol.rs`
- Test: `wstunnel-main/leikwan-ingress/tests/crypto.rs`
- Modify: `wstunnel-main/Cargo.toml`

- [ ] **Step 1: Write failing Rust protocol tests**

Create `wstunnel-main/leikwan-ingress/tests/protocol.rs`:

```rust
use leikwan_ingress::protocol::{parse_client_hello, ClientCommand};

#[test]
fn parses_open_client_hello() {
    let line = "LKI1 1 OPEN a43547b6350f72cd conn-1 1776371000 nonce1 pubkey1 mac1\n";
    let parsed = parse_client_hello(line.as_bytes()).unwrap();
    assert_eq!(parsed.command, ClientCommand::Open);
    assert_eq!(parsed.route_key, "a43547b6350f72cd");
}

#[test]
fn parses_probe_client_hello() {
    let line = "LKI1 1 PROBE a43547b6350f72cd conn-1 1776371000 nonce1 pubkey1 mac1\n";
    let parsed = parse_client_hello(line.as_bytes()).unwrap();
    assert_eq!(parsed.command, ClientCommand::Probe);
}
```

Create `wstunnel-main/leikwan-ingress/tests/crypto.rs`:

```rust
use leikwan_ingress::crypto::{derive_initial_keys, nonce_for_seq};

#[test]
fn derives_distinct_directional_keys() {
    let keys = derive_initial_keys([7u8; 32], [9u8; 32], b"transcript");
    assert_ne!(keys.client_to_slave_key, keys.slave_to_client_key);
}

#[test]
fn nonce_prefix_plus_seq_is_stable() {
    let nonce = nonce_for_seq([1, 2, 3, 4], 9);
    assert_eq!(&nonce[..4], &[1, 2, 3, 4]);
    assert_eq!(&nonce[4..], &[0, 0, 0, 0, 0, 0, 0, 9]);
}
```

- [ ] **Step 2: Run the crate tests and verify RED**

Run: `cargo test -p leikwan-ingress`

Expected: fail because the crate is not in the workspace yet.

- [ ] **Step 3: Add the crate and workspace entry**

Create `wstunnel-main/leikwan-ingress/Cargo.toml`:

```toml
[package]
name = "leikwan-ingress"
version = "0.1.0"
edition = "2021"

[dependencies]
anyhow = "1"
base64 = "0.22"
chacha20poly1305 = "0.10"
clap = { version = "4", features = ["derive"] }
hex = "0.4"
hkdf = "0.12"
hmac = "0.12"
rand = "0.8"
sha2 = "0.10"
tokio = { version = "1", features = ["full"] }
tracing = "0.1"
tracing-subscriber = { version = "0.3", features = ["fmt", "env-filter"] }
x25519-dalek = "2"
zeroize = "1"
```

Update `wstunnel-main/Cargo.toml`:

```toml
"leikwan-ingress",
```

- [ ] **Step 4: Implement parsing and key derivation**

In `src/protocol.rs`, define:

```rust
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ClientCommand { Open, Probe }

#[derive(Debug, Clone)]
pub struct ClientHello {
    pub command: ClientCommand,
    pub route_key: String,
    pub conn_id: String,
    pub client_time_s: u64,
    pub client_nonce: String,
    pub client_ephemeral_pub_b64: String,
    pub client_mac_hex: String,
}
```

In `src/crypto.rs`, define:

```rust
pub struct InitialKeys {
    pub client_to_slave_key: [u8; 32],
    pub slave_to_client_key: [u8; 32],
    pub client_nonce_prefix: [u8; 4],
    pub slave_nonce_prefix: [u8; 4],
}

pub fn nonce_for_seq(prefix: [u8; 4], seq: u64) -> [u8; 12] {
    let mut nonce = [0u8; 12];
    nonce[..4].copy_from_slice(&prefix);
    nonce[4..].copy_from_slice(&seq.to_be_bytes());
    nonce
}
```

Also implement:

- `verify_client_hello_hmac(...)`
- `derive_initial_keys(...)`
- `derive_next_epoch(...)`
- `seal_frame(...)`
- `open_frame(...)`

- [ ] **Step 5: Implement the runtime contracts**

Implement these subcommands:

```text
leikwan-ingress client --listen 127.0.0.1:20962 --remote 8.163.90.132:7001 --route-key a43547b6350f72cd --psk <hex>
leikwan-ingress mux --listen 0.0.0.0:7001 --routes-file /var/lib/leikwan-slave/listeners/routes-7001.json
leikwan-ingress backend --listen 127.0.0.1:6000 --target 138.252.162.120:9000
```

Rules:

- `client`
  - accept one local TCP connection
  - send `ClientHello`
  - read `ServerHello`
  - derive keys
  - send encrypted `ClientFinish`
  - require encrypted `ServerFinish`
  - then run framed AEAD relay

- `mux`
  - parse `ClientHello`
  - resolve `route_key`
  - verify HMAC, timestamp skew, and replay cache
  - send `ServerHello`
  - derive keys
  - validate `ClientFinish`
  - return `ServerFinish`
  - for `OPEN`, connect backend and relay framed AEAD data
  - for `PROBE`, stop after `ServerFinish`

- `backend`
  - unchanged semantics from the plain TCP prototype
  - one inbound connection creates one outbound TCP connection to the sidecar target

- [ ] **Step 6: Implement frame relay, rekey, and close**

Use:

```rust
enum FrameType {
    Data = 1,
    Fin = 2,
    Ping = 3,
    Pong = 4,
    Rekey = 5,
    RekeyAck = 6,
    Close = 7,
}
```

Required behavior:

- `Data`: encrypted payload bytes
- `Fin`: half-close the destination writer
- `Ping`/`Pong`: keepalive
- `Rekey`: announce next epoch
- `RekeyAck`: confirm epoch switch
- `Close`: authenticated shutdown reason

- [ ] **Step 7: Verify GREEN**

Run:

```bash
cargo test -p leikwan-ingress
cargo build --release -p leikwan-ingress
```

Expected: tests pass and `target/release/leikwan-ingress` exists.

- [ ] **Step 8: Commit**

```bash
git add wstunnel-main/Cargo.toml wstunnel-main/leikwan-ingress
git commit -m "feat(ingress): add aead tcp ingress helper crate"
```

### Task 3: Integrate Slave-Side AEAD TCP Mux And TCP Backend

**Files:**
- Modify: `slave-agent/slave_agent.py`
- Test: `slave-agent/tests/test_slave_aead_tcp_mux.py`
- Modify: `slave-agent/tests/test_slave_path_prefix_cleanup.py`

- [ ] **Step 1: Write the failing slave tests**

Create `slave-agent/tests/test_slave_aead_tcp_mux.py` with:

```python
def test_aead_route_file_contains_route_key_and_psk(): ...
def test_sync_listener_uses_aead_mux_service_name_on_7001(): ...
def test_aead_probe_configuration_does_not_require_tls_fields(): ...
```

Also extend `slave-agent/tests/test_slave_path_prefix_cleanup.py` with metadata like:

```python
{
    "ingress_protocol": "aead_tcp_mux",
    "backend_protocol": "tcp_relay",
    "route_key": "a43547b6350f72cd",
    "ingress_psk": "abcd",
}
```

- [ ] **Step 2: Run the slave tests and verify RED**

Run:

```bash
python -m unittest slave-agent.tests.test_slave_aead_tcp_mux
python -m unittest slave-agent.tests.test_slave_path_prefix_cleanup
```

Expected: fail because the slave agent only knows WSS path mux.

- [ ] **Step 3: Extend metadata and routes**

When publishing a service in `slave-agent/slave_agent.py`, persist:

```python
"ingress_protocol": str(payload.get("ingress_protocol", "wss_path_mux") or "wss_path_mux"),
"backend_protocol": str(payload.get("backend_protocol", "wss_backend") or "wss_backend"),
"route_key": str(payload.get("route_key", payload.get("path_prefix", "")) or ""),
"ingress_psk": str(payload.get("ingress_psk", "") or ""),
```

And in listener route generation emit:

```python
{
    "route_key": str(metadata.get("route_key", "")),
    "backend_host": str(self.config.get("backend_host", "127.0.0.1")),
    "backend_port": backend_port,
    "service_name": str(metadata.get("service_name", "")),
    "ingress_protocol": str(metadata.get("ingress_protocol", "wss_path_mux")),
    "ingress_psk": str(metadata.get("ingress_psk", "")),
}
```

- [ ] **Step 4: Add a dedicated AEAD mux service path**

Add:

```python
def aead_mux_service_name(listen_port: int) -> str:
    return f"leikwan-aead-mux-{listen_port}"
```

And render a unit:

```python
def render_aead_mux_unit(self, service_name: str, listen_port: int, routes_path: Path) -> str:
    ingress_bin = shell_arg(str(self.config.get("ingress_bin", "/usr/local/bin/leikwan-ingress")))
    return f"""[Unit]
Description=Leikwan AEAD TCP Mux {service_name}
After=network.target

[Service]
Type=simple
ExecStart={ingress_bin} mux --listen 0.0.0.0:{listen_port} --routes-file {shell_arg(str(routes_path))}
Restart=always
RestartSec=3
LimitNOFILE=1048576

[Install]
WantedBy=multi-user.target
"""
```

Use it when the listener routes on that port are `aead_tcp_mux`.

- [ ] **Step 5: Add the backend service selector**

Keep `render_backend_unit(...)` for WSS. Add:

```python
def render_plain_tcp_backend_unit(self, service_name: str, backend_port: int, target: str) -> str:
    ingress_bin = shell_arg(str(self.config.get("ingress_bin", "/usr/local/bin/leikwan-ingress")))
    return f"""[Unit]
Description=Leikwan TCP Backend {service_name}
After=network.target

[Service]
Type=simple
ExecStart={ingress_bin} backend --listen 127.0.0.1:{backend_port} --target {shell_arg(target)}
Restart=always
RestartSec=3
LimitNOFILE=1048576

[Install]
WantedBy=multi-user.target
"""
```

Select it when `payload.get("ingress_protocol") == "aead_tcp_mux"`.

- [ ] **Step 6: Verify GREEN**

Run:

```bash
python -m unittest slave-agent.tests.test_slave_aead_tcp_mux
python -m unittest slave-agent.tests.test_slave_path_prefix_cleanup
python -m py_compile slave-agent/slave_agent.py
```

Expected: all pass.

- [ ] **Step 7: Commit**

```bash
git add slave-agent/slave_agent.py slave-agent/tests/test_slave_aead_tcp_mux.py slave-agent/tests/test_slave_path_prefix_cleanup.py
git commit -m "feat(slave-agent): add aead tcp mux and tcp backend"
```

### Task 4: Switch Bond Runtime Rendering And Probes On The Client

**Files:**
- Modify: `client-agent/install_forward_runtime.py`
- Modify: `client-agent/client_agent.py`
- Test: `client-agent/tests/test_mptcp_bond_regressions.py`

- [ ] **Step 1: Write failing client tests**

Extend `client-agent/tests/test_mptcp_bond_regressions.py` with:

```python
def test_bond_aead_ingress_start_script_uses_leikwan_ingress_client(self): ...
def test_aead_probe_uses_route_key_and_psk(self): ...
```

The first should assert a bond forward with:

```python
"bonding": {
    "ingress_mode": "aead_tcp_mux",
    "route_key": "a43547b6350f72cd",
    "ingress_psk": "abcd",
}
```

renders:

```python
"/usr/local/bin/leikwan-ingress client --listen 127.0.0.1:20962 --remote 8.163.90.132:7001 --route-key a43547b6350f72cd --psk abcd"
```

- [ ] **Step 2: Run the tests and verify RED**

Run: `python -m unittest client-agent.tests.test_mptcp_bond_regressions`

Expected: fail because bond mode always renders `wstunnel client` now.

- [ ] **Step 3: Add AEAD ingress rendering**

In `client-agent/install_forward_runtime.py`, inside bond mode:

```python
bond_ingress_bin = str(agent_config.get("bond_ingress_bin", "/usr/local/bin/leikwan-ingress")).strip() or "/usr/local/bin/leikwan-ingress"
ingress_mode = str(bond.get("ingress_mode", "wss_path_mux")).strip() or "wss_path_mux"
route_key = str(bond.get("route_key", path_prefix)).strip()
ingress_psk = str(bond.get("ingress_psk", "")).strip()
```

Replace the per-leg launcher:

```python
if ingress_mode == "aead_tcp_mux":
    lines.append(
        f"{bond_ingress_bin} client --listen 127.0.0.1:{tcp_back} "
        f"--remote {host}:{port} --route-key {route_key} --psk {ingress_psk} &"
    )
else:
    lines.append(
        f"/usr/local/bin/wstunnel client -L tcp://127.0.0.1:{tcp_back}:{bond_remote_target} "
        f"--tls-sni-override {sni} --http-headers \"Host: {sni}\" "
        f"--http-upgrade-path-prefix \"{path_prefix}\" wss://{host}:{port} &"
    )
```

Do the same for the UDP transport leg. The local contract to `leikwan-bond` stays unchanged.

- [ ] **Step 4: Replace ready probes for AEAD mode**

In `client-agent/client_agent.py`, keep `remote_ready_probe(...)` for legacy WSS. Add:

```python
@classmethod
def remote_aead_probe(cls, host: str, port: int, route_key: str, ingress_psk: str, timeout: float = 3.0):
    started = time.time()
    with socket.create_connection((host, port), timeout=timeout) as sock:
        sock.settimeout(timeout)
        conn_id = "probe-" + secrets.token_hex(8)
        client_nonce = secrets.token_hex(12)
        client_pub = "probe-pub"
        mac = hmac.new(bytes.fromhex(ingress_psk), f"{route_key}|{conn_id}".encode("utf-8"), hashlib.sha256).hexdigest()
        line = f"LKI1 1 PROBE {route_key} {conn_id} {int(time.time())} {client_nonce} {client_pub} {mac}\n"
        sock.sendall(line.encode("utf-8"))
        response = sock.recv(512)
    if response.startswith(b"LKI1 1 SERVERHELLO"):
        return True, round((time.time() - started) * 1000, 1), None
    return False, None, response.decode("utf-8", errors="ignore").strip() or "invalid_probe_response"
```

Then dispatch in `ensure_runtime_health(...)`:

```python
bonding = forward.get("bonding", {}) if isinstance(forward.get("bonding", {}), dict) else {}
if mode == "bond" and str(bonding.get("ingress_mode", "wss_path_mux")) == "aead_tcp_mux":
    ready, _, _ = self.remote_aead_probe(
        str(endpoint.get("host", "")),
        int(endpoint.get("port", 0) or 0),
        str(bonding.get("route_key", forward.get("path_prefix", ""))),
        str(endpoint.get("ingress_psk", "") or bonding.get("ingress_psk", "")),
    )
else:
    ready, _, _ = self.remote_ready_probe(...)
```

- [ ] **Step 5: Verify GREEN**

Run:

```bash
python -m unittest client-agent.tests.test_mptcp_bond_regressions
python -m py_compile client-agent/install_forward_runtime.py
python -m py_compile client-agent/client_agent.py
```

Expected: pass.

- [ ] **Step 6: Commit**

```bash
git add client-agent/install_forward_runtime.py client-agent/client_agent.py client-agent/tests/test_mptcp_bond_regressions.py
git commit -m "feat(client-agent): add bond aead ingress runtime"
```

### Task 5: Ship The Binary And Installer Wiring

**Files:**
- Modify: `install/install-client-agent.sh`
- Modify: `install/install-slave.sh`
- Modify: `artifacts/versions.json`
- Modify: `docs/DEPLOY.md`

- [ ] **Step 1: Download the new helper in both installers**

In `install/install-client-agent.sh`:

```bash
download "$ARTIFACT_BASE/artifacts/wstunnel/leikwan-ingress_linux_amd64" "$BIN_DIR/leikwan-ingress"
```

And extend the config:

```json
"bond_ingress_bin": "/usr/local/bin/leikwan-ingress"
```

In `install/install-slave.sh`:

```bash
curl -fsSL "$MASTER_URL/artifacts/wstunnel/leikwan-ingress_linux_amd64" -o "$BIN_DIR/leikwan-ingress"
chmod 0755 "$BIN_DIR/leikwan-ingress"
```

And extend the config:

```json
"ingress_bin": "/usr/local/bin/leikwan-ingress"
```

- [ ] **Step 2: Update the versions manifest**

Add to `artifacts/versions.json`:

```json
"leikwan_ingress_linux_amd64": {
  "version": "0.1.0",
  "path": "artifacts/wstunnel/leikwan-ingress_linux_amd64",
  "artifact_url": "/artifacts/wstunnel/leikwan-ingress_linux_amd64"
}
```

Bump:

```json
"client_agent_installer": { "version": "2026.04.17.1", ... }
"slave_installer": { "version": "2026.04.17.1", ... }
```

- [ ] **Step 3: Document rollout**

Update `docs/DEPLOY.md`:

```md
## Bond AEAD TCP ingress prototype

- bond forwards with `bond_ingress_mode=aead_tcp_mux` use slave public port `7001`
- `client -> slave` uses PSK + ephemeral X25519 handshake plus `chacha20-ietf-poly1305`
- health checks use the same ingress protocol in `PROBE` mode
- `slave -> sidecar` remains one raw TCP connection per accepted leg
```

- [ ] **Step 4: Build the helper artifact**

Run:

```bash
cd wstunnel-main
cargo build --release -p leikwan-ingress
```

Then place the produced binary at:

```text
artifacts/wstunnel/leikwan-ingress_linux_amd64
```

- [ ] **Step 5: Commit**

```bash
git add install/install-client-agent.sh install/install-slave.sh artifacts/versions.json docs/DEPLOY.md
git commit -m "chore(artifacts): ship aead tcp ingress helper"
```

### Task 6: Live Verification On One Bond Forward

**Files:**
- Modify: none
- Test: live environment only

- [ ] **Step 1: Roll one bond forward onto `aead_tcp_mux`**

Apply:

```json
{
  "client_mode": "bond",
  "bond_ingress_mode": "aead_tcp_mux",
  "public_port": 7001
}
```

- [ ] **Step 2: Verify slave convergence**

Run on the slave:

```bash
ss -ltnp | grep 7001
systemctl status leikwan-aead-mux-7001 --no-pager -n 40
cat /var/lib/leikwan-slave/listeners/routes-7001.json
```

Expected:

- `7001` is listening
- route file contains `route_key` and `ingress_psk`
- backend service for the endpoint is active

- [ ] **Step 3: Verify client runtime swap**

Run on the client:

```bash
systemctl cat leikwan-client-23
grep -n "leikwan-ingress client" /usr/local/bin/leikwan-client-start-23.sh
```

Expected: bond legs use `leikwan-ingress client`, not `wstunnel client`.

- [ ] **Step 4: Verify health checks and TCP**

Run on the client:

```bash
journalctl -u leikwan-client-agent --no-pager -n 120 --since "5 min ago"
iperf3 -c 127.0.0.1 -p 11021 -t 10
```

Expected:

- no `all slave endpoints failed ready probe`
- no `failed to get a connection to the server from the pool`
- bond hello reaches the sidecar through the AEAD ingress legs

- [ ] **Step 5: Verify UDP still works through bond**

Run the existing bond UDP smoke test and inspect:

```bash
journalctl -u leikwan-client-23 --no-pager -n 120 --since "5 min ago"
```

Expected: no UDP dispatch failures, because `leikwan-bond` still owns UDP framing over the reliable ingress stream.

- [ ] **Step 6: Record results**

Append one short entry to `docs/2026-04-15-fix-record.md` with:

- selected forward id
- `aead_tcp_mux` convergence result
- TCP hello result
- UDP regression result

- [ ] **Step 7: Final verification sweep**

Run:

```bash
php master/tests/provisioning_bond_aead_ingress_test.php
python -m unittest client-agent.tests.test_mptcp_bond_regressions
python -m unittest slave-agent.tests.test_slave_aead_tcp_mux
python -m unittest slave-agent.tests.test_slave_path_prefix_cleanup
cd wstunnel-main && cargo test -p leikwan-ingress
```

Expected: all pass.
