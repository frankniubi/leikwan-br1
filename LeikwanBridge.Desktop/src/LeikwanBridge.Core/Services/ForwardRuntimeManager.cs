using System.Text.Json;
using System.Collections.Concurrent;
using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services.Platform;
using LeikwanBridge.Core.Services.Ultra;
using Serilog;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// Manages forward process lifecycles: apply (start), stop, restart.
/// </summary>
public class ForwardRuntimeManager : IDisposable
{
    private readonly AgentConfig _config;
    private readonly IProcessSupervisor _supervisor;
    private readonly BondRuntimeBuilder _bondBuilder;
    private readonly BondEndpointHealthService _bondEndpointHealth;
    private readonly MptcpRuntimeBuilder _mptcpBuilder;
    private readonly StateManager _stateManager;
    private readonly Func<string, bool> _binaryExists;
    private readonly Func<int, bool> _portListeningProbe;
    private readonly IUltraRuntimeHost _ultraRuntimeHost;
    private readonly Func<int, IReadOnlyList<PortOwner>> _proxyPortOwners;
    private readonly ConcurrentDictionary<int, object> _forwardLocks = new();
    private readonly ConcurrentDictionary<int, byte> _startingForwards = new();
    private readonly ConcurrentDictionary<int, byte> _nativeOutboundNoticed = new();
    private readonly Func<DateTimeOffset> _startupRetryClock;
    private sealed record UltraStartupFailure(string Contract, int Attempts, DateTimeOffset RetryAfter);
    private readonly ConcurrentDictionary<int, UltraStartupFailure> _ultraStartupFailures = new();
    private readonly ConcurrentDictionary<int, DateTimeOffset> _lastBreakdownLog = new();

    public event Action<int, string>? UltraRecoveryRequested;

    /// <summary>
    /// Raised instead of <see cref="UltraRecoveryRequested"/> for a runtime that is
    /// idle on purpose (awaiting or lapsed policy): it needs a lease delivered
    /// on its control channel, not a restart.
    /// </summary>
    public event Action<int, string>? UltraLeaseRefreshRequested;

    /// <summary>
    /// Raised for every runtime failure line that suggests the relay stopped
    /// accepting the forward's provider credential (the source host
    /// invalidates it when it re-acquires the line). Unthrottled: the
    /// subscriber (config sync) rate-limits the renewal per forward.
    /// </summary>
    public event Action<int, string>? UltraCredentialSuspected;

    /// <summary>
    /// Restart reason a health check uses once an idle runtime has not come
    /// back after several lease deliveries; it bypasses the idle exemption.
    /// </summary>
    public const string IdleRuntimeUnresponsiveReason = "idle_runtime_unresponsive";

    /// <summary>Policy state the running Ultra runtime last reported in its status file.</summary>
    public virtual UltraRuntimePolicyState GetUltraRuntimePolicyState(int forwardId)
    {
        var statusPath = _ultraRuntimeHost.GetStatusPath(forwardId);
        if (string.IsNullOrWhiteSpace(statusPath))
            return UltraRuntimePolicyState.Unknown;
        // A snapshot older than the last lease delivery can still say
        // "awaiting"/"lapsed" for a runtime that has just been authorized.
        return UltraRuntimeStatus.ReadPolicyState(
            statusPath,
            _lastUltraLeaseDelivery.TryGetValue(forwardId, out var delivered) ? delivered : null);
    }

    private readonly ConcurrentDictionary<int, DateTimeOffset> _lastUltraLeaseDelivery = new();

    /// <summary>
    /// True when the forward's runtime process is alive and reports that it is
    /// waiting for, or has outlived, its policy. Such a runtime is not broken.
    /// </summary>
    public virtual bool IsUltraRuntimeIdleOnPurpose(int forwardId)
        => IsForwardRunning(forwardId)
            && UltraRuntimeStatus.IsIdleOnPurpose(GetUltraRuntimePolicyState(forwardId));

    /// <summary>Asks config sync to deliver a fresh lease to the running runtime.</summary>
    public virtual void RequestUltraLeaseRefresh(int forwardId, string reason)
        => UltraLeaseRefreshRequested?.Invoke(forwardId, reason);

