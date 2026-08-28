using System.Security.Cryptography;
using System.Text.RegularExpressions;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;

namespace LeikwanBridge.Mac.Services;

public sealed partial class MacPlatformIdentityProvider
{
    private readonly MacPlatformPaths _paths;
    private readonly Func<string> _deviceName;
    private readonly object _lock = new();

    public MacPlatformIdentityProvider(MacPlatformPaths paths, Func<string>? deviceName = null)
    {
        _paths = paths;
        _deviceName = deviceName ?? (() => Environment.MachineName);
    }

    public ClientPlatformIdentity GetOrCreate()
    {
        lock (_lock)
        {
            var deviceId = LoadOrCreateDeviceId();
            return new ClientPlatformIdentity(
                "macos-desktop",
                deviceId,
                _deviceName(),
                "Leikwan-Mac-GUI",
                MasterApiClient.AgentVersion,
                SupportsMptcp: false);
        }
    }

    private string LoadOrCreateDeviceId()
    {
        var path = _paths.Root.TrimEnd('/', '\\') + "/device-id";
        if (File.Exists(path))
        {
            var existing = File.ReadAllText(path).Trim();
            if (DeviceIdPattern().IsMatch(existing))
                return existing;
        }

        var value = "macos-client-" + Convert.ToHexString(RandomNumberGenerator.GetBytes(16)).ToLowerInvariant();
        var temporary = path + "." + Guid.NewGuid().ToString("N") + ".tmp";
        File.WriteAllText(temporary, value + Environment.NewLine);
        SetPrivateFileMode(temporary);
        File.Move(temporary, path, overwrite: true);
        SetPrivateFileMode(path);
        return value;
    }

    private static void SetPrivateFileMode(string path)
    {
        if (!OperatingSystem.IsWindows())
            File.SetUnixFileMode(path, UnixFileMode.UserRead | UnixFileMode.UserWrite);
    }

    [GeneratedRegex("^macos-client-[a-f0-9]{32}$", RegexOptions.CultureInvariant)]
    private static partial Regex DeviceIdPattern();
}
