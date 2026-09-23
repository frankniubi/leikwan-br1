using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Persistent record of the windowless client's self-update attempts. It is the
/// only thing that stops a bad artifact from turning into an update/restart
/// loop: every attempt is counted and a retry time is written *before* the
/// service is touched, and a build that failed its health gate is remembered as
/// rejected until the panel publishes something newer.
/// </summary>
public sealed class HeadlessUpdateState
{
    public const int CurrentSchema = 1;

    [JsonPropertyName("schema")]
    public int Schema { get; set; } = CurrentSchema;

    /// <summary>Version this state was last reconciled against.</summary>
    [JsonPropertyName("installed_version")]
    public string InstalledVersion { get; set; } = string.Empty;

    /// <summary>Published version the attempt counter belongs to.</summary>
    [JsonPropertyName("target_version")]
    public string TargetVersion { get; set; } = string.Empty;

    [JsonPropertyName("attempts")]
    public int Attempts { get; set; }

    /// <summary>One of <see cref="HeadlessUpdateResults"/>.</summary>
    [JsonPropertyName("last_result")]
    public string LastResult { get; set; } = string.Empty;

    /// <summary>Bounded, secret-free reason for the last result.</summary>
    [JsonPropertyName("last_error")]
    public string LastError { get; set; } = string.Empty;

    [JsonPropertyName("last_check_at")]
    public DateTimeOffset? LastCheckAt { get; set; }

    [JsonPropertyName("last_attempt_at")]
    public DateTimeOffset? LastAttemptAt { get; set; }

    [JsonPropertyName("next_retry_at")]
    public DateTimeOffset? NextRetryAt { get; set; }

    [JsonPropertyName("last_applied_version")]
    public string LastAppliedVersion { get; set; } = string.Empty;

    [JsonPropertyName("last_applied_at")]
    public DateTimeOffset? LastAppliedAt { get; set; }

    [JsonPropertyName("rejected_versions")]
    public List<string> RejectedVersions { get; set; } = [];
}

public static class HeadlessUpdateResults
{
    public const string Applied = "applied";
    public const string ApplyStarted = "apply_started";
    public const string DownloadFailed = "download_failed";
    public const string VerifyFailed = "verify_failed";
    public const string LaunchFailed = "launch_failed";
    public const string StopFailed = "stop_failed";
    public const string BackupFailed = "backup_failed";
    public const string CopyFailed = "copy_failed";
    public const string Rejected = "rejected";
    public const string RollbackFailed = "rollback_failed";
}

/// <summary>Atomic, corruption-tolerant load/save of <see cref="HeadlessUpdateState"/>.</summary>
public static class HeadlessUpdateStateStore
{
    private static readonly JsonSerializerOptions JsonOptions = new() { WriteIndented = true };

    public static HeadlessUpdateState Load(string path)
    {
        try
        {
            if (!File.Exists(path))
                return new HeadlessUpdateState();
            var state = JsonSerializer.Deserialize<HeadlessUpdateState>(File.ReadAllText(path), JsonOptions);
            if (state is null)
                return new HeadlessUpdateState();
            state.RejectedVersions ??= [];
            return state;
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException or JsonException)
        {
            // A corrupt/unreadable state file must never block updates forever;
            // the worst case of starting fresh is one extra, backed-off attempt.
            return new HeadlessUpdateState();
        }
    }

    public static void Save(string path, HeadlessUpdateState state)
    {
        var directory = Path.GetDirectoryName(path);
        if (!string.IsNullOrWhiteSpace(directory))
            Directory.CreateDirectory(directory);
        var temporary = path + ".tmp";
        File.WriteAllText(temporary, JsonSerializer.Serialize(state, JsonOptions) + Environment.NewLine, Encoding.UTF8);
        File.Move(temporary, path, overwrite: true);
    }
}

public enum HeadlessUpdateDecisionKind
{
    Disabled,
    ManifestUnavailable,
    ManifestIncomplete,
    UpToDate,
    DowngradeRefused,
    Rejected,
    BackingOff,
    ApplyInProgress,
    Apply,
}

public sealed record HeadlessUpdateDecision(
    HeadlessUpdateDecisionKind Kind,
    string? TargetVersion,
    string Reason);

/// <summary>
/// Pure scheduling/decision rules for the service-mode auto-update loop, kept
/// free of I/O so every branch is unit-testable.
/// </summary>
public static class HeadlessUpdatePolicy
{
    /// <summary>First check after service start: 1..10 minutes, so a fleet that
    /// reboots together does not hit the panel in one burst.</summary>
    public static readonly TimeSpan InitialDelayMin = TimeSpan.FromMinutes(1);
    public static readonly TimeSpan InitialDelayMax = TimeSpan.FromMinutes(10);

