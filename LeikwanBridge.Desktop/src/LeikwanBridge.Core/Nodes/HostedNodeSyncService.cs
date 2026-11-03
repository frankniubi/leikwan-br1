namespace LeikwanBridge.Core.Nodes;

/// <summary>Outcome of "sync all local nodes to cloud".</summary>
public sealed record HostedBulkUploadResult(
    int Uploaded,
    int AlreadyHosted,
    int Unsupported,
    int Failed,
    IReadOnlyList<HostedNodeUploadFailure> Failures);

public sealed record HostedNodeUploadFailure(int LocalId, string Name, string Error, string? Field);

/// <summary>Outcome of importing pasted share links.</summary>
public sealed record UserNodeImportResult(IReadOnlyList<UserNode> Added, int Duplicates, int Invalid, string FirstError);

/// <summary>Observable state of the sync service (no credentials).</summary>
public sealed record HostedNodeSyncStatus(
    bool IsBusy,
    int ClientId,
    string Revision,
    DateTimeOffset? LastSyncedAt,
    string LastError,
    IReadOnlyList<HostedNodePairing> Pairings)
{
    public static HostedNodeSyncStatus Initial { get; } = new(false, 0, string.Empty, null, string.Empty, []);
}

/// <summary>
/// Two-way sync of the client's hosted nodes ("上云") with this device. Platform neutral:
/// the Windows and macOS apps differ only in the <see cref="IUserNodeRepository"/> and
/// <see cref="IHostedNodeSyncStateStore"/> they pass in.
///
/// Model (docs/plans/2026-10-12-hosted-user-node-pairing.md §7, same as Android):
/// <list type="bullet">
/// <item>The panel is the source of truth for hosted nodes, keyed by client id. A hosted
/// node is a local node with <see cref="UserNode.HostedId"/> set; it caches the panel copy.</item>
/// <item>Writes are write-through: upload, edit, delete and pairing call the panel first
/// and then apply the listing it returns. Nothing is queued offline, so the device copy
/// never diverges and the panel's arrival order is the only ordering (last writer wins).</item>
/// <item>Cloud → local: when the pull's hosted_nodes_revision moves (or another client
/// signs in) the listing is fetched and materialized. Rows match by hosted id; a new hosted
/// node equal to a local node (<see cref="UserNodeIdentity"/>) adopts that row; hosted rows
/// the panel no longer lists are deleted.</item>
/// <item>Local-only nodes are never uploaded or changed by sync; the user uploads them
/// explicitly (one node or all of them).</item>
/// </list>
/// All operations are serialized. Events fire on the calling thread after the gate is released.
/// </summary>
public sealed class HostedNodeSyncService
{
    /// <summary>Panel limit per batch (HostedNodeService::MAX_NODES_PER_CLIENT).</summary>
    public const int MaxBatch = 32;

    private readonly Func<IHostedNodeApi?> _apiFactory;
    private readonly IUserNodeRepository _repository;
    private readonly IHostedNodeSyncStateStore _stateStore;
    private readonly Func<int> _clientId;
    private readonly Func<DateTimeOffset> _clock;
    private readonly SemaphoreSlim _gate = new(1, 1);
    private HostedNodeSyncState _state = HostedNodeSyncState.Empty;
    private HostedNodeSyncStatus _status = HostedNodeSyncStatus.Initial;
    private bool _initialized;

    /// <param name="api">
    /// The signed-in client's panel API (normally the app's current <see cref="Services.MasterApiClient"/>);
    /// read on every call because the apps recreate it when the user signs in again. Null means
    /// signed out: calls fail with hosted_nodes_offline.
    /// </param>
    /// <param name="repository">The device's node list.</param>
    /// <param name="stateStore">Where the last synced revision and pairings are kept.</param>
    /// <param name="clientId">The signed-in client id (0 when signed out).</param>
    public HostedNodeSyncService(
        Func<IHostedNodeApi?> api,
        IUserNodeRepository repository,
        IHostedNodeSyncStateStore stateStore,
        Func<int> clientId,
        Func<DateTimeOffset>? clock = null)
    {
        _apiFactory = api ?? throw new ArgumentNullException(nameof(api));
        _repository = repository ?? throw new ArgumentNullException(nameof(repository));
        _stateStore = stateStore ?? throw new ArgumentNullException(nameof(stateStore));
        _clientId = clientId ?? throw new ArgumentNullException(nameof(clientId));
        _clock = clock ?? (() => DateTimeOffset.UtcNow);
    }

