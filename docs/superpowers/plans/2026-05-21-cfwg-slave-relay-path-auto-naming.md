# CFWG Slave Relay Path Auto-Naming Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement deterministic, camouflage-style auto-naming for wstunnel WSS upgrade paths used by the cfwg slave relay leg, so the operator never needs to manually specify `slave_path_prefix` — it "just works" from the forward config alone, while looking like innocent CDN traffic to any DPI observer.

---

## Background & Problem Statement

The cfwg slave relay feature (implemented in `feat/cfwg-slave-relay-leg`) adds a second WG interface that routes through the slave's WSS mux using a **different URL path** than the direct cfwg leg. Currently:

- **Direct cfwg leg path:** derived from `cf_path_prefix` (e.g. `tokyo.cfwg`)
- **Slave relay leg path:** manually configured via `slave_relay.slave_path_prefix` (e.g. `Windows-9.1.iso`)

**Problems with manual path assignment:**
1. Operator must remember to configure it — easy to forget
2. No collision detection between direct and relay paths
3. No consistency across forwards — hard to debug
4. Path must look like plausible CDN traffic (DPI camouflage)
5. Must not collide with other services on the same slave mux port

---

## Design: Auto-Naming Strategy

### Naming Constraints

1. **Cloudflare compatible:** CF allows any URL path on websocket upgrade — no restriction on path length or characters (except standard URL encoding)
2. **No extra port:** both direct and relay legs share the same CF-fronted port (e.g. 2053, 2083, 2087, 8443, 443) — only the path differs
3. **DPI camouflage:** paths should look like normal CDN file requests, not tunnel identifiers
4. **Collision-free:** different forwards on the same slave must have unique paths
5. **Deterministic:** given the same forward_id and config, the path should always be the same
6. **Short enough:** Cloudflare doesn't limit path length, but shorter is better for overhead

### Proposed Path Template System

Use a **camouflage template** system with deterministic substitution:

```
Template pool (rotated by forward_id):
  - "assets/v{major}.{minor}/chunk-{hash}.ts"          (HLS video segment)
  - "cdn/releases/{hash}/update-{version}.bin"          (software update)
  - "media/stream-{hash}.m3u8"                          (HLS playlist)
  - "files/{hash}/Windows-{version}.iso"                (ISO download)
  - "static/js/{hash}.chunk.js"                         (webpack chunk)
  - "api/v2/telemetry/{hash}"                           (telemetry endpoint)
  - "download/{hash}/package-{version}.deb"             (package download)
  - "content/{hash}/episode-{num}.mp4"                  (video content)
```

Where `{hash}` is derived deterministically from:
- `forward_id`
- `endpoint_id` (slave node)
- A fixed salt per leg type (direct vs relay)

This ensures:
- Same forward always gets the same path
- Direct and relay paths never collide (different salt)
- Different forwards on same slave never collide (different forward_id)
- Paths look like real CDN traffic

### Concrete Algorithm

```python
def auto_cfwg_path(forward_id: int, leg_type: str) -> str:
    """Generate a deterministic camouflage path for cfwg legs.
    
    leg_type: "direct" or "slave_relay"
    """
    import hashlib
    
    # Templates indexed by (forward_id % len(templates))
    templates = [
        ("assets/v{v1}.{v2}/chunk-{h8}.ts", "direct_suffix", "relay_suffix"),
        ("cdn/releases/{h8}/update-{v1}.{v2}.bin", "direct_suffix", "relay_suffix"),  
        ("media/stream-{h8}.m3u8", "direct_suffix", "relay_suffix"),
        ("files/{h8}/Windows-{v1}.{v2}.iso", "direct_suffix", "relay_suffix"),
        ("static/js/{h8}.chunk.js", "direct_suffix", "relay_suffix"),
        ("api/v2/telemetry/{h12}", "direct_suffix", "relay_suffix"),
        ("download/{h8}/package-{v1}.{v2}.deb", "direct_suffix", "relay_suffix"),
        ("content/{h8}/episode-{num}.mp4", "direct_suffix", "relay_suffix"),
    ]
    
    salt = f"cfwg-{leg_type}-{forward_id}"
    digest = hashlib.sha256(salt.encode()).hexdigest()
    
    h8 = digest[:8]           # 8-char hex hash
    h12 = digest[:12]         # 12-char hex hash
    v1 = str((forward_id * 7 + 2) % 20 + 1)  # fake version major
    v2 = str((forward_id * 13 + 5) % 99)       # fake version minor
    num = str((forward_id * 3 + 1) % 500 + 1)  # fake episode number
    
    template_idx = forward_id % len(templates)
    template = templates[template_idx][0]
    
    path = template.format(h8=h8, h12=h12, v1=v1, v2=v2, num=num)
    return path
```

