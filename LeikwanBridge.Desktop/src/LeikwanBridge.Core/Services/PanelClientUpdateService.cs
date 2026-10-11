using System.Security.Cryptography;
using System.Text.Json;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Applies a staged client update on a platform's own terms (zip swap + service
/// restart on Windows, bundle replacement + launchd kickstart on macOS).
/// </summary>
public interface IClientUpdateApplier
{
    Task<bool> ApplyAsync(string stagedFile, string version, IClientServiceController service, TextWriter output, CancellationToken ct);
}

/// <summary>One published payload entry of the panel's artifacts/versions.json.</summary>
public sealed record PublishedClientUpdate(string Version, string ArtifactUrl, string Sha256, long SizeBytes, string FileName);

/// <summary>Raised when downloaded bytes do not match the manifest.</summary>
public sealed class ClientUpdateVerificationException(string message) : Exception(message);

/// <summary>Read the published entry and fetch it as a verified local file.</summary>
public interface IClientUpdateSource
{
    Task<PublishedClientUpdate?> ReadPublishedAsync(CancellationToken ct);

    /// <summary>Downloads (or reuses an already-verified copy of) the artifact
    /// and returns its local path. Throws <see cref="ClientUpdateVerificationException"/>
    /// when the size or sha256 does not match the manifest.</summary>
    Task<string> DownloadVerifiedAsync(PublishedClientUpdate update, CancellationToken ct);
}

/// <summary>
/// `leikwan update` against the panel manifest: read the published entry for
/// this payload, download it through the resumable/hash-checked downloader, and
/// hand the verified file to the platform applier. Nothing is applied until the
/// bytes match the manifest. The service-mode auto-update loop uses the same
/// manifest read and the same verified download through <see cref="IClientUpdateSource"/>.
/// </summary>
public sealed class PanelClientUpdateService : IClientUpdateService, IClientUpdateSource
{
    private readonly string _panelUrl;
    private readonly string _manifestKey;
    private readonly string _installedVersion;
    private readonly string _stagingDirectory;
    private readonly IClientUpdateApplier _applier;
    private readonly HttpClient _http;

    public PanelClientUpdateService(
        string panelUrl,
        string manifestKey,
        string installedVersion,
        string stagingDirectory,
        IClientUpdateApplier applier,
        HttpClient? http = null)
    {
        _panelUrl = panelUrl.TrimEnd('/');
        _manifestKey = manifestKey;
        _installedVersion = installedVersion;
        _stagingDirectory = stagingDirectory;
        _applier = applier;
        _http = http ?? CreateDefaultHttpClient(_panelUrl);
    }

    // The manifest and the artifact live on panel/update hosts, so the
    // fallback client must be the proxy-free, physical-NIC one as well; a
    // bare HttpClient would honour the system proxy and any TUN route.
    private static HttpClient CreateDefaultHttpClient(string panelUrl)
    {
        var http = Uri.TryCreate(panelUrl, UriKind.Absolute, out var panel)
            ? LeikwanHttpClientFactory.CreateFor(panel)
            : LeikwanHttpClientFactory.CreateDefault();
        http.Timeout = TimeSpan.FromMinutes(10);
        return http;
    }

    public async Task<ClientUpdateCheck> CheckAsync(CancellationToken ct)
    {
        var entry = await ReadPublishedAsync(ct).ConfigureAwait(false);
        var published = entry?.Version;
        var available = entry is not null
            && !string.IsNullOrWhiteSpace(entry.ArtifactUrl)
            && !string.IsNullOrWhiteSpace(entry.Sha256)
            && !string.Equals(published, _installedVersion, StringComparison.OrdinalIgnoreCase);
        return new ClientUpdateCheck(
            _installedVersion,
            published,
            available,
            entry?.ArtifactUrl ?? string.Empty);
    }

    public async Task<string> DescribeLatestAsync(CancellationToken ct)
    {
        try
        {
            var entry = await ReadPublishedAsync(ct).ConfigureAwait(false);
            return entry is null
                ? "manifest unavailable"
                : $"{entry.Version} ({entry.SizeBytes} bytes)";
        }
        catch (Exception exception) when (exception is HttpRequestException or TaskCanceledException or JsonException)
        {
            return "manifest unavailable: " + exception.GetType().Name;
        }
    }

    public async Task<bool> ApplyAsync(
        ClientUpdateCheck check,
        IClientServiceController service,
        TextWriter output,
        CancellationToken ct)
    {
        var entry = await ReadPublishedAsync(ct).ConfigureAwait(false);
        if (entry is null || string.IsNullOrWhiteSpace(entry.ArtifactUrl))
        {
            output.WriteLine("published artifact is missing from the panel manifest");
            return false;
        }

        output.WriteLine($"download  : {entry.ArtifactUrl}");
        string stagedFile;
        try
        {
            stagedFile = await DownloadVerifiedAsync(entry, ct).ConfigureAwait(false);
        }
        catch (ClientUpdateVerificationException exception)
        {
            output.WriteLine($"verify    : {exception.Message}");
            return false;
        }

        output.WriteLine($"verify    : {ShortHash(entry.Sha256)} ok");
        return await _applier.ApplyAsync(stagedFile, entry.Version, service, output, ct).ConfigureAwait(false);
    }

