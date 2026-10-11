using System.IO;
using LeikwanBridge.Core.Services;
using Serilog;
using Serilog.Events;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class DesktopLoggingTests
{
    [Theory]
    [InlineData(null, DesktopLogLevel.Warning)]
    [InlineData("INFO", DesktopLogLevel.Warning)]
    [InlineData("warning", DesktopLogLevel.Warning)]
    [InlineData("critical", DesktopLogLevel.Critical)]
    [InlineData("debug", DesktopLogLevel.Debug)]
    [InlineData("invalid", DesktopLogLevel.Warning)]
    public void Normalize_UsesSafeSupportedLevels(string? value, string expected)
    {
        Assert.Equal(expected, DesktopLogLevel.Normalize(value));
    }

    [Fact]
    public void Redact_RemovesSecretsAndLiteralIpAddresses()
    {
        var text = LogPrivacy.Redact(
            "https://203.0.113.7:443/path?token=abc Authorization: Bearer xyz "
            + "peer=2001:db8::7%en0 password=secret");

        Assert.DoesNotContain("203.0.113.7", text, StringComparison.Ordinal);
        Assert.DoesNotContain("2001:db8::7", text, StringComparison.Ordinal);
        Assert.DoesNotContain("%en0", text, StringComparison.Ordinal);
        Assert.DoesNotContain("abc", text, StringComparison.Ordinal);
        Assert.DoesNotContain("xyz", text, StringComparison.Ordinal);
        Assert.DoesNotContain("secret", text, StringComparison.Ordinal);
        Assert.Contains("[REDACTED]", text, StringComparison.Ordinal);
    }

    [Fact]
    public void Formatter_RedactsRenderedPropertiesAndExceptions()
    {
        var formatter = new RedactingTextFormatter("{Message:lj}{NewLine}{Exception}");
        var logEvent = new LogEvent(
            DateTimeOffset.UtcNow,
            LogEventLevel.Error,
            new InvalidOperationException("connect to 198.51.100.9 failed"),
            new Serilog.Parsing.MessageTemplateParser().Parse("peer {Peer} token={Token}"),
            [
                new("Peer", new ScalarValue("192.0.2.15")),
                new("Token", new ScalarValue("private-value")),
            ]);
        using var writer = new StringWriter();

        formatter.Format(logEvent, writer);

        var output = writer.ToString();
        Assert.DoesNotContain("192.0.2.15", output, StringComparison.Ordinal);
        Assert.DoesNotContain("198.51.100.9", output, StringComparison.Ordinal);
        Assert.DoesNotContain("private-value", output, StringComparison.Ordinal);
        Assert.Contains("[REDACTED]", output, StringComparison.Ordinal);
    }
}
