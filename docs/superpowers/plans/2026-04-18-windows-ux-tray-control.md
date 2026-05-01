# Windows UX / Tray / Control Surface Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the Windows client's post-login blank window, add a real tray workflow, improve the main control page, add MPTCP auto-filtering after rule retrieval, and reduce visible restart thrash.

**Architecture:** Keep the current WPF app shell and view model structure, but repair visibility state, add tray lifecycle support, move forward filtering into explicit view-model state, and slightly slow whole-forward rebuilds after critical process exit. Backend transport behavior stays as-is; this pass is about shell usability and operator control.

**Tech Stack:** .NET 8 WPF, CommunityToolkit.Mvvm, System.Windows.Forms.NotifyIcon, xUnit

---

### Task 1: Lock White-Screen And Filter Behavior With Tests

**Files:**
- Create: `LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/LeikwanBridge.App.Tests.csproj`
- Create: `LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/ViewModels/MainViewModelTests.cs`
- Modify: `LeikwanBridge.Desktop/LeikwanBridge.Desktop.sln`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/ViewModels/MainViewModelTests.cs`

- [ ] **Step 1: Write the failing App test project and view-model tests**

```xml
<Project Sdk="Microsoft.NET.Sdk">
  <PropertyGroup>
    <TargetFramework>net8.0-windows</TargetFramework>
    <ImplicitUsings>enable</ImplicitUsings>
    <Nullable>enable</Nullable>
    <IsPackable>false</IsPackable>
  </PropertyGroup>
  <ItemGroup>
    <PackageReference Include="Microsoft.NET.Test.Sdk" Version="17.11.1" />
    <PackageReference Include="xunit" Version="2.9.2" />
    <PackageReference Include="xunit.runner.visualstudio" Version="2.8.2">
      <IncludeAssets>runtime; build; native; contentfiles; analyzers; buildtransitive</IncludeAssets>
      <PrivateAssets>all</PrivateAssets>
    </PackageReference>
  </ItemGroup>
  <ItemGroup>
    <ProjectReference Include="..\\..\\src\\LeikwanBridge.App\\LeikwanBridge.App.csproj" />
  </ItemGroup>
</Project>
```

```csharp
[Fact]
public void Initialize_WithValidConfig_HidesSetupWizard()
{
    var vm = new MainViewModel();
    vm.Initialize(new AgentConfig
    {
        MasterUrl = "https://v3.leikwanhost.com",
        ClientId = 1,
        AgentToken = "token"
    });

    Assert.False(vm.ShowSetupWizard);
}

