import Foundation

/// Retry pacing for the Ultra lease loop after a failed attempt.
///
/// Every lease attempt that did not start from a fresh `configs/pull` (the plan-time
/// `ultra_access` was used instead) can be retried immediately once: the next attempt
/// pulls a fresh grant, so a generation rotation the planned policy missed is picked
/// up in seconds instead of after the 5–60 s control-plane backoff (the dead window
/// behind "Ultra authorization unavailable", client 68, 2026-11-11). Repeated
/// failures pace normally so a broken panel is not hammered.
public enum UltraRetryDecision: Equatable {
    /// Retry right away; the next attempt starts with a fresh pull.
    case immediate
    /// Wait the given number of milliseconds (the regular backoff pacing).
    case backoff(Int64)
}

public enum UltraRetryPolicy {
    /// The delay after a failed lease attempt.
    ///
    /// - Parameters:
    ///   - pullFirstAtFailure: `true` when the failed attempt already started from a
    ///     fresh `configs/pull` (pulling again immediately would repeat the same result).
    ///   - backoffDelayMs: the delay the regular control-plane backoff prescribes.
    public static func delayMs(pullFirstAtFailure: Bool, backoffDelayMs: Int64) -> UltraRetryDecision {
        if pullFirstAtFailure {
            return .backoff(max(backoffDelayMs, 1_000))
        }
        return .immediate
    }
}
