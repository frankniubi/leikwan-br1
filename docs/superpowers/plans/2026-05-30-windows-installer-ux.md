# Windows Installer UX Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the current IExpress-based Windows installer with an Inno Setup installer that supports `All users` vs `Just for me`, install-directory selection, optional desktop shortcut creation, migration prompting for legacy installs, standard uninstall registration, and silent app-triggered updates that reuse the last chosen installer options.

**Architecture:** Keep the existing single-EXE updater contract, but move installer UX, uninstall registration, elevation handling, and task selection into a checked-in Inno Setup script. Preserve repo-specific install behavior by keeping a staged payload plus deterministic post-install metadata (`install-manifest.json` and new `install-state.json`), and teach the app/update layer to read the persisted install state and launch the installer with an explicit silent-update contract.

**Tech Stack:** PowerShell build script, Inno Setup (`ISCC` compiler + Pascal Script), .NET 8 / C#, WPF desktop app, xUnit tests, Python Windows installer integration tests.

---

## File structure / decomposition

### Packaging and installer source
- Modify: `scripts/build-windows-client-setup.ps1`
  - Replace the IExpress `.sed` generation path with Inno Setup compilation.
  - Keep payload staging under `artifacts/windows/_setup_stage/`.
  - Fail fast when `ISCC.exe` is missing.
- Create: `scripts/windows-client-setup.iss`
  - Own the installer wizard, branding, install-mode selection, directory selection, desktop shortcut task, migration prompt, uninstall registration, elevation rules, silent-update contract parsing, and invocation of repo-specific install actions.
- Create: `scripts/windows-client-install-helper.ps1`
  - Own the repo-specific file-copy/backup/metadata work that is easier to keep in PowerShell than in Inno Pascal Script: stop processes, back up old payload, copy files, write `install-manifest.json`, write `install-state.json`, and remove installer-owned shortcuts/metadata on uninstall.

### App and Core files
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml.cs`
  - Launch app-triggered updates with the new silent-update argument contract.
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.App/AppDefaults.cs`
  - Stop assuming `%ProgramFiles%\LeiKwan Bridge` is the only meaningful install root when no override is present.
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/WindowsAppUpdateService.cs`
  - Add shared installer-state loading, silent-update argument construction, and validation helpers.
- Create: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/WindowsAppInstallState.cs`
  - Typed model for `install-state.json`.

### Tests
- Modify: `scripts/tests/test_windows_client_setup_installer.py`
  - Replace IExpress/CAB-specific assertions with Inno Setup integration checks.
- Modify: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/WindowsAppUpdateServiceTests.cs`
  - Cover install-state parsing, silent-update argument generation, and invalid-target rejection.
- Create: `LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/AppDefaultsTests.cs`
  - Cover install-root discovery precedence after the redesign.

### Existing files to keep unchanged unless execution proves otherwise
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/WindowsAppUpdateManifest.cs`
- `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/AgentConfig.cs`
- `LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/Services/*`

### Sequencing constraints
- Define the `install-state.json` schema and silent-update CLI contract before changing the WPF update launcher.
- Replace the installer integration test harness early, because current coverage is hard-wired to CAB extraction.
- Keep shortcut creation/removal owned by one path only. Once Inno is in charge of the checkbox task, the old unconditional PowerShell shortcut logic must disappear.

---

### Task 1: Add the install-state model and silent-update contract helpers

**Files:**
- Create: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/WindowsAppInstallState.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/WindowsAppUpdateService.cs`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/WindowsAppUpdateServiceTests.cs`

- [ ] **Step 1: Write the failing tests for install-state round-trip and silent-update argument building**

Extend `WindowsAppUpdateServiceTests.cs` with:

```csharp
[Fact]
public void WindowsAppInstallState_DeserializesExpectedFields()
{
    var json = """
{
  "install_mode": "just-for-me",
  "install_dir": "C:\\Users\\demo\\AppData\\Local\\Programs\\LeiKwan Bridge",
  "desktop_shortcut": true,
  "display_name": "LeiKwan Bridge | 利群會聚",
  "installed_version": "0.1.15.0",
  "installed_at": "2026-05-30T12:34:56Z"
}
""";

    var state = System.Text.Json.JsonSerializer.Deserialize<WindowsAppInstallState>(json)!;

    Assert.Equal("just-for-me", state.InstallMode);
    Assert.True(state.DesktopShortcut);
    Assert.Equal("LeiKwan Bridge | 利群會聚", state.DisplayName);
}

[Fact]
public void BuildSilentUpdateArguments_UsesExplicitInstallerContract()
{
    var state = new WindowsAppInstallState
    {
        InstallMode = "all-users",
        InstallDir = @"C:\Program Files\LeiKwan Bridge",
        DesktopShortcut = false,
        DisplayName = "LeiKwan Bridge | 利群會聚",
        InstalledVersion = "0.1.15.0",
        InstalledAt = "2026-05-30T12:34:56Z",
    };

    var args = WindowsAppUpdateService.BuildSilentUpdateArguments(
        installerPath: @"C:\Users\demo\AppData\Local\LeiKwan Bridge\updates\leikwan-bridge-setup-0.1.16.0.exe",
        currentInstallRoot: @"C:\Program Files\LeiKwan Bridge",
        state);

    Assert.Contains("/VERYSILENT", args);
    Assert.Contains("/NORESTART", args);
    Assert.Contains("/LEIKWANUPDATE=1", args);
    Assert.Contains(@"/LEIKWANINSTALLDIR=C:\Program Files\LeiKwan Bridge", args);
    Assert.Contains("/LEIKWANINSTALLMODE=all-users", args);
    Assert.Contains("/LEIKWANDESKTOPSHORTCUT=0", args);
}
```

