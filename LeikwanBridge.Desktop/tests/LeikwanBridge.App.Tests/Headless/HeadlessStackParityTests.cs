using Xunit;

namespace LeikwanBridge.App.Tests.Headless;

/// <summary>
/// The windowless client builds its own copy of the Core service graph. These
/// assertions keep that copy from drifting away from the graph the GUI client
/// builds in MainViewModel.ConnectAsync: a missing line here means one of the
/// two surfaces silently runs without polling, heartbeat, health checks or the
/// port preflight that kills a stale managed runtime.
/// </summary>
public sealed class HeadlessStackParityTests
{
    private static readonly string[] SharedConstructionLines =
    [
        "new ProcessSupervisor(",
        "new ForwardRuntimeManager(",
        "portPreflight: PortConflictPreflight.Bind(",
        "new ConfigPollingService(",
        "new ClientDiagnosticsService(",
        "new HealthCheckService(",
        "new WireGuardManager(",
        "new HeartbeatService(",
        "MasterApiClient.BuildWindowsDeviceId(",
        "\"windows-desktop\"",
        "additionalRuntimeDirectories: [Path.Combine(",
        "\"proxy-runtime\")]",
    ];

    [Fact]
    public void WindowlessStackMirrorsTheGuiServiceGraph()
    {
        var repoRoot = FindRepositoryRoot();
        var gui = File.ReadAllText(Path.Combine(
            repoRoot, "LeikwanBridge.Desktop", "src", "LeikwanBridge.App", "ViewModels", "MainViewModel.cs"));
        var headless = File.ReadAllText(Path.Combine(
            repoRoot, "LeikwanBridge.Desktop", "src", "LeikwanBridge.Headless", "WindowsClientStack.cs"));

        foreach (var line in SharedConstructionLines)
        {
            Assert.Contains(line, gui, StringComparison.Ordinal);
            Assert.Contains(line, headless, StringComparison.Ordinal);
        }
    }

    [Fact]
    public void WindowlessStackReusesTheUltraPolicyRevocationRule()
    {
        var repoRoot = FindRepositoryRoot();
        var gui = File.ReadAllText(Path.Combine(
            repoRoot, "LeikwanBridge.Desktop", "src", "LeikwanBridge.App", "ViewModels", "MainViewModel.cs"));
        var headless = File.ReadAllText(Path.Combine(
            repoRoot, "LeikwanBridge.Desktop", "src", "LeikwanBridge.Headless", "WindowsClientStack.cs"));

        foreach (var line in new[] { "StopForwardForPolicy(action.ForwardId)", "^[a-f0-9]{64}$", "\"ultra\"" })
        {
            Assert.Contains(line, gui, StringComparison.Ordinal);
            Assert.Contains(line, headless, StringComparison.Ordinal);
        }
    }

    private static string FindRepositoryRoot()
    {
        var directory = new DirectoryInfo(Directory.GetCurrentDirectory());
        // A git worktree has a .git file instead of a .git directory.
        while (directory is not null && !File.Exists(Path.Combine(directory.FullName, ".git", "HEAD"))
            && !File.Exists(Path.Combine(directory.FullName, ".git")))
            directory = directory.Parent;
        return directory?.FullName ?? throw new DirectoryNotFoundException("repository root not found");
    }
}
