# Windows Installer Self-Elevation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Windows installer automatically request administrator permission when installing to `C:\Program Files\LeiKwan Bridge`, while keeping desktop-app-triggered updates on the same installer path and preserving the existing “launch installer then exit” behavior.

**Architecture:** Self-elevation lives only in the generated installer entry script (`install.ps1`) produced by `scripts/build-windows-client-setup.ps1`. The script resolves the install target, detects whether admin is required, relaunches itself with `RunAs` plus an explicit `-Elevated` recursion guard when needed, and then continues the existing backup/copy/manifest flow. The desktop app still launches the installer the same way it does today; no parallel privilege logic is added in the app.

**Tech Stack:** PowerShell installer script generation, IExpress packaging, Python installer test harness, existing WPF desktop app update-launch path.

---

## File structure / decomposition

### Primary implementation file
- Modify: `scripts/build-windows-client-setup.ps1`
  - Add self-elevation logic to the generated `install.ps1`
  - Add recursion guard (`-Elevated`)
  - Preserve install-root override (`LEIKWAN_INSTALL_DIR`)
  - Preserve backup/manifest/install behavior after elevation

### Primary test file
- Modify: `scripts/tests/test_windows_client_setup_installer.py`
  - Add script-content assertions for self-elevation and recursion guard
  - Keep override install-root path test green

### Verification touchpoint (no behavior redesign expected)
- Inspect-only unless a tiny adjustment is proven necessary: `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml.cs`
  - Confirm app-triggered update still launches installer and exits immediately
  - Do not add app-side `RunAs` logic unless verification proves installer-only ownership is insufficient

### Existing packaging/output artifacts affected by verification
- `artifacts/windows/_setup_stage/install.ps1`
- `artifacts/windows/_setup_stage/install.cmd`
- `artifacts/windows/leikwan-bridge-setup.exe`

---

### Task 1: Add self-elevation to the generated installer entry script

**Files:**
- Modify: `scripts/build-windows-client-setup.ps1`
- Test: `scripts/tests/test_windows_client_setup_installer.py`

- [ ] **Step 1: Write the failing installer self-elevation test expectations**

Extend `scripts/tests/test_windows_client_setup_installer.py` with assertions that the extracted `install.ps1` contains all of the following:

```python
script_text = (extract_dir / "install.ps1").read_text(encoding="utf-8")
self.assertIn("-Verb RunAs", script_text)
self.assertIn("-Elevated", script_text)
self.assertIn("WindowsPrincipal", script_text)
self.assertIn("IsInRole", script_text)
```

Also assert the existing override path still works:

```python
env["LEIKWAN_INSTALL_DIR"] = str(localappdata / "LeiKwan Bridge")
```

- [ ] **Step 2: Run the installer test to verify it fails**

Run:

```bash
python "scripts/tests/test_windows_client_setup_installer.py"
```

Expected: FAIL because the generated `install.ps1` does not yet contain self-elevation logic.

- [ ] **Step 3: Add the admin-detection and self-relaunch logic to generated `install.ps1`**

In `scripts/build-windows-client-setup.ps1`, change the generated `install.ps1` script body so it accepts a recursion guard parameter and performs an early admin check.

The generated script should include code equivalent to:

```powershell
param(
    [switch]$Elevated
)

$ErrorActionPreference = "Stop"

$programFilesDir = if (-not [string]::IsNullOrWhiteSpace($env:ProgramW6432)) {
    $env:ProgramW6432
} elseif (-not [string]::IsNullOrWhiteSpace($env:ProgramFiles)) {
    $env:ProgramFiles
} else {
    [Environment]::GetFolderPath([System.Environment+SpecialFolder]::ProgramFiles)
}

$installDir = if (-not [string]::IsNullOrWhiteSpace($env:LEIKWAN_INSTALL_DIR)) {
    $env:LEIKWAN_INSTALL_DIR
} else {
    Join-Path $programFilesDir "LeiKwan Bridge"
}

function Test-IsAdministrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Test-RequiresElevation {
    param([string]$TargetDir)

    $programFiles = [System.IO.Path]::GetFullPath($programFilesDir)
    $target = [System.IO.Path]::GetFullPath($TargetDir)
    return $target.StartsWith($programFiles, [System.StringComparison]::OrdinalIgnoreCase)
}

if (Test-RequiresElevation -TargetDir $installDir -and -not (Test-IsAdministrator)) {
    if ($Elevated) {
        throw "installer relaunched with -Elevated but still lacks administrator rights"
    }

    $psiArgs = @(
        "-NoProfile",
        "-ExecutionPolicy", "Bypass",
        "-File", $PSCommandPath,
        "-Elevated"
    )

    Start-Process powershell.exe -Verb RunAs -ArgumentList $psiArgs -Wait
    exit $LASTEXITCODE
}
```

Keep the existing install logic after that block.

- [ ] **Step 4: Run the installer test to verify it passes**

Run:

```bash
python "scripts/tests/test_windows_client_setup_installer.py"
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add scripts/build-windows-client-setup.ps1 scripts/tests/test_windows_client_setup_installer.py
git commit -m "feat(installer): add self-elevation for Program Files installs"
```

---

### Task 2: Preserve deterministic relaunch arguments and avoid recursion

**Files:**
- Modify: `scripts/build-windows-client-setup.ps1`
- Test: `scripts/tests/test_windows_client_setup_installer.py`

- [ ] **Step 1: Write a failing test for recursion-guard and override preservation**

Add assertions that the extracted `install.ps1` includes:

```python
self.assertIn('if ($Elevated)', script_text)
self.assertIn('installer relaunched with -Elevated but still lacks administrator rights', script_text)
self.assertIn('$env:LEIKWAN_INSTALL_DIR', script_text)
self.assertIn('$PSCommandPath', script_text)
```

- [ ] **Step 2: Run the installer test to verify it fails**

Run:

```bash
python "scripts/tests/test_windows_client_setup_installer.py"
```

Expected: FAIL until the generated script explicitly carries the recursion guard and relaunch inputs.

- [ ] **Step 3: Preserve relaunch context explicitly**

Ensure the generated script relaunches itself using the same script path and preserves the effective install-root override.

The generated code should include logic equivalent to:

```powershell
$psi = New-Object System.Diagnostics.ProcessStartInfo
$psi.FileName = "powershell.exe"
$psi.Verb = "RunAs"
$psi.UseShellExecute = $true
$psi.ArgumentList.Add("-NoProfile")
$psi.ArgumentList.Add("-ExecutionPolicy")
$psi.ArgumentList.Add("Bypass")
$psi.ArgumentList.Add("-File")
$psi.ArgumentList.Add($PSCommandPath)
$psi.ArgumentList.Add("-Elevated")

if (-not [string]::IsNullOrWhiteSpace($env:LEIKWAN_INSTALL_DIR)) {
    $psi.Environment["LEIKWAN_INSTALL_DIR"] = $env:LEIKWAN_INSTALL_DIR
}
if (-not [string]::IsNullOrWhiteSpace($env:LEIKWAN_DESKTOP_DIR)) {
    $psi.Environment["LEIKWAN_DESKTOP_DIR"] = $env:LEIKWAN_DESKTOP_DIR
}

$process = [System.Diagnostics.Process]::Start($psi)
$process.WaitForExit()
exit $process.ExitCode
```

If PowerShell/ProcessStartInfo environment mutation proves brittle in this script-generation context, preserve `LEIKWAN_INSTALL_DIR` and `LEIKWAN_DESKTOP_DIR` via explicit command arguments or fallback environment set commands — but keep the scope local to installer relaunch only.

- [ ] **Step 4: Run the installer test to verify it passes**

Run:

```bash
python "scripts/tests/test_windows_client_setup_installer.py"
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add scripts/build-windows-client-setup.ps1 scripts/tests/test_windows_client_setup_installer.py
git commit -m "feat(installer): preserve relaunch context for elevated installs"
```

---

### Task 3: Keep app-triggered updates on the same installer path without app-side RunAs logic

**Files:**
- Inspect/modify only if needed: `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml.cs`
- Test: existing desktop tests only if behavior must be adjusted

- [ ] **Step 1: Write the verification note before changing app code**

Create a short local checklist in your working notes (not a new committed file) confirming `MainWindow.xaml.cs` currently does:

```csharp
var process = Process.Start(new ProcessStartInfo
{
    FileName = installerPath,
    UseShellExecute = true,
    WorkingDirectory = Path.GetDirectoryName(installerPath) ?? string.Empty,
});
System.Windows.Application.Current.Shutdown();
```

- [ ] **Step 2: Verify no app-side change is necessary**

Read `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml.cs` and confirm the current installer launch still satisfies the approved requirement:

- app launches installer
- installer self-elevates if needed
- app exits immediately after launch

Expected: no app code change required.

- [ ] **Step 3: If no app change is needed, verify by leaving the file untouched**

Do **not** modify `MainWindow.xaml.cs` unless verification proves the installer-only ownership model is insufficient.

Expected outcome: no app-side diff for this task.

- [ ] **Step 4: If you had to change the app after all, run only the relevant desktop tests**

Run only if an app-side diff was required:

```bash
dotnet test "LeikwanBridge.Desktop/LeikwanBridge.Desktop.sln" --filter "MainViewModelTests|WindowsAppUpdateServiceTests" --nologo -v minimal
```

Expected: PASS.

- [ ] **Step 5: Commit only if an app-side change actually happened**

```bash
git add LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml.cs
git commit -m "fix(updater): keep installer launch on unified elevation path"
```

---

### Task 4: Rebuild setup artifact and verify manual install behavior contract

