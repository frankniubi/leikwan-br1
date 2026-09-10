# MPTCP Multi-Interface Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert single-WG-interface MPTCP from "1 interface with N peers" to "N interfaces each with 1 peer" so the kernel can establish true multipath subflows across different devices.

**Architecture:** Panel generates `underlays[]` (array of per-leg WG interface configs) instead of single `underlay`. Client/sidecar agents loop over `underlays[]`, creating one WG interface per entry. MPTCP endpoints get per-entry `dev` binding. Rust relay reads `underlays[]` for auto-derived endpoints. Backward compat: agents accept both old `underlay` and new `underlays`.

**Tech Stack:** PHP 8 (Panel), Python 3 (agents), Rust (relay), WireGuard, Linux MPTCP kernel

**Spec:** `docs/superpowers/specs/2026-04-12-mptcp-multi-interface-design.md`

---

## File Map

| File | Action | Responsibility |
|------|--------|---------------|
| `master/src/Support/ProvisioningService.php` | Modify | Generate `underlays[]` + per-endpoint `dev` in `buildMptcpClientConfig()` and `mptcpSidecarManagementForPoint()` |
| `client-agent/leikwan_mptcp_client.py` | Modify | Loop over `underlays[]` creating N WG interfaces; support per-endpoint `dev` in `configure_mptcp_policy()` |
| `sidecar-agent/leikwan_mptcp_sidecar.py` | Modify | Same as client agent |
| `sidecar-agent/sidecar_agent.py` | Modify | Map new `mptcp_policy` per-endpoint `dev` fields |
| `wstunnel-main/leikwan-mptcp-relay/src/config.rs` | Modify | Add `underlays` field to `ClientConfig` |
| `wstunnel-main/leikwan-mptcp-relay/src/main.rs` | Modify | Read `underlays[]` for per-interface endpoint derivation |

---

## Task 1: Panel — `buildMptcpClientConfig()` multi-interface output

**Files:**
- Modify: `master/src/Support/ProvisioningService.php:3691-3742`

This task changes the client config generation from a single `underlay` with N legs to an `underlays` array where each entry has 1 leg and its own interface name. Also populates `mptcp_endpoints` with per-endpoint `dev` binding.

- [ ] **Step 1: Modify `buildMptcpClientConfig()` to emit `underlays` array**

In `master/src/Support/ProvisioningService.php`, find `buildMptcpClientConfig()` (line 3691). Replace the return array's `underlay` and endpoint fields. The key changes:

1. Replace `'underlay' => [single config]` with `'underlays' => [array of configs]`
2. Replace empty `'mptcp_endpoints' => []` with populated per-endpoint entries including `dev`
3. Remove `'mptcp_endpoint_device'` (no longer a single global device)

Find this block in the return array (around lines 3730-3741):

```php
            'manage_mptcp_endpoints' => $underlayLegs !== [],
            'mptcp_endpoint_device' => 'lw-mptcp-' . (int)$forward['id'],
            'mptcp_endpoint_id_base' => (($forward['id'] % 25) * 10),
            'mptcp_endpoints' => [],
            'underlay' => [
                'type' => config('MPTCP_UNDERLAY_TYPE', 'wireguard'),
                'mode' => 'slave_transit',
                'interface_name' => 'lw-mptcp-' . (int)$forward['id'],
                'peer_keepalive_seconds' => max(0, config('MPTCP_PEER_KEEPALIVE_SECONDS', 25)),
                'mptcp_endpoints' => [],
                'legs' => $underlayLegs,
            ],
```

Replace with:

```php
            'manage_mptcp_endpoints' => $underlayLegs !== [],
            'mptcp_endpoint_id_base' => (($forward['id'] % 25) * 10),
            'mptcp_endpoints' => array_values(array_map(function($leg, $idx) use ($forward) {
                return [
                    'address' => $leg['local_address'],
                    'dev' => 'lw-mptcp-' . (int)$forward['id'] . '-' . $idx,
                    'subflow' => true,
                    'signal' => false,
                    'backup_only' => $leg['backup_only'] ?? false,
                ];
            }, $underlayLegs, array_keys($underlayLegs))),
            'underlays' => array_values(array_map(function($leg, $idx) use ($forward) {
                return [
                    'type' => config('MPTCP_UNDERLAY_TYPE', 'wireguard'),
                    'mode' => 'slave_transit',
                    'interface_name' => 'lw-mptcp-' . (int)$forward['id'] . '-' . $idx,
                    'peer_keepalive_seconds' => max(0, config('MPTCP_PEER_KEEPALIVE_SECONDS', 25)),
                    'legs' => [$leg],
                ];
            }, $underlayLegs, array_keys($underlayLegs))),
```

Note: `'mptcp_endpoint_device'` is removed entirely — each endpoint now carries its own `dev`.

- [ ] **Step 2: Verify no other code references the removed `mptcp_endpoint_device` key from client config**

Run:
```bash
grep -rn "mptcp_endpoint_device" master/src/
```

Expected: only the sidecar function (`mptcpSidecarManagementForPoint`) should remain — the client side reference is now gone. The agents read this from the JSON at runtime, so removing it from the Panel output is sufficient.

