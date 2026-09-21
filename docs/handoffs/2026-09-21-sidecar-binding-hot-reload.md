# 2026-09-21 — External-UoT sidecar: safe hot reload of the binding configuration

**Scope:** `tools/uot-sidecar` (the Go external UoT sidecar).
**Change:** an operator-triggered, all-or-nothing configuration reload that adds, removes or rotates forward bindings **without restarting the process and without disturbing live sessions**.
**Source only:** nothing was deployed. The five production sidecars still run the old build (proved in section 7).

## 1. Why this exists

`docs/handoffs/2026-09-20-uot-all-directions-test-matrix.md` (lines 60–63) recorded the gap:

> 当前源码不支持配置热加载。不能假设写JSON、systemctl reload或SIGHUP会新增forward。
> `tools/uot-sidecar/main.go` 仅启动时`loadConfig`一次，信号处理只注册SIGINT/SIGTERM；未实现SIGHUP、文件watch、reload API。

The consequence was that every new Ultra UDP direction needed a sidecar restart, which tears down all live sessions. That is what this change removes.

## 2. Mechanism

### 2.1 Immutable snapshot, atomic publication

`sidecarConfig` is now treated as an immutable value. `configStore` (`reload.go`) owns it behind `atomic.Pointer[sidecarConfig]`:

- `parseConfig` allocates fresh maps and fresh secret byte slices on every load, and nothing ever writes through a published pointer, so a published snapshot is never mutated again.
- `configStore.reload()` serialises reloads with a mutex (two triggers cannot validate against the same predecessor and publish out of order), then does **read → validate → publish**:
  1. `loadSnapshot(path)` = `os.ReadFile` + `parseConfig` (full validation, see 2.2);
  2. `checkReloadable(previous, candidate)` rejects what cannot be applied to a listening process;
  3. `current.Store(candidate)` — one pointer store is the entire publication.
- On any failure the function returns an error and **nothing is stored**: the previous snapshot keeps serving. There is no partial application and no half-applied state.

### 2.2 What is validated before publishing (all rejections keep the old snapshot)

| Rejection | Reason text / source |
|---|---|
| unreadable / missing file | `open <path>: no such file or directory` |
| bad JSON | `config is not a JSON object: …` |
| duplicate forward id | `duplicate forward id "170"` (new: `encoding/json` silently keeps the last duplicate key) |
| forwards not an object | `forwards must be an object` |
| invalid forward id | `invalid forward id "seventeen"` |
| malformed / non host:port target | `target must be host:port`, `target port is invalid` |
| bad limits | `max_frame_bytes is too small`, `frame_payload_max is invalid`, `queue and session limits must be positive`, `timeouts must be positive` |
| listen address changed | `listen address 127.0.0.1:7443 -> 127.0.0.1:8000 needs a restart` |

The listen address is the **only** setting a reload refuses: it is wired into the already-open listener, and keeping the old socket while the snapshot claims the new address would be exactly the half-applied state this feature must never produce. Everything else is per-request or per-session state and is applied whole.

### 2.3 Reader discipline — one snapshot per request

Every reader loads the pointer **once** and then uses that value:

| Reader | Snapshot taken | Used for |
|---|---|---|
| `handleConnection` | once at the top of the connection | HELLO read limit, binding authorisation, acceptance/rejection ACK, carrier lifetime |
| `uotSession` | the snapshot of the HELLO that admitted it (`session.config`) | read/write deadlines, keepalive, frame limits, session grace |
| `sessionRegistry.announceMode` | the snapshot passed in by the HELLO | session limit, negotiated frame payload, UDP dialer timeout |
| `start` / `reaperLoop` | once | listener address, reaper cadence |

A concurrent HELLO therefore sees either the whole old configuration or the whole new one — never a mix.

### 2.4 Session retention (the point of the feature)

A session stores the binding and the snapshot it was admitted under. Consequences:

1. **Removal does not disturb an established session.** The session keeps its carrier, its UDP socket and its negotiated state; the removed id simply leaves `snapshot.forwards`, so new HELLOs for it are refused with the existing Python-compatible text `forward is not allowed`.
2. **Token rotation does not disturb an established session**, for the same reason.
3. **Carrier reattach after removal/rotation still works**, which matters because the Ultra relay can move a client to a new carrier mid-session. `sidecarServer.authorizeHello` first tries the current snapshot binding, and only if that fails consults `sessionRegistry.liveSession(sessionID, forwardID)` — the binding of the *same, still-live session*. That retired binding may **keep an existing session alive but never create a new one**: `announceMode` takes an `allowCreate` flag and answers `forward is not allowed` when the session is gone.
4. **New ids are usable immediately**: the next HELLO loads the new pointer.

### 2.5 Triggers (both local-only)

