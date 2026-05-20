# CFWG Forward 47 — Fixing Suggestions for the Next Agent

> This document is the actionable companion to `08-complete-implementation-plan.md`.
> It tells the next AI agent exactly what to do, in what order, with what commands,
> and what to look for in the output. Every step is executable.
> Date: 2026-05-20 | Branch: `codex/cfwg47-assessment-handoff-20260520`

---

## 0. Situation Summary for the Next Agent

Forward 47 (`lw-cfwg-47`) on `sh36` (114.80.33.50) broke because the panel pushed a newer
`install_forward_runtime.py` / `client_agent.py` that rendered the CFWG wstunnel command
differently from the known-good local build. The symptom was wstunnel returning HTTP 400/520
on the WebSocket upgrade, meaning the sidecar rejected the path. The fix was to restore the
older files and pin `disable_auto_update=true` on client id=3 and sidecar point id=5.

The root cause has NOT been identified at the code level. This document tells you how to
find it and fix it. You have access to the full repo at `leikwan-br1/`. You do NOT have
SSH to `sh36` or the panel host — those steps are marked `[OPERATOR]` and must be handed
off to a human operator.

---

## 1. Find the Root Cause (the diff between working and broken builds)

### 1.1 The three lineages you must understand

| Lineage | Description | sha256 of `client_agent.py` | sha256 of `install_forward_runtime.py` |
|---------|-------------|-------|-------|
| **A: Manifest (declared)** | What `artifacts/versions.json` says | `75354f40397f64d78e334e256615547d7f44203fe920323fba90610753ccec5f` | `f88c4c2f763a0d58ba49a4ea4f1a874eecac51cf3a54a4bc84c63bb22f5f9c24` |
| **B: In-tree (current repo HEAD)** | What's checked into git right now | `ebae5089c2a33683421607428ec9e4f3055465cb61226ae663781d45173da5ce` | `9341ed7713fb8d76db85bfc75b0a21b77e97af81035139c49b38dfb34817d018` |
| **C: Live restored (working on sh36)** | The files that actually make forward 47 work | `3abb7ecd4c572d3bcc18915b3ba0bdb8cf598ffcf3baf36b10288dc27c9cbf64` | `2e4c0c883e12901a56c85c9cca38941a4492703837d6b66a8f309ab2a9715548` |

The bug is in the diff between **C** (working) and whichever of **A** or **B** the panel
actually served to sh36 before the break. The panel serves from
`/www/wwwroot/v3.leikwanhost.com/leikwan-v3/client-agent/`. Its hash is almost certainly
lineage **A** (the manifest-declared version) because that's what the panel updater pushes.

### 1.2 What to diff

The agent that has access to the panel host (`/www/wwwroot/v3.leikwanhost.com/leikwan-v3/`)
or to a copy of the files at manifest hash **A** must run:

```bash
# [OPERATOR] On the panel host or wherever lineage A files are accessible:
diff -u \
  /root/leikwan-mptcp-leg-priority-sh36-20260519-005901/install_forward_runtime.py \
  /www/wwwroot/v3.leikwanhost.com/leikwan-v3/client-agent/install_forward_runtime.py \
  > /tmp/install_forward_runtime_diff.txt

diff -u \
  /root/leikwan-mptcp-leg-priority-sh36-20260519-005901/leikwan-client-agent.py \
  /www/wwwroot/v3.leikwanhost.com/leikwan-v3/client-agent/client_agent.py \
  > /tmp/client_agent_diff.txt
```

### 1.3 What to look for in the diff

The bug is almost certainly in ONE of these locations. Check them in this order (most likely first):

#### Location 1: `install_forward_runtime.py` → `self_leg_wg_underlay()`

This function rewrites the WireGuard endpoint and builds the wstunnel client command line.
The working command (from handoff) is:

