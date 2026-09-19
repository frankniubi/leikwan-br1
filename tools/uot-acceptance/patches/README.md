# Owned Shadowsocks target: bounded UDP output

This patch is scoped to the **owned test target's Shadowsocks-none service**,
not a general desktop sing-box replacement.

Upstream: `SagerNet/sing-box` tag `v1.13.12`,
commit `1086ab2563320e0da0c23b3a491d8dfa0939dff4`.

The verified call path is Shadowsocks inbound `NewPacket` →
`listener.PacketWriter()` → `packetWriter.WritePacket` → `loopUDPOut`.
Upstream has a 64-packet outbound queue. A full queue releases the packet and
returns `nil`, with only a trace-level drop message. That is outside the
client–sidecar ACK window, so transport retransmission cannot repair it.

The patch:

- Uses 4,096 packets **and a 16 MiB byte admission budget**.
- Waits at most 250 ms; admission exhaustion returns an explicit error and logs
  the failure rather than returning success.
- Tracks blocked admissions and timeouts; cancellation is not called a timeout.
- Serializes writer admission against input shutdown and releases queued byte
  reservations on every writer exit.
- Requests 16 MiB listener socket buffers; verify actual kernel values after
  deployment.
- Bounds UDP output writes to five seconds.
- Adds regression tests for full-queue backpressure, timeout, cancellation,
  shutdown drain and byte-cap enforcement.

Build in a separate checkout; do not modify the operator's installed service
until the binary passes `check` against its existing configuration.

```sh
git checkout 1086ab2563320e0da0c23b3a491d8dfa0939dff4
git apply /path/to/sing-box-v1.13.12-bounded-udp.patch
go test -race -count=5 ./common/listener
CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -trimpath \
  -ldflags '-s -w -X github.com/sagernet/sing-box/constant.Version=1.13.12-enterprise-uot.1' \
  -o sing-box-enterprise ./cmd/sing-box
./sing-box-enterprise check -c /path/to/existing-ss-config.json
```

This slim build has no optional feature tags. Its existing Shadowsocks-none
configuration passed validation; do not substitute it for a full-featured
installation that needs other tags.

Keep the original binary and configuration, stage the new executable beside
the original, use a service-specific `ExecStart` override, and schedule an
independent rollback **before** restarting the target. Do not restart it in the
middle of an acceptance run.

This is bounded best-effort UDP backpressure, **not** an application-level
delivery ACK or durable exactly-once service.
# Installed desktop canary compatibility

`desktop-installed-1.0.44-compat.patch` is **not part of the master-based UoT
feature**. It reproduces the already-installed desktop Core dependencies from
commit `c5da15b1d53c4ad755f7a13f8054cb23a6fb1732` relative to master
`72b086224ac3b5078fd1e536b0ee33056de64745`, for the copied 1.0.44 macOS canary
bundle only. No dirty original-worktree changes are included.

The installed executable references AssemblyVersion `1.0.44.0` and logging
types absent from master. A master-only Core DLL therefore cannot be swapped
into it. Build an isolated copy of the candidate Core with this compatibility
patch and `-p:Version=1.0.44 -p:AssemblyVersion=1.0.44.0
-p:FileVersion=1.0.44.0`; run the full Core tests on that copy before use.
This is a private compatibility canary, not a republished signed release.
For production, rebuild the entire matching desktop package atomically instead.
