using System.Buffers.Binary;
using System.IO.Compression;
using System.Net;
using System.Security.Cryptography;
using System.Text;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Mac.Services;
using Xunit;

namespace LeikwanBridge.Mac.Tests;

public sealed class MacAppUpdateServiceTests
{
    [Fact]
    public async Task CheckAsync_SelectsTheAppleSiliconArtifacts()
    {
        using var temp = new TempDirectory();
        using var http = BuildHttp(request => JsonResponse(ManifestJson()));
        using var service = CreateService(temp, "arm64", http);

        var artifact = await service.CheckAsync("https://panel.example.test", "1.0.0");

        Assert.NotNull(artifact);
        Assert.Equal("arm64", artifact.Architecture);
        Assert.Contains("update-arm64.zip", artifact.ArtifactUrl);
        Assert.NotNull(artifact.ManualInstaller);
        Assert.Equal("dmg", artifact.ManualInstaller!.Format);
        Assert.Contains("client-arm64.dmg", artifact.ManualInstaller.ArtifactUrl);
    }

    [Theory]
    [InlineData("x64")]
    [InlineData("x86_64")]
    [InlineData("amd64")]
    public void Constructor_RejectsIntelArchitectures(string architecture)
    {
        using var temp = new TempDirectory();
        var exception = Assert.Throws<PlatformNotSupportedException>(() => new MacAppUpdateService(
            Path.Combine(temp.Path, "updates"),
            Path.Combine(temp.Path, "LeiKwan Bridge.app"),
            architecture));
        Assert.Equal("mac_architecture_unsupported", exception.Message);
    }

    [Fact]
    public async Task CheckAsync_IgnoresLegacyIntelEntriesInThePanelManifest()
    {
        // The panel may keep advertising the retired Intel packages for a while;
        // an Apple Silicon client must neither select nor be confused by them.
        using var temp = new TempDirectory();
        using var http = BuildHttp(_ => JsonResponse(ManifestJson(includeLegacyIntelEntries: true)));
        using var service = CreateService(temp, "arm64", http);

        var artifact = await service.CheckAsync("https://panel.example.test", "1.0.0");

        Assert.NotNull(artifact);
        Assert.Equal("arm64", artifact.Architecture);
        Assert.DoesNotContain("x64", artifact.ArtifactUrl);
        Assert.DoesNotContain("x64", artifact.ManualInstaller!.ArtifactUrl);
    }

    [Fact]
    public async Task CheckAsync_FailsWhenOnlyLegacyIntelEntriesArePublished()
    {
        using var temp = new TempDirectory();
        var sha = new string('A', 64);
        var manifest = $$"""
        {
          "component": "desktop-client-app",
          "manifest": {
            "macos_client_update_x64": { "version": "2.0.0", "artifact_url": "https://cdn.example.test/update-x64.zip", "sha256": "{{sha}}", "size_bytes": 14, "architecture": "x86_64", "format": "zip" },
            "macos_client_app_x64": { "version": "2.0.0", "artifact_url": "https://cdn.example.test/client-x64.dmg", "sha256": "{{sha}}", "size_bytes": 15, "architecture": "x86_64", "format": "dmg" }
          }
        }
        """;
        using var http = BuildHttp(_ => JsonResponse(manifest));
        using var service = CreateService(temp, "arm64", http);

        var exception = await Assert.ThrowsAsync<InvalidDataException>(
            () => service.CheckAsync("https://panel.example.test", "1.0.0"));
        Assert.Equal("update_manifest_architecture_missing", exception.Message);
    }

    [Fact]
    public async Task CheckAsync_ReturnsNullForSameOrOlderVersion()
    {
        using var temp = new TempDirectory();
        using var http = BuildHttp(_ => JsonResponse(ManifestJson(version: "1.0.0")));
        using var service = CreateService(temp, "arm64", http);

        Assert.Null(await service.CheckAsync("https://panel.example.test", "1.0.0.0"));
        Assert.Null(await service.CheckAsync("https://panel.example.test", "2.0.0"));
    }

    [Fact]
    public async Task CheckAsync_IgnoresRuntimeHashesForOtherArchitectures()
    {
        using var temp = new TempDirectory();
        var currentHash = Sha256(ThinMachO("arm64"));
        using var http = BuildHttp(_ => JsonResponse(ManifestJson(
            version: "1.0.0",
            runtimeEntries: $"\"ultra_client_runtime_macos_arm64\": {{ \"sha256\": \"{currentHash}\" }}, \"ultra_client_runtime_macos_x64\": {{ \"sha256\": \"{new string('B', 64)}\" }}, \"leikwan_uot_bond_linux_arm64\": {{ \"sha256\": \"{new string('C', 64)}\" }}")));
        using var service = CreateService(temp, "arm64", http);

        Assert.Null(await service.CheckAsync("https://panel.example.test", "1.0.0"));
    }

