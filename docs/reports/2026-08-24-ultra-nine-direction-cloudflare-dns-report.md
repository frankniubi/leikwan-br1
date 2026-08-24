# Ultra Nine-Direction Cloudflare DNS Canary Report

Date: 2026-08-24 (Asia/Taipei)

## Scope

An isolated Linux canary on `172.21.255.189` tested the deployed Ultra client
against `1.1.1.1:53` through nine destination families: Singapore, Hong Kong,
Tokyo, Frankfurt, Los Angeles, Moscow, South Korea, Taiwan, and Sydney.

The operator aliases map as follows:

- `SEO` used the provider's South Korea family. The catalog does not prove the
  physical exit city is Seoul.
- `TPE` used the provider's Taiwan family. The catalog does not prove the
  physical exit city is Taipei.
- `AUS` used the explicitly cataloged Sydney line.

Client artifact:

```text
Version: 2026.08.24.1
Platform: Debian 12, linux/amd64
SHA-256: 0076156a838099ec958fd8f60b1b79ac1d707d114cbf4629b614ef9c0fb7c825
```

## Method

One temporary Ultra process ran at a time. Each process used one explicitly
selected line, `tcp+udp`, a 10 Mbps canary rate limit, two probe workers, eight
maximum TCP connections, and a 32-packet UDP queue. For each direction the
canary sent three UDP queries and three TCP queries:

```text
example.com A
google.com A
o-o.myaddr.l.google.com TXT
```

A query counted as successful only when `dig` exited successfully, reported
`NOERROR`, and returned a non-empty answer section. The client location lookup
reported an unclassified Jiangsu source; `detected_then_any` therefore used the
single admin-approved candidate for each test family. The first process used a
network location lookup and the remaining processes reused the bounded cache.

## Results

| Alias | Destination family | Selected line | Relay IP | UDP | TCP | CPU time | Peak RSS | Verdict |
| --- | --- | --- | --- | --- | --- | ---: | ---: | --- |
| SIN | Singapore | `#463 Beijing-Singapore BGP` | `117.50.29.168` | 3/3, NOERROR, median 100 ms | 3/3, NOERROR, median 228 ms | 0.06 s | 12.53 MiB | Pass |
| HKG | Hong Kong | `#448 Beijing-Hong Kong BGP` | `115.190.69.151` | 3/3, NOERROR, median 64 ms | 3/3, NOERROR, median 152 ms | 0.01 s | 8.99 MiB | Pass |
| TYO | Tokyo | `#453 Shanghai-Japan BGP` | `113.31.108.86` | 3/3, NOERROR, median 48 ms | 3/3, NOERROR, median 116 ms | 0.01 s | 9.11 MiB | Pass |
| FRA | Frankfurt | `#693 Beijing-Germany BGP (Mac)` | `117.50.156.54` | 3/3, NOERROR, median 144 ms | 3/3, NOERROR, median 320 ms | 0.01 s | 9.11 MiB | Pass |
| LAX | Los Angeles | `#454 Shanghai-USA BGP` | `124.220.195.144` | 3/3, NOERROR, median 148 ms | 3/3, NOERROR, median 312 ms | 0.01 s | 11.04 MiB | Pass |
| MOS | Moscow | `#692 Beijing-Russia BGP (Mac)` | `117.50.128.250` | 3/3, NOERROR, median 116 ms | 3/3, NOERROR, median 260 ms | 0.01 s | 8.41 MiB | Pass |
| SEO | South Korea | `#451 Shanghai-Korea BGP` | `106.75.227.28` | 3/3, NOERROR, median 36 ms | 3/3, NOERROR, median 76 ms | 0.02 s | 11.05 MiB | Pass |
| TPE | Taiwan | `#467 Guangzhou-Taiwan BGP` | `106.75.169.113` | 3/3, NOERROR, median 56 ms | 3/3, NOERROR, median 144 ms | 0.01 s | 9.11 MiB | Pass |
| AUS | Sydney | `#39 Guangzhou-Sydney BGP` | `42.240.153.92` | 3/3, NOERROR, median 156 ms | 3/3, NOERROR, median 336 ms | 0.01 s | 11.04 MiB | Pass |