```text
/usr/local/bin/wstunnel client \
  -L udp://127.0.0.1:40047:127.0.0.1:14969?timeout_sec=0 \
  --tls-sni-override nrt1.nbwly.com \
  --http-headers Host: nrt1.nbwly.com \
  --http-upgrade-path-prefix 07289d1f5ed25399.cfwg \
  wss://162.159.39.5:2053
```

Look for ANY change in the newer build that alters:
- The `--http-upgrade-path-prefix` value (e.g., adding a leading `/`, dropping the `.cfwg` suffix, URL-encoding it)
- The `-L udp://` target address or port (must be `127.0.0.1:40047:127.0.0.1:14969`)
- The `wss://` URL (must point to `162.159.39.5:2053` or whatever `cf_dial_host:cf_dial_port` the panel publishes)
- The `--tls-sni-override` value (must match `cf_sni_host`)

If the diff shows a change to how `cf_path_prefix` is consumed (e.g., the newer build
strips a suffix, prepends a slash, or URL-encodes it), that is the bug.

#### Location 2: `install_forward_runtime.py` → `render_forward_runtime()`

This function assembles the full start script. Look for:
- Changes to the order of wstunnel flags
- Changes to how `cf_target_port` is captured before the local UDP override
- Changes to the systemd unit template that would prevent the service from starting correctly
- Changes to how the `outer_transport` field is matched (the cf_wg branch is entered when `outer_transport == 'wstunnel_udp'`)

#### Location 3: `client_agent.py` → `apply_forward()`

This function decides whether to re-render. Look for:
- Changes to the `write_text_if_changed()` logic that could make it think the runtime is already correct when it isn't
- Changes to what fields are included in the forward dict passed to `render_forward_runtime()`
- Changes to the `load_applied()` / save path that could skip a needed re-render
- New fields added to the forward dict that the newer `install_forward_runtime.py` expects but the panel doesn't provide

#### Location 4: `client_agent.py` → `select_forward_candidates()` or `apply_latency_target_candidate_escalation()`

Less likely but possible: the newer build changes candidate selection in a way that picks
a different `cf_dial_host` or `cf_path_prefix` from the candidate pool. Look for:
- Changes to how `self_leg_cf_candidates` is iterated
- Changes to the ordering or filtering of candidates
- New fallback behavior that picks a different IP when the panel-published list is empty

### 1.4 If you have access to git history

The repo's git log is shallow (3 commits on this branch). But if the panel host has a git
repo at `/www/wwwroot/v3.leikwanhost.com/leikwan-v3/`, you can find the exact commit that
broke it:

```bash
# [OPERATOR] On the panel host:
cd /www/wwwroot/v3.leikwanhost.com/leikwan-v3
git log --oneline --since="2026-05-17" --until="2026-05-20" -- client-agent/install_forward_runtime.py
git log --oneline --since="2026-05-17" --until="2026-05-20" -- client-agent/client_agent.py
```

The breaking commit is the one between the known-good timestamp (~2026-05-18 23:00 UTC)
and the time the user reported failure.

---

## 2. The Three Most Probable Bugs (and how to fix each)

### Bug Hypothesis A: `cf_path_prefix` value corruption

**Symptom:** wstunnel gets HTTP 400/520 because the path the client sends doesn't match
what the sidecar expects.

**How to confirm:** In the diff from step 1.3, look for ANY change to how `cf_path_prefix`
is read from the forward dict or passed to the wstunnel command line in `self_leg_wg_underlay()`.

Common corruption patterns:
- Adding a leading `/` → sidecar expects `07289d1f5ed25399.cfwg`, client sends `/07289d1f5ed25399.cfwg`
- URL-encoding the dot → sidecar expects `.cfwg`, client sends `%2ecfwg`
- Dropping the `.cfwg` suffix → sidecar expects `07289d1f5ed25399.cfwg`, client sends `07289d1f5ed25399`
- Reading from wrong dict key → e.g., reading `path_prefix` instead of `cf_path_prefix`

**Fix:** Revert the specific line that changed how `cf_path_prefix` is consumed. The correct
behavior is:

```python
# In self_leg_wg_underlay() or render_forward_runtime():
cf_path_prefix = str(underlay.get("cf_path_prefix", "") or "").strip()
# Use cf_path_prefix directly in the command line, no transformation:
f"--http-upgrade-path-prefix {cf_path_prefix}"
```

No leading slash. No URL encoding. No suffix manipulation. The panel already appends `.cfwg`
at `ProvisioningService.php:3012`.

**Test after fix:**
```python
# In test_cfwg_forward_47_render_diff.py:
self.assertIn("--http-upgrade-path-prefix 0123456789abcdef.cfwg", rendered["start_script"])
self.assertNotIn("--http-upgrade-path-prefix /", rendered["start_script"])
```

### Bug Hypothesis B: `applied.json` signature change causes skip

**Symptom:** The client agent receives the correct forward dict from the panel but doesn't
re-render because it thinks the runtime is already applied.

**How to confirm:** In the diff from step 1.3, look for changes to `apply_forward()` that
alter what triggers a re-render. The current working logic is:

```python
# client_agent.py:1044 apply_forward calls render_forward_runtime
# client_agent.py:121 write_text_if_changed compares file content byte-for-byte
# If ANY rendered file differs from on-disk → rewrite + restart
```

If the newer build added a new intermediate cache, changed the comparison logic, or added
a "skip if hash matches" shortcut BEFORE calling `render_forward_runtime()`, that could
cause the client to skip a needed re-render.

**Fix:** The `apply_forward()` path must ALWAYS call `render_forward_runtime()` and let
`write_text_if_changed()` decide file-by-file whether to rewrite. No early-exit based on
a top-level signature. The handoff confirms this: "The applied-state signatures were
cleared/neutralized for the restored runtime so the client would not incorrectly treat
the old broken runtime as a no-op."

If the newer build added a top-level signature check, remove it:

```python
# WRONG (if this was added in the newer build):
if self._forward_signature(forward) == applied.get(str(forward_id), {}).get("signature"):
    return  # skip — THIS IS THE BUG

# CORRECT (always render, let write_text_if_changed gate the actual disk write):
rendered = render_forward_runtime(forward, {})
for file_entry in rendered.get("files", []):
    self.write_text_if_changed(pathlib.Path(file_entry["path"]), file_entry["content"])
```

**Test after fix:**
```python
# In a new test:
# Seed applied.json with a stale signature for forward 47
# Call apply_forward with a forward dict that has a different cf_path_prefix
# Assert that the rendered start_script was actually written to disk
# (i.e., write_text_if_changed was called and returned True)
```

### Bug Hypothesis C: Candidate selection picks wrong CF dial host

**Symptom:** The wstunnel client connects to a different IP or port than expected, and
the CF edge at that IP doesn't have a route to the sidecar.

**How to confirm:** In the diff, look for changes to `select_forward_candidates()` (line 2269)
that alter which candidate is selected as the primary. The working configuration uses:
- `cf_dial_host = 162.159.39.5`
- `cf_dial_port = 2053`
- `cf_sni_host = nrt1.nbwly.com`

If the newer build changes the selection logic (e.g., prefers a different candidate from
`self_leg_cf_candidates`, or falls back to a default that doesn't match the sidecar's
published route), the wstunnel client would connect to an edge that doesn't know about
the sidecar, returning 520 (origin unreachable).

**Fix:** Ensure the candidate selection for cf_wg forwards uses the panel-published
`cf_dial_host` / `cf_dial_port` / `cf_sni_host` directly from the `self_leg_wg` block,
not from a generic candidate pool fallback. The correct read path is:

```python
underlay = candidate.get("self_leg_wg", {})
cf_dial_host = str(underlay.get("cf_dial_host", "") or "")
cf_dial_port = int(underlay.get("cf_dial_port", 0) or 0)
cf_sni_host = str(underlay.get("cf_sni_host", "") or "")
```

