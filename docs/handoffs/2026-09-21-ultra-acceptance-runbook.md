# Ultra acceptance runbook: temporary forward rules, TCP and UDP separated (2026-09-21)

Operator-corrected test definition:

- Run the traffic **from the three test hosts** (Linux `172.21.255.189`,
  Windows `172.21.255.4`, macOS `100.103.253.21`) **toward overseas targets**.
  The earlier attempt measured sidecar-to-sidecar inside the same overseas
  network, which is the wrong direction and proves nothing about the user path.
- Create **temporary Ultra forward rules** for the test instead of reusing the
  long-lived production forwards (158/159/170/171/180).
- **Test TCP and UDP separately**, not only as a combined run.
- Several locations may run concurrently.

## Mechanism (recovered from the previous round)

Forwards are created by running a PHP script **on the panel host**, not through
a REST call:

```php
$root='/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master';
$config=new Leikwan\Master\Config($root.'/.env');
$db=new Leikwan\Master\Database\Database($config);
$p=new Leikwan\Master\Support\ProvisioningService($config,$db);

$payload=['forward_combination_id'=>$combo,'backend_mode'=>'ultra',
  'line_client_id'=>3,'listen_port'=>$port,'public_port'=>$port,
  'target_host'=>$host,'target_port'=>$targetPort,'transport_mode'=>'tcp+udp',
  'client_mode'=>'single','endpoint_mode'=>'direct','native_outbound'=>false];
$p->createForward(30,$payload,'admin',$nonce,$payloadHash);
```

`createForward` creates the row disabled; `setForwardEnabled($id,true,'admin')`
starts it, and `setForwardEnabled($id,false,'admin')` stops it. That second
call was broken until this session (see the pause-rollback fix), so any cleanup
path written before that fix silently failed to disable the forward.

Working examples from the previous round: forwards **198** (combo 51, HK sidecar
`70.36.96.183`, listen 60151) and **199** (combo 52, SG sidecar `77.93.90.45`,
listen 60152), both with `target_port` 60051. Receipt:
`tmp/hk-sg-client3-20260920/created-private.json`.

## Access paths that actually work

| Target | Path |
|---|---|
| panel | SSH entry parsed from `leikwan-v3.txt` by `tmp/hk-sg-client3-20260920/ops.py` |
| Linux client | password from `test-env.txt`; plain paramiko works |
| macOS client | `ops.py` uses key `/home/frankniubi/.ssh/leikwan_macos_build` and `key_filename` |
| Windows client | password from `test-env.txt`; plain paramiko works |

The macOS key exists **only inside WSL**, so macOS steps must be driven through
`wsl -e bash -lc ...`. A Windows-side paramiko cannot read that path.

## Separatated protocol measurements

`tmp/lk_throughput.py` drives a rate-controlled run through a chosen listen
port and a chosen final target, and reports both directions separately:

```
python3 lk_throughput.py --listen-port <port> --target-host <h> --target-port <udp> \
    --tcp-target-port <tcp> --mbps <udp_mbps> --tcp-mbps <tcp_mbps> \
    --seconds <n> --udp-streams <n> --tcp-streams <n>
```

For a UDP-only run pass `--tcp-streams 0`; for TCP-only pass `--udp-streams 0`.

## Target endpoints verified in this session

| Endpoint | Behaviour | Evidence |
|---|---|---|
| `185.220.238.143:60000` | UDP echo, replies to any payload | 40/40 through the tunnel, 0% loss |
| `185.220.238.143:62101` | UDP rate-pump sink, replies `LKT1ACK` | 20/20 through the tunnel with the magic prefix |
| `185.220.238.143:62102` | TCP responder, replies `LKT1ACK` | accepts connections, 8 conns / 50000 B in a 15s run |
| `185.220.238.143:10001` | Shadowsocks-none forwarder (`sing-box`, method none) | dials the final target named in the SS address header |

Private targets deployed by this session, removable in one command each:
`lk-target-pump.service` (62101/62102) and `lk-udp-echo-62001.service` on each
sidecar. `leikwan-external-uot.service` was never modified.

## Two harness bugs found and fixed the hard way

1. A bare payload is not a Shadowsocks request. The forwarder silently drops
   anything that is not `address-header || payload`. Every early "the target is
   dead" conclusion was this mistake.
2. A non-blocking `recvfrom` immediately after `sendto` races the round trip and
   counts every packet as lost. The drain must use `select`.

## Still required before a run counts as acceptance

1. A decision on which temporary forwards to create (ids, combinations, listen
   ports, and the overseas targets each should point at).
2. Confirmation that concurrent 20Mbps load against `185.220.238.143` is
   acceptable while it serves production UoT traffic.
3. Whether to test the deployed versions first (Linux `2026.09.19.1`, macOS
   `.6`) or to deploy the merged candidate first. The Linux host is
   agent-managed, so a candidate needs a bounded `LEIKWAN_UPDATE_HOLD_UNTIL`
   plus a rollback timer.