    public HostedNodeSyncService(
        IHostedNodeApi api,
        IUserNodeRepository repository,
        IHostedNodeSyncStateStore stateStore,
        Func<int> clientId,
        Func<DateTimeOffset>? clock = null)
        : this(() => api, repository, stateStore, clientId, clock)
    {
        ArgumentNullException.ThrowIfNull(api);
    }

    private IHostedNodeApi Api => _apiFactory() ?? throw new HostedNodeException("hosted_nodes_offline");

    /// <summary>Busy flag, last sync time, last error code and the resolved pairings.</summary>
    public event Action<HostedNodeSyncStatus>? StatusChanged;

    /// <summary>The local node list changed (materialized listing, upload, import, delete).</summary>
    public event Action? NodesChanged;

    /// <summary>
    /// Panel pairings that appeared or now point at another node since the previous sync.
    /// A UI applies these as the forward's selected node (console or another device changed
    /// them); unchanged pairings are not repeated, so a local-only pick on this device stays.
    /// </summary>
    public event Action<IReadOnlyList<HostedNodePairing>>? PanelPairingsChanged;

    public HostedNodeSyncStatus Status => _status;

    public IReadOnlyList<HostedNodePairing> Pairings => _state.Pairings;

    /// <summary>The local node the panel pairs with <paramref name="forwardId"/>, if any.</summary>
    public HostedNodePairing? PairingFor(int forwardId)
        => _state.Pairings.FirstOrDefault(pairing => pairing.ForwardId == forwardId);

    public async Task InitializeAsync(CancellationToken ct = default)
    {
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        try { await EnsureInitializedAsync(ct).ConfigureAwait(false); }
        finally { _gate.Release(); }
        PublishStatus(_status with { ClientId = _state.ClientId, Revision = _state.Revision, LastSyncedAt = _state.SyncedAt, Pairings = _state.Pairings });
    }

    /// <summary>
    /// Call after every config pull with <c>client.hosted_nodes_revision</c>. Null (an older
    /// panel) does nothing. Never throws for panel or network failures: those land in
    /// <see cref="Status"/>.LastError and the next pull retries. Returns true when a sync ran.
    /// </summary>
    public async Task<bool> SyncIfRevisionChangedAsync(string? revision, CancellationToken ct = default)
    {
        if (revision is null)
            return false;
        var clientId = _clientId();
        if (clientId <= 0)
            return false;
        var fetch = false;
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        try
        {
            await EnsureInitializedAsync(ct).ConfigureAwait(false);
            if (_state.ClientId == clientId && string.Equals(_state.Revision, revision, StringComparison.Ordinal))
                return false;
            if (revision.Length == 0 && !(await _repository.LoadAsync(ct).ConfigureAwait(false)).Any(node => node.IsHosted))
            {
                // Nothing hosted on either side: record it without a fetch.
                _state = new HostedNodeSyncState(clientId, string.Empty, [], _clock());
                await _stateStore.SaveAsync(_state, ct).ConfigureAwait(false);
            }
            else
            {
                fetch = true;
            }
        }
        finally { _gate.Release(); }
        if (!fetch)
            return false;
        try
        {
            await RefreshAsync(ct).ConfigureAwait(false);
            return true;
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
            throw;
        }
        catch
        {
            // Recorded in Status by RunAsync; the stored revision is unchanged, so the next pull retries.
            return false;
        }
    }

