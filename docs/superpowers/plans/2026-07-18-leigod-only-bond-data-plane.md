# LeiGod-Only Bond Data Plane Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Harden standalone `leikwan-bond` so LeiGod-only Speed Plus uses one to eight explicit Slave legs, survives ordinary leg loss by degrading to the best surviving Slave inside the same bond session, and reports deterministic atomic leg health snapshots.

**Architecture:** Keep the data plane inside `wstunnel-main/leikwan-bond`; do not revive native MPTCP, Cloudflare, WSS, or classic sidecar fallback paths. Add a stable logical leg identity contract to `transport.rs`, propagate it through `OpenLeg` and `LegState`, then make the TCP scheduler explicitly manage `speed_plus`, `single_leg_only`, and `degraded_single_leg` pool modes. Existing ACK windows and pending chunks remain the reliability core, but retransmission selection becomes best-survivor aware, recovered legs pass probation before striping resumes, and snapshots are written through an atomic replace.

**Tech Stack:** Rust 2021, Tokio async TCP/QUIC, existing `anyhow`/`bytes`/`clap`/`serde`/`serde_json` dependencies plus `tempfile` for same-directory atomic snapshot replacement, `cargo test`, standalone integration tests under `wstunnel-main/leikwan-bond/tests`.

---

## Scope and locked runtime contract

This plan changes only the standalone bond runtime and its Rust tests. It does **not** change PHP control-plane publication, retirement cleanup, Linux supervisor rendering, artifact serving, telemetry ingestion, rollout activation, production services, persisted LeiGod credentials, or historical Forward rows.

The active Forward contract remains:

```text
backend_mode = leigod_mode
client_mode = bond
runtime_preference = single
bond_ingress_mode = plain_tcp_mux
```

For this plan, `runtime_preference = single` means the Linux client directly runs the standalone `leikwan-bond` client. It does not mean one Slave and it does not authorize native MPTCP.

The approved Speed Plus data-plane behavior is:

```text
1 usable Slave  -> single_leg_only
2..8 usable Slaves -> speed_plus_eligible
0 usable Slaves -> invalid_speed_plus_leg_set / no_usable_legs
```

The stable logical leg key is:

```text
bond:<forward_id>:tcp:<node_id>:<endpoint_id>
```

When bonding degrades, the runtime must preserve:

```text
same application TCP socket
same standalone bond transport session
same sidecar target TCP connection
same bond protocol family
```

The runtime must retransmit unacknowledged frames through the deterministic best surviving eligible Slave. It must not switch to MPTCP, Cloudflare, WSS, or classic sidecar. If no bond-family leg remains usable until the resume timeout expires, the bond session fails explicitly.

---

## File map

**Modify:**

- `wstunnel-main/leikwan-bond/src/transport.rs`
  - Owns `LegSpec`, leg URI parsing, stable leg identity parsing, duplicate detection, and leg-set cardinality checks.
- `wstunnel-main/leikwan-bond/src/main.rs`
  - Adds CLI flags that let the Linux supervisor require stable identity, pass snapshot/probation policy, and parse leg URIs before the session layer starts.
- `wstunnel-main/leikwan-bond/src/session.rs`
  - Owns `OpenLeg`, `LegState`, TCP scheduler decisions, ACK/retransmit behavior, degradation state, probation, re-admission, and snapshot output.
- `wstunnel-main/leikwan-bond/Cargo.toml`
  - Adds `tempfile` as a runtime dependency for same-directory atomic snapshot replacement.
- `wstunnel-main/leikwan-bond/Cargo.lock`
  - Records the locked transitive dependency set after adding the `tempfile` dependency.

**Create:**

- `wstunnel-main/leikwan-bond/tests/tcp_speed_plus_degrade.rs`
  - End-to-end local TCP integration coverage for one application stream surviving leg loss and for explicit all-legs-lost failure.

Do not modify:

- `docs/superpowers/plans/2026-06-23-bridge-lite-multi-theme-redesign.md`
- PHP control-plane files
- Python agent files
- artifact mirrors
- production hosts or service units

---

## Implementation pre-flight baseline

Before Task 1, record the baseline used by Task 9 to audit committed changes and protect the unrelated Bridge Lite plan. This creates an untracked file under `.git/`; do not stage it.

Run:

```bash
python - <<'PY'
import hashlib
import json
import pathlib
import subprocess

bridge = pathlib.Path('docs/superpowers/plans/2026-06-23-bridge-lite-multi-theme-redesign.md')
base_ref = subprocess.check_output(['git', 'rev-parse', 'HEAD'], text=True).strip()
bridge_status = subprocess.check_output(['git', 'status', '--short', '--', str(bridge)], text=True)
bridge_hash = hashlib.sha256(bridge.read_bytes()).hexdigest() if bridge.exists() else None
pathlib.Path('.git/leigod-bond-data-plane-baseline.json').write_text(
    json.dumps(
        {
            'base_ref': base_ref,
            'bridge_path': str(bridge),
            'bridge_status': bridge_status,
            'bridge_hash': bridge_hash,
        },
        indent=2,
        sort_keys=True,
    ) + '\n',
    encoding='utf-8',
)
print(base_ref)
PY
```

Expected: prints the starting commit hash. `.git/leigod-bond-data-plane-baseline.json` exists and records the initial Bridge Lite status/hash for Task 9. If the protected Bridge Lite plan is already untracked at baseline, that exact untracked status is allowed later only if the content hash is unchanged.

---

### Task 1: Add stable logical leg identity parsing

**Files:**
- Modify: `wstunnel-main/leikwan-bond/src/transport.rs:1-190`

- [ ] **Step 1: Write failing parser tests for stable leg identity**

Append these tests to the existing `#[cfg(test)] mod tests` in `wstunnel-main/leikwan-bond/src/transport.rs`:

```rust
#[test]
fn parse_leg_spec_builds_stable_logical_key_from_query_fields() {
    let spec = parse_leg_spec_with_options(
        "tcp://198.51.100.10:24001?priority=10&forward_id=91&node_id=20&endpoint_id=184&display_host=node20.example",
        LegParseOptions {
            required_forward_id: Some(91),
            require_stable_identity: true,
        },
    )
    .expect("stable leg should parse");

    let identity = spec
        .stable_identity
        .as_ref()
        .expect("stable identity is required");
    assert_eq!(identity.forward_id, 91);
    assert_eq!(identity.node_id, 20);
    assert_eq!(identity.endpoint_id, 184);
    assert_eq!(identity.protocol, "tcp");
    assert_eq!(identity.logical_key(), "bond:91:tcp:20:184");
    assert_eq!(spec.logical_key.as_deref(), Some("bond:91:tcp:20:184"));
    assert_eq!(spec.display_host.as_deref(), Some("node20.example"));
}

#[test]
fn parse_leg_spec_accepts_explicit_matching_logical_key() {
    let spec = parse_leg_spec_with_options(
        "tcp://198.51.100.11:24002?forward_id=91&node_id=21&endpoint_id=185&leg_key=bond:91:tcp:21:185",
        LegParseOptions {
            required_forward_id: Some(91),
            require_stable_identity: true,
        },
    )
    .expect("explicit matching leg_key should parse");

    assert_eq!(spec.logical_key.as_deref(), Some("bond:91:tcp:21:185"));
}

#[test]
fn parse_leg_spec_rejects_missing_stable_fields_when_required() {
    let err = parse_leg_spec_with_options(
        "tcp://198.51.100.10:24001?node_id=20&endpoint_id=184",
        LegParseOptions {
            required_forward_id: Some(91),
            require_stable_identity: true,
        },
    )
    .expect_err("missing forward_id should fail");

    assert!(
        err.to_string().contains("missing_stable_leg_identity"),
        "unexpected error: {err:?}"
    );
}

#[test]
fn parse_leg_spec_rejects_forward_id_mismatch() {
    let err = parse_leg_spec_with_options(
        "tcp://198.51.100.10:24001?forward_id=92&node_id=20&endpoint_id=184",
        LegParseOptions {
            required_forward_id: Some(91),
            require_stable_identity: true,
        },
    )
    .expect_err("wrong forward_id should fail");

    assert!(
        err.to_string().contains("stable_leg_forward_id_mismatch"),
        "unexpected error: {err:?}"
    );
}

#[test]
fn parse_leg_spec_rejects_logical_key_mismatch() {
    let err = parse_leg_spec_with_options(
        "tcp://198.51.100.10:24001?forward_id=91&node_id=20&endpoint_id=184&leg_key=bond:91:tcp:21:184",
        LegParseOptions {
            required_forward_id: Some(91),
            require_stable_identity: true,
        },
    )
    .expect_err("wrong leg_key should fail");

    assert!(
        err.to_string().contains("stable_leg_key_mismatch"),
        "unexpected error: {err:?}"
    );
}
```

Keep the existing `parse_leg_spec_reads_role_query_param` test unchanged; this task must preserve backward-compatible parsing when strict identity is not requested.

- [ ] **Step 2: Run the parser tests and verify they fail before implementation**

Run:

```bash
cd wstunnel-main/leikwan-bond && cargo test parse_leg_spec_builds_stable_logical_key_from_query_fields -- --nocapture
cd wstunnel-main/leikwan-bond && cargo test parse_leg_spec_rejects_missing_stable_fields_when_required -- --nocapture
```

Expected: both commands FAIL because `LegParseOptions`, `parse_leg_spec_with_options`, `stable_identity`, and `logical_key` do not exist.

- [ ] **Step 3: Add stable identity types and strict parser entry point**

In `wstunnel-main/leikwan-bond/src/transport.rs`, add these types near `LegRole` and extend `LegSpec` with the new fields:

```rust
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct LegParseOptions {
    pub required_forward_id: Option<u64>,
    pub require_stable_identity: bool,
}

impl Default for LegParseOptions {
    fn default() -> Self {
        Self {
            required_forward_id: None,
            require_stable_identity: false,
        }
    }
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct StableLegIdentity {
    pub forward_id: u64,
    pub protocol: String,
    pub node_id: u64,
    pub endpoint_id: u64,
}

impl StableLegIdentity {
    pub fn new(forward_id: u64, protocol: &str, node_id: u64, endpoint_id: u64) -> anyhow::Result<Self> {
        if forward_id == 0 || node_id == 0 || endpoint_id == 0 {
            return Err(anyhow!("missing_stable_leg_identity"));
        }
        if protocol != "tcp" {
            return Err(anyhow!("unsupported_stable_leg_protocol:{protocol}"));
        }
        Ok(Self {
            forward_id,
            protocol: protocol.to_string(),
            node_id,
            endpoint_id,
        })
    }

    pub fn logical_key(&self) -> String {
        format!(
            "bond:{}:{}:{}:{}",
            self.forward_id, self.protocol, self.node_id, self.endpoint_id
        )
    }
}

#[derive(Clone)]
pub struct LegSpec {
    pub transport: LegTransport,
    pub host: String,
    pub port: u16,
    pub server_name: Option<String>,
    pub insecure: bool,
    pub priority: u16,
    pub role: LegRole,
    pub endpoint_id: Option<u64>,
    pub node_id: Option<u64>,
    pub display_host: Option<String>,
    pub stable_identity: Option<StableLegIdentity>,
    pub logical_key: Option<String>,
    pub original: String,
}
```

Update the existing `Debug` implementation for `LegSpec` so it prints `logical_key` and `stable_identity` without exposing tokens or secrets:

```rust
impl fmt::Debug for LegSpec {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("LegSpec")
            .field("transport", &self.transport)
            .field("host", &self.host)
            .field("port", &self.port)
            .field("priority", &self.priority)
            .field("role", &self.role)
            .field("endpoint_id", &self.endpoint_id)
            .field("node_id", &self.node_id)
            .field("display_host", &self.display_host)
            .field("logical_key", &self.logical_key)
            .finish()
    }
}
```

- [ ] **Step 4: Implement strict URI parsing while preserving legacy parser behavior**

Replace the existing `parse_leg_spec` body with a call to the new function, then implement `parse_leg_spec_with_options`:

```rust
pub fn parse_leg_spec(raw: &str) -> anyhow::Result<LegSpec> {
    parse_leg_spec_with_options(raw, LegParseOptions::default())
}

pub fn parse_leg_spec_with_options(raw: &str, options: LegParseOptions) -> anyhow::Result<LegSpec> {
    let trimmed = raw.trim();
    if trimmed.is_empty() {
        return Err(anyhow!("bond leg target cannot be empty"));
    }

    if !trimmed.contains("://") {
        if options.require_stable_identity {
            return Err(anyhow!("missing_stable_leg_identity"));
        }
        let address = SocketAddr::from_str(trimmed)
            .with_context(|| format!("invalid tcp bond leg target {trimmed}"))?;
        return Ok(LegSpec {
            transport: LegTransport::Tcp,
            host: address.ip().to_string(),
            port: address.port(),
            server_name: None,
            insecure: false,
            priority: LegTransport::Tcp.default_priority(),
            role: LegRole::Primary,
            endpoint_id: None,
            node_id: None,
            display_host: None,
            stable_identity: None,
            logical_key: None,
            original: trimmed.to_string(),
        });
    }

    let url = Url::parse(trimmed).with_context(|| format!("invalid bond leg URI {trimmed}"))?;
    let transport = match url.scheme() {
        "tcp" => LegTransport::Tcp,
        "quic" => LegTransport::Quic,
        other => {
            return Err(anyhow!(
                "unsupported bond leg scheme {other}, expected tcp:// or quic://"
            ));
        }
    };
    let host = url
        .host_str()
        .ok_or_else(|| anyhow!("bond leg URI is missing host: {trimmed}"))?
        .to_string();
    let port = url
        .port()
        .ok_or_else(|| anyhow!("bond leg URI is missing port: {trimmed}"))?;

    let mut server_name = None;
    let mut insecure = false;
    let mut priority = transport.default_priority();
    let mut role = LegRole::Primary;
    let mut forward_id = None;
    let mut endpoint_id = None;
    let mut node_id = None;
    let mut display_host = None;
    let mut explicit_leg_key = None;

    for (key, value) in url.query_pairs() {
        match key.as_ref() {
            "server_name" | "sni" => {
                if !value.is_empty() {
                    server_name = Some(value.to_string());
                }
            }
            "insecure" => {
                let normalized = value.trim().to_ascii_lowercase();
                insecure = matches!(normalized.as_str(), "1" | "true" | "yes" | "on");
            }
            "priority" => {
                if let Ok(parsed) = value.parse::<u16>() {
                    priority = parsed;
                }
            }
            "role" => {
                role = match value.as_ref() {
                    "helper" => LegRole::Helper,
                    _ => LegRole::Primary,
                };
            }
            "forward_id" => {
                forward_id = value.parse::<u64>().ok();
            }
            "endpoint_id" => {
                endpoint_id = value.parse::<u64>().ok();
            }
            "node_id" => {
                node_id = value.parse::<u64>().ok();
            }
            "leg_key" | "logical_key" => {
                if !value.is_empty() {
                    explicit_leg_key = Some(value.to_string());
                }
            }
            "display_host" | "host_label" => {
                if !value.is_empty() {
                    display_host = Some(value.to_string());
                }
            }
            _ => {}
        }
    }

    if let (Some(required), Some(actual)) = (options.required_forward_id, forward_id) {
        if required != actual {
            return Err(anyhow!("stable_leg_forward_id_mismatch:required={required}:actual={actual}"));
        }
    }

    let stable_identity = match (options.required_forward_id.or(forward_id), node_id, endpoint_id) {
        (Some(fwd), Some(node), Some(endpoint)) if matches!(transport, LegTransport::Tcp) => {
            Some(StableLegIdentity::new(fwd, "tcp", node, endpoint)?)
        }
        (Some(_), Some(_), Some(_)) if options.require_stable_identity => {
            return Err(anyhow!("unsupported_stable_leg_protocol:{:?}", transport));
        }
        _ if options.require_stable_identity => return Err(anyhow!("missing_stable_leg_identity")),
        _ => None,
    };

    let logical_key = match (&stable_identity, explicit_leg_key) {
        (Some(identity), Some(explicit)) => {
            let expected = identity.logical_key();
            if explicit != expected {
                return Err(anyhow!("stable_leg_key_mismatch:expected={expected}:actual={explicit}"));
            }
            Some(explicit)
        }
        (Some(identity), None) => Some(identity.logical_key()),
        (None, Some(explicit)) if options.require_stable_identity => {
            return Err(anyhow!("missing_stable_leg_identity_for_key:{explicit}"));
        }
        (None, Some(explicit)) => Some(explicit),
        (None, None) => None,
    };

    Ok(LegSpec {
        transport,
        host,
        port,
        server_name,
        insecure,
        priority,
        role,
        endpoint_id,
        node_id,
        display_host,
        stable_identity,
        logical_key,
        original: trimmed.to_string(),
    })
}
```

