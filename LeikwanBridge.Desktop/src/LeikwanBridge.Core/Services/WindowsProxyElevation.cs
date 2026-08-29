using System.ComponentModel;
using System.Diagnostics;
using System.Globalization;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services;

public sealed record WindowsProxyStartRequest(
    WindowsProxyMode Mode,
    int ProfileId,
    string GameId);

public static class WindowsProxyElevation
{
    public const string ProxyStartArgument = "--leikwan-proxy-start";

    public static ProcessStartInfo BuildRelaunchStartInfo(
        string executablePath,
        WindowsProxyStartRequest request)
    {
        if (string.IsNullOrWhiteSpace(executablePath))
            throw new ArgumentException("executable_path_required", nameof(executablePath));
        if (request.ProfileId <= 0)
            throw new ArgumentOutOfRangeException(nameof(request), "proxy_profile_required");

        var startInfo = new ProcessStartInfo
        {
            FileName = executablePath,
            UseShellExecute = true,
            Verb = "runas",
            WorkingDirectory = Path.GetDirectoryName(executablePath) ?? AppContext.BaseDirectory,
        };
        startInfo.ArgumentList.Add(ProxyStartArgument);
        startInfo.ArgumentList.Add(request.Mode.ToString());
        startInfo.ArgumentList.Add(request.ProfileId.ToString(CultureInfo.InvariantCulture));
        startInfo.ArgumentList.Add(request.GameId?.Trim() ?? string.Empty);
        return startInfo;
    }

    public static bool TryParseProxyStartRequest(
        IReadOnlyList<string>? args,
        out WindowsProxyStartRequest request)
    {
        request = default!;
        if (args is null)
            return false;

        for (var index = 0; index < args.Count; index++)
        {
            if (!string.Equals(args[index], ProxyStartArgument, StringComparison.OrdinalIgnoreCase))
                continue;
            if (index + 2 >= args.Count
                || !Enum.TryParse<WindowsProxyMode>(args[index + 1], true, out var mode)
                || mode != WindowsProxyMode.Process
                || !int.TryParse(args[index + 2], NumberStyles.None, CultureInfo.InvariantCulture, out var profileId)
                || profileId <= 0)
            {
                return false;
            }

            var gameId = index + 3 < args.Count
                ? args[index + 3]?.Trim() ?? string.Empty
                : string.Empty;
            request = new WindowsProxyStartRequest(mode, profileId, gameId);
            return true;
        }

        return false;
    }

    public static bool IsElevationCancelled(Exception exception)
        => exception is Win32Exception { NativeErrorCode: 1223 };

    public static bool IsElevated()
    {
        if (!OperatingSystem.IsWindows())
            return true;

        try
        {
            using var identity = System.Security.Principal.WindowsIdentity.GetCurrent();
            var principal = new System.Security.Principal.WindowsPrincipal(identity);
            return principal.IsInRole(System.Security.Principal.WindowsBuiltInRole.Administrator);
        }
        catch
        {
            return false;
        }
    }
}
