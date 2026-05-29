using System.IO;

namespace LeikwanBridge.App;

public static class AppDefaults
{
    public const string DefaultMasterUrl = "https://v3-mirror.leikwanhost.com";
    public const string LegacyMasterUrl = "https://v3.leikwanhost.com";

    public static string GetInstallRoot()
    {
        var overridePath = Environment.GetEnvironmentVariable("LEIKWAN_INSTALL_DIR");
        if (!string.IsNullOrWhiteSpace(overridePath))
            return overridePath.Trim();

        var programFiles = Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles);
        return Path.Combine(programFiles, "LeiKwan Bridge");
    }
}