- [ ] **Step 5: Run parser tests and verify backward compatibility**

Run:

```bash
cd wstunnel-main/leikwan-bond && cargo test parse_leg_spec -- --nocapture
```

Expected: PASS. The existing role-query test and the new strict identity tests all pass.

- [ ] **Step 6: Commit stable identity parsing**

Run:

```bash
git add wstunnel-main/leikwan-bond/src/transport.rs
git commit -m "feat(bond): parse stable leg identity" -m "Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Enforce explicit one-to-eight leg-set policy at the CLI boundary

**Files:**
- Modify: `wstunnel-main/leikwan-bond/src/transport.rs:75-110`
- Modify: `wstunnel-main/leikwan-bond/src/main.rs:1-160`
- Modify: `wstunnel-main/leikwan-bond/src/session.rs:530-1416`

- [ ] **Step 1: Write failing leg-set tests**

Add these tests to `wstunnel-main/leikwan-bond/src/transport.rs`:

```rust
#[test]
fn parse_leg_specs_with_policy_rejects_zero_usable_legs() {
    let err = parse_leg_specs_with_policy(
        &[],
        LegSetPolicy {
            required_forward_id: Some(91),
            require_stable_identity: true,
            min_usable_legs: 1,
            max_usable_legs: 8,
        },
    )
    .expect_err("zero legs should fail");

    assert!(err.to_string().contains("invalid_speed_plus_leg_set:no_usable_legs"));
}

#[test]
fn parse_leg_specs_with_policy_rejects_more_than_eight_legs() {
    let legs: Vec<String> = (1..=9)
        .map(|idx| {
            format!(
                "tcp://198.51.100.{idx}:24{idx:03}?forward_id=91&node_id={idx}&endpoint_id={}",
                180 + idx
            )
        })
        .collect();
    let refs: Vec<&str> = legs.iter().map(String::as_str).collect();

    let err = parse_leg_specs_with_policy(
        &refs,
        LegSetPolicy {
            required_forward_id: Some(91),
            require_stable_identity: true,
            min_usable_legs: 1,
            max_usable_legs: 8,
        },
    )
    .expect_err("nine legs should fail");

    assert!(err.to_string().contains("invalid_speed_plus_leg_set:too_many_legs"));
}

#[test]
fn parse_leg_specs_with_policy_rejects_duplicate_logical_keys() {
    let legs = [
        "tcp://198.51.100.10:24001?forward_id=91&node_id=20&endpoint_id=184",
        "tcp://198.51.100.11:24002?forward_id=91&node_id=20&endpoint_id=184",
    ];

    let err = parse_leg_specs_with_policy(
        &legs,
        LegSetPolicy {
            required_forward_id: Some(91),
            require_stable_identity: true,
            min_usable_legs: 1,
            max_usable_legs: 8,
        },
    )
    .expect_err("duplicate leg key should fail");

    assert!(err.to_string().contains("duplicate_bond_leg_key:bond:91:tcp:20:184"));
}

#[test]
fn parse_leg_specs_with_policy_accepts_one_to_eight_distinct_legs() {
    let legs = [
        "tcp://198.51.100.10:24001?forward_id=91&node_id=20&endpoint_id=184",
        "tcp://198.51.100.11:24002?forward_id=91&node_id=21&endpoint_id=185",
    ];

    let specs = parse_leg_specs_with_policy(
        &legs,
        LegSetPolicy {
            required_forward_id: Some(91),
            require_stable_identity: true,
            min_usable_legs: 1,
            max_usable_legs: 8,
        },
    )
    .expect("two distinct stable legs should parse");

    assert_eq!(specs.len(), 2);
    assert_eq!(specs[0].logical_key.as_deref(), Some("bond:91:tcp:20:184"));
    assert_eq!(specs[1].logical_key.as_deref(), Some("bond:91:tcp:21:185"));
}
```

Add this CLI test to `wstunnel-main/leikwan-bond/src/main.rs`:

```rust
#[test]
fn client_cli_accepts_stable_identity_policy_flags() {
    let cli = Cli::try_parse_from([
        "leikwan-bond",
        "client",
        "--protocol",
        "tcp",
        "--listen",
        "127.0.0.1:11024",
        "--legs",
        "tcp://198.51.100.10:24001?forward_id=91&node_id=20&endpoint_id=184",
        "--session-token",
        "token",
        "--forward-id",
        "91",
        "--require-stable-leg-identity",
        "--min-usable-legs",
        "1",
        "--max-usable-legs",
        "8",
    ])
    .expect("client cli should parse stable identity flags");

    match cli.command {
        Command::Client {
            forward_id,
            require_stable_leg_identity,
            min_usable_legs,
            max_usable_legs,
            ..
        } => {
            assert_eq!(forward_id, Some(91));
            assert!(require_stable_leg_identity);
            assert_eq!(min_usable_legs, 1);
            assert_eq!(max_usable_legs, 8);
        }
        _ => panic!("expected client command"),
    }
}
```

- [ ] **Step 2: Run the new leg-set and CLI tests and verify they fail before implementation**

Run:

```bash
cd wstunnel-main/leikwan-bond && cargo test parse_leg_specs_with_policy -- --nocapture
cd wstunnel-main/leikwan-bond && cargo test client_cli_accepts_stable_identity_policy_flags -- --nocapture
```

Expected: both commands FAIL because `LegSetPolicy`, `parse_leg_specs_with_policy`, and the CLI flags do not exist.

- [ ] **Step 3: Add `LegSetPolicy` and strict leg-list parser**

In `wstunnel-main/leikwan-bond/src/transport.rs`, add this public type and replace `parse_leg_specs` with a compatibility wrapper:

```rust
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct LegSetPolicy {
    pub required_forward_id: Option<u64>,
    pub require_stable_identity: bool,
    pub min_usable_legs: usize,
    pub max_usable_legs: usize,
}

impl Default for LegSetPolicy {
    fn default() -> Self {
        Self {
            required_forward_id: None,
            require_stable_identity: false,
            min_usable_legs: 1,
            max_usable_legs: usize::MAX,
        }
    }
}

pub fn parse_leg_specs(raw: Vec<String>) -> anyhow::Result<Vec<LegSpec>> {
    let refs: Vec<&str> = raw.iter().map(String::as_str).collect();
    parse_leg_specs_with_policy(&refs, LegSetPolicy::default())
}

pub fn parse_leg_specs_with_policy(raw: &[&str], policy: LegSetPolicy) -> anyhow::Result<Vec<LegSpec>> {
    if raw.len() < policy.min_usable_legs {
        return Err(anyhow!("invalid_speed_plus_leg_set:no_usable_legs"));
    }
    if raw.len() > policy.max_usable_legs {
        return Err(anyhow!(
            "invalid_speed_plus_leg_set:too_many_legs:max={}:actual={}",
            policy.max_usable_legs,
            raw.len()
        ));
    }

    let mut seen = std::collections::HashSet::<String>::new();
    let mut specs = Vec::with_capacity(raw.len());
    for leg in raw {
        let spec = parse_leg_spec_with_options(
            leg,
            LegParseOptions {
                required_forward_id: policy.required_forward_id,
                require_stable_identity: policy.require_stable_identity,
            },
        )?;
        if policy.require_stable_identity {
            let key = spec
                .logical_key
                .clone()
                .ok_or_else(|| anyhow!("missing_stable_leg_identity"))?;
            if !seen.insert(key.clone()) {
                return Err(anyhow!("duplicate_bond_leg_key:{key}"));
            }
        }
        specs.push(spec);
    }

    Ok(specs)
}
```

- [ ] **Step 4: Add client CLI flags and call the strict parser**

In `wstunnel-main/leikwan-bond/src/main.rs`, import the policy and add the flags to `Command::Client`:

```rust
use transport::{parse_leg_specs, parse_leg_specs_with_policy, LegSetPolicy, LegSpec};
```

Inside the `Client` variant, add:

```rust
        #[arg(long)]
        forward_id: Option<u64>,
        #[arg(long, default_value_t = false)]
        require_stable_leg_identity: bool,
        #[arg(long, default_value = "1")]
        min_usable_legs: usize,
        #[arg(long, default_value = "8")]
        max_usable_legs: usize,
```

In the `Command::Client` match arm, parse once before starting the runtime:

```rust
            let leg_refs: Vec<&str> = legs.iter().map(String::as_str).collect();
            let use_policy = require_stable_leg_identity
                || forward_id.is_some()
                || min_usable_legs != 1
                || max_usable_legs != 8;
            let specs = if use_policy {
                parse_leg_specs_with_policy(
                    &leg_refs,
                    LegSetPolicy {
                        required_forward_id: forward_id,
                        require_stable_identity: require_stable_leg_identity,
                        min_usable_legs,
                        max_usable_legs,
                    },
                )?
            } else {
                parse_leg_specs(legs.clone())?
            };
```

Change the session API so the parsed specs, including `stable_identity` and `logical_key`, are not discarded:

```rust
pub async fn run_client_listener(
    bind: String,
    leg_specs: Vec<LegSpec>,
    session_token: String,
    protocol: TransportProtocol,
    config: SessionConfig,
) -> anyhow::Result<()> {
    match protocol {
        TransportProtocol::Tcp => run_client_tcp_listener(bind, leg_specs, session_token, config).await,
        TransportProtocol::Udp => run_client_udp_listener(bind, leg_specs, session_token, config).await,
    }
}
```

Update `run_client_tcp_listener`, `connect_client_tcp_transport_session`, `open_client_tcp_transport_session`, and `connect_client_tcp_transport_legs` to accept `Vec<LegSpec>` instead of `Vec<String>`. In `run_client_tcp_listener`, clone `leg_specs` when reconnecting the persistent transport after a send failure. Remove the old `parse_leg_specs(leg_targets)?` call from `connect_client_tcp_transport_legs`; start that function with:

```rust
async fn connect_client_tcp_transport_legs(
    mut specs: Vec<LegSpec>,
    session_token: String,
    connect_timeout: Duration,
) -> anyhow::Result<ClientTransportBootstrap> {
    specs.sort_by_key(|spec| (spec.priority, transport_bias(spec.transport), spec.original.clone()));
    if specs.is_empty() {
        return Err(anyhow!("at least one bond leg target is required"));
    }
    // existing session_id, hello, initial connect, reconnect, and bootstrap code follows
}
```

Update `run_client_udp_listener` and `connect_client_udp_legs` the same way if they still parse `Vec<String>` internally. TCP is the acceptance target for this plan, but preserving one parser boundary avoids divergent TCP/UDP behavior.

Ensure existing ad-hoc commands still work: `--require-stable-leg-identity` opts into fail-closed behavior, and the Linux supervisor plan will make that opt-in mandatory for active LeiGod Forwards.

- [ ] **Step 5: Run policy and CLI tests**

Run:

```bash
cd wstunnel-main/leikwan-bond && cargo test parse_leg_specs_with_policy -- --nocapture
cd wstunnel-main/leikwan-bond && cargo test client_cli_accepts_stable_identity_policy_flags -- --nocapture
```

Expected: PASS for both commands.

- [ ] **Step 6: Commit leg-set policy**

Run:

```bash
git add wstunnel-main/leikwan-bond/src/transport.rs wstunnel-main/leikwan-bond/src/main.rs wstunnel-main/leikwan-bond/src/session.rs
git commit -m "feat(bond): enforce explicit leg set policy" -m "Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Propagate stable identity through open legs, runtime legs, and labels

**Files:**
- Modify: `wstunnel-main/leikwan-bond/src/session.rs:336-515`
- Modify: `wstunnel-main/leikwan-bond/src/session.rs:1487-1521`
- Modify: `wstunnel-main/leikwan-bond/src/session.rs:2469-2505`

- [ ] **Step 1: Write failing identity propagation tests**

Add these tests to the existing test module in `wstunnel-main/leikwan-bond/src/session.rs`:

```rust
async fn test_tcp_stream_pair() -> (tokio::net::TcpStream, tokio::net::TcpStream) {
    let listener = tokio::net::TcpListener::bind("127.0.0.1:0")
        .await
        .expect("bind test listener");
    let addr = listener.local_addr().expect("test listener addr");
    let client = tokio::net::TcpStream::connect(addr);
    let (server, _) = listener.accept().await.expect("accept test peer");
    let client = client.await.expect("connect test peer");
    (client, server)
}

#[tokio::test]
async fn open_leg_preserves_stable_identity_and_logical_key() {
    let (stream, _peer) = test_tcp_stream_pair().await;
    let identity = StableLegIdentity::new(91, "tcp", 20, 184).expect("identity");

    let leg = OpenLeg::new(
        LegIo::Tcp(stream),
        10,
        LegRole::Primary,
        "tcp://198.51.100.10:24001".to_string(),
    )
    .with_stable_identity(identity);

    assert_eq!(leg.forward_id, Some(91));
    assert_eq!(leg.node_id, Some(20));
    assert_eq!(leg.endpoint_id, Some(184));
    assert_eq!(leg.logical_key.as_deref(), Some("bond:91:tcp:20:184"));
}

#[tokio::test]
async fn spawn_tcp_leg_task_copies_logical_identity_into_leg_state() {
    let (stream, _peer) = test_tcp_stream_pair().await;
    let identity = StableLegIdentity::new(91, "tcp", 20, 184).expect("identity");
    let (event_tx, _event_rx) = mpsc::unbounded_channel();

    let leg = OpenLeg::new(LegIo::Tcp(stream), 10, LegRole::Primary, "leg-a".to_string())
        .with_stable_identity(identity)
        .with_spec_index(0)
        .with_metadata(Some(184), Some(20), "node20".to_string());

    let state = spawn_tcp_leg_task(0, leg, event_tx);

    assert_eq!(state.forward_id, Some(91));
    assert_eq!(state.node_id, Some(20));
    assert_eq!(state.endpoint_id, Some(184));
    assert_eq!(state.logical_key.as_deref(), Some("bond:91:tcp:20:184"));
    assert_eq!(state.stable_label(), "bond:91:tcp:20:184");
}
```

- [ ] **Step 2: Run identity propagation tests and verify they fail before implementation**

Run:

```bash
cd wstunnel-main/leikwan-bond && cargo test open_leg_preserves_stable_identity_and_logical_key -- --nocapture
cd wstunnel-main/leikwan-bond && cargo test spawn_tcp_leg_task_copies_logical_identity_into_leg_state -- --nocapture
```

Expected: both commands FAIL because `OpenLeg` and `LegState` do not expose stable identity fields or `with_stable_identity`.

- [ ] **Step 3: Extend `OpenLeg` without changing handshake semantics**

In `wstunnel-main/leikwan-bond/src/session.rs`, import the identity type:

```rust
use crate::transport::{connect_leg, parse_leg_specs_with_policy, LegIo, LegRole, LegSpec, LegTransport, StableLegIdentity};
```

Extend `OpenLeg`:

```rust
struct OpenLeg {
    stream: LegIo,
    transport: LegTransport,
    priority: u16,
    role: LegRole,
    label: String,
    spec_index: Option<usize>,
    endpoint_id: Option<u64>,
    node_id: Option<u64>,
    forward_id: Option<u64>,
    logical_key: Option<String>,
    stable_identity: Option<StableLegIdentity>,
    host: String,
}
```

Update `OpenLeg::new` and add `with_stable_identity`:

```rust
impl OpenLeg {
    fn new(stream: LegIo, priority: u16, role: LegRole, label: String) -> Self {
        let transport = stream.transport();
        Self {
            stream,
            transport,
            priority,
            role,
            host: extract_host_from_label(&label),
            label,
            spec_index: None,
            endpoint_id: None,
            node_id: None,
            forward_id: None,
            logical_key: None,
            stable_identity: None,
        }
    }

    fn with_stable_identity(mut self, identity: StableLegIdentity) -> Self {
        self.forward_id = Some(identity.forward_id);
        self.node_id = Some(identity.node_id);
        self.endpoint_id = Some(identity.endpoint_id);
        self.logical_key = Some(identity.logical_key());
        self.stable_identity = Some(identity);
        self
    }
}
```

