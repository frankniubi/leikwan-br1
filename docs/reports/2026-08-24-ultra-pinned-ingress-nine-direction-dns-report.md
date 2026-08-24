# Ultra Pinned-Ingress Nine-Direction DNS Canary Report

Date: 2026-08-24 (Asia/Taipei)

## Scope

An isolated Linux canary on `172.21.255.189` tested `1.1.1.1:53` through nine
Ultra directions with the ingress city fixed by the operator:

| Alias | Required ingress | Destination family | Only approved line |
| --- | --- | --- | --- |
| SIN | Guangzhou | Singapore | `#464 Guangzhou-Singapore BGP` |
| HKG | Guangzhou | Hong Kong | `#447 Guangzhou-Hong Kong BGP` |
| LAX | Shanghai | Los Angeles | `#454 Shanghai-USA BGP` |
| TYO | Shanghai | Tokyo | `#453 Shanghai-Japan BGP` |
| SEO | Shanghai | South Korea | `#451 Shanghai-Korea BGP` |
| MOS | Beijing | Moscow | `#692 Beijing-Russia BGP (Mac)` |
| FRA | Beijing | Frankfurt | `#693 Beijing-Germany BGP (Mac)` |
| TPE | Fuzhou | Taiwan | `#41 Fuzhou-Taiwan BGP` |
| AUS | Guangzhou | Sydney | `#39 Guangzhou-Sydney BGP` |

Each temporary combination had exactly one enabled member. Its
`ultra_allowed_ingress_zones_json` contained only the required ingress, so a
different ingress could not be selected as fallback.

Client artifact:

```text
Version: 2026.08.24.1
Platform: Debian 12, linux/amd64
SHA-256: 0076156a838099ec958fd8f60b1b79ac1d707d114cbf4629b614ef9c0fb7c825
```

## Method

One Ultra process ran at a time. Each used `tcp+udp`, a 10 Mbps canary rate
limit, two probe workers, eight maximum TCP connections, and a 32-packet UDP
queue. A successful direction required three valid UDP and three valid TCP DNS
responses for:

```text
example.com A
google.com A
o-o.myaddr.l.google.com TXT
```

Every successful query returned `NOERROR` with a non-empty answer section.
The client location was outside the configured ingress aliases and was reported
as `unknown`; this did not widen the candidate set because each combination had
only its pinned line.

## Final Results

| Alias | Ingress | Line | Selected relay | Successful attempt | UDP | TCP | CPU time | Peak RSS |
| --- | --- | --- | --- | --- | --- | --- | ---: | ---: |
| SIN | Guangzhou | `#464` | `124.71.119.122` | 1 | 3/3, median 80 ms | 3/3, median 200 ms | 0.05 s | 11.88 MiB |
| HKG | Guangzhou | `#447` | `106.75.129.175` | 1 | 3/3, median 40 ms | 3/3, median 120 ms | 0.05 s | 13.93 MiB |
| LAX | Shanghai | `#454` | `124.220.195.144` | 1 | 3/3, median 148 ms | 3/3, median 312 ms | 0.02 s | 10.55 MiB |
| TYO | Shanghai | `#453` | `106.75.227.43` | 2, after material refresh | 3/3, median 40 ms | 3/3, median 92 ms | 0.01 s | 9.04 MiB |
| SEO | Shanghai | `#451` | `106.75.227.28` | 1 | 3/3, median 36 ms | 3/3, median 76 ms | 0.01 s | 11.10 MiB |
| MOS | Beijing | `#692` | `117.50.128.250` | 2, after material refresh | 3/3, median 116 ms | 3/3, median 260 ms | 0.02 s | 11.20 MiB |
| FRA | Beijing | `#693` | `117.50.156.54` | 1 | 3/3, median 148 ms | 3/3, median 312 ms | 0.01 s | 8.92 MiB |
| TPE | Fuzhou | `#41` | `150.242.96.228` | 1 | 3/3, median 40 ms | 3/3, median 100 ms | 0.02 s | 11.11 MiB |
| AUS | Guangzhou | `#39` | `42.240.153.93` | 1 | 3/3, median 152 ms | 3/3, median 340 ms | 0.01 s | 10.48 MiB |

