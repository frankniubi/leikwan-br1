# Windows Bond Binary Refresh And Branding Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the Windows `leikwan-bond.exe` from the current Rust bond source, add a white-background LeiKwan icon to the desktop and runtime executables, refresh the local install, and generate a new Windows setup executable.

**Architecture:** Keep the current Windows packaging flow and runtime topology. Add one app-side branding helper that chooses the packaged icon path or falls back to the app executable icon, embed the same `.ico` into the WPF app and Rust runtime executables, then rebuild and reinstall from the updated artifacts.

**Tech Stack:** .NET 8 WPF, xUnit, Python 3 with Pillow for icon asset generation, Rust Cargo workspace, `windows-resource` build metadata, PowerShell packaging via IExpress

---

### Task 1: Add Failing Tests For App-Side Branding Asset Resolution

**Files:**
- Create: `LeikwanBridge.Desktop/src/LeikwanBridge.App/Services/BrandingAssets.cs`
- Create: `LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/Services/BrandingAssetsTests.cs`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/Services/BrandingAssetsTests.cs`

- [ ] **Step 1: Write the failing branding asset tests**

```csharp
using System.IO;
using LeikwanBridge.App.Services;
using Xunit;

namespace LeikwanBridge.App.Tests.Services;

public sealed class BrandingAssetsTests
{
    [Fact]
    public void ResolveTrayIconSource_PrefersPackagedIconWhenPresent()
    {
        var root = Path.Combine(Path.GetTempPath(), Path.GetRandomFileName());
        Directory.CreateDirectory(Path.Combine(root, "Resources", "Icons"));
        var iconPath = Path.Combine(root, "Resources", "Icons", "leikwan-host.ico");
        File.WriteAllBytes(iconPath, new byte[] { 0, 0, 1, 0 });

        var source = BrandingAssets.ResolveTrayIconSource(root, Path.Combine(root, "LeikwanBridge.App.exe"));

        Assert.Equal(iconPath, source);
    }

    [Fact]
    public void ResolveTrayIconSource_FallsBackToExecutableWhenPackagedIconMissing()
    {
        var root = Path.Combine(Path.GetTempPath(), Path.GetRandomFileName());
        Directory.CreateDirectory(root);
        var exePath = Path.Combine(root, "LeikwanBridge.App.exe");
        File.WriteAllBytes(exePath, new byte[] { 77, 90 });

        var source = BrandingAssets.ResolveTrayIconSource(root, exePath);

        Assert.Equal(exePath, source);
    }
}
```

- [ ] **Step 2: Run the app test project to verify red**

Run: `dotnet test "LeikwanBridge.Desktop\tests\LeikwanBridge.App.Tests\LeikwanBridge.App.Tests.csproj" --filter BrandingAssetsTests`
Expected: FAIL because `BrandingAssets` does not exist yet.

- [ ] **Step 3: Implement the minimal helper to pass the tests**

```csharp
using System;
using System.IO;

namespace LeikwanBridge.App.Services;

public static class BrandingAssets
{
    public static string ResolveTrayIconSource(string appBaseDirectory, string executablePath)
    {
        var packagedIcon = Path.Combine(appBaseDirectory, "Resources", "Icons", "leikwan-host.ico");
        return File.Exists(packagedIcon) ? packagedIcon : executablePath;
    }
}
```

- [ ] **Step 4: Re-run the targeted app tests and verify green**

Run: `dotnet test "LeikwanBridge.Desktop\tests\LeikwanBridge.App.Tests\LeikwanBridge.App.Tests.csproj" --filter BrandingAssetsTests`
Expected: PASS

### Task 2: Generate White-Background Icon Assets And Wire Them Into The WPF App

**Files:**
- Create: `scripts/generate-windows-logo-assets.py`
- Create: `LeikwanBridge.Desktop/src/LeikwanBridge.App/Resources/Icons/leikwan-host-app.png`
- Create: `LeikwanBridge.Desktop/src/LeikwanBridge.App/Resources/Icons/leikwan-host.ico`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.App/LeikwanBridge.App.csproj`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml.cs`
- Modify: `LeikwanBridge.Desktop/LeikwanBridge.Desktop.sln`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/Services/BrandingAssetsTests.cs`

- [ ] **Step 1: Extend the tests so the tray path is safe to consume from the window layer**