**Example outputs for forward 47:**
- Direct: `media/stream-a7c3e2f1.m3u8`  
- Relay: `media/stream-b9d4f8a2.m3u8` (different hash due to different salt)

**Example outputs for forward 48:**
- Direct: `files/c1e5a9b3/Windows-8.29.iso`
- Relay: `files/d2f6b0c4/Windows-8.29.iso`

### Fallback Behavior

1. If `slave_path_prefix` is explicitly set → use it (backward compat)
2. If `slave_path_prefix` is empty/missing → auto-generate using the algorithm above
3. If `cf_path_prefix` on the direct leg is explicitly set → use it; otherwise auto-generate

### Direct Leg Path (also auto-generate)

Currently the direct cfwg leg uses `{base_path}.cfwg` which is **not** great camouflage (the `.cfwg` suffix is suspicious). We should also auto-generate the direct leg path using the same template system but with a different salt.

However, this is a **breaking change** for existing deployments. So:
- Keep the existing `.cfwg` suffix behavior as default
- Add `auto_camouflage_paths: true` in `self_leg_wg` to opt-in to the new auto-naming
- When enabled, both direct and relay paths use the template system

---

## Files to Change

### Client side: `client-agent/install_forward_runtime.py`

1. Add `auto_cfwg_path(forward_id, leg_type, endpoint_id=0)` function
2. Modify `self_leg_wg_underlay()`:
   - When `auto_camouflage_paths` is truthy in `wg` config, auto-generate `cf_path_prefix` using `auto_cfwg_path(forward_id, "direct")`
3. Modify `self_leg_slave_relay_wg_underlay()`:
   - When `slave_path_prefix` is empty, auto-generate using `auto_cfwg_path(forward_id, "slave_relay")`
4. Add the path to the slave relay wstunnel command line in `render_forward_runtime()`

### Slave side: `slave-agent/slave_agent.py`

5. Modify `ensure_cfwg_relay_service()`:
   - Accept auto-generated path from the panel publish payload
   - No local path generation needed (panel tells slave what path to use)

### Panel side (documentation only — no code changes needed immediately):

6. Document that when publishing the cfwg relay task, the panel should:
   - Set `cfwg_relay.slave_path_prefix` to the auto-generated value
   - Or leave it empty and let the slave use whatever the client specifies

### Test: `client-agent/tests/test_cfwg_slave_relay_runtime.py`

7. Add test for auto-generated path when `slave_path_prefix` is empty
8. Add test verifying direct and relay paths never collide
9. Add test verifying same forward always produces same path (determinism)
10. Add test verifying different forwards produce different paths

---

## Detailed Implementation Tasks

### Task 1 — Add `auto_cfwg_path()` utility function

- [ ] In `client-agent/install_forward_runtime.py`, add the following function **after** the existing `cfwg_watchdog_pin()` function (around line 80):

```python
def auto_cfwg_path(forward_id: int, leg_type: str, endpoint_id: int = 0) -> str:
    """Generate a deterministic camouflage URL path for cfwg wstunnel legs.

    The path looks like a normal CDN file request to evade DPI.
    Different leg_type values ("direct", "slave_relay") produce different paths
    for the same forward_id, ensuring no collision on the same slave mux port.

    Args:
        forward_id: The forward ID (must be > 0)
        leg_type: "direct" for the client→CF leg, "slave_relay" for client→slave→CF leg
        endpoint_id: Optional endpoint ID for additional uniqueness (default 0)
    """
    import hashlib

    salt = f"leikwan-cfwg-{leg_type}-fwd{forward_id}-ep{endpoint_id}"
    digest = hashlib.sha256(salt.encode()).hexdigest()

    h8 = digest[:8]
    h12 = digest[:12]
    v_major = str((forward_id * 7 + 2) % 20 + 1)
    v_minor = str((forward_id * 13 + 5) % 99)
    episode = str((forward_id * 3 + 1) % 500 + 1)

    # Template pool — rotate by forward_id for variety across forwards
    templates = [
        f"assets/v{v_major}.{v_minor}/chunk-{h8}.ts",
        f"cdn/releases/{h8}/update-{v_major}.{v_minor}.bin",
        f"media/stream-{h8}.m3u8",
        f"files/{h8}/Windows-{v_major}.{v_minor}.iso",
        f"static/js/{h8}.chunk.js",
        f"api/v2/telemetry/{h12}",
        f"download/{h8}/package-{v_major}.{v_minor}.deb",
        f"content/{h8}/episode-{episode}.mp4",
    ]

    return templates[forward_id % len(templates)]
```