- [ ] **Step 4: Attach parsed identity during the client handshake**

In `connect_and_ack_leg`, change the successful branch to attach identity after metadata:

```rust
let mut open_leg = OpenLeg::new(stream, spec.priority, spec.role, spec.original.clone())
    .with_metadata(
        spec.endpoint_id,
        spec.node_id,
        spec.display_host.clone().unwrap_or_else(|| spec.host.clone()),
    );
if let Some(identity) = spec.stable_identity.clone() {
    open_leg = open_leg.with_stable_identity(identity);
} else if let Some(logical_key) = spec.logical_key.clone() {
    open_leg.logical_key = Some(logical_key);
}
return Ok(open_leg);
```

This preserves the existing `Hello`/`HelloAck` wire protocol and adds no credential material to logs.

- [ ] **Step 5: Extend `LegState` and state creation**

Extend `LegState`:

```rust
struct LegState {
    id: usize,
    sender: mpsc::UnboundedSender<WireFrame>,
    active: bool,
    inflight_bytes: usize,
    transport: LegTransport,
    priority: u16,
    role: LegRole,
    smoothed_rtt: Duration,
    label: String,
    spec_index: Option<usize>,
    endpoint_id: Option<u64>,
    node_id: Option<u64>,
    forward_id: Option<u64>,
    logical_key: Option<String>,
    stable_identity: Option<StableLegIdentity>,
    host: String,
    cooldown_until: Option<Instant>,
    penalty_count: u8,
    tcp_health: ProtocolLegHealth,
    udp_health: ProtocolLegHealth,
    recent_events: VecDeque<LegHealthEvent>,
}

impl LegState {
    fn stable_label(&self) -> String {
        self.logical_key.clone().unwrap_or_else(|| self.label.clone())
    }
}
```

In `spawn_tcp_leg_task`, copy the new fields:

```rust
let transport = leg.transport;
let priority = leg.priority;
let role = leg.role;
let label = leg.label;
let spec_index = leg.spec_index;
let endpoint_id = leg.endpoint_id;
let node_id = leg.node_id;
let forward_id = leg.forward_id;
let logical_key = leg.logical_key;
let stable_identity = leg.stable_identity;
let host = leg.host;
let (reader, writer) = tokio::io::split(leg.stream);
let (tx, rx) = mpsc::unbounded_channel();
tokio::spawn(run_tcp_leg_writer(leg_id, writer, rx, event_tx.clone()));
tokio::spawn(run_tcp_leg_reader(leg_id, reader, event_tx));
LegState {
    id: leg_id,
    sender: tx,
    active: true,
    inflight_bytes: 0,
    transport,
    priority,
    role,
    smoothed_rtt: initial_leg_rtt(transport),
    label,
    spec_index,
    endpoint_id,
    node_id,
    forward_id,
    logical_key,
    stable_identity,
    host,
    cooldown_until: None,
    penalty_count: 0,
    tcp_health: ProtocolLegHealth::new(),
    udp_health: ProtocolLegHealth::new(),
    recent_events: VecDeque::new(),
}
```

- [ ] **Step 6: Run identity propagation tests**

Run:

```bash
cd wstunnel-main/leikwan-bond && cargo test open_leg_preserves_stable_identity_and_logical_key -- --nocapture
cd wstunnel-main/leikwan-bond && cargo test spawn_tcp_leg_task_copies_logical_identity_into_leg_state -- --nocapture
```

Expected: PASS for both commands.

- [ ] **Step 7: Commit identity propagation**

Run:

```bash
git add wstunnel-main/leikwan-bond/src/session.rs
git commit -m "feat(bond): preserve logical leg identity" -m "Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Add deterministic pool classification and best-survivor selection

**Files:**
- Modify: `wstunnel-main/leikwan-bond/src/session.rs:80-180`
- Modify: `wstunnel-main/leikwan-bond/src/session.rs:1589-2050`
- Modify: `wstunnel-main/leikwan-bond/src/session.rs:4211-4397`

- [ ] **Step 1: Write failing tests for pool mode and best-survivor ordering**

Add tests to `wstunnel-main/leikwan-bond/src/session.rs`:

```rust
#[test]
fn classify_tcp_pool_distinguishes_single_speed_plus_and_degraded() {
    let mut legs = test_tcp_legs([
        test_leg("bond:91:tcp:20:184", 20, 184, true, Duration::from_millis(40), 0.90),
        test_leg("bond:91:tcp:21:185", 21, 185, true, Duration::from_millis(45), 0.85),
    ]);

    assert_eq!(classify_tcp_pool(&legs), TcpPoolMode::SpeedPlus);

    legs[1].active = false;
    assert_eq!(
        classify_tcp_pool(&legs),
        TcpPoolMode::SingleLegOnly { leg_id: legs[0].id }
    );

    legs[0].active = false;
    assert_eq!(classify_tcp_pool(&legs), TcpPoolMode::NoUsableLegs);
}

#[test]
fn best_surviving_tcp_leg_is_deterministic() {
    let mut legs = test_tcp_legs([
        test_leg("bond:91:tcp:22:186", 22, 186, true, Duration::from_millis(38), 0.75),
        test_leg("bond:91:tcp:20:184", 20, 184, true, Duration::from_millis(38), 0.75),
        test_leg("bond:91:tcp:21:185", 21, 185, true, Duration::from_millis(50), 0.98),
    ]);
    legs[2].tcp_health.penalty_ewma = 0.50;

    let best = best_surviving_tcp_leg(&legs).expect("best leg");
    assert_eq!(
        get_tcp_leg_by_id(&legs, best).expect("best leg by id").stable_label(),
        "bond:91:tcp:20:184"
    );
}

#[test]
fn select_stream_leg_uses_best_survivor_in_degraded_pool() {
    let mut cursor = 0usize;
    let legs = test_tcp_legs([
        test_leg("bond:91:tcp:20:184", 20, 184, true, Duration::from_millis(40), 0.90),
        test_leg("bond:91:tcp:21:185", 21, 185, false, Duration::from_millis(35), 0.99),
    ]);
    let config = test_session_config();
    let mode = TcpPoolMode::DegradedSingle {
        leg_id: 0,
        reason: PoolDegradeReason::OnlyOneUsableLeg,
    };

    let selected = select_stream_leg_with_pool_mode(
        &legs,
        &mut cursor,
        None,
        131_072,
        0,
        &config,
        &mode,
    );

    assert_eq!(selected, Some(0));
}
```

Add compact test helpers in the same test module:

```rust
fn test_session_config() -> SessionConfig {
    SessionConfig {
        chunk_size: 131_072,
        max_window_bytes: 16_777_216,
        retransmit_after: Duration::from_millis(300),
        join_timeout: Duration::from_millis(400),
        connect_timeout: Duration::from_millis(3_000),
        interactive_chunk_bytes: 16_384,
        latency_guard_bytes: 262_144,
        interactive_rtt_slack: Duration::from_millis(30),
        warmup_trigger_bytes: 65_536,
        warmup_slice_bytes: 65_536,
        warmup_leg_delay: Duration::from_millis(80),
        bulk_promote_bytes: 262_144,
        leg_cooldown: Duration::from_millis(3_000),
        latency_first: false,
        udp_primary_only: false,
    }
}
```

- [ ] **Step 2: Run pool-mode tests and verify they fail before implementation**

Run:

```bash
cd wstunnel-main/leikwan-bond && cargo test classify_tcp_pool_distinguishes_single_speed_plus_and_degraded -- --nocapture
cd wstunnel-main/leikwan-bond && cargo test best_surviving_tcp_leg_is_deterministic -- --nocapture
cd wstunnel-main/leikwan-bond && cargo test select_stream_leg_uses_best_survivor_in_degraded_pool -- --nocapture
```

Expected: all three commands FAIL because the pool-mode types and helper functions do not exist.

- [ ] **Step 3: Add explicit pool-mode types**

Near `HealthProtocol`, add:

```rust
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum PoolDegradeReason {
    OnlyOneUsableLeg,
    RetransmitPressure,
    ProbationPending,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum TcpPoolMode {
    NoUsableLegs,
    SingleLegOnly { leg_id: usize },
    SpeedPlus,
    DegradedSingle { leg_id: usize, reason: PoolDegradeReason },
}

impl TcpPoolMode {
    fn pinned_leg(self) -> Option<usize> {
        match self {
            Self::SingleLegOnly { leg_id } | Self::DegradedSingle { leg_id, .. } => Some(leg_id),
            Self::NoUsableLegs | Self::SpeedPlus => None,
        }
    }

    fn as_str(self) -> &'static str {
        match self {
            Self::NoUsableLegs => "no_usable_legs",
            Self::SingleLegOnly { .. } => "single_leg_only",
            Self::SpeedPlus => "speed_plus",
            Self::DegradedSingle { .. } => "degraded_single_leg",
        }
    }
}
```

- [ ] **Step 4: Implement deterministic best-survivor selection**

Add helper functions near the existing `select_leg_for_data_with_cooldown` helpers:

```rust
fn tcp_leg_is_usable(leg: &LegState) -> bool {
    leg.active && !matches!(leg.tcp_health.state, LegHealthState::Quarantined)
}

fn find_tcp_leg_index_by_id(legs: &[LegState], leg_id: usize) -> Option<usize> {
    legs.iter().position(|leg| leg.id == leg_id)
}

fn get_tcp_leg_by_id(legs: &[LegState], leg_id: usize) -> Option<&LegState> {
    find_tcp_leg_index_by_id(legs, leg_id).and_then(|index| legs.get(index))
}

fn get_tcp_leg_mut_by_id(legs: &mut [LegState], leg_id: usize) -> Option<&mut LegState> {
    let index = find_tcp_leg_index_by_id(legs, leg_id)?;
    legs.get_mut(index)
}

fn best_surviving_tcp_leg(legs: &[LegState]) -> Option<usize> {
    legs.iter()
        .filter(|leg| tcp_leg_is_usable(leg))
        .min_by(|left, right| compare_best_tcp_leg(left, right))
        .map(|leg| leg.id)
}

fn compare_best_tcp_leg(left: &LegState, right: &LegState) -> std::cmp::Ordering {
    left.tcp_health
        .penalty_ewma
        .partial_cmp(&right.tcp_health.penalty_ewma)
        .unwrap_or(std::cmp::Ordering::Equal)
        .then_with(|| left.smoothed_rtt.cmp(&right.smoothed_rtt))
        .then_with(|| right.tcp_health.effective_weight.partial_cmp(&left.tcp_health.effective_weight).unwrap_or(std::cmp::Ordering::Equal))
        .then_with(|| left.priority.cmp(&right.priority))
        .then_with(|| left.stable_label().cmp(&right.stable_label()))
        .then_with(|| left.id.cmp(&right.id))
}

fn classify_tcp_pool(legs: &[LegState]) -> TcpPoolMode {
    let usable: Vec<usize> = legs
        .iter()
        .filter(|leg| tcp_leg_is_usable(leg))
        .map(|leg| leg.id)
        .collect();
    match usable.len() {
        0 => TcpPoolMode::NoUsableLegs,
        1 => TcpPoolMode::SingleLegOnly { leg_id: usable[0] },
        _ => TcpPoolMode::SpeedPlus,
    }
}
```

`LegHealthState::Probation` remains usable for control and same-family survival. Task 6 prevents probation legs from resuming bulk Speed Plus striping while a non-probation leg exists.

- [ ] **Step 5: Make stream selection pool-mode aware**

Replace `select_stream_leg` with a wrapper and a pool-mode-aware implementation:

```rust
fn select_stream_leg(
    legs: &[LegState],
    round_robin_cursor: &mut usize,
    preferred_leg_id: Option<usize>,
    payload_len: usize,
    outstanding_bytes: usize,
    config: &SessionConfig,
) -> Option<usize> {
    let mode = classify_tcp_pool(legs);
    select_stream_leg_with_pool_mode(
        legs,
        round_robin_cursor,
        preferred_leg_id,
        payload_len,
        outstanding_bytes,
        config,
        &mode,
    )
}

fn select_stream_leg_with_pool_mode(
    legs: &[LegState],
    round_robin_cursor: &mut usize,
    preferred_leg_id: Option<usize>,
    payload_len: usize,
    outstanding_bytes: usize,
    config: &SessionConfig,
    pool_mode: &TcpPoolMode,
) -> Option<usize> {
    if let Some(leg_id) = pool_mode.pinned_leg() {
        return get_tcp_leg_by_id(legs, leg_id).filter(|leg| tcp_leg_is_usable(leg)).map(|leg| leg.id);
    }

    let interactive_flow = payload_len <= config.interactive_chunk_bytes
        || outstanding_bytes < config.latency_guard_bytes;

    if config.latency_first && outstanding_bytes < config.bulk_promote_bytes {
        if let Some(primary_leg) = legs
            .iter()
            .find(|leg| leg.active && leg.role == LegRole::Primary && leg_available_for_new_data(leg, Instant::now()))
        {
            return Some(primary_leg.id);
        }
    }

    if interactive_flow {
        if let Some(leg_id) = preferred_leg_id {
            if preferred_leg_allowed_for_interactive_tcp(legs, leg_id, Instant::now()) {
                return Some(leg_id);
            }
        }
    }

    select_leg_for_data(
        legs,
        round_robin_cursor,
        HealthProtocol::Tcp,
        payload_len,
        outstanding_bytes,
        config,
    )
}
```

- [ ] **Step 6: Maintain pool mode in the transport loop**

Inside `run_tcp_transport_session`, add:

```rust
let mut tcp_pool_mode = classify_tcp_pool(&legs);
let mut degraded_since = None::<Instant>;
```

Add this helper near `classify_tcp_pool`:

```rust
fn refresh_tcp_pool_mode(
    session_id: Uuid,
    legs: &[LegState],
    tcp_pool_mode: &mut TcpPoolMode,
    degraded_since: &mut Option<Instant>,
) {
    let previous_mode = *tcp_pool_mode;
    *tcp_pool_mode = classify_tcp_pool(legs);
    if matches!(
        *tcp_pool_mode,
        TcpPoolMode::DegradedSingle { .. } | TcpPoolMode::SingleLegOnly { .. }
    ) {
        if degraded_since.is_none() {
            *degraded_since = Some(Instant::now());
        }
    } else {
        *degraded_since = None;
    }
    if previous_mode != *tcp_pool_mode {
        info!(
            "bond TCP transport {} pool mode changed {} -> {}",
            session_id,
            previous_mode.as_str(),
            tcp_pool_mode.as_str()
        );
    }
}
```

Call `refresh_tcp_pool_mode(session_id, &legs, &mut tcp_pool_mode, &mut degraded_since);` at these exact branches in `run_tcp_transport_session`:

1. Immediately after pushing a new state in the `Some(new_leg) = add_leg_rx.recv()` branch.
2. Immediately after `handle_tcp_transport_frame(...).await?` returns, because ACK, data, FIN, and reset handling can change health and affinity.
3. Immediately after the `TcpTransportEvent::LegClosed { leg_id }` branch marks a leg inactive and re-pins streams.
4. Immediately after each retransmit tick calls `penalize_tcp_leg`, `suppress_stream_preferred_tcp_leg`, or `maybe_readmit_probation_leg` in Tasks 5 and 6.

When active legs drop below two, replace the old `preferred_leg_id = None` reset with a deterministic repin:

```rust
if let Some(best) = best_surviving_tcp_leg(&legs) {
    for state in streams.values_mut() {
        state.preferred_leg_id = Some(best);
    }
}
```

- [ ] **Step 7: Run pool-mode tests**

Run:

```bash
cd wstunnel-main/leikwan-bond && cargo test classify_tcp_pool -- --nocapture
cd wstunnel-main/leikwan-bond && cargo test best_surviving_tcp_leg -- --nocapture
cd wstunnel-main/leikwan-bond && cargo test select_stream_leg_uses_best_survivor_in_degraded_pool -- --nocapture
```

Expected: PASS for all three commands.

- [ ] **Step 8: Commit pool-mode scheduler foundation**

Run:

```bash
git add wstunnel-main/leikwan-bond/src/session.rs
git commit -m "feat(bond): classify tcp pool degradation" -m "Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Retransmit unacknowledged chunks through the best surviving Slave

**Files:**
- Modify: `wstunnel-main/leikwan-bond/src/session.rs:2731-2800`
- Modify: `wstunnel-main/leikwan-bond/src/session.rs:3869-3940`
- Modify: `wstunnel-main/leikwan-bond/src/session.rs:4314-4345`
- Modify: `wstunnel-main/leikwan-bond/src/session.rs:4754-4775`