- [ ] **Step 3: Commit**

```bash
git add master/src/Support/ProvisioningService.php
git commit -m "feat(panel): buildMptcpClientConfig emits underlays[] with per-endpoint dev"
```

---

## Task 2: Panel — `mptcpSidecarManagementForPoint()` multi-interface output

**Files:**
- Modify: `master/src/Support/ProvisioningService.php:3849-3953`

Same pattern as Task 1 but for the sidecar side. Uses `signal: true` instead of `subflow: true`. Interface naming: `lw-sc-{pointId}-{legIdx}`.

- [ ] **Step 1: Modify `mptcpSidecarManagementForPoint()` to emit `underlays` array**

Find the return statement (around lines 3932-3952):

```php
        return [
            'underlay' => [
                'type' => config('MPTCP_UNDERLAY_TYPE', 'wireguard'),
                'mode' => 'slave_transit',
                'interface_name' => 'lw-sidecar-' . $pointId,
                'peer_keepalive_seconds' => max(0, config('MPTCP_PEER_KEEPALIVE_SECONDS', 25)),
                'legs' => $legs,
            ],
            'mptcp_policy' => [
                'endpoint_device' => 'lw-sidecar-' . $pointId,
                'endpoint_id_base' => ($pointId % 25) * 10,
                'endpoints' => array_map(
                    fn($leg) => [
                        'address' => $leg['sidecar_address'],
                        'signal' => true,
                    ],
                    $legs
                ),
            ],
            'wireguard_public_key' => trim($pointConfig['wireguard_public_key']),
        ];
```

Replace with:

```php
        return [
            'underlays' => array_values(array_map(function($leg, $idx) use ($pointId) {
                return [
                    'type' => config('MPTCP_UNDERLAY_TYPE', 'wireguard'),
                    'mode' => 'slave_transit',
                    'interface_name' => 'lw-sc-' . $pointId . '-' . $idx,
                    'peer_keepalive_seconds' => max(0, config('MPTCP_PEER_KEEPALIVE_SECONDS', 25)),
                    'legs' => [$leg],
                ];
            }, $legs, array_keys($legs))),
            'mptcp_policy' => [
                'endpoint_id_base' => ($pointId % 25) * 10,
                'endpoints' => array_values(array_map(function($leg, $idx) use ($pointId) {
                    return [
                        'address' => $leg['local_address'],
                        'dev' => 'lw-sc-' . $pointId . '-' . $idx,
                        'signal' => true,
                    ];
                }, $legs, array_keys($legs))),
            ],
            'wireguard_public_key' => trim($pointConfig['wireguard_public_key']),
        ];
```

Key changes:
- `underlay` → `underlays` (array, each with 1 leg)
- Interface name: `lw-sidecar-{pointId}` → `lw-sc-{pointId}-{legIdx}` (15-char limit safe)
- `mptcp_policy.endpoint_device` removed — each endpoint entry now has its own `dev`
- `endpoints[].address` uses `$leg['local_address']` (which is `sidecar_address` for sidecar-perspective legs)

- [ ] **Step 2: Commit**

```bash
git add master/src/Support/ProvisioningService.php
git commit -m "feat(panel): mptcpSidecarManagementForPoint emits underlays[] with per-endpoint dev"
```

---

## Task 3: Client Agent — multi-underlay support in `apply_underlay` path

**Files:**
- Modify: `client-agent/leikwan_mptcp_client.py:219-245` (apply_wireguard_transit_underlay)
- Modify: `client-agent/leikwan_mptcp_client.py:784` (call site in main_async)

This task makes the client agent create N WG interfaces from the `underlays` array, with backward compat for old single `underlay`.

- [ ] **Step 1: Rename `apply_wireguard_transit_underlay` to `apply_wireguard_transit_underlay_single`**

In `client-agent/leikwan_mptcp_client.py`, change line 219:

```python
def apply_wireguard_transit_underlay(config: dict[str, Any]) -> None:
```

to:

```python
def apply_wireguard_transit_underlay_single(config: dict[str, Any]) -> None:
```

No other changes to the function body — it already works correctly with a 1-leg `legs` array.

- [ ] **Step 2: Add new `apply_wireguard_transit_underlays` wrapper**

Insert this new function right before the renamed `apply_wireguard_transit_underlay_single` (before line 219):

```python
def apply_wireguard_transit_underlays(underlays: list[dict[str, Any]], parent_config: dict[str, Any]) -> None:
    """Create N WG interfaces, one per underlay entry (one leg each)."""
    if os.name == "nt":
        return
    active_iface_names: set[str] = set()
    for underlay in underlays:
        if not isinstance(underlay, dict):
            continue
        # Inherit top-level config values that the underlay needs
        merged = dict(underlay)
        for key in ("local_private_key_path", "initcwnd", "initrwnd"):
            if key not in merged and key in parent_config:
                merged[key] = parent_config[key]
        apply_wireguard_transit_underlay_single(merged)
        name = str(merged.get("interface_name", "") or "").strip()
        if name:
            active_iface_names.add(name)
    # Clean up stale interfaces from previous config
    cleanup_stale_transit_interfaces(parent_config, active_iface_names)
```