**Test after fix:**
```python
# In test_cfwg_forward_47_render_diff.py:
self.assertIn("wss://192.0.2.5:2053", rendered["start_script"])  # scrubbed IP
self.assertIn("--tls-sni-override edge.example.test", rendered["start_script"])  # scrubbed SNI
```

---

## 3. Sidecar-Side Verification

### 3.1 Check the sidecar route table

```bash
# [OPERATOR] On the sidecar host for point id=5:
cat /var/lib/leikwan-sidecar-agent/cf-routes-14969.json | python3 -m json.tool
```

Expected output must contain a route like:

```json
{
  "path_prefix": "07289d1f5ed25399.cfwg",
  "backend_host": "127.0.0.1",
  "backend_port": 26000,
  "target": "127.0.0.1:14969",
  "transport_mode": "udp",
  "service_name": "cf-forward-47-cfwg"
}
```

If this entry is MISSING → the sidecar never received the route from the panel. Check:
```bash
# [OPERATOR]
journalctl -u leikwan-sidecar-agent.service --since "2026-05-19" | grep -i "cf-forward-47"
```

If the entry EXISTS but `path_prefix` differs from `07289d1f5ed25399.cfwg` → that's the
sidecar-side half of the mismatch. The panel is publishing a different path than the client
expects.

### 3.2 Check the per-forward wstunnel server backend

```bash
# [OPERATOR]
systemctl status cf-forward-47-cfwg.service
systemctl cat cf-forward-47-cfwg.service
```

Expected unit contains:
```text
ExecStart=/usr/local/bin/wstunnel server ws://127.0.0.1:<backend_port> \
  --restrict-to 127.0.0.1:14969 \
  --restrict-http-upgrade-path-prefix 07289d1f5ed25399.cfwg \
  --websocket-ping-frequency-sec 5
```

If `--restrict-http-upgrade-path-prefix` shows a DIFFERENT value than what the client
sends (`07289d1f5ed25399.cfwg`), that is the mismatch causing the 400 error.

### 3.3 Check the mux is running and listening

```bash
# [OPERATOR]
ss -tlnp | grep 2053
systemctl status leikwan-bond-sidecar-5-cf-14969.service
```

If port 2053 is not listening → the mux is down and ALL CF-routed forwards through this
sidecar are broken, not just forward 47.

---

## 4. The Panel Database Check

### 4.1 Verify `forwards.path_prefix` for forward 47

```sql
-- [OPERATOR] Run on the panel database:
SELECT id, path_prefix, backend_mode, forward_combination_id
FROM forwards
WHERE id = 47;
```

Expected: `path_prefix = '07289d1f5ed25399'` (no leading slash, no `.cfwg` suffix).

The panel appends `.cfwg` at `ProvisioningService.php:3012`:
```php
$wgProfile['cf_path_prefix'] = $cfBasePathPrefix . '.cfwg';
```

So the wire value should be `07289d1f5ed25399.cfwg`.

If `path_prefix` has a leading slash (`/07289d1f5ed25399`), the wire value becomes
`/07289d1f5ed25399.cfwg` which the sidecar's `--restrict-http-upgrade-path-prefix` will
NOT match (it was set up with no leading slash). This would cause the 400 error.

**Fix if leading slash found:**
```sql
-- [OPERATOR] Fix the DB row:
UPDATE forwards SET path_prefix = '07289d1f5ed25399' WHERE id = 47;
```
Then wait one heartbeat cycle for the panel to republish the route to both client and sidecar.

### 4.2 Verify `forward_combinations.self_leg_cf_transport` for forward 47's combination

```sql
-- [OPERATOR]
SELECT fc.id, fc.self_leg_cf_transport, f.forward_combination_id
FROM forward_combinations fc
JOIN forwards f ON f.forward_combination_id = fc.id
WHERE f.id = 47;
```

Expected: `self_leg_cf_transport = 'cf_wg'`.

If this is anything else (e.g., `cf_direct`, empty, null), the panel will NOT enter the
`wantCfWg` branch at `ProvisioningService.php:2995` and will NOT produce the `self_leg_wg`
block with `cf_path_prefix`. The client would then not render a wstunnel-over-WG path at all.

