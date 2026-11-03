using System.Globalization;
using Serilog;

namespace LeikwanBridge.App.ViewModels;

/// <summary>
/// Account values for the dashboard cards: rate limit and home province.
/// Values are either read from state the client already has (line speed limits) or
/// from the panel's access-policy read (home province). The province card has a
/// separate state for each case, so an empty value never looks like a broken read.
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
    private bool _accessPolicyRead;
    private bool _accessPolicyFailed;
    private DateTimeOffset _accessPolicyFetchedAt = DateTimeOffset.MinValue;
    private int _accessPolicyReadInFlight;

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

    /// <summary>Value line of the home-province card.</summary>
    public string ProvinceText => ProvinceDisplayKey(IsConnected, _accessPolicyRead, _accessPolicyFailed, _mainProvinceCode);

    /// <summary>
    /// Resource key for the home-province value. Not connected, still reading, read failed,
    /// no province set for the account, or the province name.
    /// </summary>
    public static string ProvinceDisplayKey(bool connected, bool read, bool failed, string? code)
    {
        if (!connected)
            return "DashProvinceNotConnected";
        if (read)
            return ProvinceResourceKey(code);
        return failed ? "DashProvinceReadFailed" : "DashProvinceReading";
    }

    /// <summary>Maps a panel province code to its resource key ("Province_GD"); a missing or unknown code maps to "not set".</summary>
    public static string ProvinceResourceKey(string? code)
    {
        var normalized = code?.Trim().ToUpperInvariant();
        return normalized is not null && MainlandProvinceCodes.Contains(normalized)
            ? "Province_" + normalized
            : "DashProvinceNotSet";
    }

    partial void OnIsConnectedChanged(bool value) => OnPropertyChanged(nameof(ProvinceText));

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
            // The session may have ended while the read was in flight. Its answer belongs to that session.
            if (!ReferenceEquals(_api, api))
                return;
            _mainProvinceCode = status.MainProvinceCode;
            _accessPolicyRead = true;
            _accessPolicyFailed = false;
            _accessPolicyFetchedAt = DateTimeOffset.UtcNow;
            OnPropertyChanged(nameof(ProvinceText));
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
            throw;
        }
        catch (Exception exception)
        {
            _accessPolicyFailed = true;
            _accessPolicyFetchedAt = DateTimeOffset.UtcNow;
            OnPropertyChanged(nameof(ProvinceText));
            Log.Debug(exception, "access policy read failed; dashboard keeps its last province");
        }
        finally
        {
            Interlocked.Exchange(ref _accessPolicyReadInFlight, 0);
        }
    }

    /// <summary>Forgets the home province when the session ends, so the next session never shows the previous account's value.</summary>
    private void ResetAccessPolicyState()
    {
        _mainProvinceCode = null;
        _accessPolicyRead = false;
        _accessPolicyFailed = false;
        _accessPolicyFetchedAt = DateTimeOffset.MinValue;
        OnPropertyChanged(nameof(ProvinceText));
    }
}
