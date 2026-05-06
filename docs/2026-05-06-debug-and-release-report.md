# 2026-05-06 Debug and Release Report

## Branch
- `master`

## Scope completed
- Investigated and fixed the Windows desktop app staying disconnected after login.
- Investigated and stabilized Linux client bond runtime behavior for forward `11030` and `11035`.
- Fixed slave self-update packaging and runtime issues, including `8.138.186.81`.
- Fixed Windows app update-check and update-install flow.
- Published a newer Windows app release to panel and verified in-app update from the installed version.

## Key permanent fixes

### Windows desktop connectivity
- Added stable device identity headers and better API error handling in `MasterApiClient`.
- Updated desktop connection flow to surface real connection errors instead of generic disconnected state.
- Fixed default client online-device-limit provisioning behavior on the panel side.

### CF Bond runtime selection
- Added quality-aware CF bond endpoint selection in Linux client runtime.
- Added matching quality-aware endpoint selection in Windows app runtime.
- Limited active CF bond healthy endpoint sets to the strongest three legs to reduce weak-leg contamination.

### Slave update path
- Added panel route for `slave-agent-update.sh`.
- Updated slave installer to install `/usr/local/bin/leikwan-slave-update`.
- Switched slave installer away from GitHub `wstunnel` fetch to panel-served artifacts.
- Made slave runtime updates suppress duplicate re-entry while one update is already running.
- Made slave installer use atomic file replacement for runtime binaries.

### Windows app updater
- Added visible feedback when update check is already up to date.
- Added visible feedback when update check fails.
- Made Windows updater robust to both relative and absolute `artifact_url` values.
- Restored panel compatibility for the currently installed old Windows app by serving relative `windows_client_app.artifact_url` in `client-manifest.json`.

## Published versions
- Windows desktop app bumped from `0.1.15.0` to `0.1.16.0`.
- Published setup artifact: `artifacts/windows/leikwan-bridge-setup.exe`
- Published setup SHA256: `E26166D706E2D635E790759E9612D8ED488430A72011782ABACA5979EA11A9C3`

## Live verification highlights
- `11030` verified at about `400 Mbits/sec` receiver with `iperf3 -c 127.0.0.1 -p 11030 -R -b 400m`.
- `11035` verified at about `400 Mbits/sec` receiver with `iperf3 -c 127.0.0.1 -p 11035 -R -b 400m` after runtime selection stabilization.
- `8.138.186.81` successfully updated to slave agent `2026.04.27.1`.
- Installed Windows app updated in-app from `0.1.15.0` to `0.1.16.0` using cached installer and pending-update restart flow.

## Files materially changed
- `LeikwanBridge.Desktop/src/LeikwanBridge.App/LeikwanBridge.App.csproj`
- `LeikwanBridge.Desktop/src/LeikwanBridge.App/ViewModels/MainViewModel.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/BondEndpointHealthState.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/EndpointConfig.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/BondEndpointHealthService.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/MasterApiClient.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/WindowsAppUpdateService.cs`
- `client-agent/client_agent.py`
- `install/install-slave.sh`
- `master/src/Bootstrap.php`
- `master/src/Controllers/ArtifactController.php`
- `master/src/Support/ProvisioningService.php`
- `artifacts/versions.json`
- `artifacts/windows/leikwan-bridge-setup.exe`

## Notes
- The repository currently has no configured git remote, so remote push requires a remote to be added before `git push` can succeed.
- There is a pre-existing untracked artifact workspace at `artifacts/windows/_local_sync_temp/`; it was not required for the fixes and is intentionally left out of the shipping set.