| Platform | Trigger | Notes |
|---|---|---|
| Unix | **SIGHUP** | `reload_signal_unix.go`; conventional reload signal |
| all | **reload marker file** | `reload.go`, polled once a second |

**The marker file is the chosen Windows-capable trigger.** `--reload-file <path>`, default `<config>.reload`, `--reload-file=-` disables it. Creating that file triggers exactly one reload; the loop removes it before reloading, so each trigger needs a fresh file (if the file cannot be removed the loop falls back to mtime+size and still fires once per change).

Why it is safe:

- it opens **no socket** and the process listens on nothing new — the only listening socket is still the carrier TCP port (`ss` output in section 6 shows exactly one scratch listener);
- the marker carries **no data**; the configuration is always read from the file the process was started with;
- creating it requires local write access to the directory that already holds the configuration **and its secrets**, i.e. it grants nothing that is not already granted;
- an unauthenticated remote party has no path to it.

SIGINT/SIGTERM keep exactly their previous meaning: log `external UoT sidecar stopping` and shut down. `main` now selects on a signal channel and a reload channel; the shutdown branch is the old code.

Logging (never contains a secret or token):

```
INFO external UoT configuration reloaded path=./config.json forwards=1->2 added=[9002] removed=[] credentials_changed=[]
WARNING external UoT configuration reload rejected path=./config.json reason=config is not a JSON object: unexpected EOF previous_snapshot=still-serving
INFO external UoT reload marker observed path=./reload.marker
INFO external UoT reload triggers: SIGHUP (unix), local reload marker path=./reload.marker
```

## 3. Files changed

New:

- `tools/uot-sidecar/reload.go` — `configStore`, `reload`, `checkReloadable`, `applyReload`, `describeForwardChange`, `runReloadMarkerLoop`
- `tools/uot-sidecar/reload_signal_unix.go` / `reload_signal_windows.go` — SIGHUP on Unix, no-op on Windows
- `tools/uot-sidecar/reload_test.go` — the seven tests in section 5

Modified:

- `tools/uot-sidecar/config.go` — `loadSnapshot`; duplicate-forward-id rejection (`rejectDuplicateForwardIDs`, `forwardIDsInOrder`, `objectKeysInOrder`, `skipJSONValue`)
- `tools/uot-sidecar/main.go` — `--reload-file`, `configStore`, reload loop, trigger log line
- `tools/uot-sidecar/server.go` — `configStore` on the server, per-session snapshot, `authorizeHello`, `liveSession`, `allowCreate`, snapshot-aware ACKs, per-HELLO UDP dialer
- `tools/uot-sidecar/server_reliable.go` — frame limits from the session snapshot
- test call-site updates in `config_test.go`, `protocol_test.go`, `queue_backpressure_test.go`, `reliable_integration_test.go`, `server_test.go`

## 4. Build / vet / race results (raw)

### 4.1 Linux amd64 — the platform the sidecars run (authoritative for `-race`)

Run in WSL Debian (gcc 14, Go 1.26.5 linux/amd64), working directory `tools/uot-sidecar`:

```
=== go version ===
go version go1.26.5 linux/amd64
=== go build ./... ===
build-exit=0
=== go vet ./... ===
vet-exit=0
=== go test -race -count=1 ./... ===
ok  	leikwan.local/uot-sidecar	3.344s
race-test-exit=0
```

Verbose run of the same command: 39 tests, all `PASS`, including
`TestSidecarReloadAddsForwardForNewHello`, `TestSidecarReloadRejectsBadJSONAndKeepsServing`,
`TestSidecarReloadRejectionsLeaveSnapshotUntouched`, `TestSidecarReloadRemovalKeepsEstablishedSession`,
`TestSidecarReloadTokenRotationKeepsEstablishedSession`, `TestSidecarReloadRacingHelloHasNoTornRead`,
`TestRunReloadMarkerLoopTriggersOncePerMarker` — full transcript in
`tmp/hot-reload-verification/wsl-go-test-race-verbose.txt`.

### 4.2 Windows amd64 (this workstation)

```
=== go version ===
go version go1.26.5 windows/amd64
=== go build ./... ===
build-exit=0
=== go vet ./... ===
vet-exit=0
=== go test -count=1 ./... ===
ok  	leikwan.local/uot-sidecar	2.688s
test-exit=0
=== go test -race -count=1 ./... ===
go: -race requires cgo; enable cgo by setting CGO_ENABLED=1
race-exit=2
```

**Honest note:** `-race` cannot build on this Windows host — Go requires cgo for the race runtime there (`go env CGO_ENABLED` is `0`) and no C compiler (gcc/clang) is installed. The race-detector requirement is therefore satisfied by the Linux run above, which is also the production platform. A Linux Go 1.26.5 toolchain was installed inside the existing WSL Debian image for that purpose; no repository or system file was changed for it.

