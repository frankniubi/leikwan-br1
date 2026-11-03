using System.Globalization;
using Serilog.Events;
using Serilog.Formatting;
using Serilog.Formatting.Display;

namespace LeikwanBridge.Core.Services;

/// <summary>Preserves the normal Serilog layout while redacting rendered values.</summary>
public sealed class RedactingTextFormatter : ITextFormatter
{
    private readonly MessageTemplateTextFormatter _inner;

    public RedactingTextFormatter(string outputTemplate)
    {
        _inner = new MessageTemplateTextFormatter(outputTemplate, CultureInfo.InvariantCulture);
    }

    public void Format(LogEvent logEvent, TextWriter output)
    {
        using var buffer = new StringWriter(CultureInfo.InvariantCulture);
        _inner.Format(logEvent, buffer);
        output.Write(LogPrivacy.Redact(buffer.ToString()));
    }
}
