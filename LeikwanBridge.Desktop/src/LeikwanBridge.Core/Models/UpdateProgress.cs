namespace LeikwanBridge.Core.Models;

/// <summary>
/// High-level phase of a desktop client update flow. Both the Windows and
/// macOS update services report through <see cref="IProgress{UpdateProgress}"/>
/// using this phase so each platform UI can visualize the same pipeline.
/// </summary>
public enum UpdatePhase
{
    Check,
    Download,
    Verify,
    Stage,
    Apply,
}

/// <summary>
/// Shared progress payload for update visualization. <see cref="Percent"/>
/// is a 0-100 value for the current phase; phases without a measurable
/// denominator report 0 (or 100 when the phase completes).
/// </summary>
public sealed record UpdateProgress(UpdatePhase Phase, double Percent, string Message)
{
    public static UpdateProgress Create(UpdatePhase phase, double percent = 0, string message = "")
        => new(phase, Math.Clamp(percent, 0, 100), message ?? string.Empty);

    public static UpdateProgress Checking(string message = "")
        => Create(UpdatePhase.Check, 0, message);

    public static UpdateProgress Downloading(double percent, string message = "")
        => Create(UpdatePhase.Download, percent, message);

    public static UpdateProgress Verifying(double percent, string message = "")
        => Create(UpdatePhase.Verify, percent, message);

    public static UpdateProgress Staging(double percent, string message = "")
        => Create(UpdatePhase.Stage, percent, message);

    public static UpdateProgress Applying(double percent, string message = "")
        => Create(UpdatePhase.Apply, percent, message);
}
