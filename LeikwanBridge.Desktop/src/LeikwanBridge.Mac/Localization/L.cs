using System.Globalization;
using Avalonia;
using Avalonia.Markup.Xaml.Styling;

namespace LeikwanBridge.Mac.Localization;

/// <summary>
/// UI text for the active language. Every user-visible string lives in
/// Resources/Strings.axaml (English, the base) and Resources/Strings.zh-CN.axaml (overlay);
/// views bind them with DynamicResource, code reads them through <see cref="T"/>. The English
/// fallback argument keeps view models usable without a running Avalonia application (tests),
/// and LocalizationTests checks every key used from code exists in both dictionaries with the
/// same English text.
/// </summary>
public static class L
{
    public const string English = "en-US";
    public const string SimplifiedChinese = "zh-CN";

    private static ResourceInclude? _overlay;

    public static string Language { get; private set; } = English;

    public static string Normalize(string? language)
        => string.Equals(language, SimplifiedChinese, StringComparison.OrdinalIgnoreCase)
            || (language?.StartsWith("zh", StringComparison.OrdinalIgnoreCase) ?? false)
            ? SimplifiedChinese
            : English;

    /// <summary>The localized text for <paramref name="key"/>, or <paramref name="english"/> when no app is running.</summary>
    public static string T(string key, string english)
    {
        object? value = null;
        return Application.Current?.TryGetResource(key, null, out value) == true && value is string text
            ? text
            : english;
    }

    /// <summary><see cref="T"/> with string.Format placeholders ({0}, {1}...).</summary>
    public static string F(string key, string english, params object?[] args)
        => string.Format(CultureInfo.CurrentCulture, T(key, english), args);

    /// <summary>Switches the string overlay; DynamicResource bindings update live.</summary>
    public static void Apply(string? language)
    {
        Language = Normalize(language);
        var app = Application.Current;
        if (app is null)
            return;
        var merged = app.Resources.MergedDictionaries;
        if (_overlay is not null)
        {
            merged.Remove(_overlay);
            _overlay = null;
        }
        if (Language == SimplifiedChinese)
        {
            _overlay = new ResourceInclude(new Uri("avares://LeikwanBridge.Mac/App.axaml"))
            {
                Source = new Uri("avares://LeikwanBridge.Mac/Resources/Strings.zh-CN.axaml"),
            };
            merged.Add(_overlay);
        }
        try
        {
            CultureInfo.CurrentUICulture = new CultureInfo(Language);
        }
        catch (CultureNotFoundException)
        {
        }
    }
}