    public Task<ProxyForwardLease> AcquireProxyForwardAsync(ForwardConfig forward, CancellationToken ct = default)
    {
        ArgumentNullException.ThrowIfNull(forward);
        var running = IsForwardRunning(forward.ForwardId) || IsForwardStarting(forward.ForwardId);
        if (string.Equals(forward.BackendMode, "ultra", StringComparison.OrdinalIgnoreCase)
            && !IsNativeOutboundForward(forward)
            && forward.UltraAccess is null
            && !running)
        {
            Log.Warning(
                "ordinary proxy forward {ForwardId} cannot start because Ultra policy is missing (runtime_running={RuntimeRunning})",
                forward.ForwardId,
                running);
            return Task.FromException<ProxyForwardLease>(
                new InvalidOperationException("proxy_forward_policy_unavailable"));
        }

        return ProxyForwardLease.AcquireAsync(
            forward,
            () => IsForwardRunning(forward.ForwardId) || IsForwardStarting(forward.ForwardId),
            token => Task.Run(() => RestartForward(forward, force: true, reason: "manual_apply"), token),
            _ => Task.Run(() => StopForward(forward.ForwardId)),
            ct,
            async (candidate, token) => !IsForwardStarting(candidate.ForwardId)
                && IsForwardRunning(candidate.ForwardId)
                && HasOwnedProxyListener(candidate)
                && await ProxyForwardLease.IsListenerReadyAsync(candidate, token).ConfigureAwait(false));
    }

    private bool HasOwnedProxyListener(ForwardConfig forward)
    {
        if (IsNativeOutboundForward(forward))
            return false;

        var pids = _supervisor.GetProcessIds(forward.ForwardId);
        var owners = _proxyPortOwners(forward.ListenPort);
        if (owners.Count == 0)
        {
            Log.Warning(
                "proxy listener ownership table unreadable for forward {ForwardId} port {Port} transport {Transport}; falling back to listener readiness probe",
                forward.ForwardId,
                forward.ListenPort,
                forward.Transport);
            return ProxyForwardLease.IsListenerReadyAsync(forward).GetAwaiter().GetResult();
        }
        var protocols = forward.Transport.Trim().ToLowerInvariant() switch
        {
            "tcp" => new[] { "tcp" },
            "udp" => new[] { "udp" },
            "tcp+udp" => new[] { "tcp", "udp" },
            _ => Array.Empty<string>(),
        };
        return protocols.Length > 0 && protocols.All(protocol =>
            owners.Any(owner => owner.Protocol == protocol && pids.Contains(owner.Pid))
            && owners.Where(owner => owner.Protocol == protocol).All(owner => pids.Contains(owner.Pid)));
    }

    public bool IsForwardStarting(int forwardId) => _startingForwards.ContainsKey(forwardId);
    public bool IsForwardReady(ForwardConfig forward) => !IsNativeOutboundForward(forward)
        && !IsForwardStarting(forward.ForwardId)
        && IsForwardRunning(forward.ForwardId)
        && (forward.ListenPort <= 0 || _portListeningProbe(forward.ListenPort));

    public static bool IsNativeOutboundForward(ForwardConfig forward)
        => forward.IsNativeOutboundRule;

    public ForwardRuntimeManager(
        AgentConfig config,
        IProcessSupervisor supervisor,
        StateManager stateManager,
        Func<string, bool>? binaryExists = null,
        Func<int, bool>? portListeningProbe = null,
        Func<int, UltraLease, CancellationToken, Task<bool>>? ultraLeaseSender = null,
        Func<int, PortPreflightResult>? portPreflight = null,
        IUltraRuntimeHost? ultraRuntimeHost = null,
        Func<int, IReadOnlyList<PortOwner>>? proxyPortOwners = null,
        Func<DateTimeOffset>? startupRetryClock = null)
    {
        _config = config;
        _supervisor = supervisor;
        _bondBuilder = new BondRuntimeBuilder(config);
        _mptcpBuilder = new MptcpRuntimeBuilder(config);
        _bondEndpointHealth = new BondEndpointHealthService(stateManager);
        _stateManager = stateManager;
        _startupRetryClock = startupRetryClock ?? (() => DateTimeOffset.UtcNow);
        _binaryExists = binaryExists ?? File.Exists;
        _portListeningProbe = portListeningProbe ?? NetworkProbe.IsPortListening;
        _proxyPortOwners = proxyPortOwners ?? (OperatingSystem.IsWindows()
            ? PortOwnerEnumerator.FindOwners : _ => []);
        _ultraRuntimeHost = ultraRuntimeHost ?? new WindowsUltraRuntimeHost(
            config,
            supervisor,
            stateManager,
            _binaryExists,
            _portListeningProbe,
            ultraLeaseSender,
            portPreflight);
        if (supervisor is IUltraRuntimeFailureSource failures)
            failures.UltraCredentialSuspect += (forwardId, kind) => UltraCredentialSuspected?.Invoke(forwardId, kind);
    }

