namespace LeikwanBridge.Core.Services.Platform;

/// <summary>
/// A process supervisor that reads Ultra runtime stderr and reports the
/// failures that suggest the relay stopped accepting the forward's provider
/// credential (see <see cref="Ultra.UltraRuntimeFailureLog.IsCredentialSuspect"/>).
/// <para>
/// The event is raised on the output reader thread, once per failure line and
/// without rate limiting; subscribers coalesce and must not block or throw.
/// </para>
/// </summary>
public interface IUltraRuntimeFailureSource
{
    /// <summary>(forward id, failure kind)</summary>
    event Action<int, string>? UltraCredentialSuspect;
}
