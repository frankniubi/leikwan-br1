# Ultra Persistent UDP and Debug Collection Implementation Plan

**Goal:** Keep Ultra UDP associations alive from runtime startup, activate independent forwards concurrently, and provide authenticated UUID-scoped debug log collection capped at 100 MiB.

**Architecture:** The Go runtime owns a persistent prewarm association and 5-second protocol keepalive; user associations take over the warm session and retain their own keepalive loop. Desktop and Linux agents parallelize independent lease requests and forward applies with a bounded worker pool while retaining per-forward failure isolation. The panel exposes a short-lived debug collection UUID in client metadata and accepts sanitized, sequenced log chunks through an authenticated endpoint with an atomic per-UUID byte reservation.

**Tech Stack:** Go runtime/tests, C# .NET desktop core/tests, Python client-agent/tests, PHP panel controllers/migrations/tests, JSON artifact manifests and release scripts.

**Spec:** Approved in chat on 2026-08-31; Android remains deferred unless an existing client contract requires a no-op field.

## Global Constraints

- UDP keepalive starts with the forward runtime and continues after selection; interval is exactly 5 seconds and sends only the existing protocol keepalive envelope.
- Probe target remains fixed at `1.1.1.1:53`; probe work is bounded and concurrent, and probe status is not shown as a user-facing connection failure.
- Forward activation uses a maximum default concurrency of 4; one forward's lease or runtime failure must not cancel siblings.
- Debug collection UUIDs are server-issued, client-authenticated, expiry-checked, replay-safe, and capped at exactly `100 * 1024 * 1024` bytes per UUID.
- Debug payloads are sanitized before upload, chunked at <=256 KiB, stored outside public artifact/document roots, and never block forward activation.
- Do not emit `Establishing Connection with the Server` for lease refresh/prewarm; use neutral debug/status records only.
- Preserve all unrelated dirty worktree changes and never copy credentials into source, commits, artifacts, or user-facing output.

---

### Task 1: Go persistent prewarm and neutral logging

**Files:**
- Modify: `tools/ultra-client/main.go`
- Test: `tools/ultra-client/main_test.go`, `tools/ultra-client/regression_test.go`

**Interfaces:**
- Consumes existing `armDatagram`, `sendDatagramKeepalive`, `udpWarmSession`, and `serveUDPOnConn`.
- Produces a warm-session worker that retries failed setup, keeps one unclaimed session alive indefinitely, and stops cleanly when a user association claims it or the runtime context ends.

- [ ] **Step 1: Write failing tests** for warm-session retry after keepalive failure/expiry, clean cancellation, and absence of the legacy startup phrase in runtime output.
- [ ] **Step 2: Run focused Go tests** and verify the new lifecycle assertions fail for the current two-minute one-shot worker.
- [ ] **Step 3: Implement the persistent warm worker** with a claim notification, keepalive failure notification, bounded retry backoff, and no user payload writes.
- [ ] **Step 4: Replace the misleading startup log string** with a neutral status/debug message that does not claim a new server connection during lease refresh/prewarm.
- [ ] **Step 5: Run `go test -count=1 ./tools/ultra-client/...`** and the existing protocol regression tests.

### Task 2: Concurrent forward activation on desktop

**Files:**
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ConfigPollingService.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ForwardRuntimeManager.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/StateManager.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ProcessSupervisor.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/DesktopRuntimeSession.cs`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ConfigPollingServiceTests.cs`, `ForwardRuntimeManagerTests.cs`, `DesktopRuntimeSessionTests.cs`

**Interfaces:**
- `ConfigPollingService` retains its public synchronous API and adds an internal bounded worker path for independent forward reconciliation.
- `StateManager` gains an atomic per-forward upsert/remove operation so concurrent applies cannot overwrite sibling state.
- Existing per-forward runtime methods remain serialized per forward but may execute for different IDs concurrently.

- [ ] **Step 1: Add a failing test** proving two delayed Ultra lease resolutions overlap and one failed forward does not prevent the other from being applied.
- [ ] **Step 2: Run the focused .NET test** and verify it observes the current serial behavior.
- [ ] **Step 3: Implement bounded `Task.WhenAll` reconciliation** with a default `SemaphoreSlim(4)` and per-forward exception handling.
- [ ] **Step 4: Add per-forward locks and atomic state upserts** around start/refresh/stop paths.
- [ ] **Step 5: Update `StartAllAsync` to use the same bounded path** and retain cancellation semantics.
- [ ] **Step 6: Run the desktop core test suite** and inspect logs for parallel start timestamps.

### Task 3: Concurrent activation parity on Linux/Python

**Files:**
- Modify: `client-agent/client_agent.py`
- Modify: `client-agent/install_forward_runtime.py`
- Modify: `artifacts/install-forward-runtime.py`
- Test: `client-agent/tests/test_ultra_runtime.py`, `client-agent/tests/test_client_agent_*.py`

**Interfaces:**
- `resolve_ultra_leases` keeps its batch API; runtime apply uses a bounded executor for independent forwards.
- Generated runtime config keeps `udp_prewarm=true` and `udp_keepalive_interval_seconds=5` for UDP-capable transports.