- [ ] **Step 2: Run the focused Core tests and verify they fail**

Run:

```bash
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter "WindowsAppInstallState_DeserializesExpectedFields|BuildSilentUpdateArguments_UsesExplicitInstallerContract"
```

Expected: FAIL because `WindowsAppInstallState` and `BuildSilentUpdateArguments` do not exist yet.

- [ ] **Step 3: Add the install-state model**

Create `WindowsAppInstallState.cs`:

```csharp
using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed class WindowsAppInstallState
{
    [JsonPropertyName("install_mode")]
    public string InstallMode { get; set; } = "";

    [JsonPropertyName("install_dir")]
    public string InstallDir { get; set; } = "";

    [JsonPropertyName("desktop_shortcut")]
    public bool DesktopShortcut { get; set; }

    [JsonPropertyName("display_name")]
    public string DisplayName { get; set; } = "";

    [JsonPropertyName("installed_version")]
    public string InstalledVersion { get; set; } = "";

    [JsonPropertyName("installed_at")]
    public string InstalledAt { get; set; } = "";
}
```

- [ ] **Step 4: Add the minimal update-service helpers**

Add to `WindowsAppUpdateService.cs`:

```csharp
public static string BuildInstallStatePath(string installRoot)
{
    return Path.Combine(installRoot, "install-state.json");
}

public static WindowsAppInstallState? LoadInstallState(string installRoot)
{
    var path = BuildInstallStatePath(installRoot);
    if (!File.Exists(path))
        return null;

    var json = File.ReadAllText(path);
    return JsonSerializer.Deserialize<WindowsAppInstallState>(json);
}

public static string[] BuildSilentUpdateArguments(string installerPath, string currentInstallRoot, WindowsAppInstallState state)
{
    _ = installerPath;
    return
    [
        "/VERYSILENT",
        "/NORESTART",
        "/LEIKWANUPDATE=1",
        $"/LEIKWANINSTALLDIR={currentInstallRoot}",
        $"/LEIKWANINSTALLMODE={state.InstallMode}",
        $"/LEIKWANDESKTOPSHORTCUT={(state.DesktopShortcut ? 1 : 0)}",
    ];
}
```

- [ ] **Step 5: Re-run the focused Core tests and verify they pass**

Run:

```bash
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter "WindowsAppInstallState_DeserializesExpectedFields|BuildSilentUpdateArguments_UsesExplicitInstallerContract"
```

Expected: PASS.

- [ ] **Step 6: Commit the install-state contract layer**

```bash
git add LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/WindowsAppInstallState.cs \
  LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/WindowsAppUpdateService.cs \
  LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/WindowsAppUpdateServiceTests.cs
git commit -m "feat: add windows installer state contract"
```

---

### Task 2: Make install-root discovery compatible with per-user and machine installs

**Files:**
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.App/AppDefaults.cs`
- Create: `LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/AppDefaultsTests.cs`

- [ ] **Step 1: Write the failing app-side tests for install-root precedence**

Create `AppDefaultsTests.cs`:

```csharp
using System.Text.Json;
using LeikwanBridge.App;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.App.Tests;

public sealed class AppDefaultsTests
{
    [Fact]
    public void GetInstallRoot_ReturnsExplicitEnvironmentOverride_First()
    {
        Environment.SetEnvironmentVariable("LEIKWAN_INSTALL_DIR", @"D:\LeiKwan Bridge Test");
        try
        {
            Assert.Equal(@"D:\LeiKwan Bridge Test", AppDefaults.GetInstallRoot());
        }
        finally
        {
            Environment.SetEnvironmentVariable("LEIKWAN_INSTALL_DIR", null);
        }
    }

