using System.Globalization;
using CommunityToolkit.Mvvm.ComponentModel;
using LeikwanBridge.Core.Nodes;
using LeikwanBridge.Mac.Services;

namespace LeikwanBridge.Mac.ViewModels;

/// <summary>
/// In-place editor for a Shadowsocks / SOCKS5 / VLESS node on the Nodes page. The protocol
/// is fixed (delete and re-import to change it). Secrets (password, UUID) are never shown:
/// the fields start empty and a blank field keeps the stored value, a filled one replaces it.
/// A hosted node is saved through the panel (write-through, every device); a local one stays here.
/// </summary>
public sealed partial class NodeEditorViewModel : ObservableObject
{
    private UserNode? _original;

    public string Key { get; private set; } = string.Empty;

    [ObservableProperty, NotifyPropertyChangedFor(nameof(IsShadowsocks), nameof(IsSocks5), nameof(IsVless))]
    private string _protocol = UserNodeProtocol.Shadowsocks;

    [ObservableProperty] private bool _isOpen;
    [ObservableProperty] private bool _isHosted;
    [ObservableProperty] private string _name = string.Empty;
    [ObservableProperty] private string _server = string.Empty;
    [ObservableProperty] private string _portText = string.Empty;
    [ObservableProperty] private string _method = string.Empty;
    [ObservableProperty] private string _plugin = string.Empty;
    [ObservableProperty] private string _pluginOpts = string.Empty;
    [ObservableProperty] private string _username = string.Empty;
    [ObservableProperty] private string _password = string.Empty;
    [ObservableProperty] private string _uuid = string.Empty;
    [ObservableProperty] private string _security = "none";
    [ObservableProperty] private string _sni = string.Empty;
    [ObservableProperty] private string _fingerprint = string.Empty;
    [ObservableProperty] private string _publicKey = string.Empty;
    [ObservableProperty] private string _shortId = string.Empty;
    [ObservableProperty] private string _flow = string.Empty;
    [ObservableProperty] private string _transport = "tcp";
    [ObservableProperty] private string _path = string.Empty;
    [ObservableProperty] private string _host = string.Empty;
    [ObservableProperty] private string _serviceName = string.Empty;
    [ObservableProperty] private string _error = string.Empty;

    public bool IsShadowsocks => Protocol == UserNodeProtocol.Shadowsocks;
    public bool IsSocks5 => Protocol == UserNodeProtocol.Socks5;
    public bool IsVless => Protocol == UserNodeProtocol.Vless;

    public IReadOnlyList<string> MethodOptions => UserNodeProtocol.CloudShadowsocksMethods;
    public IReadOnlyList<string> PluginOptions { get; } = ["", .. UserNodeProtocol.ShadowsocksPlugins];
    public IReadOnlyList<string> SecurityOptions => UserNodeProtocol.VlessSecurities;
    public IReadOnlyList<string> TransportOptions => UserNodeProtocol.VlessTransports;

    public void Open(string key, UserNode node, bool hosted)
    {
        _original = node.Clone();
        Key = key;
        Protocol = UserNodeProtocol.Normalize(node.Protocol) is { Length: > 0 } protocol ? protocol : UserNodeProtocol.Shadowsocks;
        IsHosted = hosted;
        Name = node.Name;
        Server = node.Server;
        PortText = node.Port > 0 ? node.Port.ToString(CultureInfo.InvariantCulture) : string.Empty;
        Method = node.Method;
        Plugin = node.Plugin;
        PluginOpts = node.PluginOpts;
        Username = node.Username;
        Security = string.IsNullOrWhiteSpace(node.Security) ? "none" : node.Security;
        Sni = node.Sni;
        Fingerprint = node.Fingerprint;
        PublicKey = node.PublicKey;
        ShortId = node.ShortId;
        Flow = node.Flow;
        Transport = string.IsNullOrWhiteSpace(node.Transport) ? "tcp" : node.Transport;
        Path = node.Path;
        Host = node.Host;
        ServiceName = node.ServiceName;
        // Secrets are re-entered or left blank to keep the stored value.
        Password = string.Empty;
        Uuid = string.Empty;
        Error = string.Empty;
        IsOpen = true;
    }

    public void Close()
    {
        IsOpen = false;
        _original = null;
        Password = string.Empty;
        Uuid = string.Empty;
        Error = string.Empty;
    }

    /// <summary>The edited node; throws <see cref="NodeLibraryException"/> for invalid input.</summary>
    public UserNode Build()
    {
        var node = _original?.Clone() ?? throw new NodeLibraryException("node_not_found");
        if (!int.TryParse(PortText?.Trim(), NumberStyles.None, CultureInfo.InvariantCulture, out var port)
            || port is < 1 or > 65535)
            throw new NodeLibraryException("proxy_port_invalid");
        if (string.IsNullOrWhiteSpace(Server))
            throw new NodeLibraryException("proxy_endpoint_invalid");
        node.Name = Name?.Trim() ?? string.Empty;
        node.Server = Server.Trim().Trim('[', ']');
        node.Port = port;
        if (!string.IsNullOrEmpty(Password))
            node.Password = Password;
        switch (Protocol)
        {
            case UserNodeProtocol.Shadowsocks:
                node.Method = Method?.Trim() ?? string.Empty;
                node.Plugin = Plugin?.Trim() ?? string.Empty;
                node.PluginOpts = string.IsNullOrEmpty(node.Plugin) ? string.Empty : PluginOpts?.Trim() ?? string.Empty;
                break;
            case UserNodeProtocol.Socks5:
                node.Username = Username?.Trim() ?? string.Empty;
                if (string.IsNullOrEmpty(node.Username))
                    node.Password = string.Empty;
                break;
            case UserNodeProtocol.Vless:
                if (!string.IsNullOrWhiteSpace(Uuid))
                    node.Uuid = Uuid.Trim();
                node.Security = Security?.Trim() ?? "none";
                node.Sni = Sni?.Trim() ?? string.Empty;
                node.Fingerprint = Fingerprint?.Trim() ?? string.Empty;
                node.PublicKey = PublicKey?.Trim() ?? string.Empty;
                node.ShortId = ShortId?.Trim() ?? string.Empty;
                node.Flow = Flow?.Trim() ?? string.Empty;
                node.Transport = Transport?.Trim() ?? "tcp";
                node.Path = Path?.Trim() ?? string.Empty;
                node.Host = Host?.Trim() ?? string.Empty;
                node.ServiceName = ServiceName?.Trim() ?? string.Empty;
                break;
        }
        // The canonical share link is what gets stored and uploaded: it must round-trip.
        string? link;
        try
        {
            link = UserNodeShareLink.Format(node);
        }
        catch (Exception exception) when (exception is ArgumentException or InvalidOperationException or FormatException)
        {
            throw new NodeLibraryException("hosted_node_invalid");
        }
        if (string.IsNullOrEmpty(link))
            throw new NodeLibraryException("hosted_node_invalid");
        if (!UserNodeShareLink.TryParse(link, out _, out var parseError))
            throw new NodeLibraryException(string.IsNullOrEmpty(parseError) ? "hosted_node_invalid" : parseError);
        return node;
    }
}
