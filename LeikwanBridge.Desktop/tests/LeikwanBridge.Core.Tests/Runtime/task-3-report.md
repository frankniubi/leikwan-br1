# Task 3: Desktop startup connection recovery

## Scope

- Windows `MainWindow.TryAutoConnectAsync` and macOS `App.InitializeDesktopAsync`
  now start a background retry after the first connection failure.
- Retries are throttled with bounded backoff and run as one idempotent loop.
- Existing API paths, payloads, and connected forward runtimes are unchanged.
- Application cancellation stops pending retries before shutdown completes.

## TDD evidence

The new `ConnectionRecoverySupervisorTests` were run before implementation and
failed to compile because `ConnectionRecoverySupervisor` did not exist
(`CS0246`). After implementation both tests passed: retry-until-success and
idempotent start plus cancellation.

## Verification

```text
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --no-restore --nologo --filter FullyQualifiedName~ConnectionRecoverySupervisorTests
Passed: 2, Failed: 0, Skipped: 0

dotnet build LeikwanBridge.Desktop/src/LeikwanBridge.App/LeikwanBridge.App.csproj --no-restore --nologo
Build succeeded: 0 warnings, 0 errors

dotnet build LeikwanBridge.Desktop/src/LeikwanBridge.Mac/LeikwanBridge.Mac.csproj --no-restore --nologo
Build succeeded: 0 warnings, 0 errors
```

Only the lifecycle implementation, Core regression tests, and this report are
included in the task commit. Other existing dirty files remain untouched.
