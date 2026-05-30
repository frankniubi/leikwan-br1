# Windows installer self-elevation verification handoff

## Fresh verification completed in this worktree

- `python scripts/tests/test_windows_client_setup_installer.py`
  - Result: `Ran 2 tests ... OK`
- Generated stage script inspection for self-elevation markers
  - Result: `-Verb RunAs`, `-Elevated`, `WindowsPrincipal`, and `IsInRole` are present in `artifacts/windows/_setup_stage/install.ps1`
- App-triggered update launch path inspection
  - Result: `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml.cs` still launches the installer via `Process.Start(...)` and exits immediately with `System.Windows.Application.Current.Shutdown();`
- App-side diff check for this task scope
  - Result: no app-side file changes were required for Installer Task 5 itself, so no additional desktop solution tests were required by Task 5 Step 1

## Manual privileged-path checks not freshly reproducible in this headless CLI step

The following checks remain manual/operator verification items rather than fresh CLI evidence in this Task 5 step:

1. Launch installer from a normal non-admin shell
2. Observe UAC prompt
3. Click Yes
4. Confirm install completes into `C:\Program Files\LeiKwan Bridge`
5. Confirm `install-manifest.json` exists under Program Files install root
6. Trigger app update path and confirm it launches the same installer and exits immediately

## Deferred items

- Deferred: MSI / manifest-level installer privilege model
- Deferred: redesign of update-cache/download location
- Deferred: app-side duplicate RunAs logic