    /// <summary>"Refresh from cloud": fetch the listing and materialize it.</summary>
    public Task RefreshAsync(CancellationToken ct = default)
        => RunAsync(async () =>
        {
            await ApplyAsync(await Api.ListHostedNodesAsync(ct).ConfigureAwait(false), ct).ConfigureAwait(false);
            return true;
        }, ct);

    /// <summary>Uploads one local node; the same local row becomes the hosted row. Optionally pairs it with a forward.</summary>
    public Task<UserNode> UploadAsync(int localId, int? pairForwardId = null, CancellationToken ct = default)
        => RunAsync(async () =>
        {
            var node = await FindAsync(localId, ct).ConfigureAwait(false)
                ?? throw new HostedNodeException("node_not_found");
            if (node.IsHosted)
                return node;
            var uri = ShareUriOrThrow(node);
            var response = await Api.CreateHostedNodeAsync(
                new HostedNodeUploadRequest(uri, node.Name, pairForwardId is > 0 ? pairForwardId : null), ct).ConfigureAwait(false);
            var hostedId = response.NodeId is > 0 ? response.NodeId.Value : throw new HostedNodeException("hosted_node_invalid");
            await LinkLocalRowAsync(node, hostedId, ct).ConfigureAwait(false);
            await ApplyAsync(response, ct).ConfigureAwait(false);
            var all = await _repository.LoadAsync(ct).ConfigureAwait(false);
            return all.FirstOrDefault(item => item.LocalId == localId)
                ?? all.FirstOrDefault(item => item.HostedId == hostedId)
                ?? node;
        }, ct);

    /// <summary>"Sync all to cloud": every local node the panel accepts, in batches of <see cref="MaxBatch"/>.</summary>
    public Task<HostedBulkUploadResult> UploadAllLocalAsync(CancellationToken ct = default)
        => RunAsync(async () =>
        {
            var local = (await _repository.LoadAsync(ct).ConfigureAwait(false)).Where(node => !node.IsHosted).ToList();
            var uploadable = local.Where(node => node.IsCloudSupported && UserNodeShareLink.Format(node) is not null).ToList();
            var unsupported = local.Count - uploadable.Count;
            int uploaded = 0, already = 0, failed = 0;
            var failures = new List<HostedNodeUploadFailure>();
            foreach (var chunk in uploadable.Chunk(MaxBatch))
            {
                var entries = chunk
                    .Select(node => new HostedNodeBatchEntry(node.LocalId.ToString(System.Globalization.CultureInfo.InvariantCulture), UserNodeShareLink.Format(node)!, node.Name))
                    .ToList();
                var response = await Api.BatchCreateHostedNodesAsync(new HostedNodeBatchRequest(entries), ct).ConfigureAwait(false);
                var byRef = chunk.ToDictionary(node => node.LocalId.ToString(System.Globalization.CultureInfo.InvariantCulture), StringComparer.Ordinal);
                foreach (var result in response.Results ?? [])
                {
                    if (result.Ref is null || !byRef.TryGetValue(result.Ref, out var node))
                        continue;
                    if (result.Ok && result.NodeId is > 0)
                    {
                        await LinkLocalRowAsync(node, result.NodeId.Value, ct).ConfigureAwait(false);
                        if (result.Created == true) uploaded++; else already++;
                    }
                    else
                    {
                        failed++;
                        failures.Add(new HostedNodeUploadFailure(node.LocalId, node.Name, result.Error ?? "hosted_node_invalid", result.Field));
                    }
                }
                await ApplyAsync(response, ct).ConfigureAwait(false);
            }
            return new HostedBulkUploadResult(uploaded, already, unsupported, failed, failures);
        }, ct);

