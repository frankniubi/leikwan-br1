using System.Net;
using System.Text;
using System.Text.Json;

namespace LeikwanBridge.Mac.Services;

/// <summary>
/// Parses the URI forms supported by the macOS connection editor.
/// Provider material is kept in the returned profile and never in an error.
/// </summary>
public static class MacConnectionUriParser
{
    private const int MaxUriLength = 16 * 1024;

    public static MacConnectionProfile Parse(string uri)
    {
        if (!TryParse(uri, out var profile, out var error))
            throw new FormatException(error);
        return profile;
    }

    public static bool TryParse(
        string? uri,
        out MacConnectionProfile profile,
        out string error)
    {
        profile = new MacConnectionProfile();
        error = "proxy_uri_invalid";
        if (string.IsNullOrWhiteSpace(uri))
        {
            error = "proxy_uri_required";
            return false;
        }

        var trimmed = uri.Trim();
        if (trimmed.Length > MaxUriLength || trimmed.Any(char.IsControl))
            return false;

        try
        {
            if (trimmed.StartsWith("ss://", StringComparison.OrdinalIgnoreCase))
                profile = ParseShadowsocks(trimmed);
            else if (trimmed.StartsWith("wg://", StringComparison.OrdinalIgnoreCase))
                profile = ParseWireGuardUri(trimmed);
            else if (LooksLikeWireGuardConfig(trimmed))
                profile = ParseWireGuardConfig(trimmed, null);
            else
            {
                error = "proxy_protocol_unsupported";
                return false;
            }

            error = string.Empty;
            return true;
        }
        catch (FormatException exception)
        {
            error = exception.Message;
            return false;
        }
        catch (JsonException)
        {
            error = "proxy_uri_invalid";
            return false;
        }
        catch
        {
            error = "proxy_uri_invalid";
            return false;
        }
    }

    private static MacConnectionProfile ParseShadowsocks(string uri)
    {
        var body = uri[(uri.IndexOf("://", StringComparison.Ordinal) + 3)..];
        var name = ExtractFragment(ref body);
        var query = ExtractQuery(ref body);
        var at = body.LastIndexOf('@');
        string userInfo;
        string hostPort;
        if (at >= 0)
        {
            userInfo = body[..at];
            hostPort = body[(at + 1)..];
        }
        else
        {
            var decodedBody = DecodeBase64OrComponent(body.TrimEnd('/'));
            at = decodedBody.LastIndexOf('@');
            if (at < 0)
                throw new FormatException("proxy_credentials_invalid");
            userInfo = decodedBody[..at];
            hostPort = decodedBody[(at + 1)..];
        }

        var credentials = DecodeShadowsocksCredentials(userInfo);
        var endpoint = ParseHostPort(hostPort);
        var queryValues = ParseQuery(query);
        if (queryValues.TryGetValue("plugin", out var plugin)
            && !string.IsNullOrWhiteSpace(plugin))
            throw new FormatException("proxy_plugin_unsupported");

        return new MacConnectionProfile
        {
            Name = string.IsNullOrWhiteSpace(name) ? $"{endpoint.Host}:{endpoint.Port}" : name,
            Protocol = MacConnectionProtocol.Shadowsocks,
            Server = endpoint.Host,
            Port = endpoint.Port,
            Method = credentials.Method,
            Password = credentials.Password,
        };
    }

    private static MacConnectionProfile ParseWireGuardUri(string uri)
    {
        var body = uri[(uri.IndexOf("://", StringComparison.Ordinal) + 3)..];
        var name = ExtractFragment(ref body);
        _ = ExtractQuery(ref body);
        var payload = DecodeBase64OrComponent(body.TrimEnd('/'));
        if (!LooksLikeWireGuardConfig(payload))
        {
            var json = payload.Trim();
            if (json.StartsWith("{", StringComparison.Ordinal))
                return ParseWireGuardJson(json, name);
            throw new FormatException("proxy_wg_config_invalid");
        }
        return ParseWireGuardConfig(payload, name);
    }

