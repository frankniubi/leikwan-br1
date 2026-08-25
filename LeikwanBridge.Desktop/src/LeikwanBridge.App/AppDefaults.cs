using System.IO;
using LeikwanBridge.Core.Services;

namespace LeikwanBridge.App;

public static class AppDefaults
{
    public const string DefaultMasterUrl = "https://v3.leikwanhost.com";
    public const string LegacyMasterUrl = "https://v3-mirror.leikwanhost.com";

    public static string NormalizeMasterUrl(string? value)
    {
        var normalized = (value ?? string.Empty).Trim().TrimEnd('/');
        return string.Equals(normalized, LegacyMasterUrl, StringComparison.OrdinalIgnoreCase)
            ? DefaultMasterUrl
            : normalized;
    }

    public static string GetInstallRoot()
    {
        return ResolveInstallRoot();
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
        return Path.Combine(programFiles, "LeiKwan Bridge");
    }
}