- [ ] **Step 1: Write failing retransmit tests**

Add these tests to `wstunnel-main/leikwan-bond/src/session.rs`:

```rust
#[test]
fn select_retransmit_leg_uses_best_survivor_when_degraded() {
    let mut cursor = 0usize;
    let legs = test_tcp_legs([
        test_leg("bond:91:tcp:20:184", 20, 184, true, Duration::from_millis(40), 0.90),
        test_leg("bond:91:tcp:21:185", 21, 185, false, Duration::from_millis(35), 0.99),
    ]);
    let config = test_session_config();
    let pool_mode = TcpPoolMode::DegradedSingle {
        leg_id: 0,
        reason: PoolDegradeReason::OnlyOneUsableLeg,
    };

    let selected = select_retransmit_leg_with_pool_mode(
        &legs,
        &mut cursor,
        None,
        1,
        2,
        131_072,
        131_072,
        &config,
        &pool_mode,
    );

    assert_eq!(selected, Some(0));
}

#[test]
fn mark_transport_streams_for_best_survivor_retry_repins_pending_chunks() {
    let mut streams = HashMap::<u32, TcpStreamState>::new();
    let mut state = test_tcp_stream_state(7);
    state.pending_chunks.insert(
        0,
        PendingChunk {
            payload: Bytes::from_static(b"hello"),
            last_sent_at: Instant::now(),
            last_leg_id: 1,
            transmit_count: 1,
        },
    );
    streams.insert(7, state);

    mark_transport_streams_for_best_survivor_retry(
        &mut streams,
        0,
        Duration::from_millis(300),
    );

    let stream = streams.get(&7).expect("stream");
    assert_eq!(stream.preferred_leg_id, Some(0));
    let chunk = stream.pending_chunks.get(&0).expect("pending chunk");
    assert_eq!(chunk.last_leg_id, 0);
    assert!(chunk.last_sent_at.elapsed() >= Duration::from_millis(300));
}
```

Add `test_tcp_stream_state` in the same test module with the smallest constructor needed for `pending_chunks`, `preferred_leg_id`, and close-state fields used by current methods:

```rust
fn test_tcp_stream_state(_stream_id: u32) -> TcpStreamState {
    let (local_write_tx, _local_write_rx) = mpsc::channel(8);
    let (close_tx, _close_rx) = watch::channel(false);
    TcpStreamState::new(local_write_tx, close_tx, true)
}
```

Use the existing constructor so the helper tracks the current close-state fields without inventing a parallel struct initializer.

- [ ] **Step 2: Run retransmit tests and verify they fail before implementation**

Run:

```bash
cd wstunnel-main/leikwan-bond && cargo test select_retransmit_leg_uses_best_survivor_when_degraded -- --nocapture
cd wstunnel-main/leikwan-bond && cargo test mark_transport_streams_for_best_survivor_retry_repins_pending_chunks -- --nocapture
```

Expected: both commands FAIL because the new helper functions and pool-mode argument do not exist.

- [ ] **Step 3: Add best-survivor retry marker**

Add this helper near `mark_transport_streams_for_retry`:

```rust
fn mark_transport_streams_for_best_survivor_retry(
    streams: &mut HashMap<u32, TcpStreamState>,
    best_leg_id: usize,
    retransmit_after: Duration,
) {
    let retry_at = Instant::now()
        .checked_sub(retransmit_after)
        .unwrap_or_else(Instant::now);
    for state in streams.values_mut() {
        state.preferred_leg_id = Some(best_leg_id);
        for chunk in state.pending_chunks.values_mut() {
            chunk.last_sent_at = retry_at;
            if chunk.last_leg_id != best_leg_id {
                chunk.last_leg_id = best_leg_id;
                chunk.readmission_probe_leg_id = None;
            }
        }
    }
}
```

This does not mark chunks as acknowledged. It only makes the next retransmit tick eligible to send through the best surviving bond leg. If a chunk had been a readmission probe for a different probation leg, the re-pin clears `readmission_probe_leg_id`; ACK credit is valid only for bytes whose most recent send stayed on the same probation leg.

- [ ] **Step 4: Make retransmit selection pool-mode aware**

Replace `select_retransmit_leg` with a wrapper and a pool-mode-aware function:

```rust
fn select_retransmit_leg(
    legs: &[LegState],
    round_robin_cursor: &mut usize,
    preferred_leg_id: Option<usize>,
    last_leg_id: usize,
    transmit_count: u8,
    payload_len: usize,
    outstanding_bytes: usize,
    config: &SessionConfig,
) -> Option<usize> {
    let mode = classify_tcp_pool(legs);
    select_retransmit_leg_with_pool_mode(
        legs,
        round_robin_cursor,
        preferred_leg_id,
        last_leg_id,
        transmit_count,
        payload_len,
        outstanding_bytes,
        config,
        &mode,
    )
}

fn select_retransmit_leg_with_pool_mode(
    legs: &[LegState],
    round_robin_cursor: &mut usize,
    preferred_leg_id: Option<usize>,
    last_leg_id: usize,
    transmit_count: u8,
    payload_len: usize,
    outstanding_bytes: usize,
    config: &SessionConfig,
    pool_mode: &TcpPoolMode,
) -> Option<usize> {
    if let Some(leg_id) = pool_mode.pinned_leg() {
        return get_tcp_leg_by_id(legs, leg_id).filter(|leg| tcp_leg_is_usable(leg)).map(|leg| leg.id);
    }

    if transmit_count >= 1 {
        if let Some(leg_id) = select_alternate_leg(legs, round_robin_cursor, HealthProtocol::Tcp, last_leg_id) {
            return Some(leg_id);
        }
    }

    if let Some(leg_id) = preferred_leg_id {
        if get_tcp_leg_by_id(legs, leg_id).is_some_and(|leg| leg.active) && leg_id != last_leg_id {
            return Some(leg_id);
        }
    }

    select_leg_for_data_with_cooldown(
        legs,
        round_robin_cursor,
        HealthProtocol::Tcp,
        payload_len,
        outstanding_bytes,
        config,
        Instant::now(),
    )
}
```

- [ ] **Step 5: Re-pin live streams when a leg closes**

In the `TcpTransportEvent::LegClosed` arm, after `mark_leg_inactive` and before recomputing inflight bytes, replace the old below-two-leg preferred-leg clearing with:

```rust
let pool_mode_after_loss = classify_tcp_pool(&legs);
if let Some(best_leg_id) = pool_mode_after_loss.pinned_leg().or_else(|| best_surviving_tcp_leg(&legs)) {
    mark_transport_streams_for_best_survivor_retry(
        &mut streams,
        best_leg_id,
        config.retransmit_after,
    );
} else {
    mark_transport_streams_for_retry(&mut streams, leg_id, config.retransmit_after);
}
```

Keep the existing `no_leg_deadline` behavior. If all legs are gone, the session waits for bond-family reconnection until the resume timeout, then fails explicitly with the existing no-active-legs error.

- [ ] **Step 6: Run retransmit tests**

Run:

```bash
cd wstunnel-main/leikwan-bond && cargo test select_retransmit_leg_uses_best_survivor_when_degraded -- --nocapture
cd wstunnel-main/leikwan-bond && cargo test mark_transport_streams_for_best_survivor_retry_repins_pending_chunks -- --nocapture
```

Expected: PASS for both commands.

- [ ] **Step 7: Commit best-survivor retransmission**

Run:

```bash
git add wstunnel-main/leikwan-bond/src/session.rs
git commit -m "feat(bond): retransmit through best surviving leg" -m "Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: Add probation and controlled re-admission for recovered legs

**Files:**
- Modify: `wstunnel-main/leikwan-bond/src/main.rs:20-130`
- Modify: `wstunnel-main/leikwan-bond/src/session.rs:44-70`
- Modify: `wstunnel-main/leikwan-bond/src/session.rs:336-370`
- Modify: `wstunnel-main/leikwan-bond/src/session.rs:1523-1587`
- Modify: `wstunnel-main/leikwan-bond/src/session.rs:3557-3678`

- [ ] **Step 1: Write failing probation tests**

Add these tests to `wstunnel-main/leikwan-bond/src/session.rs`:

```rust
#[test]
fn recovered_leg_starts_in_probation_and_is_not_used_for_bulk_striping() {
    let now = Instant::now();
    let mut legs = test_tcp_legs([
        test_leg("bond:91:tcp:20:184", 20, 184, true, Duration::from_millis(40), 0.90),
        test_leg("bond:91:tcp:21:185", 21, 185, true, Duration::from_millis(35), 0.99),
    ]);
    legs[1].admission = LegAdmission::Probation {
        until: now + Duration::from_secs(5),
        good_acks: 0,
        delivered_bytes: 0,
    };
    let mut cursor = 0usize;
    let config = test_session_config();

    let selected = select_leg_for_data_with_cooldown(
        &legs,
        &mut cursor,
        HealthProtocol::Tcp,
        131_072,
        1_048_576,
        &config,
        now,
    );

    assert_eq!(selected, Some(0));
}

#[test]
fn expired_probation_without_required_progress_stays_degraded() {
    let now = Instant::now();
    let mut legs = test_tcp_legs([
        test_leg("bond:91:tcp:20:184", 20, 184, true, Duration::from_millis(40), 0.90),
        test_leg("bond:91:tcp:21:185", 21, 185, true, Duration::from_millis(35), 0.99),
    ]);
    legs[1].admission = LegAdmission::Probation {
        until: now - Duration::from_millis(1),
        good_acks: 0,
        delivered_bytes: 0,
    };

    assert_eq!(
        classify_tcp_pool(&legs),
        TcpPoolMode::DegradedSingle {
            leg_id: 0,
            reason: PoolDegradeReason::ProbationPending,
        }
    );
}

#[test]
fn probation_leg_is_readmitted_after_time_acks_and_bytes() {
    let now = Instant::now();
    let mut leg = test_leg("bond:91:tcp:21:185", 21, 185, true, Duration::from_millis(35), 0.99);
    leg.admission = LegAdmission::Probation {
        until: now - Duration::from_millis(1),
        good_acks: 2,
        delivered_bytes: 65_536,
    };
    let config = test_session_config();

    maybe_readmit_probation_leg(&mut leg, now, &config);

    assert_eq!(leg.admission, LegAdmission::Active);
}

#[test]
fn readmission_probe_chunk_credits_only_probe_leg_from_real_pending_data() {
    let now = Instant::now();
    let mut legs = test_tcp_legs([
        test_leg("bond:91:tcp:20:184", 20, 184, true, Duration::from_millis(40), 0.90),
        test_leg("bond:91:tcp:21:185", 21, 185, true, Duration::from_millis(35), 0.99),
    ]);
    legs[1].admission = LegAdmission::Probation {
        until: now - Duration::from_millis(1),
        good_acks: 1,
        delivered_bytes: 0,
    };
    let config = test_session_config();
    let (local_write_tx, _local_write_rx) = mpsc::channel(8);
    let (close_tx, _close_rx) = watch::channel(false);
    let mut state = TcpStreamState::new(local_write_tx, close_tx, true);
    state.local_backlog.push_back(Bytes::from(vec![b'p'; 65_536]));
    let mut streams = HashMap::<u32, TcpStreamState>::new();
    streams.insert(9, state);
    let mut cursor = 0usize;

    flush_tcp_stream_backlogs(
        Uuid::nil(),
        &mut streams,
        &mut legs,
        &mut cursor,
        SessionRole::Client,
        &config,
    );

    let state = streams.get_mut(&9).expect("stream");
    let chunk = state.pending_chunks.values().next().expect("probe chunk");
    assert_eq!(chunk.last_leg_id, 1);
    assert_eq!(chunk.readmission_probe_leg_id, Some(1));
    let ack = chunk.payload.len() as u64;

    apply_ack(&mut state.pending_chunks, &mut legs, ack);
    maybe_readmit_probation_leg(&mut legs[1], now, &config);

    assert_eq!(legs[1].admission, LegAdmission::Active);
}

#[test]
fn speed_plus_does_not_resume_until_two_non_probation_legs_are_active() {
    let now = Instant::now();
    let mut legs = test_tcp_legs([
        test_leg("bond:91:tcp:20:184", 20, 184, true, Duration::from_millis(40), 0.90),
        test_leg("bond:91:tcp:21:185", 21, 185, true, Duration::from_millis(35), 0.99),
    ]);
    legs[1].admission = LegAdmission::Probation {
        until: now + Duration::from_secs(5),
        good_acks: 0,
        delivered_bytes: 0,
    };

    assert_eq!(
        classify_tcp_pool(&legs),
        TcpPoolMode::DegradedSingle {
            leg_id: 0,
            reason: PoolDegradeReason::ProbationPending,
        }
    );
}
```

- [ ] **Step 2: Run probation tests and verify they fail before implementation**

Run:

```bash
cd wstunnel-main/leikwan-bond && cargo test recovered_leg_starts_in_probation_and_is_not_used_for_bulk_striping -- --nocapture
cd wstunnel-main/leikwan-bond && cargo test expired_probation_without_required_progress_stays_degraded -- --nocapture
cd wstunnel-main/leikwan-bond && cargo test probation_leg_is_readmitted_after_time_acks_and_bytes -- --nocapture
cd wstunnel-main/leikwan-bond && cargo test readmission_probe_chunk_credits_only_probe_leg_from_real_pending_data -- --nocapture
cd wstunnel-main/leikwan-bond && cargo test speed_plus_does_not_resume_until_two_non_probation_legs_are_active -- --nocapture
```

Expected: all five commands FAIL because `LegAdmission`, probation config fields, readmission helpers, and readmission probe tracking do not exist.

- [ ] **Step 3: Add probation fields to config and CLI**

Extend `SessionConfig`:

```rust
pub struct SessionConfig {
    pub chunk_size: usize,
    pub max_window_bytes: usize,
    pub retransmit_after: Duration,
    pub join_timeout: Duration,
    pub connect_timeout: Duration,
    pub interactive_chunk_bytes: usize,
    pub latency_guard_bytes: usize,
    pub interactive_rtt_slack: Duration,
    pub warmup_trigger_bytes: usize,
    pub warmup_slice_bytes: usize,
    pub warmup_leg_delay: Duration,
    pub bulk_promote_bytes: usize,
    pub leg_cooldown: Duration,
    pub latency_first: bool,
    pub udp_primary_only: bool,
    pub leg_probation: Duration,
    pub readmit_min_good_acks: u32,
    pub readmit_min_delivered_bytes: u64,
}
```

In `main.rs`, add client flags:

```rust
        #[arg(long, default_value = "1000")]
        leg_probation_ms: u64,
        #[arg(long, default_value = "2")]
        readmit_min_good_acks: u32,
        #[arg(long, default_value = "65536")]
        readmit_min_delivered_bytes: u64,
```

Add these fields when constructing `SessionConfig`:

```rust
leg_probation: Duration::from_millis(leg_probation_ms),
readmit_min_good_acks,
readmit_min_delivered_bytes,
```

Update every test helper and call site constructing `SessionConfig`.

- [ ] **Step 4: Add `LegAdmission` and make availability respect probation**

Add near `LegState`:

```rust
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum LegAdmission {
    Active,
    Probation {
        until: Instant,
        good_acks: u32,
        delivered_bytes: u64,
    },
}

impl LegAdmission {
    fn permits_bulk(self) -> bool {
        matches!(self, Self::Active)
    }

    fn is_probation(self) -> bool {
        matches!(self, Self::Probation { .. })
    }

    fn probation_timer_elapsed(self, now: Instant) -> bool {
        matches!(self, Self::Probation { until, .. } if until <= now)
    }
}
```

Extend `LegState`:

```rust
admission: LegAdmission,
```

Initialize the first connected legs as active by adding admission to `OpenLeg` and copying it into `LegState`:

```rust
#[derive(Debug, Clone, Copy)]
struct LegAdmissionPolicy {
    probation: Duration,
}

impl LegAdmissionPolicy {
    fn recovered_leg_admission(self, now: Instant) -> LegAdmission {
        LegAdmission::Probation {
            until: now + self.probation,
            good_acks: 0,
            delivered_bytes: 0,
        }
    }
}

