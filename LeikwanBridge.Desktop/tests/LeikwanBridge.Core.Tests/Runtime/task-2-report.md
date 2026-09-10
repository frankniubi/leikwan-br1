# Task 2: Desktop control-plane recovery

## Scope

- Keep the existing API paths and request payloads unchanged.
- Preserve active forward runtimes when a control-plane pull fails.
- Add a throttled full-policy pull trigger for transient control-plane errors.
- Wire `DesktopRuntimeSession` sync errors to the recovery trigger.

## Implementation

`ConfigPollingService.RequestControlPlaneRecovery()` queues a full pull and
starts one immediate sync at most once per 10 seconds. The existing polling
loop continues to retry after the immediate attempt, preventing a dual-CDN
outage from becoming a tight request loop.

`DesktopRuntimeSession` invokes the recovery trigger from `SyncError`; it does
not stop, recreate, or mutate the existing forward runtime.

## TDD evidence

1. RED: the new regression test failed to compile because
   `RequestControlPlaneRecovery` did not exist (`CS1061`).
2. GREEN: after implementation, the focused test passed.

## Verification

```text
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --no-restore --nologo --filter FullyQualifiedName~RequestControlPlaneRecovery
Passed: 1, Failed: 0, Skipped: 0

dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --no-restore --nologo --filter FullyQualifiedName~DesktopClientControllerTests
Passed: 11, Failed: 0, Skipped: 0

dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --no-restore --nologo --filter FullyQualifiedName~ConfigPollingServiceTests
Passed: 22, Failed: 0, Skipped: 0
```

`git diff --check` is clean for the task files. Existing unrelated dirty files
outside the four task files were not modified, staged, or committed. Existing
same-file Ultra reconciliation changes were preserved intact.
