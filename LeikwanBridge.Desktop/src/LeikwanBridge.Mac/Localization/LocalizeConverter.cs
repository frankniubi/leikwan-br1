using System.Globalization;
using Avalonia.Data.Converters;

namespace LeikwanBridge.Mac.Localization;

/// <summary>
/// Shows an option value (enum or code) in the active language: the resource key is
/// ConverterParameter + value, e.g. "Mode" + Proxy -> "ModeProxy". Unknown values fall back
/// to the raw value (data such as a server-provided name is shown as-is).
/// </summary>
public sealed class LocalizeConverter : IValueConverter
{
    public object? Convert(object? value, Type targetType, object? parameter, CultureInfo culture)
    {
        var raw = value?.ToString() ?? string.Empty;
        return L.T((parameter as string ?? string.Empty) + raw, raw);
    }

    public object? ConvertBack(object? value, Type targetType, object? parameter, CultureInfo culture)
        => throw new NotSupportedException();
}
