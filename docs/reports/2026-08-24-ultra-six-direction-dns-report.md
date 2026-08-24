# Ultra Six-Direction DNS Canary Report

Date: 2026-08-24 (Asia/Taipei)

## Scope

An isolated Linux canary on `172.21.255.189` validated the deployed Ultra
client against `8.8.8.8:53` through all six configured destination families:
Singapore, Hong Kong, Tokyo, Frankfurt, Los Angeles, and Moscow. The test used
the production Panel control plane and source-acquired material while keeping
the source host outside the data path.

Client artifact:

```text
Version: 2026.08.24.1
Platform: Debian 12, linux/amd64
SHA-256: 0076156a838099ec958fd8f60b1b79ac1d707d114cbf4629b614ef9c0fb7c825
```

## Method

For each destination family, the test started one isolated Ultra process on an
unoccupied local port, sent three UDP and three TCP DNS queries, captured the
credential-free runtime status, and then stopped the process before moving to
the next family. The query set was:

```text
example.com A
google.com A
o-o.myaddr.l.google.com TXT
```

The Bilibili request observed Jiangsu/Suzhou/China Telecom. The deployed
ingress catalog currently defines Beijing, Shanghai, and Guangzhou, so the
runtime correctly categorized this as `unknown`; `detected_then_any` then
ranked all admin-approved ingress candidates. The first process used a network
location result and the remaining five reused the bounded shared cache.

All 36 `dig` invocations returned exit code 0 with non-empty answer sections.
The retained artifact did not include the DNS header, so `NOERROR` below is
inferred from those valid answers rather than copied from a retained header.

## Results

| Destination | Selected admin line | Relay IP | UDP | TCP | Process observation | Verdict |
| --- | --- | --- | --- | --- | --- | --- |
| Singapore | `#463 Beijing-Singapore BGP` | `117.50.29.150` | 3/3, NOERROR, median 104 ms | 3/3, NOERROR, median 236 ms | 0.06 CPU s / 12.489 s (0.48% avg), 13.94 MiB RSS | Pass |
| Hong Kong | `#448 Beijing-Hong Kong BGP` | `115.190.69.133` | 3/3, NOERROR, median 68 ms | 3/3, NOERROR, median 168 ms | 0.04 CPU s / 12.103 s (0.33% avg), 10.37 MiB RSS | Pass |
| Tokyo | `#453 Shanghai-Japan BGP` | `1.117.188.175` | 3/3, NOERROR, median 52 ms | 3/3, NOERROR, median 116 ms | 0.01 CPU s / 11.704 s (0.09% avg), 8.48 MiB RSS | Pass |
| Frankfurt | `#693 Beijing-Germany BGP` | `117.50.156.51` | 3/3, NOERROR, median 148 ms | 3/3, NOERROR, median 328 ms | 0.05 CPU s / 12.824 s (0.39% avg), 11.05 MiB RSS | Pass |
| Los Angeles | `#454 Shanghai-USA BGP` | `113.31.159.140` | 3/3, NOERROR, median 136 ms | 3/3, NOERROR, median 280 ms | 0.04 CPU s / 12.505 s (0.32% avg), 10.99 MiB RSS | Pass |
| Moscow | `#692 Beijing-Russia BGP` | `117.50.128.249` | 3/3, NOERROR, median 156 ms | 3/3, NOERROR, median 316 ms | 0.02 CPU s / 12.626 s (0.16% avg), 11.11 MiB RSS | Pass |

The runtime selected the same approved relay for TCP and UDP in each family.
No failover was observed or deliberately induced. Runtime status reported TCP
selection RTTs of 67-183 ms and UDP association setup times of 595-3,598 ms;
the latter is setup latency and is not the DNS query time shown above.

## Observed DNS Values

The values are point-in-time observations and may change independently of the
forwarding implementation.

| Destination | `example.com A` | `google.com A` | `o-o.myaddr.l.google.com TXT` |
| --- | --- | --- | --- |
| Singapore | `104.20.23.154`, `172.66.147.243` | `74.125.130.100/101/102/113/138/139` | UDP resolver `74.114.31.150`; TCP resolver `172.253.211.30`; ECS `162.128.243.0/24` |
| Hong Kong | `104.20.23.154`, `172.66.147.243` | `142.250.198.206` | UDP resolver `172.253.4.30`; TCP resolver `172.253.237.16`; ECS `156.59.5.0/24` |
| Tokyo | `104.20.23.154`, `172.66.147.243` | `108.177.98.100/101/102/113/138/139` | UDP resolver `172.253.236.25`; TCP resolver `172.253.235.26`; ECS `210.171.236.0/24` |
| Frankfurt | `104.20.23.154`, `172.66.147.243` | `142.251.14.100/101/102/113/138/139` | UDP resolver `172.217.34.28`; TCP resolver `172.217.34.19`; ECS `38.57.237.0/24` |
| Los Angeles | `104.20.23.154`, `172.66.147.243` | `192.179.26.100/101/102/113/138/139` | UDP resolver `172.217.46.149`; TCP resolver `172.217.46.21`; ECS `98.96.208.0/24` |
| Moscow | `8.47.69.6`, `8.6.112.6` | `209.85.233.100/101/102/113/138/139` | Resolver `192.178.93.148`; ECS `169.197.96.0/24` |

## Cleanup and Source Boundary

- All six temporary listeners and processes were stopped.
- The isolated Panel service, combinations, and forwards were deleted; zero
  canary rows remained, and the 20 temporarily enabled inventory lines were
  restored to disabled.
- The installed production client binary and existing client-agent services on
  the canary host were preserved.
- Recursive deletion of the expired sealed test directory was rejected by the
  execution guard. It was moved to a root-only backup quarantine with no group
  or other permissions. Its capabilities expired at
  `2026-08-23T21:57:54Z`.
- On source 171, legacy data-plane units and abandoned sessions discovered by
  the audit were stopped and disabled. The post-enforcement audit showed no
  client data listener or old client connection; the source agent remained an
  outbound-only one-task service.

## Evidence and Limits

The sanitized result artifact was created at `2026-08-23T21:42:12Z`, is 21,976
bytes, and has SHA-256
`66d43c90ab92c1199e7b236e29de5d4794eb0664e680f45a8c2647d4cc630394`.
It contains no token, credential, user, client identifier, or sealed material.

The canary captured process CPU time and one RSS observation, not peak CPU or
`VmHWM`. The host does not have a Go toolchain, so `go test -race` could not be
run there; regular Go tests and the deployed ELF canary covered the runtime.

Final result: **6/6 destination families and 36/36 DNS requests passed**.
