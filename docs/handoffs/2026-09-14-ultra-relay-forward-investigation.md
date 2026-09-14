# Ultra relay/forward investigation — findings and their limits (2026-09-14 evening UTC)

Written after a night in which several confident conclusions were overturned by their own
controls. Every claim below states what would falsify it. Read the LIMITS sections.

## 1. The client's greeting fallback cannot fire on the failure the comment describes

`tools/ultra-client/main.go`:

* `:133-135` define `currentHandshakeGreeting {5,5,0,1,2,0x80,0x83,1}` and
  `legacyHandshakeGreeting {5,3,2,0x80,0x83,1}`.
* `:137-144` `handshakeGreetingOrder` returns LEGACY first, with the comment: *"some
  existing relays accept the five-method greeting but never attach the resulting stream
  to the target"*.
* `:517` `dialStream` calls it, so the LIVE ordinary-TCP path sends LEGACY first.
* `:499-505` `dialStreamWithGreetingOrder` falls back to `order[1]` **only when
  `dialStreamWithGreetingUntil` returns an error AND `isRetryableHandshakeError(err)`
  holds.

**The defect:** a handshake that SUCCEEDS and then attaches nothing returns the
connection normally, so no error is raised, `isRetryableHandshakeError` is never
consulted, and the fallback never runs. The client stays on the greeting that cannot
work for that relay, silently, for the life of the connection. The comment describes one
failure mode; the code only handles the other one.

Confirmed via `.codegraph/codegraph.db` (nodes/edges), not by grepping:
`handshakeGreetingOrder` <- `dialStream` (main.go:517).

## 2. The greeting is NOT the cause of the zero-byte results on this relay

Side by side, one relay, one minute, explicit greeting bytes, command 1 (target connect),
payloads chosen so a real service must answer:

| destination | greeting | bytes back |
| ----------- | -------- | ---------- |
| 1.1.1.1:80 | legacy | 408 (HTTP/1.1 403 Forbidden) |
| 1.1.1.1:80 | current | 408 (HTTP/1.1 403 Forbidden) |
| 45.143.235.221:10809 | legacy | 0 |
| 45.143.235.221:10809 | current | 0 |
| 160.25.134.152:21565 | legacy | 0 |
| 160.25.134.152:21565 | current | 0 |

Both greetings carry to the control and both fail to the tokyo forwards, so the greeting
does not change the outcome. This does NOT refute the comment in general — only for these
relays and this target. The fallback defect in section 1 stands on its own.

## 3. What IS established about the transport

* Our handshake, credentials, command-1 target connect, XOR framing and stream read all
  WORK through the relay from the source host: they delivered a TLS ServerHello and then
  HTTP 403 responses — real application bytes from a real service.
* The relay's reply CODE does not decide anything: a stream that delivered 326 bytes and
  one that delivered nothing produced BYTE-IDENTICAL ten-byte frames (`04 01 01 00 01
  <addr> <port>`) with code `0x01` on both.
* The four tokyo forwards (and a fifth) returned zero application bytes where the control
  returned 408, on the same relay in the same minute with the SAME HTTP payload.

## 4. What is NOT established — do not read section 3 as more than it says

* **The destinations may be unreachable from the relay, OR they may simply not answer an
  HTTP GET.** These are forwarded game ports; a service that expects a game protocol will
  return zero bytes to a web request exactly as a non-attached stream does. I removed one
  confound (I had been sending DNS queries to tokyo and HTTP to the control) and the result
  did not change, but zero bytes still cannot separate the two readings.
* The local Leigod SOCKS cross-check (`127.0.0.1:20041`, one of 15 `leigod-linux-amd64`
  instances) showed the same shape — 381 bytes to 1.1.1.1:80, 0 to the tokyo IPs — but it
  had the SAME flaw (HTTP to game ports), so it is INCONCLUSIVE, not corroborating.
* **No client impact has been established by any measurement here.** The probe runs on
  the source host; its verdict is about that host's path, not a user's.

## 5. The relay identity question, and the retraction it forces

`ss -tnp` on the source host shows `leigod-linux-amd64` processes holding ESTABLISHED
connections to `:5438` on the same hosts and ranges the ultra probe dials (e.g.
`113.31.107.168:5438`, `113.31.112.106:5438`, `106.75.49.200:5438`). So `:5438` is the
Leigod server port and the ultra relays ARE those servers.

