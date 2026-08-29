using System.Net;
using System.Text;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Parses the small, intentionally supported Windows import surface.  The
/// parser does not use a generic URI user-info split for SS: SS2022 passwords
/// commonly contain a colon, so the first colon is the only delimiter.
/// </summary>
public static class WindowsProxyUriParser
{
    private const int MaxUriLength = 16 * 1024;

    public static WindowsProxyProfile Parse(string uri)
    {
        if (!TryParse(uri, out var profile, out var error))
            throw new FormatException(error);
        return profile;
    }

    public static bool TryParse(
        string? uri,
        out WindowsProxyProfile profile,
        out string error)
    {
        profile = new WindowsProxyProfile();
        error = "proxy_uri_invalid";
        if (string.IsNullOrWhiteSpace(uri))
        {
            error = "proxy_uri_required";
            return false;
        }

        var trimmed = uri.Trim();
        if (trimmed.Length > MaxUriLength || trimmed.Any(char.IsControl))
        {
            error = "proxy_uri_invalid";
            return false;
        }

        try
        {
            if (trimmed.StartsWith("ss://", StringComparison.OrdinalIgnoreCase))
                profile = ParseShadowsocks(trimmed);
            else if (trimmed.StartsWith("vless://", StringComparison.OrdinalIgnoreCase))
                profile = ParseVless(trimmed);
            else
            {
                error = "proxy_protocol_unsupported";
                return false;
            }

            error = "";
            return true;
        }
        catch (FormatException exception)
        {
            // Deliberately return stable, secret-free error codes.  Never put
            // the imported URI or password in a UI/log exception.
            error = exception.Message;
            return false;
        }
        catch
        {
            error = "proxy_uri_invalid";
            return false;
        }
    }

    public static IReadOnlyList<WindowsProxyProfile> ParseMany(string text)
    {
        if (string.IsNullOrWhiteSpace(text))
            return [];

        var source = text.Trim();
        if (TryDecodeBase64(source, out var decoded))
            source = decoded;

        var profiles = new List<WindowsProxyProfile>();
        foreach (var line in source.Split(['\r', '\n'], StringSplitOptions.RemoveEmptyEntries))
        {
            if (TryParse(line.Trim(), out var profile, out _))
                profiles.Add(profile);
        }

        return profiles;
    }

