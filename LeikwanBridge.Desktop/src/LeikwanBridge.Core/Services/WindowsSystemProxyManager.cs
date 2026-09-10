using Microsoft.Win32;
using System.Runtime.InteropServices;

#pragma warning disable CA1416

namespace LeikwanBridge.Core.Services;

public interface IWindowsSystemProxyManager
{
    bool IsEnabled { get; }
    Task EnableAsync(int port, CancellationToken ct = default);
    Task DisableAsync(CancellationToken ct = default);
}

/// <summary>
/// Owns the WinINet per-user proxy setting and restores the exact previous
/// values when the local connection stops.  It intentionally does not touch
/// WinHTTP or machine-wide policy.
/// </summary>
public sealed class WindowsSystemProxyManager : IWindowsSystemProxyManager
{
    private const string InternetSettingsPath = @"Software\Microsoft\Windows\CurrentVersion\Internet Settings";
    private ProxyState? _previous;
    private string? _ownedServer;

    public bool IsEnabled { get; private set; }

    public Task EnableAsync(int port, CancellationToken ct = default)
    {
        ct.ThrowIfCancellationRequested();
        if (!OperatingSystem.IsWindows())
            throw new PlatformNotSupportedException("windows_system_proxy_required");
        if (port is < 1 or > 65535)
            throw new ArgumentOutOfRangeException(nameof(port));

        using var key = Registry.CurrentUser.OpenSubKey(InternetSettingsPath, writable: true)
            ?? throw new InvalidOperationException("windows_proxy_registry_unavailable");
        var server = BuildProxyServer(port);
        _previous ??= ProxyState.Capture(key);
        key.SetValue("ProxyEnable", 1, RegistryValueKind.DWord);
        key.SetValue("ProxyServer", server, RegistryValueKind.String);
        key.SetValue("ProxyOverride", "<local>", RegistryValueKind.String);
        _ownedServer = server;
        IsEnabled = true;
        NotifyWinInet();
        return Task.CompletedTask;
    }

    public Task DisableAsync(CancellationToken ct = default)
    {
        ct.ThrowIfCancellationRequested();
        if (!OperatingSystem.IsWindows())
        {
            IsEnabled = false;
            _previous = null;
            _ownedServer = null;
            return Task.CompletedTask;
        }

        using var key = Registry.CurrentUser.OpenSubKey(InternetSettingsPath, writable: true);
        if (key is not null && _previous is not null)
        {
            var currentServer = key.GetValue("ProxyServer")?.ToString() ?? string.Empty;
            var currentEnabled = Convert.ToInt32(key.GetValue("ProxyEnable", 0) ?? 0);
            // Do not overwrite a proxy changed by another application while we
            // were running.
            if (string.Equals(currentServer, _ownedServer, StringComparison.OrdinalIgnoreCase)
                && currentEnabled != 0)
                _previous.Restore(key);
        }
        _previous = null;
        _ownedServer = null;
        IsEnabled = false;
        NotifyWinInet();
        return Task.CompletedTask;
    }

    public static string BuildProxyServer(int port) => $"127.0.0.1:{port}";

    private static void NotifyWinInet()
    {
        InternetSetOption(IntPtr.Zero, 39, IntPtr.Zero, 0); // SETTINGS_CHANGED
        InternetSetOption(IntPtr.Zero, 37, IntPtr.Zero, 0); // REFRESH
    }

    [DllImport("wininet.dll", SetLastError = true)]
    private static extern bool InternetSetOption(IntPtr hInternet, int option, IntPtr buffer, int length);

    private sealed record ProxyState(int Enabled, string Server, string Override)
    {
        public static ProxyState Capture(RegistryKey key)
            => new(
                Convert.ToInt32(key.GetValue("ProxyEnable", 0) ?? 0),
                key.GetValue("ProxyServer")?.ToString() ?? string.Empty,
                key.GetValue("ProxyOverride")?.ToString() ?? string.Empty);

        public void Restore(RegistryKey key)
        {
            key.SetValue("ProxyEnable", Enabled, RegistryValueKind.DWord);
            if (string.IsNullOrEmpty(Server))
                key.DeleteValue("ProxyServer", false);
            else
                key.SetValue("ProxyServer", Server, RegistryValueKind.String);
            if (string.IsNullOrEmpty(Override))
                key.DeleteValue("ProxyOverride", false);
            else
                key.SetValue("ProxyOverride", Override, RegistryValueKind.String);
        }
    }
}

#pragma warning restore CA1416
