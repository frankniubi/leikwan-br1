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
