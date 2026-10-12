using LeikwanBridge.Core.Nodes;

namespace LeikwanBridge.Mac.Services;

/// <summary>
/// The Nodes page backed by the shared Core <see cref="HostedNodeSyncService"/>: the Mac's
/// saved profiles are the node list (<see cref="MacProfileNodeRepository"/>), hosted nodes are
/// write-through to the panel, and panel pairings flow back through <see cref="PanelPairingsChanged"/>.
/// WireGuard / external profiles stay local and are shown as "Local only".
/// </summary>
public sealed class MacCloudNodeLibrary : INodeLibrary
{
    private readonly IMacConnectionService _connections;
    private readonly HostedNodeSyncService _sync;
    private readonly Func<bool> _signedIn;

    public MacCloudNodeLibrary(IMacConnectionService connections, HostedNodeSyncService sync, Func<bool> signedIn)
    {
        _connections = connections;
        _sync = sync;
        _signedIn = signedIn;
        _sync.NodesChanged += () => Changed?.Invoke();
        _sync.PanelPairingsChanged += pairings => PanelPairingsChanged?.Invoke(pairings
            .Select(pairing => new NodePairing(pairing.ForwardId, pairing.LocalNodeId.ToString(System.Globalization.CultureInfo.InvariantCulture)))
            .ToList());
    }

    public event Action? Changed;

    public event Action<IReadOnlyList<NodePairing>>? PanelPairingsChanged;

    public bool IsCloudAvailable => _signedIn();

    public string LastSyncError => _sync.Status.LastError;

    public IReadOnlyList<NodeListItem> Nodes
        => _connections.Profiles
            .Where(profile => profile.Id > 0)
            .Select(profile =>
            {
                var item = MacLocalNodeLibrary.Project([profile], _connections.Selection)[0];
                var node = MacProfileNodeRepository.ToNode(profile);
                var state = profile.IsHosted ? NodeCloudState.Hosted
                    : node is { IsCloudSupported: true } ? NodeCloudState.LocalOnly
                    : NodeCloudState.Unsupported;
                return item with { CloudState = state };
            })
            .ToList();

    public Task ReloadAsync(CancellationToken ct = default) => _sync.RefreshAsync(ct);

    public async Task<NodeListItem> ImportShareLinkAsync(string link, CancellationToken ct = default)
    {
        var text = link?.Trim() ?? string.Empty;
        if (text.Length == 0)
            throw new NodeLibraryException("proxy_uri_required");
        // WireGuard stays a Mac-only node; everything else goes through the shared parser.
        if (text.StartsWith("wg://", StringComparison.OrdinalIgnoreCase) || text.Contains("[Interface]", StringComparison.OrdinalIgnoreCase))
            return await new MacLocalNodeLibrary(_connections).ImportShareLinkAsync(text, ct).ConfigureAwait(false);
        UserNodeImportResult result;
        try
        {
            result = await _sync.ImportAsync(text, ct).ConfigureAwait(false);
        }
        catch (Exception exception) when (exception is not OperationCanceledException)
        {
            throw new NodeLibraryException(HostedNodeSyncService.ErrorCode(exception));
        }
        if (result.Added.Count == 0)
            throw new NodeLibraryException(result.Duplicates > 0 ? "node_duplicate"
                : string.IsNullOrEmpty(result.FirstError) ? "proxy_uri_invalid" : result.FirstError);
        Changed?.Invoke();
        var first = result.Added[0];
        return Nodes.FirstOrDefault(node => node.Key == Key(first.LocalId))
            ?? new NodeListItem(Key(first.LocalId), first.Name, first.DisplayProtocol, first.DisplayEndpoint, NodeCloudState.LocalOnly, []);
    }

    public Task UploadAsync(string key, CancellationToken ct = default)
        => Run(() => _sync.UploadAsync(LocalId(key), ct: ct));

    public async Task<NodeSyncSummary> SyncAllAsync(CancellationToken ct = default)
    {
        HostedBulkUploadResult result = null!;
        await Run(async () => result = await _sync.UploadAllLocalAsync(ct).ConfigureAwait(false)).ConfigureAwait(false);
        return new NodeSyncSummary(result.Uploaded, result.AlreadyHosted, result.Unsupported, result.Failed);
    }

    public Task RefreshFromCloudAsync(CancellationToken ct = default) => Run(() => _sync.RefreshAsync(ct));

    public Task PairAsync(int forwardId, string? key, CancellationToken ct = default)
        => key is null
            ? Run(() => _sync.ClearPairingAsync(forwardId, ct))
            : Run(() => _sync.PushPairingAsync(forwardId, LocalId(key), ct));

    public async Task DeleteAsync(string key, CancellationToken ct = default)
    {
        var id = LocalId(key);
        var profile = _connections.Profiles.FirstOrDefault(item => item.Id == id);
        if (profile is not null && MacProfileNodeRepository.ToNode(profile) is null)
            await _connections.DeleteProfileAsync(id, ct).ConfigureAwait(false);
        else
            await Run(() => _sync.DeleteAsync(id, ct)).ConfigureAwait(false);
        Changed?.Invoke();
    }

    /// <summary>Whether the node behind <paramref name="key"/> is hosted (pairing it is shared with every device).</summary>
    public bool IsHosted(string key)
        => MacLocalNodeLibrary.TryParseKey(key, out var id)
            && _connections.Profiles.FirstOrDefault(profile => profile.Id == id)?.IsHosted == true;

    private static string Key(int localId) => localId.ToString(System.Globalization.CultureInfo.InvariantCulture);

    private static int LocalId(string key)
        => MacLocalNodeLibrary.TryParseKey(key, out var id) ? id : throw new NodeLibraryException("node_not_found");

    private static async Task Run(Func<Task> action)
    {
        try
        {
            await action().ConfigureAwait(false);
        }
        catch (NodeLibraryException)
        {
            throw;
        }
        catch (Exception exception) when (exception is not OperationCanceledException)
        {
            throw new NodeLibraryException(HostedNodeSyncService.ErrorCode(exception));
        }
    }
}
