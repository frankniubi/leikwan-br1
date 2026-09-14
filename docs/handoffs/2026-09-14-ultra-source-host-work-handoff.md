# Ultra source-host work — handoff (2026-09-14/15, source host 10.10.10.171)

## READ THIS FIRST — THE UNEXPLAINED THING

**The ~6.2% zero-downstream fault on live forward 184 is STILL UNEXPLAINED**, and every
number in this document measured on the source host is evidence about THE SOURCE HOST ONLY,
not about any client. Nothing here establishes client impact.

Two things remain open and neither was closed by this work:

1. **The SS-framed retest is blocked on credentials.** The forward targets are Shadowsocks
   servers (operator's correction) and a Shadowsocks server drops an unframed or
   wrongly-keyed request silently by design. The Shadowsocks method and password are NOT
   persisted anywhere on the host — a bounded read-only search found no file containing
   them; they exist only in memory inside the running `leigod-linux-amd64` processes. They
   are the operator's to supply.
2. **The first post-deploy panel-issued review is still pending.**
   `UltraCandidateReviewService` sets `REENQUEUE_COOLDOWN_SECONDS = 21600` (6 h) and line
   581's last review completed 15:55:40 UTC, so the next is due ~21:55 UTC. A 50-minute
   watcher saw none. The deploy is verified by digest, compile, CLI and service state; it is
   NOT yet verified by a panel-issued review landing with a proof block.

---

## THE METHOD NOTE — the deliverable, above the findings

**Every wrong conclusion in this work came from reading a field as a verdict it did not
carry. Every one was caught by a control whose outcome was already known.**

Three instances, in the order they were found:

| field / observation | what it was read as | what it actually meant |
| ------------------- | ------------------- | ---------------------- |
| `target_tcp_zero_downstream == 0` | "the line is clean" | it counts ONE thing: rounds where the relay sent nothing at all. A relay that answered and refused also leaves it at 0 |
| the reply code `0x01` in the connect frame | "the relay refused" | it is `0x01` on a stream that delivered 326 real bytes AND on one that delivered none — byte-identical frames |
| a zero-byte reply from a tokyo forward | "the relay cannot reach the destination" | those targets are Shadowsocks servers, which drop an unframed/incorrectly-keyed request silently BY DESIGN. The silence was manufactured by our own payload |

The guard is the same each time, and it is cheap: **use a control whose outcome you already
know, and make the payload one the service must answer.** In this work the control that
caught each error was 1.1.1.1:80 — a plain HTTP server that answers, and therefore a
destination against which "carried" and "did not carry" are distinguishable.

A second, related lesson: four plausible mechanisms were proposed from reading code or logs
and all four were refuted by measurement — per-relay lottery, "different egress", the
greeting, and "unresolvable by construction". A candidate mechanism is not a finding until
it has been measured against a control.

---

## 1. The live layout (this was the original blocker; it dissolved on inspection)

None of the three hypotheses offered was correct. The monolith does **not** inline the review
logic and does **not** import a probe — it **spawns** one:

* `/usr/local/bin/ultra-source-agent.py:920` — `self.review_script = Path(self.config.get("review_script", "/opt/ultra-source/runtime/ultra_line_review.py"))`
* `:1114-1126` — builds `["python3", str(self.review_script), "--relay", …]`
* `:1128` — `subprocess.run(command, capture_output=True, text=True, timeout=window + 120, check=False)`
* `grep -c 'NativeSession\|ECHO_TARGET\|def probe_' /usr/local/bin/ultra-source-agent.py` → **0**

The split layout was already live. The operator note's file paths and names were right.

## 2. Two corrections to the earlier access memo

* **"no live `ultra_line_review.py` anywhere" is FALSE.** It is at
  `/opt/ultra-source/runtime/ultra_line_review.py`, 7876 bytes, md5
  `a9cc8b87b1bebf8a370bda023c9981ec`. The memo's `find` was malformed (a stray `-o -xdev`
  changes the expression).
* **"the drift gate WILL refuse" is FALSE — the gate PASSED.** Both installed files were
  byte-identical to repo commit `1893e05b`:
  `git cat-file blob 1893e05b:source-agent/ultra_line_review.py` → `a9cc8b87…`, and
  `…ultra_source_agent.py` → `db911ae5522e8e5986c6b8855a588dd3`. The only mismatch was
  old-installed vs new-in-repo, i.e. the upgrade itself.
* **TRAP for the next reader:** hashing `git show HEAD:file > out` in PowerShell re-encodes
  the text and yields a DIFFERENT hash. Use `git cat-file blob`.

## 3. What is deployed, and the proof

| file | md5 | sha256 | bytes |
| ---- | --- | ------ | ----- |
| `/opt/ultra-source/runtime/ultra_line_review.py` | `6bd9aba7d58ed673b27df15c4b8d9835` | `fda0a88f5068f66755e589d0e61abf11aa0b195582665386058a1889bde21ae0` | 44096 |
| `/usr/local/bin/ultra-source-agent.py` | `264990c14399877fa0300ba65527736a` | `1d11023ffd5afb61f5dbf722f6dbe94fca1b6148394a271ffa7161c1d231e57a` | 57632 |

