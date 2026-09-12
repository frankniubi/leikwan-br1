namespace LeikwanBridge.Mac.Services;

/// <summary>
/// Reads the live TUN MTU back from <c>/sbin/ifconfig</c> so an in-place sing-box reload can
/// be verified instead of assumed: sing-box reports a rejected config on stderr and keeps
/// the old service running, so "SIGHUP delivered" is not proof that the new MTU took effect.
/// </summary>
public static class MacTunMtuProbe
{
    /// <summary>
    /// MTU of the interface that owns <paramref name="address"/>, or 0 when no interface
    /// currently carries it (for example while the reload is rebuilding the tunnel).
    /// </summary>
    public static int Parse(string? ifconfigOutput, string address)
    {
        if (string.IsNullOrWhiteSpace(ifconfigOutput) || string.IsNullOrWhiteSpace(address))
            return 0;
        var blockMtu = 0;
        var matched = false;
        foreach (var rawLine in ifconfigOutput.Split('\n'))
        {
            var line = rawLine.TrimEnd('\r');
            if (line.Length == 0)
                continue;
            if (!char.IsWhiteSpace(line[0]))
            {
                if (matched)
                    return blockMtu;
                blockMtu = ParseMtu(line);
                continue;
            }
            if (!matched && DeclaresAddress(line, address))
                matched = true;
        }
        return matched ? blockMtu : 0;
    }

    private static int ParseMtu(string header)
    {
        var tokens = header.Split((char[]?)null, StringSplitOptions.RemoveEmptyEntries);
        for (var index = 0; index + 1 < tokens.Length; index++)
        {
            if (tokens[index] == "mtu" && int.TryParse(tokens[index + 1], out var value))
                return value;
        }
        return 0;
    }

    private static bool DeclaresAddress(string line, string address)
    {
        // ifconfig renders the address as "inet 172.19.0.1 --> 172.19.0.2"; match the
        // address token itself so a longer address such as 172.19.0.10 cannot alias it.
        var tokens = line.Split((char[]?)null, StringSplitOptions.RemoveEmptyEntries);
        for (var index = 0; index + 1 < tokens.Length; index++)
        {
            if (tokens[index] == "inet" && tokens[index + 1] == address)
                return true;
        }
        return false;
    }
}
