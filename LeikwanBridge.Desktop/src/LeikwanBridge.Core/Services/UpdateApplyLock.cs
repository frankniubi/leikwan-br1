using System.Globalization;
using System.Text;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Machine-wide "an update is being applied" lock. The lock is an open file
/// handle with no sharing, so the OS releases it the moment the holder exits
/// or crashes; a stale lock file on disk never blocks anything.
/// </summary>
public sealed class UpdateApplyLock : IDisposable
{
    private readonly FileStream _stream;

    private UpdateApplyLock(FileStream stream, string path)
    {
        _stream = stream;
        Path = path;
    }

    public string Path { get; }

    public static UpdateApplyLock? TryAcquire(string path)
    {
        try
        {
            var directory = System.IO.Path.GetDirectoryName(path);
            if (!string.IsNullOrWhiteSpace(directory))
                Directory.CreateDirectory(directory);
            var stream = new FileStream(path, FileMode.OpenOrCreate, FileAccess.ReadWrite, FileShare.None);
            try
            {
                var note = Encoding.UTF8.GetBytes(string.Create(
                    CultureInfo.InvariantCulture,
                    $"pid={Environment.ProcessId} acquired_at={DateTimeOffset.UtcNow:O}\n"));
                stream.SetLength(0);
                stream.Write(note);
                stream.Flush();
            }
            catch (IOException)
            {
                // The note is diagnostics only; the open handle is the lock.
            }

            return new UpdateApplyLock(stream, path);
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
            return null;
        }
    }

    public static async Task<UpdateApplyLock?> AcquireAsync(
        string path,
        TimeSpan timeout,
        Func<TimeSpan, CancellationToken, Task>? delay = null,
        CancellationToken ct = default)
    {
        var wait = delay ?? ((span, token) => Task.Delay(span, token));
        var deadline = DateTimeOffset.UtcNow + timeout;
        while (true)
        {
            var acquired = TryAcquire(path);
            if (acquired is not null)
                return acquired;
            if (DateTimeOffset.UtcNow >= deadline)
                return null;
            await wait(TimeSpan.FromSeconds(1), ct).ConfigureAwait(false);
        }
    }

    /// <summary>True when some other handle currently holds the lock.</summary>
    public static bool IsHeld(string path)
    {
        using var probe = TryAcquire(path);
        return probe is null && File.Exists(path);
    }

    public void Dispose() => _stream.Dispose();
}
