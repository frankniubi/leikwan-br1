using System.Globalization;
using Serilog;

namespace LeikwanBridge.App.ViewModels;

/// <summary>
/// Account values for the dashboard cards: plan, rate limit and home province.
/// Values are either read from state the client already has (line speed limits) or
/// from the panel's access-policy read (home province). A missing value is shown
/// as a resource key so the dashboard can say "not provided" in either language.
/// </summary>
public partial class MainViewModel
{
    private static readonly TimeSpan AccessPolicyRefreshInterval = TimeSpan.FromMinutes(10);

    /// <summary>The 31 mainland province codes the panel's region policy uses.</summary>
    public static readonly IReadOnlySet<string> MainlandProvinceCodes = new HashSet<string>(StringComparer.Ordinal)
    {
        "AH", "BJ", "CQ", "FJ", "GD", "GS", "GX", "GZ", "HA", "HB", "HE", "HI", "HL", "HN", "JL", "JS",
        "JX", "LN", "NM", "NX", "QH", "SC", "SD", "SH", "SN", "SX", "TJ", "XJ", "XZ", "YN", "ZJ",
    };

    private string? _mainProvinceCode;
    private DateTimeOffset _accessPolicyFetchedAt = DateTimeOffset.MinValue;
    private int _accessPolicyReadInFlight;

    /// <summary>
    /// The panel does not send a plan name to the desktop client yet, so the card
    /// shows the "not provided" text instead of a value.
    /// </summary>
    public string PlanText => "DashPlanUnavailable";

    /// <summary>
    /// Summary of the line speed limits: unlimited, one shared value, or "varies by line".
    /// </summary>
    public string SpeedLimitText
    {
        get
        {
            var limits = Forwards
                .Select(forward => forward.Config?.SpeedLimitMbps ?? 0)
                .Distinct()
                .ToArray();
            if (limits.Length == 0 || limits.All(limit => limit <= 0))
                return "DashRateLimitNone";
            if (limits.Length > 1)
                return "DashRateLimitMixed";
            return limits[0].ToString("0.#", CultureInfo.InvariantCulture) + " Mbps";
        }
    }

    /// <summary>Province resource key for the account's home province, or the "not loaded" text.</summary>
    public string ProvinceText => ProvinceResourceKey(_mainProvinceCode);

    /// <summary>Maps a panel province code to its resource key ("Province_GD"); unknown codes map to "not loaded".</summary>
    public static string ProvinceResourceKey(string? code)
    {
        var normalized = code?.Trim().ToUpperInvariant();
        return normalized is not null && MainlandProvinceCodes.Contains(normalized)
            ? "Province_" + normalized
            : "DashProvinceUnavailable";
    }

    /// <summary>
    /// Reads the home province from the panel. Runs at most once per interval, and never
    /// throws: a failed read keeps the last value and is logged at debug level.
    /// </summary>
    public async Task RefreshAccessPolicyAsync(CancellationToken ct = default, bool force = false)
    {
        var api = _api;
        if (api is null)
            return;
        if (!force && DateTimeOffset.UtcNow - _accessPolicyFetchedAt < AccessPolicyRefreshInterval)
            return;
        if (Interlocked.Exchange(ref _accessPolicyReadInFlight, 1) == 1)
            return;
        try
        {
            var status = await api.GetAccessPolicyAsync(ct);
            _mainProvinceCode = status.MainProvinceCode;
            _accessPolicyFetchedAt = DateTimeOffset.UtcNow;
            OnPropertyChanged(nameof(ProvinceText));
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
            throw;
        }
        catch (Exception exception)
        {
            _accessPolicyFetchedAt = DateTimeOffset.UtcNow;
            Log.Debug(exception, "access policy read failed; dashboard keeps its last province");
        }
        finally
        {
            Interlocked.Exchange(ref _accessPolicyReadInFlight, 0);
        }
    }
}
