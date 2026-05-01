using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Helpers;

public static class WindowsForwardPolicy
{
    public static bool IsSupportedOnWindows(ForwardConfig forward)
    {
        var mode = ModeNormalizer.Normalize(forward.Mode);
        return mode is "single" or "bond";
    }

    public static List<ForwardConfig> FilterSupportedForwards(IEnumerable<ForwardConfig> forwards)
    {
        return forwards
            .Where(IsSupportedOnWindows)
            .ToList();
    }
}