**Files:**
- Modify/generated: `artifacts/windows/_setup_stage/install.ps1`
- Modify/generated: `artifacts/windows/_setup_stage/install.cmd`
- Modify/generated: `artifacts/windows/leikwan-bridge-setup.exe`
- Modify/generated: `artifacts/windows/_setup_stage/package.sed`

- [ ] **Step 1: Rebuild the setup artifact with the new self-elevation logic**

Run:

```bash
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "scripts/build-windows-client-setup.ps1" \
  -AppPublishDir "artifacts/windows/publish" \
  -BondExe "C:\Users\frankniubi\AppData\Local\LeiKwan Bridge\leikwan-bond.exe" \
  -IngressExe "C:\Users\frankniubi\AppData\Local\LeiKwan Bridge\leikwan-ingress.exe" \
  -UotBondExe "C:\Users\frankniubi\AppData\Local\LeiKwan Bridge\leikwan-uot-bond.exe" \
  -MptcpRelayExe "C:\Users\frankniubi\AppData\Local\LeiKwan Bridge\leikwan-mptcp-relay.exe" \
  -WstunnelExe "C:\Users\frankniubi\AppData\Local\LeiKwan Bridge\wstunnel.exe" \
  -OutputSetup "artifacts/windows/leikwan-bridge-setup.exe"
```

Expected: prints `Created artifacts/windows/leikwan-bridge-setup.exe` and a SHA256.

- [ ] **Step 2: Run the installer packaging test on the rebuilt EXE**

Run:

```bash
python "scripts/tests/test_windows_client_setup_installer.py"
```

Expected: `OK`.

- [ ] **Step 3: Inspect the generated install script inside the stage directory**

Run:

```bash
python - <<'PY'
from pathlib import Path
p = Path('artifacts/windows/_setup_stage/install.ps1')
text = p.read_text(encoding='utf-8')
for needle in ['-Verb RunAs', '-Elevated', 'WindowsPrincipal', 'IsInRole']:
    assert needle in text, needle
print('OK: self-elevation markers present')
PY
```

Expected: `OK: self-elevation markers present`

- [ ] **Step 4: Commit the rebuilt installer artifact and stage script changes**

```bash
git add scripts/build-windows-client-setup.ps1 scripts/tests/test_windows_client_setup_installer.py artifacts/windows/_setup_stage/install.ps1 artifacts/windows/_setup_stage/install.cmd artifacts/windows/_setup_stage/package.sed artifacts/windows/leikwan-bridge-setup.exe
git commit -m "feat(installer): rebuild setup with self-elevation support"
```

---

### Task 5: Verify the end-to-end behavior boundaries and record deferred items

**Files:**
- Reference: `docs/superpowers/specs/2026-05-30-windows-installer-self-elevation-design.md`
- Modify only if needed for execution notes: `docs/superpowers/plans/2026-05-30-windows-installer-self-elevation.md`

- [ ] **Step 1: Run the affected desktop solution tests if app-side code changed**

Only if Task 3 required an app diff:

```bash
dotnet test "LeikwanBridge.Desktop/LeikwanBridge.Desktop.sln" --nologo -v minimal
```

Expected: PASS.

- [ ] **Step 2: Perform manual privileged-path verification**

Manual verification checklist:

```text
1. Launch installer from a normal non-admin shell
2. Observe that UAC is requested automatically
3. Click Yes
4. Confirm install completes into C:\Program Files\LeiKwan Bridge
5. Confirm install-manifest.json exists under Program Files install root
6. Trigger app update path and confirm it launches the same installer and app exits immediately
```

Expected: all six checks succeed.

- [ ] **Step 3: Record deferred items in execution notes**

Keep these out of scope for this feature:

```md
- Deferred: MSI / manifest-level installer privilege model
- Deferred: redesign of update-cache/download location
- Deferred: app-side duplicate RunAs logic
```

- [ ] **Step 4: Commit any final verification-only adjustments if needed**

```bash
git add .
git commit -m "test(installer): verify self-elevation behavior"
```

Only do this if manual verification required a tiny code/test/doc correction.

---

## Self-review checklist

### Spec coverage
- installer-entry self-elevation: Task 1
- recursion guard / relaunch argument preservation: Task 2
- app-triggered update still exits immediately and does not own RunAs: Task 3
- rebuilt setup artifact / packaging verification: Task 4
- verification boundaries and deferred items: Task 5

### Placeholder scan
- no placeholder markers remain
- each code-changing step includes concrete code or exact commands
- each validation step includes an exact command and expected result

### Type / API consistency
- recursion guard name: `-Elevated`
- override variable: `LEIKWAN_INSTALL_DIR`
- optional desktop shortcut override remains `LEIKWAN_DESKTOP_DIR`
- installer launch ownership remains in the installer, not the app

## Notes for the implementing engineer

- Keep the elevation logic in the generated installer script, not in WPF app code.
- Preserve the current “launch installer then exit” app behavior; the user explicitly chose that scope.
- Treat real UAC click-through as manual verification, not an automated test promise.
- Do not widen this into an installer technology migration.
