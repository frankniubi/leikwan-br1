namespace LeikwanBridge.Core.Models;

public enum ForwardStrategy
{
    SpeedMax,
    LatencyBest,
    Random,
}

public static class ForwardStrategyValues
{
    public static string ToWireValue(this ForwardStrategy strategy) => strategy switch
    {
        ForwardStrategy.SpeedMax => "speed_max",
        ForwardStrategy.Random => "random",
        _ => "latency_best",
    };

    public static ForwardStrategy ParseOrDefault(string? value) => value?.Trim().ToLowerInvariant() switch
    {
        "speed_max" => ForwardStrategy.SpeedMax,
        "random" => ForwardStrategy.Random,
        _ => ForwardStrategy.LatencyBest,
    };
}
