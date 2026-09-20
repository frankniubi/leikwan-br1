# macOS external-UoT continuation

## Final acceptance: PASS

The repaired **managed Mac canary**, not the old installed release, passed
the requested 720-second simultaneous TCP/UDP test on September 19, 2026.
Measurement started at **15:48:53 Asia/Taipei** (07:48:53 UTC), with final
drain/receipt collection ending at 16:01:05.

| Metric | Result |
|---|---:|
| TCP completed requests / failures | **13,440 / 0** |
| TCP application throughput | **20.103 Mbps** |
| UDP unique received / server sent | **1,500,000 / 1,500,000** |
| UDP missing / duplicate / invalid packets | **0 / 0 / 0** |
| UDP application throughput inside measurement window | **19.992 Mbps** |
| Reliable queue drops / timeouts / sequence gaps / unrecoverable frames | **0 / 0 / 0 / 0** |
| Mac runtime PID changes | **0** |
| Observed fresh lease versions | **4**, same healthy candidate retained |
| Mac helper average CPU / peak RSS | **36.00% of one core / 94.11 MiB** |
| Sidecar average CPU / peak RSS | **12.99% of one core / 24.86 MiB** |
| Observed Tokyo UDP socket drops | **0** |

Application delivery included 568 packets arriving during the explicit drain
period after the 720-second measurement window. They are included in the
complete received count, but **not** the reported within-window throughput.
The independent final runtime delivered sequence was 1,500,030, including
control/probe traffic; the application data count above remains 1,500,000.

Client SHA-256:
`fe4829543f8b22f38adc5bd927163c78e08fc95ea2bfa5a82342c33cd9187320`.
Sidecar SHA-256:
`0946aacb37ccfcb230a92ea2fa35a93e8bcc2c913abf954248fe6b2de15b6c49`.
The managed Core compatibility DLL was
`79f4e2d0148bd36d4641b64577e43e87276444ba1b9c48f682d56e202b962e50`.
The wrapper required the managed panel's actual grant, modifying only the
test sidecar endpoint, reliable delivery mode, and queue setting.

This is the requested Mac test gate, **not** a released/notarized Mac package,
new Linux/Windows runtime acceptance, or unconditional enterprise zero-loss
guarantee. FEC was not enabled.

## Scope

The requested gate is a complete 720-second run at 20,000,000 bit/s per
protocol, with 128 TCP workers and 1,500,000 unique 1,200-byte UDP data packets.
The client address is `127.0.0.1:10001`, Shadowsocks `none`.
UDP follows the leased Ultra provider relay and authenticated external UoT
sidecar. No LeiKwan slave/CDN or direct client-to-sidecar data path is involved.

Source changes are in `C:\tmp\leikwan-enterprise-uot-20260919`, on top of the
master-derived Linux/Windows source commit `d5876de6`. The original dirty
checkout is preserved.

Committed fixes (not pushed):

- `2cc604e7`: preserve revalidated candidate identity across credential rotation.
- `b0875fa3`: reject metadata ACK followed by immediate EOF before application bytes.
- `7a663b19`: retain reliable windows through bounded carrier backpressure.

## Confirmed corrections

1. **Rotating provider credentials broke path continuity.** The path comparison
   required a stable provider username or selection reference. The panel's
   candidate hash identifies the same logical line even when both values
   rotate. Matching now requires the same endpoint and valid matching
   candidate hashes when both are available. Only the newly granted,
   successfully revalidated lease is admitted; old credentials are not reused.
   A regression test first failed by selecting the faster unrelated candidate,
   then passed after the change. Scope, withdrawal, endpoint-change, and
   different-line checks remain enforced.
2. **Metadata-prelude TCP handshakes accepted an already closed connection.**
   The `05 02` ACK path checked for immediate FIN/reset, but the 14-byte
   metadata-prelude path did not. Both now keep immediate EOF/reset in the
   setup phase, before consuming any application payload. A bounded
   non-consuming peek preserves server-first payload. No application request
   replay is introduced. The real TCP regression test accepted a dead stream
   before this repair and rejects it afterward.
3. **Reliable admission used a raw-UDP timeout.** Under the observed carrier
   stall, the sidecar destroyed 4,097 frames after its 4,096-packet window
   filled and a 250 ms admission deadline expired. Reliable admission now
   waits at most five seconds, matching a carrier write-attempt budget.
   Earlier caller deadlines and cancellation still win; packet and byte
   limits remain unchanged. Both standalone transport copies and their
   regression tests were updated. A 350 ms simulated stalled ACK fails before
   the repair and succeeds afterward without lost retained frames.

## Test-harness corrections and invalid historical claims

- A running SSH command or local listener is not evidence that the data plane
  remained healthy. Earlier progress messages inferred stability from these
  facts; those statements must not be used as acceptance evidence.