    private static MacConnectionProfile ParseWireGuardConfig(string config, string? explicitName)
    {
        var (interfaceFields, peers) = ParseWireGuardIni(config);
        var privateKey = Value(interfaceFields, "privatekey");
        var localAddress = Value(interfaceFields, "address");
        var peer = peers.FirstOrDefault();
        if (string.IsNullOrWhiteSpace(privateKey)
            || string.IsNullOrWhiteSpace(localAddress)
            || peer is null)
            throw new FormatException("proxy_wg_config_invalid");

        var endpoint = ParseEndpoint(Value(peer, "endpoint"));
        var publicKey = Value(peer, "publickey");
        if (string.IsNullOrWhiteSpace(publicKey))
            throw new FormatException("proxy_wg_config_invalid");

        var mtu = ParseOptionalNumber(Value(interfaceFields, "mtu"), 1420);
        var keepalive = ParseOptionalNumber(Value(peer, "persistentkeepalive"), 25);
        return new MacConnectionProfile
        {
            Name = string.IsNullOrWhiteSpace(explicitName)
                ? $"WireGuard {endpoint.Host}:{endpoint.Port}"
                : explicitName,
            Protocol = MacConnectionProtocol.WireGuard,
            Server = endpoint.Host,
            Port = endpoint.Port,
            PrivateKey = privateKey,
            PeerPublicKey = publicKey,
            PreSharedKey = Value(peer, "presharedkey"),
            LocalAddress = localAddress,
            AllowedIps = Value(peer, "allowedips", "0.0.0.0/0, ::/0"),
            Mtu = mtu,
            PersistentKeepalive = keepalive,
        };
    }

    private static MacConnectionProfile ParseWireGuardJson(string json, string? explicitName)
    {
        using var document = JsonDocument.Parse(json);
        var root = document.RootElement;
        var peer = root.TryGetProperty("peers", out var peers)
            && peers.ValueKind == JsonValueKind.Array
            && peers.GetArrayLength() > 0
            ? peers[0]
            : root;
        var server = StringProperty(peer, "address", "server")
            ?? StringProperty(root, "server");
        var port = IntProperty(peer, "port", "server_port")
            ?? IntProperty(root, "port", "server_port");
        var privateKey = StringProperty(root, "private_key", "privateKey");
        var publicKey = StringProperty(peer, "public_key", "publicKey");
        var localAddress = StringOrArrayProperty(root, "address", "addresses");
        if (string.IsNullOrWhiteSpace(privateKey)
            || string.IsNullOrWhiteSpace(publicKey)
            || string.IsNullOrWhiteSpace(server)
            || port is null
            || string.IsNullOrWhiteSpace(localAddress))
            throw new FormatException("proxy_wg_config_invalid");

        var allowedIps = StringOrArrayProperty(peer, "allowed_ips", "allowedIps");
        return new MacConnectionProfile
        {
            Name = explicitName
                ?? StringProperty(root, "name")
                ?? $"WireGuard {server}:{port.Value}",
            Protocol = MacConnectionProtocol.WireGuard,
            Server = server,
            Port = port.Value,
            PrivateKey = privateKey,
            PeerPublicKey = publicKey,
            PreSharedKey = StringProperty(peer, "pre_shared_key", "preSharedKey") ?? string.Empty,
            LocalAddress = localAddress,
            AllowedIps = allowedIps ?? "0.0.0.0/0, ::/0",
            Mtu = IntProperty(root, "mtu") ?? 1420,
            PersistentKeepalive = IntProperty(peer, "persistent_keepalive_interval", "persistentKeepalive") ?? 25,
        };
    }