    public virtual void ApplyForward(ForwardConfig forward)
    {
        ApplyForward(forward, contractSignature: null, applyReason: "manual_apply");
    }

    public virtual void ApplyForward(ForwardConfig forward, string? contractSignature, string applyReason)
    {
        lock (LockFor(forward.ForwardId))
        {
            var applied = _stateManager.LoadApplied();
            applied.TryGetValue(forward.ForwardId.ToString(), out var previousState);
            if (IsNativeOutboundForward(forward))
            {
                SuppressOrdinaryUltraRuntime(forward);
                return;
            }
            // A lease/config request can complete after the user stopped its
            // forward. Re-check under the runtime lock before applying it.
            if (previousState?.UserStopped == true && applyReason == "config_sync")
            {
                DisposeLeases(forward.RuntimeLeases.Concat(forward.RuntimeLease is null ? [] : new[] { forward.RuntimeLease }));
                forward.RuntimeLease = null;
                forward.RuntimeLeases.Clear();
                return;
            }
            _startingForwards[forward.ForwardId] = 0;
            try { ApplyForwardCore(forward, previousState, contractSignature, applyReason); }
            finally { _startingForwards.TryRemove(forward.ForwardId, out _); }
        }
    }

    public virtual void ApplyAuthoritativeAgentConfig(JsonElement? agentConfigJson)
    {
        _config.ApplyAuthoritativeConfig(agentConfigJson);
    }

    public virtual string ComputeContractSignature(ForwardConfig forward)
    {
        // The external UoT grant lives on the lease candidates, exactly like the
        // provider material, so the signature may only be computed while those
        // leases are attached to the forward being compared/applied.
        var externalUot = forward.RuntimeLeases.Count > 0
            ? ExternalUotGrant.Select(forward.RuntimeLeases)
            : forward.RuntimeLease?.ExternalUot;
        var sidepath = _config.UdpUotSidepath;
        if (sidepath == null || !sidepath.EnabledForwardIds.Contains(forward.ForwardId))
            return ForwardContractSignature.Compute(forward, externalUot: externalUot);

        var uotIdentity = UotRuntimeCapabilityProbe.BuildIdentity(_config.GetEffectiveUotBondBin());
        return ForwardContractSignature.Compute(forward, sidepath, uotIdentity, externalUot);
    }

