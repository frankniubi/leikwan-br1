#!/usr/bin/env python3
"""Patch the panel's heartbeat handler to forward the runtime attestation."""
import pathlib
import shutil
import subprocess
import time

ROOT = pathlib.Path("/www/wwwroot/v3.leikwanhost.com/leikwan-v3")
TARGET = ROOT / "master/src/Controllers/ClientAgentController.php"
ENV = ROOT / "master/.env"
STAMP = time.strftime("%Y%m%dT%H%M%SZ", time.gmtime())
BACKUP = pathlib.Path("/root/leikwan-br1-backups/attestation-plumbing-" + STAMP)
BACKUP.mkdir(parents=True, exist_ok=True)

OLD = """        $ultraStatuses = $request->input('ultra_status', []);
        if (is_array($ultraStatuses)) {
            try {
                $this->ultraClientStatus->ingest((int) $client['id'], $deviceId, $ultraStatuses);
            } catch (\\Throwable) {
"""
NEW = """        $ultraStatuses = $request->input('ultra_status', []);
        // The runtime build identity reaches the panel in two shapes: the
        // desktop client puts it inside each per-forward usage snapshot, the
        // Linux/macOS agent puts it inside each per-forward status entry. Only
        // the explicit argument used to be read, so no session row ever stored a
        // fingerprint and the (already enabled) attestation enforcement had
        // nothing to compare against.
        $runtimeAttestation = $request->input('runtime_attestation');
        if (!is_array($runtimeAttestation)) {
            foreach ((array) $request->input('ultra_usage', []) as $usageEntry) {
                if (is_array($usageEntry) && is_array($usageEntry['runtime_attestation'] ?? null)) {
                    $runtimeAttestation = $usageEntry['runtime_attestation'];
                    break;
                }
            }
        }
        if (is_array($ultraStatuses)) {
            try {
                $this->ultraClientStatus->ingest(
                    (int) $client['id'],
                    $deviceId,
                    $ultraStatuses,
                    is_array($runtimeAttestation) ? $runtimeAttestation : null
                );
            } catch (\\Throwable) {
"""

source = TARGET.read_text(encoding="utf-8")
if NEW in source:
    print("controller already patched")
elif source.count(OLD) != 1:
    raise SystemExit("anchor not found exactly once (%d)" % source.count(OLD))
else:
    shutil.copy2(TARGET, BACKUP / "ClientAgentController.php.prev")
    TARGET.write_text(source.replace(OLD, NEW, 1), encoding="utf-8")
    print("controller patched")
lint = subprocess.run(["php", "-l", str(TARGET)], capture_output=True, text=True)
print((lint.stdout or lint.stderr).strip())
if lint.returncode != 0:
    shutil.copy2(BACKUP / "ClientAgentController.php.prev", TARGET)
    raise SystemExit("lint failed; restored")

# Observe first: enforcement may only deny once real fingerprints arrive, and
# the published hash for the macOS/Android entries predates a rebuild.
lines = ENV.read_text(encoding="utf-8").splitlines()
changed = False
for index, line in enumerate(lines):
    if line.startswith("ULTRA_ATTESTATION_ENFORCEMENT="):
        if line.strip() != "ULTRA_ATTESTATION_ENFORCEMENT=report":
            shutil.copy2(ENV, BACKUP / ".env.prev")
            lines[index] = "ULTRA_ATTESTATION_ENFORCEMENT=report"
            changed = True
if changed:
    ENV.write_text("\n".join(lines) + "\n", encoding="utf-8")
print("enforcement now:", [l for l in lines if l.startswith("ULTRA_ATTESTATION_ENFORCEMENT")])
print("backup:", BACKUP)