## 5. Tests added (`tools/uot-sidecar/reload_test.go`)

| Test | Proves |
|---|---|
| `TestSidecarReloadAddsForwardForNewHello` | valid reload adds forward 181; a HELLO with 181 succeeds immediately; 170 still works; unknown 999 still fails; the log names the added id |
| `TestSidecarReloadRejectsBadJSONAndKeepsServing` | a corrupt file is rejected, the published pointer is unchanged, the log states `not a JSON object` + `previous_snapshot=still-serving`, and both the established session and new HELLOs keep working |
| `TestSidecarReloadRejectionsLeaveSnapshotUntouched` | rejection matrix: bad JSON, duplicate id, forwards not an object, malformed target, invalid forward id, bad frame limit, listen-address change, missing file — each keeps the previous snapshot (pointer identity) and service continues |
| `TestSidecarReloadRemovalKeepsEstablishedSession` | removing a binding: established carrier keeps relaying, a new HELLO for the removed id is refused, and the same session may still reattach (`OKR`) |
| `TestSidecarReloadTokenRotationKeepsEstablishedSession` | rotation: in-flight session unaffected, retired token cannot create a session, rotated token works, established session reattaches |
| `TestSidecarReloadRacingHelloHasNoTornRead` | 60 reloads racing 6 clients × 8 HELLOs plus a snapshot reader, under `-race`: every attempt is authorised by exactly one of the two published configurations; a torn read could authorise neither |
| `TestRunReloadMarkerLoopTriggersOncePerMarker` | marker trigger: nothing without a marker, exactly one reload per marker, marker consumed, fresh marker fires again |

## 6. Live demonstration (real sidecar host, scratch process)

Host **70.36.96.183** (one of the five). Production unit `leikwan-external-uot` runs `/usr/local/bin/leikwan-external-uot-go --config /etc/leikwan-external-uot.json` as PID 1075611, `CanReload=no`, `NRestarts=0`, up since 2026-09-20 17:30:27 UTC.

Artifacts (built from this working tree, never installed over production):

- `tmp/hot-reload-verification/uot-sidecar_linux_amd64` — sha256 `97281f055731a80a55eed515d6fe6e8c6b71723349c00ceb1853baa3b221c142`, build stamp `2026.09.21.1-hotreload-demo`
- `tmp/hot-reload-verification/demo_linux_amd64.exe` — scratch UOT2 client/echo used only for this demonstration

Runs as `/tmp/uot-hot-reload-demo/uot-sidecar --config ./config.json --log-level DEBUG --reload-file ./reload.marker` listening on **127.0.0.1:39717** (loopback, non-default port), UDP echo target on 127.0.0.1:39718. Full transcript: `tmp/hot-reload-verification/live-demo-70.36.96.183.txt`.

### 6.1 Step 2 — HELLO + payload round trip on forward 9001

```
ack={"frame_payload_max":1200,"ok":true,"queue_packets":512,"session":"OKN"}
echo=hello-A
result=OK
ack={"error":"forward is not allowed","frame_payload_max":0,"ok":false,"queue_packets":512}
result=REJECTED          # forward 9999, unknown id
```

### 6.2 Step 4 — reload A+B via **SIGHUP**, same PID

```
kill -HUP 1435194
2026-09-21 07:52:09,893 INFO external UoT configuration reloaded path=./config.json forwards=1->2 added=[9002] removed=[] credentials_changed=[]
    PID    PPID ELAPSED COMMAND
1435194       1      21 ./uot-sidecar --config ./config.json --log-level DEBUG --reload-file ./reload.marker
ack={"frame_payload_max":1200,"ok":true,"queue_packets":512,"session":"OKN"}
echo=hello-B-new-id
result=OK                # forward 9002, never seen before this instant
echo=hello-A-still-works # forward 9001 still fine, result=OK
```

The PID is identical before and after (1435194, PPID 1, elapsed growing), i.e. no restart.

### 6.3 Step 5 — reload **removing** forward 9001 via the marker file

```
touch reload.marker
2026-09-21 07:52:18,629 INFO external UoT reload marker observed path=./reload.marker
2026-09-21 07:52:18,630 INFO external UoT configuration reloaded path=./config.json forwards=2->1 added=[] removed=[9001] credentials_changed=[]
marker_after_trigger: ls: cannot access 'reload.marker': No such file or directory
ack={"error":"forward is not allowed","frame_payload_max":0,"ok":false,"queue_packets":512}   # NEW HELLO for 9001
ack={"frame_payload_max":1200,"ok":true,"queue_packets":512,"session":"OKN"}                  # 9002 still fine
```

