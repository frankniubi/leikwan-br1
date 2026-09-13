using System.Security.Cryptography;
using System.Text.Json;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services;

public sealed class WindowsAppUpdateService : IDisposable
{
    private readonly Func<LeikwanHttpClientKind, HttpClient> _httpClientFactory;
    private readonly bool _ownsHttpClient;
    private readonly Action<HttpRequestMessage>? _onRequestForTest;
    private readonly Dictionary<LeikwanHttpClientKind, HttpClient> _clients = [];

    public WindowsAppUpdateService(
        Func<LeikwanHttpClientKind, HttpClient>? httpClientFactory = null,
        bool ownsHttpClient = true,
        Action<HttpRequestMessage>? onRequestForTest = null)
    {
        _httpClientFactory = httpClientFactory ?? LeikwanHttpClientFactory.Create;
        _ownsHttpClient = ownsHttpClient;
        _onRequestForTest = onRequestForTest;
    }

    public static bool IsRemoteVersionNewer(string remoteVersion, string localVersion)
    {
        if (!TryParseComparableVersion(remoteVersion, out var remote))
            return false;
        if (!TryParseComparableVersion(localVersion, out var local))
            return false;
        return remote > local;
    }

    public static bool HasRuntimeDrift(AgentConfig config, WindowsClientAppArtifact artifact)
    {
        if (artifact.RuntimeSha256.Count == 0)
            return false;
        var paths = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase)
        {
            ["ultra_client_runtime_windows"] = config.GetEffectiveUltraBin(),
            ["leikwan_bond_windows_amd64"] = config.GetEffectiveBondBin(),
            ["leikwan_ingress_windows_amd64"] = config.GetEffectiveBondIngressBin(),
            ["leikwan_uot_bond_windows_amd64"] = config.GetEffectiveUotBondBin(),
            ["leikwan_mptcp_relay_windows_amd64"] = config.GetEffectiveMptcpRelayBin(),
            ["wstunnel_windows_amd64"] = config.GetEffectiveWstunnelBin(),
        };
        foreach (var (key, expected) in artifact.RuntimeSha256)
        {
            if (!paths.TryGetValue(key, out var path) || string.IsNullOrWhiteSpace(expected))
                continue;
            if (!File.Exists(path) || !string.Equals(ComputeSha256(path), expected, StringComparison.OrdinalIgnoreCase))
                return true;
        }
        return false;
    }

    public static bool ShouldCheckForUpdatesOnStartup(AgentConfig config)
    {
        return !config.DisableAutoUpdates
            && !string.IsNullOrWhiteSpace(config.MasterUrl);
    }

    private static bool TryParseComparableVersion(string version, out Version parsed)
    {
        parsed = new Version(0, 0, 0, 0);
        var normalized = (version ?? string.Empty).Trim();
        if (normalized.Length == 0)
            return false;

        var metadataIndex = normalized.IndexOfAny(['+', '-']);
        if (metadataIndex >= 0)
            normalized = normalized[..metadataIndex];

        if (!Version.TryParse(normalized, out var value))
            return false;

        parsed = new Version(
            value.Major,
            value.Minor,
            value.Build >= 0 ? value.Build : 0,
            value.Revision >= 0 ? value.Revision : 0);
        return true;
    }

    public static string BuildCachedInstallerPath(string installRoot, string version)
    {
        return Path.Combine(installRoot, "updates", $"leikwan-bridge-setup-{version}.exe");
    }

    /// <summary>
    /// Keeps the installer cache down to the builds the client can still act
    /// on. Every release drops a ~70 MB setup in this folder and nothing ever
    /// removed them, so the cache grew without bound on every machine.
    /// Never throws: failing to prune must not affect an update.
    /// </summary>
    public static IReadOnlyList<string> PruneCachedInstallers(
        string installRoot,
        string currentVersion,
        string? pendingInstallerPath = null)
    {
        var removed = new List<string>();
        try
        {
            var updatesDir = Path.Combine(installRoot, "updates");
            if (!Directory.Exists(updatesDir))
                return removed;

            var keep = new HashSet<string>(StringComparer.OrdinalIgnoreCase)
            {
                Path.GetFullPath(BuildCachedInstallerPath(installRoot, currentVersion)),
            };
            if (!string.IsNullOrWhiteSpace(pendingInstallerPath))
            {
                try
                {
                    keep.Add(Path.GetFullPath(pendingInstallerPath.Trim()));
                }
                catch (Exception)
                {
                    // A malformed pending path simply is not protected.
                }
            }

            foreach (var file in Directory.EnumerateFiles(updatesDir, "leikwan-bridge-setup-*.exe"))
            {
                string fullPath;
                try
                {
                    fullPath = Path.GetFullPath(file);
                }
                catch (Exception)
                {
                    continue;
                }

                if (keep.Contains(fullPath))
                    continue;

                TryDeleteFile(fullPath);
                if (!File.Exists(fullPath))
                    removed.Add(fullPath);
            }
        }
        catch (Exception)
        {
            // Best effort only.
        }

        return removed;
    }

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

        var normalizedCurrentInstallRoot = NormalizeInstallRoot(currentInstallRoot);
        var normalizedStateInstallRoot = NormalizeInstallRoot(state.InstallDir);
        if (!string.Equals(normalizedCurrentInstallRoot, normalizedStateInstallRoot, StringComparison.OrdinalIgnoreCase))
            throw new InvalidOperationException("install-state target mismatch for silent update");

        var validatedInstallMode = ValidateInstallMode(state.InstallMode);
        var validatedDesktopShortcut = ValidateDesktopShortcut(state.DesktopShortcut);

        return
        [
            "/VERYSILENT",
            "/NORESTART",
            validatedInstallMode == "all-users" ? "/ALLUSERS" : "/CURRENTUSER",
            "/LEIKWANUPDATE=1",
            $"/LEIKWANINSTALLDIR={normalizedCurrentInstallRoot}",
            $"/LEIKWANINSTALLMODE={validatedInstallMode}",
            $"/LEIKWANDESKTOPSHORTCUT={(validatedDesktopShortcut ? 1 : 0)}",
        ];
    }

    public static string NormalizeInstallRoot(string path)
    {
        if (string.IsNullOrWhiteSpace(path))
            throw new InvalidOperationException("install-state install_dir is missing or empty");

        var fullPath = Path.GetFullPath(path.Trim());
        var rootPath = Path.GetPathRoot(fullPath) ?? string.Empty;
        if (string.Equals(fullPath, rootPath, StringComparison.OrdinalIgnoreCase))
            return fullPath;

        return fullPath.TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar);
    }

    private static string ValidateInstallMode(string installMode)
    {
        var normalized = (installMode ?? string.Empty).Trim();
        if (string.Equals(normalized, "all-users", StringComparison.OrdinalIgnoreCase))
            return "all-users";
        if (string.Equals(normalized, "just-for-me", StringComparison.OrdinalIgnoreCase))
            return "just-for-me";

        throw new InvalidOperationException("install-state install_mode is missing or invalid");
    }

    private static bool ValidateDesktopShortcut(bool? desktopShortcut)
    {
        if (!desktopShortcut.HasValue)
            throw new InvalidOperationException("install-state desktop_shortcut is missing");

        return desktopShortcut.Value;
    }

    public static bool HasPendingUpdate(AgentConfig config)
    {
        return !string.IsNullOrWhiteSpace(config.PendingUpdateVersion)
            && !string.IsNullOrWhiteSpace(config.PendingUpdateInstallerPath);
    }

    public static string ResolveArtifactUrl(string masterUrl, string artifactUrl)
    {
        // Rebases relative URLs onto the selected endpoint and redirects
        // absolute URLs that point at another known panel endpoint so every
        // artifact download follows the user's chosen mirror.
        return PanelEndpoints.RebaseArtifactUrl(masterUrl, artifactUrl);
    }

    public static string BuildArtifactDownloadUrl(string artifactUrl, string version, string sha256)
    {
        if (string.IsNullOrWhiteSpace(artifactUrl))
            return string.Empty;

        if (!Uri.TryCreate(artifactUrl, UriKind.Absolute, out var absolute))
            throw new InvalidOperationException("artifact URL must be absolute");

        var builder = new UriBuilder(absolute);
        var query = builder.Query.TrimStart('?');
        var encodedVersion = Uri.EscapeDataString((version ?? string.Empty).Trim());
        var encodedSha256 = Uri.EscapeDataString((sha256 ?? string.Empty).Trim());
        if (HasQueryParameter(query, "v", encodedVersion)
            && HasQueryParameter(query, "sha256", encodedSha256))
            return absolute.ToString();

        if (!string.IsNullOrEmpty(query))
            query += "&";
        query += "v=" + encodedVersion;
        query += "&sha256=" + encodedSha256;
        builder.Query = query;
        return builder.Uri.ToString();
    }

    private static bool HasQueryParameter(string query, string key, string expectedValue)
    {
        foreach (var item in query.Split('&', StringSplitOptions.RemoveEmptyEntries))
        {
            var separator = item.IndexOf('=');
            if (separator < 0)
                continue;

            var actualKey = Uri.UnescapeDataString(item[..separator]);
            var actualValue = Uri.UnescapeDataString(item[(separator + 1)..]);
            if (string.Equals(actualKey, key, StringComparison.OrdinalIgnoreCase)
                && string.Equals(actualValue, expectedValue, StringComparison.Ordinal))
                return true;
        }

        return false;
    }

    public Task<WindowsClientAppArtifact> FetchWindowsClientArtifactAsync(string masterUrl, CancellationToken ct = default)
        => FetchWindowsClientArtifactAsync(masterUrl, progress: null, ct);

    public async Task<WindowsClientAppArtifact> FetchWindowsClientArtifactAsync(
        string masterUrl,
        IProgress<UpdateProgress>? progress,
        CancellationToken ct = default)
    {
        Report(progress, UpdateProgress.Checking("Checking for updates..."));
        var uri = new Uri(masterUrl.TrimEnd('/') + "/artifacts/client-manifest.json");
        using var request = new HttpRequestMessage(HttpMethod.Get, uri);
        using var responseMessage = await SendAsync(request, ct);
        responseMessage.EnsureSuccessStatusCode();
        var json = await responseMessage.Content.ReadAsStringAsync(ct);
        var response = JsonSerializer.Deserialize<WindowsClientArtifactManifestResponse>(json)
            ?? throw new InvalidOperationException("null client manifest response");
        var artifact = response.Manifest.WindowsClientApp;
        foreach (var (key, component) in response.Manifest.Components)
        {
            if (!key.Contains("runtime", StringComparison.OrdinalIgnoreCase)
                || component.ValueKind != JsonValueKind.Object)
                continue;
            if (component.TryGetProperty("sha256", out var sha)
                && sha.ValueKind == JsonValueKind.String
                && !string.IsNullOrWhiteSpace(sha.GetString()))
                artifact.RuntimeSha256[key] = sha.GetString()!;
        }
        Report(progress, UpdateProgress.Checking("Update check finished."));
        return artifact;
    }

    public Task DownloadInstallerAsync(string artifactUrl, string destinationPath, CancellationToken ct = default)
        => DownloadInstallerAsync(artifactUrl, destinationPath, progress: null, expectedSha256: null, ct: ct);

    public Task DownloadInstallerAsync(
        string artifactUrl,
        string destinationPath,
        IProgress<UpdateProgress>? progress,
        CancellationToken ct = default)
        => DownloadInstallerAsync(artifactUrl, destinationPath, progress, expectedSha256: null, ct: ct);

    public async Task DownloadInstallerAsync(
        string artifactUrl,
        string destinationPath,
        IProgress<UpdateProgress>? progress,
        string? expectedSha256,
        long? expectedLength = null,
        CancellationToken ct = default)
    {
        var dir = Path.GetDirectoryName(destinationPath);
        if (!string.IsNullOrWhiteSpace(dir))
            Directory.CreateDirectory(dir);

        try
        {
            Report(progress, UpdateProgress.Downloading(0, "Downloading update..."));
            // Resumable: the shared panel client caps every request at 30 s
            // including the body, and this artifact is ~70 MB, so a single
            // request only ever completes on a >2.3 MB/s link. The downloader
            // keeps a .part file and continues from the last byte received.
            await ResumableArtifactDownloader.DownloadAsync(
                SendAsync,
                artifactUrl,
                destinationPath,
                progress: (received, total) => Report(progress, UpdateProgress.Downloading(
                    total is > 0
                        ? Math.Min(99.5, received * 100.0 / total.Value)
                        : 0,
                    $"Downloading update... ({received} bytes)")),
                expectedLength: expectedLength,
                ct: ct);
            Report(progress, UpdateProgress.Downloading(100, "Download finished."));

            if (string.IsNullOrWhiteSpace(expectedSha256))
                return;

            Report(progress, UpdateProgress.Verifying(0, "Verifying download..."));
            var actualSha256 = ComputeSha256(destinationPath);
            if (!string.Equals(actualSha256, expectedSha256.Trim(), StringComparison.OrdinalIgnoreCase))
                throw new InvalidDataException("downloaded installer hash mismatch");
            Report(progress, UpdateProgress.Verifying(100, "Download verified."));
        }
        catch
        {
            TryDeleteFile(destinationPath);
            throw;
        }
    }

    private static void Report(IProgress<UpdateProgress>? progress, UpdateProgress update)
    {
        try
        {
            progress?.Report(update);
        }
        catch (Exception)
        {
            // A broken UI progress sink must never fail the update pipeline.
        }
    }

    private static void TryDeleteFile(string path)
    {
        try
        {
            if (File.Exists(path))
                File.Delete(path);
        }
        catch (IOException)
        {
        }
        catch (UnauthorizedAccessException)
        {
        }
    }

    public static string ComputeSha256(string path)
    {
        using var stream = File.OpenRead(path);
        using var sha = SHA256.Create();
        var hash = sha.ComputeHash(stream);
        return Convert.ToHexString(hash);
    }

    private Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken ct)
    {
        _onRequestForTest?.Invoke(request);
        var kind = LeikwanHttpClientFactory.SelectKind(request.RequestUri ?? throw new InvalidOperationException("request URI is missing"));
        return GetClient(kind).SendAsync(request, ct);
    }

    private HttpClient GetClient(LeikwanHttpClientKind kind)
    {
        if (!_clients.TryGetValue(kind, out var client))
        {
            client = _httpClientFactory(kind);
            _clients[kind] = client;
        }
        return client;
    }

    public void Dispose()
    {
        if (!_ownsHttpClient)
            return;

        foreach (var client in _clients.Values)
            client.Dispose();
        _clients.Clear();
    }
}