    [Fact]
    public void GetInstallRoot_FallsBackToProgramFiles_WhenNoOverrideExists()
    {
        Environment.SetEnvironmentVariable("LEIKWAN_INSTALL_DIR", null);
        var root = AppDefaults.GetInstallRoot();
        Assert.EndsWith("LeiKwan Bridge", root);
    }
}
```

- [ ] **Step 2: Run the app test project and verify the new tests pass before behavior changes**

Run:

```bash
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/LeikwanBridge.App.Tests.csproj --filter "GetInstallRoot_ReturnsExplicitEnvironmentOverride_First|GetInstallRoot_FallsBackToProgramFiles_WhenNoOverrideExists"
```

Expected: PASS. This protects the current contract before broadening it.

- [ ] **Step 3: Add the next failing test for install-state-aware discovery**

Append:

```csharp
[Fact]
public void GetInstallRoot_UsesInstallStateBesideCurrentExecutable_WhenAvailable()
{
    using var tempDir = new TempStateDirectory();
    var installRoot = System.IO.Path.Combine(tempDir.Path, "LeiKwan Bridge");
    Directory.CreateDirectory(installRoot);
    File.WriteAllText(
        System.IO.Path.Combine(installRoot, "install-state.json"),
        JsonSerializer.Serialize(new WindowsAppInstallState
        {
            InstallMode = "just-for-me",
            InstallDir = installRoot,
            DesktopShortcut = true,
            DisplayName = "LeiKwan Bridge | 利群會聚",
            InstalledVersion = "0.1.15.0",
            InstalledAt = "2026-05-30T12:34:56Z",
        }));

    var discovered = AppDefaults.ResolveInstallRoot(
        installStatePathOverride: System.IO.Path.Combine(installRoot, "install-state.json"));

    Assert.Equal(installRoot, discovered);
}
```

- [ ] **Step 4: Run the new app test and verify it fails**

Run:

```bash
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/LeikwanBridge.App.Tests.csproj --filter GetInstallRoot_UsesInstallStateBesideCurrentExecutable_WhenAvailable
```

Expected: FAIL because `ResolveInstallRoot` does not exist yet.

- [ ] **Step 5: Add an install-state-aware resolver without breaking the current getter**

Update `AppDefaults.cs`:

```csharp
public static string GetInstallRoot()
{
    return ResolveInstallRoot();
}

public static string ResolveInstallRoot(string? installStatePathOverride = null)
{
    var overridePath = Environment.GetEnvironmentVariable("LEIKWAN_INSTALL_DIR");
    if (!string.IsNullOrWhiteSpace(overridePath))
        return overridePath.Trim();

    var installStatePath = installStatePathOverride
        ?? Path.Combine(AppContext.BaseDirectory, "install-state.json");
    if (File.Exists(installStatePath))
    {
        var state = WindowsAppUpdateService.LoadInstallState(Path.GetDirectoryName(installStatePath)!);
        if (state is not null && !string.IsNullOrWhiteSpace(state.InstallDir))
            return state.InstallDir.Trim();
    }

    var programFiles = Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles);
    return Path.Combine(programFiles, "LeiKwan Bridge");
}
```

- [ ] **Step 6: Re-run the app tests and verify they pass**

Run:

```bash
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/LeikwanBridge.App.Tests.csproj --filter "GetInstallRoot_ReturnsExplicitEnvironmentOverride_First|GetInstallRoot_FallsBackToProgramFiles_WhenNoOverrideExists|GetInstallRoot_UsesInstallStateBesideCurrentExecutable_WhenAvailable"
```

Expected: PASS.

- [ ] **Step 7: Commit the install-root discovery change**

```bash
git add LeikwanBridge.Desktop/src/LeikwanBridge.App/AppDefaults.cs \
  LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/AppDefaultsTests.cs
git commit -m "feat: make windows install root discovery state-aware"
```

---

### Task 3: Replace the Windows installer integration harness with Inno-oriented checks

**Files:**
- Modify: `scripts/tests/test_windows_client_setup_installer.py`

- [ ] **Step 1: Replace the IExpress extraction test with silent-install contract tests**

Rewrite `test_windows_client_setup_installer.py` so the primary test names become:

```python
def test_setup_installs_payload_in_just_for_me_mode_without_desktop_shortcut(self) -> None:
    self.assertTrue(SETUP_EXE.is_file(), f"missing setup exe: {SETUP_EXE}")

def test_setup_installs_payload_with_desktop_shortcut_when_task_enabled(self) -> None:
    self.assertTrue(SETUP_EXE.is_file(), f"missing setup exe: {SETUP_EXE}")

def test_setup_rejects_silent_update_when_install_state_mismatches_target(self) -> None:
    self.assertTrue(SETUP_EXE.is_file(), f"missing setup exe: {SETUP_EXE}")