Backups, each with the `.orig` bytes, `SHA256SUMS`/`MD5SUMS`, and a rehearsed restore:

```text
/root/leikwan-source-agent-backups/20260914T171500Z/   original pair (probe a9cc8b87 + agent db911ae5)
/root/leikwan-source-agent-backups/20260914T181500Z/   probe v2
/root/leikwan-source-agent-backups/20260914T183000Z/   probe v3
/root/leikwan-source-agent-backups/20260914T184500Z/   probe v4 (current)
```

Every install ran a refusing drift gate first, a timestamped backup, a `cmp`-verified
rollback rehearsal, `py_compile` on the INSTALLED bytes, and a one-command post-check. The
service was stopped only for the swap and restarted immediately; `ProtectSystem=strict` and
the rest of the unit hardening were never weakened. Host python is 3.11.2.

## 4. Three real probe defects, found and fixed test-first

1. **A refusal was reported as a silence.** `valid_connect_frame()` required a 10-byte frame
   and never decoded the reply code, so a relay that answered with a 4-byte reply header fell
   through to a generic downstream check and was stored as `no_downstream_bytes` — with
   `target_tcp_zero_downstream` left at **0**, the very field the operator note told a human
   to read as the fault signature. The fault read as clean.
2. **An unparsed reply was collapsed into a silence.** Unknown reply shapes are now recorded
   with their raw bytes and never folded into `no_downstream_bytes`.
3. **The reply code was treated as a verdict.** Measured: a stream that delivered 326 real
   bytes and one that delivered nothing produced BYTE-IDENTICAL ten-byte frames
   (`04 01 01 00 01 <addr> <port>`), code `0x01` on both. Success is now decided by arrival
   of a non-echo payload (`MIN_DELIVERED_PAYLOAD_BYTES = 8`); the code is a named diagnostic;
   a refusal requires a non-zero code AND no payload AND no answer.

Pre-fix failure messages are in the commit bodies (e.g. `AssertionError: the reply code must
be decoded` / `assert None == 4`). Probe suite: 37 passed. The PHP gate contract test passes
against the regenerated fixture.

## 5. The operator note, re-derived

`docs/handoffs/2026-09-14-ultra-review-probe-operator-note-171-REDERIVED.md` — committed, and
corrected twice:

* **§10 now opens with a loud warning** that `target_tcp_zero_downstream` reads CLEAN on the
  fault the note was written for, and points at the refusal/payload fields instead.
* The **relay code is not a verdict** either, with the byte-identical-frame evidence.
* Every "~1 s idle timeout" phrasing is gone — a short close is the relay finishing its
  answer, not going quiet.
* **§13 states plainly that the probe runs on the SOURCE HOST**, so its verdict is about the
  source host's egress and not a client's.

Its install heredocs are regenerated from the **deployed** bytes and verified by extracting
them back out of the committed file and comparing digests — a paste-ready guide whose blocks
do not match the host is worse than no guide.

## 6. Contested, not settled: the greeting comment

`tools/ultra-client/main.go:137-144` asserts that *"some existing relays accept the
five-method greeting but never attach the resulting stream to the target"*.

**Tonight's measurements found NO difference between the two greetings** — on every relay and
every destination tested, both greetings carried to the control (1.1.1.1:80, 408 bytes) and
both delivered nothing to the tokyo forwards. So the claim is **UNREPRODUCED and contested**,
not refuted in general: either the affected relays were not in our sample, or the comment
describes something that no longer holds.

**Do not delete the comment and do not act on it.** If a greeting-dependent failure is ever
actually reproduced — one relay, both greetings, opposite outcomes — then a greeting hint on
the quarantined path record becomes justified. Until then the honest output is an observation,
not a mechanism.

A related code fact, recorded because it is easy to misread: `dialStreamWithGreetingOrder`
(`main.go:499-505`) falls back to the second greeting **only when the handshake errors**. A
handshake that succeeds and attaches nothing returns normally, so that fallback cannot fire.
## 7. Who owns the silent-stream retry decision (this prevented a wrong fix)

**`noteRelayOutcome` — `tools/ultra-client/ha_tcp.go:132-161`** is the single owner. It is
called from exactly one production site, `relayTCP` (`:163`, call at `:223`), after BOTH copy
goroutines finish. `no_downstream_bytes` appears nowhere else in the package.

It ALREADY implements "judge by bytes arriving, not by the handshake succeeding":

* any delivered byte → `ClearPathFailure` (clears isolation and the streak);
* no strike if `lifetime > haDeadStreamMaxAge` (3 s) or if `upstreamBytes == 0`;
* no strike if `!remoteStoppedFirst()` — **a local abort is explicitly not evidence about the
  relay** (the `ec224e60` fix; `haRelayOrderEpsilon = 25 ms`);
* `FailSilentPath` quarantines only after `pathIsolationMinStrikes` = **2** consecutive
  silent streams; one strike never isolates.

