using LeikwanBridge.Core.Models;
using System.Net;
using System.Text;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

[Trait("Platform", "Windows")]
public sealed class WindowsAppUpdateServiceTests
{
    [Fact]
    public void AgentConfig_MigrateLegacyUltraBin_UsesInstalledRuntimePath()
    {
        using var tempDir = new TempUpdateDirectory();
        var installedBin = Path.Combine(tempDir.Path, "bin", "ultra-client.exe");
        Directory.CreateDirectory(Path.GetDirectoryName(installedBin)!);
        File.WriteAllText(installedBin, "runtime");
        var config = new AgentConfig
        {
            UltraBin = @"C:\Users\demo\AppData\Local\LeiKwan Bridge\runtime\ultra-client.exe",
        };

        Assert.True(config.MigrateLegacyUltraBin(tempDir.Path));
        Assert.Equal(installedBin, config.UltraBin);
    }

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
    public void HasRuntimeDrift_RequestsUpdateWhenInstalledUltraHashDiffers()
    {
        using var tempDir = new TempUpdateDirectory();
        var ultra = Path.Combine(tempDir.Path, "ultra.exe");
        File.WriteAllText(ultra, "old-runtime");
        var config = new AgentConfig { UltraBin = ultra };
        var artifact = new WindowsClientAppArtifact
        {
            Version = "0.1.20.27",
            RuntimeSha256 = new Dictionary<string, string>
            {
                ["ultra_client_runtime_windows"] = new string('a', 64),
            },
        };

        Assert.True(WindowsAppUpdateService.HasRuntimeDrift(config, artifact));
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
    public void BuildArtifactDownloadUrl_AddsVersionAndHashCacheBust()
    {
        var url = WindowsAppUpdateService.BuildArtifactDownloadUrl(
            "https://v3-mirror.leikwanhost.com/artifacts/leikwan-bridge-setup.exe",
            "0.1.19.4",
            "ABC123");

        Assert.Equal(
            "https://v3-mirror.leikwanhost.com/artifacts/leikwan-bridge-setup.exe?v=0.1.19.4&sha256=ABC123",
            url);
    }

    [Fact]
    public void BuildArtifactDownloadUrl_DoesNotDuplicateMatchingCacheBust()
    {
        var url = WindowsAppUpdateService.BuildArtifactDownloadUrl(
            "https://v3-mirror.leikwanhost.com/artifacts/leikwan-bridge-setup.exe?v=0.1.19.4&sha256=ABC123",
            "0.1.19.4",
            "ABC123");

        Assert.Equal(
            "https://v3-mirror.leikwanhost.com/artifacts/leikwan-bridge-setup.exe?v=0.1.19.4&sha256=ABC123",
            url);
    }


    [Fact]
    public async Task FetchWindowsClientArtifactAsync_UsesInjectedAntiPoisonClient_ForProtectedLeikwanManifestHost()
    {
        using var http = new HttpClient(new StubUpdateHandler()) { Timeout = TimeSpan.FromSeconds(5) };
        var requestedUrls = new List<Uri>();
        var service = new WindowsAppUpdateService(
            httpClientFactory: _ => http,
            ownsHttpClient: false,
            onRequestForTest: request => requestedUrls.Add(request.RequestUri!));

        var artifact = await service.FetchWindowsClientArtifactAsync("https://v3-mirror.leikwanhost.com");

        Assert.Equal("0.1.15.0", artifact.Version);
        Assert.Equal("https://v3-mirror.leikwanhost.com/artifacts/client-manifest.json", requestedUrls.Single().ToString());
    }

    [Fact]
    public async Task DownloadInstallerAsync_UsesInjectedAntiPoisonClient_ForProtectedLeikwanArtifactHost()
    {
        using var tempDir = new TempUpdateDirectory();
        using var http = new HttpClient(new StubUpdateHandler()) { Timeout = TimeSpan.FromSeconds(5) };
        var requestedUrls = new List<Uri>();
        var service = new WindowsAppUpdateService(
            httpClientFactory: _ => http,
            ownsHttpClient: false,
            onRequestForTest: request => requestedUrls.Add(request.RequestUri!));
        var destination = Path.Combine(tempDir.Path, "setup.exe");

        await service.DownloadInstallerAsync("https://downloads.leikwanhost.com/artifacts/leikwan-bridge-setup.exe", destination);

        Assert.Equal("stub installer", File.ReadAllText(destination));
        Assert.Equal("https://downloads.leikwanhost.com/artifacts/leikwan-bridge-setup.exe", requestedUrls.Single().ToString());
    }

    [Fact]
    public async Task DownloadInstallerAsync_CustomCdnUsesNormalClientSelector()
    {
        using var tempDir = new TempUpdateDirectory();
        var selectedKinds = new List<LeikwanHttpClientKind>();
        using var http = new HttpClient(new StubUpdateHandler()) { Timeout = TimeSpan.FromSeconds(5) };
        var service = new WindowsAppUpdateService(
            httpClientFactory: kind =>
            {
                selectedKinds.Add(kind);
                return http;
            },
            ownsHttpClient: false);
        var destination = Path.Combine(tempDir.Path, "setup.exe");

        await service.DownloadInstallerAsync("https://cdn.example.com/leikwan-bridge-setup.exe", destination);

        Assert.Equal([LeikwanHttpClientKind.Normal], selectedKinds);
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

    [Fact]
    public void ResolveArtifactUrl_RebindsKnownEndpointOriginToSelectedEndpoint()
    {
        var url = WindowsAppUpdateService.ResolveArtifactUrl(
            "https://api-leikwanbridge.nbwly.com",
            "https://v3.leikwanhost.com/artifacts/leikwan-bridge-setup.exe?v=0.1.20.28&sha256=ABC123");

        Assert.Equal(
            "https://api-leikwanbridge.nbwly.com/artifacts/leikwan-bridge-setup.exe?v=0.1.20.28&sha256=ABC123",
            url);
    }

    [Fact]
    public void ResolveArtifactUrl_KeepsAbsoluteKnownOriginUrl_WhenMasterIsCustom()
    {
        var url = WindowsAppUpdateService.ResolveArtifactUrl(
            "https://panel.example.com",
            "https://v3.leikwanhost.com/artifacts/leikwan-bridge-setup.exe");

        Assert.Equal("https://v3.leikwanhost.com/artifacts/leikwan-bridge-setup.exe", url);
    }

    [Fact]
    public void ResolveArtifactUrl_KeepsSelectedEndpointOriginUntouched()
    {
        var url = WindowsAppUpdateService.ResolveArtifactUrl(
            "https://v3.leikwanhost.com",
            "https://v3.leikwanhost.com/artifacts/leikwan-bridge-setup.exe");

        Assert.Equal("https://v3.leikwanhost.com/artifacts/leikwan-bridge-setup.exe", url);
    }

    [Fact]
    public async Task FetchWindowsClientArtifactAsync_ReportsCheckPhaseProgress()
    {
        using var http = new HttpClient(new ProgressStubHandler()) { Timeout = TimeSpan.FromSeconds(5) };
        var updates = new List<UpdateProgress>();
        var service = new WindowsAppUpdateService(
            httpClientFactory: _ => http,
            ownsHttpClient: false);

        await service.FetchWindowsClientArtifactAsync(
            "https://v3-mirror.leikwanhost.com",
            new Progress<UpdateProgress>(updates.Add));

        Assert.NotEmpty(updates);
        Assert.All(updates, update => Assert.Equal(UpdatePhase.Check, update.Phase));
        Assert.Contains(updates, update => update.Message.Contains("Checking", StringComparison.OrdinalIgnoreCase));
    }

    [Fact]
    public async Task DownloadInstallerAsync_ReportsDownloadAndVerifyProgress()
    {
        using var tempDir = new TempUpdateDirectory();
        using var http = new HttpClient(new ProgressStubHandler()) { Timeout = TimeSpan.FromSeconds(5) };
        var updates = new List<UpdateProgress>();
        var service = new WindowsAppUpdateService(
            httpClientFactory: _ => http,
            ownsHttpClient: false);
        var destination = Path.Combine(tempDir.Path, "setup.exe");
        var localInstaller = Path.Combine(tempDir.Path, "source.exe");
        File.WriteAllText(localInstaller, "stub installer");
        var expectedSha = WindowsAppUpdateService.ComputeSha256(localInstaller);

        await service.DownloadInstallerAsync(
            "https://downloads.leikwanhost.com/artifacts/leikwan-bridge-setup.exe",
            destination,
            new Progress<UpdateProgress>(updates.Add),
            expectedSha);

        Assert.Equal("stub installer", File.ReadAllText(destination));
        Assert.Contains(updates, update => update.Phase == UpdatePhase.Download);
        Assert.Contains(updates, update => update.Phase == UpdatePhase.Download && update.Percent == 100);
        Assert.Contains(updates, update => update.Phase == UpdatePhase.Verify && update.Percent == 100);
    }

    [Fact]
    public async Task DownloadInstallerAsync_ThrowsAndCleansUpOnHashMismatch()
    {
        using var tempDir = new TempUpdateDirectory();
        using var http = new HttpClient(new ProgressStubHandler()) { Timeout = TimeSpan.FromSeconds(5) };
        var updates = new List<UpdateProgress>();
        var service = new WindowsAppUpdateService(
            httpClientFactory: _ => http,
            ownsHttpClient: false);
        var destination = Path.Combine(tempDir.Path, "setup.exe");

        var error = await Assert.ThrowsAsync<InvalidDataException>(() =>
            service.DownloadInstallerAsync(
                "https://downloads.leikwanhost.com/artifacts/leikwan-bridge-setup.exe",
                destination,
                new Progress<UpdateProgress>(updates.Add),
                new string('a', 64)));

        Assert.Equal("downloaded installer hash mismatch", error.Message);
        Assert.False(File.Exists(destination));
        Assert.Contains(updates, update => update.Phase == UpdatePhase.Verify);
    }

    private sealed class ProgressStubHandler : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            var path = request.RequestUri?.AbsolutePath ?? "";
            if (path.EndsWith("/client-manifest.json", StringComparison.OrdinalIgnoreCase))
            {
                return Task.FromResult(new HttpResponseMessage(HttpStatusCode.OK)
                {
                    RequestMessage = request,
                    Content = new StringContent("""
                        {
                          "component": "windows-client-app",
                          "manifest": {
                            "windows_client_app": {
                              "version": "0.1.15.0",
                              "path": "artifacts/windows/leikwan-bridge-setup.exe",
                              "artifact_url": "/artifacts/leikwan-bridge-setup.exe",
                              "sha256": "ABC123",
                              "size_bytes": 14
                            }
                          }
                        }
                        """, Encoding.UTF8, "application/json"),
                });
            }

            var content = new StringContent("stub installer", Encoding.UTF8, "application/octet-stream");
            content.Headers.ContentLength = 14;
            return Task.FromResult(new HttpResponseMessage(HttpStatusCode.OK)
            {
                RequestMessage = request,
                Content = content,
            });
        }
    }