```

Each test should launch the built setup EXE directly instead of extracting a CAB.

- [ ] **Step 2: Add the failing scaffolding for the first silent install test**

Use a command shape like:

```python
install = subprocess.run(
    [
        str(SETUP_EXE),
        "/VERYSILENT",
        "/NORESTART",
        "/CURRENTUSER",
        f"/DIR={install_dir}",
        "/TASKS=",
    ],
    capture_output=True,
    text=True,
    env=env,
)
```

and assert:

```python
self.assertEqual(install.returncode, 0, msg=install.stdout + "\n" + install.stderr)
self.assertTrue((install_dir / "leikwan-client-win.exe").is_file())
self.assertTrue((install_dir / "install-manifest.json").is_file())
self.assertTrue((install_dir / "install-state.json").is_file())
self.assertFalse((desktop_dir / "LeiKwan Bridge.lnk").exists())
```

- [ ] **Step 3: Run the Python installer test and verify it fails**

Run:

```bash
python scripts/tests/test_windows_client_setup_installer.py
```

Expected: FAIL because the built setup EXE is still IExpress-based and does not support these Inno arguments yet.

- [ ] **Step 4: Keep the rewritten Inno-oriented test harness in place even while red**

The goal of this task is to replace the obsolete CAB-extraction assumptions early. Do not preserve the old `extract_setup()`-based checks in parallel.

- [ ] **Step 5: Commit the test-harness migration before installer implementation**

```bash
git add scripts/tests/test_windows_client_setup_installer.py
git commit -m "test: rewrite windows installer harness for inno setup"
```

---

### Task 4: Introduce a checked-in Inno Setup script with branding, install modes, and task selection

**Files:**
- Create: `scripts/windows-client-setup.iss`
- Modify: `scripts/build-windows-client-setup.ps1`

- [ ] **Step 1: Write the initial `.iss` script with product metadata and wizard shell**

Create `scripts/windows-client-setup.iss` with a `#define`-driven header and setup metadata:

```iss
#define MyAppName "LeiKwan Bridge | 利群會聚"
#define MyAppExeName "leikwan-client-win.exe"

[Setup]
AppId={{D6E7C18D-9C25-4C42-9E9B-1DF4A3F46B90}
AppName={#MyAppName}
AppVersion={#AppVersion}
DefaultDirName={autopf}\LeiKwan Bridge
DefaultGroupName={#MyAppName}
AllowNoIcons=yes
OutputBaseFilename=leikwan-bridge-setup
SetupIconFile={#SourcePath}\..\LeikwanBridge.Desktop\src\LeikwanBridge.App\Resources\Icons\leikwan-host.ico
Compression=lzma
SolidCompression=yes
PrivilegesRequiredOverridesAllowed=dialog
WizardStyle=modern
```

- [ ] **Step 2: Add install-mode and desktop-shortcut task definitions**

Add the core task scaffolding:

```iss
[Tasks]
Name: "desktopicon"; Description: "Create Desktop Shortcut"; Flags: unchecked

[Icons]
Name: "{autodesktop}\LeiKwan Bridge"; Filename: "{app}\leikwan-client-win.exe"; Tasks: desktopicon
```

Add mode-aware defaults in Pascal Script via `InitializeWizard` / `NextButtonClick` rather than hard-coding only `{autopf}`.

- [ ] **Step 3: Add a custom install-mode page skeleton**

In the Pascal Script section, create radio buttons for `All users` and `Just for me` and wire the default directory change:

```iss
var
  InstallModePage: TWizardPage;
  AllUsersRadio: TRadioButton;
  CurrentUserRadio: TRadioButton;

procedure UpdateDefaultDirFromInstallMode;
begin
  if AllUsersRadio.Checked then
    WizardForm.DirEdit.Text := ExpandConstant('{autopf}\LeiKwan Bridge')
  else
    WizardForm.DirEdit.Text := ExpandConstant('{localappdata}\Programs\LeiKwan Bridge');
end;
```

- [ ] **Step 4: Modify the build script so it stages payloads for Inno instead of `.sed` generation**

In `build-windows-client-setup.ps1`, remove the `.sed` / `iexpress.exe` generation block and replace it with an `ISCC` invocation shape like:

```powershell
$innoScript = "scripts/windows-client-setup.iss"
$iscc = Get-Command ISCC.exe -ErrorAction SilentlyContinue
if (-not $iscc) {
    throw "Inno Setup compiler not found: ISCC.exe"
}

Start-Process -FilePath $iscc.Source -ArgumentList @(
    "/DAppVersion=$([System.Diagnostics.FileVersionInfo]::GetVersionInfo($appExe).FileVersion)",
    "/DStageDir=$([System.IO.Path]::GetFullPath($StageDir))",
    "/DOutputDir=$([System.IO.Path]::GetFullPath((Split-Path -Parent $OutputSetup)))",
    $innoScript
) -Wait -NoNewWindow
```

- [ ] **Step 5: Run the packaging script and verify it fails for helper-script integration, not for missing Inno metadata**

Run:

```bash
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/build-windows-client-setup.ps1
```

Expected: either
- FAIL with a clear `ISCC.exe` missing message, or
- FAIL later because the installer helper integration is not implemented yet.

- [ ] **Step 6: Commit the initial Inno shell and packaging pivot**

```bash
git add scripts/build-windows-client-setup.ps1 scripts/windows-client-setup.iss
git commit -m "feat: scaffold inno setup windows installer"
```

---

### Task 5: Move repo-specific install work into a helper script that writes install-manifest and install-state

**Files:**
- Create: `scripts/windows-client-install-helper.ps1`
- Modify: `scripts/windows-client-setup.iss`
- Modify: `scripts/build-windows-client-setup.ps1`
- Modify: `scripts/tests/test_windows_client_setup_installer.py`

- [ ] **Step 1: Create a focused PowerShell helper for install actions**

