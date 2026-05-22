using System.Security.Cryptography;
using System.Text.Json;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services;

public sealed class WindowsAppUpdateService
{
    private readonly HttpClient _http = new() { Timeout = TimeSpan.FromSeconds(30) };

    public static bool IsRemoteVersionNewer(string remoteVersion, string localVersion)
    {
        if (!TryParseComparableVersion(remoteVersion, out var remote))
            return false;
        if (!TryParseComparableVersion(localVersion, out var local))
            return false;
        return remote > local;
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

    public async Task<WindowsClientAppArtifact> FetchWindowsClientArtifactAsync(string masterUrl, CancellationToken ct = default)
    {
        var url = masterUrl.TrimEnd('/') + "/artifacts/client-manifest.json";
        var json = await _http.GetStringAsync(url, ct);
        var response = JsonSerializer.Deserialize<WindowsClientArtifactManifestResponse>(json)
            ?? throw new InvalidOperationException("null client manifest response");
        return response.Manifest.WindowsClientApp;
    }

    public async Task DownloadInstallerAsync(string artifactUrl, string destinationPath, CancellationToken ct = default)
    {
        var dir = Path.GetDirectoryName(destinationPath);
        if (!string.IsNullOrWhiteSpace(dir))
            Directory.CreateDirectory(dir);

        using var response = await _http.GetAsync(artifactUrl, ct);
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
}