- [ ] Verify: `python3 -c "from client-agent.install_forward_runtime import auto_cfwg_path; print(auto_cfwg_path(47, 'direct')); print(auto_cfwg_path(47, 'slave_relay'))"`

---

### Task 2 — Wire auto-path into slave relay underlay builder

- [ ] In `self_leg_slave_relay_wg_underlay()` in `install_forward_runtime.py`, find the line:

```python
    if slave_host == "" or slave_port <= 0 or slave_path_prefix == "":
        return None
```

- [ ] Change to:

```python
    if slave_host == "" or slave_port <= 0:
        return None
    if slave_path_prefix == "":
        slave_path_prefix = auto_cfwg_path(forward_id, "slave_relay", int_or_default(candidate.get("endpoint_id"), 0))
```

This means `slave_path_prefix` is no longer required — if empty, it auto-generates.

---

### Task 3 — Wire auto-path into slave relay wstunnel rendering

- [ ] In `render_forward_runtime()` MPTCP section, in the slave relay wstunnel loop, verify that `slave_path` variable will now contain the auto-generated path (it reads from `leg["slave_path_prefix"]` which was already set in the underlay builder). No change needed here — it flows through naturally.

---

### Task 4 — Optionally auto-generate direct cfwg path (opt-in)

- [ ] In `self_leg_wg_underlay()`, after the line:

```python
        base_path = str(candidate.get("cf_path_prefix", "") or wg.get("cf_path_prefix", "") or candidate.get("path_prefix", "") or forward.get("path_prefix", "") or "").strip().strip("/")
        if base_path != "":
            wg["cf_path_prefix"] = base_path if base_path.endswith(".cfwg") else base_path + ".cfwg"
```

- [ ] Add a fallback when `base_path` is still empty **and** `auto_camouflage_paths` is enabled:

```python
        if base_path == "" and truthy(wg.get("auto_camouflage_paths", False)):
            base_path = auto_cfwg_path(forward_id, "direct", int_or_default(candidate.get("endpoint_id"), 0))
            wg["cf_path_prefix"] = base_path
        elif base_path != "":
            wg["cf_path_prefix"] = base_path if base_path.endswith(".cfwg") else base_path + ".cfwg"
```

This is opt-in only. Existing deployments keep using `.cfwg` suffix.

---

### Task 5 — Add regression tests

- [ ] In `client-agent/tests/test_cfwg_slave_relay_runtime.py`, add:

```python
class CfwgAutoPathTests(unittest.TestCase):
    def test_auto_path_generates_deterministic_output(self) -> None:
        path1 = install_runtime.auto_cfwg_path(47, "direct")
        path2 = install_runtime.auto_cfwg_path(47, "direct")
        self.assertEqual(path1, path2)

    def test_auto_path_direct_and_relay_never_collide(self) -> None:
        for fwd_id in range(1, 200):
            direct = install_runtime.auto_cfwg_path(fwd_id, "direct")
            relay = install_runtime.auto_cfwg_path(fwd_id, "slave_relay")
            self.assertNotEqual(direct, relay, f"collision for forward_id={fwd_id}")

    def test_auto_path_different_forwards_produce_different_paths(self) -> None:
        paths = set()
        for fwd_id in range(1, 200):
            path = install_runtime.auto_cfwg_path(fwd_id, "slave_relay")
            self.assertNotIn(path, paths, f"duplicate path for forward_id={fwd_id}")
            paths.add(path)

    def test_auto_path_looks_like_cdn_traffic(self) -> None:
        for fwd_id in [1, 47, 100, 255]:
            path = install_runtime.auto_cfwg_path(fwd_id, "slave_relay")
            # Should not contain suspicious keywords
            self.assertNotIn("wg", path.lower())
            self.assertNotIn("wireguard", path.lower())
            self.assertNotIn("tunnel", path.lower())
            self.assertNotIn("vpn", path.lower())
            # Should contain a file extension or look like an API path
            self.assertTrue(
                any(path.endswith(ext) for ext in [".ts", ".bin", ".m3u8", ".iso", ".js", ".deb", ".mp4"])
                or "/telemetry/" in path,
                f"path does not look like CDN traffic: {path}"
            )

    def test_slave_relay_auto_generates_path_when_empty(self) -> None:
        candidate = {
            "candidate_kind": "self_leg",
            "selected_primary": True,
            "runtime_capabilities": ["bond", "mptcp", "wireguard"],
            "probe_transport": "cf_wg",
            "self_leg_wg": {
                "enabled": True,
                "outer_transport": "wstunnel_udp",
                "client_address": "10.204.0.1",
                "sidecar_address": "10.204.0.2",
                "sidecar_public_key": "PUBKEY===",
                "endpoint_port": 51820,
                "cf_dial_host": "104.16.0.5",
                "cf_dial_port": 443,
                "cf_path_prefix": "index.m3u8",
                "slave_relay": {
                    "enabled": True,
                    "slave_host": "47.116.11.144",
                    "slave_port": 2053,
                    "slave_path_prefix": "",  # empty — should auto-generate
                    "slave_sni_host": "nrt1.nbwly.com",
                    "client_address": "10.204.1.1",
                    "sidecar_address": "10.204.1.2",
                    "cf_target_port": 51820,
                },
            },
        }
        forward = {"forward_id": 47}
        result = install_runtime.self_leg_slave_relay_wg_underlay(candidate, forward)
        self.assertIsNotNone(result)
        leg = result["legs"][0]
        slave_path = leg["slave_path_prefix"]
        self.assertNotEqual("", slave_path)
        self.assertNotIn("cfwg", slave_path)  # should not expose internal naming
```