    private void ApplyForwardCore(ForwardConfig forward, AppliedForwardState? previousState, string? contractSignature, string applyReason)
    {
        if (IsNativeOutboundForward(forward))
        {
            SuppressOrdinaryUltraRuntime(forward);
            return;
        }
        if (string.Equals(forward.BackendMode, "ultra", StringComparison.OrdinalIgnoreCase))
        {
            ApplyUltraForward(forward, previousState, contractSignature, applyReason);
            return;
        }
        var selectedForward = forward;
        var forwardId = selectedForward.ForwardId;
        var mode = ModeNormalizer.Normalize(selectedForward.Mode);
        if (mode == "mptcp" && !HasUsableMptcpRelay(selectedForward))
        {
            selectedForward = MptcpFallbackService.ConvertToBond(selectedForward);
            mode = ModeNormalizer.Normalize(selectedForward.Mode);
        }
        var frontendMode = WindowsForwardPolicy.GetSidecarFrontendMode(selectedForward);


        Log.Information("applying forward {ForwardId} mode={Mode} transport={Transport} endpoints={Count}",
            forwardId, mode, selectedForward.Transport, selectedForward.Endpoints.Count);

        BondRuntimeBuilder.RuntimeSpec spec;
        try
        {
            spec = frontendMode != ""
                ? _bondBuilder.BuildFrontend(WithSelectedBondRuntimeEndpoints(selectedForward))
                : mode switch
                {
                    "bond" => _bondBuilder.Build(WithSelectedBondRuntimeEndpoints(selectedForward)),
                    "race" => _bondBuilder.BuildRace(selectedForward),
                    "mptcp" => _mptcpBuilder.Build(selectedForward),
                    _ => _bondBuilder.BuildSingle(selectedForward),
                };
            if (RequiresFeedbackScheduler(selectedForward))
                UotRuntimeCapabilityProbe.RequireFeedbackCapability(_config.GetEffectiveUotBondBin());
        }
        catch (Exception ex)
        {
            Log.Error(ex, "failed to validate runtime spec for forward {ForwardId}; existing runtime preserved", forwardId);
            return;
        }

        PinForwardEgressHosts(selectedForward);

        // Validation above is intentionally complete before replacing the running runtime.
        _supervisor.StopAll(forwardId);
        WaitForPortsReleased(PortsForReleaseWait(previousState, selectedForward.ListenPort), _portListeningProbe, TimeSpan.FromSeconds(3));

        foreach (var file in spec.GeneratedFiles ?? [])
        {
            var directory = Path.GetDirectoryName(file.FilePath);
            if (!string.IsNullOrWhiteSpace(directory))
                Directory.CreateDirectory(directory);
            File.WriteAllText(file.FilePath, file.Content);
        }

        foreach (var process in spec.Processes.Where(p => p.RequiredForReadiness))
        {
            if (!_binaryExists(process.ExePath))
            {
                Log.Error("binary not found: {ExePath} for forward {ForwardId} [{Label}]", process.ExePath, forwardId, process.Label);
                continue;
            }
            _supervisor.StartProcess(forwardId, process.Role, process.Label, process.ExePath, process.Arguments);
        }

        if (spec.LegPorts.Count > 0 && !WaitForRequiredPorts(spec.LegPorts, _portListeningProbe, TimeSpan.FromSeconds(15)))
        {
            Log.Error("forward {ForwardId}: required leg ports did not become ready", forwardId);
            _supervisor.StopAll(forwardId);
            return;
        }

        foreach (var process in spec.Processes.Where(p => !p.RequiredForReadiness))
        {
            if (!_binaryExists(process.ExePath))
            {
                Log.Error("binary not found: {ExePath} for forward {ForwardId} [{Label}]", process.ExePath, forwardId, process.Label);
                continue;
            }
            _supervisor.StartProcess(forwardId, process.Role, process.Label, process.ExePath, process.Arguments);
        }

        // Save only this forward atomically; sibling forward applies may be
        // running concurrently during a config reconciliation.
        var state = new AppliedForwardState
        {
            ForwardId = forwardId,
            Mode = mode,
            ListenPort = selectedForward.ListenPort,
            IngressMode = spec.IngressMode,
            EndpointCount = selectedForward.Endpoints.Count,
            LegPorts = [.. spec.LegPorts],
            ProcessRoles = [.. spec.Processes.Select(process => process.Role)],
            ProcessLabels = [.. spec.Processes.Select(process => process.Label)],
            ProcessIds = [.. _supervisor.GetProcessIds(forwardId)],
            ContractSignature = contractSignature ?? previousState?.ContractSignature ?? string.Empty,
            LastApplyReason = string.IsNullOrWhiteSpace(applyReason)
                ? previousState?.LastApplyReason ?? "config_sync"
                : applyReason,
            UserStopped = false,
            UpdatedAt = DateTimeOffset.UtcNow.ToUnixTimeSeconds(),
        };
        _stateManager.UpdateApplied(current => current[forwardId.ToString()] = state);
    }

    private void PinForwardEgressHosts(ForwardConfig forward)
    {
        if (string.IsNullOrWhiteSpace(_config.PreferredEgressInterface))
            return;

        var hosts = new HashSet<string>(StringComparer.OrdinalIgnoreCase);
        AddHost(hosts, forward.Target);
        foreach (var endpoint in forward.Endpoints)
            AddHost(hosts, endpoint.Host);

        if (forward.Bonding is { } bonding)
        {
            AddHost(hosts, bonding.SidecarHost);
            AddHost(hosts, bonding.AggregatorHost);
            AddHost(hosts, bonding.RelayHost);
            AddHost(hosts, bonding.RemoteTarget);
        }

        if (forward.Mptcp is { } mptcp)
        {
            AddHost(hosts, mptcp.SidecarHost);
            AddHost(hosts, mptcp.RemoteHost);
            AddHost(hosts, mptcp.RelayHost);
            AddHost(hosts, mptcp.SlaveRelayHost);
        }

        foreach (var host in hosts)
            PanelDirectEgress.RegisterHost(host);

        static void AddHost(HashSet<string> hosts, string? host)
        {
            var value = (host ?? string.Empty).Trim();
            if (value.Length > 0)
                hosts.Add(value);
        }
    }

