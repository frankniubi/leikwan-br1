using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services;

public static class NativeOutboundConfigBuilder
{
    public static NativeOutboundConfigFragment Build(
        NativeOutboundLease lease,
        bool bypassChina,
        int localPort,
        string? localProxyHost = null,
        int? localProxyPort = null,
        string? ruleSetDirectory = null)
    {
        ArgumentNullException.ThrowIfNull(lease);
        if (localPort is < 1 or > 65535)
            throw new ArgumentOutOfRangeException(nameof(localPort));
        if (!lease.IsUsable(DateTimeOffset.UtcNow))
            throw new InvalidOperationException("native_lease_invalid");
        var useLocalProxy = !string.IsNullOrWhiteSpace(localProxyHost) || localProxyPort.HasValue;
        if (useLocalProxy && (string.IsNullOrWhiteSpace(localProxyHost)
            || localProxyPort is < 1 or > 65535))
            throw new InvalidOperationException("native_local_proxy_invalid");

        var outbound = new JsonObject
        {
            ["type"] = "socks",
            ["tag"] = "proxy",
            ["server"] = useLocalProxy ? localProxyHost : lease.EndpointHost,
            ["server_port"] = useLocalProxy ? localProxyPort : lease.EndpointPort,
            // sing-box models the SOCKS version as a string.  Omitting the
            // network selector keeps both TCP and UDP enabled; its value is a
            // scalar and cannot be expressed as "tcp,udp".
            ["version"] = "5",
            // Keep the native UDP leg as real UDP.  An explicit disabled value
            // prevents a runtime default from silently selecting UDP-over-TCP.
            ["udp_over_tcp"] = new JsonObject { ["enabled"] = false },
        };
        if (!useLocalProxy)
        {
            outbound["username"] = Encoding.UTF8.GetString(lease.User);
            outbound["password"] = Encoding.UTF8.GetString(lease.Password);
        }

        var rules = new JsonArray
        {
            new JsonObject { ["ip_is_private"] = true, ["outbound"] = "direct" },
        };
        if (lease.RouteScope == "cn_only")
        {
            rules.Add(new JsonObject
            {
                ["rule_set"] = new JsonArray("geoip-cn", "geosite-cn"),
                ["outbound"] = "proxy",
            });
        }
        else if (lease.RouteScope == "intl_only")
        {
            rules.Add(new JsonObject
            {
                ["rule_set"] = new JsonArray("geoip-cn", "geosite-cn"),
                ["outbound"] = "direct",
            });
        }
        else if (bypassChina)
        {
            rules.Add(new JsonObject
            {
                ["rule_set"] = new JsonArray("geoip-cn", "geosite-cn"),
                ["outbound"] = "direct",
            });
        }

        var route = new JsonObject
        {
            ["rules"] = rules,
            ["final"] = lease.RouteScope == "cn_only" ? "direct" : "proxy",
        };
        // Prefer the rule-sets bundled next to sing-box. The remote fallback
        // follows the usable international path for the selected direction:
        // an intl_only relay exists specifically for this traffic, and direct
        // raw.githubusercontent.com access can stall from the mainland.
        var ruleSetDownloadDetour = lease.RouteScope == "cn_only" ? "direct" : "proxy";
        route["rule_set"] = NativeChinaRuleSets.BuildDefinitions(
            NativeChinaRuleSets.TryResolve(ruleSetDirectory),
            ruleSetDownloadDetour);
        var root = new JsonObject
        {
            ["outbound"] = outbound,
            ["route"] = route,
        };
        return new NativeOutboundConfigFragment(
            root.ToJsonString(new JsonSerializerOptions { WriteIndented = false }),
            localPort);
    }
}