- [ ] **Step 1: Add a failing test** proving independent `apply_forward` calls overlap while a failed call is isolated.
- [ ] **Step 2: Run the focused Python tests** and verify serial behavior fails the overlap assertion.
- [ ] **Step 3: Implement the bounded executor and synchronized applied-state merge.**
- [ ] **Step 4: Keep both runtime generator copies byte-identical and preserve the neutral log wording.**
- [ ] **Step 5: Run the full client-agent unit suite and byte-equality check.**

### Task 4: Panel UUID debug collection contract

**Files:**
- Create: `master/migrations/20260831_add_debug_collection.sql`
- Modify: `master/migrations/schema.sql`
- Modify: `master/src/Bootstrap.php`
- Modify: `master/src/Controllers/ClientAgentController.php`
- Modify: `master/src/Controllers/AdminController.php`
- Modify: `master/src/Controllers/TenantController.php`
- Create: `master/src/Support/DebugCollectionStore.php`
- Test: `master/tests/*debug*collection*.php`, controller contract tests

**Interfaces:**
- Client metadata fields: `debug_collection_uuid`, `debug_collection_expires_at`.
- Endpoint: `POST /api/client/debug/log` with `{collection_uuid, sequence, chunk_sha256, content_base64}`.
- `DebugCollectionStore::appendChunk(int $clientId, string $uuid, int $sequence, string $sha256, string $bytes): array` atomically reserves quota and records an idempotent chunk.

- [ ] **Step 1: Add failing PHP tests** for valid upload, wrong client/UUID, expired UUID, duplicate sequence, invalid hash, and the 100 MiB boundary.
- [ ] **Step 2: Run focused PHP tests** and verify the endpoint/store are missing or reject the contract.
- [ ] **Step 3: Add the migration and schema definition** with UUID/session metadata, byte counter, expiry, and unique `(collection_uuid, client_id, sequence)`.
- [ ] **Step 4: Implement authenticated endpoint validation, atomic quota reservation, and private storage under the configured data directory.**
- [ ] **Step 5: Add admin/tenant start and stop actions** that issue/revoke UUIDs without exposing raw log contents publicly.
- [ ] **Step 6: Run panel contract and migration tests.**

### Task 5: Client debug upload, redaction, and retry

**Files:**
- Modify: `client-agent/client_agent.py`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/ClientMeta.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/MasterApiClient.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ClientDiagnosticsService.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/DesktopRuntimeSession.cs`
- Test: `client-agent/tests/test_debug_collection.py`, desktop diagnostics tests

**Interfaces:**
- `DebugLogUploader` reads only configured/local client logs, redacts credential-bearing keys and bearer material, chunks at <=256 KiB, and uploads in a background task.
- Uploads include UUID, monotonic sequence, byte length, and SHA-256; retry is bounded and stops on 401/403/404/409/413.

- [ ] **Step 1: Add failing tests** for redaction, chunk sizing, retry classification, duplicate-safe sequencing, and non-blocking diagnostics scheduling.
- [ ] **Step 2: Run focused Python/.NET tests** and verify the uploader is absent.
- [ ] **Step 3: Implement shared redaction rules and Python uploader.**
- [ ] **Step 4: Implement desktop metadata parsing and background uploader using the existing `MasterApiClient` transport.**
- [ ] **Step 5: Add client-agent metadata propagation and ensure uploads stop after UUID expiry/revocation.**
- [ ] **Step 6: Run Python and desktop test suites.**

### Task 6: Artifact publication, web copy, and acceptance verification

**Files:**
- Modify: release/version manifests under `artifacts/`, `master` artifact metadata, and existing web copy that describes diagnostics/connection status.
- Create: release notes/handoff under `docs/handoffs/`.
- Test: release manifest tests and local smoke scripts.

**Interfaces:**
- Published Windows, macOS, and Linux artifacts carry the new runtime/config behavior and matching hashes.
- Web copy describes “UDP keepalive” and “Debug collection” without the removed connection phrase.

- [ ] **Step 1: Build Go and platform artifacts** with reproducible version metadata.
- [ ] **Step 2: Run manifest/hash and source-mirror checks.**
- [ ] **Step 3: Update web copy and verify the removed phrase is absent from user-facing strings.**
- [ ] **Step 4: Replace the local Windows binary only after hash/version verification and preserve a rollback copy.**
- [ ] **Step 5: Run the local 10001 TCP+UDP smoke test five times at three-second intervals; record the exact result and any target-side limitation.**
- [ ] **Step 6: Run final full suites, review the diff, and publish artifacts only after all required checks pass.**

---

## Self-review

- Every approved requirement maps to Tasks 1-6: persistent keepalive, bounded concurrent activation on all three requested desktop/server platforms, UUID-scoped debug collection, tests, neutral logs, and artifacts/web copy.
- No task relies on an undefined interface; the new store and uploader signatures are stated explicitly.
- The 100 MiB limit is enforced on decoded bytes, not base64 characters.
- Android is intentionally unchanged per the approved scope.
