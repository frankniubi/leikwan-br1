using System.Globalization;
using System.Windows;
using System.Windows.Data;
using System.Windows.Media;
using MediaColor = System.Windows.Media.Color;

namespace LeikwanBridge.App.Converters;

public class BoolToStatusBrushConverter : IValueConverter
{
    public object Convert(object value, Type targetType, object parameter, CultureInfo culture)
    {
        // Theme palette when the app is running; fixed colors for tests / design time.
        var app = System.Windows.Application.Current;
        var themed = app?.TryFindResource(value is true ? "SuccessBrush" : "DangerBrush") as System.Windows.Media.Brush;
        return themed ?? (value is true
            ? new SolidColorBrush(MediaColor.FromRgb(0x16, 0xA3, 0x4A)) // green
            : new SolidColorBrush(MediaColor.FromRgb(0xDC, 0x26, 0x26))); // red
    }

    public object ConvertBack(object value, Type targetType, object parameter, CultureInfo culture)
        => throw new NotSupportedException();
}

public class BoolToStatusTextConverter : IValueConverter
{
    public object Convert(object value, Type targetType, object parameter, CultureInfo culture)
    {
        return value is true ? "Running" : "Stopped";
    }

    public object ConvertBack(object value, Type targetType, object parameter, CultureInfo culture)
        => throw new NotSupportedException();
}

public class BytesToHumanConverter : IValueConverter
{
    private static readonly string[] Units = ["B", "KB", "MB", "GB", "TB"];

    public object Convert(object value, Type targetType, object parameter, CultureInfo culture)
    {
        double bytes = System.Convert.ToDouble(value);
        int unitIndex = 0;
        while (bytes >= 1024 && unitIndex < Units.Length - 1)
        {
            bytes /= 1024;
            unitIndex++;
        }
        return $"{bytes:F1} {Units[unitIndex]}";
    }

    public object ConvertBack(object value, Type targetType, object parameter, CultureInfo culture)
        => throw new NotSupportedException();
}

public class ModeToColorConverter : IValueConverter
{
    public object Convert(object value, Type targetType, object parameter, CultureInfo culture)
    {
        return new SolidColorBrush(MediaColor.FromRgb(0x16, 0xA3, 0x4A));
    }

    public object ConvertBack(object value, Type targetType, object parameter, CultureInfo culture)
        => throw new NotSupportedException();
}

public class InverseBoolConverter : IValueConverter
{
    public object Convert(object value, Type targetType, object parameter, CultureInfo culture)
        => value is not true;

    public object ConvertBack(object value, Type targetType, object parameter, CultureInfo culture)
        => value is not true;
}

public class InverseBoolToVisibilityConverter : IValueConverter
{
    public object Convert(object value, Type targetType, object parameter, CultureInfo culture)
        => value is true ? Visibility.Collapsed : Visibility.Visible;

    public object ConvertBack(object value, Type targetType, object parameter, CultureInfo culture)
        => value is Visibility.Collapsed;
}

public class NullToVisibilityConverter : IValueConverter
{
    public object Convert(object value, Type targetType, object parameter, CultureInfo culture)
        => value is null ? Visibility.Collapsed : Visibility.Visible;

    public object ConvertBack(object value, Type targetType, object parameter, CultureInfo culture)
        => System.Windows.Data.Binding.DoNothing;
}

/// <summary>True when all bound values are equal strings (sidebar item Tag vs. CurrentView).</summary>
public sealed class StringEqualsMultiConverter : IMultiValueConverter
{
    public object Convert(object[] values, Type targetType, object parameter, CultureInfo culture)
        => values.Length >= 2
            && values[0] is string left
            && values[1] is string right
            && string.Equals(left, right, StringComparison.OrdinalIgnoreCase);

    public object[] ConvertBack(object value, Type[] targetTypes, object parameter, CultureInfo culture)
        => throw new NotSupportedException();
}

/// <summary>Visible when a saved profile is a local node the panel would accept (upload button).</summary>
public sealed class ProfileUploadableToVisibilityConverter : IValueConverter
{
    public object Convert(object value, Type targetType, object parameter, CultureInfo culture)
        => value is LeikwanBridge.Core.Models.WindowsProxyProfile { IsHosted: false } profile
            && LeikwanBridge.Core.Nodes.WindowsProxyProfileNodeRepository.ToNode(profile) is { IsCloudSupported: true }
            ? Visibility.Visible
            : Visibility.Collapsed;

    public object ConvertBack(object value, Type targetType, object parameter, CultureInfo culture)
        => throw new NotSupportedException();
}

/// <summary>Visible when an integer (e.g. a collection Count) is zero.</summary>
public sealed class ZeroToVisibilityConverter : IValueConverter
{
    public object Convert(object value, Type targetType, object parameter, CultureInfo culture)
    {
        var zero = value is int count && count == 0;
        var invert = string.Equals(parameter as string, "invert", StringComparison.OrdinalIgnoreCase);
        return zero ^ invert ? Visibility.Visible : Visibility.Collapsed;
    }

    public object ConvertBack(object value, Type targetType, object parameter, CultureInfo culture)
        => throw new NotSupportedException();
}

/// <summary>
/// Localized text for a state value: looks up the resource "{parameter}{value}" (for example
/// "StateConnected", "QualityGood") and falls back to the raw value, so enum names never
/// leak into a Chinese UI.
/// </summary>
public sealed class ResourceTextConverter : IValueConverter
{
    public object Convert(object value, Type targetType, object parameter, CultureInfo culture)
    {
        var text = value?.ToString() ?? string.Empty;
        if (text.Length == 0)
            return text;
        var key = (parameter as string ?? string.Empty) + text;
        return System.Windows.Application.Current?.TryFindResource(key) as string ?? text;
    }

    public object ConvertBack(object value, Type targetType, object parameter, CultureInfo culture)
        => throw new NotSupportedException();
}