The bytes-based decision is **structurally downstream of the dial**: `dialStream` runs before
`relayTCP` exists, and `dialStreamWithGreetingOrder` returns before any payload has flowed in
either direction. A test asserting that "a normal return with zero downstream payload triggers
the next greeting" therefore **cannot be written at that layer** — there is no payload to
observe. Adding a greeting-level fallback would have been a SECOND OWNER for a policy the HA
layer already owns. Not implemented; option (ii) (a greeting hint on the path record) was
considered and **declined** because the greeting hypothesis was refuted by measurement and
building machinery for a dead cause is how beliefs get shipped.

## 8. How the zero-byte results were produced, and why they were uninterpretable

Measured on the source host, through advertised relays of line 581, against the tokyo forward
targets — with the same HTTP payload to every destination so the payload could not be the
variable:

| destination | native local SOCKS 127.0.0.1:20041 | our relay |
| ----------- | ---------------------------------- | --------- |
| control 1.1.1.1:80 | 408 bytes (HTTP 403) | 408 bytes (HTTP 403) |
| 185.220.238.143:20007 (config forward target) | 0 bytes | 0 bytes |
| fwd184 45.143.235.221:10809 | 0 bytes | 0 bytes |
| fwd164 160.25.134.152:21565 | 0 bytes | 0 bytes |

**Reading: our path and the native path are capability-equivalent on every destination
tested.** Our handshake, command-1 target connect, XOR framing and read path all deliver real
application bytes from this host.

**And the zeroes are not evidence about reachability.** The operator identified the reason:
those targets are Shadowsocks servers, which drop an unframed or wrongly-keyed request
silently by design. 185.220.238.143:20007 returned zero bytes through its OWN native path for
the same reason — it is an SS server too, and returning nothing is correct behaviour for the
payload we sent.

**Retracted:** "unresolved by construction with the payloads available", "every non-HTTP
destination is a blind spot in the instrument", and an earlier "source-egress property"
framing. The probe's own target probe already sends an SS-none-framed DNS query, not raw HTTP,
but the ultra material's credential is ~13 bytes while the probe's `credential_checksum`
returns 0 unless the password is exactly 16 bytes — so **we do not know which SS method or
credential those targets require.** Nothing on the host records it.

`ss -tnp` also shows `leigod-linux-amd64` holding ESTABLISHED `:5438` connections to the same
hosts and ranges the ultra probe dials (e.g. `113.31.107.168:5438`), so the relays ARE those
servers — the retracted "different egress" reading came from not checking that first.

## 9. Mechanisms tested and refuted (do not re-propose without new measurement)

| hypothesis | how it died |
| ---------- | ----------- |
| refusal is per-relay endpoint | 9 distinct relays, 3 advertised + 6 not, all delivered 0 to the same target |
| different egress from the working client | `:5438` IS the Leigod server port; the native client uses the same servers |
| the five-method greeting | both greetings carried to the control and both delivered nothing to tokyo, same relay, same minute |
| the destinations are unreachable from the relay | the operator's SS explanation; and 185.220.238.143:20007 returned 0 through its own native path too |

## 10. Known-failing test, NOT attributable to this work

`TestServeNativeSocks5ConnectRoundTrip` fails with *"native SOCKS TCP port 62670 did not
become ready"*. It failed on the **first baseline run** of `go test ./...` before any change
was made, and **no file in `tools/ultra-client` was modified** by this work.

## 11. Repo commits produced

```text
3aaaed26  fix(ultra): read the relay's target-connect reply code instead of calling a refusal a silence
af499934  fix(ultra): never report an unparsed relay reply as a silence
bb004b27  fix(ultra): payload decides a transfer, the relay code is only a diagnostic
226b29e3  docs(ultra): correct the operator note's fault-signature guidance and add the vantage limit
2eae8872  docs(ultra): the relay code is not a verdict either - correct the note again
50813b15  docs(ultra): re-derive the source-host 171 operator note against the live layout
3e153197  docs(ultra): record the advertised-vs-live relay mismatch found on 171
487dd3bb  docs(ultra): first per-endpoint end-to-end measurement from source host 171
5ac576f1  docs(ultra): greeting-fallback defect, and the limits of the zero-byte finding
cf4314f3  docs(ultra): retract the 'unresolvable by construction' framing - the payload was wrong
93795229  docs(ultra): trace who owns the silent-stream retry decision in the client
```

## 12. Still open (the complete list)

1. **The ~6.2% zero-downstream on live forward 184 — UNEXPLAINED.** Requires a client-side
   measurement; no source-host measurement can settle it.
2. **The SS-framed retest** — blocked on the operator's Shadowsocks credentials/method.
3. **The first post-deploy panel review** — pending the 6-hour cooldown (~21:55 UTC).
4. **The advertised-vs-live relay mismatch** — `ultra_lines.relay_ips_json` is overwritten by
   scalar assignment on every material upload (`UltraInventoryService.php:464-488`) while the
   supplier pool rotates on every acquisition, so a review validates against a moving sample.
   The approved fix (a windowed union in a sidecar key, same transaction, 30 min window, cap 64,
   time-based eviction, refuse-and-log when full, admin display showing the observed total) was
   **not started** — correctly paused for the investigation above.
