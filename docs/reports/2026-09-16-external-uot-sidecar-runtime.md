# External UoT sidecar runtime

## Scope

`sidecar-agent/leikwan_external_uot.py` is an opt-in, operator-owned sidecar
for the new Ultra TCP carrier path. It does not alter
`client-agent/leikwan_uot.py`, `client-agent/leikwan_uot_bond.py`,
`sidecar-agent/leikwan_udp_uot_merge.py`, or any production default.

The data path is:

```text
client -> Ultra/LeiGod TCP carrier -> external sidecar -> fixed UDP target
```

The sidecar owns one connected UDP socket per 16-byte session ID. A carrier
reconnect with the same session ID during the grace period reuses that socket,
so the target-visible UDP source port remains stable.

## Wire contract

Every frame is:

```text
uint32 big-endian frame length (header + payload)
UOT2                    4 bytes
version                 uint8, 1
type                    uint8
flags                   uint16 big-endian
session_id              16 bytes
sequence                uint64 big-endian
payload_len             uint32 big-endian
payload                 payload_len bytes
```

The header is 36 bytes. The type values are fixed to:

| Type | Value | Direction | Meaning |
| --- | ---: | --- | --- |
| `HELLO` | 1 | client -> sidecar | Authentication and target binding |
| `HELLO_ACK` | 2 | sidecar -> client | Negotiated limits and `OKN`/`OKR` |
| `DATA` | 3 | client -> sidecar | Complete UDP datagram |
| `UDP_DATA` | 4 | sidecar -> client | Complete UDP datagram |
| `CLOSE` | 5 | either | End a carrier/session |
| `KEEPALIVE` | 6 | either | Keep the carrier idle timer alive |

`HELLO` JSON is:

```json
{"token":"hmac-sha256:<hex>","forward_id":17,"target":"127.0.0.1:53000","frame_payload_max":4096}
```

`HELLO_ACK` JSON contains:

```json
{"ok":true,"frame_payload_max":4096,"queue_packets":256,"session":"OKN"}
```

For a new session `session` is `OKN`; reattaching a live session is `OKR`.
The implementation rejects a changed forward or target for an existing session.

## Authentication and target policy

Each configured forward has a target allowlist. The HMAC token is derived from
the per-forward secret and the canonical binding:

```text
HMAC-SHA256(secret, "<forward_id>\n<normalized host:port>")
```

The wire representation is `hmac-sha256:<lowercase hex digest>`. A configured
static token is also supported for deployments whose control plane already
delivers a per-forward secret token. The target must still be in the explicit
allowlist in both cases; the sidecar never accepts an arbitrary target from a
client.

Example configuration (use a local secret, not this placeholder):

```json
{
  "listen_host": "0.0.0.0",
  "listen_port": 18443,
  "frame_payload_max": 65507,
  "queue_packets": 256,
  "keepalive_seconds": 10,
  "idle_timeout_seconds": 90,
  "session_grace_seconds": 30,
  "forwards": {
    "17": {
      "secret": "replace-with-a-local-secret",
      "targets": ["127.0.0.1:53000"]
    }
  }
}
```

Run it explicitly with:

```text
python3 sidecar-agent/leikwan_external_uot.py --config /etc/leikwan-external-uot.json
```

## Queues and tuning

The client-to-target and target-to-client directions are asynchronous. A
bounded per-session outbound queue prevents an unreachable carrier from
unboundedly consuming memory. Queue overflow and payload oversize are counted
and dropped; the sidecar does not turn UDP loss into a fake successful ACK.

`AdaptiveTuner` uses an RTT EMA plus bounded AIMD reactions:

- eight stable RTT observations increase the recommended frame budget and
  trim queue capacity toward the lower bound;
- queue pressure or reconnect halves the frame budget and increases the queue
  recommendation;
- all frame and queue recommendations remain within configured bounds.

The negotiated frame limit is kept as the live session hard cap, so a tuning
reaction cannot shrink an already accepted datagram size mid-session. The Go
client should use the tuner recommendation for subsequent carrier handshakes
or an explicit control-plane refresh.

