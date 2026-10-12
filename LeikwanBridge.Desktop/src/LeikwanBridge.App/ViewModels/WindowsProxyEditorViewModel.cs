using System.Globalization;
using CommunityToolkit.Mvvm.ComponentModel;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;

namespace LeikwanBridge.App.ViewModels;

public sealed partial class WindowsProxyEditorViewModel : ObservableObject
{
    public IReadOnlyList<string> ProtocolOptions { get; } = WindowsProxyProtocol.Supported;
    public IReadOnlyList<string> ShadowsocksMethodOptions { get; } = WindowsProxyProtocol.ShadowsocksMethodOptions;
    public IReadOnlyList<string> VlessTransportOptions { get; } = WindowsProxyProtocol.VlessTransportOptions;
    public IReadOnlyList<int> PortOptions { get; } = [80, 443, 8080, 8443, 2080, 30526];
    public IReadOnlyList<int> LocalListenPortOptions { get; } = [2080, 2081, 1080, 7890, 7891, 8888];
    public IReadOnlyList<WindowsProxyMode> ModeOptions { get; } =
        [WindowsProxyMode.Http, WindowsProxyMode.Tun, WindowsProxyMode.Process];

    [ObservableProperty] private int _id;
    [ObservableProperty] private string _name = "";
    [ObservableProperty] private string _protocol = WindowsProxyProtocol.Shadowsocks;
    [ObservableProperty] private string _server = "";
    [ObservableProperty] private string _portText = "443";
    [ObservableProperty] private string _method = "aes-256-gcm";
    [ObservableProperty] private string _password = "";
    [ObservableProperty] private bool _udpOverTcp;
    [ObservableProperty] private string _uuid = "";
    [ObservableProperty] private string _flow = "";
    [ObservableProperty] private string _transport = "tcp";
    [ObservableProperty] private string _host = "";
    [ObservableProperty] private string _path = "/";
    [ObservableProperty] private string _serviceName = "";
    [ObservableProperty] private bool _tls;
    [ObservableProperty] private string _serverName = "";
    [ObservableProperty] private string _fingerprint = "";
    [ObservableProperty] private string _realityPublicKey = "";
    [ObservableProperty] private string _realityShortId = "";
    [ObservableProperty] private string _alpn = "";
    [ObservableProperty] private bool _allowInsecure;
    [ObservableProperty] private string _username = "";
    [ObservableProperty] private string _plugin = "";
    [ObservableProperty] private string _pluginOpts = "";

    /// <summary>Panel node id of the loaded profile (0 = local only); kept so an edit stays the same hosted node.</summary>
    [ObservableProperty] private long _hostedId;

    private bool _enabled = true;

    public IReadOnlyList<string> ShadowsocksPluginOptions { get; } = WindowsProxyProtocol.ShadowsocksPluginOptions;
    public bool IsSocks5 => WindowsProxyProtocol.Normalize(Protocol) == WindowsProxyProtocol.Socks5;
    public bool IsHosted => HostedId > 0;

    partial void OnHostedIdChanged(long value) => OnPropertyChanged(nameof(IsHosted));
    [ObservableProperty] private WindowsProxyMode _selectedMode = WindowsProxyMode.Http;

    public bool IsShadowsocks => WindowsProxyProtocol.IsShadowsocks(Protocol);
    public bool IsShadowsocks2022 => string.Equals(
        WindowsProxyProtocol.Normalize(Protocol),
        WindowsProxyProtocol.Shadowsocks2022,
        StringComparison.Ordinal);
    public bool IsVless => string.Equals(
        WindowsProxyProtocol.Normalize(Protocol),
        WindowsProxyProtocol.Vless,
        StringComparison.Ordinal);
    public bool IsVlessWebSocket => IsVless && Transport is "ws" or "httpupgrade";
    public bool IsVlessGrpc => IsVless && string.Equals(Transport, "grpc", StringComparison.Ordinal);

    partial void OnProtocolChanged(string value)
    {
        var normalized = WindowsProxyProtocol.Normalize(value);
        if (!WindowsProxyProtocol.IsSupported(normalized))
            Protocol = WindowsProxyProtocol.Shadowsocks;
        else
            Protocol = normalized;

        if (IsShadowsocks2022 && !WindowsProxyProtocol.IsShadowsocks2022Method(Method))
            Method = "2022-blake3-aes-256-gcm";
        else if (!IsShadowsocks2022 && WindowsProxyProtocol.IsShadowsocks2022Method(Method))
            Method = "aes-256-gcm";
        PublishProtocolState();
    }

    partial void OnTransportChanged(string value)
    {
        var normalized = value?.Trim().ToLowerInvariant() ?? "tcp";
        Transport = WindowsProxyProtocol.VlessTransportOptions.Contains(normalized, StringComparer.Ordinal)
            ? normalized
            : "tcp";
        OnPropertyChanged(nameof(IsVlessWebSocket));
        OnPropertyChanged(nameof(IsVlessGrpc));
    }

