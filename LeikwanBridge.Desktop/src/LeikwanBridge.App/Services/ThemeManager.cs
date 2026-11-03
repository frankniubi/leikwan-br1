using System.IO;
using System.Runtime.InteropServices;
using System.Windows;
using System.Windows.Interop;
using Microsoft.Win32;
using Serilog;

namespace LeikwanBridge.App.Services;

public enum ThemePreference
{
    System,
    Light,
    Dark,
}

/// <summary>
/// Light / dark theme for the WPF app. Follows the Windows "app mode" (Settings ›
/// Personalization › Colors) by default and re-applies when the user changes it; the
/// Settings page can pin light or dark. Swaps the Colors.(Light|Dark).xaml palette in the
/// application resources (every brush is a DynamicResource) and tints the title bar.
/// The preference is a per-user UI choice, so it lives next to the per-user installer
/// cache rather than in the shared ProgramData agent config.
/// </summary>
public static class ThemeManager
{
    private const string PaletteMarker = "Themes/Colors.";
    private static bool _listening;

    public static ThemePreference Preference { get; private set; } = ThemePreference.System;

    public static bool IsDark { get; private set; }

    public static event Action<bool>? ThemeChanged;

    public static string PreferencePath { get; set; } = Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
        "LeiKwan Bridge",
        "ui-theme.txt");

    public static void Initialize()
    {
        Preference = LoadPreference();
        // Session-only override (support / screenshots): LEIKWAN_THEME=light|dark|system.
        var overrideValue = Environment.GetEnvironmentVariable("LEIKWAN_THEME");
        if (!string.IsNullOrWhiteSpace(overrideValue))
            Preference = ParsePreference(overrideValue);
        if (!_listening)
        {
            _listening = true;
            try { SystemEvents.UserPreferenceChanged += OnUserPreferenceChanged; }
            catch (Exception exception) { Log.Debug(exception, "theme change notifications unavailable"); }
        }
        Apply();
    }

    public static void SetPreference(ThemePreference preference)
    {
        Preference = preference;
        try
        {
            Directory.CreateDirectory(Path.GetDirectoryName(PreferencePath)!);
            File.WriteAllText(PreferencePath, preference.ToString());
        }
        catch (Exception exception)
        {
            Log.Debug(exception, "theme preference could not be saved");
        }
        Apply();
    }

    public static ThemePreference ParsePreference(string? value)
        => Enum.TryParse<ThemePreference>(value?.Trim(), true, out var parsed) ? parsed : ThemePreference.System;

    /// <summary>True when Windows apps are set to dark mode.</summary>
    public static bool IsSystemDark()
    {
        try
        {
            using var key = Registry.CurrentUser.OpenSubKey(@"Software\Microsoft\Windows\CurrentVersion\Themes\Personalize");
            return key?.GetValue("AppsUseLightTheme") is int value && value == 0;
        }
        catch
        {
            return false;
        }
    }

    public static void Apply()
    {
        var dark = Preference switch
        {
            ThemePreference.Dark => true,
            ThemePreference.Light => false,
            _ => IsSystemDark(),
        };
        var application = System.Windows.Application.Current;
        if (application is null)
            return;
        if (!application.Dispatcher.CheckAccess())
        {
            application.Dispatcher.BeginInvoke(Apply);
            return;
        }
        var merged = application.Resources.MergedDictionaries;
        var palette = new ResourceDictionary
        {
            Source = new Uri("pack://application:,,,/LeikwanBridge.App;component/Resources/Themes/Colors." + (dark ? "Dark" : "Light") + ".xaml", UriKind.Absolute),
        };
        var index = -1;
        for (var i = 0; i < merged.Count; i++)
        {
            if ((merged[i].Source?.ToString() ?? string.Empty).Contains(PaletteMarker, StringComparison.Ordinal))
            {
                index = i;
                break;
            }
        }
        if (index >= 0)
            merged[index] = palette;
        else
            merged.Insert(0, palette);
        IsDark = dark;
        foreach (Window window in application.Windows)
            ApplyTitleBar(window);
        ThemeChanged?.Invoke(dark);
    }

    /// <summary>Dark or light caption for <paramref name="window"/> (Windows 10 2004+ / 11).</summary>
    public static void ApplyTitleBar(Window window)
    {
        try
        {
            var handle = new WindowInteropHelper(window).Handle;
            if (handle == IntPtr.Zero)
                return;
            var enabled = IsDark ? 1 : 0;
            if (DwmSetWindowAttribute(handle, DwmUseImmersiveDarkMode, ref enabled, sizeof(int)) != 0)
                DwmSetWindowAttribute(handle, DwmUseImmersiveDarkModeLegacy, ref enabled, sizeof(int));
        }
        catch (Exception exception)
        {
            Log.Debug(exception, "title bar theme not applied");
        }
    }

    private static ThemePreference LoadPreference()
    {
        try
        {
            return File.Exists(PreferencePath) ? ParsePreference(File.ReadAllText(PreferencePath)) : ThemePreference.System;
        }
        catch
        {
            return ThemePreference.System;
        }
    }

    private static void OnUserPreferenceChanged(object sender, UserPreferenceChangedEventArgs e)
    {
        if (Preference == ThemePreference.System && e.Category is UserPreferenceCategory.General or UserPreferenceCategory.Color)
            Apply();
    }

    private const int DwmUseImmersiveDarkMode = 20;
    private const int DwmUseImmersiveDarkModeLegacy = 19;

    [DllImport("dwmapi.dll")]
    private static extern int DwmSetWindowAttribute(IntPtr hwnd, int attribute, ref int value, int size);
}