### 4.3 Verify sidecar point has wireguard_public_key

```sql
-- [OPERATOR]
SELECT id, agent_config_json
FROM bond_sidecar_point
WHERE id = 5;
```

The `agent_config_json` column must contain a `wireguard_public_key` field with a valid
base64 WireGuard public key. This is the key the client puts in `[Peer] PublicKey`.

From the handoff, the working value is:
```text
bBbi/7CsWMWrX/TrM/mRwTWceO3UoOIAL2S1BXLXCw4=
```

If this field is empty or null, the panel skips the `buildSelfLegWireGuardProfile` call
and forward 47 won't get a CFWG candidate at all.

---

## 5. How to Fix and Verify Without Breaking the Live Path

### 5.1 The safe sequence

```text
1. [OPERATOR] Capture current working state (backup as per handoff Priority 1)
2. [AGENT]    Run the diffs described in section 1 above
3. [AGENT]    Identify which of hypotheses A/B/C matches the diff
4. [AGENT]    Write the code fix in the repo (modify install_forward_runtime.py or client_agent.py)
5. [AGENT]    Write the regression test (per 08-complete-implementation-plan.md Phase 1)
6. [AGENT]    Run the test against synthetic fixtures → must be GREEN
7. [OPERATOR] Capture live panel payload for forward 47 (tcpdump or panel log)
8. [AGENT]    Run the test against live-captured fixtures → must be GREEN
9. [AGENT]    Regenerate artifacts/versions.json with new sha256 for the fixed file
10. [OPERATOR] Deploy the fixed file to the panel artifact tree
11. [OPERATOR] Follow the unpin runbook (08-complete-implementation-plan.md Phase 4)
```

### 5.2 The test that proves the fix works

Before deploying, the fix must pass this assertion:

```python
import importlib.util, json, pathlib

# Load the FIXED install_forward_runtime.py
spec = importlib.util.spec_from_file_location("install_forward_runtime",
    "client-agent/install_forward_runtime.py")
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

# Load the panel payload (scrubbed fixture or live capture)
forward = json.loads(pathlib.Path(
    "client-agent/tests/fixtures/cfwg-forward-47/panel-payload.json").read_text())

# Render
rendered = mod.render_forward_runtime(forward, {})

# THE CRITICAL ASSERTIONS:
start_script = rendered["start_script"]

# 1. Path prefix is correct (no leading slash, has .cfwg suffix)
assert "--http-upgrade-path-prefix 0123456789abcdef.cfwg" in start_script

# 2. Local UDP target is correct
assert "-L udp://127.0.0.1:40047:127.0.0.1:14969?timeout_sec=0" in start_script

# 3. CF dial host and SNI are correct
assert "wss://192.0.2.5:2053" in start_script
assert "--tls-sni-override edge.example.test" in start_script

# 4. No extra transformations applied to the path
assert "--http-upgrade-path-prefix /" not in start_script  # no leading slash
assert "%2e" not in start_script.lower()  # no URL encoding
```

### 5.3 The sidecar assertion

```python
# Load the FIXED sidecar_agent.py
# ... (module loading as above)

# Render the backend unit for forward 47
unit_text = agent.render_cf_backend_unit(
    service_name="cf-forward-47-cfwg",
    backend_port=26047,  # after Phase 2 deterministic port
    target="127.0.0.1:14969",
    path_prefix="0123456789abcdef.cfwg"
)

# THE CRITICAL ASSERTIONS:
assert "wstunnel server ws://127.0.0.1:26047" in unit_text
assert "--restrict-to 127.0.0.1:14969" in unit_text
assert "--restrict-http-upgrade-path-prefix 0123456789abcdef.cfwg" in unit_text
```

---

## 6. After the Fix: Manifest Regeneration

Once the code fix is committed, regenerate `artifacts/versions.json`:

```python
#!/usr/bin/env python3
"""Regenerate artifacts/versions.json for the four Python agent files."""
import hashlib, json, pathlib

MANIFEST_PATH = pathlib.Path("artifacts/versions.json")
manifest = json.loads(MANIFEST_PATH.read_text())

FILES = {
    "client_agent": pathlib.Path("client-agent/client_agent.py"),
    "install_forward_runtime": pathlib.Path("client-agent/install_forward_runtime.py"),
    "sidecar_agent": pathlib.Path("sidecar-agent/sidecar_agent.py"),
    "mptcp_client_runtime": pathlib.Path("client-agent/leikwan_mptcp_client.py"),
}

for key, path in FILES.items():
    content = path.read_bytes()
    manifest[key]["sha256"] = hashlib.sha256(content).hexdigest()
    manifest[key]["size_bytes"] = len(content)

MANIFEST_PATH.write_text(json.dumps(manifest, indent=2, ensure_ascii=False) + "\n")
print("Manifest regenerated.")
for key, path in FILES.items():
    print(f"  {key}: sha256={manifest[key]['sha256']} size={manifest[key]['size_bytes']}")
```

Commit this alongside the code fix so the manifest consistency test passes.

---

## 7. Verification Commands After Unpin

After the operator clears `disable_auto_update` per the runbook:

```bash
# [OPERATOR] Wait 60 seconds for heartbeat, then:

# Check file hashes match the new manifest
sha256sum /usr/local/bin/leikwan-client-agent.py /usr/local/bin/install_forward_runtime.py

# Check WG is alive
wg show lw-cfwg-47

# Check reachability
ping -I lw-cfwg-47 -c 5 -W 1 10.204.53.42

# Check wstunnel command line is correct
ps aux | grep "wstunnel client" | grep "forward-47\|40047"
# Must show: --http-upgrade-path-prefix 07289d1f5ed25399.cfwg

# Check services are active
systemctl is-active leikwan-client-agent.service leikwan-client-47.service
```

Success criteria (ALL must be true):
- sha256 matches manifest values
- `wg show` shows `latest handshake` and increasing `received` bytes
- ping returns 0% loss, ~50ms RTT
- wstunnel command shows correct `--http-upgrade-path-prefix`
- both services are `active`

If ANY fails → immediate rollback:
```bash
# [OPERATOR]
cp -a /root/codex-backups/cfwg47-pre-unpin-<ts>/leikwan-client-agent.py /usr/local/bin/
cp -a /root/codex-backups/cfwg47-pre-unpin-<ts>/install_forward_runtime.py /usr/local/bin/
rm -f /var/lib/leikwan-client-agent/applied.json
systemctl restart leikwan-client-agent.service
# Then re-pin disable_auto_update on panel for client id=3 and sidecar point id=5
```

---

## 8. Summary: The One Thing That Will Find the Bug

If you can only do ONE thing, do this:

```bash
# [OPERATOR] On the panel host:
diff -u \
  /root/leikwan-mptcp-leg-priority-sh36-20260519-005901/install_forward_runtime.py \
  /www/wwwroot/v3.leikwanhost.com/leikwan-v3/client-agent/install_forward_runtime.py \
  | grep -A3 -B3 "cf_path_prefix\|http-upgrade-path-prefix\|cf_target_port\|self_leg_wg"
```

The output of that command will show you the exact lines that changed between the working
and broken builds. From there, the fix is reverting or correcting those specific lines.

The 400/520 error from wstunnel means the CF edge or sidecar rejected the WebSocket upgrade
request. The ONLY reasons that happens are:
1. Wrong path prefix (client sends X, sidecar expects Y)
2. Wrong destination (client connects to an edge that has no route to this sidecar)
3. Sidecar route missing entirely (panel didn't publish it)

All three are diagnosable from the diff + the sidecar route table inspection above.

---

*End of fixing suggestions. The next agent should start at Section 1.2 if they have panel
host access, or at Section 2 if they only have repo access and must work from hypotheses.*
