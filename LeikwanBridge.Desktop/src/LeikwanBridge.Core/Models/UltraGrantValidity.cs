namespace LeikwanBridge.Core.Models;

/// <summary>
/// Client side of the W1 "offline validity" contract (docs/plans/2026-09-24-enterprise-ha-plan.md).
/// <para>
/// The client tells the panel, with <see cref="OfflineValidityHeader"/>, the longest
/// policy/lease validity it can hold while the panel is unreachable. The panel answers
/// with min(header, its own ceiling, material and service expiry) and keeps
/// <c>renew_after</c> at the usual ~4 minutes, so an online client renews exactly as
/// often as before and only an outage makes use of the longer window.
/// </para>
/// <para>
/// Acceptance is wider than the request (<see cref="MaxAcceptedValidity"/>): the panel
/// clamps its own setting to 3600 s, and a client must not reject a grant merely
/// because an operator raised that ceiling. A panel that ignores the header keeps
/// issuing grants of at most 300 s, which are accepted as before.
/// </para>
/// </summary>
public static class UltraGrantValidity
{
    public const string OfflineValidityHeader = "X-Leikwan-Ultra-Offline-Validity";

    /// <summary>Validity this build asks for (seconds).</summary>
    public const int RequestedOfflineValiditySeconds = 1800;

    /// <summary>Longest validity this build accepts from the panel (seconds).</summary>
    public const int MaxAcceptedValiditySeconds = 3600;

    /// <summary>Clock skew and transit allowance on top of the accepted validity.</summary>
    public static readonly TimeSpan ClockSkewAllowance = TimeSpan.FromSeconds(30);

    public static readonly TimeSpan MaxAcceptedValidity = TimeSpan.FromSeconds(MaxAcceptedValiditySeconds);

    public static string RequestedOfflineValidityHeaderValue { get; } =
        RequestedOfflineValiditySeconds.ToString(System.Globalization.CultureInfo.InvariantCulture);

    /// <summary>Latest expiry a policy or lease may carry when checked at <paramref name="now"/>.</summary>
    public static DateTimeOffset LatestAcceptedExpiry(DateTimeOffset now)
        => now + MaxAcceptedValidity + ClockSkewAllowance;

    public static bool IsWithinAcceptedWindow(DateTimeOffset expiresAt, DateTimeOffset now)
        => expiresAt <= LatestAcceptedExpiry(now);
}
