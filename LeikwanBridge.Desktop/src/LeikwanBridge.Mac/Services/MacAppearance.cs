using System.Text.Json;
using Avalonia;
using Avalonia.Styling;

namespace LeikwanBridge.Mac.Services;

/// <summary>
/// Theme choice: follow macOS, or the fixed day / night brand palette (Resources/Palette.axaml).
/// There are exactly two palettes; nothing is derived from the wallpaper or system accent.
/// </summary>
public static class MacAppearance
{
    public const string System = "system";
    public const string Day = "day";
    public const string Night = "night";

    public static IReadOnlyList<string> Modes { get; } = [System, Day, Night];

    public static string Normalize(string? mode)
        => mode?.Trim().ToLowerInvariant() switch
        {
            Day or "light" => Day,
            Night or "dark" => Night,
            _ => System,
        };

    public static ThemeVariant Variant(string? mode) => Normalize(mode) switch
    {
        Day => ThemeVariant.Light,
        Night => ThemeVariant.Dark,
        _ => ThemeVariant.Default,
    };

    /// <summary>Applies the mode to the running app (no-op without one, e.g. in tests).</summary>
    public static void Apply(string? mode)
    {
        if (Application.Current is { } app)
            app.RequestedThemeVariant = Variant(mode);
    }
}

/// <summary>
/// Mac-only UI preferences (currently the theme), kept next to config.json so the shared
/// Core AgentConfig does not change for one platform's window setting.
/// </summary>
public sealed class MacUiPreferencesStore
{
    private readonly string _path;

    public MacUiPreferencesStore(string path)
    {
        _path = path;
    }

    public string LoadThemeMode()
    {
        try
        {
            if (!File.Exists(_path) || new FileInfo(_path).Length > 16 * 1024)
                return MacAppearance.System;
            using var document = JsonDocument.Parse(File.ReadAllText(_path));
            return document.RootElement.TryGetProperty("theme", out var theme) && theme.ValueKind == JsonValueKind.String
                ? MacAppearance.Normalize(theme.GetString())
                : MacAppearance.System;
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException or JsonException)
        {
            return MacAppearance.System;
        }
    }

    public async Task SaveThemeModeAsync(string mode, CancellationToken ct = default)
    {
        var directory = Path.GetDirectoryName(_path);
        if (!string.IsNullOrEmpty(directory))
            Directory.CreateDirectory(directory);
        var temp = _path + ".tmp";
        await File.WriteAllTextAsync(temp, JsonSerializer.Serialize(new { theme = MacAppearance.Normalize(mode) }), ct).ConfigureAwait(false);
        File.Move(temp, _path, overwrite: true);
    }
}
