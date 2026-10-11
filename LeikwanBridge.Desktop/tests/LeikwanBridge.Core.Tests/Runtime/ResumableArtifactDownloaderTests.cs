using System.Net;
using System.Net.Http.Headers;
using System.Security.Cryptography;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

/// <summary>
/// The shared panel client caps a request at 30 s including the response body,
/// and the published client artifacts are ~70 MB. Before this, any link slower
/// than ~2.3 MB/s failed at exactly 30 seconds, every time, no matter how much
/// had already arrived - which is the "update always dies at 30 seconds" report.
/// These tests pin the contract that makes a slow link survivable: keep what
/// arrived, ask for the rest with Range, and finish.
/// </summary>
public sealed class ResumableArtifactDownloaderTests : IDisposable
{
    private readonly string _root = Path.Combine(Path.GetTempPath(), "lw-resume-" + Guid.NewGuid().ToString("N"));

    public ResumableArtifactDownloaderTests() => Directory.CreateDirectory(_root);

    public void Dispose()
    {
        try { Directory.Delete(_root, recursive: true); } catch (IOException) { }
    }

    private static byte[] Content(int length)
    {
        var bytes = new byte[length];
        for (var i = 0; i < length; i++)
            bytes[i] = (byte)(i % 251);
        return bytes;
    }

    private static long? RangeOffset(HttpRequestMessage request)
    {
        var range = request.Headers.Range;
        if (range?.Ranges is null || range.Ranges.Count == 0)
            return null;
        return range.Ranges.First().From;
    }

    /// <summary>
    /// Serves <paramref name="content"/> but lets each response body die after
    /// <paramref name="bytesPerAttempt"/> bytes while still advertising the full
    /// remaining length - exactly what a 30 s client timeout looks like from the
    /// reader's side.
    /// </summary>
    private static Func<HttpRequestMessage, CancellationToken, Task<HttpResponseMessage>> DyingServer(
        byte[] content,
        int bytesPerAttempt,
        List<long> observedOffsets,
        bool honourRange = true)
    {
        return (request, _) =>
        {
            var offset = honourRange ? RangeOffset(request) ?? 0 : 0;
            observedOffsets.Add(offset);
            var remaining = content.Length - (int)offset;
            var bodyLength = Math.Min(bytesPerAttempt, remaining);
            var response = new HttpResponseMessage(
                honourRange && offset > 0 ? HttpStatusCode.PartialContent : HttpStatusCode.OK)
            {
                Content = new StreamContent(new MemoryStream(content, (int)offset, bodyLength)),
            };
            // The server believes it is sending the whole remainder; the
            // connection dies after bodyLength bytes.
            response.Content.Headers.ContentLength = remaining;
            return Task.FromResult(response);
        };
    }

    [Fact]
    public async Task ADeadAttemptResumesFromTheBytesAlreadyReceived()
    {
        var content = Content(1000);
        var offsets = new List<long>();
        var destination = Path.Combine(_root, "installer.exe");
        var progress = new List<long>();

        var total = await ResumableArtifactDownloader.DownloadAsync(
            DyingServer(content, bytesPerAttempt: 300, offsets),
            "https://panel.example/artifacts/leikwan-bridge-setup.exe",
            destination,
            progress: (received, _) => progress.Add(received),
            stallTimeout: TimeSpan.FromSeconds(5));

        Assert.Equal(content.Length, total);
        Assert.Equal(content, await File.ReadAllBytesAsync(destination));
        Assert.False(File.Exists(destination + ".part"));
        // 300 + 300 + 300 + 100: four attempts, each resuming where the last died.
        Assert.Equal(new long[] { 0, 300, 600, 900 }, offsets);
        Assert.Contains(900, progress);
    }

    [Fact]
    public async Task AttemptsAfterTheFirstCarryARangeHeader()
    {
        var content = Content(600);
        var offsets = new List<long>();
        var destination = Path.Combine(_root, "installer.exe");

        await ResumableArtifactDownloader.DownloadAsync(
            DyingServer(content, bytesPerAttempt: 250, offsets),
            "https://panel.example/x",
            destination,
            stallTimeout: TimeSpan.FromSeconds(5));

        Assert.Equal(new long[] { 0, 250, 500 }, offsets);
        Assert.Equal(content, await File.ReadAllBytesAsync(destination));
    }

