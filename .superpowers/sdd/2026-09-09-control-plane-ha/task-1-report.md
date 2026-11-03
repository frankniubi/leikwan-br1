# Task 1 report: Desktop control-plane endpoint supervisor

## Status

Implemented the desktop endpoint supervisor and routed `MasterApiClient` transport through it. The primary endpoint remains the caller-provided `masterUrl`; the production Cloudflare/Aliyun pair is selected automatically for the two known panel hosts, while tests and custom installations retain their existing endpoint behavior.

## Files changed

- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ControlPlaneEndpointSupervisor.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/MasterApiClient.cs`
- `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/ControlPlaneEndpointSupervisorTests.cs`

The existing `AgentVersion` worktree change was preserved and is not part of this task commit.

## Behavior

- Transport exceptions/timeouts and HTTP 502/503/504/530 fail over to the next endpoint with bounded backoff.
- 401/403/409 and other received business responses do not trigger endpoint failover.
- POST/lease/heartbeat requests are never re-submitted after an HTTP response has been received; their response is returned to the existing API error handling path.
- A successful request records the endpoint and makes it current. A successful primary request therefore recovers from a mirror.
- Request paths, JSON payloads, headers, and response parsing remain in `MasterApiClient` unchanged; only request URI construction is re-based onto the selected endpoint.

## Tests

- Focused supervisor suite: **10 passed, 0 failed**.
- Existing `MasterApiClientDesktopLoginTests`: **19 passed, 0 failed**.
- Full `LeikwanBridge.Core.Tests`: **346 passed, 0 failed, 0 skipped**.

There are two pre-existing compiler warnings in unrelated tests (`WindowsProxyConfigBuilderTests` nullability and an unused event in `DesktopClientControllerTests`).

## API compatibility checks

- No server/API route, request JSON field, lease schema, or response schema was changed.
- Existing localhost/custom endpoint tests continue to use a single endpoint unless an explicit fallback list is supplied.
- Known production hosts automatically pair as Cloudflare primary / Aliyun fallback or the reverse when the configured primary is Aliyun.

## Concerns

- The supervisor only has endpoint knowledge local to the `MasterApiClient`; UI/runtime layers still decide how to retain an existing forward while control-plane requests are unavailable.
- A POST that receives a transient HTTP status is intentionally not retried, per the no-duplicate-submission requirement. The caller receives the original status and may schedule its normal higher-level retry.
- No live CDN outage simulation was performed by this task; coverage is deterministic unit and Core-suite testing.