TCP and UDP selected the same relay in every family. No failover was observed.
All nine local TCP and UDP ports were released after their process stopped.

## Observed Answers

The values below are point-in-time DNS observations. Google and Cloudflare may
change them independently of the forwarding implementation.

| Alias | `example.com A` | Observed `google.com A` set | `o-o.myaddr.l.google.com TXT` (UDP / TCP) |
| --- | --- | --- | --- |
| SIN | `104.20.23.154`, `172.66.147.243` | `172.217.70.{100,101,102,113,138,139}`; `74.125.24.{100,101,102,113,138,139}` | `2400:cb00:961:1024::ac47:f40b` / `2400:cb00:1244:1024::6816:430b` |
| HKG | `104.20.23.154`, `172.66.147.243` | `108.177.125.{100,101,102,113,138,139}`; `142.250.197.78` | `162.158.176.9` / `2400:cb00:581:1024::ac47:d1a4` |
| TYO | `104.20.23.154`, `172.66.147.243` | `142.250.21.{100,101,102,113,138,139}`; `142.251.24.{100,101,102,113,138,139}` | `2400:cb00:1174:1024::a29f:6f0d` / `2400:cb00:1009:1024::ac40:d60c` |
| FRA | `104.20.23.154`, `172.66.147.243` | `142.251.13.{100,101,102,113,138,139}`; `142.251.127.{100,101,102,113,138,139}` | `2400:cb00:470:1024::ac46:f158` / `2400:cb00:931:1024::ac44:140b` |
| LAX | `104.20.23.154`, `172.66.147.243` | `142.251.218.174`; `142.251.219.14` | `162.158.165.148` / `2400:cb00:1231:1024::6816:130c` |
| MOS | `8.47.69.0`, `8.6.112.0` | `172.253.152.{100,101,102,113,138,139}`; `74.125.205.{100,101,102,113,138,139}` | `2400:cb00:903:1024::ac45:320d` / `172.68.9.11` |
| SEO | `104.20.23.154`, `172.66.147.243` | `172.217.209.{100,101,102,113,138,139}`; `142.251.118.{100,101,102,113,138,139}` | `172.71.109.71` / `162.158.15.13` |
| TPE | `104.20.23.154`, `172.66.147.243` | `142.250.204.46`; `142.250.198.78` | `162.158.241.32` / `162.158.241.105` |
| AUS | `104.20.23.154`, `172.66.147.243` | `142.251.42.110`; `142.251.222.238` | `2400:cb00:26:1024::6ca2:f80a` / `162.158.39.38` |

The `o-o.myaddr.l.google.com` value identifies the recursive resolver path
observed by Google; it is not a direct proof of the Ultra relay's public exit
address.

## Cleanup

- The Panel database was backed up before the canary.
- The temporary service, nine combinations, and nine forwards were deleted.
- All nine selected lines were restored to their original disabled state.
- The next source poll requested zero lines and reported no error.
- Panel-side sealed access files were removed. The nine capabilities were
  self-contained and expire at `2026-08-24T11:42:29Z`.
- The client had zero canary processes and zero occupied canary ports after the
  run. Its installed binary and active Client Agent were preserved.
- The client test directory was moved to a root-only `0700` quarantine because
  recursive deletion was rejected by the execution guard. The local sealed
  archive was reduced to zero bytes.
- The public Panel health endpoint returned HTTP 200 after cleanup.

The credential-free result JSON was created at `2026-08-24T11:26:37Z` and has
SHA-256 `7afc8dc4a72701d43007655dc6ff0eb7f73b784ae6c40dfe599723907c953f01`.

Final result: **9/9 destination families and 54/54 DNS requests passed**.