Create `scripts/windows-client-install-helper.ps1` with parameters:

```powershell
param(
    [Parameter(Mandatory = $true)] [string]$InstallDir,
    [Parameter(Mandatory = $true)] [string]$StageDir,
    [Parameter(Mandatory = $true)] [string]$InstallMode,
    [Parameter(Mandatory = $true)] [bool]$DesktopShortcut,
    [Parameter(Mandatory = $true)] [string]$DisplayName,
    [Parameter(Mandatory = $true)] [string]$AppVersion
)
```

- [ ] **Step 2: Move backup/copy/manifest logic from the current generated `install.ps1` into the helper**

Port the current behaviors from `build-windows-client-setup.ps1` into callable functions inside the helper:

```powershell
function Stop-InstalledProcesses {
    param([string]$TargetDir)

    Get-CimInstance Win32_Process | Where-Object {
        $_.ExecutablePath -and $_.ExecutablePath.StartsWith($TargetDir, [System.StringComparison]::OrdinalIgnoreCase)
    } | ForEach-Object {
        try {
            Stop-Process -Id $_.ProcessId -Force -ErrorAction Stop
        } catch {
            Write-Warning "failed to stop process $($_.Name) [$($_.ProcessId)]: $($_.Exception.Message)"
        }
    }
}

function Backup-ExistingInstall {
    param([string]$TargetDir)

    $timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
    $backupDir = Join-Path $TargetDir ("backup-" + $timestamp)
    if (Test-Path -LiteralPath $TargetDir) {
        New-Item -ItemType Directory -Path $backupDir -Force | Out-Null
        Get-ChildItem -LiteralPath $TargetDir -Force | Where-Object { $_.Name -notlike 'backup-*' } | ForEach-Object {
            Copy-Item -LiteralPath $_.FullName -Destination (Join-Path $backupDir $_.Name) -Recurse -Force
        }
        return $backupDir
    }
    return ""
}

function Copy-StagedPayload {
    param([string]$PayloadRoot, [string]$TargetDir)

    New-Item -ItemType Directory -Path $TargetDir -Force | Out-Null
    Copy-Item -LiteralPath (Join-Path $PayloadRoot '*') -Destination $TargetDir -Recurse -Force
}

function Write-InstallManifest {
    param([string]$TargetDir, [string]$BackupDir)

    $manifestPath = Join-Path $TargetDir 'install-manifest.json'
    $manifest = [ordered]@{
        installed_at = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')
        source = 'leikwan-bridge-setup.exe'
        app_sha256 = (Get-FileHash (Join-Path $TargetDir 'leikwan-client-win.exe') -Algorithm SHA256).Hash
        backup_dir = $BackupDir
    }
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($manifestPath, ($manifest | ConvertTo-Json -Depth 4), $utf8NoBom)
}

function Write-InstallState {
    param([string]$TargetDir, [string]$InstallMode, [bool]$DesktopShortcut, [string]$DisplayName, [string]$AppVersion)

    $statePath = Join-Path $TargetDir 'install-state.json'
    $state = [ordered]@{
        install_mode = $InstallMode
        install_dir = $TargetDir
        desktop_shortcut = $DesktopShortcut
        display_name = $DisplayName
        installed_version = $AppVersion
        installed_at = (Get-Date).ToString('yyyy-MM-ddTHH:mm:ssK')
    }
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($statePath, ($state | ConvertTo-Json -Depth 4), $utf8NoBom)
}
```

For `install-state.json`, write at least:

```json
{
  "install_mode": "just-for-me",
  "install_dir": "C:\\Users\\demo\\AppData\\Local\\Programs\\LeiKwan Bridge",
  "desktop_shortcut": true,
  "display_name": "LeiKwan Bridge | 利群會聚",
  "installed_version": "0.1.15.0",
  "installed_at": "2026-05-30T12:34:56Z"
}
```

- [ ] **Step 3: Hook the helper into the Inno install flow**

In `windows-client-setup.iss`, call the helper from Pascal Script after files are extracted, passing selected mode and task values. Use this call shape:

```iss
ResultCode := Exec(
  ExpandConstant('{cmd}'),
  '/c powershell.exe -NoProfile -ExecutionPolicy Bypass -File "' + ExpandConstant('{tmp}\windows-client-install-helper.ps1') + '" ' +
  '-InstallDir "' + ExpandConstant('{app}') + '" ' +
  '-StageDir "' + ExpandConstant('{tmp}') + '" ' +
  '-InstallMode "' + GetSelectedInstallMode() + '" ' +
  '-DesktopShortcut $' + BoolToStr(WizardIsTaskSelected('desktopicon'), True) + ' ' +
  '-DisplayName "LeiKwan Bridge | 利群會聚" ' +
  '-AppVersion "' + ExpandConstant('{#AppVersion}') + '"',
  '', SW_HIDE, ewWaitUntilTerminated, ResultCode);
```

- [ ] **Step 4: Stage the helper script into the built installer payload**

Update `build-windows-client-setup.ps1` so `_setup_stage` contains:

```powershell
Copy-Item -LiteralPath "scripts/windows-client-install-helper.ps1" -Destination (Join-Path $StageDir "windows-client-install-helper.ps1") -Force
```

and ensure the `.iss` `[Files]` section includes it.

- [ ] **Step 5: Run the Python installer test and verify the just-for-me scenario passes**

Run:

```bash
python scripts/tests/test_windows_client_setup_installer.py
```

Expected: `test_setup_installs_payload_in_just_for_me_mode_without_desktop_shortcut` passes, and any remaining failures are confined to migration or silent-update validation cases.

- [ ] **Step 6: Commit the helper-backed install implementation**

```bash
git add scripts/windows-client-install-helper.ps1 \
  scripts/windows-client-setup.iss \
  scripts/build-windows-client-setup.ps1 \
  scripts/tests/test_windows_client_setup_installer.py
git commit -m "feat: implement windows installer payload copy and metadata"
```

---

### Task 6: Add migration detection and deterministic legacy-install handling

**Files:**
- Modify: `scripts/windows-client-setup.iss`
- Modify: `scripts/windows-client-install-helper.ps1`
- Modify: `scripts/tests/test_windows_client_setup_installer.py`

- [ ] **Step 1: Add the failing Python test for legacy LocalAppData detection**

Add a test case that seeds a fake legacy install under `%LOCALAPPDATA%\LeiKwan Bridge` while leaving `%LOCALAPPDATA%\LeiKwan Bridge\updates` as cache-only data, then asserts the installer distinguishes them.

Use setup like:

```python
legacy_install = localappdata / "LeiKwan Bridge"
updates_only = legacy_install / "updates"
updates_only.mkdir(parents=True)
(legacy_install / "leikwan-client-win.exe").write_bytes(b"legacy-app")
(legacy_install / "install-manifest.json").write_text("{}", encoding="utf-8")
```

Expected post-install assertion:

```python
self.assertTrue((selected_install_dir / "leikwan-client-win.exe").is_file())
self.assertTrue((selected_install_dir / "install-state.json").is_file())
```

and for the decline-migration path, the installer updates the legacy install in place instead of creating a second copy.

- [ ] **Step 2: Run the Python test and verify it fails**

Run:

```bash
python scripts/tests/test_windows_client_setup_installer.py
```

Expected: FAIL because legacy detection and decline-migration semantics are not wired yet.

- [ ] **Step 3: Add deterministic legacy-install detection in the Inno script**

In `windows-client-setup.iss`, define candidate roots:

```iss
function LegacyPerUserInstallDir: String;
begin
  Result := ExpandConstant('{localappdata}\LeiKwan Bridge');
end;

function NewPerUserInstallDir: String;
begin
  Result := ExpandConstant('{localappdata}\Programs\LeiKwan Bridge');
end;

function MachineInstallDir: String;
begin
  Result := ExpandConstant('{autopf}\LeiKwan Bridge');
end;
```

Only treat a directory as an existing install if it contains `leikwan-client-win.exe` or `install-state.json`, not just an `updates` subdirectory.

- [ ] **Step 4: Add the migration-page decision behavior**

When the user declines migration, set the effective target back to the discovered install root rather than proceeding with a side-by-side second install.

Implement the migration-decline behavior with this exact effect:

```iss
if ExistingInstallDetected and (not MigrateRadio.Checked) then
  WizardForm.DirEdit.Text := ExistingInstallDir;
```

- [ ] **Step 5: Re-run the Python installer test and verify the migration cases pass**

Run:

```bash
python scripts/tests/test_windows_client_setup_installer.py
```

Expected: PASS for legacy detection and in-place fallback cases.

- [ ] **Step 6: Commit the migration logic**

```bash
git add scripts/windows-client-setup.iss scripts/windows-client-install-helper.ps1 scripts/tests/test_windows_client_setup_installer.py
git commit -m "feat: add windows installer migration flow"
```

---

### Task 7: Add app-triggered silent-update launching with target validation

**Files:**
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/WindowsAppUpdateService.cs`
- Modify: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/WindowsAppUpdateServiceTests.cs`

- [ ] **Step 1: Write the failing Core tests for invalid or ambiguous install-state handling**

Add to `WindowsAppUpdateServiceTests.cs`:

```csharp
[Fact]
public void BuildSilentUpdateArguments_ThrowsWhenInstallRootDoesNotMatchState()
{
    var state = new WindowsAppInstallState
    {
        InstallMode = "just-for-me",
        InstallDir = @"C:\Users\demo\AppData\Local\Programs\LeiKwan Bridge",
        DesktopShortcut = true,
        DisplayName = "LeiKwan Bridge | 利群會聚",
        InstalledVersion = "0.1.15.0",
        InstalledAt = "2026-05-30T12:34:56Z",
    };

    var ex = Assert.Throws<InvalidOperationException>(() =>
        WindowsAppUpdateService.BuildSilentUpdateArguments(
            installerPath: @"C:\Users\demo\AppData\Local\LeiKwan Bridge\updates\leikwan-bridge-setup-0.1.16.0.exe",
            currentInstallRoot: @"C:\Program Files\LeiKwan Bridge",
            state));

    Assert.Contains("install-state", ex.Message, StringComparison.OrdinalIgnoreCase);
}
```