- Earlier 120-second runs were two-minute preflights, not 12-minute acceptance.
- An old `ultra-candidate` and the revision-8 executable are distinct binaries.
  Later runs attest the running binary, PID, grant source, status, and hashes.
- A route JSON string claiming `reliable_datagram_v1` did not prove that mode
  was actually passed into the runtime. Runs without reliable counters are
  not reliable-mode evidence. The corrected wrapper sets the real bootstrap
  field and the runtime publishes sequence/ACK counters.
- The first long run in this continuation lost its test sidecar and test
  target when their 1,800-second lifetimes expired. The journal explicitly
  records `RuntimeMaxSec` expiry. It is an invalid product verdict.
- The original target Shadowsocks process did not contain the existing
  backpressure repair. Its staged replacement is now SHA-256
  `606559fc9225e27470f3068a2d7bc51ffceb838e0df6e18865551c763b311b67`,
  guarded by a rollback timer.
- The signed installed Mac helper was restored from its verified backup;
  experiments now use the separate canary bundle. The final test wrapper
  requires the real managed panel grant rather than inventing a grant.
- Every new acceptance run has a timestamped output directory and live
  Mac/Tokyo metrics. Earlier outputs overwritten by older helpers are not
  recoverable; preserved summaries and archived outputs remain failures.

## Preserved results

| Run (UTC start, September 19) | TCP | UDP | Verdict |
|---|---|---|---|
| 06:38:31, old helper | 4,710 OK; 68,504 header EOF, 50 header timeout; 7.074 Mbps | 1,499,585 / 1,500,000 | FAIL |
| 06:57:48, revision 8 | 12,692 OK; 3,678 header EOF; 19.062 Mbps | 1,500,000 / 1,500,000 | FAIL TCP |
| 07:16:50, revision 9 | 13,305 OK; 2 header EOF; 19.862 Mbps | 1,500,000 / 1,500,000; 19.992 Mbps | FAIL TCP |
| 07:35:32, revision 10 | 13,431 OK; 0 errors; 20.012 Mbps | 142,328 / 1,500,000; reliable admission deadline exceeded | FAIL UDP |
| 07:48:53, revision 11 | 13,440 OK; 0 errors; 20.103 Mbps | 1,500,000 / 1,500,000; 19.992 Mbps | **PASS** |

Revision 9 retained one runtime PID and the same candidate through six
observed lease versions. Mac CPU averaged 27.87% of one core and peak RSS was
85.48 MiB. Its reliable queue drop/timeout, sequence gap, and unrecoverable
counters were zero; 6,482 duplicate carrier frames were discarded, with no
application duplicates. The two remaining TCP failures followed a primary
setup timeout and a fallback returning immediate EOF.

Revision 11 adds the reliable admission repair. All client tests passed;
Linux race suites passed for the client (160.329 seconds) and sidecar
(3.221 seconds). Ten shared transport files are byte-identical.

## Cleanup and deployment status

Cleanup completed after collecting the passing run. The canary app and its
owned children were stopped. The original Mac config and signed installed
helper were restored and read back at
`cfd542e9fb69a84446c3a0552fcf6041406b004401dba3b0cb9d64a5cc2f3209`.
The installed app restarted as PID 35649 with the port-10001 helper PID 35678.
This restoration is **not** a claim that the old installed release contains
the new fixes: packaging, signing, and public release are still pending.

Tokyo's temporary sidecar/target services and rollback timer were stopped.
The original Shadowsocks service was restored, active as PID 1777463, with
SHA-256 `832ead23c40dd1652d8a2dc5c05e6496358c6071a0c4be2e188e9b302e869a33`.
No WireGuard `conf32` restart was needed.

The closed main reliable sidecar session `f6f48494` independently reported
final cumulative ACK **1,500,005** (1,500,000 data packets plus five completion
receipts), zero retained frames, zero queue timeout/drop, zero sequence gap,
and zero unrecoverable frames. Its final EOF was the deliberate cleanup.

## Evidence

Local evidence is under `tmp/enterprise-uot-20260919/`:

- `verified-20260919T071647Z-summary.json`
- `verified-20260919T071647Z-verdict.json`
- `verified-20260919T071647Z-{mac,tokyo}-metrics.jsonl`
- `mac-r9-error-details.txt`
- `mac-panel-grant-wrapper.txt`
- `mac-verified-stage.txt`, `tokyo-verified-stage.txt`
- `verified-20260919T074852Z-summary.json`
- `verified-20260919T074852Z-verdict.json`
- `verified-20260919T074852Z-{mac,tokyo}-metrics.jsonl`
- `mac-final-cleanup.txt`, `tokyo-final-cleanup.txt`

The acceptance program's own `reliable_udp_verdict` stays `NOT_TESTED` because
it measures application delivery, not the tunnel protocol. Independent
runtime sequence/ACK counters are evaluated separately. macOS per-socket
receive-drop counters are unknown. FEC and guaranteed application-ACK delivery
are not implemented or claimed.
