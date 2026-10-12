using System.Globalization;
using System.Net;
using System.Text;

namespace LeikwanBridge.Core.Nodes;

/// <summary>
/// Share-link parse and format for the user's own nodes (ss://, socks5:// / socks://, vless://).
///
/// Parsing follows the Android ProxyUriParser and the panel's HostedNodeSpec:
/// <list type="bullet">
/// <item>Shadowsocks: SIP002 with base64url or percent-encoded userinfo, or the legacy
/// whole-authority base64 form; the first colon splits method and password (2022 PSKs
/// may contain colons); <c>plugin=name;opts</c> and <c>uot</c> / <c>udp_over_tcp</c>.</item>
/// <item>SOCKS5: optional <c>user:pass@</c> (percent-encoded) or v2rayN's base64 userinfo.</item>
/// <item>VLESS: query and fragment are decoded exactly once (a ws path such as
/// <c>/ray?ed=2048</c> survives), IPv6 brackets are stripped, <c>type=raw|none</c> means tcp.</item>
/// </list>
/// Percent-decoding keeps '+' literal, like the panel (which stores the node), so what
/// the device shows is what the cloud stores.
///
/// <see cref="Format"/> renders the canonical link the panel accepts and returns
/// (same parameter names as HostedNodeSpec::toShareUri); it is the inverse of <see cref="TryParse"/>.
///
/// Error codes are stable and never contain the link or a credential.
/// </summary>
public static class UserNodeShareLink
{
    public const int MaxUriLength = 4096;

    public static UserNode Parse(string uri)
        => TryParse(uri, out var node, out var error) ? node : throw new FormatException(error);

    public static bool TryParse(string? uri, out UserNode node, out string error)
    {
        node = new UserNode();
        if (string.IsNullOrWhiteSpace(uri))
        {
            error = "node_uri_required";
            return false;
        }
        var trimmed = uri.Trim();
        if (trimmed.Length > MaxUriLength || trimmed.Any(char.IsControl))
        {
            error = "node_uri_invalid";
            return false;
        }
        try
        {
            var schemeEnd = trimmed.IndexOf("://", StringComparison.Ordinal);
            var scheme = schemeEnd > 0 ? trimmed[..schemeEnd].ToLowerInvariant() : string.Empty;
            node = scheme switch
            {
                "ss" => ParseShadowsocks(trimmed[(schemeEnd + 3)..]),
                "socks" or "socks5" => ParseSocks(trimmed[(schemeEnd + 3)..]),
                "vless" => ParseVless(trimmed[(schemeEnd + 3)..]),
                _ => throw new FormatException("node_protocol_unsupported"),
            };
            if (string.IsNullOrWhiteSpace(node.Name))
                node.Name = node.DisplayEndpoint;
            error = string.Empty;
            return true;
        }
        catch (FormatException exception) when (exception.Message.StartsWith("node_", StringComparison.Ordinal))
        {
            error = exception.Message;
            return false;
        }
        catch
        {
            error = "node_uri_invalid";
            return false;
        }
    }

    /// <summary>
    /// Parses a pasted block: one link, several links on separate lines, or a base64
    /// subscription body. Lines that are not supported links are skipped.
    /// </summary>
    public static IReadOnlyList<UserNode> ParseMany(string? text)
    {
        if (string.IsNullOrWhiteSpace(text))
            return [];
        var source = text.Trim();
        if (!source.Contains("://", StringComparison.Ordinal) && TryDecodeBase64(source, out var decoded))
            source = decoded;
        var result = new List<UserNode>();
        foreach (var line in source.Split(['\r', '\n'], StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries))
        {
            if (TryParse(line, out var node, out _))
                result.Add(node);
        }
        return result;
    }