**Retraction:** an earlier reading of this work as 'a source-egress property' implied a
network path distinct from the working client. That framing is wrong and is withdrawn. What
the measurements support is the narrower statement in section 3 — our stack works, and
these destinations returned nothing — with the section 4 limits attached.

## 6. Method note that cost the most time

Three separate conclusions during this work were wrong in the same way: a field was read as
a verdict when it did not carry that information.

* `target_tcp_zero_downstream` reads 0 on a relay that answered with a refusal — the field
  told a human the fault was clean.
* The relay reply code is `0x01` on a successful carry — a 'general failure' that was not a
  failure.
* A zero-byte reply was read as 'the destination is unreachable' when it equally means 'the
  destination did not answer that payload'.

The rule that would have caught all three: **name the thing a field actually counts, and
check it against a control whose outcome you already know.**

---

## 7. RETRACTION (correction from the operator, 2026-09-14 ~18:20 UTC)

The operator identified why every tokyo destination returned zero bytes: the forward targets
are **Shadowsocks servers**, and a Shadowsocks server that receives an unframed request, or a
request with no valid password/cipher, **drops it silently by design** — precisely so it is
not probeable.

That invalidates the framing of section 4 and of the reading placed on it:

* **RETRACTED: "unresolved by construction with the payloads available."** It was not
  unresolvable. We sent a payload the service is designed to ignore, and then spent hours
  interpreting the silence. The correct statement is **unresolved because the payload was
  wrong**, not because measurement was impossible.
* **RETRACTED: "every non-HTTP destination is a blind spot in the instrument."** The
  instrument was not blind; it was pointed with the wrong protocol.
* **"Our relay and the native path are capability-equivalent" is TRUE BUT EMPTY.** Both
  paths were correct and both got nothing, because the payload was wrong — not the path.
* The 1.1.1.1:80 control answered because it is a plain HTTP server. 185.220.238.143:20007
  returned zero bytes through its own native path because it too is a Shadowsocks server
  doing exactly what SS does with a wrong payload. That was the clue, and it was read as a
  property of the destination instead.

**This does NOT explain the field symptom.** The ~6.2% zero-downstream on live forward 184
was measured on REAL CLIENT TRAFFIC WITH REAL SHADOWSOCKS FRAMING. The correction explains
the PROBE's zero-byte results. These are two separate things: a measurement error made here,
and an unexplained production symptom that still requires the client-side measurement.

### The general lesson — the third instance of one pattern

A payload the service is designed to ignore produces the same observation as a dead path.
That is the same class of error as reading a non-discriminating reply code as a verdict, and
as reading `target_tcp_zero_downstream == 0` as health. Each time, a field or a silence was
read as information it did not carry. The guard is the same: **use a control whose outcome is
already known, and make the payload one the service must answer.**

### Where the Shadowsocks material lives (bounded read-only search, ~18:30 UTC)

Values were never printed. Findings:

* `/opt/leigod/forwards.json` and `forwards.d/rules.json` — forwarding config only:
  `id, name, listen_port, target_host, target_port, exit, mode`. **No method, no password.**
* `/opt/leigod/resp_region0.json` — an API response cache; every `data` list in it is EMPTY.
* `/opt/leigod/lines/*.toml` — blocks `acc, device, local, rules` only; `local` carries only
  `socks_addr`.
* `/opt/leigod/.leigod/session.json` — `client_token, saved_at, user, web_token`.
* A recursive scan of `/opt/leigod/**/*.json` for the strings `"method"`, `"password"` or
  `"cipher"` found NO file.

Conclusion: **the Shadowsocks method and password are not persisted on this host in any file
found; they exist only in memory inside the running `leigod-linux-amd64` processes.** The
credentials for the tokyo forward targets are the operator's own and are not derivable from
anything on this host. The plain-SOCKS path (`127.0.0.1:20041`) works without credential
knowledge because the CLIENT holds them and performs the SS framing — but that path always
egresses through its own line, so it cannot substitute for pointing OUR relay at a chosen
destination.

Consequence for the retest: an SS-framed comparison requires credentials supplied by the
operator. It cannot be run from what is on the host.

### Separate observation

`tools/ultra-client` contains NO handling of the string `no_downstream_bytes`, yet the test
log emits `tcp candidate quarantined ... reason=no_downstream_bytes`. That string comes from
a layer outside `main.go` and should be located before any change to stream semantics that
assumes this file owns the decision.