    private void ApplyUltraForward(ForwardConfig forward, AppliedForwardState? previousState, string? contractSignature, string applyReason)
    {
        if (IsNativeOutboundForward(forward))
        {
            SuppressOrdinaryUltraRuntime(forward);
            return;
        }
        var policy = forward.UltraAccess;
        var leases = forward.RuntimeLeases.Count > 0
            ? forward.RuntimeLeases.ToList()
            : forward.RuntimeLease is null ? [] : [forward.RuntimeLease];
        var now = DateTimeOffset.UtcNow;
        var policyFailure = policy?.GetValidationFailure(forward, now);
        var usableLeases = policy is null
            ? []
            : leases.Where(candidate => candidate.IsUsable(policy, forward, now)).ToList();
        if (policy is null || usableLeases.Count == 0 || policyFailure is not null)
        {
            // Breakdown analysis: identify why leases were rejected
            Dictionary<string, int>? breakdown = null;
            if (usableLeases.Count == 0 && policy is not null && leases.Count > 0)
            {
                breakdown = leases
                    .Select(candidate => candidate.GetUsabilityFailureReason(policy, forward, now) ?? "unknown")
                    .GroupBy(reason => reason)
                    .OrderBy(group => group.Key)
                    .ToDictionary(group => group.Key, group => group.Count());
            }

            // Stale lease material should trigger a targeted refresh, but it is
            // not proof that the currently running process still holds a valid
            // authorization. Fail closed until a fresh usable lease arrives.
            var staleLeaseSet = policy is not null
                && policyFailure is null
                && leases.Count > 0
                && usableLeases.Count == 0
                && breakdown is not null
                && breakdown.All(kv => kv.Key is "expiry" or "policyGeneration");

            if (staleLeaseSet)
            {
                _ultraStartupFailures.TryRemove(forward.ForwardId, out _);
                UltraRecoveryRequested?.Invoke(forward.ForwardId, "stale_policy_or_lease");
            }

            if (breakdown is not null)
            {
                var canLog = !_lastBreakdownLog.TryGetValue(forward.ForwardId, out var lastLog)
                    || (now - lastLog).TotalSeconds >= 60;
                if (canLog)
                {
                    _lastBreakdownLog[forward.ForwardId] = now;
                    var breakdownText = string.Join(", ", breakdown.Select(kv => $"{kv.Key}={kv.Value}"));
                    Log.Warning(
                        "Ultra lease usability breakdown for forward {ForwardId}: {Breakdown}",
                        forward.ForwardId,
                        breakdownText);
                }
            }
            Log.Warning(
                "Ultra policy or lease rejected for forward {ForwardId}; stopping without legacy fallback (policy_reason={PolicyReason}, lease_count={LeaseCount}, usable_count={UsableCount})",
                forward.ForwardId,
                policy is null ? "missing" : policyFailure ?? "ok",
                leases.Count,
                usableLeases.Count);
            DisposeLeases(leases);
            forward.RuntimeLease = null;
            forward.RuntimeLeases.Clear();
            StopForwardForPolicy(forward.ForwardId);
            return;
        }
        var effectiveSignature = contractSignature ?? ComputeContractSignature(forward);
        try
        {
            // Validation above always runs, even while cooling down: a revoked
            // or expired policy must stop the forward, not be hidden by retries.
            if (string.Equals(applyReason, "manual_apply", StringComparison.Ordinal)
                || (_ultraStartupFailures.TryGetValue(forward.ForwardId, out var oldFailure)
                    && !string.Equals(oldFailure.Contract, effectiveSignature, StringComparison.Ordinal)))
                _ultraStartupFailures.TryRemove(forward.ForwardId, out _);
            if (_ultraStartupFailures.TryGetValue(forward.ForwardId, out var failure)
                && failure.RetryAfter > _startupRetryClock())
            {
                Log.Debug("Ultra startup retry deferred for forward {ForwardId} until {RetryAfter}",
                    forward.ForwardId, failure.RetryAfter);
                return;
            }
            var deliveryStartedAt = DateTimeOffset.UtcNow;
            _ultraRuntimeHost.Start(
                forward,
                usableLeases,
                effectiveSignature,
                string.IsNullOrWhiteSpace(applyReason) ? "config_sync" : applyReason);
            _lastUltraLeaseDelivery[forward.ForwardId] = deliveryStartedAt;
            _ultraStartupFailures.TryRemove(forward.ForwardId, out _);
        }
        catch (Exception ex)
        {
            var attempts = _ultraStartupFailures.TryGetValue(forward.ForwardId, out var previousFailure)
                && string.Equals(previousFailure.Contract, effectiveSignature, StringComparison.Ordinal)
                ? Math.Min(previousFailure.Attempts + 1, 5)
                : 1;
            var delaySeconds = Math.Min(300, 30 * (1 << (attempts - 1)));
            _ultraStartupFailures[forward.ForwardId] = new UltraStartupFailure(
                effectiveSignature, attempts, _startupRetryClock().AddSeconds(delaySeconds));
            Log.Warning(ex, "Ultra runtime start failed for forward {ForwardId} ({ErrorType})",
                forward.ForwardId, ex.GetType().Name);
            StopForwardForPolicy(forward.ForwardId);
        }
        finally
        {
            DisposeLeases(leases);
            forward.RuntimeLease = null;
            forward.RuntimeLeases.Clear();
        }
    }