    private static (string Method, string Password) DecodeShadowsocksCredentials(string value)
    {
        var candidates = new List<string>();
        if (TryDecodeBase64(value, out var decoded))
            candidates.Add(decoded);
        var unescaped = DecodeComponent(value);
        if (!string.Equals(unescaped, value, StringComparison.Ordinal))
        {
            if (TryDecodeBase64(unescaped, out var escapedDecoded))
                candidates.Add(escapedDecoded);
            candidates.Add(unescaped);
        }
        candidates.Add(value);

        foreach (var candidate in candidates)
        {
            var colon = candidate.IndexOf(':');
            if (colon > 0)
                return (candidate[..colon].Trim(), candidate[(colon + 1)..]);
            if (string.Equals(candidate.Trim(), "none", StringComparison.OrdinalIgnoreCase))
                return ("none", string.Empty);
        }
        throw new FormatException("proxy_credentials_invalid");
    }

    private static (string Host, int Port) ParseHostPort(string value)
    {
        var hostPort = DecodeComponent(value).Trim().TrimEnd('/');
        string host;
        string portText;
        if (hostPort.StartsWith("[", StringComparison.Ordinal))
        {
            var close = hostPort.IndexOf(']');
            if (close <= 1 || close + 1 >= hostPort.Length || hostPort[close + 1] != ':')
                throw new FormatException("proxy_endpoint_invalid");
            host = hostPort[1..close];
            portText = hostPort[(close + 2)..];
        }
        else
        {
            var colon = hostPort.LastIndexOf(':');
            if (colon <= 0 || colon == hostPort.Length - 1 || hostPort[..colon].Contains(':'))
                throw new FormatException("proxy_endpoint_invalid");
            host = hostPort[..colon];
            portText = hostPort[(colon + 1)..];
        }

        if (!int.TryParse(portText, out var port) || port is < 1 or > 65535)
            throw new FormatException("proxy_port_invalid");
        if (!IsValidHost(host))
            throw new FormatException("proxy_endpoint_invalid");
        return (host, port);
    }

    private static (string Host, int Port) ParseEndpoint(string value)
    {
        if (string.IsNullOrWhiteSpace(value))
            throw new FormatException("proxy_endpoint_invalid");
        return ParseHostPort(value);
    }

    private static bool IsValidHost(string host)
        => !string.IsNullOrWhiteSpace(host)
            && host.Length <= 255
            && !host.Any(char.IsWhiteSpace)
            && (IPAddress.TryParse(host, out _)
                || Uri.CheckHostName(host) is UriHostNameType.Dns);

    private static string ExtractFragment(ref string value)
    {
        var index = value.IndexOf('#');
        if (index < 0)
            return string.Empty;
        var fragment = value[(index + 1)..];
        value = value[..index];
        return DecodeComponent(fragment);
    }

    private static string ExtractQuery(ref string value)
    {
        var index = value.IndexOf('?');
        if (index < 0)
            return string.Empty;
        var query = value[(index + 1)..];
        value = value[..index];
        return query;
    }

    private static Dictionary<string, string> ParseQuery(string query)
    {
        var result = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        foreach (var pair in query.Split('&', StringSplitOptions.RemoveEmptyEntries))
        {
            var separator = pair.IndexOf('=');
            var key = separator < 0 ? pair : pair[..separator];
            var value = separator < 0 ? string.Empty : pair[(separator + 1)..];
            if (key.Length > 0)
                result[DecodeComponent(key).ToLowerInvariant()] = DecodeComponent(value);
        }
        return result;
    }

    private static string DecodeBase64OrComponent(string value)
    {
        if (TryDecodeBase64(value, out var decoded))
            return decoded;
        return DecodeComponent(value);
    }