TCP and UDP selected the same relay for every successful direction. No line
failover occurred, and all local ports were released after their process
stopped. Across the successful attempts, **9/9 directions and 54/54 DNS
queries passed**.

## Refresh Retry

The first TYO and MOS processes were rejected by the upstream with a bare
`EOF` after approximately 2.46 seconds. They exited before any DNS query could
be sent, so the first attempt recorded `0/3` UDP and `0/3` TCP rather than
claiming a network query failure.

At that time the Panel still considered the material valid:

| Direction | Old acquired time | Nominal expiry | Failure |
| --- | --- | --- | --- |
| TYO `#453` | `2026-08-24T11:20:16Z` | `2026-08-24T11:50:16Z` | upstream `EOF` |
| MOS `#692` | `2026-08-24T11:20:21Z` | `2026-08-24T11:50:21Z` | upstream `EOF` |

When the normal ten-minute refresh window opened, source 171 acquired fresh
material at `11:41:25Z` and `11:41:28Z`. New capabilities for the same forward,
same line, and same ingress immediately passed all twelve retry queries. This
is consistent with upstream material becoming unusable before its advertised
expiry; it was not caused by selecting the wrong ingress. The evidence does
not distinguish an upstream session invalidation from another provider-side
rejection mechanism.

The refresh also rotated relay lists. SEO, FRA, and AUS completed against the
sealed pre-refresh generation, so their selected relay no longer appeared in
the Panel's later current-generation list. Singleton combination membership
still proves their exact line identity. Historical status mapping should not
rely only on the latest `relay_ips_json` after a generation change.

## Observed Answers

The following point-in-time values may change independently of Ultra:

| Alias | `example.com A` | `o-o.myaddr.l.google.com TXT` (UDP / TCP) |
| --- | --- | --- |
| SIN | `104.20.23.154`, `172.66.147.243` | `172.70.145.84` / `162.158.161.90` |
| HKG | `104.20.23.154`, `172.66.147.243` | `172.70.32.11` / `2400:cb00:975:1024::ac46:200c` |
| LAX | `104.20.23.154`, `172.66.147.243` | `2400:cb00:467:1024::ac47:9d76` / `2400:cb00:1232:1024::6816:160b` |
| TYO | `104.20.23.154`, `172.66.147.243` | `2400:cb00:1008:1024::ac40:d40b` / `2400:cb00:448:1024::ac46:dded` |
| SEO | `104.20.23.154`, `172.66.147.243` | `162.158.15.13` / `141.101.83.26` |
| MOS | `8.47.69.0`, `8.6.112.0` | `2400:cb00:904:1024::ac44:a70b` / `2400:cb00:87:1024::ac44:90b` |
| FRA | `104.20.23.154`, `172.66.147.243` | `172.69.149.207` / `2400:cb00:71:1024::a29e:5507` |
| TPE | `104.20.23.154`, `172.66.147.243` | `2400:cb00:80:1024::a29e:f169` / `2400:cb00:80:1024::a29e:f169` |
| AUS | `104.20.23.154`, `172.66.147.243` | `2400:cb00:491:1024::a29e:2726` / `172.68.209.7` |

The `o-o.myaddr.l.google.com` value identifies the recursive resolver path seen
by Google, not the Ultra relay's public exit address.

## Cleanup

- The Panel database was backed up before the canary.
- The temporary service, nine combinations, and nine forwards were deleted.
- All nine lines were restored to their original disabled state.
- Panel-side sealed access files were removed. The source's next jobs request
  returned zero lines and no error.
- The client had zero canary processes and zero occupied canary ports after the
  run. Its installed binary and active Client Agent were preserved.
- Recursive deletion was rejected by the execution guard, so the client test
  directory was moved to a root-only `0700` quarantine. Both local sealed
  archives were reduced to zero bytes. The latest retry capabilities expire by
  `2026-08-24T12:02:22Z`.
- The public Panel health endpoint returned HTTP 200 after cleanup.

Credential-free evidence hashes:

```text
Initial result JSON: 37c0d4ebbbd002f2722373f584e6665b6b101f833601bd5004c4a2df36f36cd6
Retry result JSON:   ebf8a49c489a1ac602e7bdd60dd1df8fdb0a47521336b051810092458e1c82a3
```

Final result: **the requested ingress topology passed all nine directions**.