    public void Load(WindowsProxyProfile? source)
    {
        source ??= new WindowsProxyProfile();
        Id = source.Id;
        Name = source.Name;
        Protocol = WindowsProxyProtocol.IsSupported(source.Protocol)
            ? WindowsProxyProtocol.Normalize(source.Protocol)
            : WindowsProxyProtocol.Shadowsocks;
        Server = source.Server;
        PortText = source.Port > 0 ? source.Port.ToString(CultureInfo.InvariantCulture) : "443";
        Method = string.IsNullOrWhiteSpace(source.Method)
            ? (string.Equals(Protocol, WindowsProxyProtocol.Shadowsocks2022, StringComparison.Ordinal)
                ? "2022-blake3-aes-256-gcm"
                : "aes-256-gcm")
            : source.Method;
        Password = source.Password;
        UdpOverTcp = source.UdpOverTcp;
        Uuid = source.Uuid;
        Flow = source.Flow;
        Transport = WindowsProxyProtocol.VlessTransportOptions.Contains(source.Transport, StringComparer.OrdinalIgnoreCase)
            ? source.Transport.ToLowerInvariant()
            : "tcp";
        Host = source.Host;
        Path = string.IsNullOrWhiteSpace(source.Path) ? "/" : source.Path;
        ServiceName = source.ServiceName;
        Tls = source.Tls;
        ServerName = source.ServerName;
        Fingerprint = source.Fingerprint;
        RealityPublicKey = source.RealityPublicKey;
        RealityShortId = source.RealityShortId;
        Alpn = source.Alpn;
        AllowInsecure = source.AllowInsecure;
        Username = source.Username;
        Plugin = source.Plugin;
        PluginOpts = source.PluginOpts;
        HostedId = source.HostedId;
        _enabled = source.Enabled;
        PublishProtocolState();
    }

    public WindowsProxyProfile ToProfile()
    {
        var protocol = WindowsProxyProtocol.Normalize(Protocol);
        var method = Method.Trim();
        if (protocol == WindowsProxyProtocol.Shadowsocks2022
            && !WindowsProxyProtocol.IsShadowsocks2022Method(method))
        {
            method = "2022-blake3-aes-256-gcm";
        }
        else if (protocol != WindowsProxyProtocol.Shadowsocks2022
            && WindowsProxyProtocol.IsShadowsocks2022Method(method))
        {
            method = "aes-256-gcm";
        }

        return new WindowsProxyProfile
        {
            Id = Id,
            Name = Name.Trim(),
            Protocol = protocol,
            Server = Server.Trim(),
            Port = ParseNumber(PortText, 443),
            Method = method,
            Password = Password,
            UdpOverTcp = UdpOverTcp,
            Uuid = Uuid.Trim(),
            Flow = Flow.Trim(),
            Transport = Transport.Trim().ToLowerInvariant(),
            Host = Host.Trim(),
            Path = string.IsNullOrWhiteSpace(Path) ? "/" : Path.Trim(),
            ServiceName = ServiceName.Trim(),
            Tls = Tls,
            ServerName = ServerName.Trim(),
            Fingerprint = Fingerprint.Trim(),
            RealityPublicKey = RealityPublicKey.Trim(),
            RealityShortId = RealityShortId.Trim(),
            Alpn = Alpn.Trim(),
            AllowInsecure = AllowInsecure,
            Username = IsSocks5 ? Username.Trim() : string.Empty,
            Plugin = IsShadowsocks ? Plugin.Trim().ToLowerInvariant() : string.Empty,
            PluginOpts = IsShadowsocks && !string.IsNullOrWhiteSpace(Plugin) ? PluginOpts.Trim() : string.Empty,
            HostedId = HostedId,
            Enabled = _enabled,
        };
    }

    public bool TryImport(string? uri, out string error)
    {
        if (WindowsProxyUriParser.TryParse(uri, out var profile, out error))
        {
            Load(profile);
            return true;
        }
        return false;
    }

    private void PublishProtocolState()
    {
        OnPropertyChanged(nameof(IsShadowsocks));
        OnPropertyChanged(nameof(IsShadowsocks2022));
        OnPropertyChanged(nameof(IsVless));
        OnPropertyChanged(nameof(IsSocks5));
        OnPropertyChanged(nameof(IsVlessWebSocket));
        OnPropertyChanged(nameof(IsVlessGrpc));
    }

    private static int ParseNumber(string? value, int fallback)
        => int.TryParse(value?.Trim(), NumberStyles.Integer, CultureInfo.InvariantCulture, out var parsed)
            ? parsed
            : fallback;
}
