using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Helpers;

public sealed record ForwardAcceptance(bool Accepted, string EffectiveMode, string Notice);

public interface IForwardAcceptancePolicy
{
    ForwardAcceptance Evaluate(ForwardConfig forward);
}

public sealed class WindowsForwardAcceptancePolicy : IForwardAcceptancePolicy
{
    public static WindowsForwardAcceptancePolicy Instance { get; } = new();

    private WindowsForwardAcceptancePolicy()
    {
    }

    public ForwardAcceptance Evaluate(ForwardConfig forward)
    {
        if (!WindowsForwardPolicy.IsSupportedOnWindows(forward))
            return new ForwardAcceptance(false, string.Empty, string.Empty);

        var backendMode = (forward.BackendMode ?? string.Empty).Trim().ToLowerInvariant();
        if (backendMode == "ultra")
            return new ForwardAcceptance(true, "ultra", string.Empty);

        var rawMode = !string.IsNullOrWhiteSpace(forward.ClientMode)
            ? forward.ClientMode
            : forward.Mode;
        return new ForwardAcceptance(true, ModeNormalizer.Normalize(rawMode), string.Empty);
    }
}