    public static readonly TimeSpan CheckInterval = TimeSpan.FromHours(6);
    public static readonly TimeSpan CheckJitter = TimeSpan.FromMinutes(30);

    public static readonly TimeSpan BackoffBase = TimeSpan.FromMinutes(15);
    public static readonly TimeSpan BackoffCap = TimeSpan.FromHours(24);

    /// <summary>Never schedule the next cycle sooner than this.</summary>
    public static readonly TimeSpan MinimumDelay = TimeSpan.FromMinutes(1);

    /// <summary>An "apply_started" record older than this belongs to a
    /// detached step that died without reporting back.</summary>
    public static readonly TimeSpan ApplyStaleAfter = TimeSpan.FromMinutes(30);

    public const int MaxRememberedRejections = 16;

    public static bool IsAutoUpdateEnabled(AgentConfig config)
        => !config.DisableAutoUpdates;

    public static TimeSpan InitialDelay(Random random)
        => Between(random, InitialDelayMin, InitialDelayMax);

    public static TimeSpan RegularDelay(Random random)
        => Between(random, CheckInterval - CheckJitter, CheckInterval + CheckJitter);

    /// <summary>15 min, 30 min, 1 h, 2 h, ... capped at 24 h.</summary>
    public static TimeSpan BackoffFor(int attempts)
    {
        if (attempts <= 0)
            return TimeSpan.Zero;
        var exponent = Math.Min(attempts - 1, 16);
        var ticks = BackoffBase.Ticks * (1L << exponent);
        return ticks >= BackoffCap.Ticks ? BackoffCap : TimeSpan.FromTicks(ticks);
    }

    /// <summary>Delay until the next cycle: the regular interval, or sooner when
    /// a backed-off retry is due before it.</summary>
    public static TimeSpan NextDelay(HeadlessUpdateState state, DateTimeOffset now, Random random)
    {
        var regular = RegularDelay(random);
        if (state.NextRetryAt is { } retryAt && retryAt > now)
        {
            var untilRetry = retryAt - now + TimeSpan.FromSeconds(random.Next(0, 60));
            if (untilRetry < regular)
                regular = untilRetry;
        }

        return regular < MinimumDelay ? MinimumDelay : regular;
    }

    public static bool IsNewer(string candidate, string installed)
        => WindowsAppUpdateService.IsRemoteVersionNewer(candidate, installed);

    public static bool IsSameVersion(string left, string right)
        => !IsNewer(left, right) && !IsNewer(right, left)
            && TryNormalize(left) is not null && TryNormalize(right) is not null;

    public static HeadlessUpdateDecision Decide(
        bool autoUpdatesEnabled,
        string installedVersion,
        PublishedClientUpdate? published,
        HeadlessUpdateState state,
        DateTimeOffset now)
    {
        if (!autoUpdatesEnabled)
            return new(HeadlessUpdateDecisionKind.Disabled, null, "disable_auto_updates is set");
        if (published is null)
            return new(HeadlessUpdateDecisionKind.ManifestUnavailable, null, "no manifest entry for this payload");

        var version = published.Version;
        if (string.IsNullOrWhiteSpace(version)
            || string.IsNullOrWhiteSpace(published.ArtifactUrl)
            || string.IsNullOrWhiteSpace(published.Sha256)
            || published.SizeBytes <= 0)
        {
            return new(HeadlessUpdateDecisionKind.ManifestIncomplete, version, "manifest entry lacks version, url, sha256 or size");
        }

        if (!IsNewer(version, installedVersion))
        {
            return IsNewer(installedVersion, version)
                ? new(HeadlessUpdateDecisionKind.DowngradeRefused, version, $"published {version} is older than installed {installedVersion}")
                : new(HeadlessUpdateDecisionKind.UpToDate, version, "installed build is the published one");
        }

        if (state.RejectedVersions.Any(item => IsSameVersion(item, version)))
            return new(HeadlessUpdateDecisionKind.Rejected, version, $"{version} failed its health gate earlier");

        if (IsSameVersion(state.TargetVersion, version))
        {
            if (state.LastResult == HeadlessUpdateResults.ApplyStarted
                && state.LastAttemptAt is { } started
                && now - started < ApplyStaleAfter)
            {
                return new(HeadlessUpdateDecisionKind.ApplyInProgress, version, "an apply step for this version is still running");
            }

            if (state.NextRetryAt is { } retryAt && retryAt > now)
                return new(HeadlessUpdateDecisionKind.BackingOff, version, $"attempt {state.Attempts} failed; retry after {retryAt:O}");
        }

        return new(HeadlessUpdateDecisionKind.Apply, version, $"{installedVersion} -> {version}");
    }