### 6.4 Step 6 — corrupt config via SIGHUP is rejected, old snapshot keeps serving

```
2026-09-21 07:52:24,409 WARNING external UoT configuration reload rejected path=./config.json reason=config is not a JSON object: unexpected EOF previous_snapshot=still-serving
ack={"frame_payload_max":1200,"ok":true,"queue_packets":512,"session":"OKN"}
echo=hello-B-after-corrupt-reload
result=OK
```

### 6.5 Step 8/9 — production untouched, SIGTERM unchanged

```
MainPID=1075611              NRestarts=0            SubState=running
ActiveEnterTimestamp=Sun 2026-09-20 17:30:27 UTC   CanReload=no
production_config_md5=90371980d2b1ed8f00aae0a3e60cc48c   (identical before and after)
production_listener: LISTEN 0 1024000 *:7443 *:*
kill -TERM 1435194
2026-09-21 07:52:33,335 INFO external UoT sidecar stopping
scratch sidecar stopped
```

Post-run check: **no scratch process remains**, scratch port 39717 closed, production MainPID/uptime/restart counter/listener unchanged, production config file byte-identical (`/etc/leikwan-external-uot.json` was only ever read). Production forwards 158/159/170/171/180 were never touched; the scratch sidecar never bound a production port and never used the production config.

## 7. Session-retention proof

A HELD session (`demo hold`, one DATA frame per second on forward 9001) was opened **before** the first reload and left running across **all three** reloads:

```
2026-09-21T07:52:01Z hold echo=ping-1
   ...                                (reload A+B        07:52:09,893)
   ...                                (reload remove 9001 07:52:18,630)
   ...                                (corrupt reject    07:52:24,409)
2026-09-21T07:52:26Z hold echo=ping-26
hold_echoes_total=26
```

26 consecutive echoes, one per second, no gap and no reconnect, spanning the reloads at 07:52:09, 07:52:18 and 07:52:24 — including the reload that **removed** the very binding (9001) the session was using, and the rejected reload. When the process was finally stopped with SIGTERM the session's own counters were `udp_in=32 udp_out=32 queue_drops=0 oversize_drops=0 reattaches=0`, i.e. the datagram path never broke.

## 8. What is NOT covered (honest limits)

1. **No deployment.** The five production hosts still run the old binary; `CanReload=no` remains until the well-known and the unit gains `ExecReload=/bin/kill -HUP $MAINPID`. This change only makes the capability exist in source (and proves it on a scratch process).
2. **The Python entry point is untouched.** `sidecar-agent/leikwan_external_uot.py` has no reload; a host running the Python sidecar gains nothing from this change.
3. **Listener rebind is refused**, not implemented: changing `listen_host`/`listen_port` needs a restart. A reload that changes them is rejected as a whole.
4. **Live sessions keep their admission snapshot.** `frame_payload_max`, `max_frame_bytes`, `idle_timeout_seconds`, `keepalive_seconds`, `session_grace_seconds`, `session_limit` and `udp_connect_timeout_seconds` from a reload apply to HELLOs and sessions admitted afterwards; sessions already running keep the old values (deliberate: it is what makes zero-interruption provable). Raising a frame limit therefore does not upgrade existing sessions.
5. **Revocation is not instantaneous.** A live session — including its carrier reattach — survives removal or rotation of its binding as long as the session lives. A continuously attached session is never reaped, so the only hard cut today is a restart (or waiting out `session_grace_seconds` on a detached session). If instant revocation is ever required it must be designed explicitly (for example a reload that closes sessions whose binding disappeared) and is **not** part of this change.
6. **Config schema changes** are still limited to the keys the parser knows: a new/renamed key is simply ignored (`summarizeConfig` is unchanged). Reload re-reads bindings and tunables, it does not migrate formats.
7. **Stricter than Python in one case:** duplicate forward ids are now rejected at parse time. The Python sidecar (and `encoding/json`) silently keep the last duplicate; an existing file that relies on that would now be rejected at startup too.
8. **Unverified platform detail:** the SIGHUP path is proven only by the live Linux demonstration; the Windows marker trigger is proven by unit test. There is no automated test for `notifyReloadSignals` itself.
9. **systemd `CanReload` and packaging** are outside this change; the docs/handoffs/2026-09-20 statement stays true for the currently deployed binaries.

## 9. Suggested follow-ups

- Add `ExecReload=/bin/kill -HUP $MAINPID` to the sidecar unit and set `CanReload=yes` once a build containing this change is deployed.
- Roll out the new binary to the five hosts (fleet rollout, not part of this task) and re-run the `reload-capability` probes from `tmp/uot-all-directions-20260920/reload-capability/`.
- Decide whether the Python sidecar needs the same capability, or whether it is fully retired.