- [ ] **Step 3: Add `cleanup_stale_transit_interfaces` function**

Insert after the new wrapper function:

```python
def cleanup_stale_transit_interfaces(config: dict[str, Any], active_names: set[str]) -> None:
    """Remove WG interfaces that were part of a previous multi-interface config but are no longer needed."""
    if os.name == "nt" or not shutil_which("wg"):
        return
    try:
        result = run_command(["wg", "show", "interfaces"], check=False)
        current_interfaces = result.stdout.strip().split()
    except Exception:
        return
    # Identify stale interfaces: match our naming patterns but not in active set
    for iface in current_interfaces:
        if iface.startswith("lw-mptcp-") and iface not in active_names:
            logging.info("removing stale transit interface: %s", iface)
            if shutil_which("systemctl"):
                run_command(["systemctl", "stop", f"wg-quick@{iface}.service"], check=False)
                run_command(["systemctl", "disable", f"wg-quick@{iface}.service"], check=False)
            elif shutil_which("wg-quick"):
                run_command(["wg-quick", "down", iface], check=False)
            conf_path = pathlib.Path(f"/etc/wireguard/{iface}.conf")
            if conf_path.exists():
                conf_path.unlink()
```

- [ ] **Step 4: Update `apply_underlay` dispatcher to handle `underlays` array**

In `client-agent/leikwan_mptcp_client.py`, the current `apply_underlay` function (line 294) only takes a single config dict. We need a new top-level function that the `main_async` caller uses. Add this before the existing `apply_underlay`:

```python
def apply_underlays(config: dict[str, Any]) -> None:
    """Dispatch underlay setup: supports both old 'underlay' and new 'underlays' formats."""
    underlays = config.get("underlays", [])
    if isinstance(underlays, list) and underlays:
        # New multi-interface path
        apply_wireguard_transit_underlays(underlays, config)
        return
    # Backward compat: old single-underlay path
    underlay = config.get("underlay", {})
    if isinstance(underlay, dict):
        apply_underlay(underlay)
```

- [ ] **Step 5: Update `main_async` call site**

In `client-agent/leikwan_mptcp_client.py`, line 784:

```python
    apply_underlay(config.get("underlay", {}) if isinstance(config.get("underlay", {}), dict) else {})
```

Replace with:

```python
    apply_underlays(config)
```

- [ ] **Step 6: Update `remote_host_candidates` to read from `underlays[]`**

In `client-agent/leikwan_mptcp_client.py`, find `remote_host_candidates` (line 469). It currently reads `config.get("underlay", {})` to extract `mptcp_remote_address` from legs. Add support for `underlays[]`:

Replace lines 479-486:

```python
    underlay = config.get("underlay", {})
    if isinstance(underlay, dict):
        legs = underlay.get("legs", [])
        if isinstance(legs, list):
            for leg in legs:
                if isinstance(leg, dict):
                    add_host(leg.get("mptcp_remote_address"))
    return hosts
```

With:

```python
    # Support both new underlays[] and old underlay formats
    underlays = config.get("underlays", [])
    if not isinstance(underlays, list) or not underlays:
        underlay = config.get("underlay", {})
        underlays = [underlay] if isinstance(underlay, dict) else []
    for underlay in underlays:
        if not isinstance(underlay, dict):
            continue
        legs = underlay.get("legs", [])
        if isinstance(legs, list):
            for leg in legs:
                if isinstance(leg, dict):
                    add_host(leg.get("mptcp_remote_address"))
    return hosts
```

- [ ] **Step 7: Commit**

```bash
git add client-agent/leikwan_mptcp_client.py
git commit -m "feat(client-agent): support underlays[] array for multi-interface WG setup"
```

---

## Task 4: Client Agent — per-endpoint `dev` support in `configure_mptcp_policy`

**Files:**
- Modify: `client-agent/leikwan_mptcp_client.py:308-462` (configure_mptcp_policy)

Currently `configure_mptcp_policy` reads a single global `mptcp_endpoint_device` and applies it to all endpoints. We need to support per-endpoint `dev` from the `mptcp_endpoints` list entries, while keeping backward compat with the global device.

- [ ] **Step 1: Add per-entry `dev` to desired_entries parsing**

In `configure_mptcp_policy()`, find the desired entries parsing loop (around line 341-362). Currently when parsing dict entries:

```python
        if isinstance(item, dict):
            address = str(item.get("address", "") or "").strip()
            backup_only = truthy(item.get("backup_only", False))
            signal = truthy(item.get("signal", False))
            subflow = truthy(item.get("subflow", False))
            fullmesh = truthy(item.get("fullmesh", False))
```

Add `dev` extraction after `fullmesh`:

```python
            dev = str(item.get("dev", "") or "").strip()
```

And for the string case, add:

```python
            dev = ""
```

In the `desired_entries.append()` call, add `"dev": dev`:

```python
        desired_entries.append(
            {
                "address": address,
                "backup_only": backup_only,
                "signal": signal,
                "subflow": subflow,
                "fullmesh": fullmesh,
                "dev": dev,
            }
        )
```

- [ ] **Step 2: Use per-entry `dev` in the deletion pass**

In the deletion pass (around lines 421-435), the orphan cleanup currently checks `entry.get("dev", "") == endpoint_device`. We need to also match per-entry devices. Replace the orphan cleanup block:

```python
        if expected_entry is None:
            if endpoint_device != "" and entry.get("dev", "") == endpoint_device:
                run_command(["ip", "mptcp", "endpoint", "delete", "id", str(endpoint_id)], check=False)
            continue
```

With:

```python
        if expected_entry is None:
            # Clean up orphan endpoints that belong to any of our devices
            entry_dev = entry.get("dev", "")
            all_our_devices = {endpoint_device} if endpoint_device else set()
            all_our_devices.update(e.get("dev", "") for e in desired_entries if e.get("dev", ""))
            if entry_dev and entry_dev in all_our_devices:
                run_command(["ip", "mptcp", "endpoint", "delete", "id", str(endpoint_id)], check=False)
            continue
```

For the stale detection (dev mismatch), change:

```python
        elif endpoint_device != "" and entry.get("dev", "") != endpoint_device:
```

to:

```python
        else:
            expected_dev = expected_entry.get("dev", "") or endpoint_device
            if expected_dev != "" and entry.get("dev", "") != expected_dev:
                run_command(["ip", "mptcp", "endpoint", "delete", "id", str(endpoint_id)], check=False)
```

- [ ] **Step 3: Use per-entry `dev` in the creation pass**

In the creation pass (around lines 437-462), replace the matching check's device condition:

```python
                and (endpoint_device == "" or entry.get("dev", "") == endpoint_device)
```

With:

```python
                and (entry.get("dev", "") == (desired_entry.get("dev", "") or endpoint_device) or (desired_entry.get("dev", "") == "" and endpoint_device == ""))
```

And replace the device appending:

```python
        if endpoint_device != "":
            parts.extend(["dev", endpoint_device])
```

With:

```python
        ep_dev = desired_entry.get("dev", "") or endpoint_device
        if ep_dev != "":
            parts.extend(["dev", ep_dev])
```

- [ ] **Step 4: Commit**

```bash
git add client-agent/leikwan_mptcp_client.py
git commit -m "feat(client-agent): per-endpoint dev binding in configure_mptcp_policy"
```

---

## Task 5: Sidecar Agent — multi-underlay support + per-endpoint `dev`

**Files:**
- Modify: `sidecar-agent/leikwan_mptcp_sidecar.py:208-241` (apply_wireguard_transit_underlay)
- Modify: `sidecar-agent/leikwan_mptcp_sidecar.py:275-286` (apply_underlay)
- Modify: `sidecar-agent/leikwan_mptcp_sidecar.py:288-441` (configure_mptcp_policy)
- Modify: `sidecar-agent/leikwan_mptcp_sidecar.py:703` (call site in main_async)

Mirror the client agent changes from Tasks 3 and 4.

- [ ] **Step 1: Rename `apply_wireguard_transit_underlay` to `_single`**

In `sidecar-agent/leikwan_mptcp_sidecar.py`, change line 208:

```python
def apply_wireguard_transit_underlay(config: dict[str, Any]) -> None:
```

to:

```python
def apply_wireguard_transit_underlay_single(config: dict[str, Any]) -> None:
```

- [ ] **Step 2: Add `apply_wireguard_transit_underlays` wrapper and cleanup function**

Insert before the renamed function (before line 208):

```python
def apply_wireguard_transit_underlays(underlays: list[dict[str, Any]], parent_config: dict[str, Any]) -> None:
    """Create N WG interfaces, one per underlay entry (one leg each)."""
    if os.name == "nt":
        return
    active_iface_names: set[str] = set()
    for underlay in underlays:
        if not isinstance(underlay, dict):
            continue
        merged = dict(underlay)
        for key in ("local_private_key_path", "initcwnd", "initrwnd"):
            if key not in merged and key in parent_config:
                merged[key] = parent_config[key]
        apply_wireguard_transit_underlay_single(merged)
        name = str(merged.get("interface_name", "") or "").strip()
        if name:
            active_iface_names.add(name)
    cleanup_stale_transit_interfaces(parent_config, active_iface_names)


def cleanup_stale_transit_interfaces(config: dict[str, Any], active_names: set[str]) -> None:
    """Remove WG interfaces from previous config that are no longer needed."""
    if os.name == "nt" or not shutil_which("wg"):
        return
    try:
        result = run_command(["wg", "show", "interfaces"], check=False)
        current_interfaces = result.stdout.strip().split()
    except Exception:
        return
    for iface in current_interfaces:
        if iface.startswith("lw-sc-") and iface not in active_names:
            logging.info("removing stale transit interface: %s", iface)
            if shutil_which("systemctl"):
                run_command(["systemctl", "stop", f"wg-quick@{iface}.service"], check=False)
                run_command(["systemctl", "disable", f"wg-quick@{iface}.service"], check=False)
            elif shutil_which("wg-quick"):
                run_command(["wg-quick", "down", iface], check=False)
            conf_path = pathlib.Path(f"/etc/wireguard/{iface}.conf")
            if conf_path.exists():
                conf_path.unlink()
```