    private static WindowsProxyProfile ParseShadowsocks(string uri)
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
            // SIP-002 may encode the complete authority, and subscription
            // providers sometimes percent-escape that Base64 payload.  Try
            // both forms before treating it as plaintext.
            var encodedCandidates = new[] { body, body.TrimEnd('/') };
            var decodedBody = "";
            var decoded = false;
            foreach (var candidate in encodedCandidates)
            {
                if (TryDecodeBase64(candidate, out decodedBody))
                {
                    decoded = true;
                    break;
                }
            }
            if (!decoded)
            {
                var unescapedBody = DecodeComponent(body);
                var unescapedCandidates = new[] { unescapedBody, unescapedBody.TrimEnd('/') };
                foreach (var candidate in unescapedCandidates)
                {
                    if (TryDecodeBase64(candidate, out decodedBody))
                    {
                        decoded = true;
                        break;
                    }
                }
                if (!decoded)
                    decodedBody = unescapedBody;
            }
            at = decodedBody.LastIndexOf('@');
            if (at < 0)
                throw new FormatException("proxy_uri_invalid");
            userInfo = decodedBody[..at];
            hostPort = decodedBody[(at + 1)..];
        }

        var credentials = DecodeShadowsocksCredentials(userInfo);
        var endpoint = ParseHostPort(hostPort);
        var parameters = ParseQuery(query);
        if (parameters.TryGetValue("plugin", out var plugin)
            && !string.IsNullOrWhiteSpace(plugin))
            throw new FormatException("proxy_plugin_unsupported");
        var method = credentials.Method.Trim();
        if (!WindowsProxyProtocol.IsSupportedShadowsocksMethod(method))
            throw new FormatException("proxy_method_unsupported");

        var is2022 = WindowsProxyProtocol.IsShadowsocks2022Method(method);
        return new WindowsProxyProfile
        {
            Name = string.IsNullOrWhiteSpace(name) ? $"{endpoint.Host}:{endpoint.Port}" : name,
            Protocol = is2022
                ? WindowsProxyProtocol.Shadowsocks2022
                : WindowsProxyProtocol.Shadowsocks,
            Server = endpoint.Host,
            Port = endpoint.Port,
            Method = method,
            Password = credentials.Password,
            UdpOverTcp = Truthy(parameters, "uot") || Truthy(parameters, "udp_over_tcp"),
        };
    }

    private static WindowsProxyProfile ParseVless(string uri)
    {
        if (!Uri.TryCreate(uri, UriKind.Absolute, out var parsed)
            || !string.Equals(parsed.Scheme, "vless", StringComparison.OrdinalIgnoreCase)
            || string.IsNullOrWhiteSpace(parsed.UserInfo)
            || string.IsNullOrWhiteSpace(parsed.Host)
            || parsed.Port is < 1 or > 65535)
            throw new FormatException("proxy_uri_invalid");

        var uuid = DecodeComponent(parsed.UserInfo);
        if (!Guid.TryParse(uuid, out _))
            throw new FormatException("proxy_uuid_invalid");

        var query = ParseQuery(parsed.Query.TrimStart('?'));
        var transport = ValueOrDefault(query, "type", "tcp").ToLowerInvariant();
        if (transport == "none")
            transport = "tcp";
        if (!WindowsProxyProtocol.VlessTransportOptions.Contains(transport, StringComparer.Ordinal))
            throw new FormatException("proxy_transport_unsupported");

        var security = ValueOrDefault(query, "security", "none").ToLowerInvariant();
        if (security is not ("none" or "tls" or "reality"))
            throw new FormatException("proxy_tls_unsupported");

        var serverName = ValueOrDefault(query, "sni", "");
        var profile = new WindowsProxyProfile
        {
            Name = string.IsNullOrWhiteSpace(parsed.Fragment)
                ? $"{parsed.Host}:{parsed.Port}"
                : DecodeComponent(parsed.Fragment.TrimStart('#')),
            Protocol = WindowsProxyProtocol.Vless,
            Server = parsed.Host,
            Port = parsed.Port,
            Uuid = uuid,
            Flow = ValueOrDefault(query, "flow", ""),
            Transport = transport,
            Host = ValueOrDefault(query, "host", ""),
            Path = ValueOrDefault(query, "path", "/"),
            ServiceName = ValueOrDefault(query, "servicename", ValueOrDefault(query, "service_name", "")),
            Tls = security is "tls" or "reality",
            ServerName = serverName,
            Fingerprint = ValueOrDefault(query, "fp", ""),
            RealityPublicKey = ValueOrDefault(query, "pbk", ""),
            RealityShortId = ValueOrDefault(query, "sid", ""),
            Alpn = ValueOrDefault(query, "alpn", ""),
            AllowInsecure = Truthy(query, "allowinsecure") || Truthy(query, "allow_insecure"),
        };

        if (string.IsNullOrWhiteSpace(profile.ServerName)
            && profile.Tls
            && !string.IsNullOrWhiteSpace(profile.Host))
            profile.ServerName = profile.Host;
        if (profile.Transport == "ws" && string.IsNullOrWhiteSpace(profile.Path))
            profile.Path = "/";
        return profile;
    }

    private static (string Method, string Password) DecodeShadowsocksCredentials(string value)
    {
        var candidates = new List<string>();
        if (TryDecodeBase64(value, out var base64))
            candidates.Add(base64);
        var unescaped = DecodeComponent(value);
        if (!string.Equals(unescaped, value, StringComparison.Ordinal))
        {
            if (TryDecodeBase64(unescaped, out var escapedBase64))
                candidates.Add(escapedBase64);
            candidates.Add(unescaped);
        }
        candidates.Add(value);

        foreach (var candidate in candidates)
        {
            var colon = candidate.IndexOf(':');
            if (colon > 0)
                return (candidate[..colon], candidate[(colon + 1)..]);

            // The legacy `none` cipher is commonly written without a
            // password (`ss://none@host:port`).  Keep that form importable;
            // non-none methods still fail validation later if empty.
            if (WindowsProxyProtocol.IsSupportedShadowsocksMethod(candidate))
                return (candidate, "");
        }
        throw new FormatException("proxy_credentials_invalid");
    }

    private static (string Host, int Port) ParseHostPort(string value)
    {
        // A trailing slash is legal in SIP-002 authorities (for example
        // `ss://...@host:443/?plugin=...`).  It is not part of the port.
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

    private static bool IsValidHost(string host)
    {
        if (string.IsNullOrWhiteSpace(host) || host.Length > 255)
            return false;
        if (IPAddress.TryParse(host, out _))
            return true;
        return Uri.CheckHostName(host) is UriHostNameType.Dns
            && !host.Any(char.IsWhiteSpace);
    }

    private static string ExtractFragment(ref string value)
    {
        var index = value.IndexOf('#');
        if (index < 0)
            return "";
        var fragment = value[(index + 1)..];
        value = value[..index];
        return DecodeComponent(fragment);
    }

    private static string ExtractQuery(ref string value)
    {
        var index = value.IndexOf('?');
        if (index < 0)
            return "";
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
            var value = separator < 0 ? "" : pair[(separator + 1)..];
            if (key.Length == 0)
                continue;
            result[DecodeComponent(key).ToLowerInvariant()] = DecodeComponent(value);
        }
        return result;
    }

    private static string ValueOrDefault(IReadOnlyDictionary<string, string> values, string key, string fallback)
        => values.TryGetValue(key, out var value) && !string.IsNullOrWhiteSpace(value) ? value : fallback;

    private static bool Truthy(IReadOnlyDictionary<string, string> values, string key)
    {
        if (!values.TryGetValue(key, out var value))
            return false;
        return value.Trim().ToLowerInvariant() is "1" or "true" or "yes" or "on";
    }

    private static string DecodeComponent(string value)
    {
        try { return Uri.UnescapeDataString(value.Replace("+", "%2B", StringComparison.Ordinal)); }
        catch { throw new FormatException("proxy_uri_invalid"); }
    }

    private static bool TryDecodeBase64(string value, out string decoded)
    {
        decoded = "";
        var candidates = new List<string> { value.Trim() };
        try
        {
            var unescaped = DecodeComponent(value).Trim();
            if (!candidates.Contains(unescaped, StringComparer.Ordinal))
                candidates.Add(unescaped);
        }
        catch
        {
            // Keep the raw candidate; malformed percent escapes are handled
            // by the caller as a normal invalid URI.
        }

        foreach (var candidate in candidates)
        {
            if (candidate.Length == 0 || candidate.Length % 4 == 1)
                continue;
            var normalized = candidate.Replace('-', '+').Replace('_', '/');
            normalized = normalized.PadRight(normalized.Length + ((4 - normalized.Length % 4) % 4), '=');
            try
            {
                var bytes = Convert.FromBase64String(normalized);
                var text = Encoding.UTF8.GetString(bytes);
                if (text.Length > 0
                    && !text.Any(character => character < 0x20 && character is not '\r' and not '\n' and not '\t'))
                {
                    decoded = text;
                    return true;
                }
            }
            catch
            {
                // Try the next representation (raw vs percent-unescaped).
            }
        }
        return false;
    }
}
