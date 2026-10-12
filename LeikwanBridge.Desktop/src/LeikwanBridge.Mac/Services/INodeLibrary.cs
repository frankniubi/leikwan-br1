namespace LeikwanBridge.Mac.Services;

/// <summary>Where a node lives relative to the panel's hosted-node store.</summary>
public enum NodeCloudState
{
    /// <summary>Only on this Mac; can be uploaded.</summary>
    LocalOnly,

    /// <summary>Hosted by the panel for this client and synced to every trusted device.</summary>
    Hosted,

    /// <summary>A protocol the hosted store does not accept (for example WireGuard): stays local.</summary>
    Unsupported,
}

/// <summary>One row of the Nodes page. Credentials never travel through this type.</summary>
public sealed record NodeListItem(
    string Key,
    string Name,
    string Protocol,
    string Endpoint,
    NodeCloudState CloudState,
    IReadOnlyList<int> PairedForwardIds);

/// <summary>A forward → node pairing (node by library key).</summary>
public sealed record NodePairing(int ForwardId, string Key);

/// <summary>Outcome of "sync all to cloud" (mirrors the panel batch endpoint counts).</summary>
public sealed record NodeSyncSummary(int Uploaded, int AlreadyInCloud, int Unsupported, int Failed);

/// <summary>A node-library failure with a stable code the UI maps to text.</summary>
public sealed class NodeLibraryException(string code) : Exception(code)
{
    public string Code { get; } = code;
}

/// <summary>
/// The Nodes page contract. Phase 1 binds <see cref="MacLocalNodeLibrary"/> (the
/// Keychain-backed saved nodes, no cloud); the shared LeikwanBridge.Core node store and
/// two-way sync service plug in behind the same members later without changing the page.
/// Pairing a node with a service locally stays with the main view model (it owns the
/// forward selection); <see cref="PairAsync"/> is the cloud write-through only.
/// </summary>
public interface INodeLibrary
{
    event Action? Changed;

    /// <summary>
    /// Pairings set or changed on the panel (console or another device) since the last sync;
    /// the main view model applies them as each forward's selected node.
    /// </summary>
    event Action<IReadOnlyList<NodePairing>>? PanelPairingsChanged;

    bool IsCloudAvailable { get; }

    IReadOnlyList<NodeListItem> Nodes { get; }

    Task ReloadAsync(CancellationToken ct = default);

    /// <summary>Parses a share link (ss://, wg://, ...) and saves it as a local node.</summary>
    Task<NodeListItem> ImportShareLinkAsync(string link, CancellationToken ct = default);

    Task UploadAsync(string key, CancellationToken ct = default);

    Task<NodeSyncSummary> SyncAllAsync(CancellationToken ct = default);

    Task RefreshFromCloudAsync(CancellationToken ct = default);

    /// <summary>Publishes a forward → node pairing to the panel (null clears it).</summary>
    Task PairAsync(int forwardId, string? key, CancellationToken ct = default);

    Task DeleteAsync(string key, CancellationToken ct = default);
}