    [Fact]
    public async Task CheckAsync_RequestsUpdateWhenCurrentRuntimeDiffers()
    {
        using var temp = new TempDirectory();
        using var http = BuildHttp(_ => JsonResponse(ManifestJson(
            version: "1.0.0",
            runtimeEntries: $"\"ultra_client_runtime_macos_arm64\": {{ \"sha256\": \"{new string('B', 64)}\" }}")));
        using var service = CreateService(temp, "arm64", http);

        Assert.NotNull(await service.CheckAsync("https://panel.example.test", "1.0.0"));
    }

    [Fact]
    public async Task CheckAsync_RequestsUpdateWhenPackagedHelperDiffers()
    {
        using var temp = new TempDirectory();
        var currentHash = Sha256(ThinMachO("arm64"));
        using var http = BuildHttp(_ => JsonResponse(ManifestJson(
            version: "1.0.0",
            packageRuntimeSha256: $"\"bin/ultra-client\": \"{currentHash}\", \"bin/wstunnel\": \"{new string('B', 64)}\"")));
        using var service = CreateService(temp, "arm64", http);

        Assert.NotNull(await service.CheckAsync("https://panel.example.test", "1.0.0"));
    }

    [Fact]
    public async Task PrepareAsync_RejectsSizeAndHashMismatches()
    {
        using var temp = new TempDirectory();
        var package = Encoding.UTF8.GetBytes("not-the-declared-package");
        using var http = BuildHttp(_ => BytesResponse(package));
        using var service = CreateService(temp, "arm64", http);
        var artifact = ArtifactFor(package, size: package.Length + 1);

        var sizeError = await Assert.ThrowsAsync<InvalidDataException>(() => service.PrepareAsync(artifact));
        Assert.Equal("update_size_mismatch", sizeError.Message);

        artifact = artifact with { SizeBytes = package.Length, Sha256 = new string('a', 64) };
        var hashError = await Assert.ThrowsAsync<InvalidDataException>(() => service.PrepareAsync(artifact));
        Assert.Equal("update_hash_mismatch", hashError.Message);
    }

    [Fact]
    public async Task PrepareAsync_RejectsCrossOriginPlainHttpBeforeRequest()
    {
        using var temp = new TempDirectory();
        var requests = 0;
        using var http = BuildHttp(_ =>
        {
            requests++;
            return BytesResponse([]);
        });
        using var service = CreateService(temp, "arm64", http);
        var artifact = ArtifactFor([1]) with
        {
            ArtifactUrl = "http://cdn.example.test/client-arm64.zip",
        };

        var error = await Assert.ThrowsAsync<InvalidDataException>(() => service.PrepareAsync(artifact));

        Assert.Equal("update_artifact_url_invalid", error.Message);
        Assert.Equal(0, requests);
    }

    [Fact]
    public async Task PrepareAsync_RejectsZipPathTraversalBeforeExtraction()
    {
        using var temp = new TempDirectory();
        var package = BuildZip(archive =>
        {
            var entry = archive.CreateEntry("../outside.txt");
            using var writer = new StreamWriter(entry.Open(), Encoding.UTF8);
            writer.Write("escape");
        });
        using var http = BuildHttp(_ => BytesResponse(package));
        using var service = CreateService(temp, "arm64", http);

        var error = await Assert.ThrowsAsync<InvalidDataException>(() => service.PrepareAsync(ArtifactFor(package)));

        Assert.Equal("update_archive_path_invalid", error.Message);
        Assert.False(File.Exists(Path.Combine(temp.Path, "outside.txt")));
    }

    [Theory]
    [InlineData("com.example.wrong", "2.0.0", "arm64", "bundle_identifier_invalid")]
    [InlineData("com.leikwan.bridge", "1.9.0", "arm64", "bundle_version_invalid")]
    [InlineData("com.leikwan.bridge", "2.0.0", "x86_64", "bundle_architecture_invalid")]
    public void BundleValidator_RejectsWrongIdentityVersionOrArchitecture(
        string bundleId,
        string version,
        string binaryArchitecture,
        string expectedError)
    {
        using var temp = new TempDirectory();
        var bundle = CreateBundle(temp.Path, bundleId, version, binaryArchitecture);
        var artifact = new ClientAppArtifact
        {
            Version = "2.0.0",
            Architecture = "arm64",
            Format = "zip",
        };

        var error = Assert.Throws<InvalidDataException>(() => new MacBundleValidator().Validate(bundle, artifact));

        Assert.Equal(expectedError, error.Message);
    }

