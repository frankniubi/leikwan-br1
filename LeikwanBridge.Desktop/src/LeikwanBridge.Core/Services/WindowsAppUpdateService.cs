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
        if (string.IsNullOrWhiteSpace(artifactUrl))
            return string.Empty;
        if (Uri.TryCreate(artifactUrl, UriKind.Absolute, out var absolute))
            return absolute.ToString();
        return masterUrl.TrimEnd('/') + "/" + artifactUrl.TrimStart('/');
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

    public async Task<WindowsClientAppArtifact> FetchWindowsClientArtifactAsync(string masterUrl, CancellationToken ct = default)
    {
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
        return artifact;
    }

    public async Task DownloadInstallerAsync(string artifactUrl, string destinationPath, CancellationToken ct = default)
    {
        var dir = Path.GetDirectoryName(destinationPath);
        if (!string.IsNullOrWhiteSpace(dir))
            Directory.CreateDirectory(dir);

        using var request = new HttpRequestMessage(HttpMethod.Get, artifactUrl);
        using var response = await SendAsync(request, ct);
        response.EnsureSuccessStatusCode();
        await using var stream = await response.Content.ReadAsStreamAsync(ct);
        await using var file = File.Create(destinationPath);
        await stream.CopyToAsync(file, ct);
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