```csharp
[Fact]
public void ResolveTrayIconSource_ReturnsAbsolutePath()
{
    var root = Path.Combine(Path.GetTempPath(), Path.GetRandomFileName());
    Directory.CreateDirectory(Path.Combine(root, "Resources", "Icons"));
    var iconPath = Path.Combine(root, "Resources", "Icons", "leikwan-host.ico");
    File.WriteAllBytes(iconPath, new byte[] { 0, 0, 1, 0 });

    var source = BrandingAssets.ResolveTrayIconSource(root, Path.Combine(root, "LeikwanBridge.App.exe"));

    Assert.True(Path.IsPathFullyQualified(source));
}
```

- [ ] **Step 2: Run the focused app tests to verify red**

Run: `dotnet test "LeikwanBridge.Desktop\tests\LeikwanBridge.App.Tests\LeikwanBridge.App.Tests.csproj" --filter BrandingAssetsTests`
Expected: FAIL because the helper does not yet normalize the returned path and no app icon is wired.

- [ ] **Step 3: Implement the icon asset generator and app icon wiring**

Generator script shape:

```python
from pathlib import Path
from PIL import Image, ImageDraw, ImageFont

root = Path(__file__).resolve().parents[1]
out_dir = root / "LeikwanBridge.Desktop" / "src" / "LeikwanBridge.App" / "Resources" / "Icons"
out_dir.mkdir(parents=True, exist_ok=True)

size = 512
img = Image.new("RGB", (size, size), "white")
draw = ImageDraw.Draw(img)
draw.rounded_rectangle((32, 32, 480, 480), radius=96, outline="#1f5fae", width=18, fill="white")
draw.text((110, 155), "LH", fill="#1f5fae")
draw.text((258, 155), "H", fill="#68b341")
png_path = out_dir / "leikwan-host-app.png"
ico_path = out_dir / "leikwan-host.ico"
img.save(png_path)
img.save(ico_path, sizes=[(16,16), (24,24), (32,32), (48,48), (64,64), (128,128), (256,256)])
```

Project file shape:

```xml
<PropertyGroup>
  <ApplicationIcon>Resources\Icons\leikwan-host.ico</ApplicationIcon>
</PropertyGroup>
<ItemGroup>
  <Resource Include="Resources\Icons\leikwan-host.ico" />
  <Resource Include="Resources\Icons\leikwan-host-app.png" />
</ItemGroup>
```

Tray usage shape:

```csharp
var exePath = System.Diagnostics.Process.GetCurrentProcess().MainModule?.FileName ?? string.Empty;
var iconSource = BrandingAssets.ResolveTrayIconSource(AppContext.BaseDirectory, exePath);
var trayIcon = Drawing.Icon.ExtractAssociatedIcon(iconSource) ?? Drawing.SystemIcons.Application;
```

Window XAML shape:

```xml
Icon="Resources/Icons/leikwan-host.ico"
```

- [ ] **Step 4: Generate the icon assets and re-run the focused app tests**

Run:
- `python .\scripts\generate-windows-logo-assets.py`
- `dotnet test "LeikwanBridge.Desktop\tests\LeikwanBridge.App.Tests\LeikwanBridge.App.Tests.csproj" --filter BrandingAssetsTests`
Expected: PASS

### Task 3: Embed The Same Icon And Updated Version Metadata Into The Rust Windows Runtimes

**Files:**
- Modify: `wstunnel-main/leikwan-bond/Cargo.toml`
- Create: `wstunnel-main/leikwan-bond/build.rs`
- Modify: `wstunnel-main/leikwan-ingress/Cargo.toml`
- Create: `wstunnel-main/leikwan-ingress/build.rs`
- Test: `wstunnel-main/leikwan-bond/src/main.rs`

- [ ] **Step 1: Add a failing CLI version expectation for the refreshed bond binary**

Add to `wstunnel-main/leikwan-bond/src/main.rs` test module:

```rust
#[test]
fn cli_reports_refreshed_version() {
    let mut cmd = Cli::command();
    assert_eq!(cmd.get_version(), Some("0.1.13"));
}
```

- [ ] **Step 2: Run the bond test command to verify red**

Run: `cargo test -p leikwan-bond cli_reports_refreshed_version -- --nocapture`
Expected: FAIL because the crate still reports `0.1.0`.

- [ ] **Step 3: Update Cargo metadata and add Windows resource embedding**

Cargo manifest shape:

```toml
[package]
name = "leikwan-bond"
version = "0.1.13"
edition = "2021"

[build-dependencies]
windows-resource = "0.2"
```