struct OpenLeg {
    stream: LegIo,
    transport: LegTransport,
    priority: u16,
    role: LegRole,
    label: String,
    spec_index: Option<usize>,
    endpoint_id: Option<u64>,
    node_id: Option<u64>,
    forward_id: Option<u64>,
    logical_key: Option<String>,
    stable_identity: Option<StableLegIdentity>,
    host: String,
    admission: LegAdmission,
}

impl OpenLeg {
    fn new(stream: LegIo, priority: u16, role: LegRole, label: String) -> Self {
        let transport = stream.transport();
        Self {
            stream,
            transport,
            priority,
            role,
            host: extract_host_from_label(&label),
            label,
            spec_index: None,
            endpoint_id: None,
            node_id: None,
            forward_id: None,
            logical_key: None,
            stable_identity: None,
            admission: LegAdmission::Active,
        }
    }

    fn with_admission(mut self, admission: LegAdmission) -> Self {
        self.admission = admission;
        self
    }
}
```

Change `spawn_tcp_leg_task` to copy the admission already carried by `OpenLeg`; do not read a process-global default:

```rust
fn spawn_tcp_leg_task(
    leg_id: usize,
    leg: OpenLeg,
    event_tx: mpsc::UnboundedSender<TcpTransportEvent>,
) -> LegState {
    let transport = leg.transport;
    let priority = leg.priority;
    let role = leg.role;
    let label = leg.label;
    let spec_index = leg.spec_index;
    let endpoint_id = leg.endpoint_id;
    let node_id = leg.node_id;
    let forward_id = leg.forward_id;
    let logical_key = leg.logical_key;
    let stable_identity = leg.stable_identity;
    let host = leg.host;
    let admission = leg.admission;
    let (reader, writer) = tokio::io::split(leg.stream);
    let (tx, rx) = mpsc::unbounded_channel();
    tokio::spawn(run_tcp_leg_writer(leg_id, writer, rx, event_tx.clone()));
    tokio::spawn(run_tcp_leg_reader(leg_id, reader, event_tx));
    LegState {
        id: leg_id,
        sender: tx,
        active: true,
        inflight_bytes: 0,
        transport,
        priority,
        role,
        smoothed_rtt: initial_leg_rtt(transport),
        label,
        spec_index,
        endpoint_id,
        node_id,
        forward_id,
        logical_key,
        stable_identity,
        host,
        admission,
        cooldown_until: None,
        penalty_count: 0,
        tcp_health: ProtocolLegHealth::new(),
        udp_health: ProtocolLegHealth::new(),
        recent_events: VecDeque::new(),
    }
}
```

`spawn_tcp_leg_tasks` keeps its current signature and maps initial legs directly, so initially connected legs remain `LegAdmission::Active`:

```rust
fn spawn_tcp_leg_tasks(
    legs: Vec<OpenLeg>,
    event_tx: mpsc::UnboundedSender<TcpTransportEvent>,
) -> Vec<LegState> {
    let mut states = Vec::with_capacity(legs.len());
    for (index, leg) in legs.into_iter().enumerate() {
        states.push(spawn_tcp_leg_task(index, leg, event_tx.clone()));
    }
    states
}
```

Update the older non-TCP `spawn_leg_task` path used by UDP sessions so adding `LegState::admission` does not break existing UDP code. Copy the same `OpenLeg` fields and set `admission` in the returned `LegState`:

```rust
fn spawn_leg_task(leg_id: usize, leg: OpenLeg, event_tx: mpsc::UnboundedSender<SessionEvent>) -> LegState {
    let transport = leg.transport;
    let priority = leg.priority;
    let role = leg.role;
    let label = leg.label;
    let spec_index = leg.spec_index;
    let endpoint_id = leg.endpoint_id;
    let node_id = leg.node_id;
    let forward_id = leg.forward_id;
    let logical_key = leg.logical_key;
    let stable_identity = leg.stable_identity;
    let host = leg.host;
    let admission = leg.admission;
    let (reader, writer) = tokio::io::split(leg.stream);
    let (tx, rx) = mpsc::unbounded_channel();
    tokio::spawn(run_leg_writer(leg_id, writer, rx, event_tx.clone()));
    tokio::spawn(run_leg_reader(leg_id, reader, event_tx));
    LegState {
        id: leg_id,
        sender: tx,
        active: true,
        inflight_bytes: 0,
        transport,
        priority,
        role,
        smoothed_rtt: initial_leg_rtt(transport),
        label,
        spec_index,
        endpoint_id,
        node_id,
        forward_id,
        logical_key,
        stable_identity,
        host,
        admission,
        cooldown_until: None,
        penalty_count: 0,
        tcp_health: ProtocolLegHealth::new(),
        udp_health: ProtocolLegHealth::new(),
        recent_events: VecDeque::new(),
    }
}
```

Update every manual `LegState { ... }` literal created by tests or helpers after adding the field. At minimum, add `admission: LegAdmission::Active,` to the literals in `fake_leg` and `local_eof_waits_for_buffered_data_before_sending_fin`; do the same for any additional literals found by `grep -R "LegState {" wstunnel-main/leikwan-bond/src/session.rs` during implementation.

Pass the configured probation policy only at recovered-leg boundaries. In `open_client_tcp_transport_session`, change the bootstrap call to:

```rust
let bootstrap = connect_client_tcp_transport_legs(
    leg_specs,
    session_token,
    config.connect_timeout,
    LegAdmissionPolicy {
        probation: config.leg_probation,
    },
)
.await?;
```

Change `connect_client_tcp_transport_legs` and `run_client_leg_connector` signatures to carry that policy:

```rust
async fn connect_client_tcp_transport_legs(
    mut specs: Vec<LegSpec>,
    session_token: String,
    connect_timeout: Duration,
    admission_policy: LegAdmissionPolicy,
) -> anyhow::Result<ClientTransportBootstrap> {
    // existing sort, initial connects, and bootstrap construction stay the same
}

async fn run_client_leg_connector(
    specs: Arc<Vec<LegSpec>>,
    hello: WireFrame,
    connect_timeout: Duration,
    admission_policy: LegAdmissionPolicy,
    mut active_specs_rx: watch::Receiver<HashSet<usize>>,
    mut shutdown_rx: watch::Receiver<bool>,
    add_leg_tx: mpsc::UnboundedSender<OpenLeg>,
) {
    // existing loop stays the same except the successful reconnect branch below
}
```

When `connect_client_tcp_transport_legs` spawns the connector, pass `admission_policy` before the watch receivers:

```rust
tokio::spawn(run_client_leg_connector(
    specs,
    hello,
    connect_timeout,
    admission_policy,
    active_specs_rx,
    connector_shutdown_rx,
    add_leg_tx,
));
```

In `run_client_leg_connector`, mark only reconnected specs as probation before sending them back to the transport loop:

```rust
Ok(leg) => {
    next_retry_at[spec_index] = Instant::now() + CLIENT_LEG_RETRY_BACKOFF;
    let leg = leg
        .with_spec_index(spec_index)
        .with_admission(admission_policy.recovered_leg_admission(Instant::now()));
    if add_leg_tx.send(leg).is_err() {
        return;
    }
}
```

In the `Some(new_leg) = add_leg_rx.recv()` branch of `run_tcp_transport_session`, keep client reconnected legs in their carried probation state and also mark aggregator-side late legs as probation after the late `HelloAck` succeeds:

```rust
Some(new_leg) = add_leg_rx.recv() => {
    let new_leg = if role == SessionRole::Aggregator {
        acknowledge_late_leg(session_id, new_leg)
            .await?
            .with_admission(LegAdmissionPolicy {
                probation: config.leg_probation,
            }
            .recovered_leg_admission(Instant::now()))
    } else {
        new_leg
    };
    let new_leg_id = legs.len();
    let state = spawn_tcp_leg_task(new_leg_id, new_leg, event_tx.clone());
    info!("bond tcp transport {} added leg {}", session_id, state.label);
    legs.push(state);
    refresh_active_spec_snapshot(&legs, active_specs_tx.as_ref());
    refresh_tcp_pool_mode(session_id, &legs, &mut tcp_pool_mode, &mut degraded_since);
    last_activity = Instant::now();
    no_leg_deadline = None;
}
```

- [ ] **Step 5: Update selection and classification**

Make `leg_available_for_new_data` reject ordinary new data on probation legs. Probation is state-based: an expired timer alone does not make the leg available for bulk striping; only `maybe_readmit_probation_leg` can promote it to `Active`. The `now` argument still gates the existing `cooldown_until` suppression.

```rust
fn leg_available_for_new_data(leg: &LegState, now: Instant) -> bool {
    leg.active
        && !matches!(leg.tcp_health.state, LegHealthState::Quarantined)
        && leg.cooldown_until.is_none_or(|until| until <= now)
        && leg.admission.permits_bulk()
}
```

Update `classify_tcp_pool` so probation does not resume Speed Plus, including the case where the probation timer has already elapsed but the ACK/byte thresholds have not been met:

```rust
fn classify_tcp_pool(legs: &[LegState]) -> TcpPoolMode {
    let non_probation: Vec<usize> = legs
        .iter()
        .filter(|leg| tcp_leg_is_usable(leg) && !leg.admission.is_probation())
        .map(|leg| leg.id)
        .collect();
    if non_probation.len() >= 2 {
        return TcpPoolMode::SpeedPlus;
    }
    if non_probation.len() == 1 {
        let probation_exists = legs
            .iter()
            .any(|leg| tcp_leg_is_usable(leg) && leg.admission.is_probation());
        if probation_exists {
            return TcpPoolMode::DegradedSingle {
                leg_id: non_probation[0],
                reason: PoolDegradeReason::ProbationPending,
            };
        }
        return TcpPoolMode::SingleLegOnly { leg_id: non_probation[0] };
    }
    if let Some(best) = best_surviving_tcp_leg(legs) {
        return TcpPoolMode::SingleLegOnly { leg_id: best };
    }
    TcpPoolMode::NoUsableLegs
}
```

- [ ] **Step 6: Count successful ACK progress toward readmission**

Do not count `ack - last_ack_offset` in the ACK branch of `handle_tcp_transport_frame`; that would attribute aggregate stream progress to whichever leg carried the ACK frame. Instead, count only chunks removed from `pending_chunks`, because each `PendingChunk` records the leg that most recently carried those bytes.

First extend `PendingChunk` so readmission credit can distinguish a deliberately scheduled probe from ordinary retransmit traffic:

```rust
struct PendingChunk {
    payload: Bytes,
    last_sent_at: Instant,
    last_leg_id: usize,
    transmit_count: u8,
    readmission_probe_leg_id: Option<usize>,
}
```

When existing Task 5 code repins pending chunks to the best survivor, keep `readmission_probe_leg_id` only if the chunk's most recent send still belongs to that same leg. If a re-pin or retransmit changes the most recent send leg, clear `readmission_probe_leg_id` before any later ACK can count the bytes toward readmission. Do not create readmission credit from arbitrary retransmissions.

Add a bounded helper near `best_surviving_tcp_leg` to find a probation leg whose timer has elapsed and that still needs real delivered bytes:

```rust
fn readmission_probe_candidate(legs: &[LegState], now: Instant, config: &SessionConfig) -> Option<usize> {
    legs.iter()
        .filter(|leg| tcp_leg_is_usable(leg))
        .filter(|leg| leg.cooldown_until.is_none_or(|until| until <= now))
        .filter(|leg| leg.admission.probation_timer_elapsed(now))
        .filter_map(|leg| {
            let LegAdmission::Probation {
                good_acks,
                delivered_bytes,
                ..
            } = leg.admission
            else {
                return None;
            };
            if good_acks >= config.readmit_min_good_acks
                && delivered_bytes >= config.readmit_min_delivered_bytes
            {
                return None;
            }
            Some(leg.id)
        })
        .min_by(|left, right| {
            let left = get_tcp_leg_by_id(legs, *left).expect("left candidate exists");
            let right = get_tcp_leg_by_id(legs, *right).expect("right candidate exists");
            compare_best_tcp_leg(left, right)
        })
}
```

Add a small admission probe scheduler. It sends at most one fresh backlog chunk through a probation leg whose timer has elapsed. This is the only path that allows a probation leg to earn ACK/byte readmission credit; ordinary sticky, warmup, bulk, and retransmit selection still avoid probation legs while any active survivor can carry the flow.

```rust
fn try_schedule_readmission_probe(
    stream_id: u32,
    state: &mut TcpStreamState,
    legs: &mut [LegState],
    role: SessionRole,
    now: Instant,
    config: &SessionConfig,
) -> bool {
    let pending = pending_bytes(&state.pending_chunks);
    if pending >= config.max_window_bytes {
        return false;
    }
    let remaining_window = config.max_window_bytes - pending;
    if state
        .pending_chunks
        .values()
        .any(|chunk| chunk.readmission_probe_leg_id.is_some())
    {
        return false;
    }
    let Some(candidate_leg_id) = readmission_probe_candidate(legs, now, config) else {
        return false;
    };
    let Some(candidate_index) = find_tcp_leg_index_by_id(legs, candidate_leg_id) else {
        return false;
    };
    let Some(payload) = state.local_backlog.pop_front() else {
        return false;
    };
    let desired_probe_len = config
        .readmit_min_delivered_bytes
        .max(config.chunk_size as u64)
        .min(payload.len() as u64) as usize;
    let probe_len = desired_probe_len.min(remaining_window);
    if probe_len == 0 {
        state.local_backlog.push_front(payload);
        return false;
    }
    let probe_payload = payload.slice(..probe_len);
    let remainder = if probe_len < payload.len() {
        Some(payload.slice(probe_len..))
    } else {
        None
    };
    let seq = state.outbound_next_offset;
    if !send_to_leg(legs, candidate_index, role.outbound_data(stream_id, seq, probe_payload.clone())) {
        state.local_backlog.push_front(payload);
        return false;
    }
    if let Some(remainder) = remainder {
        state.local_backlog.push_front(remainder);
    }
    state.pending_chunks.insert(
        seq,
        PendingChunk {
            payload: probe_payload.clone(),
            last_sent_at: now,
            last_leg_id: candidate_leg_id,
            transmit_count: 1,
            readmission_probe_leg_id: Some(candidate_leg_id),
        },
    );
    state.outbound_next_offset += probe_payload.len() as u64;
    true
}
```

The important invariant is that probe bytes come from the same application TCP stream and are inserted into the same `pending_chunks` map as ordinary data, so ACK removal proves delivery through the probation leg.

Call `try_schedule_readmission_probe` from `flush_tcp_stream_backlogs` immediately before the ordinary `while pending_bytes(&state.pending_chunks) < config.max_window_bytes` loop for each opened stream:

```rust
let now = Instant::now();
let _ = try_schedule_readmission_probe(stream_id, state, legs, role, now, config);

