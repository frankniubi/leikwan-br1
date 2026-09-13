using System.Net;
using System.Net.Http.Headers;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Downloads a published client artifact with resume and stall detection.
///
/// The shared panel clients carry <c>HttpClient.Timeout = 30 s</c>, and in .NET
/// that timeout covers the whole operation *including reading the response
/// body*. The Windows installer is 69.5 MB and the macOS update packages are
/// 68-72 MB, so those clients abort every download slower than ~2.3 MB/s - which
/// is most of them between CN and the panel, and it fails at exactly 30 seconds
/// no matter how much had already arrived.
///
/// This downloader keeps the artifact in a <c>.part</c> file and simply resumes
/// from the byte it reached whenever an attempt dies, so a 30 s request budget
/// becomes a chunk size instead of a deadline: even a 100 KB/s link finishes 69 MB
/// in a bounded number of attempts. Liveness is enforced separately by
/// <paramref name="stallTimeout"/> - no bytes for that long means the connection
/// is dead, not slow, and the attempt is abandoned immediately.
///
/// The panel serves artifacts through <c>ArtifactController::serveRepoFile</c>,
/// which honours <c>Range</c> (200/206/416); anything else is handled by
/// restarting the attempt from zero.
/// </summary>
public static class ResumableArtifactDownloader
{
    /// <summary>No bytes for this long abandons the attempt (a slow link keeps sending).</summary>
    public static TimeSpan DefaultStallTimeout { get; } = TimeSpan.FromSeconds(20);

    /// <summary>
    /// Attempts are cheap and resumable; 64 of them still completes 69 MB on a
    /// ~40 KB/s link, and a healthy link needs exactly one.
    /// </summary>
    public const int DefaultMaxAttempts = 64;

    /// <summary>Bytes received so far, and the artifact size when the server reported it.</summary>
    public delegate void ProgressHandler(long received, long? total);

    /// <summary>
    /// @param send Performs one HTTP request. Supplied by the caller so each
    ///             service keeps its own routing/anti-poison client and tests can
    ///             inject a fake handler.
    /// @return the number of bytes in the completed artifact.
    /// </summary>
    public static async Task<long> DownloadAsync(
        Func<HttpRequestMessage, CancellationToken, Task<HttpResponseMessage>> send,
        string artifactUrl,
        string destinationPath,
        ProgressHandler? progress = null,
        TimeSpan? stallTimeout = null,
        int maxAttempts = DefaultMaxAttempts,
        long? expectedLength = null,
        CancellationToken ct = default)
    {
        ArgumentException.ThrowIfNullOrWhiteSpace(artifactUrl);
        ArgumentException.ThrowIfNullOrWhiteSpace(destinationPath);
        if (maxAttempts < 1)
            throw new ArgumentOutOfRangeException(nameof(maxAttempts));

        var stall = stallTimeout ?? DefaultStallTimeout;
        var directory = Path.GetDirectoryName(destinationPath);
        if (!string.IsNullOrWhiteSpace(directory))
            Directory.CreateDirectory(directory);

        var partialPath = destinationPath + ".part";
        // The published manifest size is authoritative: without it a truncated
        // body that happens to match the Content-Length the server advertised
        // would look like a finished download.
        var authoritativeLength = expectedLength is > 0 ? expectedLength : null;
        long received = 0;
        long? total = authoritativeLength;
        Exception? lastError = null;

        for (var attempt = 0; attempt < maxAttempts; attempt++)
        {
            ct.ThrowIfCancellationRequested();
            received = File.Exists(partialPath) ? new FileInfo(partialPath).Length : 0;
            if (total is > 0 && received >= total.Value)
            {
                Promote(partialPath, destinationPath);
                return received;
            }

            try
            {
                using var request = new HttpRequestMessage(HttpMethod.Get, artifactUrl);
                if (received > 0)
                    request.Headers.Range = new RangeHeaderValue(received, null);

                using var response = await send(request, ct).ConfigureAwait(false);

                if (response.StatusCode == HttpStatusCode.RequestedRangeNotSatisfiable)
                {
                    // The partial is at or past the artifact size: start over.
                    received = 0;
                    total = authoritativeLength;
                    DeletePartial(partialPath);
                    continue;
                }

                response.EnsureSuccessStatusCode();

                var isPartial = response.StatusCode == HttpStatusCode.PartialContent;
                if (received > 0 && !isPartial)
                {
                    // The server ignored Range and is about to send the whole file.
                    received = 0;
                    DeletePartial(partialPath);
                }

                var contentLength = response.Content.Headers.ContentLength;
                total = authoritativeLength
                    ?? (contentLength is > 0 ? received + contentLength.Value : null);

                await using (var source = await response.Content.ReadAsStreamAsync(ct).ConfigureAwait(false))
                await using (var file = new FileStream(
                    partialPath,
                    received > 0 ? FileMode.Append : FileMode.Create,
                    FileAccess.Write,
                    FileShare.None,
                    bufferSize: 128 * 1024,
                    useAsync: true))
                {
                    var buffer = new byte[128 * 1024];
                    while (true)
                    {
                        ct.ThrowIfCancellationRequested();
                        var read = await ReadWithStallTimeoutAsync(source, buffer, stall, ct).ConfigureAwait(false);
                        if (read == 0)
                            break;
                        await file.WriteAsync(buffer.AsMemory(0, read), ct).ConfigureAwait(false);
                        received += read;
                        progress?.Invoke(received, total);
                    }
                    await file.FlushAsync(ct).ConfigureAwait(false);
                }

                if (total is > 0 && received < total.Value)
                {
                    // The stream ended early (server closed, timeout mid-body).
                    progress?.Invoke(received, total);
                    continue;
                }

                Promote(partialPath, destinationPath);
                return received;
            }
            catch (OperationCanceledException) when (ct.IsCancellationRequested)
            {
                throw;
            }
            catch (Exception exception) when (exception is HttpRequestException or IOException or TaskCanceledException or TimeoutException)
            {
                // Whatever went wrong, the bytes already on disk are still good:
                // the next attempt resumes from them.
                lastError = exception;
                progress?.Invoke(received, total);
            }
        }

        throw new IOException(
            $"artifact download did not complete after {maxAttempts} attempts ({received} bytes received)",
            lastError);
    }

    /// <summary>
    /// Reads with an inactivity budget. A TaskCanceledException whose only cause
    /// is the stall timer (not the caller) surfaces as a TimeoutException so the
    /// retry loop can distinguish "dead connection" from "user cancelled".
    /// </summary>
    private static async Task<int> ReadWithStallTimeoutAsync(
        Stream source,
        byte[] buffer,
        TimeSpan stallTimeout,
        CancellationToken ct)
    {
        using var readCts = CancellationTokenSource.CreateLinkedTokenSource(ct);
        readCts.CancelAfter(stallTimeout);
        try
        {
            return await source.ReadAsync(buffer, readCts.Token).ConfigureAwait(false);
        }
        catch (OperationCanceledException) when (!ct.IsCancellationRequested)
        {
            throw new TimeoutException($"artifact download stalled for {stallTimeout.TotalSeconds:0.#}s");
        }
    }

    private static void Promote(string partialPath, string destinationPath)
    {
        File.Move(partialPath, destinationPath, overwrite: true);
    }

    private static void DeletePartial(string partialPath)
    {
        try
        {
            if (File.Exists(partialPath))
                File.Delete(partialPath);
        }
        catch (IOException)
        {
            // A leftover partial only costs a resume; never fail the download for it.
        }
    }
}
