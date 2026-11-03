using System.Globalization;

namespace LeikwanBridge.App.ViewModels;

/// <summary>
/// Splits a forward's "host:port" target for the dashboard card. The panel always sends
/// the port, and an IPv6 address is bracketed ("[2001:db8::1]:443"). A target that does
/// not parse is shown whole, with no port.
/// </summary>
public static class ForwardTarget
{
    /// <summary>Shown when a forward has no target, such as a native outbound rule.</summary>
    public const string Placeholder = "-";

    public static (string Address, string Port) Split(string? target)
    {
        var value = target?.Trim() ?? "";
        if (value.Length == 0)
            return (Placeholder, Placeholder);

        var separator = value.LastIndexOf(':');
        if (separator <= 0 || separator == value.Length - 1)
            return (value, Placeholder);

        var port = value[(separator + 1)..];
        if (!int.TryParse(port, NumberStyles.None, CultureInfo.InvariantCulture, out var number)
            || number is <= 0 or > 65535)
            return (value, Placeholder);

        var address = value[..separator];
        if (address.Length > 2 && address[0] == '[' && address[^1] == ']')
            address = address[1..^1];
        return (address, number.ToString(CultureInfo.InvariantCulture));
    }
}
