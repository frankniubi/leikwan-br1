using LeikwanBridge.Mac.Services;
using LeikwanBridge.Mac.Tests.Platform;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Services;

public sealed class MacUltraRuntimeStatusTests
{
    [Fact]
    public void ReadsTheMeasuredCeilingAndOversizeDrops()
    {
        using var temp = new TemporaryDirectory();
        var statusPath = Path.Combine(temp.Path, "status.json");
        File.WriteAllText(statusPath, """{"forward_id":158,"udp_payload_ceiling":1440,"udp_oversize_drops":7}""");

        var status = MacUltraRuntimeStatusReader.Read(statusPath);

        Assert.Equal(1440, status.PayloadCeiling);
        Assert.Equal(7, status.OversizeDrops);
        Assert.Equal(1440, MacUltraRuntimeStatusReader.ReadPayloadCeiling(statusPath));
    }

    [Fact]
    public void UnusableOrMalformedStatusReadsAsNotMeasured()
    {
        using var temp = new TemporaryDirectory();
        var omitted = Path.Combine(temp.Path, "omitted.json");
        File.WriteAllText(omitted, """{"forward_id":158,"state":"ready"}""");
        var zero = Path.Combine(temp.Path, "zero.json");
        File.WriteAllText(zero, """{"forward_id":158,"udp_payload_ceiling":0,"udp_oversize_drops":0}""");
        var malformed = Path.Combine(temp.Path, "malformed.json");
        File.WriteAllText(malformed, "{not json");
        var wrongType = Path.Combine(temp.Path, "wrong-type.json");
        File.WriteAllText(wrongType, """{"udp_payload_ceiling":"1440","udp_oversize_drops":"many"}""");
        var notAnObject = Path.Combine(temp.Path, "array.json");
        File.WriteAllText(notAnObject, "[1440]");

        Assert.Equal(0, MacUltraRuntimeStatusReader.ReadPayloadCeiling(omitted));
        Assert.Equal(0, MacUltraRuntimeStatusReader.ReadPayloadCeiling(zero));
        Assert.Equal(0, MacUltraRuntimeStatusReader.ReadPayloadCeiling(malformed));
        Assert.Equal(0, MacUltraRuntimeStatusReader.ReadPayloadCeiling(wrongType));
        Assert.Equal(0, MacUltraRuntimeStatusReader.ReadPayloadCeiling(notAnObject));
        Assert.Equal(0, MacUltraRuntimeStatusReader.ReadPayloadCeiling(Path.Combine(temp.Path, "missing.json")));
        Assert.Equal(0, MacUltraRuntimeStatusReader.ReadPayloadCeiling(null));
        Assert.Equal(0, MacUltraRuntimeStatusReader.ReadPayloadCeiling("   "));
    }

    [Fact]
    public void OversizedStatusFileIsIgnored()
    {
        using var temp = new TemporaryDirectory();
        var statusPath = Path.Combine(temp.Path, "status.json");
        var payload = "{\"udp_payload_ceiling\":1440,\"padding\":\"" + new string('x', 70 * 1024) + "\"}";
        File.WriteAllText(statusPath, payload);

        Assert.Equal(0, MacUltraRuntimeStatusReader.ReadPayloadCeiling(statusPath));
    }

    [Fact]
    public void NativeRelayStatusPathPrefersTheNewestInstanceOfThatForward()
    {
        using var temp = new TemporaryDirectory();
        var runtimeDirectory = Path.Combine(temp.Path, "runtime");
        var older = Path.Combine(runtimeDirectory, "native-usage", "native-158-old");
        var newer = Path.Combine(runtimeDirectory, "native-usage", "native-158-new");
        var otherForward = Path.Combine(runtimeDirectory, "native-usage", "native-159-other");
        Directory.CreateDirectory(older);
        Directory.CreateDirectory(newer);
        Directory.CreateDirectory(otherForward);
        var olderPath = Path.Combine(older, "status.json");
        var newerPath = Path.Combine(newer, "status.json");
        File.WriteAllText(olderPath, """{"udp_payload_ceiling":1440}""");
        File.WriteAllText(newerPath, """{"udp_payload_ceiling":1360}""");
        File.WriteAllText(Path.Combine(otherForward, "status.json"), """{"udp_payload_ceiling":1500}""");
        File.SetLastWriteTimeUtc(olderPath, new DateTime(2026, 9, 12, 1, 0, 0, DateTimeKind.Utc));
        File.SetLastWriteTimeUtc(newerPath, new DateTime(2026, 9, 12, 2, 0, 0, DateTimeKind.Utc));

        var found = MacUltraRuntimeStatusReader.FindNativeRelayStatusPath(runtimeDirectory, 158);

        Assert.Equal(newerPath, found);
        Assert.Equal(1360, MacUltraRuntimeStatusReader.ReadPayloadCeiling(found));
        Assert.Null(MacUltraRuntimeStatusReader.FindNativeRelayStatusPath(runtimeDirectory, 0));
        Assert.Null(MacUltraRuntimeStatusReader.FindNativeRelayStatusPath(Path.Combine(temp.Path, "absent"), 158));
        Assert.Null(MacUltraRuntimeStatusReader.FindNativeRelayStatusPath(runtimeDirectory, 160));
    }
}