- [ ] **Step 2: Run the focused Core tests and verify they fail**

Run:

```bash
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter BuildSilentUpdateArguments_ThrowsWhenInstallRootDoesNotMatchState
```

Expected: FAIL because no validation exists yet.

- [ ] **Step 3: Add target validation in the Core helper**

Update `BuildSilentUpdateArguments`:

```csharp
if (!string.Equals(
        Path.GetFullPath(currentInstallRoot).TrimEnd(Path.DirectorySeparatorChar),
        Path.GetFullPath(state.InstallDir).TrimEnd(Path.DirectorySeparatorChar),
        StringComparison.OrdinalIgnoreCase))
{
    throw new InvalidOperationException("install-state target mismatch for silent update");
}
```

- [ ] **Step 4: Change the WPF launcher to use the explicit argument contract**

Update `LaunchInstallerAndExit` in `MainWindow.xaml.cs` to accept installer arguments:

```csharp
private void LaunchInstallerAndExit(string installerPath, IReadOnlyList<string>? installerArguments = null)
{
    _vm.DisconnectCommand.Execute(null);
    _trayLifecycle.RequestExit();
    DisposeTrayIcon();
    var process = Process.Start(new ProcessStartInfo
    {
        FileName = installerPath,
        UseShellExecute = true,
        WorkingDirectory = Path.GetDirectoryName(installerPath) ?? string.Empty,
        Arguments = installerArguments is null ? string.Empty : string.Join(" ", installerArguments),
    });
    if (process == null)
        throw new InvalidOperationException("failed to launch installer");
    System.Windows.Application.Current.Shutdown();
}
```

and in both `OnInstallUpdateNow_Click` and `TryApplyPendingUpdateAsync`, build the args from the current install state before launching.

- [ ] **Step 5: Re-run the focused Core tests and smoke the app test project**

Run:

```bash
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter BuildSilentUpdateArguments_ThrowsWhenInstallRootDoesNotMatchState
```

Expected: PASS.

Then run:

```bash
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/LeikwanBridge.App.Tests.csproj
```

Expected: PASS.

- [ ] **Step 6: Commit the silent-update launch changes**

```bash
git add LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml.cs \
  LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/WindowsAppUpdateService.cs \
  LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/WindowsAppUpdateServiceTests.cs
git commit -m "feat: launch silent windows updates with install-state contract"
```

---

### Task 8: Finish uninstall registration, desktop-shortcut cleanup, and full installer verification

**Files:**
- Modify: `scripts/windows-client-setup.iss`
- Modify: `scripts/windows-client-install-helper.ps1`
- Modify: `scripts/tests/test_windows_client_setup_installer.py`

- [ ] **Step 1: Add or tighten uninstall cleanup behavior**

Ensure uninstall removes installer-owned artifacts only. In the helper script, add an uninstall mode or dedicated cleanup function that removes:

```powershell
Remove-Item -LiteralPath (Join-Path $InstallDir "install-state.json") -Force -ErrorAction SilentlyContinue
Remove-Item -LiteralPath (Join-Path $InstallDir "install-manifest.json") -Force -ErrorAction SilentlyContinue
Remove-Item -LiteralPath (Join-Path $DesktopDir "LeiKwan Bridge.lnk") -Force -ErrorAction SilentlyContinue
```

while intentionally preserving `%ProgramData%\Leikwan\ClientAgent` and `%LOCALAPPDATA%\LeiKwan Bridge\updates`.

- [ ] **Step 2: Add the failing Python test for uninstall cleanup boundaries**

Add a test that installs into a temp directory, seeds a fake `%ProgramData%\Leikwan\ClientAgent` equivalent, triggers uninstall, and asserts:

```python
self.assertFalse((install_dir / "leikwan-client-win.exe").exists())
self.assertFalse((install_dir / "install-state.json").exists())
self.assertTrue(programdata_dir.exists())
```

- [ ] **Step 3: Run the installer integration tests and verify they fail or expose the remaining uninstall gap**

Run:

```bash
python scripts/tests/test_windows_client_setup_installer.py
```

Expected: FAIL until uninstall cleanup is wired end-to-end.

- [ ] **Step 4: Wire uninstall execution through Inno**

In `windows-client-setup.iss`, make sure uninstall calls the helper cleanup path or equivalent Inno file-removal directives, and that uninstall registration uses the branded display name `LeiKwan Bridge | 利群會聚`.

- [ ] **Step 5: Re-run the full installer integration suite and verify it passes**

Run:

```bash
python scripts/tests/test_windows_client_setup_installer.py
```

Expected: PASS.

- [ ] **Step 6: Commit the uninstall and end-to-end installer verification changes**