    private static bool TryDecodeBase64(string value, out string decoded)
    {
        decoded = string.Empty;
        var candidate = DecodeComponent(value).Trim();
        if (candidate.Length == 0 || candidate.Length % 4 == 1)
            return false;
        candidate = candidate.Replace('-', '+').Replace('_', '/');
        candidate = candidate.PadRight(candidate.Length + ((4 - candidate.Length % 4) % 4), '=');
        try
        {
            var bytes = Convert.FromBase64String(candidate);
            var text = Encoding.UTF8.GetString(bytes);
            if (text.Length == 0 || text.Any(character => character < 0x20 && character is not '\r' and not '\n' and not '\t'))
                return false;
            decoded = text;
            return true;
        }
        catch (FormatException)
        {
            return false;
        }
    }

    private static string DecodeComponent(string value)
    {
        try { return Uri.UnescapeDataString(value.Replace("+", "%2B", StringComparison.Ordinal)); }
        catch { throw new FormatException("proxy_uri_invalid"); }
    }

    private static bool LooksLikeWireGuardConfig(string value)
    {
        var trimmed = value.TrimStart();
        return trimmed.StartsWith("[Interface]", StringComparison.OrdinalIgnoreCase)
            && trimmed.Contains("[Peer]", StringComparison.OrdinalIgnoreCase);
    }

    private static (Dictionary<string, string> Interface, List<Dictionary<string, string>> Peers) ParseWireGuardIni(string text)
    {
        var interfaceFields = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        var peers = new List<Dictionary<string, string>>();
        var section = string.Empty;
        Dictionary<string, string>? currentPeer = null;
        foreach (var raw in text.Split(['\r', '\n']))
        {
            var line = raw.Split('#', ';')[0].Trim();
            if (line.Length == 0)
                continue;
            if (line.StartsWith("[", StringComparison.Ordinal) && line.EndsWith("]", StringComparison.Ordinal))
            {
                section = line[1..^1].Trim().ToLowerInvariant();
                if (section == "peer")
                {
                    currentPeer = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
                    peers.Add(currentPeer);
                }
                continue;
            }
            var separator = line.IndexOf('=');
            if (separator <= 0)
                continue;
            var key = line[..separator].Trim().ToLowerInvariant().Replace("_", string.Empty, StringComparison.Ordinal);
            var value = line[(separator + 1)..].Trim();
            if (value.Length == 0)
                continue;
            if (section == "interface")
                interfaceFields[key] = value;
            else if (section == "peer")
            {
                if (currentPeer is not null)
                    currentPeer[key] = value;
            }
        }
        return (interfaceFields, peers);
    }

    private static string Value(IReadOnlyDictionary<string, string> values, string key, string fallback = "")
        => values.TryGetValue(key, out var value) ? value : fallback;

    private static int ParseOptionalNumber(string value, int fallback)
        => int.TryParse(value.Trim(), out var parsed) && parsed > 0 ? parsed : fallback;

    private static string? StringProperty(JsonElement element, params string[] names)
    {
        foreach (var name in names)
        {
            if (element.TryGetProperty(name, out var value) && value.ValueKind == JsonValueKind.String)
                return value.GetString();
        }
        return null;
    }

    private static int? IntProperty(JsonElement element, params string[] names)
    {
        foreach (var name in names)
        {
            if (!element.TryGetProperty(name, out var value))
                continue;
            if (value.ValueKind == JsonValueKind.Number && value.TryGetInt32(out var number))
                return number;
            if (value.ValueKind == JsonValueKind.String && int.TryParse(value.GetString(), out number))
                return number;
        }
        return null;
    }

    private static string? StringOrArrayProperty(JsonElement element, params string[] names)
    {
        foreach (var name in names)
        {
            if (!element.TryGetProperty(name, out var value))
                continue;
            if (value.ValueKind == JsonValueKind.String)
                return value.GetString();
            if (value.ValueKind == JsonValueKind.Array)
            {
                var values = value.EnumerateArray()
                    .Where(item => item.ValueKind == JsonValueKind.String)
                    .Select(item => item.GetString())
                    .Where(item => !string.IsNullOrWhiteSpace(item));
                var joined = string.Join(", ", values);
                if (joined.Length > 0)
                    return joined;
            }
        }
        return null;
    }
}
