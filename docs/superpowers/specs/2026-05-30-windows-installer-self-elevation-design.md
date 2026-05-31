# Windows installer self-elevation for Program Files installs

## Summary

Add self-elevation to the Windows desktop installer so installs targeting `C:\Program Files\LeiKwan Bridge` automatically request administrator permission and continue installation after the user approves UAC.

This design covers both:

- user-launched installs by double-clicking the installer; and
- app-triggered update installs launched from the desktop app.

The core rule is: **the installer owns elevation**. The app should continue to launch the installer normally; the installer decides whether elevation is required and re-launches itself with `RunAs` when needed.

## Background

The Windows desktop app and packaging flow now support installation under:

- `C:\Program Files\LeiKwan Bridge`

However, installing there requires administrator permissions. Current behavior supports the target path but does not automatically request elevation when the installer is launched as a normal user.

That means a user can still hit an access-denied failure even though the install target is otherwise valid.

This is especially important because the same installer flow is used in two ways:

1. direct user install (double-click the packaged setup); and
2. app-triggered update install (desktop app launches the installer and exits).

The desired behavior is a single consistent elevation path for both flows.

## Goals

1. Automatically request administrator rights when the installer needs to write into `Program Files`.
2. Use the same elevation path for both first-time installs and app-triggered update installs.
3. Prevent recursive self-relaunch loops.
4. Keep the responsibility boundary clear: installation privilege handling belongs in the installer, not in the desktop app.
5. Preserve existing packaging behavior, backup behavior, and install-manifest generation.

## Non-goals

This design does **not** include:

- converting the installer to MSI;
- replacing IExpress packaging with a new installer technology;
- adding Windows manifest-level `requireAdministrator` behavior to the packaged setup EXE;
- redesigning the update download/cache location;
- changing runtime/state ownership outside the existing install flow.

## Current flow

### Packaging

`scripts/build-windows-client-setup.ps1` generates:

- a staged payload under `artifacts/windows/_setup_stage/`
- an `install.ps1` script that performs file copy, backup, manifest write, and shortcut creation
- an `install.cmd` launcher
- a packaged `artifacts/windows/leikwan-bridge-setup.exe`

### Install runtime behavior

The generated `install.ps1`:

- computes the install root (now Program Files-capable);
- kills stale installed processes under the install root;
- copies desktop binaries and helper runtimes;
- writes `install-manifest.json`;
- creates a desktop shortcut.

### Update-launch behavior

The desktop app currently launches the installer from `MainWindow.xaml.cs` and exits.

That behavior should remain. The app should not take on separate privilege-escalation responsibilities.

## Proposed design

### 1. Installer-entry self-elevation

Self-elevation is implemented in the generated `install.ps1` script.

Installer startup sequence becomes:

1. Resolve install root.
2. Determine whether the target location requires admin rights.
3. Determine whether the current process already has admin rights.
4. If admin rights are required but not present, re-launch the script elevated.
5. Exit the non-elevated process.
6. Continue the install in the elevated process.

This keeps privilege handling at the installer entrypoint rather than splitting it across multiple callers.

### 2. Elevation trigger rule

Elevation is required when the effective install root targets `Program Files` or another location that requires administrator rights.

For this design, the primary supported privileged path is:

- `C:\Program Files\LeiKwan Bridge`

If the installer is redirected via `LEIKWAN_INSTALL_DIR` to a non-privileged test path, it should not self-elevate.

This preserves automated testability while still enforcing elevation for the real production target.

### 3. Recursion guard

The elevated re-launch must include a recursion guard.

Recommended mechanism:

- pass an explicit script argument such as `-Elevated`

Behavior:

- if the script is non-admin and not already marked `-Elevated`, re-launch with `RunAs` and `-Elevated`
- if the script is launched with `-Elevated`, it must not attempt to re-launch again

This avoids:

- infinite UAC loops
- double-install attempts
- ambiguous control flow between parent and elevated child process

### 4. Relaunch implementation

When self-elevation is needed, the script should call something equivalent to:

- `Start-Process powershell.exe -Verb RunAs ...`

The relaunched command must preserve:

- the script path
- the effective install root override (if any)
- any arguments needed to continue normal install execution
- the `-Elevated` marker

After spawning the elevated process, the original non-elevated process should exit cleanly.

### 5. App-triggered update behavior

The desktop app should continue to launch the installer the same way it does now.

No separate in-app elevation logic is required.

Why this is correct:

- the app is responsible for downloading and launching the installer
- the installer is responsible for deciding whether elevation is required
- app-triggered updates therefore automatically inherit the same self-elevation path as double-click installs

This keeps the update flow simple and avoids duplicated privilege logic in the app.

### 6. Install-root support changes already in scope

This self-elevation work assumes the Program Files-capable install-root path remains in place:

- install root defaulting to `Program Files\LeiKwan Bridge`
- stale installed process cleanup respecting that root
- packaging test overrides still using `LEIKWAN_INSTALL_DIR`

Those path changes and the new self-elevation behavior should be treated as one coherent installer behavior set.

## File impact

### Primary implementation file

- `scripts/build-windows-client-setup.ps1`

This script must generate an `install.ps1` that:

- resolves the effective install root
- detects whether admin is required
- detects whether the current process is already elevated
- re-launches itself with `RunAs` when needed
- guards against recursion with `-Elevated`

### Likely verification touchpoints

- `scripts/tests/test_windows_client_setup_installer.py`
- `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml.cs`

The app-side file is primarily a verification touchpoint to confirm no separate elevation logic is required.

## Verification strategy

### Automated verification

Automated tests cannot reliably click through a real UAC dialog, so they should verify the script-level contract instead.

Required automated checks:

1. packaging still succeeds
2. extracted installer payload still installs correctly when `LEIKWAN_INSTALL_DIR` points to a writable test directory
3. generated install script contains the self-elevation path and recursion guard
4. non-privileged override installs do not falsely force elevation

### Manual verification

Manual verification must confirm the real UAC behavior:

1. launch the installer from a normal, non-admin shell
2. installer requests elevation
3. approve UAC
4. install completes into `C:\Program Files\LeiKwan Bridge`
5. `install-manifest.json` is written under Program Files install root
6. desktop app update flow launches the same installer path and reaches the same elevation behavior

## Risks

### Recursive relaunch bug

If `-Elevated` is not handled correctly, the installer can repeatedly re-launch itself.

Mitigation:

- make the `-Elevated` check explicit and early
- test script generation and execution flow carefully

### Lost arguments/state during relaunch

If the elevated relaunch does not preserve the install root or other inputs, behavior can differ between elevated and non-elevated runs.

Mitigation:

- explicitly pass the needed arguments/environment through the relaunched command
- keep the argument surface small

### Over-scoping privilege logic into the app

Adding separate app-side elevation logic would increase complexity and drift from the desired responsibility boundary.

Mitigation:

- keep self-elevation in the installer only
- leave app update launch behavior unchanged unless verification proves otherwise

## Open questions resolved in this design

- **Should this cover only first-time installs or also app-triggered updates?**
  - Both. The installer should behave the same in either path.

- **Should the app itself own RunAs?**
  - No. The installer owns privilege handling.

## Implementation constraints

- Keep this as a focused installer behavior change.
- Do not redesign the updater architecture.
- Do not migrate to MSI or another installer framework as part of this work.
- Preserve the current install-manifest/backup behavior.

## Approval state

This design reflects the approved direction:

- Program Files remains the default install target
- the installer should self-elevate
- the same elevation path should cover both manual install and app-triggered update launch
