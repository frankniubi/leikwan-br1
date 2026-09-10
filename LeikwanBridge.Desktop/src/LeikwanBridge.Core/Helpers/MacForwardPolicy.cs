using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Helpers;

public sealed class MacForwardPolicy : IForwardAcceptancePolicy
{
    public const string UnsupportedModeNotice =
        "The mode of this forward is not supported in MacOS binary. 此转发的模式尚未在MacOS客户端支援.";

    public static MacForwardPolicy Instance { get; } = new();

    private MacForwardPolicy()
    {
    }

    public ForwardAcceptance Evaluate(ForwardConfig forward)
    {
        var backendMode = (forward.BackendMode ?? string.Empty).Trim().ToLowerInvariant();
        var effectiveMode = (!string.IsNullOrWhiteSpace(forward.ClientMode)
            ? forward.ClientMode
            : forward.Mode ?? string.Empty).Trim().ToLowerInvariant();

        var accepted = backendMode switch
        {
            "current_mode" => effectiveMode is "single" or "bond",
            "ultra" => UltraForwardPolicy.IsSupported(forward, effectiveMode),
            _ => false,
        };

        return accepted
            ? new ForwardAcceptance(true, backendMode == "ultra" ? "ultra" : effectiveMode, string.Empty)
            : new ForwardAcceptance(false, string.Empty, UnsupportedModeNotice);
    }
}
