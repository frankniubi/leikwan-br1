using System;
using System.IO;

namespace LeikwanBridge.App.Services;

public static class BrandingAssets
{
    public static string ResolveTrayIconSource(string appBaseDirectory, string executablePath)
    {
        var packagedIcon = Path.GetFullPath(Path.Combine(appBaseDirectory, "Resources", "Icons", "leikwan-host.ico"));
        if (File.Exists(packagedIcon))
        {
            return packagedIcon;
        }

        return Path.GetFullPath(executablePath);
    }
}