Note: sidecar cleanup matches `lw-sc-` prefix (not `lw-mptcp-`).

- [ ] **Step 3: Add `apply_underlays` dispatcher**

Insert before the existing `apply_underlay` (line 275):

```python
def apply_underlays(config: dict[str, Any]) -> None:
    """Dispatch underlay setup: supports both old 'underlay' and new 'underlays' formats."""
    underlays = config.get("underlays", [])
    if isinstance(underlays, list) and underlays:
        apply_wireguard_transit_underlays(underlays, config)
        return
    underlay = config.get("underlay", {})
    if isinstance(underlay, dict):
        apply_underlay(underlay)
```

- [ ] **Step 4: Update `main_async` call site**

In `sidecar-agent/leikwan_mptcp_sidecar.py`, line 703:

```python
    apply_underlay(config.get("underlay", {}) if isinstance(config.get("underlay", {}), dict) else {})
```

Replace with:

```python
    apply_underlays(config)
```

- [ ] **Step 5: Apply per-endpoint `dev` changes to `configure_mptcp_policy`**

Apply the exact same changes as Task 4 Steps 1-3 to `sidecar-agent/leikwan_mptcp_sidecar.py`'s `configure_mptcp_policy` (lines 288-441). The code is structurally identical to the client version.

The changes are:
1. Parse `dev` from each endpoint entry dict (around line 322)
2. Include `"dev": dev` in `desired_entries.append()` 
3. Update orphan cleanup to match any of our devices (around line 407)
4. Update stale detection to use per-entry dev (around line 412)
5. Update matching check in creation pass (around line 423)
6. Use per-entry dev in `ip mptcp endpoint add` (around line 430)

- [ ] **Step 6: Commit**

```bash
git add sidecar-agent/leikwan_mptcp_sidecar.py
git commit -m "feat(sidecar-agent): multi-underlay support + per-endpoint dev binding"
```

---

## Task 6: Sidecar Agent — update `sidecar_agent.py` config mapping for `underlays`

**Files:**
- Modify: `sidecar-agent/sidecar_agent.py:196-217` (apply_management method)

The `sidecar_agent.py` `apply_management()` method (line 196) extracts `underlay` from the Panel response, injects `local_private_key_path` and `listen_port`, then passes it to the runtime config. For the new `underlays[]` format, we must:
1. Extract `underlays` from the Panel response
2. Inject `local_private_key_path` and `listen_port` into **each** underlay entry (each needs a unique listen port)
3. Pass both `underlays` and the old `underlay` (for backward compat)

- [ ] **Step 1: Add `underlays` extraction and private-key/port injection**

In `sidecar-agent/sidecar_agent.py`, find `apply_management()` (line 196). After line 201:

```python
        underlay = dict(management.get("underlay", {})) if isinstance(management.get("underlay", {}), dict) else {}
        if underlay != {}:
            underlay["local_private_key_path"] = str(self.wireguard_private_key_path())
            underlay.setdefault("listen_port", self.wireguard_listen_port())
        mptcp_policy = dict(management.get("mptcp_policy", {})) if isinstance(management.get("mptcp_policy", {}), dict) else {}
```

Add after it (before `runtime_config = {`):

```python
        # Handle new multi-interface underlays format
        raw_underlays = management.get("underlays", [])
        underlays = []
        if isinstance(raw_underlays, list):
            base_listen_port = self.wireguard_listen_port()
            for idx, raw_ul in enumerate(raw_underlays):
                if not isinstance(raw_ul, dict):
                    continue
                ul = dict(raw_ul)
                ul["local_private_key_path"] = str(self.wireguard_private_key_path())
                ul.setdefault("listen_port", base_listen_port + idx)
                underlays.append(ul)
```

- [ ] **Step 2: Add `underlays` to the runtime_config dict**

In the `runtime_config = {` block (line 203), after the `"underlay"` line (line 211):

```python
            "underlay": underlay if underlay != {} else self.config.get("underlay", {}),
```

Add:

```python
            "underlays": underlays,
```

The `mptcp_endpoint_device` line stays as-is — it will naturally be empty string when the Panel no longer sends `endpoint_device` in `mptcp_policy`. The per-endpoint `dev` fields pass through in the `endpoints` list.

- [ ] **Step 3: Commit**

```bash
git add sidecar-agent/sidecar_agent.py
git commit -m "feat(sidecar-agent): extract and inject underlays[] with per-interface private key and listen port"
```

---

## Task 7: Client Agent — update `install_forward_runtime.py` config mapping for `underlays`

**Files:**
- Modify: `client-agent/install_forward_runtime.py:345-377`

The `install_forward_runtime.py` builds the JSON config that the MPTCP client runtime reads. Currently it extracts `underlay` from the Panel heartbeat response and injects `local_private_key_path` and `listen_port`. For multi-interface, we must do the same for each entry in `underlays[]`.