    private sealed class StubUpdateHandler : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            var path = request.RequestUri?.AbsolutePath ?? "";
            if (path.EndsWith("/client-manifest.json", StringComparison.OrdinalIgnoreCase))
            {
                return Task.FromResult(new HttpResponseMessage(HttpStatusCode.OK)
                {
                    RequestMessage = request,
                    Content = new StringContent("""
                        {
                          "component": "windows-client-app",
                          "manifest": {
                            "windows_client_app": {
                              "version": "0.1.15.0",
                              "path": "artifacts/windows/leikwan-bridge-setup.exe",
                              "artifact_url": "/artifacts/leikwan-bridge-setup.exe",
                              "sha256": "ABC123",
                              "size_bytes": 14
                            }
                          }
                        }
                        """, Encoding.UTF8, "application/json"),
                });
            }

            return Task.FromResult(new HttpResponseMessage(HttpStatusCode.OK)
            {
                RequestMessage = request,
                Content = new StringContent("stub installer", Encoding.UTF8, "application/octet-stream"),
            });
        }
    }

    private sealed class TempUpdateDirectory : IDisposable
    {
        public TempUpdateDirectory()
        {
            Path = System.IO.Path.Combine(System.IO.Path.GetTempPath(), "leikwan-update-tests", Guid.NewGuid().ToString("N"));
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