    public virtual void StopForward(int forwardId)
    {
        lock (LockFor(forwardId))
        {
            Log.Information("stopping forward {ForwardId}", forwardId);
            _ultraRuntimeHost.Stop(forwardId);

            var applied = _stateManager.LoadApplied();
            if (!applied.TryGetValue(forwardId.ToString(), out var state))
            {
                state = new AppliedForwardState { ForwardId = forwardId };
            }

            state.ProcessIds = [];
            state.UserStopped = true;
            state.UpdatedAt = DateTimeOffset.UtcNow.ToUnixTimeSeconds();
            _stateManager.UpdateApplied(current => current[forwardId.ToString()] = state);
        }
    }

    public virtual void StopForwardForPolicy(int forwardId)
    {
        lock (LockFor(forwardId))
        {
            StopForward(forwardId);
            var applied = _stateManager.LoadApplied();
            if (!applied.TryGetValue(forwardId.ToString(), out var state))
                return;
            state.UserStopped = false;
            state.UpdatedAt = DateTimeOffset.UtcNow.ToUnixTimeSeconds();
            _stateManager.UpdateApplied(current => current[forwardId.ToString()] = state);
        }
    }

    public virtual void RestartForward(ForwardConfig forward)
    {
        RestartForward(forward, force: true, reason: "manual_apply");
    }

    public virtual void RestartForward(ForwardConfig forward, bool force, string reason)
    {
        if (IsNativeOutboundForward(forward))
        {
            lock (LockFor(forward.ForwardId))
                SuppressOrdinaryUltraRuntime(forward);
            return;
        }
        var ultra = string.Equals(forward.BackendMode, "ultra", StringComparison.OrdinalIgnoreCase);
        // Health recovery must never wait behind a slow lease delivery and then
        // destroy the now-ready runtime using a credential-free catalog snapshot.
        if (ultra && IsForwardStarting(forward.ForwardId))
            return;
        var manual = string.Equals(reason, "manual_apply", StringComparison.Ordinal);
        lock (LockFor(forward.ForwardId))
        {
            if (ultra)
            {
                if (manual)
                    _ultraStartupFailures.TryRemove(forward.ForwardId, out _);
                var state = _stateManager.LoadApplied().GetValueOrDefault(forward.ForwardId.ToString());
                if (!manual && (state?.UserStopped == true || IsForwardReady(forward)))
                    return;
                // A runtime that waits for (or outlived) its policy is idle on
                // purpose. Queueing a recovery would make the next reconcile
                // restart it; it only needs a lease on its control channel.
                if (!manual
                    && !string.Equals(reason, IdleRuntimeUnresponsiveReason, StringComparison.Ordinal)
                    && IsUltraRuntimeIdleOnPurpose(forward.ForwardId))
                {
                    Log.Information(
                        "forward {ForwardId}: Ultra runtime is idle waiting for a policy; requesting a lease instead of a restart (reason={Reason})",
                        forward.ForwardId,
                        reason);
                    RequestUltraLeaseRefresh(forward.ForwardId, reason);
                    return;
                }
                if (manual && state?.UserStopped == true)
                {
                    state.UserStopped = false;
                    _stateManager.UpdateApplied(current => current[forward.ForwardId.ToString()] = state);
                }
                var leases = forward.RuntimeLeases.Count > 0
                    ? forward.RuntimeLeases
                    : forward.RuntimeLease is null ? [] : new List<UltraLease> { forward.RuntimeLease };
                var hasFreshLease = forward.UltraAccess is { } policy
                    && leases.Any(lease => lease.IsUsable(policy, forward, DateTimeOffset.UtcNow));
                // Catalog/UI snapshots deliberately have no retained credentials.
                // A manual Start from such a snapshot also needs lease-aware recovery.
                if (!manual || !hasFreshLease)
                {
                    UltraRecoveryRequested?.Invoke(forward.ForwardId, reason);
                    return;
                }
            }
            var applied = _stateManager.LoadApplied();
            applied.TryGetValue(forward.ForwardId.ToString(), out var previousState);

            var effectiveForward = EffectiveForwardConfigBuilder.Build(forward);
            var contractSignature = ComputeContractSignature(effectiveForward);
            var signatureMatches = previousState != null
                && string.Equals(previousState.ContractSignature, contractSignature, StringComparison.Ordinal);
            if (!force && signatureMatches && IsForwardRunning(forward.ForwardId))
                return;

            _startingForwards[forward.ForwardId] = 0;
            try
            {
                ApplyForwardCore(effectiveForward, previousState, contractSignature, string.IsNullOrWhiteSpace(reason) ? "manual_apply" : reason);
            }
            finally { _startingForwards.TryRemove(forward.ForwardId, out _); }
        }
    }

