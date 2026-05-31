using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed class WindowsAppInstallState
{
    [JsonPropertyName("install_mode")]
    public string InstallMode { get; set; } = "";

    [JsonPropertyName("install_dir")]
    public string InstallDir { get; set; } = "";

    [JsonPropertyName("desktop_shortcut")]
    public bool? DesktopShortcut { get; set; }

    [JsonPropertyName("display_name")]
    public string DisplayName { get; set; } = "";

    [JsonPropertyName("installed_version")]
    public string InstalledVersion { get; set; } = "";

    [JsonPropertyName("installed_at")]
    public string InstalledAt { get; set; } = "";
}
