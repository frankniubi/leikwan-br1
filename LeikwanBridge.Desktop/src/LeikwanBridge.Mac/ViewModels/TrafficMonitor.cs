using LeikwanBridge.Mac.Services;

namespace LeikwanBridge.Mac.ViewModels;

/// <summary>
/// Turns cumulative runtime counters into a live speed series and session totals.
/// The ultra-client rewrites its status file about every five seconds, so the
/// monitor spreads each observed delta over the time since the previous change
/// and holds that rate until the next write (or drops to zero once the runtime
/// has been quiet for longer than <see cref="HoldWindow"/>). A counter that goes
/// backwards is a restarted runtime and contributes its whole new value.
/// </summary>
public sealed class TrafficMonitor
{
    public const int HistoryLength = 40;
    public static readonly TimeSpan HoldWindow = TimeSpan.FromSeconds(7);
    private static readonly TimeSpan MinimumSpread = TimeSpan.FromSeconds(1);
    private static readonly TimeSpan MaximumSpread = TimeSpan.FromSeconds(10);

    private readonly Dictionary<string, TrafficCounter> _last = new(StringComparer.Ordinal);
    private readonly Queue<double> _download = new();
    private readonly Queue<double> _upload = new();
    private bool _primed;
    private DateTimeOffset _lastChange;

    public TrafficMonitor()
    {
        for (var i = 0; i < HistoryLength; i++)
        {
            _download.Enqueue(0);
            _upload.Enqueue(0);
        }
    }

    public double DownloadRate { get; private set; }
    public double UploadRate { get; private set; }
    public long SessionDownload { get; private set; }
    public long SessionUpload { get; private set; }
    public IReadOnlyList<double> DownloadHistory => _download.ToArray();
    public IReadOnlyList<double> UploadHistory => _upload.ToArray();

    public void Sample(DateTimeOffset now, IReadOnlyDictionary<string, TrafficCounter> counters)
    {
        long deltaIn = 0;
        long deltaOut = 0;
        foreach (var (key, counter) in counters)
        {
            if (!_primed)
                continue;
            if (_last.TryGetValue(key, out var previous))
            {
                deltaIn += counter.BytesIn >= previous.BytesIn ? counter.BytesIn - previous.BytesIn : counter.BytesIn;
                deltaOut += counter.BytesOut >= previous.BytesOut ? counter.BytesOut - previous.BytesOut : counter.BytesOut;
            }
            else
            {
                // A runtime that started after the monitor did: all of it is new traffic.
                deltaIn += counter.BytesIn;
                deltaOut += counter.BytesOut;
            }
        }

        _last.Clear();
        foreach (var (key, counter) in counters)
            _last[key] = counter;

        if (!_primed)
        {
            _primed = true;
            _lastChange = now;
        }
        else if (deltaIn > 0 || deltaOut > 0)
        {
            var spread = now - _lastChange;
            if (spread < MinimumSpread)
                spread = MinimumSpread;
            if (spread > MaximumSpread)
                spread = MaximumSpread;
            DownloadRate = deltaIn / spread.TotalSeconds;
            UploadRate = deltaOut / spread.TotalSeconds;
            SessionDownload = SaturatingAdd(SessionDownload, deltaIn);
            SessionUpload = SaturatingAdd(SessionUpload, deltaOut);
            _lastChange = now;
        }
        else if (now - _lastChange > HoldWindow)
        {
            DownloadRate = 0;
            UploadRate = 0;
        }

        Push(_download, DownloadRate);
        Push(_upload, UploadRate);
    }

    private static void Push(Queue<double> queue, double value)
    {
        queue.Enqueue(value);
        while (queue.Count > HistoryLength)
            queue.Dequeue();
    }

    private static long SaturatingAdd(long left, long right)
        => right > 0 && left > long.MaxValue - right ? long.MaxValue : left + right;

    public static string FormatRate(double bytesPerSecond) => FormatBytes(bytesPerSecond) + "/s";

    public static string FormatBytes(double bytes)
    {
        var value = Math.Max(0, bytes);
        string[] units = ["B", "KB", "MB", "GB", "TB"];
        var index = 0;
        while (value >= 1024 && index < units.Length - 1)
        {
            value /= 1024;
            index++;
        }
        var format = index == 0 || value >= 100 ? "0" : "0.0";
        return value.ToString(format, System.Globalization.CultureInfo.InvariantCulture) + " " + units[index];
    }
}