    /// <summary>
    /// Saves an edited node. A hosted node goes to the panel first (the local copy changes
    /// only once the panel accepted it); a local-only node is saved on this device.
    /// </summary>
    public Task<UserNode> SaveAsync(UserNode edited, CancellationToken ct = default)
        => RunAsync(async () =>
        {
            ArgumentNullException.ThrowIfNull(edited);
            if (edited.HostedId is not > 0)
            {
                var saved = await _repository.SaveAsync(edited, ct).ConfigureAwait(false);
                NodesChanged?.Invoke();
                return saved;
            }
            var uri = ShareUriOrThrow(edited);
            await ApplyAsync(await Api.UpdateHostedNodeAsync(edited.HostedId.Value, new HostedNodeUpdateRequest(uri, edited.Name), ct).ConfigureAwait(false), ct).ConfigureAwait(false);
            return (await _repository.LoadAsync(ct).ConfigureAwait(false)).FirstOrDefault(item => item.HostedId == edited.HostedId) ?? edited;
        }, ct);

    /// <summary>Deletes a node. A hosted node is deleted on the panel, i.e. for every device of the client.</summary>
    public Task DeleteAsync(int localId, CancellationToken ct = default)
        => RunAsync(async () =>
        {
            var node = await FindAsync(localId, ct).ConfigureAwait(false);
            if (node is null)
                return true;
            if (node.HostedId is > 0)
            {
                try
                {
                    await ApplyAsync(await Api.DeleteHostedNodeAsync(node.HostedId.Value, ct).ConfigureAwait(false), ct).ConfigureAwait(false);
                }
                catch (HostedNodeException exception) when (exception.Code == "hosted_node_not_found")
                {
                    // Already gone on the panel (another device deleted it): resync.
                    await ApplyAsync(await Api.ListHostedNodesAsync(ct).ConfigureAwait(false), ct).ConfigureAwait(false);
                }
                return true;
            }
            await _repository.DeleteAsync(localId, ct).ConfigureAwait(false);
            NodesChanged?.Invoke();
            return true;
        }, ct);

    /// <summary>
    /// Records the user's pick for a forward. A hosted node is paired on the panel for every
    /// device; a local-only node stays a pick on this device (the panel cannot reference it).
    /// Returns true when the panel was updated.
    /// </summary>
    public Task<bool> PushPairingAsync(int forwardId, int localId, CancellationToken ct = default)
        => RunAsync(async () =>
        {
            if (forwardId <= 0)
                return false;
            var node = await FindAsync(localId, ct).ConfigureAwait(false);
            if (node?.HostedId is not > 0)
                return false;
            if (_state.Pairings.Any(pairing => pairing.ForwardId == forwardId && pairing.LocalNodeId == localId && pairing.IsExplicit))
                return false;
            await ApplyAsync(await Api.PairHostedNodeAsync(forwardId, node.HostedId, ct).ConfigureAwait(false), ct).ConfigureAwait(false);
            return true;
        }, ct);

    /// <summary>Clears the explicit panel pairing of a forward (the panel falls back to a target match).</summary>
    public Task ClearPairingAsync(int forwardId, CancellationToken ct = default)
        => RunAsync(async () =>
        {
            if (forwardId > 0)
                await ApplyAsync(await Api.PairHostedNodeAsync(forwardId, null, ct).ConfigureAwait(false), ct).ConfigureAwait(false);
            return true;
        }, ct);

    /// <summary>
    /// Imports pasted share links as local nodes. Links equal to a node already on this
    /// device (hosted or local) are skipped. Nothing is uploaded.
    /// </summary>
    public Task<UserNodeImportResult> ImportAsync(string? text, CancellationToken ct = default)
        => RunAsync(async () =>
        {
            var lines = (text ?? string.Empty).Split(['\r', '\n'], StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries);
            var parsed = UserNodeShareLink.ParseMany(text);
            var firstError = string.Empty;
            if (parsed.Count == 0)
            {
                UserNodeShareLink.TryParse(lines.FirstOrDefault(), out _, out firstError);
                return new UserNodeImportResult([], 0, Math.Max(1, lines.Length), firstError);
            }
            var existing = await _repository.LoadAsync(ct).ConfigureAwait(false);
            var keys = existing.Select(UserNodeIdentity.Key).Where(key => key is not null).ToHashSet(StringComparer.Ordinal);
            var added = new List<UserNode>();
            var duplicates = 0;
            foreach (var node in parsed)
            {
                var key = UserNodeIdentity.Key(node);
                if (key is not null && !keys.Add(key))
                {
                    duplicates++;
                    continue;
                }
                node.LocalId = 0;
                node.HostedId = null;
                added.Add(await _repository.SaveAsync(node, ct).ConfigureAwait(false));
            }
            if (added.Count > 0)
                NodesChanged?.Invoke();
            var invalid = Math.Max(0, lines.Count(line => line.Contains("://", StringComparison.Ordinal)) - parsed.Count);
            return new UserNodeImportResult(added, duplicates, invalid, firstError);
        }, ct);