    /// <summary>Canonical share link of a node; null when the node cannot be expressed as one.</summary>
    public static string? Format(UserNode node)
    {
        ArgumentNullException.ThrowIfNull(node);
        var server = StripBrackets(node.Server.Trim());
        if (server.Length == 0 || node.Port is < 1 or > 65535)
            return null;
        var authority = (server.Contains(':') ? $"[{server}]" : server) + ":" + node.Port.ToString(CultureInfo.InvariantCulture);
        var fragment = "#" + Encode(string.IsNullOrEmpty(node.Name) ? node.DisplayEndpoint : node.Name);
        switch (UserNodeProtocol.Normalize(node.Protocol))
        {
            case UserNodeProtocol.Shadowsocks:
            {
                var method = node.Method.Trim();
                if (method.Length == 0 || node.Password.Length == 0)
                    return null;
                var userInfo = Convert.ToBase64String(Encoding.UTF8.GetBytes(method + ":" + node.Password))
                    .TrimEnd('=').Replace('+', '-').Replace('/', '_');
                var query = new List<string>();
                var plugin = NormalizePlugin(node.Plugin);
                if (plugin.Length > 0)
                {
                    var opts = node.PluginOpts.Trim();
                    query.Add("plugin=" + Encode(opts.Length == 0 ? plugin : plugin + ";" + opts));
                }
                if (node.UdpOverTcp)
                    query.Add("uot=1");
                return "ss://" + userInfo + "@" + authority + (query.Count == 0 ? "" : "?" + string.Join("&", query)) + fragment;
            }
            case UserNodeProtocol.Socks5:
            {
                var auth = node.Username.Length > 0 ? Encode(node.Username) + ":" + Encode(node.Password) + "@" : "";
                return "socks5://" + auth + authority + fragment;
            }
            case UserNodeProtocol.Vless:
            {
                var uuid = node.Uuid.Trim();
                if (uuid.Length == 0)
                    return null;
                var transport = NormalizeTransport(node.Transport);
                var security = NormalizeSecurity(node.Security);
                var pairs = new List<KeyValuePair<string, string>>
                {
                    new("encryption", "none"),
                    new("type", transport),
                    new("security", security),
                };
                void Add(string key, string value)
                {
                    if (!string.IsNullOrWhiteSpace(value))
                        pairs.Add(new(key, value.Trim()));
                }
                Add("flow", node.Flow);
                if (security != "none")
                {
                    Add("sni", node.Sni);
                    Add("fp", node.Fingerprint);
                }
                if (security == "reality")
                {
                    Add("pbk", node.PublicKey);
                    Add("sid", node.ShortId);
                }
                if (transport is "ws" or "httpupgrade")
                {
                    Add("path", node.Path);
                    Add("host", node.Host);
                }
                else if (transport == "grpc")
                {
                    Add("serviceName", node.ServiceName);
                }
                if (security != "none")
                {
                    Add("alpn", node.Alpn);
                    if (node.AllowInsecure && security == "tls")
                        pairs.Add(new("allowInsecure", "1"));
                }
                var query = string.Join("&", pairs.Select(pair => pair.Key + "=" + Encode(pair.Value)));
                return "vless://" + Encode(uuid) + "@" + authority + "?" + query + fragment;
            }
            default:
                return null;
        }
    }

    public static string NormalizePlugin(string? plugin)
    {
        var value = (plugin ?? string.Empty).Trim().ToLowerInvariant();
        return value == "simple-obfs" ? "obfs-local" : value;
    }

    public static string NormalizeTransport(string? transport)
    {
        var value = (transport ?? string.Empty).Trim().ToLowerInvariant();
        return value is "" or "raw" or "none" ? "tcp" : value;
    }

    public static string NormalizeSecurity(string? security)
    {
        var value = (security ?? string.Empty).Trim().ToLowerInvariant();
        return value.Length == 0 ? "none" : value;
    }

    // ------------------------------------------------------------------ parsers