- [ ] **Step 1: Add `underlays` extraction and injection**

In `client-agent/install_forward_runtime.py`, find the config_payload dict (around line 346-374). After line 373:

```python
            "underlay": dict(mptcp.get("underlay", {})) if isinstance(mptcp.get("underlay", {}), dict) else {},
```

Add:

```python
            "underlays": [],
```

Then after line 377 (the `underlay` private key injection block):

```python
        if isinstance(config_payload["underlay"], dict):
            config_payload["underlay"]["local_private_key_path"] = str(pathlib.Path(str(agent_config.get("state_dir", "/var/lib/leikwan-client-agent"))) / "wireguard" / "private.key")
            config_payload["underlay"].setdefault("listen_port", int_or_default(agent_config.get("wireguard_listen_port"), 0))
```

Add the multi-underlay injection:

```python
        # Inject private key and listen port into each underlay for multi-interface mode
        raw_underlays = mptcp.get("underlays", [])
        if isinstance(raw_underlays, list):
            base_listen_port = int_or_default(agent_config.get("wireguard_listen_port"), 0)
            privkey_path = str(pathlib.Path(str(agent_config.get("state_dir", "/var/lib/leikwan-client-agent"))) / "wireguard" / "private.key")
            injected_underlays = []
            for idx, raw_ul in enumerate(raw_underlays):
                if not isinstance(raw_ul, dict):
                    continue
                ul = dict(raw_ul)
                ul["local_private_key_path"] = privkey_path
                ul.setdefault("listen_port", base_listen_port + idx if base_listen_port > 0 else 0)
                injected_underlays.append(ul)
            config_payload["underlays"] = injected_underlays
```

- [ ] **Step 2: Update `client_agent.py` `mptcp_remote_hosts` for `underlays[]`**

In `client-agent/client_agent.py`, find `mptcp_remote_hosts` (line 1051). It reads `mptcp.get("underlay", {})` to extract remote hosts. Replace lines 1061-1067:

```python
        underlay = mptcp.get("underlay", {})
        if isinstance(underlay, dict):
            legs = underlay.get("legs", [])
            if isinstance(legs, list):
                for leg in legs:
                    if isinstance(leg, dict):
                        add_host(leg.get("mptcp_remote_address"))
```

With:

```python
        underlays = mptcp.get("underlays", [])
        if not isinstance(underlays, list) or not underlays:
            underlay = mptcp.get("underlay", {})
            underlays = [underlay] if isinstance(underlay, dict) else []
        for underlay in underlays:
            if not isinstance(underlay, dict):
                continue
            legs = underlay.get("legs", [])
            if isinstance(legs, list):
                for leg in legs:
                    if isinstance(leg, dict):
                        add_host(leg.get("mptcp_remote_address"))
```

- [ ] **Step 3: Commit**

```bash
git add client-agent/install_forward_runtime.py client-agent/client_agent.py
git commit -m "feat(client-agent): inject private key into underlays[] and support new format in host probing"
```

---

## Task 8: Rust Relay — read `underlays[]` for per-interface endpoint derivation

**Files:**
- Modify: `wstunnel-main/leikwan-mptcp-relay/src/config.rs:7-57`
- Modify: `wstunnel-main/leikwan-mptcp-relay/src/main.rs:55-67` (derive call site)
- Modify: `wstunnel-main/leikwan-mptcp-relay/src/main.rs:147-185` (derive_subflow_endpoints_from_underlay)

- [ ] **Step 1: Add `underlays` field to `ClientConfig`**

In `wstunnel-main/leikwan-mptcp-relay/src/config.rs`, after the `underlay` field (line 43):

```rust
    #[serde(default)]
    pub underlay: serde_json::Value,
```

Add:

```rust
    #[serde(default)]
    pub underlays: Vec<serde_json::Value>,
```

- [ ] **Step 2: Rewrite `derive_subflow_endpoints_from_underlay` to handle `underlays[]`**

In `wstunnel-main/leikwan-mptcp-relay/src/main.rs`, replace the function `derive_subflow_endpoints_from_underlay` (lines 147-185) with:

```rust
fn derive_subflow_endpoints_from_underlays(
    underlays: &[serde_json::Value],
    fallback_underlay: &serde_json::Value,
    fallback_device: &str,
    id_base: u32,
) -> Vec<endpoint::EndpointEntry> {
    // Use underlays[] if non-empty, else fall back to single underlay
    let effective: Vec<&serde_json::Value> = if !underlays.is_empty() {
        underlays.iter().collect()
    } else if fallback_underlay.is_object() {
        vec![fallback_underlay]
    } else {
        return Vec::new();
    };

    let mut seen = std::collections::HashSet::new();
    let mut endpoints = Vec::new();

    for underlay in &effective {
        let iface_name = underlay
            .get("interface_name")
            .and_then(|v| v.as_str())
            .unwrap_or(fallback_device);

        let legs = match underlay.get("legs").and_then(|l| l.as_array()) {
            Some(arr) => arr,
            None => continue,
        };

        for leg in legs {
            let addr = match leg.get("local_address").and_then(|a| a.as_str()) {
                Some(a) => a.to_string(),
                None => continue,
            };
            if seen.contains(&addr) {
                continue;
            }
            seen.insert(addr.clone());

            let id = id_base + endpoints.len() as u32 + 1;
            endpoints.push(endpoint::EndpointEntry {
                address: addr,
                flags: vec!["subflow".to_string()],
                device: iface_name.to_string(),
                id,
            });
        }
    }

    endpoints
}
```

