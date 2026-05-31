using System.Text.Json;
using LeikwanBridge.App;
using LeikwanBridge.Core.Models;
using Xunit;

namespace LeikwanBridge.App.Tests;

public sealed class AppDefaultsTests
{
    [Fact]
    public void GetInstallRoot_ReturnsExplicitEnvironmentOverride_First()
    {
        using var scope = new EnvironmentVariableScope("LEIKWAN_INSTALL_DIR", @"D:\LeiKwan Bridge Test");

        Assert.Equal(@"D:\LeiKwan Bridge Test", AppDefaults.GetInstallRoot());
    }

    [Fact]
    public void GetInstallRoot_FallsBackToProgramFiles_WhenNoOverrideExists()
    {
        using var scope = new EnvironmentVariableScope("LEIKWAN_INSTALL_DIR", null);

        var root = AppDefaults.GetInstallRoot();

        Assert.Equal(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles), "LeiKwan Bridge"), root);
    }

    [Fact]
    public void GetInstallRoot_UsesInstallStateBesideCurrentExecutable_WhenAvailable()
    {
        using var scope = new EnvironmentVariableScope("LEIKWAN_INSTALL_DIR", null);
        using var tempDir = new TempStateDirectory();
        var installRoot = Path.Combine(tempDir.Path, "LeiKwan Bridge");
        Directory.CreateDirectory(installRoot);
        File.WriteAllText(
            Path.Combine(installRoot, "install-state.json"),
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
            installStatePathOverride: Path.Combine(installRoot, "install-state.json"));

        Assert.Equal(installRoot, discovered);
    }

    private sealed class EnvironmentVariableScope : IDisposable
    {
        private readonly string _name;
        private readonly string? _originalValue;

        public EnvironmentVariableScope(string name, string? value)
        {
            _name = name;
            _originalValue = Environment.GetEnvironmentVariable(name);
            Environment.SetEnvironmentVariable(name, value);
        }

        public void Dispose()
        {
            Environment.SetEnvironmentVariable(_name, _originalValue);
        }
    }

    private sealed class TempStateDirectory : IDisposable
    {
        public TempStateDirectory()
        {
            Path = System.IO.Path.Combine(System.IO.Path.GetTempPath(), "leikwan-app-tests", Guid.NewGuid().ToString("N"));
            Directory.CreateDirectory(Path);
        }

        public string Path { get; }

        public void Dispose()
        {
            if (Directory.Exists(Path))
                Directory.Delete(Path, recursive: true);
        }
    }
}