    [Fact]
    public async Task AServerThatIgnoresRangeRestartsInsteadOfCorruptingTheFile()
    {
        var content = Content(800);
        var offsets = new List<long>();
        var destination = Path.Combine(_root, "installer.exe");
        var calls = 0;

        var send = new Func<HttpRequestMessage, CancellationToken, Task<HttpResponseMessage>>((request, _) =>
        {
            offsets.Add(RangeOffset(request) ?? 0);
            calls++;
            // Both attempts answer 200 (Range ignored) and advertise the full
            // artifact length; the first one's body dies after 200 bytes, which
            // is what a 30 s client timeout looks like to the reader. The second
            // delivers everything.
            var bodyLength = calls == 1 ? 200 : content.Length;
            var response = new HttpResponseMessage(HttpStatusCode.OK)
            {
                Content = new StreamContent(new MemoryStream(content, 0, bodyLength)),
            };
            response.Content.Headers.ContentLength = content.Length;
            return Task.FromResult(response);
        });

        var total = await ResumableArtifactDownloader.DownloadAsync(
            send, "https://panel.example/x", destination, stallTimeout: TimeSpan.FromSeconds(5));

        Assert.Equal(content.Length, total);
        // The 200 bytes from the first attempt must not be prepended to the rest.
        Assert.Equal(content, await File.ReadAllBytesAsync(destination));
        // The client does ask to resume (200), the server answers 200 instead of
        // 206, and the downloader therefore rewrites the file from zero rather
        // than appending a body that starts at byte 0.
        Assert.Equal(new long[] { 0, 200 }, offsets);
    }

    [Fact]
    public async Task AnUnsatisfiableRangeStartsOver()
    {
        var content = Content(400);
        var destination = Path.Combine(_root, "installer.exe");
        await File.WriteAllBytesAsync(destination + ".part", new byte[900]);
        var calls = 0;

        var send = new Func<HttpRequestMessage, CancellationToken, Task<HttpResponseMessage>>((request, _) =>
        {
            calls++;
            if (calls == 1)
                return Task.FromResult(new HttpResponseMessage(HttpStatusCode.RequestedRangeNotSatisfiable));
            var response = new HttpResponseMessage(HttpStatusCode.OK)
            {
                Content = new StreamContent(new MemoryStream(content)),
            };
            response.Content.Headers.ContentLength = content.Length;
            return Task.FromResult(response);
        });

        var total = await ResumableArtifactDownloader.DownloadAsync(
            send, "https://panel.example/x", destination, stallTimeout: TimeSpan.FromSeconds(5));

        Assert.Equal(content.Length, total);
        Assert.Equal(content, await File.ReadAllBytesAsync(destination));
    }

    [Fact]
    public async Task AStalledConnectionIsAbandonedAndReported()
    {
        var destination = Path.Combine(_root, "installer.exe");
        var send = new Func<HttpRequestMessage, CancellationToken, Task<HttpResponseMessage>>((request, _) =>
        {
            var response = new HttpResponseMessage(HttpStatusCode.PartialContent)
            {
                Content = new StreamContent(new BlockingStream()),
            };
            response.Content.Headers.ContentLength = 1000;
            return Task.FromResult(response);
        });

        var started = DateTime.UtcNow;
        var error = await Assert.ThrowsAsync<IOException>(() => ResumableArtifactDownloader.DownloadAsync(
            send,
            "https://panel.example/x",
            destination,
            stallTimeout: TimeSpan.FromMilliseconds(150),
            maxAttempts: 3));

        Assert.Contains("did not complete", error.Message);
        Assert.True(DateTime.UtcNow - started < TimeSpan.FromSeconds(20), "the stall budget must fail fast");
    }

    [Fact]
    public async Task TheCompletedFileHashesToTheArtifact()
    {
        var content = Content(5000);
        var expected = Convert.ToHexString(SHA256.HashData(content));
        var destination = Path.Combine(_root, "installer.exe");

        await ResumableArtifactDownloader.DownloadAsync(
            DyingServer(content, bytesPerAttempt: 700, new List<long>()),
            "https://panel.example/x",
            destination,
            stallTimeout: TimeSpan.FromSeconds(5));

        Assert.Equal(expected, WindowsAppUpdateService.ComputeSha256(destination));
    }

    /// <summary>A connection that accepts the request and then never sends anything.</summary>
    private sealed class BlockingStream : Stream
    {
        public override bool CanRead => true;
        public override bool CanSeek => false;
        public override bool CanWrite => false;
        public override long Length => throw new NotSupportedException();
        public override long Position { get => 0; set => throw new NotSupportedException(); }
        public override void Flush() { }
        public override int Read(byte[] buffer, int offset, int count) => throw new NotSupportedException();
        public override long Seek(long offset, SeekOrigin origin) => throw new NotSupportedException();
        public override void SetLength(long value) => throw new NotSupportedException();
        public override void Write(byte[] buffer, int offset, int count) => throw new NotSupportedException();

        public override async ValueTask<int> ReadAsync(Memory<byte> buffer, CancellationToken cancellationToken = default)
        {
            await Task.Delay(Timeout.Infinite, cancellationToken).ConfigureAwait(false);
            return 0;
        }
    }
}