    /// <summary>Reconcile the state with what is actually installed: an apply
    /// that finished (even if its detached step never reported back) resets the
    /// counter.</summary>
    public static bool Reconcile(HeadlessUpdateState state, string installedVersion, DateTimeOffset now)
    {
        var changed = false;
        if (!string.IsNullOrWhiteSpace(state.TargetVersion)
            && !IsNewer(state.TargetVersion, installedVersion)
            && (state.Attempts > 0 || state.LastResult == HeadlessUpdateResults.ApplyStarted))
        {
            if (IsSameVersion(state.TargetVersion, installedVersion))
            {
                state.LastAppliedVersion = installedVersion;
                state.LastAppliedAt ??= now;
                state.LastResult = HeadlessUpdateResults.Applied;
                state.LastError = string.Empty;
            }

            state.Attempts = 0;
            state.NextRetryAt = null;
            changed = true;
        }

        if (!string.Equals(state.InstalledVersion, installedVersion, StringComparison.Ordinal))
        {
            state.InstalledVersion = installedVersion;
            changed = true;
        }

        return changed;
    }

    /// <summary>Counts the attempt and schedules its retry before anything is
    /// restarted, so a step that dies mid-way still leaves a backoff behind.</summary>
    public static void RecordApplyStarted(HeadlessUpdateState state, string version, DateTimeOffset now)
    {
        BeginAttempt(state, version);
        state.Attempts++;
        state.LastResult = HeadlessUpdateResults.ApplyStarted;
        state.LastError = string.Empty;
        state.LastAttemptAt = now;
        state.NextRetryAt = now + BackoffFor(state.Attempts);
    }

    /// <summary>A failure before the apply step started (download, verify,
    /// launch). Counts as an attempt.</summary>
    public static void RecordFailure(HeadlessUpdateState state, string version, string result, string error, DateTimeOffset now)
    {
        BeginAttempt(state, version);
        state.Attempts++;
        state.LastResult = result;
        state.LastError = Bound(error);
        state.LastAttemptAt = now;
        state.NextRetryAt = now + BackoffFor(state.Attempts);
    }

    /// <summary>A failure inside the apply step that was already counted by
    /// <see cref="RecordApplyStarted"/> (or by a manual update): keep the
    /// counter, refresh the reason and push the retry out.</summary>
    public static void RecordApplyFailure(HeadlessUpdateState state, string version, string result, string error, DateTimeOffset now)
    {
        if (!IsSameVersion(state.TargetVersion, version) || state.Attempts <= 0)
        {
            RecordFailure(state, version, result, error, now);
            return;
        }

        state.LastResult = result;
        state.LastError = Bound(error);
        state.LastAttemptAt = now;
        state.NextRetryAt = now + BackoffFor(state.Attempts);
    }

    public static void RecordRejected(HeadlessUpdateState state, string version, string reason, DateTimeOffset now)
    {
        BeginAttempt(state, version);
        if (state.Attempts <= 0)
            state.Attempts = 1;
        state.LastResult = HeadlessUpdateResults.Rejected;
        state.LastError = Bound(reason);
        state.LastAttemptAt = now;
        state.NextRetryAt = now + BackoffFor(state.Attempts);
        if (!state.RejectedVersions.Any(item => IsSameVersion(item, version)))
            state.RejectedVersions.Add(version);
        if (state.RejectedVersions.Count > MaxRememberedRejections)
            state.RejectedVersions.RemoveRange(0, state.RejectedVersions.Count - MaxRememberedRejections);
    }

    public static void RecordApplied(HeadlessUpdateState state, string version, DateTimeOffset now)
    {
        state.TargetVersion = version;
        state.InstalledVersion = version;
        state.Attempts = 0;
        state.LastResult = HeadlessUpdateResults.Applied;
        state.LastError = string.Empty;
        state.LastAttemptAt = now;
        state.NextRetryAt = null;
        state.LastAppliedVersion = version;
        state.LastAppliedAt = now;
    }

    private static void BeginAttempt(HeadlessUpdateState state, string version)
    {
        if (IsSameVersion(state.TargetVersion, version))
            return;
        state.TargetVersion = version;
        state.Attempts = 0;
        state.NextRetryAt = null;
    }

    private static TimeSpan Between(Random random, TimeSpan min, TimeSpan max)
    {
        var span = max.Ticks - min.Ticks;
        return TimeSpan.FromTicks(min.Ticks + (long)(random.NextDouble() * span));
    }

    private static Version? TryNormalize(string value)
    {
        var normalized = (value ?? string.Empty).Trim();
        var metadata = normalized.IndexOfAny(['+', '-']);
        if (metadata >= 0)
            normalized = normalized[..metadata];
        return Version.TryParse(normalized, out var parsed) ? parsed : null;
    }

    internal static string Bound(string? value)
    {
        var text = (value ?? string.Empty).Replace('\r', ' ').Replace('\n', ' ').Trim();
        return text.Length > 200 ? text[..200] : text;
    }
}