```bash
git add scripts/windows-client-setup.iss \
  scripts/windows-client-install-helper.ps1 \
  scripts/tests/test_windows_client_setup_installer.py
git commit -m "feat: add windows installer uninstall cleanup"
```

---

### Task 9: Run focused and full validation, then capture manual verification steps

**Files:**
- Reference: `docs/superpowers/specs/2026-05-30-windows-installer-ux-design.md`
- Modify: `docs/superpowers/plans/2026-05-30-windows-installer-ux.md` (execution checkboxes only)

- [ ] **Step 1: Run focused Core tests for the installer-state contract**

Run:

```bash
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/LeikwanBridge.Core.Tests.csproj --filter WindowsAppUpdateServiceTests
```

Expected: PASS.

- [ ] **Step 2: Run the App test project**

Run:

```bash
dotnet test LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/LeikwanBridge.App.Tests.csproj
```

Expected: PASS.

- [ ] **Step 3: Run the Windows installer integration suite**

Run:

```bash
python scripts/tests/test_windows_client_setup_installer.py
```

Expected: PASS.

- [ ] **Step 4: Run the packaging build one final time**

Run:

```bash
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/build-windows-client-setup.ps1
```

Expected: PASS and `artifacts/windows/leikwan-bridge-setup.exe` exists.

- [ ] **Step 5: Write the manual verification checklist into the execution handoff**

Use these exact bullets in the handoff notes after implementation:

```md
- Double-click `artifacts/windows/leikwan-bridge-setup.exe` and confirm the welcome page shows `LeiKwan Bridge | 利群會聚`
- Confirm the wizard lets the user choose `All users` vs `Just for me`
- Confirm the wizard lets the user change the install directory
- Confirm the wizard exposes `Create Desktop Shortcut`
- Confirm a legacy `%LOCALAPPDATA%\LeiKwan Bridge` install triggers the migration recommendation page
- Confirm declining migration upgrades the existing install in place instead of creating a second install
- Confirm the app appears in Windows installed-apps surfaces and can be uninstalled
- Confirm app-triggered updates launch the installer silently and reuse the prior install mode, install directory, and desktop shortcut choice
- Confirm machine-scoped installs and updates still prompt for UAC when launched from a non-elevated session
```

- [ ] **Step 6: Commit the validated installer UX implementation batch**

```bash
git add scripts/build-windows-client-setup.ps1 \
  scripts/windows-client-setup.iss \
  scripts/windows-client-install-helper.ps1 \
  scripts/tests/test_windows_client_setup_installer.py \
  LeikwanBridge.Desktop/src/LeikwanBridge.App/AppDefaults.cs \
  LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml.cs \
  LeikwanBridge.Desktop/src/LeikwanBridge.Core/Models/WindowsAppInstallState.cs \
  LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/WindowsAppUpdateService.cs \
  LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/AppDefaultsTests.cs \
  LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/WindowsAppUpdateServiceTests.cs \
  docs/superpowers/specs/2026-05-30-windows-installer-ux-design.md \
  docs/superpowers/plans/2026-05-30-windows-installer-ux.md
git commit -m "feat: add inno-based windows installer ux"
```

---

## Self-review checklist

### Spec coverage
- Inno Setup migration from IExpress: Tasks 3-5
- Branding and welcome title `LeiKwan Bridge | 利群會聚`: Tasks 4 and 8
- `All users` vs `Just for me`: Tasks 4 and 5
- Install-directory selection: Task 4
- Desktop shortcut task: Tasks 4, 5, and 8
- Legacy install migration recommendation and decline behavior: Task 6
- Standard uninstall entry and cleanup boundaries: Task 8
- `install-state.json` persistence: Tasks 1 and 5
- Silent app-triggered update contract with explicit arguments: Tasks 1 and 7
- App-side updater launch changes: Task 7
- Automated/manual verification updates: Tasks 3, 8, and 9

### Placeholder scan
- No `TBD`, `TODO`, or “similar to previous task” placeholders remain
- Every task names exact files, concrete commands, expected results, and code skeletons
- Silent-update argument names are fixed to the approved spec values

### Type consistency
- `WindowsAppInstallState` fields are consistent across the plan:
  - `InstallMode`
  - `InstallDir`
  - `DesktopShortcut`
  - `DisplayName`
  - `InstalledVersion`
  - `InstalledAt`
- Silent-update contract values are consistent across tasks:
  - `/LEIKWANUPDATE=1`
  - `/LEIKWANINSTALLDIR=<absolute install root>`
  - `/LEIKWANINSTALLMODE=<all-users|just-for-me>`
  - `/LEIKWANDESKTOPSHORTCUT=<1|0>`

## Notes for the implementing engineer

- Keep the updater artifact contract as one EXE. Do not turn this into MSI-only distribution.
- Keep installer-owned shortcut creation/removal in one path only.
- Treat `install-state.json` as the installer-state contract. Do not continue relying on transient environment variables for update targeting.
- Distinguish a real legacy install from a directory that only contains cached update EXEs.
- Preserve `%ProgramData%\Leikwan\ClientAgent` on uninstall.
