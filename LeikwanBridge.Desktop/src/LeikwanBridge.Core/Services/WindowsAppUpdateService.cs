using System.Security.Cryptography;
using System.Text.Json;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services;

public sealed class WindowsAppUpdateService
{
    private readonly HttpClient _http = new() { Timeout = TimeSpan.FromSeconds(30) };

    public static bool IsRemoteVersionNewer(string remoteVersion, string localVersion)
    {
        if (!Version.TryParse(remoteVersion, out var remote))
            return false;
        if (!Version.TryParse(localVersion, out var local))
            return false;
        return remote > local;
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
