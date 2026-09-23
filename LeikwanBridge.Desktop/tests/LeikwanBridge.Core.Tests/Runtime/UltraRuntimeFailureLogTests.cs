using LeikwanBridge.Core.Services.Ultra;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class UltraRuntimeFailureLogTests
{
    private const string Hash = "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef";

    [Theory]
    [InlineData("2026/09/24 00:10:01 tcp stream ended forward_id=158 direction=upstream stage=remote_write reason=aborted generation=g", "tcp_stream_ended")]
    [InlineData("2026/09/24 00:10:01.123456 tcp stream ended forward_id=158 direction=downstream stage=remote_read reason=reset generation=g", "tcp_stream_ended")]
    [InlineData("tcp stream setup failed forward_id=158: dial tcp 106.75.77.97:5438: i/o timeout", "tcp_stream_setup_failed")]
    [InlineData("2026/09/24 00:10:01 external UoT carrier reconnect forward_id=170 elapsed=3s reconnects=1 drops=0 reason=EOF", "external_uot_carrier_reconnect")]
    [InlineData("2026/09/24 00:10:01 active candidate forwarding test failed protocol=tcp forward_id=170 selection_ref=r generation=g reason=timeout", "active_candidate_test_failed")]
    [InlineData("2026/09/24 00:10:01 runtime stopped: policy expired", "runtime_stopped")]
    public void FailureLinesAreClassified(string line, string kind)
        => Assert.Equal(kind, UltraRuntimeFailureLog.Classify(line));

    [Theory]
    [InlineData("2026/09/24 00:10:01 tcp stream ended forward_id=158 direction=upstream stage=local_read reason=eof generation=g")]
    [InlineData("2026/09/24 00:10:01 tcp stream ended forward_id=158 direction=downstream stage=remote_read reason=complete generation=g")]
    [InlineData("2026/09/24 00:10:01 tcp stream ended forward_id=158 direction=upstream stage=local_read reason=canceled generation=g")]
    [InlineData("2026/09/24 00:10:01 external UoT carrier attached forward_id=170 sid=01 logical_protocol=udp")]
    [InlineData("2026/09/24 00:10:01 ultra lease selection started forward_id=170 candidates=2")]
    [InlineData("")]
    public void NormalTelemetryStaysAtDebug(string line)
        => Assert.Null(UltraRuntimeFailureLog.Classify(line));

    [Fact]
    public void RedactionRemovesAddressesButKeepsOpaqueHashes()
    {
        var redacted = UltraRuntimeFailureLog.Redact(
            $"tcp stream setup failed forward_id=158: dial tcp 106.75.77.97:5438 via [2001:db8::1]:443 candidate_hash={Hash}");
        Assert.DoesNotContain("106.75.77.97", redacted);
        Assert.DoesNotContain("2001:db8", redacted);
        Assert.Contains($"candidate_hash={Hash}", redacted);
        Assert.Contains("forward_id=158", redacted);
        Assert.True(UltraRuntimeFailureLog.Redact(new string('x', 4096)).Length <= UltraRuntimeFailureLog.MaxLineLength + 3);
    }

    [Fact]
    public void FailuresAreRateLimitedPerForwardAndKindAndReportSuppression()
    {
        var log = new UltraRuntimeFailureLog(maxPerWindow: 2, window: TimeSpan.FromMinutes(1));
        var start = DateTimeOffset.Parse("2026-09-24T00:00:00Z");
        var emitted = new List<(string Kind, string Line, int Suppressed)>();
        const string line = "2026/09/24 00:10:01 tcp stream ended forward_id=158 direction=upstream stage=remote_write reason=aborted";

        for (var i = 0; i < 5; i++)
            Assert.True(log.Observe(158, line, start.AddSeconds(i), (k, l, s) => emitted.Add((k, l, s))));
        Assert.Equal(2, emitted.Count);
        Assert.StartsWith("tcp stream ended", emitted[0].Line);

        // Another forward has its own budget.
        log.Observe(159, line, start.AddSeconds(5), (k, l, s) => emitted.Add((k, l, s)));
        Assert.Equal(3, emitted.Count);

        // The next window reports what the previous one dropped.
        log.Observe(158, line, start.AddMinutes(1), (k, l, s) => emitted.Add((k, l, s)));
        Assert.Equal(4, emitted.Count);
        Assert.Equal(3, emitted[3].Suppressed);
        Assert.False(log.Observe(158, "2026/09/24 00:10:01 ultra lease selection started forward_id=158", start, (_, _, _) => emitted.Add(default)));
        Assert.Equal(4, emitted.Count);
    }
}