    // ------------------------------------------------------------------ internals

    private async Task<T> RunAsync<T>(Func<Task<T>> action, CancellationToken ct)
    {
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        PublishStatus(_status with { IsBusy = true });
        try
        {
            await EnsureInitializedAsync(ct).ConfigureAwait(false);
            var result = await action().ConfigureAwait(false);
            _status = _status with { LastError = string.Empty };
            return result;
        }
        catch (Exception exception) when (exception is not OperationCanceledException)
        {
            _status = _status with { LastError = ErrorCode(exception) };
            throw;
        }
        finally
        {
            _status = _status with
            {
                IsBusy = false,
                ClientId = _state.ClientId,
                Revision = _state.Revision,
                LastSyncedAt = _state.SyncedAt,
                Pairings = _state.Pairings,
            };
            _gate.Release();
            StatusChanged?.Invoke(_status);
        }
    }

    private async Task EnsureInitializedAsync(CancellationToken ct)
    {
        if (_initialized)
            return;
        _state = await _stateStore.LoadAsync(ct).ConfigureAwait(false);
        _initialized = true;
    }

    private void PublishStatus(HostedNodeSyncStatus status)
    {
        _status = status;
        StatusChanged?.Invoke(status);
    }

    /// <summary>Materializes a panel listing into the repository and the pairing state.</summary>
    private async Task ApplyAsync(HostedNodesResponse listing, CancellationToken ct)
    {
        var all = await _repository.LoadAsync(ct).ConfigureAwait(false);
        var hostedGroups = all.Where(node => node.IsHosted).GroupBy(node => node.HostedId!.Value).ToList();
        // One row per hosted id: the oldest row wins, later copies are duplicates.
        foreach (var group in hostedGroups)
        {
            foreach (var duplicate in group.OrderBy(node => node.LocalId).Skip(1))
                await _repository.DeleteAsync(duplicate.LocalId, ct).ConfigureAwait(false);
        }
        var hostedRows = hostedGroups.ToDictionary(group => group.Key, group => group.OrderBy(node => node.LocalId).First());
        var adoptable = all.Where(node => !node.IsHosted).ToList();
        var seen = new HashSet<long>();
        var serverToLocal = new Dictionary<long, int>();
        foreach (var dto in listing.Nodes ?? [])
        {
            if (dto.Id <= 0 || !UserNodeShareLink.TryParse(dto.Uri, out var incoming, out _))
                continue;
            if (!string.IsNullOrWhiteSpace(dto.Name))
                incoming.Name = dto.Name!;
            seen.Add(dto.Id);
            int localId;
            if (hostedRows.TryGetValue(dto.Id, out var existing))
            {
                var updated = existing.WithConnectionOf(incoming);
                if (!SameConnection(existing, updated))
                    existing = await _repository.SaveAsync(updated, ct).ConfigureAwait(false);
                localId = existing.LocalId;
            }
            else
            {
                var key = UserNodeIdentity.Key(incoming);
                var match = key is null ? null : adoptable.FirstOrDefault(node => UserNodeIdentity.Key(node) == key);
                UserNode saved;
                if (match is not null)
                {
                    adoptable.Remove(match);
                    var adopted = match.WithConnectionOf(incoming);
                    adopted.HostedId = dto.Id;
                    saved = await _repository.SaveAsync(adopted, ct).ConfigureAwait(false);
                }
                else
                {
                    incoming.LocalId = 0;
                    incoming.HostedId = dto.Id;
                    saved = await _repository.SaveAsync(incoming, ct).ConfigureAwait(false);
                }
                localId = saved.LocalId;
            }
            serverToLocal[dto.Id] = localId;
        }
        foreach (var stale in hostedRows.Values.Where(node => !seen.Contains(node.HostedId!.Value)))
            await _repository.DeleteAsync(stale.LocalId, ct).ConfigureAwait(false);

        var pairings = (listing.Pairings ?? [])
            .Where(pairing => pairing.ForwardId > 0 && serverToLocal.ContainsKey(pairing.NodeId))
            .GroupBy(pairing => pairing.ForwardId)
            .Select(group => group.First())
            .Select(pairing => new HostedNodePairing(pairing.ForwardId, serverToLocal[pairing.NodeId], pairing.NodeId, pairing.Source ?? "explicit"))
            .ToList();
        var previous = _state.ClientId == _clientId() ? _state.Pairings : [];
        var changed = pairings
            .Where(pairing => !previous.Any(old => old.ForwardId == pairing.ForwardId && old.LocalNodeId == pairing.LocalNodeId))
            .ToList();
        _state = new HostedNodeSyncState(_clientId(), listing.Revision ?? string.Empty, pairings, _clock());
        await _stateStore.SaveAsync(_state, ct).ConfigureAwait(false);
        NodesChanged?.Invoke();
        if (changed.Count > 0)
            PanelPairingsChanged?.Invoke(changed);
    }