Key change: each endpoint gets `device` from its underlay's `interface_name`, not from a single global device string.

- [ ] **Step 3: Update the call site in `main()`**

In `wstunnel-main/leikwan-mptcp-relay/src/main.rs`, find lines 55-67:

```rust
                if endpoints.is_empty() {
                    endpoints = derive_subflow_endpoints_from_underlay(
                        &cfg.underlay,
                        &cfg.mptcp_endpoint_device,
                        cfg.mptcp_endpoint_id_base,
                    );
```

Replace with:

```rust
                if endpoints.is_empty() {
                    endpoints = derive_subflow_endpoints_from_underlays(
                        &cfg.underlays,
                        &cfg.underlay,
                        &cfg.mptcp_endpoint_device,
                        cfg.mptcp_endpoint_id_base,
                    );
```

- [ ] **Step 4: Build and verify compilation**

```bash
cd wstunnel-main/leikwan-mptcp-relay && cargo build --release --target x86_64-unknown-linux-musl
```

Expected: compiles successfully with no errors.

- [ ] **Step 5: Commit**

```bash
git add wstunnel-main/leikwan-mptcp-relay/src/config.rs wstunnel-main/leikwan-mptcp-relay/src/main.rs
git commit -m "feat(relay): derive endpoints from underlays[] with per-interface device names"
```

---

## Task 9: Phase 1 Manual Validation Script

**Files:**
- Create: `scripts/manual-multi-iface-test.sh`

Create a script that can be run on the client and sidecar machines to manually split a single-interface WG config into N interfaces for testing, without requiring Panel/agent changes.

- [ ] **Step 1: Create the manual test script**

