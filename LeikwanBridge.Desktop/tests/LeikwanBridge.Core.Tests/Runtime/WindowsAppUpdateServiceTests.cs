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
}
