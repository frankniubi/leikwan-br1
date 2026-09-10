namespace LeikwanBridge.Core.Models;

/// <summary>
/// Wire-neutral defaults for ordinary Ultra candidate selection. Platform
/// hosts may choose a shorter native-outbound window, but ordinary Ultra
/// bootstrap must use this one contract.
/// </summary>
public static class UltraProbeContract
{
    public const string Target = "1.1.1.1:53";
    public const int DurationSeconds = 30;
    public const int TimeoutMilliseconds = 800;
    public const int Samples = 30;
    public const int IntervalMilliseconds = 1000;
    public const int Workers = 4;
}
