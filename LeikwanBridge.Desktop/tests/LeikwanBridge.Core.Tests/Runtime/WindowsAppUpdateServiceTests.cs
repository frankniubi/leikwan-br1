using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class WindowsAppUpdateServiceTests
{
    [Fact]
    public void AgentConfig_CanStorePendingWindowsUpdateState()
    {
        var config = new AgentConfig
        {
            PendingUpdateVersion = "0.1.15.0",
            PendingUpdateInstallerPath = @"C:\Users\demo\AppData\Local\LeiKwan Bridge\updates\leikwan-bridge-setup-0.1.15.0.exe",
            PendingUpdateSha256 = "ABC123",
            PendingUpdateDownloadedAt = "2026-05-06T12:34:56Z",
        };

        Assert.Equal("0.1.15.0", config.PendingUpdateVersion);
        Assert.EndsWith("leikwan-bridge-setup-0.1.15.0.exe", config.PendingUpdateInstallerPath);
        Assert.Equal("ABC123", config.PendingUpdateSha256);
    }

    [Fact]
    public void WindowsAppUpdateManifest_DeserializesWindowsClientMetadata()
    {
        var json = """
{
  "windows_client_app": {
    "version": "0.1.15.0",
    "path": "artifacts/windows/leikwan-bridge-setup.exe",
    "artifact_url": "/artifacts/leikwan-bridge-setup.exe"
  }
}
""";

        var manifest = System.Text.Json.JsonSerializer.Deserialize<WindowsAppUpdateManifest>(json)!;

        Assert.Equal("0.1.15.0", manifest.WindowsClientApp.Version);
        Assert.Equal("/artifacts/leikwan-bridge-setup.exe", manifest.WindowsClientApp.ArtifactUrl);
    }

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
        Assert.Contains("/ALLUSERS", args);
        Assert.Contains("/LEIKWANUPDATE=1", args);
        Assert.Contains(@"/LEIKWANINSTALLDIR=C:\Program Files\LeiKwan Bridge", args);
        Assert.Contains("/LEIKWANINSTALLMODE=all-users", args);
        Assert.Contains("/LEIKWANDESKTOPSHORTCUT=0", args);
    }

    [Fact]
    public void BuildSilentUpdateArguments_UsesCurrentUserFlag_ForPerUserInstalls()
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

        var args = WindowsAppUpdateService.BuildSilentUpdateArguments(
            installerPath: @"C:\Users\demo\AppData\Local\LeiKwan Bridge\updates\leikwan-bridge-setup-0.1.16.0.exe",
            currentInstallRoot: @"C:\Users\demo\AppData\Local\Programs\LeiKwan Bridge",
            state);

        Assert.Contains("/CURRENTUSER", args);
        Assert.DoesNotContain("/ALLUSERS", args);
    }

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

    [Fact]
    public void BuildSilentUpdateArguments_ThrowsWhenInstallStateInstallDirIsEmpty()
    {
        var state = new WindowsAppInstallState
        {
            InstallMode = "just-for-me",
            InstallDir = "   ",
            DesktopShortcut = true,
            DisplayName = "LeiKwan Bridge | 利群會聚",
            InstalledVersion = "0.1.15.0",
            InstalledAt = "2026-05-30T12:34:56Z",
        };

        var ex = Assert.Throws<InvalidOperationException>(() =>
            WindowsAppUpdateService.BuildSilentUpdateArguments(
                installerPath: @"C:\Users\demo\AppData\Local\LeiKwan Bridge\updates\leikwan-bridge-setup-0.1.16.0.exe",
                currentInstallRoot: @"C:\Users\demo\AppData\Local\Programs\LeiKwan Bridge",
                state));

        Assert.Contains("install-state", ex.Message, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public void BuildSilentUpdateArguments_PreservesDriveRootInstallDir()
    {
        var state = new WindowsAppInstallState
        {
            InstallMode = "all-users",
            InstallDir = @"C:\",
            DesktopShortcut = false,
            DisplayName = "LeiKwan Bridge | 利群會聚",
            InstalledVersion = "0.1.15.0",
            InstalledAt = "2026-05-30T12:34:56Z",
        };

        var args = WindowsAppUpdateService.BuildSilentUpdateArguments(
            installerPath: @"C:\Users\demo\AppData\Local\LeiKwan Bridge\updates\leikwan-bridge-setup-0.1.16.0.exe",
            currentInstallRoot: @"C:\",
            state);

        Assert.Contains(@"/LEIKWANINSTALLDIR=C:\", args);
    }

    [Fact]
    public void BuildSilentUpdateArguments_ThrowsWhenInstallModeIsMissing()
    {
        var state = new WindowsAppInstallState
        {
            InstallMode = " ",
            InstallDir = @"C:\Users\demo\AppData\Local\Programs\LeiKwan Bridge",
            DesktopShortcut = true,
            DisplayName = "LeiKwan Bridge | 利群會聚",
            InstalledVersion = "0.1.15.0",
            InstalledAt = "2026-05-30T12:34:56Z",
        };

        var ex = Assert.Throws<InvalidOperationException>(() =>
            WindowsAppUpdateService.BuildSilentUpdateArguments(
                installerPath: @"C:\Users\demo\AppData\Local\LeiKwan Bridge\updates\leikwan-bridge-setup-0.1.16.0.exe",
                currentInstallRoot: @"C:\Users\demo\AppData\Local\Programs\LeiKwan Bridge",
                state));

        Assert.Contains("install-state", ex.Message, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public void BuildSilentUpdateArguments_ThrowsWhenDesktopShortcutIsMissing()
    {
        var state = new WindowsAppInstallState
        {
            InstallMode = "just-for-me",
            InstallDir = @"C:\Users\demo\AppData\Local\Programs\LeiKwan Bridge",
            DesktopShortcut = null,
            DisplayName = "LeiKwan Bridge | 利群會聚",
            InstalledVersion = "0.1.15.0",
            InstalledAt = "2026-05-30T12:34:56Z",
        };

        var ex = Assert.Throws<InvalidOperationException>(() =>
            WindowsAppUpdateService.BuildSilentUpdateArguments(
                installerPath: @"C:\Users\demo\AppData\Local\LeiKwan Bridge\updates\leikwan-bridge-setup-0.1.16.0.exe",
                currentInstallRoot: @"C:\Users\demo\AppData\Local\Programs\LeiKwan Bridge",
                state));

        Assert.Contains("install-state", ex.Message, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public void IsRemoteVersionNewer_ReturnsTrue_WhenManifestVersionIsHigher()
    {
        Assert.True(WindowsAppUpdateService.IsRemoteVersionNewer("0.1.15.0", "0.1.14.0"));
    }

    [Fact]
    public void IsRemoteVersionNewer_ReturnsFalse_WhenManifestVersionIsSameOrLower()
    {
        Assert.False(WindowsAppUpdateService.IsRemoteVersionNewer("0.1.14.0", "0.1.14.0"));
        Assert.False(WindowsAppUpdateService.IsRemoteVersionNewer("0.1.13.9", "0.1.14.0"));
    }

    [Fact]
    public void BuildCachedInstallerPath_UsesDeterministicUpdatesDirectory()
    {
        var path = WindowsAppUpdateService.BuildCachedInstallerPath(
            @"C:\Users\demo\AppData\Local\LeiKwan Bridge",
            "0.1.15.0");

        Assert.EndsWith(@"updates\leikwan-bridge-setup-0.1.15.0.exe", path);
    }

    [Fact]
    public void ResolveArtifactUrl_CombinesRelativeArtifactPathWithMasterUrl()
    {
        var url = WindowsAppUpdateService.ResolveArtifactUrl(
            "https://v3-mirror.leikwanhost.com",
            "/artifacts/leikwan-bridge-setup.exe");

        Assert.Equal("https://v3-mirror.leikwanhost.com/artifacts/leikwan-bridge-setup.exe", url);
    }

    [Fact]
    public void ResolveArtifactUrl_PreservesAbsoluteArtifactUrl()
    {
        var url = WindowsAppUpdateService.ResolveArtifactUrl(
            "https://v3-mirror.leikwanhost.com",
            "https://cdn.example.com/leikwan-bridge-setup.exe");

        Assert.Equal("https://cdn.example.com/leikwan-bridge-setup.exe", url);
    }


    [Fact]
    public void ShouldCheckForUpdatesOnStartup_ReturnsFalse_WhenAutomaticUpdatesDisabled()
    {
        var config = new AgentConfig
        {
            MasterUrl = "https://v3-mirror.leikwanhost.com",
            DisableAutoUpdates = true,
        };

        Assert.False(WindowsAppUpdateService.ShouldCheckForUpdatesOnStartup(config));
    }

    [Theory]
    [InlineData("0.1.17", "0.1.17.0")]
    [InlineData("0.1.17.0", "0.1.17")]
    public void IsRemoteVersionNewer_TreatsEquivalentVersionWidthsAsSame(string remoteVersion, string localVersion)
    {
        Assert.False(WindowsAppUpdateService.IsRemoteVersionNewer(remoteVersion, localVersion));
    }


    [Fact]
    public void HasPendingUpdate_ReturnsTrue_WhenConfigContainsInstallerPathAndVersion()
    {
        var config = new AgentConfig
        {
            PendingUpdateVersion = "0.1.15.0",
            PendingUpdateInstallerPath = @"C:\Users\demo\AppData\Local\LeiKwan Bridge\updates\leikwan-bridge-setup-0.1.15.0.exe",
            PendingUpdateSha256 = "ABC123",
        };

        Assert.True(WindowsAppUpdateService.HasPendingUpdate(config));
    }

    [Fact]
    public void HasPendingUpdate_ReturnsFalse_WhenVersionOrInstallerPathMissing()
    {
        Assert.False(WindowsAppUpdateService.HasPendingUpdate(new AgentConfig()));
        Assert.False(WindowsAppUpdateService.HasPendingUpdate(new AgentConfig { PendingUpdateVersion = "0.1.15.0" }));
    }
}