    [Fact]
    public void BundleValidator_RejectsHelperHashMismatch()
    {
        using var temp = new TempDirectory();
        var bundle = CreateBundle(temp.Path, "com.leikwan.bridge", "2.0.0", "arm64");
        var helperPath = Path.Combine(bundle, "Contents", "Resources", "bin", "ultra-client");
        var tampered = File.ReadAllBytes(helperPath);
        tampered[^1] ^= 0x5A;
        File.WriteAllBytes(helperPath, tampered);

        var error = Assert.Throws<InvalidDataException>(() => new MacBundleValidator().Validate(bundle, new ClientAppArtifact
        {
            Version = "2.0.0",
            Architecture = "arm64",
            Format = "zip",
        }));

        Assert.Equal("helper_hash_mismatch", error.Message);
    }

    [Fact]
    public async Task PrepareAsync_ValidatesAndStagesOneBundle()
    {
        using var source = new TempDirectory();
        using var temp = new TempDirectory();
        var bundle = CreateBundle(source.Path, "com.leikwan.bridge", "2.0.0", "arm64");
        var package = ZipDirectory(bundle);
        using var http = BuildHttp(_ => BytesResponse(package));
        using var service = CreateService(temp, "arm64", http);

        var prepared = await service.PrepareAsync(ArtifactFor(package));

        Assert.True(Directory.Exists(prepared.StagedBundlePath));
        Assert.True(File.Exists(prepared.PackagePath));
        Assert.Equal("arm64", prepared.Artifact.Architecture);
        Assert.Matches("^[A-F0-9]{64}$", prepared.MainExecutableSha256);
    }