while pending_bytes(&state.pending_chunks) < config.max_window_bytes {
    // existing scheduling loop follows
}
```

When inserting ordinary `PendingChunk` values in `flush_tcp_stream_backlogs`, `flush_local_backlog`, and test helpers, set `readmission_probe_leg_id: None`. When a selected value is a vector index, store `last_leg_id: legs[leg_index].id`; when a helper already returns a logical leg ID, store that ID. When inserting readmission probe chunks, set it to the probation leg ID as shown above. The probe scheduler caps `probe_len` to the remaining `max_window_bytes`; it must never overfill the send window to satisfy `readmit_min_delivered_bytes` in one write. On probe send failure, push the original unsent `payload` back as one buffer before returning; do not push a sliced probe after a sliced remainder in a way that can reorder the application byte stream.

Add this helper near `credit_tcp_delivery`:

```rust
fn record_leg_ack_progress(
    legs: &mut [LegState],
    leg_id: usize,
    delivered_bytes: usize,
    readmission_probe_leg_id: Option<usize>,
) {
    if readmission_probe_leg_id != Some(leg_id) {
        return;
    }
    let Some(leg) = get_tcp_leg_mut_by_id(legs, leg_id) else {
        return;
    };
    let LegAdmission::Probation {
        until,
        mut good_acks,
        delivered_bytes: mut current_bytes,
    } = leg.admission
    else {
        return;
    };
    good_acks = good_acks.saturating_add(1);
    current_bytes = current_bytes.saturating_add(delivered_bytes as u64);
    leg.admission = LegAdmission::Probation {
        until,
        good_acks,
        delivered_bytes: current_bytes,
    };
}
```

Update `apply_ack` so probation credit is anchored to the completed chunk's `last_leg_id` at the same point existing delivery credit is applied:

```rust
fn apply_ack(pending: &mut BTreeMap<u64, PendingChunk>, legs: &mut [LegState], ack: u64) {
    let now = Instant::now();
    let completed: Vec<u64> = pending
        .iter()
        .filter_map(|(seq, chunk)| {
            if *seq + chunk.payload.len() as u64 <= ack {
                Some(*seq)
            } else {
                None
            }
        })
        .collect();

    for seq in completed {
        if let Some(chunk) = pending.remove(&seq) {
            if chunk.transmit_count <= 1 {
                update_leg_rtt(legs, chunk.last_leg_id, now.duration_since(chunk.last_sent_at));
            }
            credit_tcp_delivery(legs, chunk.last_leg_id, chunk.payload.len());
            record_leg_ack_progress(
                legs,
                chunk.last_leg_id,
                chunk.payload.len(),
                chunk.readmission_probe_leg_id,
            );
        }
    }
}
```

Keep the existing ACK branch in `handle_tcp_transport_frame` limited to stream-level progress:

```rust
apply_ack(&mut state.pending_chunks, legs, ack);
if ack > state.last_ack_offset {
    state.last_ack_offset = ack;
    state.last_ack_progress_at = Instant::now();
}
```

In `retransmit_transport_pending`, treat `select_retransmit_leg` as returning a logical leg ID, resolve that ID to the vector index used by `send_to_leg`, and clear stale probe identity immediately after a successful retransmit unless the retransmitted bytes still went through the same probation leg recorded on the chunk:

```rust
let Some(selected_leg_id) = select_retransmit_leg(
    legs,
    round_robin_cursor,
    if bulk_mode { None } else { *preferred_leg_id },
    chunk.last_leg_id,
    chunk.transmit_count,
    chunk.payload.len(),
    outstanding_bytes,
    config,
) else {
    break;
};
let Some(selected_leg_index) = find_tcp_leg_index_by_id(legs, selected_leg_id) else {
    break;
};
if send_to_leg(legs, selected_leg_index, role.outbound_data(stream_id, seq, chunk.payload.clone())) {
    chunk.last_sent_at = now;
    if chunk.readmission_probe_leg_id != Some(selected_leg_id) {
        chunk.readmission_probe_leg_id = None;
    }
    chunk.last_leg_id = selected_leg_id;
    chunk.transmit_count = chunk.transmit_count.saturating_add(1);
    if !bulk_mode {
        *preferred_leg_id = Some(selected_leg_id);
    }
}
```

This keeps readmission credit tied to bytes whose most recent send stayed on the planned probation leg. `send_to_leg` receives the vector index, but `last_leg_id`, `preferred_leg_id`, and `readmission_probe_leg_id` comparisons use the logical `LegState.id`. A probe chunk retransmitted through an active best survivor becomes ordinary pending data and cannot readmit the old probation leg when it is later ACKed.

Add the readmission helper:

```rust
fn maybe_readmit_probation_leg(leg: &mut LegState, now: Instant, config: &SessionConfig) {
    let LegAdmission::Probation {
        until,
        good_acks,
        delivered_bytes,
    } = leg.admission
    else {
        return;
    };
    if until <= now
        && good_acks >= config.readmit_min_good_acks
        && delivered_bytes >= config.readmit_min_delivered_bytes
    {
        leg.admission = LegAdmission::Active;
        leg.cooldown_until = None;
        leg.penalty_count = 0;
    }
}
```

On each retransmit tick in `run_tcp_transport_session`, call `maybe_readmit_probation_leg` for every leg before recomputing `tcp_pool_mode`:

```rust
for leg in &mut legs {
    maybe_readmit_probation_leg(leg, Instant::now(), &config);
}
refresh_tcp_pool_mode(session_id, &legs, &mut tcp_pool_mode, &mut degraded_since);
```

- [ ] **Step 7: Run probation tests**

Run:

```bash
cd wstunnel-main/leikwan-bond && cargo test probation -- --nocapture
cd wstunnel-main/leikwan-bond && cargo test expired_probation_without_required_progress_stays_degraded -- --nocapture
cd wstunnel-main/leikwan-bond && cargo test readmission_probe_chunk_credits_only_probe_leg_from_real_pending_data -- --nocapture
cd wstunnel-main/leikwan-bond && cargo test speed_plus_does_not_resume_until_two_non_probation_legs_are_active -- --nocapture
```

Expected: PASS for all four commands.

- [ ] **Step 8: Commit probation and re-admission**

Run:

```bash
git add wstunnel-main/leikwan-bond/src/main.rs wstunnel-main/leikwan-bond/src/session.rs
git commit -m "feat(bond): add leg probation before speed plus readmission" -m "Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Write atomic-replace TCP leg health snapshots with pool-mode evidence

**Files:**
- Modify: `wstunnel-main/leikwan-bond/Cargo.toml`
- Modify: `wstunnel-main/leikwan-bond/Cargo.lock`
- Modify: `wstunnel-main/leikwan-bond/src/main.rs:20-160`
- Modify: `wstunnel-main/leikwan-bond/src/session.rs:3699-3867`

- [ ] **Step 1: Add snapshot-shape and atomic-write tests**

`write_atomic_text` uses a named temporary file in the destination directory, fsyncs the file, and persists it over the previous snapshot, so `tempfile` belongs in the crate's runtime `[dependencies]` section. In `wstunnel-main/leikwan-bond/Cargo.toml`, add this exact line inside the existing `[dependencies]` section:

```toml
tempfile = "3"
```

Then run the first failing snapshot test command in Step 2 once so Cargo resolves `tempfile` and updates `wstunnel-main/leikwan-bond/Cargo.lock`. Stage both `Cargo.toml` and `Cargo.lock` in this task; do not hide dependency-lock churn in a later verification commit.

Add tests to `wstunnel-main/leikwan-bond/src/session.rs`:

```rust
#[test]
fn local_snapshot_serialization_includes_logical_key_and_pool_mode() {
    let mut legs = test_tcp_legs([
        test_leg("bond:91:tcp:20:184", 20, 184, true, Duration::from_millis(40), 0.90),
        test_leg("bond:91:tcp:21:185", 21, 185, false, Duration::from_millis(35), 0.99),
    ]);
    legs[0].forward_id = Some(91);
    let pool_mode = TcpPoolMode::DegradedSingle {
        leg_id: 0,
        reason: PoolDegradeReason::OnlyOneUsableLeg,
    };

    let json = build_tcp_leg_health_snapshot_json(
        "client",
        7001,
        91,
        Some("550e8400-e29b-41d4-a716-446655440000"),
        &pool_mode,
        Some(0),
        &legs,
    )
    .expect("snapshot json");
    let value: serde_json::Value = serde_json::from_str(&json).expect("valid json");

    assert_eq!(value["schema_version"], 2);
    assert_eq!(value["observer_type"], "client");
    assert_eq!(value["forward_id"], 91);
    assert_eq!(value["pool_mode"], "degraded_single_leg");
    assert_eq!(value["best_leg_key"], "bond:91:tcp:20:184");
    assert_eq!(value["legs"][0]["logical_key"], "bond:91:tcp:20:184");
    assert_eq!(value["legs"][0]["node_id"], 20);
    assert_eq!(value["legs"][0]["endpoint_id"], 184);
}

#[test]
fn write_atomic_text_replaces_snapshot_without_leaving_tmp_file() {
    let dir = tempfile::tempdir().expect("tempdir");
    let path = dir.path().join("bond-leg-health.json");

    write_atomic_text(&path, "{\"version\":1}").expect("first write");
    write_atomic_text(&path, "{\"version\":2}").expect("second write");

    let content = std::fs::read_to_string(&path).expect("snapshot content");
    assert_eq!(content, "{\"version\":2}");
    let leftovers = std::fs::read_dir(dir.path())
        .expect("read tempdir")
        .filter_map(Result::ok)
        .filter(|entry| entry.file_name().to_string_lossy().contains(".tmp"))
        .count();
    assert_eq!(leftovers, 0);
}
```

- [ ] **Step 2: Run snapshot tests and verify they fail before implementation**

Run:

```bash
cd wstunnel-main/leikwan-bond && cargo test local_snapshot_serialization_includes_logical_key_and_pool_mode -- --nocapture
cd wstunnel-main/leikwan-bond && cargo test write_atomic_text_replaces_snapshot_without_leaving_tmp_file -- --nocapture
```

Expected: both commands FAIL because the snapshot function signature and atomic writer do not exist.

- [ ] **Step 3: Extend TCP snapshot view structs without breaking UDP callers**

Do not replace the current `build_leg_health_snapshot_json` signature in place, because `run_bonded_udp_client` and `run_bonded_udp_server` still call `maybe_write_leg_health_snapshot` for UDP health snapshots. Keep the existing `SnapshotPayloadView`, `SnapshotLegView`, `build_leg_health_snapshot_json`, and `maybe_write_leg_health_snapshot` as the generic UDP-compatible schema.

Add TCP-specific v2 view structs beside the existing structs while keeping current TCP/UDP health fields:

```rust
#[derive(Serialize)]
struct TcpSnapshotPayloadView {
    schema_version: u8,
    observer_type: String,
    observer_id: u64,
    forward_id: u64,
    session_id: Option<String>,
    updated_at: String,
    pool_mode: String,
    best_leg_key: Option<String>,
    active_leg_count: usize,
    usable_leg_count: usize,
    legs: Vec<TcpSnapshotLegView>,
}

#[derive(Serialize)]
struct TcpSnapshotLegView {
    logical_key: String,
    forward_id: u64,
    endpoint_id: u64,
    node_id: u64,
    host: String,
    role: String,
    priority: u16,
    active: bool,
    admission: String,
    tcp: SnapshotProtocolView,
    udp: SnapshotProtocolView,
    recent_events: Vec<SnapshotEventView>,
}
```

Add a bounded admission renderer:

```rust
fn admission_view(admission: LegAdmission, now: Instant) -> &'static str {
    match admission {
        LegAdmission::Active => "active",
        LegAdmission::Probation { until, .. } if until > now => "probation",
        LegAdmission::Probation { .. } => "probation_ready",
    }
}
```

- [ ] **Step 4: Include pool mode and logical keys in TCP snapshots**

Add a TCP-only v2 serializer instead of changing the generic serializer used by UDP callers:

```rust
fn build_tcp_leg_health_snapshot_json(
    observer_type: &str,
    observer_id: u64,
    forward_id: u64,
    session_id: Option<&str>,
    pool_mode: &TcpPoolMode,
    best_leg_id: Option<usize>,
    legs: &[LegState],
) -> anyhow::Result<String> {
    let now = Instant::now();
    let best_leg_key = best_leg_id
        .and_then(|id| get_tcp_leg_by_id(legs, id))
        .map(LegState::stable_label);
    let payload = TcpSnapshotPayloadView {
        schema_version: 2,
        observer_type: observer_type.to_string(),
        observer_id,
        forward_id,
        session_id: session_id.map(str::to_string),
        updated_at: iso8601(SystemTime::now()),
        pool_mode: pool_mode.as_str().to_string(),
        best_leg_key,
        active_leg_count: legs.iter().filter(|leg| leg.active).count(),
        usable_leg_count: legs.iter().filter(|leg| tcp_leg_is_usable(leg)).count(),
        legs: legs
            .iter()
            .map(|leg| TcpSnapshotLegView {
                logical_key: leg.stable_label(),
                forward_id: leg.forward_id.unwrap_or(forward_id),
                endpoint_id: leg.endpoint_id.unwrap_or(0),
                node_id: leg.node_id.unwrap_or(0),
                host: leg.host.clone(),
                role: match leg.role {
                    LegRole::Primary => "primary".to_string(),
                    LegRole::Helper => "helper".to_string(),
                },
                priority: leg.priority,
                active: leg.active,
                admission: admission_view(leg.admission, now).to_string(),
                tcp: snapshot_protocol_view(&leg.tcp_health),
                udp: snapshot_protocol_view(&leg.udp_health),
                recent_events: leg
                    .recent_events
                    .iter()
                    .take(32)
                    .map(|event| SnapshotEventView {
                        protocol: event.protocol.as_str().to_string(),
                        from: event.from,
                        to: event.to,
                        reason: event.reason.clone(),
                        observed_at: iso8601(event.observed_at),
                    })
                    .collect(),
            })
            .collect(),
    };
    serde_json::to_string_pretty(&payload).map_err(|err| anyhow!("failed to serialize tcp leg snapshot: {err}"))
}
```

- [ ] **Step 5: Add crash-resistant atomic replacement and a TCP-only snapshot writer**

Add this atomic replacement helper near the snapshot helpers. The helper writes the full payload to a unique named temporary file in the destination directory, syncs it, persists it over the final path, removes any leftover temp path on error, and best-effort syncs the parent directory on Unix. Do not use `NamedTempFile::persist` across filesystems; the temp file must be created in the same parent directory as the final snapshot path.

Before adding `write_atomic_text`, update the imports at the top of `session.rs`:

```rust
use std::path::{Path, PathBuf};
```

The file already imports `fs` and `debug`, so no additional `std::fs` or logging import is needed.

```rust
fn write_atomic_text(path: &Path, content: &str) -> anyhow::Result<()> {
    let parent = path.parent().unwrap_or_else(|| Path::new("."));
    fs::create_dir_all(parent)
        .with_context(|| format!("failed to create snapshot dir {}", parent.display()))?;

    let file_name = path
        .file_name()
        .and_then(|name| name.to_str())
        .ok_or_else(|| anyhow!("snapshot path has no file name: {}", path.display()))?;
    let mut temp = tempfile::Builder::new()
        .prefix(&format!(".{file_name}."))
        .suffix(".tmp")
        .tempfile_in(parent)
        .with_context(|| format!("failed to create temp snapshot in {}", parent.display()))?;

    use std::io::Write as _;
    temp.write_all(content.as_bytes())
        .with_context(|| format!("failed to write temp snapshot {}", temp.path().display()))?;
    temp.as_file_mut()
        .sync_all()
        .with_context(|| format!("failed to sync temp snapshot {}", temp.path().display()))?;

    let temp_path = temp.path().to_path_buf();
    match temp.persist(path) {
        Ok(_) => {}
        Err(err) => {
            let failed_path = err.file.path().to_path_buf();
            let cause = err.error;
            let _ = fs::remove_file(&failed_path);
            return Err(anyhow!("failed to replace snapshot {}: {}", path.display(), cause));
        }
    }

    #[cfg(unix)]
    if let Ok(dir) = fs::File::open(parent) {
        let _ = dir.sync_all();
    }

    debug!("atomically replaced bond leg snapshot via {}", temp_path.display());
    Ok(())
}
```

Keep the current generic `maybe_write_leg_health_snapshot` name and signature for UDP callers, but replace its `fs::write` branch with the atomic helper:

```rust
if let Err(err) = write_atomic_text(&context.path, &snapshot) {
    warn!("failed to write bond leg health snapshot {}: {:?}", context.path.display(), err);
    return;
}
```

Add a TCP-only writer wrapper for `run_tcp_transport_session` instead of changing the generic UDP-compatible function signature:

```rust
fn maybe_write_tcp_leg_health_snapshot(
    context: Option<&SnapshotContext>,
    last_written_at: &mut Option<Instant>,
    pool_mode: &TcpPoolMode,
    best_leg_id: Option<usize>,
    session_id: Uuid,
    legs: &[LegState],
) {
    let Some(context) = context else {
        return;
    };
    if last_written_at
        .is_some_and(|instant| instant.elapsed() < SNAPSHOT_WRITE_INTERVAL)
    {
        return;
    }
    let snapshot = match build_tcp_leg_health_snapshot_json(
        context.observer_type.as_str(),
        context.observer_id,
        context.forward_id,
        Some(&session_id.to_string()),
        pool_mode,
        best_leg_id,
        legs,
    ) {
        Ok(payload) => payload,
        Err(err) => {
            warn!("failed to build TCP bond leg health snapshot: {:?}", err);
            return;
        }
    };
    if let Err(err) = write_atomic_text(&context.path, &snapshot) {
        warn!("failed to write TCP bond leg health snapshot {}: {:?}", context.path.display(), err);
        return;
    }
    *last_written_at = Some(Instant::now());
}
```

In `run_tcp_transport_session`, initialize the snapshot context with the explicit config override:

```rust
let snapshot = snapshot_context(role, claims.as_ref(), config.snapshot.as_ref());
let mut last_snapshot_write = None::<Instant>;
```

