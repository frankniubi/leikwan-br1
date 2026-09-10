namespace LeikwanBridge.Mac.Services;

public static class MacTunDnsSession
{
    public static string BuildCommands(string session)
    {
        if (string.IsNullOrEmpty(session) || session.Any(character => !char.IsAsciiHexDigit(character)))
            throw new ArgumentException("invalid DNS session", nameof(session));
        var key = $"State:/Network/Service/LeiKwan-{session}/DNS";
        return "d.init\n"
            + $"d.add ServerAddresses * {MacConnectionConfigBuilder.TunGateway}\n"
            + "d.add SupplementalMatchDomains * \"\"\n"
            + "d.add SupplementalMatchOrders * # 1\n"
            + "d.add SearchOrder # 1\n"
            + $"add {key} temporary\nshow {key}\n";
    }

    public static bool IsReady(string response)
        => response.Contains("<dictionary>", StringComparison.Ordinal)
            && response.Contains("ServerAddresses", StringComparison.Ordinal)
            && response.Contains(MacConnectionConfigBuilder.TunGateway, StringComparison.Ordinal)
            && response.Contains("SupplementalMatchDomains", StringComparison.Ordinal)
            && !response.Contains("Permission denied", StringComparison.OrdinalIgnoreCase);
}