    [Fact]
    public async Task ApplyAsync_UsesVerifiedDmgWhenBundleParentIsNotWritable()
    {
        using var temp = new TempDirectory();
        var dmg = Encoding.UTF8.GetBytes("verified-dmg");
        using var http = BuildHttp(_ => BytesResponse(dmg));
        var launcher = new FakeUpdateLauncher();
        using var service = CreateService(temp, "arm64", http, launcher, _ => false);
        var stagedBundle = CreateBundle(temp.Path, "com.leikwan.bridge", "2.0.0", "arm64", "Staged.app");
        var updateArtifact = ArtifactFor(Encoding.UTF8.GetBytes("zip")) with
        {
            ManualInstaller = new ClientAppArtifact
            {
                Version = "2.0.0",
                ArtifactUrl = "https://cdn.example.test/client-arm64.dmg",
                Sha256 = Sha256(dmg),
                SizeBytes = dmg.Length,
                Architecture = "arm64",
                Format = "dmg",
            },
        };
        var prepared = new PreparedAppUpdate(updateArtifact, "unused.zip", stagedBundle, Sha256(File.ReadAllBytes(
            Path.Combine(stagedBundle, "Contents", "MacOS", "LeikwanBridge.Mac"))));

        var result = await service.ApplyAsync(prepared, deferUntilRestart: false);

        Assert.True(result.RequiresManualInstall);
        Assert.Single(launcher.OpenedFiles);
        Assert.EndsWith(".dmg", launcher.OpenedFiles[0], StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public async Task ApplyAsync_CanDeferWithoutLaunchingHelper()
    {
        using var temp = new TempDirectory();
        using var http = BuildHttp(_ => BytesResponse([]));
        var launcher = new FakeUpdateLauncher();
        using var service = CreateService(temp, "arm64", http, launcher, _ => true);
        var stagedBundle = CreateBundle(temp.Path, "com.leikwan.bridge", "2.0.0", "arm64", "Prepared.app");
        var prepared = new PreparedAppUpdate(
            ArtifactFor(Encoding.UTF8.GetBytes("zip")),
            "package.zip",
            stagedBundle,
            Sha256(File.ReadAllBytes(Path.Combine(stagedBundle, "Contents", "MacOS", "LeikwanBridge.Mac"))));

        var result = await service.ApplyAsync(prepared, deferUntilRestart: true);

        Assert.True(result.Deferred);
        Assert.Empty(launcher.StartedProcesses);
        Assert.True(File.Exists(Path.Combine(temp.Path, "updates", "pending-update.json")));
    }

    [Fact]
    public async Task ApplyAsync_LaunchesOnlyStagedExecutableWithScrubbedRequest()
    {
        using var temp = new TempDirectory();
        using var http = BuildHttp(_ => BytesResponse([]));
        var launcher = new FakeUpdateLauncher();
        using var service = CreateService(temp, "arm64", http, launcher, _ => true);
        var stagedBundle = CreateBundle(temp.Path, "com.leikwan.bridge", "2.0.0", "arm64", "Immediate.app");
        var prepared = new PreparedAppUpdate(
            ArtifactFor(Encoding.UTF8.GetBytes("zip")),
            "package.zip",
            stagedBundle,
            Sha256(File.ReadAllBytes(Path.Combine(stagedBundle, "Contents", "MacOS", "LeikwanBridge.Mac"))));

        var result = await service.ApplyAsync(prepared, deferUntilRestart: false);

        Assert.True(result.Started);
        var launch = Assert.Single(launcher.StartedProcesses);
        Assert.EndsWith("/Contents/MacOS/LeikwanBridge.Mac", launch.Executable.Replace('\\', '/'));
        Assert.Equal("--apply-update", launch.Arguments[0]);
        var requestJson = File.ReadAllText(launch.Arguments[1]);
        Assert.DoesNotContain("credential", requestJson, StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain("password", requestJson, StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain("provider", requestJson, StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain("target", requestJson, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public async Task ApplyHelper_RestoresCurrentBundleWhenRelaunchFails()
    {
        using var temp = new TempDirectory();
        var current = CreateBundle(temp.Path, "com.leikwan.bridge", "1.0.0", "arm64", "LeiKwan Bridge.app");
        var staged = CreateBundle(temp.Path, "com.leikwan.bridge", "2.0.0", "arm64", ".LeiKwan Bridge.update.app");
        File.WriteAllText(Path.Combine(current, "old-marker"), "old");
        File.WriteAllText(Path.Combine(staged, "new-marker"), "new");
        var requestPath = Path.Combine(temp.Path, "apply.json");
        MacAppUpdateApplier.WriteRequest(requestPath, new MacUpdateApplyRequest
        {
            CurrentProcessId = 0,
            CurrentBundlePath = current,
            StagedBundlePath = staged,
            BackupBundlePath = Path.Combine(temp.Path, ".LeiKwan Bridge.backup.app"),
            Version = "2.0.0",
            PackageSizeBytes = 123,
            PackageSha256 = new string('A', 64),
            MainExecutableSha256 = Sha256(File.ReadAllBytes(Path.Combine(staged, "Contents", "MacOS", "LeikwanBridge.Mac"))),
        });
        var launcher = new FakeUpdateLauncher { OpenApplicationResult = false };

        var result = await MacAppUpdateApplier.ApplyFromRequestAsync(requestPath, launcher);

        Assert.False(result);
        Assert.True(File.Exists(Path.Combine(current, "old-marker")));
        Assert.False(File.Exists(Path.Combine(current, "new-marker")));
    }

    [Fact]
    public async Task CheckAsync_RebindsKnownEndpointArtifactUrlsToSelectedPanel()
    {
        using var temp = new TempDirectory();
        var requested = new List<Uri>();
        var panelUrl = PanelEndpoints.AliyunCdnUrl;
        var manifest = $$"""
        {
          "component": "desktop-client-app",
          "manifest": {
            "macos_client_update_arm64": { "version": "2.0.0", "artifact_url": "{{PanelEndpoints.CloudflareUrl}}/artifacts/macos/update-arm64.zip", "sha256": "{{new string('A', 64)}}", "size_bytes": 12, "architecture": "arm64", "format": "zip" },
            "macos_client_app_arm64": { "version": "2.0.0", "artifact_url": "{{PanelEndpoints.CloudflareUrl}}/artifacts/macos/leikwan-bridge-arm64.dmg", "sha256": "{{new string('A', 64)}}", "size_bytes": 13, "architecture": "arm64", "format": "dmg" }
          }
        }
        """;
        using var http = BuildHttp(request =>
        {
            requested.Add(request.RequestUri!);
            return JsonResponse(manifest);
        });
        using var service = CreateService(temp, "arm64", http);

        var artifact = await service.CheckAsync(panelUrl, "1.0.0");

        Assert.NotNull(artifact);
        Assert.StartsWith(panelUrl + "/", artifact.ArtifactUrl, StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain(PanelEndpoints.CloudflareUrl, artifact.ArtifactUrl, StringComparison.OrdinalIgnoreCase);
        Assert.NotNull(artifact.ManualInstaller);
        Assert.StartsWith(panelUrl + "/", artifact.ManualInstaller!.ArtifactUrl, StringComparison.OrdinalIgnoreCase);
        Assert.Equal(new Uri(panelUrl + "/artifacts/client-manifest.json"), Assert.Single(requested));
    }

    [Fact]
    public async Task CheckAsync_ReportsCheckPhaseProgress()
    {
        using var temp = new TempDirectory();
        using var http = BuildHttp(_ => JsonResponse(ManifestJson()));
        using var service = CreateService(temp, "arm64", http);
        var updates = new List<UpdateProgress>();

        await service.CheckAsync(
            "https://panel.example.test",
            "1.0.0",
            new Progress<UpdateProgress>(updates.Add));

        Assert.NotEmpty(updates);
        Assert.All(updates, update => Assert.Equal(UpdatePhase.Check, update.Phase));
    }

    [Fact]
    public async Task PrepareAsync_ReportsDownloadVerifyAndStagePhases()
    {
        using var source = new TempDirectory();
        using var temp = new TempDirectory();
        var bundle = CreateBundle(source.Path, "com.leikwan.bridge", "2.0.0", "arm64");
        var package = ZipDirectory(bundle);
        using var http = BuildHttp(_ => BytesResponse(package));
        using var service = CreateService(temp, "arm64", http);
        var updates = new List<UpdateProgress>();

        var prepared = await service.PrepareAsync(
            ArtifactFor(package),
            new Progress<UpdateProgress>(updates.Add));

        Assert.True(Directory.Exists(prepared.StagedBundlePath));
        Assert.Contains(updates, update => update.Phase == UpdatePhase.Download);
        Assert.Contains(updates, update => update.Phase == UpdatePhase.Download && update.Percent == 100);
        Assert.Contains(updates, update => update.Phase == UpdatePhase.Verify && update.Percent == 100);
        Assert.Contains(updates, update => update.Phase == UpdatePhase.Stage && update.Percent == 100);
    }

    [Fact]
    public async Task ApplyAsync_ReportsApplyPhaseProgress()
    {
        using var temp = new TempDirectory();
        using var http = BuildHttp(_ => BytesResponse([]));
        var launcher = new FakeUpdateLauncher();
        using var service = CreateService(temp, "arm64", http, launcher, _ => true);
        var stagedBundle = CreateBundle(temp.Path, "com.leikwan.bridge", "2.0.0", "arm64", "ApplyProgress.app");
        var prepared = new PreparedAppUpdate(
            ArtifactFor(Encoding.UTF8.GetBytes("zip")),
            "package.zip",
            stagedBundle,
            Sha256(File.ReadAllBytes(Path.Combine(stagedBundle, "Contents", "MacOS", "LeikwanBridge.Mac"))));
        var updates = new List<UpdateProgress>();

        var result = await service.ApplyAsync(
            prepared,
            deferUntilRestart: false,
            new Progress<UpdateProgress>(updates.Add));

        Assert.True(result.Started);
        Assert.Contains(updates, update => update.Phase == UpdatePhase.Apply);
        Assert.Contains(updates, update => update.Phase == UpdatePhase.Apply && update.Percent == 100);
    }

    [Fact]
    public async Task ApplyAsync_LaunchesHelperFromAStableCopyOutsideTheStagedBundle()
    {
        using var temp = new TempDirectory();
        using var http = BuildHttp(_ => BytesResponse([]));
        var launcher = new FakeUpdateLauncher();
        using var service = CreateService(temp, "arm64", http, launcher, _ => true);
        var stagedBundle = CreateBundle(temp.Path, "com.leikwan.bridge", "2.0.0", "arm64", "Prepared.app");
        var prepared = new PreparedAppUpdate(
            ArtifactFor(Encoding.UTF8.GetBytes("zip")),
            "package.zip",
            stagedBundle,
            Sha256(File.ReadAllBytes(Path.Combine(stagedBundle, "Contents", "MacOS", "LeikwanBridge.Mac"))));

        var result = await service.ApplyAsync(prepared, deferUntilRestart: false);

        Assert.True(result.Started);
        var launch = Assert.Single(launcher.StartedProcesses);
        var executable = launch.Executable.Replace('\\', '/');
        var updatesRoot = Path.Combine(temp.Path, "updates").Replace('\\', '/') + "/";
        // The helper resolves its managed payload relative to its own bundle, so the
        // swap must never rename the bundle the helper runs from: doing that made the
        // post-swap verification throw and silently rolled the update back while the
        // caller still reported "applied" (field-observed 2026-09-14, reproduced 3x).
        Assert.StartsWith(updatesRoot, executable, StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain("Prepared.app", executable, StringComparison.OrdinalIgnoreCase);
        Assert.DoesNotContain(".update-", executable, StringComparison.OrdinalIgnoreCase);
        var requestJson = File.ReadAllText(launch.Arguments[1]);
        Assert.Contains("applier_bundle_path", requestJson, StringComparison.Ordinal);
    }

    [Fact]
    public async Task ApplyHelper_PublishesTheRollbackReasonInsteadOfSwallowingIt()
    {
        using var temp = new TempDirectory();
        var current = CreateBundle(temp.Path, "com.leikwan.bridge", "1.0.0", "arm64", "LeiKwan Bridge.app");
        var staged = CreateBundle(temp.Path, "com.leikwan.bridge", "2.0.0", "arm64", ".LeiKwan Bridge.update.app");
        File.WriteAllText(Path.Combine(current, "old-marker"), "old");
        File.WriteAllText(Path.Combine(staged, "new-marker"), "new");
        var requestPath = Path.Combine(temp.Path, "apply.json");
        MacAppUpdateApplier.WriteRequest(requestPath, new MacUpdateApplyRequest
        {
            CurrentProcessId = 0,
            CurrentBundlePath = current,
            StagedBundlePath = staged,
            BackupBundlePath = Path.Combine(temp.Path, ".LeiKwan Bridge.backup.app"),
            Version = "2.0.0",
            PackageSizeBytes = 123,
            PackageSha256 = new string('A', 64),
            MainExecutableSha256 = Sha256(File.ReadAllBytes(Path.Combine(staged, "Contents", "MacOS", "LeikwanBridge.Mac"))),
        });
        var launcher = new FakeUpdateLauncher { OpenApplicationResult = false };

        var outcome = await MacAppUpdateApplier.ApplyFromRequestOutcomeAsync(requestPath, launcher);

        Assert.False(outcome.Success);
        Assert.Equal("update_relaunch_failed", outcome.Code);
        var log = File.ReadAllText(Path.Combine(temp.Path, MacAppUpdateApplier.ApplyLogFileName));
        Assert.Contains("rollback reason=IOException: update_relaunch_failed", log, StringComparison.Ordinal);
        Assert.Contains("rollback complete", log, StringComparison.Ordinal);
        var published = MacAppUpdateApplier.ReadLastResult(temp.Path);
        Assert.NotNull(published);
        Assert.False(published!.Success);
        Assert.Equal("update_relaunch_failed", published.Code);
    }

    [Fact]
    public async Task ApplyHelper_PublishesSuccessAfterTheSwap()
    {
        using var temp = new TempDirectory();
        var current = CreateBundle(temp.Path, "com.leikwan.bridge", "1.0.0", "arm64", "LeiKwan Bridge.app");
        var staged = CreateBundle(temp.Path, "com.leikwan.bridge", "2.0.0", "arm64", ".LeiKwan Bridge.update.app");
        var requestPath = Path.Combine(temp.Path, "apply.json");
        MacAppUpdateApplier.WriteRequest(requestPath, new MacUpdateApplyRequest
        {
            CurrentProcessId = 0,
            CurrentBundlePath = current,
            StagedBundlePath = staged,
            BackupBundlePath = Path.Combine(temp.Path, ".LeiKwan Bridge.backup.app"),
            Version = "2.0.0",
            PackageSizeBytes = 123,
            PackageSha256 = new string('A', 64),
            MainExecutableSha256 = Sha256(File.ReadAllBytes(Path.Combine(staged, "Contents", "MacOS", "LeikwanBridge.Mac"))),
        });

        var outcome = await MacAppUpdateApplier.ApplyFromRequestOutcomeAsync(requestPath, new FakeUpdateLauncher());

        Assert.True(outcome.Success);
        Assert.Equal("ok", outcome.Code);
        Assert.Equal("2.0.0", MacBundleValidator.ReadBundleVersion(current));
        var published = MacAppUpdateApplier.ReadLastResult(temp.Path);
        Assert.NotNull(published);
        Assert.True(published!.Success);
    }

    [Fact]
    public async Task ClientUpdateService_FailsWhenTheInstalledVersionDoesNotChange()
    {
        using var source = new TempDirectory();
        using var temp = new TempDirectory();
        var packageBundle = CreateBundle(source.Path, "com.leikwan.bridge", "2.0.0", "arm64", "LeiKwan Bridge.app");
        var package = ZipDirectory(packageBundle);
        // The published entry must describe the real package: the resumable downloader
        // refuses a body whose length differs from the manifest.
        var manifest = $$"""
        {
          "component": "desktop-client-app",
          "manifest": {
            "macos_client_update_arm64": { "version": "2.0.0", "artifact_url": "https://cdn.example.test/update-arm64.zip", "sha256": "{{Sha256(package).ToLowerInvariant()}}", "size_bytes": {{package.Length}}, "architecture": "arm64", "format": "zip" },
            "macos_client_app_arm64": { "version": "2.0.0", "artifact_url": "https://cdn.example.test/client-arm64.dmg", "sha256": "{{new string('A', 64)}}", "size_bytes": 13, "architecture": "arm64", "format": "dmg" }
          }
        }
        """;
        using var http = BuildHttp(request => request.RequestUri!.AbsolutePath.Contains("client-manifest", StringComparison.Ordinal)
            ? JsonResponse(manifest)
            : BytesResponse(package));
        var launcher = new FakeUpdateLauncher();
        var current = CreateBundle(temp.Path, "com.leikwan.bridge", "1.0.0", "arm64", "LeiKwan Bridge.app");
        using var updater = new MacAppUpdateService(
            Path.Combine(temp.Path, "updates"),
            current,
            "arm64",
            _ => http,
            ownsHttpClient: false,
            launcher: launcher,
            directoryWritable: _ => true,
            waitForCallerExit: false);
        var controller = new MacServiceController(new MacPlatformPaths(temp.Path, current));
        var client = new MacClientUpdateService(
            updater,
            controller,
            "https://panel.example.test",
            TimeSpan.FromMilliseconds(600));
        var output = new StringWriter();

        var check = await client.CheckAsync(CancellationToken.None);
        Assert.True(check.UpdateAvailable);
        var applied = await client.ApplyAsync(check, controller, output, CancellationToken.None);

        Assert.False(applied);
        Assert.Contains("still 1.0.0", output.ToString(), StringComparison.Ordinal);
    }

    private static MacAppUpdateService CreateService(
        TempDirectory temp,
        string architecture,
        HttpClient http,
        IMacUpdateLauncher? launcher = null,
        Func<string, bool>? writable = null)
    {
        var current = CreateBundle(temp.Path, "com.leikwan.bridge", "1.0.0", "arm64", "LeiKwan Bridge.app");
        return new MacAppUpdateService(
            Path.Combine(temp.Path, "updates"),
            current,
            architecture,
            _ => http,
            ownsHttpClient: false,
            launcher: launcher,
            directoryWritable: writable);
    }

    private static ClientAppArtifact ArtifactFor(byte[] package, long? size = null)
    {
        return new ClientAppArtifact
        {
            Version = "2.0.0",
            ArtifactUrl = "https://cdn.example.test/client-arm64.zip",
            Sha256 = Sha256(package),
            SizeBytes = size ?? package.Length,
            Architecture = "arm64",
            Format = "zip",
        };
    }

    private static string ManifestJson(
        string version = "2.0.0",
        string? runtimeEntries = null,
        string? packageRuntimeSha256 = null,
        bool includeLegacyIntelEntries = false)
    {
        var sha = new string('A', 64);
        var legacyIntel = includeLegacyIntelEntries
            ? $", \"macos_client_update_x64\": {{ \"version\": \"{version}\", \"artifact_url\": \"https://cdn.example.test/update-x64.zip\", \"sha256\": \"{sha}\", \"size_bytes\": 14, \"architecture\": \"x86_64\", \"format\": \"zip\" }}"
              + $", \"macos_client_app_x64\": {{ \"version\": \"{version}\", \"artifact_url\": \"https://cdn.example.test/client-x64.dmg\", \"sha256\": \"{sha}\", \"size_bytes\": 15, \"architecture\": \"x86_64\", \"format\": \"dmg\" }}"
            : string.Empty;
        var runtime = string.IsNullOrWhiteSpace(runtimeEntries) ? string.Empty : $", {runtimeEntries}";
        var packageRuntime = string.IsNullOrWhiteSpace(packageRuntimeSha256)
            ? string.Empty
            : $", \"runtime_sha256\": {{ {packageRuntimeSha256} }}";
        return $$"""
        {
          "component": "desktop-client-app",
          "manifest": {
            "macos_client_update_arm64": { "version": "{{version}}", "artifact_url": "https://cdn.example.test/update-arm64.zip", "sha256": "{{sha}}", "size_bytes": 12, "architecture": "arm64", "format": "zip"{{packageRuntime}} },
            "macos_client_app_arm64": { "version": "{{version}}", "artifact_url": "https://cdn.example.test/client-arm64.dmg", "sha256": "{{sha}}", "size_bytes": 13, "architecture": "arm64", "format": "dmg" }{{legacyIntel}}{{runtime}}
          }
        }
        """;
    }

    private static string CreateBundle(
        string root,
        string bundleId,
        string version,
        string architecture,
        string name = "Fixture.app")
    {
        var bundle = Path.Combine(root, name);
        var macOs = Path.Combine(bundle, "Contents", "MacOS");
        var helpers = Path.Combine(bundle, "Contents", "Resources", "bin");
        Directory.CreateDirectory(macOs);
        Directory.CreateDirectory(helpers);
        File.WriteAllText(Path.Combine(bundle, "Contents", "Info.plist"), $$"""
        <?xml version="1.0" encoding="UTF-8"?>
        <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
        <plist version="1.0"><dict>
          <key>CFBundleIdentifier</key><string>{{bundleId}}</string>
          <key>CFBundleShortVersionString</key><string>{{version}}</string>
          <key>CFBundleExecutable</key><string>LeikwanBridge.Mac</string>
        </dict></plist>
        """);
        var executable = ThinMachO(architecture);
        File.WriteAllBytes(Path.Combine(macOs, "LeikwanBridge.Mac"), executable);
        var helperPath = Path.Combine(helpers, "ultra-client");
        File.WriteAllBytes(helperPath, executable);
        var wstunnelPath = Path.Combine(helpers, "wstunnel");
        File.WriteAllBytes(wstunnelPath, executable);
        File.WriteAllText(Path.Combine(bundle, "Contents", "Resources", "helper-manifest.json"), $$"""
        {
          "architecture": "{{architecture}}",
          "helpers": [
            { "name": "ultra-client", "path": "bin/ultra-client", "sha256": "{{Sha256(executable)}}", "size_bytes": {{executable.Length}} },
            { "name": "wstunnel", "path": "bin/wstunnel", "sha256": "{{Sha256(executable)}}", "size_bytes": {{executable.Length}} }
          ]
        }
        """);
        return bundle;
    }

    private static byte[] ThinMachO(string architecture)
    {
        var bytes = new byte[32];
        bytes[0] = 0xCF;
        bytes[1] = 0xFA;
        bytes[2] = 0xED;
        bytes[3] = 0xFE;
        BinaryPrimitives.WriteInt32LittleEndian(
            bytes.AsSpan(4),
            architecture == "arm64" ? 0x0100000C : 0x01000007);
        return bytes;
    }

    private static byte[] ZipDirectory(string bundle)
    {
        return BuildZip(archive =>
        {
            var parent = Directory.GetParent(bundle)!.FullName;
            foreach (var file in Directory.EnumerateFiles(bundle, "*", SearchOption.AllDirectories))
            {
                var entryName = Path.GetRelativePath(parent, file).Replace('\\', '/');
                var entry = archive.CreateEntry(entryName, CompressionLevel.NoCompression);
                using var source = File.OpenRead(file);
                using var destination = entry.Open();
                source.CopyTo(destination);
            }
        });
    }

    private static byte[] BuildZip(Action<ZipArchive> build)
    {
        using var stream = new MemoryStream();
        using (var archive = new ZipArchive(stream, ZipArchiveMode.Create, leaveOpen: true))
            build(archive);
        return stream.ToArray();
    }

    private static HttpClient BuildHttp(Func<HttpRequestMessage, HttpResponseMessage> response)
        => new(new StubHandler(response)) { Timeout = TimeSpan.FromSeconds(5) };

    private static HttpResponseMessage JsonResponse(string json)
        => new(HttpStatusCode.OK) { Content = new StringContent(json, Encoding.UTF8, "application/json") };

    private static HttpResponseMessage BytesResponse(byte[] bytes)
        => new(HttpStatusCode.OK) { Content = new ByteArrayContent(bytes) };

    private static string Sha256(byte[] bytes) => Convert.ToHexString(SHA256.HashData(bytes));

    private sealed class StubHandler(Func<HttpRequestMessage, HttpResponseMessage> response) : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            var result = response(request);
            result.RequestMessage = request;
            return Task.FromResult(result);
        }
    }

    private sealed class FakeUpdateLauncher : IMacUpdateLauncher
    {
        public bool OpenApplicationResult { get; set; } = true;
        public List<(string Executable, IReadOnlyList<string> Arguments)> StartedProcesses { get; } = [];
        public List<string> OpenedFiles { get; } = [];

        public bool StartProcess(string executable, IReadOnlyList<string> arguments)
        {
            StartedProcesses.Add((executable, arguments));
            return true;
        }

        public bool OpenApplication(string bundlePath) => OpenApplicationResult;

        public bool OpenFile(string path)
        {
            OpenedFiles.Add(path);
            return true;
        }
    }

    private sealed class TempDirectory : IDisposable
    {
        public TempDirectory()
        {
            Path = System.IO.Path.Combine(System.IO.Path.GetTempPath(), "leikwan-mac-update-tests", Guid.NewGuid().ToString("N"));
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
