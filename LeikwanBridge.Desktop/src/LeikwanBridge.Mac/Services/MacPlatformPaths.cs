using LeikwanBridge.Core.Services.Platform;

namespace LeikwanBridge.Mac.Services;

public sealed class MacPlatformPaths : IClientPlatformPaths
{
    public MacPlatformPaths(string homeDirectory, string bundleDirectory, bool ensureDirectories = true)
    {
        if (string.IsNullOrWhiteSpace(homeDirectory))
            throw new ArgumentException("home directory required", nameof(homeDirectory));
        if (string.IsNullOrWhiteSpace(bundleDirectory))
            throw new ArgumentException("bundle directory required", nameof(bundleDirectory));

        HomeDirectory = homeDirectory.TrimEnd('/', '\\');
        Root = Join(HomeDirectory, "Library", "Application Support", "LeiKwan", "ClientAgent");
        ConfigPath = Join(Root, "config.json");
        StateDirectory = Join(Root, "state");
        RuntimeDirectory = Join(Root, "runtime");
        LogDirectory = Join(HomeDirectory, "Library", "Logs", "LeiKwan", "ClientAgent");
        BundleDirectory = bundleDirectory.TrimEnd('/', '\\');
        BinDirectory = Join(BundleDirectory, "Contents", "Resources", "bin");
        if (ensureDirectories)
            EnsureDirectories();
    }

    public string HomeDirectory { get; }
    public string Root { get; }
    public string ConfigPath { get; }
    public string StateDirectory { get; }
    public string RuntimeDirectory { get; }
    public string LogDirectory { get; }
    public string BundleDirectory { get; }
    public string BinDirectory { get; }

    public void EnsureDirectories()
    {
        foreach (var path in new[] { Root, StateDirectory, RuntimeDirectory, LogDirectory })
        {
            Directory.CreateDirectory(path);
            if (!OperatingSystem.IsWindows())
            {
                File.SetUnixFileMode(
                    path,
                    UnixFileMode.UserRead | UnixFileMode.UserWrite | UnixFileMode.UserExecute);
            }
        }
    }

    private static string Join(string root, params string[] segments)
    {
        return root.TrimEnd('/', '\\') + "/" + string.Join('/', segments.Select(segment => segment.Trim('/', '\\')));
    }
}