Then replace only the TCP caller with the current local variable names from `run_tcp_transport_session`:

```rust
maybe_write_tcp_leg_health_snapshot(
    snapshot.as_ref(),
    &mut last_snapshot_write,
    &tcp_pool_mode,
    best_surviving_tcp_leg(&legs),
    session_id,
    &legs,
);
```

Leave the `run_bonded_udp_client` and `run_bonded_udp_server` calls on the existing generic `maybe_write_leg_health_snapshot(...)` path. Those UDP snapshots gain atomic replacement, but they do not gain TCP v2 pool-mode fields.

- [ ] **Step 6: Preserve snapshot routing and add explicit CLI overrides**

The current runtime already writes snapshots from `LEIKWAN_BOND_*` variables and the existing client/sidecar default paths. Keep that behavior for compatibility. Add explicit settings as an override path only; later Linux supervisor and sidecar-agent work can opt into those flags without breaking existing unmanaged invocations.

In `wstunnel-main/leikwan-bond/src/session.rs`, add `SnapshotSettings` near the public config types:

```rust
#[derive(Debug, Clone)]
pub struct SnapshotSettings {
    pub path: PathBuf,
    pub observer_type: String,
    pub observer_id: u64,
    pub forward_id: u64,
}
```

Extend the existing public `SessionConfig` again; do not paste a second `SessionConfig` definition if Task 6 already added the probation fields:

```rust
#[derive(Debug, Clone)]
pub struct SessionConfig {
    pub chunk_size: usize,
    pub max_window_bytes: usize,
    pub retransmit_after: Duration,
    pub join_timeout: Duration,
    pub connect_timeout: Duration,
    pub interactive_chunk_bytes: usize,
    pub latency_guard_bytes: usize,
    pub interactive_rtt_slack: Duration,
    pub warmup_trigger_bytes: usize,
    pub warmup_slice_bytes: usize,
    pub warmup_leg_delay: Duration,
    pub bulk_promote_bytes: usize,
    pub leg_cooldown: Duration,
    pub latency_first: bool,
    pub udp_primary_only: bool,
    pub leg_probation: Duration,
    pub readmit_min_good_acks: u32,
    pub readmit_min_delivered_bytes: u64,
    pub snapshot: Option<SnapshotSettings>,
}
```

Change `SnapshotContext` so `observer_type` is owned:

```rust
struct SnapshotContext {
    observer_type: String,
    observer_id: u64,
    forward_id: u64,
    path: PathBuf,
}
```

Replace `snapshot_context` with this compatibility-preserving version:

```rust
fn snapshot_context(
    role: SessionRole,
    claims: Option<&SessionClaims>,
    settings: Option<&SnapshotSettings>,
) -> Option<SnapshotContext> {
    if let Some(settings) = settings {
        let observer_type = if settings.observer_type.trim().is_empty() {
            match role {
                SessionRole::Client => "client".to_string(),
                SessionRole::Aggregator => "sidecar".to_string(),
            }
        } else {
            settings.observer_type.trim().to_string()
        };
        let forward_id = if settings.forward_id > 0 {
            settings.forward_id
        } else {
            claims.map(|item| item.forward_id).unwrap_or(0)
        };
        return Some(SnapshotContext {
            observer_type,
            observer_id: settings.observer_id,
            forward_id,
            path: settings.path.clone(),
        });
    }

    let observer_type = match role {
        SessionRole::Client => "client".to_string(),
        SessionRole::Aggregator => "sidecar".to_string(),
    };
    let observer_id = std::env::var("LEIKWAN_BOND_OBSERVER_ID")
        .ok()
        .and_then(|value| value.parse::<u64>().ok())
        .unwrap_or(0);
    let forward_id = std::env::var("LEIKWAN_BOND_FORWARD_ID")
        .ok()
        .and_then(|value| value.parse::<u64>().ok())
        .unwrap_or_else(|| claims.map(|item| item.forward_id).unwrap_or(0));

    let path = if let Ok(value) = std::env::var("LEIKWAN_BOND_SNAPSHOT_PATH") {
        let trimmed = value.trim();
        if trimmed.is_empty() {
            None
        } else {
            Some(PathBuf::from(trimmed))
        }
    } else if role == SessionRole::Client && forward_id > 0 {
        Some(PathBuf::from(format!(
            "/run/leikwan/bond-leg-health-forward-{}.json",
            forward_id
        )))
    } else if role == SessionRole::Client {
        None
    } else {
        let state_dir = std::env::var("LEIKWAN_BOND_STATE_DIR")
            .ok()
            .filter(|value| !value.trim().is_empty())
            .unwrap_or_else(|| String::from("/var/lib/leikwan-sidecar"));
        Some(PathBuf::from(state_dir).join("bond-leg-health.json"))
    }?;

    Some(SnapshotContext {
        observer_type,
        observer_id,
        forward_id,
        path,
    })
}
```

Update all `snapshot_context(...)` callers to pass `config.snapshot.as_ref()` and call snapshot serialization with `context.observer_type.as_str()`. Leave `snapshot: None` in server defaults and any existing call sites that should keep environment/default snapshot routing. At minimum, update `run_tcp_transport_session`, `run_client_udp_listener`, and `start_pending_udp_session`.

In `wstunnel-main/leikwan-bond/src/main.rs`, add the client flags:

```rust
        #[arg(long)]
        snapshot_path: Option<String>,
        #[arg(long, default_value = "client")]
        observer_type: String,
        #[arg(long, default_value = "0")]
        observer_id: u64,
```

When constructing the client `SessionConfig`, set:

```rust
snapshot: snapshot_path
    .as_ref()
    .map(|path| session::SnapshotSettings {
        path: std::path::PathBuf::from(path),
        observer_type: observer_type.clone(),
        observer_id,
        forward_id: forward_id.unwrap_or(0),
    }),
```

With this shape, an explicit `--snapshot-path` wins; when it is omitted, the existing `LEIKWAN_BOND_SNAPSHOT_PATH`, `LEIKWAN_BOND_STATE_DIR`, `/run/leikwan/...`, and `/var/lib/leikwan-sidecar/...` behavior remains unchanged except that writes are atomic and the JSON schema is richer.

- [ ] **Step 7: Run snapshot tests**

Run:

```bash
cd wstunnel-main/leikwan-bond && cargo test local_snapshot_serialization_includes_logical_key_and_pool_mode -- --nocapture
cd wstunnel-main/leikwan-bond && cargo test write_atomic_text_replaces_snapshot_without_leaving_tmp_file -- --nocapture
```

Expected: PASS for both commands.

- [ ] **Step 8: Commit atomic snapshots**

Run:

```bash
git add wstunnel-main/leikwan-bond/Cargo.toml wstunnel-main/leikwan-bond/Cargo.lock wstunnel-main/leikwan-bond/src/main.rs wstunnel-main/leikwan-bond/src/session.rs
git commit -m "feat(bond): write atomic leg health snapshots" -m "Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: Add local TCP integration coverage for same-stream degradation

**Files:**
- Create: `wstunnel-main/leikwan-bond/tests/tcp_speed_plus_degrade.rs`
- Modify: `wstunnel-main/leikwan-bond/src/session.rs:1589-2050`

- [ ] **Step 1: Create integration-test helpers for local target, signed tokens, process spawning, and droppable legs**

Create `wstunnel-main/leikwan-bond/tests/tcp_speed_plus_degrade.rs` with this complete harness:

```rust
use std::io::{ErrorKind, Read, Write};
use std::net::{Shutdown, SocketAddr, TcpListener, TcpStream};
use std::process::{Child, Command, Stdio};
use std::sync::atomic::{AtomicBool, AtomicUsize, Ordering};
use std::sync::{Arc, Mutex};
use std::thread::{self, JoinHandle};
use std::time::{Duration, Instant, SystemTime, UNIX_EPOCH};

use base64::{engine::general_purpose::URL_SAFE_NO_PAD, Engine as _};
use hmac::{Hmac, Mac};
use serde_json::json;
use sha2::Sha256;

type HmacSha256 = Hmac<Sha256>;

const TOKEN_SECRET: &str = "integration-secret";

fn free_loopback_addr() -> String {
    let listener = TcpListener::bind("127.0.0.1:0").expect("reserve loopback port");
    listener.local_addr().expect("reserved addr").to_string()
}

fn wait_for_port(addr: &str) {
    let deadline = Instant::now() + Duration::from_secs(5);
    while Instant::now() < deadline {
        if TcpStream::connect(addr).is_ok() {
            return;
        }
        thread::sleep(Duration::from_millis(25));
    }
    panic!("port did not open: {addr}");
}

fn connect_with_retry(addr: &str) -> TcpStream {
    let deadline = Instant::now() + Duration::from_secs(5);
    loop {
        match TcpStream::connect(addr) {
            Ok(stream) => return stream,
            Err(err) if Instant::now() < deadline => {
                let _ = err;
                thread::sleep(Duration::from_millis(25));
            }
            Err(err) => panic!("failed to connect to {addr}: {err}"),
        }
    }
}

fn wait_for_proxy_accepts(proxy: &DroppableLegProxy) {
    let deadline = Instant::now() + Duration::from_secs(5);
    while Instant::now() < deadline {
        if proxy.accepted_count.load(Ordering::SeqCst) >= 1 {
            return;
        }
        thread::sleep(Duration::from_millis(25));
    }
    panic!("proxy {} did not accept a bond leg", proxy.listen);
}

fn spawn_target() -> (String, Arc<Mutex<Vec<Vec<u8>>>>) {
    let listener = TcpListener::bind("127.0.0.1:0").expect("bind target");
    let addr = listener.local_addr().expect("target addr").to_string();
    let accepted = Arc::new(Mutex::new(Vec::<Vec<u8>>::new()));
    let accepted_thread = accepted.clone();
    thread::spawn(move || {
        let (mut stream, _) = listener.accept().expect("target accept");
        let mut buf = Vec::new();
        stream.read_to_end(&mut buf).expect("target read");
        accepted_thread.lock().expect("target lock").push(buf);
    });
    (addr, accepted)
}

fn wait_for_single_target_payload(
    accepted: &Arc<Mutex<Vec<Vec<u8>>>>,
    expected_len: usize,
    timeout: Duration,
) -> Vec<u8> {
    let deadline = Instant::now() + timeout;
    loop {
        {
            let guard = accepted.lock().expect("accepted lock");
            assert!(guard.len() <= 1, "target must see at most one TCP connection");
            if guard.len() == 1 && guard[0].len() == expected_len {
                return guard[0].clone();
            }
        }
        assert!(Instant::now() < deadline, "target did not receive one complete payload");
        thread::sleep(Duration::from_millis(25));
    }
}

fn now_epoch_seconds() -> u64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .expect("system clock after epoch")
        .as_secs()
}

fn make_session_token(target_addr: &str, forward_id: u64) -> String {
    let target: SocketAddr = target_addr.parse().expect("target socket addr");
    let now = now_epoch_seconds();
    let payload = json!({
        "target_host": target.ip().to_string(),
        "target_port": target.port(),
        "target_protocol": "tcp",
        "service_id": 7001,
        "forward_id": forward_id,
        "issued_at": now,
        "expires_at": now + 300,
    });
    let payload_bytes = serde_json::to_vec(&payload).expect("token payload json");
    let mut mac = HmacSha256::new_from_slice(TOKEN_SECRET.as_bytes()).expect("hmac key");
    mac.update(&payload_bytes);
    let signature = mac.finalize().into_bytes();
    format!(
        "{}.{}",
        URL_SAFE_NO_PAD.encode(payload_bytes),
        URL_SAFE_NO_PAD.encode(signature)
    )
}

fn spawn_bond_server(listen: &str) -> Child {
    Command::new(env!("CARGO_BIN_EXE_leikwan-bond"))
        .args(["server", "--listen", listen, "--token-secret", TOKEN_SECRET])
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .spawn()
        .expect("spawn bond server")
}

fn collect_stderr(child: &mut Child) -> Arc<Mutex<String>> {
    let mut stderr = child.stderr.take().expect("child stderr is piped");
    let collected = Arc::new(Mutex::new(String::new()));
    let thread_collected = collected.clone();
    thread::spawn(move || {
        let mut buf = [0u8; 4096];
        loop {
            match stderr.read(&mut buf) {
                Ok(0) => break,
                Ok(n) => thread_collected
                    .lock()
                    .expect("stderr lock")
                    .push_str(&String::from_utf8_lossy(&buf[..n])),
                Err(_) => break,
            }
        }
    });
    collected
}

fn wait_for_stderr_contains(stderr: &Arc<Mutex<String>>, needle: &str, timeout: Duration) -> bool {
    let deadline = Instant::now() + timeout;
    while Instant::now() < deadline {
        if stderr.lock().expect("stderr lock").contains(needle) {
            return true;
        }
        thread::sleep(Duration::from_millis(50));
    }
    false
}

fn spawn_bond_client(
    listen: &str,
    legs: &[String],
    target_addr: &str,
    forward_id: u64,
) -> (Child, Arc<Mutex<String>>) {
    let token = make_session_token(target_addr, forward_id);
    let legs_arg = legs.join(",");
    let forward_id_arg = forward_id.to_string();
    let mut child = Command::new(env!("CARGO_BIN_EXE_leikwan-bond"))
        .arg("client")
        .arg("--protocol")
        .arg("tcp")
        .arg("--listen")
        .arg(listen)
        .arg("--legs")
        .arg(legs_arg)
        .arg("--session-token")
        .arg(token)
        .arg("--forward-id")
        .arg(forward_id_arg)
        .arg("--require-stable-leg-identity")
        .arg("--min-usable-legs")
        .arg("1")
        .arg("--max-usable-legs")
        .arg("8")
        .arg("--retransmit-ms")
        .arg("50")
        .arg("--join-timeout-ms")
        .arg("100")
        .arg("--connect-timeout-ms")
        .arg("500")
        .stdout(Stdio::null())
        .stderr(Stdio::piped())
        .spawn()
        .expect("spawn bond client");
    let stderr = collect_stderr(&mut child);
    (child, stderr)
}

struct DroppableLegProxy {
    listen: String,
    accepted_count: Arc<AtomicUsize>,
    client_to_server_bytes: Arc<AtomicUsize>,
    stop: Arc<AtomicBool>,
    accept_thread: Option<JoinHandle<()>>,
}

impl DroppableLegProxy {
    fn start(upstream: String, drop_client_to_server_after_bytes: Option<usize>) -> Self {
        let listener = TcpListener::bind("127.0.0.1:0").expect("bind leg proxy");
        listener
            .set_nonblocking(true)
            .expect("set proxy listener nonblocking");
        let listen = listener.local_addr().expect("proxy addr").to_string();
        let accepted_count = Arc::new(AtomicUsize::new(0));
        let client_to_server_bytes = Arc::new(AtomicUsize::new(0));
        let stop = Arc::new(AtomicBool::new(false));
        let accepted_thread = accepted_count.clone();
        let bytes_thread = client_to_server_bytes.clone();
        let stop_thread = stop.clone();
        let accept_thread = thread::spawn(move || {
            while !stop_thread.load(Ordering::SeqCst) {
                match listener.accept() {
                    Ok((client, _)) => {
                        accepted_thread.fetch_add(1, Ordering::SeqCst);
                        let upstream = upstream.clone();
                        let bytes = bytes_thread.clone();
                        thread::spawn(move || {
                            bridge_with_optional_drop(
                                client,
                                upstream,
                                drop_client_to_server_after_bytes,
                                bytes,
                            );
                        });
                    }
                    Err(err) if err.kind() == ErrorKind::WouldBlock => {
                        thread::sleep(Duration::from_millis(10));
                    }
                    Err(_) => break,
                }
            }
        });
        Self {
            listen,
            accepted_count,
            client_to_server_bytes,
            stop,
            accept_thread: Some(accept_thread),
        }
    }
}

impl Drop for DroppableLegProxy {
    fn drop(&mut self) {
        self.stop.store(true, Ordering::SeqCst);
        let _ = TcpStream::connect(&self.listen);
        if let Some(handle) = self.accept_thread.take() {
            let _ = handle.join();
        }
    }
}