    public async Task<string> DownloadVerifiedAsync(PublishedClientUpdate update, CancellationToken ct)
    {
        if (string.IsNullOrWhiteSpace(update.ArtifactUrl) || string.IsNullOrWhiteSpace(update.Sha256))
            throw new ClientUpdateVerificationException("manifest entry has no artifact url or sha256");

        Directory.CreateDirectory(_stagingDirectory);
        var stagedFile = Path.Combine(_stagingDirectory, update.FileName);

        // A copy left by an earlier cycle that already matches is reused; one
        // that does not match is discarded so the resumable downloader can
        // never "resume" onto corrupt bytes.
        if (File.Exists(stagedFile))
        {
            if (await MatchesAsync(stagedFile, update, ct).ConfigureAwait(false))
                return stagedFile;
            File.Delete(stagedFile);
        }

        // The downloader resumes a cut connection and refuses a body that does
        // not match the manifest length, so a truncated publish cannot be
        // installed as an "update".
        await ResumableArtifactDownloader.DownloadAsync(
            (request, token) => _http.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, token),
            update.ArtifactUrl,
            stagedFile,
            expectedLength: update.SizeBytes > 0 ? update.SizeBytes : null,
            ct: ct).ConfigureAwait(false);

        var length = new FileInfo(stagedFile).Length;
        if (update.SizeBytes > 0 && length != update.SizeBytes)
        {
            TryDelete(stagedFile);
            throw new ClientUpdateVerificationException($"size mismatch ({length} != {update.SizeBytes})");
        }

        var digest = await ComputeSha256Async(stagedFile, ct).ConfigureAwait(false);
        if (!string.Equals(digest, update.Sha256, StringComparison.OrdinalIgnoreCase))
        {
            TryDelete(stagedFile);
            throw new ClientUpdateVerificationException($"hash mismatch ({ShortHash(digest)} != {ShortHash(update.Sha256)})");
        }

        return stagedFile;
    }

    public async Task<PublishedClientUpdate?> ReadPublishedAsync(CancellationToken ct)
    {
        var url = _panelUrl + "/artifacts/versions.json";
        var body = await _http.GetStringAsync(url, ct).ConfigureAwait(false);
        return ParseManifestEntry(body, _manifestKey, _panelUrl);
    }

    public static PublishedClientUpdate? ParseManifestEntry(string body, string manifestKey, string panelUrl)
    {
        panelUrl = panelUrl.TrimEnd('/');
        using var document = JsonDocument.Parse(body);
        if (!document.RootElement.TryGetProperty(manifestKey, out var entry)
            || entry.ValueKind != JsonValueKind.Object)
        {
            return null;
        }

        var artifactUrl = entry.TryGetProperty("artifact_url", out var urlValue) ? urlValue.GetString() ?? string.Empty : string.Empty;
        if (artifactUrl.StartsWith('/'))
            artifactUrl = panelUrl + artifactUrl;
        var sha = entry.TryGetProperty("sha256", out var shaValue) ? shaValue.GetString() ?? string.Empty : string.Empty;
        var version = entry.TryGetProperty("version", out var versionValue) ? versionValue.GetString() ?? string.Empty : string.Empty;
        var size = entry.TryGetProperty("size_bytes", out var sizeValue) && sizeValue.TryGetInt64(out var parsed) ? parsed : 0;
        var fileName = entry.TryGetProperty("path", out var pathValue)
            ? Path.GetFileName(pathValue.GetString() ?? string.Empty)
            : string.Empty;
        if (string.IsNullOrWhiteSpace(fileName) && artifactUrl.Length > 0
            && Uri.TryCreate(artifactUrl, UriKind.Absolute, out var artifactUri))
        {
            fileName = Path.GetFileName(artifactUri.LocalPath);
        }

        if (string.IsNullOrWhiteSpace(fileName))
            fileName = manifestKey + ".bin";

        return new PublishedClientUpdate(version, artifactUrl, sha, size, fileName);
    }

    private static string ShortHash(string value)
        => value.Length > 16 ? value[..16] : value;

    private static async Task<bool> MatchesAsync(string path, PublishedClientUpdate update, CancellationToken ct)
    {
        try
        {
            if (update.SizeBytes > 0 && new FileInfo(path).Length != update.SizeBytes)
                return false;
            var digest = await ComputeSha256Async(path, ct).ConfigureAwait(false);
            return string.Equals(digest, update.Sha256, StringComparison.OrdinalIgnoreCase);
        }
        catch (IOException)
        {
            return false;
        }
    }

    private static void TryDelete(string path)
    {
        try
        {
            File.Delete(path);
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
        }
    }

    private static async Task<string> ComputeSha256Async(string path, CancellationToken ct)
    {
        await using var stream = new FileStream(path, FileMode.Open, FileAccess.Read, FileShare.Read, 1 << 20, useAsync: true);
        var hash = await SHA256.HashDataAsync(stream, ct).ConfigureAwait(false);
        return Convert.ToHexString(hash).ToLowerInvariant();
    }
}