    private async Task LinkLocalRowAsync(UserNode node, long hostedId, CancellationToken ct)
    {
        var all = await _repository.LoadAsync(ct).ConfigureAwait(false);
        var current = all.FirstOrDefault(item => item.LocalId == node.LocalId);
        if (current is null || current.HostedId == hostedId)
            return;
        if (all.Any(item => item.HostedId == hostedId && item.LocalId != current.LocalId))
        {
            // The panel already hosts this node and this device has it: the local copy is a duplicate.
            await _repository.DeleteAsync(current.LocalId, ct).ConfigureAwait(false);
            return;
        }
        current.HostedId = hostedId;
        await _repository.SaveAsync(current, ct).ConfigureAwait(false);
    }

    private async Task<UserNode?> FindAsync(int localId, CancellationToken ct)
        => (await _repository.LoadAsync(ct).ConfigureAwait(false)).FirstOrDefault(node => node.LocalId == localId);

    private static string ShareUriOrThrow(UserNode node)
    {
        if (!node.IsCloudSupported)
            throw new HostedNodeException("hosted_node_invalid", UserNodeProtocol.Normalize(node.Protocol).Length == 0 ? "protocol" : "method");
        return UserNodeShareLink.Format(node) ?? throw new HostedNodeException("hosted_node_invalid", "uri");
    }

    private static bool SameConnection(UserNode left, UserNode right)
        => string.Equals(UserNodeShareLink.Format(left), UserNodeShareLink.Format(right), StringComparison.Ordinal);

    /// <summary>Stable, secret-free error code for UI display.</summary>
    public static string ErrorCode(Exception exception) => exception switch
    {
        HostedNodeException hosted => hosted.Code,
        Services.DeviceLimitReachedException => Services.DeviceLimitReachedException.Code,
        Services.MasterApiException api when !string.IsNullOrWhiteSpace(api.ErrorCode) => api.ErrorCode,
        Services.MasterApiException api => $"hosted_nodes_http_{api.StatusCode}",
        HttpRequestException => "hosted_nodes_network",
        InvalidOperationException invalid when invalid.Message.Length is > 0 and < 64 && !invalid.Message.Contains(' ') => invalid.Message,
        _ => "hosted_nodes_failed",
    };
}
