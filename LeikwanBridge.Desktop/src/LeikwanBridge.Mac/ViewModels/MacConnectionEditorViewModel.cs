using CommunityToolkit.Mvvm.ComponentModel;
using LeikwanBridge.Mac.Services;

namespace LeikwanBridge.Mac.ViewModels;

public sealed partial class MacConnectionEditorViewModel : ObservableObject
{
    public IReadOnlyList<string> ProtocolOptions { get; } = MacConnectionProtocol.Supported;
    public IReadOnlyList<string> ShadowsocksMethodOptions { get; } =
    [
        "aes-256-gcm",
        "chacha20-ietf-poly1305",
        "2022-blake3-aes-128-gcm",
        "2022-blake3-aes-256-gcm",
        "2022-blake3-chacha20-poly1305",
    ];
    public IReadOnlyList<string> VlessTransportOptions { get; } = ["tcp", "ws", "grpc"];

    [ObservableProperty] private int _id;
    [ObservableProperty] private string _name = "";
    [ObservableProperty] private string _protocol = MacConnectionProtocol.Shadowsocks;
    [ObservableProperty] private string _server = "";
    [ObservableProperty] private string _portText = "443";
    [ObservableProperty] private string _method = "aes-256-gcm";
    [ObservableProperty] private string _password = "";
    [ObservableProperty] private string _uuid = "";
    [ObservableProperty] private string _flow = "";
    [ObservableProperty] private string _transport = "tcp";
    [ObservableProperty] private string _host = "";
    [ObservableProperty] private string _path = "/";
    [ObservableProperty] private string _serviceName = "";
    [ObservableProperty] private bool _tls;
    [ObservableProperty] private string _serverName = "";
    [ObservableProperty] private string _realityPublicKey = "";
    [ObservableProperty] private string _realityShortId = "";
    [ObservableProperty] private string _privateKey = "";
    [ObservableProperty] private string _localAddress = "";
    [ObservableProperty] private string _peerPublicKey = "";
    [ObservableProperty] private string _preSharedKey = "";
    [ObservableProperty] private string _allowedIps = "0.0.0.0/0, ::/0";
    [ObservableProperty] private string _mtuText = "1420";
    [ObservableProperty] private string _keepaliveText = "25";

    public bool IsShadowsocks => string.Equals(Protocol, MacConnectionProtocol.Shadowsocks, StringComparison.Ordinal);
    public bool IsVless => string.Equals(Protocol, MacConnectionProtocol.Vless, StringComparison.Ordinal);
    public bool IsWireGuard => string.Equals(Protocol, MacConnectionProtocol.WireGuard, StringComparison.Ordinal);
    public bool IsVlessWebSocket => IsVless && string.Equals(Transport, "ws", StringComparison.Ordinal);
    public bool IsVlessGrpc => IsVless && string.Equals(Transport, "grpc", StringComparison.Ordinal);

    partial void OnProtocolChanged(string value)
    {
        OnPropertyChanged(nameof(IsShadowsocks));
        OnPropertyChanged(nameof(IsVless));
        OnPropertyChanged(nameof(IsWireGuard));
        OnPropertyChanged(nameof(IsVlessWebSocket));
        OnPropertyChanged(nameof(IsVlessGrpc));
    }

    partial void OnTransportChanged(string value)
    {
        OnPropertyChanged(nameof(IsVlessWebSocket));
        OnPropertyChanged(nameof(IsVlessGrpc));
    }

    public void Load(MacConnectionProfile? profile)
    {
        var source = profile ?? new MacConnectionProfile();
        Id = source.Id;
        Name = source.Name;
        Protocol = MacConnectionProtocol.IsSupported(source.Protocol)
            ? source.Protocol.Trim().ToLowerInvariant()
            : MacConnectionProtocol.Shadowsocks;
        Server = source.Server;
        PortText = source.Port > 0 ? source.Port.ToString(System.Globalization.CultureInfo.InvariantCulture) : "443";
        Method = string.IsNullOrWhiteSpace(source.Method) ? "aes-256-gcm" : source.Method;
        Password = source.Password;
        Uuid = source.Uuid;
        Flow = source.Flow;
        Transport = VlessTransportOptions.Contains(source.Transport, StringComparer.OrdinalIgnoreCase)
            ? source.Transport.ToLowerInvariant()
            : "tcp";
        Host = source.Host;
        Path = string.IsNullOrWhiteSpace(source.Path) ? "/" : source.Path;
        ServiceName = source.ServiceName;
        Tls = source.Tls;
        ServerName = source.ServerName;
        RealityPublicKey = source.RealityPublicKey;
        RealityShortId = source.RealityShortId;
        PrivateKey = source.PrivateKey;
        LocalAddress = source.LocalAddress;
        PeerPublicKey = source.PeerPublicKey;
        PreSharedKey = source.PreSharedKey;
        AllowedIps = source.AllowedIps;
        MtuText = source.Mtu.ToString(System.Globalization.CultureInfo.InvariantCulture);
        KeepaliveText = source.PersistentKeepalive.ToString(System.Globalization.CultureInfo.InvariantCulture);
    }

    public MacConnectionProfile ToProfile()
    {
        return new MacConnectionProfile
        {
            Id = Id,
            Name = Name.Trim(),
            Protocol = Protocol.Trim().ToLowerInvariant(),
            Server = Server.Trim(),
            Port = ParseNumber(PortText, 443),
            Method = Method.Trim(),
            Password = Password,
            Uuid = Uuid.Trim(),
            Flow = Flow.Trim(),
            Transport = Transport.Trim().ToLowerInvariant(),
            Host = Host.Trim(),
            Path = Path.Trim(),
            ServiceName = ServiceName.Trim(),
            Tls = Tls,
            ServerName = ServerName.Trim(),
            RealityPublicKey = RealityPublicKey.Trim(),
            RealityShortId = RealityShortId.Trim(),
            PrivateKey = PrivateKey.Trim(),
            LocalAddress = LocalAddress.Trim(),
            PeerPublicKey = PeerPublicKey.Trim(),
            PreSharedKey = PreSharedKey.Trim(),
            AllowedIps = AllowedIps.Trim(),
            Mtu = ParseNumber(MtuText, 1420),
            PersistentKeepalive = ParseNumber(KeepaliveText, 25),
        };
    }

    private static int ParseNumber(string value, int fallback)
        => int.TryParse(value?.Trim(), System.Globalization.NumberStyles.Integer,
            System.Globalization.CultureInfo.InvariantCulture, out var parsed)
            ? parsed
            : fallback;
}