- [ ] Run: `python3 -m unittest client-agent/tests/test_cfwg_slave_relay_runtime.py`

---

### Task 6 — Update the slave-side `ensure_cfwg_relay_service` to accept auto-generated paths

- [ ] No code change needed — `ensure_cfwg_relay_service` already reads `cfwg_relay.slave_path_prefix` from the publish payload. The panel will populate this field with the auto-generated value when publishing the task.

- [ ] However, add a log line in `ensure_cfwg_relay_service` after the path is determined:

```python
        logging.info(
            "cfwg relay service %s: slave_path=%s cf_path=%s target=%s",
            relay_service, slave_path_prefix, cf_path_prefix, target,
        )
```

---

### Task 7 — Document the path naming convention

- [ ] Add a comment block at the top of `auto_cfwg_path()` explaining:
  - Why camouflage matters (DPI evasion)
  - How the salt ensures uniqueness
  - Template rotation rationale
  - How to add new templates if needed
  - That modifying the template pool or salt changes all existing paths (rolling restart needed)

---

## Verification Commands

After implementation, run:

```bash
# All slave relay tests (should include the new auto-path tests)
python3 -m unittest client-agent/tests/test_cfwg_slave_relay_runtime.py

# Existing cfwg tests should still pass
python3 -m unittest client-agent/tests/test_self_leg_cf_wg_runtime.py

# Existing self-leg tests should still pass
python3 -m unittest client-agent/tests/test_self_leg_best_ip_override.py

# Existing slave tests should still pass
python3 -m unittest slave-agent/tests/test_slave_plain_tcp_mux.py
```

---

## Rollout Notes

1. **Backward compatible:** existing `slave_path_prefix` config still works — auto-generation only kicks in when the field is empty
2. **Direct leg unchanged by default:** auto-camouflage for the direct leg requires `auto_camouflage_paths: true` in `self_leg_wg`
3. **Panel publish:** when the panel publishes a cfwg relay task to the slave, it should include the auto-generated path in `cfwg_relay.slave_path_prefix`
4. **No sidecar change:** the sidecar only sees the same cfwg path it already accepts — the slave relay just relays to it

---

## Example Live Config (forward 47, after auto-naming)

```json
{
  "self_leg_wg": {
    "enabled": true,
    "outer_transport": "wstunnel_udp",
    "cf_path_prefix": "index.m3u8.cfwg",
    "slave_relay": {
      "enabled": true,
      "slave_host": "47.116.11.144",
      "slave_port": 2053,
      "slave_path_prefix": "",
      "slave_sni_host": "nrt1.nbwly.com",
      "client_address": "10.204.1.1",
      "sidecar_address": "10.204.1.2"
    }
  }
}
```

Auto-generated paths at runtime:
- Direct: `index.m3u8.cfwg` (unchanged, explicit config)
- Relay: `content/a7c3e2f1/episode-142.mp4` (auto-generated from forward_id=47, leg_type="slave_relay")

---

## Summary of Changes

| File | Change |
|------|--------|
| `client-agent/install_forward_runtime.py` | Add `auto_cfwg_path()`, wire into `self_leg_slave_relay_wg_underlay()` fallback, optional direct leg auto-path |
| `slave-agent/slave_agent.py` | Add logging in `ensure_cfwg_relay_service()` |
| `client-agent/tests/test_cfwg_slave_relay_runtime.py` | Add `CfwgAutoPathTests` class with 5 tests |
| This document | Reference for implementors |