    private static UserNode ParseShadowsocks(string rest)
    {
        var (main, fragment) = SplitOnce(rest, '#');
        var (authority, query) = SplitOnce(main, '?');
        authority = authority.TrimEnd('/');
        string credentials;
        string hostPort;
        var at = authority.LastIndexOf('@');
        if (at >= 0)
        {
            var userInfo = authority[..at];
            hostPort = authority[(at + 1)..];
            credentials = TryDecodeBase64(userInfo, out var decoded) && decoded.Contains(':') && !decoded.Any(char.IsControl)
                ? decoded
                : Decode(userInfo);
        }
        else
        {
            // Legacy form: ss://BASE64(method:password@host:port)
            if (!TryDecodeBase64(Decode(authority), out var decoded))
                throw new FormatException("node_uri_invalid");
            var legacyAt = decoded.LastIndexOf('@');
            if (legacyAt < 0)
                throw new FormatException("node_uri_invalid");
            credentials = decoded[..legacyAt];
            hostPort = decoded[(legacyAt + 1)..];
        }
        var colon = credentials.IndexOf(':');
        if (colon <= 0)
            throw new FormatException("node_password_invalid");
        var (server, port) = SplitHostPort(hostPort);
        var node = new UserNode
        {
            Protocol = UserNodeProtocol.Shadowsocks,
            Name = Decode(fragment),
            Server = server,
            Port = port,
            Method = credentials[..colon].Trim().ToLowerInvariant(),
            Password = credentials[(colon + 1)..],
        };
        foreach (var (key, value) in QueryPairs(query))
        {
            switch (key)
            {
                case "plugin":
                {
                    var (name, opts) = SplitOnce(value, ';');
                    node.Plugin = NormalizePlugin(name);
                    node.PluginOpts = node.Plugin.Length == 0 ? "" : opts.Trim();
                    break;
                }
                case "plugin_opts":
                    if (value.Trim().Length > 0)
                        node.PluginOpts = value.Trim();
                    break;
                case "uot":
                case "udp_over_tcp":
                    node.UdpOverTcp = Truthy(value);
                    break;
            }
        }
        if (node.Method.Length == 0 || node.Password.Length == 0)
            throw new FormatException("node_password_invalid");
        return node;
    }

    private static UserNode ParseSocks(string rest)
    {
        var (main, fragment) = SplitOnce(rest, '#');
        main = SplitOnce(main, '?').Before.TrimEnd('/');
        var username = string.Empty;
        var password = string.Empty;
        var hostPort = main;
        var at = main.LastIndexOf('@');
        if (at >= 0)
        {
            var userInfo = main[..at];
            hostPort = main[(at + 1)..];
            if (!userInfo.Contains(':') && TryDecodeBase64(userInfo, out var decoded)
                && decoded.Contains(':') && !decoded.Any(char.IsControl))
            {
                // v2rayN form: socks://BASE64(user:pass)@host:port
                var split = decoded.IndexOf(':');
                username = decoded[..split];
                password = decoded[(split + 1)..];
            }
            else
            {
                var (rawUser, rawPass) = SplitOnce(userInfo, ':');
                username = Decode(rawUser);
                password = Decode(rawPass);
            }
        }
        if (username.Length == 0 && password.Length > 0)
            throw new FormatException("node_username_invalid");
        var (server, port) = SplitHostPort(hostPort);
        return new UserNode
        {
            Protocol = UserNodeProtocol.Socks5,
            Name = Decode(fragment),
            Server = server,
            Port = port,
            Username = username,
            Password = password,
        };
    }

    private static UserNode ParseVless(string rest)
    {
        var (main, fragment) = SplitOnce(rest, '#');
        var (authority, query) = SplitOnce(main, '?');
        authority = authority.TrimEnd('/');
        var at = authority.LastIndexOf('@');
        if (at <= 0)
            throw new FormatException("node_uuid_invalid");
        var uuid = Decode(authority[..at]).Trim();
        if (!Guid.TryParseExact(uuid, "D", out _))
            throw new FormatException("node_uuid_invalid");
        var (server, port) = SplitHostPort(authority[(at + 1)..]);
        var parameters = new Dictionary<string, string>(StringComparer.Ordinal);
        foreach (var (key, value) in QueryPairs(query))
            parameters[key] = value;
        string Value(params string[] keys)
        {
            foreach (var key in keys)
            {
                if (parameters.TryGetValue(key, out var value))
                    return value.Trim();
            }
            return string.Empty;
        }
        var encryption = Value("encryption").ToLowerInvariant();
        if (encryption is not ("" or "none"))
            throw new FormatException("node_encryption_unsupported");
        var security = NormalizeSecurity(Value("security"));
        if (!UserNodeProtocol.VlessSecurities.Contains(security, StringComparer.Ordinal))
            throw new FormatException("node_security_unsupported");
        var transport = NormalizeTransport(Value("type"));
        if (!UserNodeProtocol.VlessTransports.Contains(transport, StringComparer.Ordinal))
            throw new FormatException("node_transport_unsupported");
        return new UserNode
        {
            Protocol = UserNodeProtocol.Vless,
            Name = Decode(fragment),
            Server = server,
            Port = port,
            Uuid = uuid.ToLowerInvariant(),
            Flow = Value("flow").ToLowerInvariant(),
            Security = security,
            Sni = Value("sni", "peer"),
            Fingerprint = Value("fp").ToLowerInvariant(),
            Alpn = Value("alpn").ToLowerInvariant(),
            AllowInsecure = Truthy(Value("allowInsecure", "insecure", "allow_insecure")),
            PublicKey = Value("pbk"),
            ShortId = Value("sid").ToLowerInvariant(),
            Transport = transport,
            Path = Value("path"),
            Host = Value("host"),
            ServiceName = Value("serviceName", "servicename", "service_name"),
        };
    }