## Client integration

`tools/ultra-client` now has an explicit `external_uot` configuration block.
For a UDP-capable ordinary Ultra forward, the client keeps the local UDP
listener, probes the sidecar through the existing command-1 TCP carrier, and
uses one stable 16-byte session per local UDP peer. The carrier payload uses
the existing Ultra XOR stream transform; the UoT frame itself is not sent
plaintext to the provider socket. A reconnect retries the same session ID and
therefore receives `OKR` while the sidecar grace window is alive. Native
command-3 UDP remains the default when the block is absent.

The installer and panel broker carry the block only when the forward explicitly
sets it. The sidecar target, token, queue, keepalive, grace, and
`frame_payload_max` values are bounded before being written to the root-only
runtime configuration. External-UoT selection health uses the TCP carrier plus
sidecar HELLO instead of requiring a native UDP probe, which avoids turning a
working UoT line into `no_healthy_candidate`.

## Verification

```text
python -m unittest -v tests/test_external_uot_runtime.py
```

Result: 5 tests passed. Coverage includes 36-byte header round-trip, two
datagrams written before either response (asynchronous bidirectional relay),
HMAC and target rejection, AIMD/EMA bounds, and `OKR` reattach with a stable
target-visible UDP source port.

```text
python -m py_compile sidecar-agent/leikwan_external_uot.py tests/test_external_uot_runtime.py
git diff --check -- sidecar-agent/leikwan_external_uot.py tests/test_external_uot_runtime.py
```

Both checks pass. The Go frame, tuner, and XOR-carrier tests pass, and the
client-agent installer tests pass. No live Japan sidecar deployment or default
transport switch is included; the mode remains an explicit per-forward opt-in.

## Integration note

The Go carrier uses the exact `frame_payload_max` HELLO/ACK keys and the six
type values above. `KEEPALIVE` is echoed with type 6; there is no type-7
`KEEPALIVE_ACK`. The older `max_datagram_bytes` JSON key is not part of this
wire contract.

## Panel surface wiring (2026-09-16, follow-up)

The admin form, the forward-combination persistence, the installer, and the Go
client all understood the `external_uot` block, but the two hops between them
did not carry it:

- `ClientAgentController::attachUltraAccessGrants()` never copied the
  published sidecar contract onto the forward it hands to
  `LeigodTokenBroker`, so `ultra_access.external_uot` was always empty and
  `install_forward_runtime.py` silently fell back to native command-3 UDP;
- `ClientAgentController::forwardRevision()` did not project the published
  `forward_combination_external_uot_revision` digest, so enabling, retuning, or
  disabling the sidecar left the client polling on an unchanged revision while
  still running its previous transport.

Both hops are now wired, and the admin surface strings for the sidecar block
("Enable External UoT", "External UoT sidecar", "Sidecar target",
"Sidecar token", "Queue packets", "Keepalive seconds",
"Reconnect grace seconds", "Frame payload max", and the grant-only token note)
are registered in the zh-Hans and zh-TW dictionaries.

Verification:

```text
php master/tests/client_agent_external_uot_grant_wiring_test.php
php master/tests/client_configs_external_uot_revision_test.php
php master/tests/i18n_locale_regression_test.php
php master/tests/ultra_external_uot_metadata_test.php
php master/tests/admin_forward_combination_leigod_field_test.php
php master/tests/saveforwardcombination_ultra_inventory_test.php
python -m pytest client-agent/tests/test_ultra_runtime.py tests/test_external_uot_runtime.py -q
```

Both new tests fail against the unwired controller with
"external UoT contract from the forward combination never reached the token
broker" and "forward_revision did not change when the external UoT sidecar was
enabled". The full `master/tests` sweep reports 383 pass / 16 fail / 399; those
16 failures reproduce on a pristine `da038db3` worktree and are unrelated to
the Ultra or UoT surface. Delivery is still control-plane only: no live sidecar
traffic is exercised by these tests.