`build.rs` shape for both Rust crates:

```rust
fn main() {
    #[cfg(target_os = "windows")]
    {
        let mut res = windows_resource::WindowsResource::new();
        res.set_icon("../../LeikwanBridge.Desktop/src/LeikwanBridge.App/Resources/Icons/leikwan-host.ico");
        let _ = res.set("FileDescription", "LeiKwan runtime executable");
        res.compile().expect("failed to compile Windows resources");
    }
}
```

Apply the same resource embedding to `leikwan-ingress`, and if you keep its semantic version unchanged, leave the CLI version alone there.

- [ ] **Step 4: Re-run bond tests and rebuild both Rust Windows runtimes**

Run:
- `cargo test -p leikwan-bond -- --nocapture`
- `cargo build --release -p leikwan-bond`
- `cargo build --release -p leikwan-ingress`
Expected:
- bond tests PASS
- both release builds exit `0`

### Task 4: Refresh Windows Artifacts, Update The Local Install, And Verify End To End

**Files:**
- Modify: `artifacts/versions.json`
- Modify: `scripts/build-windows-client-setup.ps1`
- Modify: `artifacts/wstunnel/leikwan-bond_windows_amd64.exe`
- Modify: `artifacts/wstunnel/leikwan-ingress_windows_amd64.exe`
- Modify: `artifacts/windows/publish/**`
- Modify: `artifacts/windows/leikwan-bridge-setup.exe`

- [ ] **Step 1: Update version metadata before packaging**

Set the Windows artifact metadata to match the rebuilt release, for example:

```json
"leikwan_bond_windows_amd64": {
  "version": "0.1.13",
  "path": "artifacts/wstunnel/leikwan-bond_windows_amd64.exe",
  "artifact_url": "/artifacts/wstunnel/leikwan-bond_windows_amd64.exe"
},
"windows_client_app": {
  "version": "0.1.6",
  "path": "artifacts/windows/leikwan-bridge-setup.exe",
  "artifact_url": "/artifacts/leikwan-bridge-setup.exe"
}
```

- [ ] **Step 2: Publish the WPF app and rebuild the setup executable**

Run:

```powershell
dotnet publish "LeikwanBridge.Desktop\src\LeikwanBridge.App\LeikwanBridge.App.csproj" -c Release -r win-x64 -p:PublishSingleFile=true -p:SelfContained=true -p:IncludeNativeLibrariesForSelfExtract=true -o "artifacts\windows\publish"
Copy-Item ".\wstunnel-main\target\release\leikwan-bond.exe" ".\artifacts\wstunnel\leikwan-bond_windows_amd64.exe" -Force
Copy-Item ".\wstunnel-main\target\release\leikwan-ingress.exe" ".\artifacts\wstunnel\leikwan-ingress_windows_amd64.exe" -Force
powershell -ExecutionPolicy Bypass -File ".\scripts\build-windows-client-setup.ps1"
```

Expected: publish completes and `artifacts\windows\leikwan-bridge-setup.exe` exists.

- [ ] **Step 3: Install the rebuilt local version**

Run:

```powershell
powershell -ExecutionPolicy Bypass -File ".\artifacts\windows\_setup_stage\install.ps1"
```

Expected: local install under `%LOCALAPPDATA%\LeiKwan Bridge` is refreshed and a new backup directory is created if prior files existed.

- [ ] **Step 4: Verify hashes, versions, and build outputs**

Run:

```powershell
& "$env:LOCALAPPDATA\LeiKwan Bridge\leikwan-bond.exe" --version
Get-FileHash "$env:LOCALAPPDATA\LeiKwan Bridge\leikwan-bond.exe" -Algorithm SHA256
Get-FileHash ".\artifacts\wstunnel\leikwan-bond_windows_amd64.exe" -Algorithm SHA256
Get-FileHash ".\artifacts\windows\leikwan-bridge-setup.exe" -Algorithm SHA256
```

Expected:
- installed bond binary reports the refreshed version
- installed bond SHA matches the packaged Windows bond artifact
- setup hash prints successfully

- [ ] **Step 5: Run final desktop verification commands**

Run:

```powershell
dotnet test "LeikwanBridge.Desktop\tests\LeikwanBridge.App.Tests\LeikwanBridge.App.Tests.csproj"
dotnet test "LeikwanBridge.Desktop\tests\LeikwanBridge.Core.Tests\LeikwanBridge.Core.Tests.csproj"
```

Expected: both test projects PASS
