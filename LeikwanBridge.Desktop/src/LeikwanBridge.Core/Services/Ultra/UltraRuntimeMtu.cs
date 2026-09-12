namespace LeikwanBridge.Core.Services.Ultra;

/// <summary>
/// MTU policy for the Ultra native-UDP path.
///
/// The 2026-09-12 sweep (189 lines, source host) showed the payload ceiling belongs
/// to the relay association, not to the line, and that 35% of associations drop
/// datagrams above ~1440 bytes while the TUN happily emits 1500-byte frames
/// (1516 bytes once the 16-byte envelope is added). The runtime measures the
/// ceiling per association and publishes it as "udp_payload_ceiling" in its status
/// file; this helper turns that value into the MTU the host should give the TUN so
/// oversized frames are never produced in the first place.
/// </summary>
public static class UltraRuntimeMtu
{
    /// <summary>16-byte relay envelope + 20-byte IP + 8-byte UDP header.</summary>
    public const int EnvelopeOverhead = 44;

    /// <summary>Never configure the tunnel below the IPv6 minimum MTU.</summary>
    public const int MinimumMtu = 1280;

    /// <summary>
    /// Resolve the TUN MTU from the configured (panel-supplied) MTU and the measured
    /// payload ceiling.
    /// </summary>
    /// <param name="configuredMtu">Panel/operator MTU; &lt;= 0 means "not configured".</param>
    /// <param name="measuredPayloadCeiling">
    /// Runtime-measured ceiling in payload bytes; &lt;= 0 means "not measured (yet)" or
    /// "association unusable". Both cases leave the configured MTU untouched here —
    /// the runtime refuses oversized datagrams and rotates unusable associations.
    /// </param>
    public static int Resolve(int configuredMtu, int measuredPayloadCeiling)
    {
        if (configuredMtu <= 0 || measuredPayloadCeiling <= 0)
        {
            return configuredMtu;
        }

        var allowed = measuredPayloadCeiling - EnvelopeOverhead;
        if (allowed < MinimumMtu)
        {
            allowed = MinimumMtu;
        }

        return Math.Min(configuredMtu, allowed);
    }
}