fn bridge_with_optional_drop(
    client: TcpStream,
    upstream: String,
    drop_client_to_server_after_bytes: Option<usize>,
    client_to_server_bytes: Arc<AtomicUsize>,
) {
    let server = TcpStream::connect(upstream).expect("proxy connect upstream");
    let mut client_reader = client.try_clone().expect("clone client reader");
    let mut client_writer = client;
    let mut server_reader = server.try_clone().expect("clone server reader");
    let mut server_writer = server;
    let dropped = Arc::new(AtomicBool::new(false));

    let c2s_dropped = dropped.clone();
    let c2s = thread::spawn(move || {
        let mut total = 0usize;
        let mut buf = [0u8; 16 * 1024];
        loop {
            match client_reader.read(&mut buf) {
                Ok(0) => break,
                Ok(n) => {
                    total += n;
                    client_to_server_bytes.fetch_add(n, Ordering::SeqCst);
                    if server_writer.write_all(&buf[..n]).is_err() {
                        break;
                    }
                    if drop_client_to_server_after_bytes.is_some_and(|limit| total >= limit) {
                        c2s_dropped.store(true, Ordering::SeqCst);
                        let _ = client_reader.shutdown(Shutdown::Both);
                        let _ = server_writer.shutdown(Shutdown::Both);
                        break;
                    }
                }
                Err(_) => break,
            }
        }
    });

    let s2c_dropped = dropped.clone();
    let s2c = thread::spawn(move || {
        let mut buf = [0u8; 16 * 1024];
        loop {
            if s2c_dropped.load(Ordering::SeqCst) {
                break;
            }
            match server_reader.read(&mut buf) {
                Ok(0) => break,
                Ok(n) => {
                    if client_writer.write_all(&buf[..n]).is_err() {
                        break;
                    }
                }
                Err(_) => break,
            }
        }
    });

    let _ = c2s.join();
    let _ = s2c.join();
}
```

- [ ] **Step 2: Add the same-stream one-leg-loss integration test**

Append this test to `wstunnel-main/leikwan-bond/tests/tcp_speed_plus_degrade.rs`:

```rust
#[test]
fn single_application_stream_survives_leg_loss_on_best_slave() {
    let (target_addr, accepted) = spawn_target();
    let server_listen = free_loopback_addr();
    let mut server = spawn_bond_server(&server_listen);
    wait_for_port(&server_listen);

    let proxy_a = DroppableLegProxy::start(server_listen.clone(), Some(64 * 1024));
    let proxy_b = DroppableLegProxy::start(server_listen.clone(), None);

    let client_listen = free_loopback_addr();
    let legs = vec![
        format!(
            "tcp://{}?forward_id=91&node_id=20&endpoint_id=184&priority=10",
            proxy_a.listen
        ),
        format!(
            "tcp://{}?forward_id=91&node_id=21&endpoint_id=185&priority=20",
            proxy_b.listen
        ),
    ];
    let (mut client, _stderr) = spawn_bond_client(&client_listen, &legs, &target_addr, 91);
    wait_for_proxy_accepts(&proxy_a);
    wait_for_proxy_accepts(&proxy_b);

    let mut app = connect_with_retry(&client_listen);
    app.write_all(&vec![b'a'; 256 * 1024]).expect("first write");
    thread::sleep(Duration::from_millis(250));
    app.write_all(&vec![b'b'; 256 * 1024]).expect("second write");
    app.shutdown(Shutdown::Write).expect("shutdown app write");

    let payload = wait_for_single_target_payload(&accepted, 512 * 1024, Duration::from_secs(10));
    assert!(payload[..256 * 1024].iter().all(|byte| *byte == b'a'));
    assert!(payload[256 * 1024..].iter().all(|byte| *byte == b'b'));
    assert!(proxy_a.accepted_count.load(Ordering::SeqCst) >= 1);
    assert!(proxy_b.accepted_count.load(Ordering::SeqCst) >= 1);
    assert!(proxy_b.client_to_server_bytes.load(Ordering::SeqCst) > 64 * 1024);
    assert!(
        proxy_b.client_to_server_bytes.load(Ordering::SeqCst)
            > proxy_a.client_to_server_bytes.load(Ordering::SeqCst),
        "surviving proxy must carry post-loss bytes"
    );

    let _ = client.kill();
    let _ = client.wait();
    let _ = server.kill();
    let _ = server.wait();
}
```

- [ ] **Step 3: Add the all-bond-legs-lost explicit-failure integration test**

Append this test to `wstunnel-main/leikwan-bond/tests/tcp_speed_plus_degrade.rs`:

```rust
#[test]
fn all_legs_lost_reports_explicit_bond_family_failure() {
    let (target_addr, _accepted) = spawn_target();
    let server_listen = free_loopback_addr();
    let mut server = spawn_bond_server(&server_listen);
    wait_for_port(&server_listen);

    let client_listen = free_loopback_addr();
    let legs = vec![format!(
        "tcp://{}?forward_id=92&node_id=20&endpoint_id=184&priority=10",
        server_listen
    )];
    let (mut client, stderr) = spawn_bond_client(&client_listen, &legs, &target_addr, 92);

    let mut app = connect_with_retry(&client_listen);
    app.write_all(&vec![b'x'; 128 * 1024]).expect("write before loss");
    let _ = server.kill();

    assert!(
        wait_for_stderr_contains(
            &stderr,
            "no active bond legs remaining for tcp transport session",
            Duration::from_secs(8),
        ),
        "client stderr did not contain explicit no-active-bond-leg failure: {}",
        stderr.lock().expect("stderr lock").clone()
    );

    let _ = app.shutdown(Shutdown::Both);
    let _ = client.kill();
    let _ = client.wait();
}
```

The second test waits longer than the current five-second minimum transport resume timeout. It verifies a bond-family-local failure signal in the client transport log; the Linux supervisor plan owns process restart policy, so this test does not require the `leikwan-bond client` listener process to exit on its own.

- [ ] **Step 4: Run the integration tests and verify they fail before the transport changes**

Run:

```bash
cd wstunnel-main/leikwan-bond && cargo test --test tcp_speed_plus_degrade single_application_stream_survives_leg_loss_on_best_slave -- --nocapture
cd wstunnel-main/leikwan-bond && cargo test --test tcp_speed_plus_degrade all_legs_lost_reports_explicit_bond_family_failure -- --nocapture
```

Expected: FAIL. The first test fails until per-leg close re-pins unacknowledged chunks to the best surviving leg without resetting the stream. The second test fails until the no-active-leg error is surfaced in the client transport logs with the exact message shown above.

- [ ] **Step 5: Make the transport loop pass both integration tests**

Keep this family-local no-active-leg behavior in `run_tcp_transport_session`:

```rust
if !has_active_leg(&legs) {
    if has_live_streams {
        if transport_streams_can_end_cleanly(&streams, &connecting_streams) {
            break;
        }
        if no_leg_deadline.is_none() {
            no_leg_deadline = Some(Instant::now() + resume_timeout);
        }
        if Instant::now() >= no_leg_deadline.expect("no-leg deadline must be set") {
            return Err(anyhow!("no active bond legs remaining for tcp transport session"));
        }
    } else if last_activity.elapsed() >= idle_timeout {
        break;
    }
} else {
    no_leg_deadline = None;
}
```

Keep the client-side spawned transport task logging the returned error:

```rust
if let Err(err) = result {
    warn!("bond client TCP transport {} failed: {:?}", session_id, err);
}
```

Ensure the one-leg-loss path:

1. Marks only the dead leg inactive.
2. Re-pins pending chunks to the best surviving leg.
3. Leaves `TcpStreamState` and the aggregator-side upstream connector intact.
4. Sends no `Reset` for a per-leg close.
5. Allows ACKs from the surviving leg to clear pending chunks.
6. Does not start or probe another transport family.

- [ ] **Step 6: Run integration tests**

Run:

```bash
cd wstunnel-main/leikwan-bond && cargo test --test tcp_speed_plus_degrade -- --nocapture
```

Expected: PASS. The first test reports exactly one target TCP connection. The second test observes the explicit no-active-bond-leg failure in client stderr.

- [ ] **Step 7: Commit integration coverage**

Run:

```bash
git add wstunnel-main/leikwan-bond/tests/tcp_speed_plus_degrade.rs wstunnel-main/leikwan-bond/src/session.rs
git commit -m "test(bond): cover same-stream leg degradation" -m "Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 9: Run full bond data-plane verification and protect unrelated files

**Files:**
- Verify only; no additional production files.

- [ ] **Step 1: Format the Rust crate**

Run:

```bash
cd wstunnel-main/leikwan-bond && cargo fmt --all -- --check
```

Expected: PASS. If it fails, run:

```bash
cd wstunnel-main/leikwan-bond && cargo fmt --all
```

Then rerun the check command. Do not amend previous task commits during this verification task. Leave any formatting-only changes unstaged until Step 6, where they are either committed together as a verification-only formatting commit or skipped if no files changed.

- [ ] **Step 2: Run focused bond tests**

Run each focused filter separately because `cargo test` accepts only one substring filter before `--`:

```bash
cd wstunnel-main/leikwan-bond && cargo test parse_leg_spec -- --nocapture
cd wstunnel-main/leikwan-bond && cargo test parse_leg_specs_with_policy -- --nocapture
cd wstunnel-main/leikwan-bond && cargo test classify_tcp_pool -- --nocapture
cd wstunnel-main/leikwan-bond && cargo test best_surviving_tcp_leg -- --nocapture
cd wstunnel-main/leikwan-bond && cargo test retransmit -- --nocapture
cd wstunnel-main/leikwan-bond && cargo test probation -- --nocapture
cd wstunnel-main/leikwan-bond && cargo test local_snapshot_serialization -- --nocapture
cd wstunnel-main/leikwan-bond && cargo test --test tcp_speed_plus_degrade -- --nocapture
```

Expected: PASS for every command.

- [ ] **Step 3: Run the full `leikwan-bond` test suite**

Run:

```bash
cd wstunnel-main/leikwan-bond && cargo test --all-targets -- --nocapture
```

Expected: PASS.

- [ ] **Step 4: Confirm no forbidden transport fallback was introduced**

Run the committed-change scan from the pre-flight baseline, not only the current working tree diff:

```bash
python - <<'PY'
import json
import pathlib
import re
import subprocess
import sys

baseline_path = pathlib.Path('.git/leigod-bond-data-plane-baseline.json')
if not baseline_path.exists():
    raise SystemExit('missing .git/leigod-bond-data-plane-baseline.json; run the implementation pre-flight baseline first')
base_ref = json.loads(baseline_path.read_text(encoding='utf-8'))['base_ref']
patterns = re.compile(r'mptcp|cf_mode|current_mode|wss_path_mux|Cloudflare|classic sidecar', re.IGNORECASE)
diff_commands = [
    ('committed', ['git', 'diff', '--unified=0', f'{base_ref}..HEAD', '--', 'wstunnel-main/leikwan-bond']),
    ('staged', ['git', 'diff', '--cached', '--unified=0', '--', 'wstunnel-main/leikwan-bond']),
    ('working-tree', ['git', 'diff', '--unified=0', '--', 'wstunnel-main/leikwan-bond']),
]
violations = []
for scope, cmd in diff_commands:
    diff = subprocess.check_output(cmd, text=True, errors='replace')
    current_file = None
    for line in diff.splitlines():
        if line.startswith('+++ b/'):
            current_file = line[6:]
            continue
        if not line.startswith('+') or line.startswith('+++'):
            continue
        added = line[1:]
        if patterns.search(added):
            lowered = added.lower()
            allowed_negative_assertion = (
                ('no ' in lowered or 'without ' in lowered or 'forbidden' in lowered)
                and ('fallback' in lowered or 'mptcp' in lowered or 'cloudflare' in lowered or 'classic sidecar' in lowered)
            )
            if not allowed_negative_assertion:
                violations.append(f'{scope}:{current_file}: {added}')
if violations:
    print('\n'.join(violations))
    raise SystemExit(1)
PY
```

Expected: no output and exit code 0. If the script reports comments in tests that clearly assert the absence of fallback, tighten `allowed_negative_assertion` and rerun. If it reports code that starts native MPTCP, Cloudflare, WSS, `current_mode`, `cf_mode`, `wss_path_mux`, or classic sidecar fallback, remove that code and rerun the full suite.

- [ ] **Step 5: Confirm the protected Bridge Lite plan is untouched**

Run this baseline comparison. It allows the protected plan to remain untracked only if it was already untracked at pre-flight and the content hash is unchanged.

```bash
python - <<'PY'
import hashlib
import json
import pathlib
import subprocess

baseline_path = pathlib.Path('.git/leigod-bond-data-plane-baseline.json')
if not baseline_path.exists():
    raise SystemExit('missing .git/leigod-bond-data-plane-baseline.json; run the implementation pre-flight baseline first')
baseline = json.loads(baseline_path.read_text(encoding='utf-8'))
bridge = pathlib.Path(baseline['bridge_path'])
current_status = subprocess.check_output(['git', 'status', '--short', '--', str(bridge)], text=True)
current_hash = hashlib.sha256(bridge.read_bytes()).hexdigest() if bridge.exists() else None
committed = subprocess.check_output(['git', 'diff', '--name-status', f"{baseline['base_ref']}..HEAD", '--', str(bridge)], text=True)
errors = []
if current_status != baseline['bridge_status']:
    errors.append(f"status changed: baseline={baseline['bridge_status']!r} current={current_status!r}")
if current_hash != baseline['bridge_hash']:
    errors.append('content hash changed')
if committed.strip():
    errors.append(f'protected plan appears in committed changes: {committed.strip()}')
if errors:
    raise SystemExit('\n'.join(errors))
PY
```

Expected: no output and exit code 0. If it fails, stop and restore only the accidental Bridge Lite change before continuing; never stage, commit, delete, or execute that file.

- [ ] **Step 6: Commit any verification-only formatting changes**

If `cargo fmt` changed files not already committed, run:

```bash
git add wstunnel-main/leikwan-bond/src/main.rs wstunnel-main/leikwan-bond/src/transport.rs wstunnel-main/leikwan-bond/src/session.rs wstunnel-main/leikwan-bond/tests/tcp_speed_plus_degrade.rs wstunnel-main/leikwan-bond/Cargo.toml wstunnel-main/leikwan-bond/Cargo.lock
git commit -m "chore(bond): format speed plus runtime" -m "Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>" -m "Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

If no files changed, skip this commit and record that the formatting check passed without changes.

---

## Acceptance checklist

The implementation is complete only when all of these are true:

- `leikwan-bond client` can require stable leg identity and reject missing, mismatched, duplicate, zero, or more-than-eight legs.
- Every active LeiGod leg has a deterministic logical key of the form `bond:<forward_id>:tcp:<node_id>:<endpoint_id>`.
- A one-leg Forward runs as `single_leg_only` without pretending to be Speed Plus.
- A two-to-eight-leg Forward runs as `speed_plus` only while at least two non-probation legs are usable.
- Losing all but one usable leg changes pool mode to `degraded_single_leg` or `single_leg_only` and keeps the same application TCP stream and target TCP connection.
- Pending chunks are not dropped or acknowledged locally during leg loss; they are retransmitted through the best surviving eligible leg.
- Recovered legs pass probation before bulk striping resumes.
- Snapshots are atomically replaced and include schema version, pool mode, best leg key, logical keys, node IDs, endpoint IDs, protocol health, and bounded recent events.
- All-legs-lost failure remains explicit and bond-family-local.
- No native MPTCP, Cloudflare, WSS, or classic sidecar fallback path is introduced.
- `docs/superpowers/plans/2026-06-23-bridge-lite-multi-theme-redesign.md` is not modified, staged, committed, deleted, or executed.

---

## Self-review notes

Spec coverage:

- Stable logical leg identity is covered by Tasks 1-3.
- One-to-eight explicit Slave cardinality is covered by Task 2.
- Same-session best-Slave degradation is covered by Tasks 4, 5, and 8.
- ACK/retransmission through the surviving leg is covered by Task 5.
- Probation and controlled re-admission are covered by Task 6.
- Atomic health snapshots are covered by Task 7.
- No MPTCP/Cloudflare/WSS/classic fallback is covered by Tasks 8 and 9.
- Upload/download production acceptance campaigns remain in the activation/rollout plan, not this Rust data-plane plan.

Placeholder scan:

- The plan text has been reviewed for the prohibited placeholder phrases from the writing-plans guidance.
- Every code-changing step names exact files, exact code snippets, exact verification commands, and expected pass/fail outcomes.
- Future reviewers should repeat the same scan before implementing the plan and fix any newly introduced placeholder wording before execution.

No production deployment, artifact publication, credential exposure, or remote service operation is part of this plan.
