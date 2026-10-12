using System.Text.Json;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class RuntimeUsageReaderTests
{
    [Fact]
    public void Read_SumsUltraStatusCounters_AndIgnoresMalformedFiles()
    {
        using var tempDir = new TempStateDirectory();
        var runtimeDir = Path.Combine(tempDir.Path, "runtime");
        Directory.CreateDirectory(runtimeDir);
        File.WriteAllText(
            Path.Combine(runtimeDir, "ultra-forward-158.status.json"),
            JsonSerializer.Serialize(new { forward_id = 158, bytes_in = 1200, bytes_out = 3400 }));
        File.WriteAllText(
            Path.Combine(runtimeDir, "ultra-forward-159.status.json"),
            JsonSerializer.Serialize(new { forward_id = 159, bytes_in = 800, bytes_out = 600 }));
        File.WriteAllText(Path.Combine(runtimeDir, "ultra-forward-bad.status.json"), "not-json");

        var snapshot = new RuntimeUsageReader().Read(tempDir.Path);

        Assert.Equal(2000, snapshot.BytesIn);
        Assert.Equal(4000, snapshot.BytesOut);
    }

    [Fact]
    public void Read_CountsEachNativeForwardsNewestInstance_NextToOrdinaryUltra()
    {
        using var tempDir = new TempStateDirectory();
        var runtimeDir = Path.Combine(tempDir.Path, "runtime");
        Directory.CreateDirectory(runtimeDir);
        File.WriteAllText(
            Path.Combine(runtimeDir, "ultra-forward-158.status.json"),
            JsonSerializer.Serialize(new { forward_id = 158, bytes_in = 1000, bytes_out = 100 }));
        var nativeRoot = Path.Combine(tempDir.Path, "proxy-runtime", "native-usage");
        void Native(string instance, int forwardId, long bytesIn, long bytesOut, DateTime writtenUtc)
        {
            var directory = Path.Combine(nativeRoot, $"native-{forwardId}-{instance}");
            Directory.CreateDirectory(directory);
            var path = Path.Combine(directory, "status.json");
            File.WriteAllText(path, JsonSerializer.Serialize(new { forward_id = forwardId, bytes_in = bytesIn, bytes_out = bytesOut }));
            File.SetLastWriteTimeUtc(path, writtenUtc);
        }
        var now = DateTime.UtcNow;
        // An archived instance of forward 180 is kept for billing; the dashboard counts only the live one.
        Native("old", 180, 900_000, 9_000, now.AddHours(-2));
        Native("live", 180, 30_000, 300, now);
        Native("only", 181, 5_000, 50, now.AddMinutes(-1));

        var snapshot = new RuntimeUsageReader().Read(tempDir.Path);

        Assert.Equal(1000 + 30_000 + 5_000, snapshot.BytesIn);
        Assert.Equal(100 + 300 + 50, snapshot.BytesOut);
    }

    [Fact]
    public void Read_CountsNativeOutboundWithoutAnOrdinaryRuntimeDirectory()
    {
        using var tempDir = new TempStateDirectory();
        var directory = Path.Combine(tempDir.Path, "proxy-runtime", "native-usage", "native-180-live");
        Directory.CreateDirectory(directory);
        File.WriteAllText(
            Path.Combine(directory, "status.json"),
            JsonSerializer.Serialize(new { forward_id = 180, bytes_in = 4096, bytes_out = 128 }));

        var snapshot = new RuntimeUsageReader().Read(tempDir.Path);

        Assert.Equal(4096, snapshot.BytesIn);
        Assert.Equal(128, snapshot.BytesOut);
    }

    [Fact]
    public void Read_ClampsNegativeCountersToZero()
    {
        using var tempDir = new TempStateDirectory();
        var runtimeDir = Path.Combine(tempDir.Path, "runtime");
        Directory.CreateDirectory(runtimeDir);
        File.WriteAllText(
            Path.Combine(runtimeDir, "ultra-forward-158.status.json"),
            JsonSerializer.Serialize(new { forward_id = 158, bytes_in = -1, bytes_out = -20 }));

        var snapshot = new RuntimeUsageReader().Read(tempDir.Path);

        Assert.Equal(0, snapshot.BytesIn);
        Assert.Equal(0, snapshot.BytesOut);
    }
}