    public virtual bool RefreshUltraLease(ForwardConfig forward)
    {
        lock (LockFor(forward.ForwardId))
        {
            var leases = forward.RuntimeLeases.Count > 0
                ? forward.RuntimeLeases.ToList()
                : forward.RuntimeLease is null ? [] : [forward.RuntimeLease];
            var policy = forward.UltraAccess;
            try
            {
                if (!string.Equals(forward.BackendMode, "ultra", StringComparison.OrdinalIgnoreCase)
                    || IsNativeOutboundForward(forward)
                    || leases.Count == 0 || policy is null
                    || !policy.IsUsable(forward, DateTimeOffset.UtcNow)
                    || leases.Any(lease => !lease.IsUsable(policy, forward, DateTimeOffset.UtcNow)))
                    return false;
                var deliveryStartedAt = DateTimeOffset.UtcNow;
                var refreshed = _ultraRuntimeHost.Refresh(forward, leases);
                if (refreshed)
                {
                    _lastUltraLeaseDelivery[forward.ForwardId] = deliveryStartedAt;
                    _ultraStartupFailures.TryRemove(forward.ForwardId, out _);
                }
                return refreshed;
            }
            finally
            {
                DisposeLeases(leases);
                forward.RuntimeLease = null;
                forward.RuntimeLeases.Clear();
            }
        }
    }

    public bool IsForwardRunning(int forwardId) => _supervisor.IsRunning(forwardId);

    public void StopAll()
    {
        foreach (var forwardId in _stateManager.LoadApplied().Keys.Select(key => int.TryParse(key, out var id) ? id : 0).Where(id => id > 0))
            _ultraRuntimeHost.Stop(forwardId);
        _supervisor.StopAll();
        _stateManager.SaveApplied([]);
    }

    public void Dispose() => _supervisor.Dispose();

    private object LockFor(int forwardId)
        => _forwardLocks.GetOrAdd(forwardId, static _ => new object());

    private static void DisposeLeases(IEnumerable<UltraLease> leases)
    {
        var seen = new HashSet<UltraLease>();
        foreach (var lease in leases)
        {
            if (seen.Add(lease))
                lease.Dispose();
        }
    }

    private bool RequiresFeedbackScheduler(ForwardConfig forward)
    {
        var sidepath = _config.UdpUotSidepath;
        return sidepath != null
            && sidepath.EnabledForwardIds.Contains(forward.ForwardId)
            && string.Equals(sidepath.SchedulerMode, "feedback_drr", StringComparison.OrdinalIgnoreCase);
    }

    /// <summary>
    /// Retires any agent-owned ordinary Ultra runtime for a native outbound rule.
    /// The panel ships the rule only, so this is a one-shot transition cleanup:
    /// when nothing agent-managed remains, further calls (health cycles, config
    /// syncs, manual apply) are pure no-ops that write no log and no state.
    /// </summary>
    private void SuppressOrdinaryUltraRuntime(ForwardConfig forward)
    {
        var forwardId = forward.ForwardId;
        var leases = forward.RuntimeLeases.Count > 0
            ? forward.RuntimeLeases.ToList()
            : forward.RuntimeLease is null ? [] : [forward.RuntimeLease];
        var hasAppliedState = _stateManager.LoadApplied().ContainsKey(forwardId.ToString());
        if (leases.Count == 0 && !hasAppliedState)
        {
            NoteNativeOutboundRule(forwardId);
            return;
        }

        Log.Information("skip ordinary Ultra runtime for native outbound forward {ForwardId}", forwardId);
        try
        {
            _ultraRuntimeHost.Stop(forwardId);
            _stateManager.RemoveApplied(forwardId);
        }
        finally
        {
            DisposeLeases(leases);
            forward.RuntimeLease = null;
            forward.RuntimeLeases.Clear();
        }
    }

