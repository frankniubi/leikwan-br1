using System.Text.Json;

namespace LeikwanBridge.Core.Services;

public readonly record struct RuntimeUsageSnapshot(long BytesIn, long BytesOut);

public sealed class RuntimeUsageReader
{
    public RuntimeUsageSnapshot Read(string stateDir)
    {
        if (string.IsNullOrWhiteSpace(stateDir))
            return new RuntimeUsageSnapshot(0, 0);

        var runtimeDir = Path.Combine(stateDir, "runtime");
        if (!Directory.Exists(runtimeDir))
            return new RuntimeUsageSnapshot(0, 0);

        long bytesIn = 0;
        long bytesOut = 0;
        foreach (var path in Directory.EnumerateFiles(runtimeDir, "ultra-forward-*.status.json").Take(32))
        {
            try
            {
                using var document = JsonDocument.Parse(File.ReadAllBytes(path));
                var root = document.RootElement;
                if (!root.TryGetProperty("forward_id", out var forwardId)
                    || forwardId.GetInt32() <= 0)
                    continue;

                bytesIn = SaturatingAdd(bytesIn, ReadCounter(root, "bytes_in"));
                bytesOut = SaturatingAdd(bytesOut, ReadCounter(root, "bytes_out"));
            }
            catch (Exception ex) when (ex is IOException or JsonException or InvalidOperationException or FormatException)
            {
                // Runtime status files are replaced atomically; ignore a file during a concurrent rewrite.
            }
        }

        return new RuntimeUsageSnapshot(bytesIn, bytesOut);
    }

    private static long ReadCounter(JsonElement root, string propertyName)
    {
        if (!root.TryGetProperty(propertyName, out var value)
            || !value.TryGetInt64(out var counter))
            return 0;
        return Math.Max(0, counter);
    }

    private static long SaturatingAdd(long left, long right)
    {
        if (right <= 0 || left > long.MaxValue - right)
            return right <= 0 ? left : long.MaxValue;
        return left + right;
    }
}
