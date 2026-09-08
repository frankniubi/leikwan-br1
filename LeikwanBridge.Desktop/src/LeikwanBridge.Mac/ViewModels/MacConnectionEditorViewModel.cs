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
    [ObservableProperty] private int _id;
    [ObservableProperty] private string _name = "";
    [ObservableProperty] private string _protocol = MacConnectionProtocol.Shadowsocks;
    [ObservableProperty] private string _server = "";
    [ObservableProperty] private string _portText = "443";
    [ObservableProperty] private string _method = "aes-256-gcm";
    [ObservableProperty] private string _password = "";
    [ObservableProperty] private string _privateKey = "";
    [ObservableProperty] private string _localAddress = "";
    [ObservableProperty] private string _peerPublicKey = "";
    [ObservableProperty] private string _preSharedKey = "";
    [ObservableProperty] private string _allowedIps = "0.0.0.0/0, ::/0";
    [ObservableProperty] private string _mtuText = "1420";
    [ObservableProperty] private string _keepaliveText = "25";

    public bool IsShadowsocks => string.Equals(Protocol, MacConnectionProtocol.Shadowsocks, StringComparison.Ordinal);
    public bool IsWireGuard => string.Equals(Protocol, MacConnectionProtocol.WireGuard, StringComparison.Ordinal);
    public bool IsExternal => string.Equals(Protocol, MacConnectionProtocol.External, StringComparison.Ordinal);

    partial void OnProtocolChanged(string value)
    {
        var normalized = value?.Trim().ToLowerInvariant() ?? string.Empty;
        if (!MacConnectionProtocol.IsSupported(normalized))
        {
            if (!string.Equals(value, MacConnectionProtocol.Shadowsocks, StringComparison.Ordinal))
                Protocol = MacConnectionProtocol.Shadowsocks;
            return;
        }
        OnPropertyChanged(nameof(IsShadowsocks));
        OnPropertyChanged(nameof(IsWireGuard));
        OnPropertyChanged(nameof(IsExternal));
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
            Port = int.TryParse(PortText.Trim(), out var port) && port is >= 1 and <= 65535
                ? port : throw new ArgumentException("connection_port_invalid"),
            Method = Method.Trim(),
            Password = Password,
            PrivateKey = PrivateKey.Trim(),
            LocalAddress = LocalAddress.Trim(),
            PeerPublicKey = PeerPublicKey.Trim(),
            PreSharedKey = PreSharedKey.Trim(),
            AllowedIps = AllowedIps.Trim(),
            Mtu = ParseNumber(MtuText, 1420),
            PersistentKeepalive = ParseNumber(KeepaliveText, 25),
        };
    }

    public bool TryImport(string? uri, out string error)
    {
        if (MacConnectionUriParser.TryParse(uri, out var profile, out error))
        {
            Load(profile);
            return true;
        }
        return false;
    }

    private static int ParseNumber(string value, int fallback)
        => int.TryParse(value?.Trim(), System.Globalization.NumberStyles.Integer,
            System.Globalization.CultureInfo.InvariantCulture, out var parsed)
            ? parsed
            : fallback;
}