```bash
#!/bin/bash
# manual-multi-iface-test.sh
# Usage: ./manual-multi-iface-test.sh <forward_id>
# Run on client machine to split lw-mptcp-{fwdId} into per-leg interfaces.
# Requires: the current config JSON at /tmp/mptcp-config.json

set -euo pipefail

FWD_ID="${1:?Usage: $0 <forward_id>}"
IFACE="lw-mptcp-${FWD_ID}"
CONFIG_JSON="/tmp/mptcp-config.json"

if [ ! -f "$CONFIG_JSON" ]; then
    echo "ERROR: Place the forward's MPTCP JSON config at $CONFIG_JSON"
    exit 1
fi

echo "=== Step 1: Stop current single interface ==="
wg-quick down "$IFACE" 2>/dev/null || true
systemctl stop "wg-quick@${IFACE}.service" 2>/dev/null || true

echo "=== Step 2: Flush existing MPTCP endpoints ==="
ip mptcp endpoint flush 2>/dev/null || true

echo "=== Step 3: Read legs from config ==="
LEGS=$(python3 -c "
import json, sys
cfg = json.load(open('$CONFIG_JSON'))
underlay = cfg.get('underlay', {})
legs = underlay.get('legs', [])
privkey_path = underlay.get('local_private_key_path', '')
keepalive = underlay.get('peer_keepalive_seconds', 25)
initcwnd = cfg.get('initcwnd', 80)
initrwnd = cfg.get('initrwnd', 80)
for i, leg in enumerate(legs):
    print(json.dumps({
        'idx': i,
        'local_address': leg['local_address'],
        'peer_address': leg['peer_address'],
        'mptcp_remote_address': leg['mptcp_remote_address'],
        'peer_public_key': leg['peer_public_key'],
        'peer_public_host': leg['peer_public_host'],
        'peer_udp_port': leg['peer_udp_port'],
        'outer_transport': leg.get('outer_transport', 'udp'),
        'privkey_path': privkey_path,
        'keepalive': keepalive,
        'initcwnd': initcwnd,
        'initrwnd': initrwnd,
    }))
")

echo "=== Step 4: Create per-leg interfaces ==="
PRIVKEY=""
IDX=0
echo "$LEGS" | while IFS= read -r leg; do
    IDX=$(echo "$leg" | python3 -c "import json,sys; print(json.load(sys.stdin)['idx'])")
    LOCAL=$(echo "$leg" | python3 -c "import json,sys; print(json.load(sys.stdin)['local_address'])")
    PEER=$(echo "$leg" | python3 -c "import json,sys; print(json.load(sys.stdin)['peer_address'])")
    REMOTE=$(echo "$leg" | python3 -c "import json,sys; print(json.load(sys.stdin)['mptcp_remote_address'])")
    PUBKEY=$(echo "$leg" | python3 -c "import json,sys; print(json.load(sys.stdin)['peer_public_key'])")
    HOST=$(echo "$leg" | python3 -c "import json,sys; print(json.load(sys.stdin)['peer_public_host'])")
    PORT=$(echo "$leg" | python3 -c "import json,sys; print(json.load(sys.stdin)['peer_udp_port'])")
    PRIVKEY_PATH=$(echo "$leg" | python3 -c "import json,sys; print(json.load(sys.stdin)['privkey_path'])")
    KEEPALIVE=$(echo "$leg" | python3 -c "import json,sys; print(json.load(sys.stdin)['keepalive'])")
    INITCWND=$(echo "$leg" | python3 -c "import json,sys; print(json.load(sys.stdin)['initcwnd'])")
    INITRWND=$(echo "$leg" | python3 -c "import json,sys; print(json.load(sys.stdin)['initrwnd'])")
    
    NEW_IFACE="${IFACE}-${IDX}"
    CONF="/etc/wireguard/${NEW_IFACE}.conf"
    
    PRIVKEY=$(cat "$PRIVKEY_PATH")
    
    cat > "$CONF" <<WGEOF
[Interface]
PrivateKey = ${PRIVKEY}
Address = ${LOCAL}/32
PostUp = ip -4 route replace ${REMOTE}/32 dev ${NEW_IFACE} src ${LOCAL} initcwnd ${INITCWND} initrwnd ${INITRWND}
PostUp = ip -4 route replace ${PEER}/32 dev ${NEW_IFACE} src ${LOCAL} initcwnd ${INITCWND} initrwnd ${INITRWND}
PostDown = ip -4 route del ${REMOTE}/32 dev ${NEW_IFACE} >/dev/null 2>&1 || true
PostDown = ip -4 route del ${PEER}/32 dev ${NEW_IFACE} >/dev/null 2>&1 || true

[Peer]
PublicKey = ${PUBKEY}
Endpoint = ${HOST}:${PORT}
AllowedIPs = ${PEER}/32, ${REMOTE}/32
PersistentKeepalive = ${KEEPALIVE}
WGEOF
    
    chmod 600 "$CONF"
    wg-quick up "${NEW_IFACE}"
    echo "  Created ${NEW_IFACE} -> ${HOST}:${PORT} (local=${LOCAL})"
    
    # Register MPTCP endpoint with dev binding
    EP_ID=$((100 + IDX))
    ip mptcp endpoint add "${LOCAL}" id "${EP_ID}" dev "${NEW_IFACE}" subflow
    echo "  MPTCP endpoint: ${LOCAL} id=${EP_ID} dev=${NEW_IFACE} subflow"
done

echo ""
echo "=== Step 5: Set MPTCP limits ==="
NLEGS=$(echo "$LEGS" | wc -l)
ip mptcp limits set subflow "$NLEGS" add_addr_accepted "$NLEGS"

echo ""
echo "=== Verification ==="
echo "--- WG interfaces ---"
wg show interfaces
echo ""
echo "--- MPTCP endpoints ---"
ip mptcp endpoint show
echo ""
echo "--- Test: run iperf and check subflows ---"
echo "  ss -M | grep ESTAB"
```

- [ ] **Step 2: Commit**

```bash
git add scripts/manual-multi-iface-test.sh
chmod +x scripts/manual-multi-iface-test.sh
git commit -m "feat: add manual multi-interface WG test script for Phase 1 validation"
```

---

## Task 10: Integration Verification

After all code tasks are complete, verify the end-to-end flow.

- [ ] **Step 1: Verify Panel output format**

Add a temporary debug line in `buildMptcpClientConfig()` to log the output:

```php
error_log('MPTCP client config: ' . json_encode($result, JSON_PRETTY_PRINT));
```

Then trigger a config generation for forward 20 and verify:
- `underlays` is an array with N entries (one per slave leg)
- Each `underlays[].interface_name` is `lw-mptcp-20-{idx}`
- Each `underlays[].legs` has exactly 1 entry
- `mptcp_endpoints` has N entries, each with `dev` matching the corresponding interface
- `mptcp_endpoint_device` is absent

- [ ] **Step 2: Verify sidecar output format**

Similarly log `mptcpSidecarManagementForPoint()` output and verify:
- `underlays` array with per-leg interfaces named `lw-sc-{pointId}-{idx}`
- `mptcp_policy.endpoints` each has `dev` and `signal: true`
- `mptcp_policy.endpoint_device` is absent

- [ ] **Step 3: Remove debug logging**

Remove the temporary `error_log` lines added in steps 1-2.

- [ ] **Step 4: Final commit**

```bash
git add -A
git commit -m "chore: remove debug logging from Panel MPTCP config generation"
```

---

## Deployment Order (after all tasks complete)

1. **Deploy agent code** (client + sidecar) — they accept both old and new formats
2. **Deploy Rust relay binary** — reads `underlays[]` with fallback
3. **Deploy Panel code** — starts emitting new `underlays[]` format
4. **Run `php cli/republish-mptcp.php`** — pushes new configs to all agents
5. **Verify** on forward 20: `wg show interfaces` should list N interfaces, `ip mptcp endpoint show` should show N endpoints with different `dev`, `ss -M` should show N subflows ESTAB
