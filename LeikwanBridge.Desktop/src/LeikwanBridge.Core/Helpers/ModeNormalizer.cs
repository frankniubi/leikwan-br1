namespace LeikwanBridge.Core.Helpers;

public static class ModeNormalizer
{
    public static string Normalize(string? mode)
    {
        var m = (mode ?? "").Trim().ToLowerInvariant();
        return m switch
        {
            "race" or "failover" or "multi-path" or "lb" => "race",
            "bond" or "bonding" or "aggregate" or "aggregated" => "bond",
            "mptcp" or "mptcp-wireguard" or "mptcp-ipsec" or "multipath-tcp" => "mptcp",
            "auto" or "" => throw new InvalidOperationException("'auto' mode must be resolved server-side"),
            _ => "single",
        };
    }

    public static string DisplayName(string normalizedMode, bool isFallback = false)
    {
        return "LeiGod";
    }
}