[Fact]
public void ApplyForwardSnapshot_AutoSelectsMptcpFilter_WhenMptcpForwardExists()
{
    var vm = new MainViewModel();
    vm.ApplyForwardSnapshot(new[]
    {
        CreateForward(24, "mptcp"),
        CreateForward(26, "bond"),
    }, isRunning: _ => false);

    Assert.Equal("MPTCP", vm.ActiveModeFilter);
    Assert.Single(vm.VisibleForwards);
    Assert.Equal(24, vm.VisibleForwards[0].ForwardId);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `dotnet test "LeikwanBridge.Desktop\\tests\\LeikwanBridge.App.Tests\\LeikwanBridge.App.Tests.csproj"`
Expected: FAIL because no App test project exists yet and `MainViewModel` does not expose explicit filter state or a reusable forward-snapshot method.

- [ ] **Step 3: Add the test project to the solution**

```bash
dotnet sln "LeikwanBridge.Desktop\\LeikwanBridge.Desktop.sln" add "LeikwanBridge.Desktop\\tests\\LeikwanBridge.App.Tests\\LeikwanBridge.App.Tests.csproj"
```

- [ ] **Step 4: Run tests again to verify they now fail on missing behavior**

Run: `dotnet test "LeikwanBridge.Desktop\\tests\\LeikwanBridge.App.Tests\\LeikwanBridge.App.Tests.csproj"`
Expected: FAIL on missing filter/view-model surface rather than project wiring.

- [ ] **Step 5: Commit**

```bash
git add "LeikwanBridge.Desktop\\tests\\LeikwanBridge.App.Tests\\LeikwanBridge.App.Tests.csproj" "LeikwanBridge.Desktop\\tests\\LeikwanBridge.App.Tests\\ViewModels\\MainViewModelTests.cs" "LeikwanBridge.Desktop\\LeikwanBridge.Desktop.sln"
git commit -m "test: add windows app view-model coverage"
```

### Task 2: Fix Blank Window And Add MPTCP Auto Filter

**Files:**
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.App/Converters/Converters.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.App/ViewModels/MainViewModel.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.App/ViewModels/ForwardViewModel.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.App/Resources/Strings/en-US.xaml`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/ViewModels/MainViewModelTests.cs`

- [ ] **Step 1: Write one more failing test for non-MPTCP fallback**

```csharp
[Fact]
public void ApplyForwardSnapshot_FallsBackToAllFilter_WhenNoMptcpForwardExists()
{
    var vm = new MainViewModel();
    vm.ApplyForwardSnapshot(new[]
    {
        CreateForward(26, "bond"),
        CreateForward(27, "single"),
    }, isRunning: _ => false);

    Assert.Equal("All", vm.ActiveModeFilter);
    Assert.Equal(2, vm.VisibleForwards.Count);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `dotnet test "LeikwanBridge.Desktop\\tests\\LeikwanBridge.App.Tests\\LeikwanBridge.App.Tests.csproj" --filter MainViewModelTests`
Expected: FAIL because filter state and visible-forward projection do not exist yet.

- [ ] **Step 3: Implement the minimal UI-state fixes**

```csharp
public class InverseBoolToVisibilityConverter : IValueConverter
{
    public object Convert(object value, Type targetType, object parameter, CultureInfo culture)
        => value is true ? Visibility.Collapsed : Visibility.Visible;

    public object ConvertBack(object value, Type targetType, object parameter, CultureInfo culture)
        => value is Visibility.Collapsed;
}
```

```csharp
[ObservableProperty] private string _activeModeFilter = "All";
public ObservableCollection<ForwardViewModel> VisibleForwards { get; } = [];

public void ApplyForwardSnapshot(IEnumerable<ForwardConfig> forwards, Func<int, bool> isRunning)
{
    Forwards.Clear();
    foreach (var forward in forwards)
    {
        Forwards.Add(new ForwardViewModel(forward) { IsRunning = isRunning(forward.ForwardId) });
    }

    ActiveModeFilter = Forwards.Any(f => f.Mode == "mptcp") ? "MPTCP" : "All";
    RebuildVisibleForwards();
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `dotnet test "LeikwanBridge.Desktop\\tests\\LeikwanBridge.App.Tests\\LeikwanBridge.App.Tests.csproj" --filter MainViewModelTests`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add "LeikwanBridge.Desktop\\src\\LeikwanBridge.App\\Converters\\Converters.cs" "LeikwanBridge.Desktop\\src\\LeikwanBridge.App\\ViewModels\\MainViewModel.cs" "LeikwanBridge.Desktop\\src\\LeikwanBridge.App\\ViewModels\\ForwardViewModel.cs" "LeikwanBridge.Desktop\\src\\LeikwanBridge.App\\Views\\MainWindow.xaml" "LeikwanBridge.Desktop\\src\\LeikwanBridge.App\\Resources\\Strings\\en-US.xaml" "LeikwanBridge.Desktop\\tests\\LeikwanBridge.App.Tests\\ViewModels\\MainViewModelTests.cs"
git commit -m "fix: restore app shell visibility and add mptcp auto filter"
```

### Task 3: Add Tray Support And Improve The Main Control Surface

**Files:**
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.App/LeikwanBridge.App.csproj`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.App/App.xaml.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.App/Views/MainWindow.xaml.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.App/Resources/Styles/Theme.xaml`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.App/Resources/Strings/en-US.xaml`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.App.Tests/ViewModels/MainViewModelTests.cs`

- [ ] **Step 1: Write a failing test for mode-filter options**

```csharp
[Fact]
public void AvailableModeFilters_ContainsMptcp_WhenMptcpForwardExists()
{
    var vm = new MainViewModel();
    vm.ApplyForwardSnapshot(new[]
    {
        CreateForward(24, "mptcp"),
        CreateForward(26, "bond"),
    }, isRunning: _ => false);

    Assert.Contains("MPTCP", vm.AvailableModeFilters);
}
```

- [ ] **Step 2: Run tests to verify it fails**

Run: `dotnet test "LeikwanBridge.Desktop\\tests\\LeikwanBridge.App.Tests\\LeikwanBridge.App.Tests.csproj"`
Expected: FAIL because mode-filter options do not exist yet.

- [ ] **Step 3: Implement tray lifecycle and dashboard reshaping**

```xml
<UseWindowsForms>true</UseWindowsForms>
```

```csharp
private NotifyIcon? _trayIcon;
private bool _trayReady;

private void InitializeTray()
{
    _trayIcon = new NotifyIcon
    {
        Text = "Leikwan Bridge",
        Icon = SystemIcons.Application,
        Visible = true,
        ContextMenuStrip = BuildTrayMenu(),
    };
    _trayIcon.DoubleClick += (_, _) => RestoreFromTray();
    _trayReady = true;
}
```

- [ ] **Step 4: Run tests and build to verify they pass**

Run: `dotnet test "LeikwanBridge.Desktop\\tests\\LeikwanBridge.App.Tests\\LeikwanBridge.App.Tests.csproj"`
Expected: PASS

Run: `dotnet build "LeikwanBridge.Desktop\\LeikwanBridge.Desktop.sln"`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add "LeikwanBridge.Desktop\\src\\LeikwanBridge.App\\LeikwanBridge.App.csproj" "LeikwanBridge.Desktop\\src\\LeikwanBridge.App\\App.xaml.cs" "LeikwanBridge.Desktop\\src\\LeikwanBridge.App\\Views\\MainWindow.xaml" "LeikwanBridge.Desktop\\src\\LeikwanBridge.App\\Views\\MainWindow.xaml.cs" "LeikwanBridge.Desktop\\src\\LeikwanBridge.App\\Resources\\Styles\\Theme.xaml" "LeikwanBridge.Desktop\\src\\LeikwanBridge.App\\Resources\\Strings\\en-US.xaml" "LeikwanBridge.Desktop\\tests\\LeikwanBridge.App.Tests\\ViewModels\\MainViewModelTests.cs"
git commit -m "feat: add windows tray support and control surface refresh"
```

### Task 4: Smooth Restart Thrash, Rebuild Release, And Republish

**Files:**
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/HealthCheckService.cs`
- Modify: `LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/ForwardRuntimeManager.cs`
- Modify: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/HealthCheckServiceTests.cs`
- Modify: `artifacts/versions.json`
- Modify: `scripts/build-windows-client-setup.ps1`
- Test: `LeikwanBridge.Desktop/tests/LeikwanBridge.Core.Tests/Runtime/HealthCheckServiceTests.cs`

- [ ] **Step 1: Write the failing recovery backoff test**

```csharp
[Fact]
public void CriticalProcessExit_DoesNotImmediateThrashRestart_WhenCooldownActive()
{
    // arrange a recent restart timestamp, trigger another critical exit,
    // assert that a second rebuild is not scheduled immediately
}
```

- [ ] **Step 2: Run tests to verify it fails**

Run: `dotnet test "LeikwanBridge.Desktop\\tests\\LeikwanBridge.Core.Tests\\LeikwanBridge.Core.Tests.csproj" --filter HealthCheckServiceTests`
Expected: FAIL because the current rebuild path is still too eager.

- [ ] **Step 3: Implement the minimal restart smoothing**

```csharp
if (!ShouldRestart(forwardId))
    return;

_restartCooldown[forwardId] = DateTime.UtcNow;
_runtimeManager.RestartForward(forward);
```

- [ ] **Step 4: Run the full verification set**

Run: `dotnet test "LeikwanBridge.Desktop\\tests\\LeikwanBridge.Core.Tests\\LeikwanBridge.Core.Tests.csproj"`
Expected: PASS

Run: `dotnet test "LeikwanBridge.Desktop\\tests\\LeikwanBridge.App.Tests\\LeikwanBridge.App.Tests.csproj"`
Expected: PASS

Run: `dotnet build "LeikwanBridge.Desktop\\LeikwanBridge.Desktop.sln"`
Expected: PASS

- [ ] **Step 5: Rebuild and republish**

Run:

```bash
dotnet publish "LeikwanBridge.Desktop\\src\\LeikwanBridge.App\\LeikwanBridge.App.csproj" -c Release -r win-x64 -p:PublishSingleFile=true -p:SelfContained=true -p:IncludeNativeLibrariesForSelfExtract=true -o "artifacts\\windows\\publish"
powershell -ExecutionPolicy Bypass -File ".\\scripts\\build-windows-client-setup.ps1"
```

Expected: new setup exe produced for local replacement and panel upload
