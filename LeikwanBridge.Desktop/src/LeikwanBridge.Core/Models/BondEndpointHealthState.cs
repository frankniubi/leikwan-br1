using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

public sealed class BondEndpointHealthEntry
{
    [JsonPropertyName("role")]
    public string Role { get; set; } = "healthy";

    [JsonPropertyName("consecutive_failures")]
    public int ConsecutiveFailures { get; set; }

    [JsonPropertyName("consecutive_successes")]
    public int ConsecutiveSuccesses { get; set; }

    [JsonPropertyName("quarantine_until")]
    public double QuarantineUntil { get; set; }

    [JsonPropertyName("probation_until")]
    public double ProbationUntil { get; set; }

    [JsonPropertyName("last_error")]
    public string LastError { get; set; } = "";

    [JsonPropertyName("last_latency_ms")]
    public double? LastLatencyMs { get; set; }
}
