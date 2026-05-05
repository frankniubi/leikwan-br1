using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services;

public sealed class WindowsAppUpdateService
{
    public static bool IsRemoteVersionNewer(string remoteVersion, string localVersion)
    {
        if (!Version.TryParse(remoteVersion, out var remote))
            return false;
        if (!Version.TryParse(localVersion, out var local))
            return false;
        return remote > local;
    }

    public static string BuildCachedInstallerPath(string installRoot, string version)
    {
        return Path.Combine(installRoot, "updates", $"leikwan-bridge-setup-{version}.exe");
    }
}
