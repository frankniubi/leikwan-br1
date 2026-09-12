using LeikwanBridge.Mac.Services;
using LeikwanBridge.Mac.Tests.Platform;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Services;

public sealed class MacConnectionReloadTests
{
    private const string IfconfigSample =
        "lo0: flags=8049<UP,LOOPBACK,RUNNING,MULTICAST> mtu 16384\n"
        + "\toptions=1203<RXCSUM,TXCSUM,TXSTATUS,SW_TIMESTAMP>\n"
        + "\tinet 127.0.0.1 netmask 0xff000000\n"
        + "utun3: flags=8051<UP,POINTOPOINT,RUNNING,MULTICAST> mtu 1280\n"
        + "\tinet 10.8.0.2 --> 10.8.0.1 netmask 0xffffffff\n"
        + "utun6: flags=8051<UP,POINTOPOINT,RUNNING,MULTICAST> mtu 1396\n"
        + "\tinet 172.19.0.1 --> 172.19.0.2 netmask 0xfffffffc\n"
        + "\tnd6 options=201<PERFORMNUD,DAD>\n";

    [Fact]
    public void ReloadMarkerSitsNextToTheConfigItBelongsTo()
        => Assert.Equal(
            "/tmp/leikwan/connection-abc.json.reload",
            MacConnectionReload.MarkerPath("/tmp/leikwan/connection-abc.json"));

    [Fact]
    public void ReloadShellProbeConsumesTheMarkerAndSignalsTheChild()
    {
        // The privileged wrapper is a shell string: the marker path must survive quoting.
        const string path = "/tmp/leikwan/it's here/connection.json.reload";
        Assert.Equal(
            "if [ -f '/tmp/leikwan/it'\\''s here/connection.json.reload' ]; then "
            + "rm -f '/tmp/leikwan/it'\\''s here/connection.json.reload'; "
            + "kill -HUP \"$child\" 2>/dev/null; fi; ",
            MacConnectionReload.ShellProbe(path));
    }

    [Fact]
    public async Task ReloadMarkerIsConfirmedOnlyOnceTheWrapperConsumedIt()
    {
        using var temp = new TemporaryDirectory();
        var marker = MacConnectionReload.MarkerPath(Path.Combine(temp.Path, "connection.json"));
        var consumer = Task.Run(async () =>
        {
            await Task.Delay(300);
            File.Delete(marker);
        });

        Assert.True(await MacConnectionReload.RequestAsync(marker, TimeSpan.FromSeconds(5), CancellationToken.None));

        await consumer;
        Assert.False(File.Exists(marker));
    }

    [Fact]
    public async Task UnconsumedReloadMarkerFailsClosedAndIsRemoved()
    {
        using var temp = new TemporaryDirectory();
        var marker = MacConnectionReload.MarkerPath(Path.Combine(temp.Path, "connection.json"));

        Assert.False(await MacConnectionReload.RequestAsync(marker, TimeSpan.FromMilliseconds(250), CancellationToken.None));

        Assert.False(File.Exists(marker));
    }

    [Fact]
    public void TunMtuProbeReadsTheInterfaceThatOwnsTheTunAddress()
        => Assert.Equal(1396, MacTunMtuProbe.Parse(IfconfigSample, "172.19.0.1"));

    [Theory]
    [InlineData("172.19.0.10")]
    [InlineData("172.19.0.2")]
    [InlineData("172.19.0.11")]
    public void TunMtuProbeNeverAliasesAnotherAddress(string address)
        => Assert.Equal(0, MacTunMtuProbe.Parse(IfconfigSample, address));

    [Fact]
    public void TunMtuProbeReportsUnknownWhileTheTunnelIsAbsent()
    {
        Assert.Equal(0, MacTunMtuProbe.Parse(null, "172.19.0.1"));
        Assert.Equal(0, MacTunMtuProbe.Parse(string.Empty, "172.19.0.1"));
        Assert.Equal(0, MacTunMtuProbe.Parse(IfconfigSample, string.Empty));
        Assert.Equal(0, MacTunMtuProbe.Parse("utun6: flags=8051<UP> mtu 1396\n\tinet 10.0.0.1 --> 10.0.0.2\n", "172.19.0.1"));
    }
}
