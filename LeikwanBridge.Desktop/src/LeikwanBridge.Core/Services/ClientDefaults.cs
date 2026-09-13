using System.IO;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Values every client surface must agree on: the panel endpoints a fresh
/// client starts with, and the directory the managed runtime is installed into.
/// The desktop shells (GUI, no-GUI) and the installers all read them from here,
/// so a client can never point at a different panel or a different install root
/// than the updater that replaces it.
/// </summary>
public static class ClientDefaults
{
    public const string DefaultMasterUrl = "https://v3.leikwanhost.com";
    public const string MainlandMasterUrl = "https://api-leikwanbridge.nbwly.com";
    public const string LegacyMasterUrl = "https://v3-mirror.leikwanhost.com";
    public const string InstallDirectoryName = "LeiKwan Bridge";

    public static string NormalizeMasterUrl(string? value)
    {
        var normalized = (value ?? string.Empty).Trim().TrimEnd('/');
        return string.Equals(normalized, LegacyMasterUrl, StringComparison.OrdinalIgnoreCase)
            ? DefaultMasterUrl
            : normalized;
    }

    public static string ResolveInstallRoot(string? installStatePathOverride = null)
    {
        var overridePath = Environment.GetEnvironmentVariable("LEIKWAN_INSTALL_DIR");
        if (!string.IsNullOrWhiteSpace(overridePath))
            return overridePath.Trim();

        var installStatePath = installStatePathOverride
            ?? Path.Combine(AppContext.BaseDirectory, "install-state.json");
        var installRoot = Path.GetDirectoryName(installStatePath);
        if (!string.IsNullOrWhiteSpace(installRoot))
        {
            var state = WindowsAppUpdateService.LoadInstallState(installRoot);
            if (state is not null && !string.IsNullOrWhiteSpace(state.InstallDir))
                return state.InstallDir.Trim();
        }

        var programFiles = Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles);
        return Path.Combine(programFiles, InstallDirectoryName);
    }

    /// <summary>The per-machine state directory shared by every client surface.</summary>
    public static string ResolveSharedStateDirectory()
        => Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
            "Leikwan",
            "ClientAgent");
}