    private void NoteNativeOutboundRule(int forwardId)
    {
        if (_nativeOutboundNoticed.TryAdd(forwardId, 0))
        {
            Log.Debug(
                "native outbound rule forward {ForwardId}: agent-managed runtime not applicable",
                forwardId);
        }
    }

    private bool WaitForRequiredPorts(IEnumerable<int> ports, Func<int, bool> probe, TimeSpan timeout)
    {
        if (!ports.Any())
            return true;

        var deadline = DateTime.UtcNow + timeout;
        while (DateTime.UtcNow < deadline)
        {
            if (ports.All(probe))
                return true;

            Thread.Sleep(100);
        }

        return ports.All(probe);
    }

    private void WaitForPortsReleased(IEnumerable<int> ports, Func<int, bool> probe, TimeSpan timeout)
    {
        var requiredPorts = ports.Where(port => port > 0).Distinct().ToList();
        if (requiredPorts.Count == 0)
            return;

        var deadline = DateTime.UtcNow + timeout;
        while (DateTime.UtcNow < deadline)
        {
            if (requiredPorts.All(port => !probe(port)))
                return;

            Thread.Sleep(100);
        }
    }

    private static IEnumerable<int> PortsForReleaseWait(AppliedForwardState? previousState, int listenPort)
    {
        var ports = new List<int> { listenPort };
        if (previousState != null)
            ports.AddRange(previousState.LegPorts);

        return ports;
    }

    private static bool HasUsableMptcpRelay(ForwardConfig forward)
    {
        var mptcp = forward.Mptcp;
        if (mptcp is null)
            return false;

        if (!string.IsNullOrWhiteSpace(mptcp.SlaveRelayHost) && mptcp.SlaveRelayPort > 0)
            return true;

        if (!string.IsNullOrWhiteSpace(mptcp.RelayHost) && mptcp.RelayPort > 0)
            return true;

        if (!string.IsNullOrWhiteSpace(mptcp.RemoteHost) && mptcp.RemotePort > 0)
            return true;

        if (!string.IsNullOrWhiteSpace(mptcp.SidecarHost) && mptcp.SidecarPort > 0)
            return true;

        return false;
    }

    private ForwardConfig WithSelectedBondRuntimeEndpoints(ForwardConfig forward)
    {
        if (!WindowsForwardPolicy.UsesBondRuntime(forward)
            || !string.Equals(forward.Bonding?.PolicyMode, "latency_first", StringComparison.OrdinalIgnoreCase))
            return forward;

        var selectedEndpoints = _bondEndpointHealth.SelectRuntimeEndpoints(forward);
        return new ForwardConfig
        {
            ForwardId = forward.ForwardId,
            ServiceStatus = forward.ServiceStatus,
            Mode = forward.Mode,
            BackendMode = forward.BackendMode,
            ContractVersion = forward.ContractVersion,
            ClientMode = forward.ClientMode,
            RuntimePreference = forward.RuntimePreference,
            ForwardCombinationDirection = forward.ForwardCombinationDirection,
            BondIngressMode = forward.BondIngressMode,
            ActiveFamily = forward.ActiveFamily,
            AggregationEnabled = forward.AggregationEnabled,
            TargetContract = forward.TargetContract,
            SidecarRequired = forward.SidecarRequired,
            BondSidecarPointId = forward.BondSidecarPointId,
            ListenPort = forward.ListenPort,
            Target = forward.Target,
            Sni = forward.Sni,
            PathPrefix = forward.PathPrefix,
            Transport = forward.Transport,
            SpeedLimitMbps = forward.SpeedLimitMbps,
            NativeOutboundEnabled = forward.NativeOutboundEnabled,
            NativeDirection = forward.NativeDirection,
            Endpoints = selectedEndpoints,
            Bonding = forward.Bonding,
            Mptcp = forward.Mptcp,
        };
    }

}