    // ------------------------------------------------------------------ helpers

    private static (string Before, string After) SplitOnce(string value, char separator)
    {
        var index = value.IndexOf(separator);
        return index < 0 ? (value, string.Empty) : (value[..index], value[(index + 1)..]);
    }

    private static IEnumerable<(string Key, string Value)> QueryPairs(string query)
    {
        foreach (var pair in query.Split('&', StringSplitOptions.RemoveEmptyEntries))
        {
            var (key, value) = SplitOnce(pair, '=');
            var decodedKey = Decode(key).Trim();
            if (decodedKey.Length > 0)
                yield return (decodedKey, Decode(value));
        }
    }

    private static (string Server, int Port) SplitHostPort(string hostPort)
    {
        hostPort = hostPort.Trim().TrimEnd('/');
        string host;
        string portText;
        if (hostPort.StartsWith('['))
        {
            var close = hostPort.IndexOf(']');
            if (close < 0 || close + 1 >= hostPort.Length || hostPort[close + 1] != ':')
                throw new FormatException("node_server_invalid");
            host = hostPort[1..close];
            portText = hostPort[(close + 2)..];
        }
        else
        {
            var colon = hostPort.LastIndexOf(':');
            if (colon <= 0)
                throw new FormatException("node_port_invalid");
            host = hostPort[..colon];
            portText = hostPort[(colon + 1)..];
        }
        host = Decode(host).Trim();
        if (host.Length == 0 || host.Any(char.IsWhiteSpace))
            throw new FormatException("node_server_invalid");
        if (!int.TryParse(portText, NumberStyles.None, CultureInfo.InvariantCulture, out var port) || port is < 1 or > 65535)
            throw new FormatException("node_port_invalid");
        if (IPAddress.TryParse(host, out var address))
            host = address.ToString();
        return (host.ToLowerInvariant(), port);
    }

    private static string StripBrackets(string value)
        => value.StartsWith('[') && value.EndsWith(']') ? value[1..^1] : value;

    private static string Decode(string value)
    {
        if (value.Length == 0 || value.IndexOf('%') < 0)
            return value;
        try { return Uri.UnescapeDataString(value); }
        catch (UriFormatException) { return value; }
    }

    private static string Encode(string value) => Uri.EscapeDataString(value);

    private static bool Truthy(string value)
        => value.Trim().ToLowerInvariant() is "1" or "true" or "yes" or "on";

    internal static bool TryDecodeBase64(string value, out string decoded)
    {
        decoded = string.Empty;
        var normalized = value.Trim().Replace('-', '+').Replace('_', '/');
        if (normalized.Length == 0 || normalized.Any(c => !(char.IsAsciiLetterOrDigit(c) || c is '+' or '/' or '=')))
            return false;
        normalized = normalized.TrimEnd('=');
        normalized = normalized.PadRight(normalized.Length + (4 - normalized.Length % 4) % 4, '=');
        try
        {
            var bytes = Convert.FromBase64String(normalized);
            decoded = new UTF8Encoding(false, true).GetString(bytes);
            return true;
        }
        catch
        {
            return false;
        }
    }
}
