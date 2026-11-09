using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

/// <summary>
/// Response of GET /api/client/access/policy. Only the account's home province is
/// shown on the desktop dashboard; the rest is read by the location check flow.
/// </summary>
public sealed class AccessPolicyStatus
{
    [JsonPropertyName("ok")]
    public bool Ok { get; set; }

    [JsonPropertyName("main_province_code")]
    public string? MainProvinceCode { get; set; }

    [JsonPropertyName("geo_lock_enabled")]
    public bool GeoLockEnabled { get; set; }

    [JsonPropertyName("location_required")]
    public bool LocationRequired { get; set; }
}
