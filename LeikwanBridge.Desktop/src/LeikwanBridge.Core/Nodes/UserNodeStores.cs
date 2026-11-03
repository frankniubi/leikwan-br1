using System.Text.Json;
using System.Text.Json.Serialization;
using LeikwanBridge.Core.Services.Platform;

namespace LeikwanBridge.Core.Nodes;

/// <summary>
/// The device's own node list as the sync service sees it. Windows adapts its
/// existing DPAPI-protected proxy profiles (<see cref="WindowsProxyProfileNodeRepository"/>);
/// another platform can use <see cref="SecretStoreUserNodeRepository"/> over its
/// <see cref="ISecretStore"/> (macOS Keychain).
/// </summary>
public interface IUserNodeRepository
{
    /// <summary>Every local node the platform can express as a <see cref="UserNode"/>.</summary>
    Task<IReadOnlyList<UserNode>> LoadAsync(CancellationToken ct = default);

    /// <summary>Inserts (LocalId 0) or replaces a node; returns it with its LocalId.</summary>
    Task<UserNode> SaveAsync(UserNode node, CancellationToken ct = default);

    Task DeleteAsync(int localId, CancellationToken ct = default);
}

/// <summary>One forward → node pairing resolved to a local node id.</summary>
public sealed record HostedNodePairing(
    [property: JsonPropertyName("forward_id")] int ForwardId,
    [property: JsonPropertyName("local_id")] int LocalNodeId,
    [property: JsonPropertyName("hosted_id")] long HostedNodeId,
    [property: JsonPropertyName("source")] string Source)
{
    public bool IsExplicit => string.Equals(Source, "explicit", StringComparison.OrdinalIgnoreCase);
}

/// <summary>
/// What the device last materialized from the panel. Contains no credentials: the
/// client id, the hosted_nodes_revision and the resolved pairings.
/// </summary>
public sealed record HostedNodeSyncState(
    [property: JsonPropertyName("client_id")] int ClientId,
    [property: JsonPropertyName("revision")] string Revision,
    [property: JsonPropertyName("pairings")] IReadOnlyList<HostedNodePairing> Pairings,
    [property: JsonPropertyName("synced_at")] DateTimeOffset? SyncedAt = null)
{
    public static HostedNodeSyncState Empty { get; } = new(0, string.Empty, []);
}

public interface IHostedNodeSyncStateStore
{
    Task<HostedNodeSyncState> LoadAsync(CancellationToken ct = default);
    Task SaveAsync(HostedNodeSyncState state, CancellationToken ct = default);
}

/// <summary>Plain JSON file (no secrets in the state). Atomic replace on save.</summary>
public sealed class JsonFileHostedNodeSyncStateStore : IHostedNodeSyncStateStore
{
    private const int MaxBytes = 256 * 1024;
    private static readonly JsonSerializerOptions Json = new() { PropertyNameCaseInsensitive = true };
    private readonly string _path;
    private readonly SemaphoreSlim _gate = new(1, 1);

    public JsonFileHostedNodeSyncStateStore(string path)
    {
        if (string.IsNullOrWhiteSpace(path))
            throw new ArgumentException("state path required", nameof(path));
        _path = path;
    }

    public async Task<HostedNodeSyncState> LoadAsync(CancellationToken ct = default)
    {
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        try
        {
            if (!File.Exists(_path) || new FileInfo(_path).Length > MaxBytes)
                return HostedNodeSyncState.Empty;
            var json = await File.ReadAllTextAsync(_path, ct).ConfigureAwait(false);
            return Normalize(JsonSerializer.Deserialize<HostedNodeSyncState>(json, Json));
        }
        catch (Exception exception) when (exception is JsonException or IOException or UnauthorizedAccessException)
        {
            return HostedNodeSyncState.Empty;
        }
        finally { _gate.Release(); }
    }

    public async Task SaveAsync(HostedNodeSyncState state, CancellationToken ct = default)
    {
        var json = JsonSerializer.Serialize(Normalize(state), Json);
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        try
        {
            var directory = Path.GetDirectoryName(_path);
            if (!string.IsNullOrWhiteSpace(directory))
                Directory.CreateDirectory(directory);
            var temp = _path + ".tmp-" + Guid.NewGuid().ToString("N");
            await File.WriteAllTextAsync(temp, json, ct).ConfigureAwait(false);
            File.Move(temp, _path, overwrite: true);
        }
        finally { _gate.Release(); }
    }

    internal static HostedNodeSyncState Normalize(HostedNodeSyncState? state)
        => state is null
            ? HostedNodeSyncState.Empty
            : state with
            {
                Revision = state.Revision ?? string.Empty,
                Pairings = (state.Pairings ?? [])
                    .Where(pairing => pairing is { ForwardId: > 0, LocalNodeId: > 0 })
                    .GroupBy(pairing => pairing.ForwardId)
                    .Select(group => group.First())
                    .Take(512)
                    .ToList(),
            };
}

/// <summary>In-memory store for tests and previews.</summary>
public sealed class InMemoryHostedNodeSyncStateStore : IHostedNodeSyncStateStore
{
    public HostedNodeSyncState State { get; private set; } = HostedNodeSyncState.Empty;
    public Task<HostedNodeSyncState> LoadAsync(CancellationToken ct = default) => Task.FromResult(State);
    public Task SaveAsync(HostedNodeSyncState state, CancellationToken ct = default)
    {
        State = JsonFileHostedNodeSyncStateStore.Normalize(state);
        return Task.CompletedTask;
    }
}

/// <summary>
/// Node list kept as one JSON value in an <see cref="ISecretStore"/> account, so the
/// platform's secret store encrypts it (macOS: <c>MacKeychainSecretStore</c>, with its
/// file fallback). Bounded to 64 nodes / 512 KiB like the Windows profile store.
/// </summary>
public sealed class SecretStoreUserNodeRepository : IUserNodeRepository
{
    public const string DefaultAccount = "user-nodes";
    public const int MaxNodes = 64;
    private const int MaxSerializedBytes = 512 * 1024;
    private static readonly JsonSerializerOptions Json = new() { PropertyNameCaseInsensitive = true };

    private readonly ISecretStore _secrets;
    private readonly string _account;
    private readonly SemaphoreSlim _gate = new(1, 1);

    public SecretStoreUserNodeRepository(ISecretStore secrets, string account = DefaultAccount)
    {
        _secrets = secrets ?? throw new ArgumentNullException(nameof(secrets));
        _account = string.IsNullOrWhiteSpace(account) ? DefaultAccount : account;
    }

    public async Task<IReadOnlyList<UserNode>> LoadAsync(CancellationToken ct = default)
    {
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        try { return (await ReadAsync(ct).ConfigureAwait(false)).Select(node => node.Clone()).ToList(); }
        finally { _gate.Release(); }
    }

    public async Task<UserNode> SaveAsync(UserNode node, CancellationToken ct = default)
    {
        ArgumentNullException.ThrowIfNull(node);
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        try
        {
            var nodes = await ReadAsync(ct).ConfigureAwait(false);
            var copy = node.Clone();
            var index = copy.LocalId > 0 ? nodes.FindIndex(item => item.LocalId == copy.LocalId) : -1;
            if (index >= 0)
            {
                nodes[index] = copy;
            }
            else
            {
                if (nodes.Count >= MaxNodes)
                    throw new InvalidOperationException("node_limit_reached");
                copy.LocalId = nodes.Count == 0 ? 1 : nodes.Max(item => item.LocalId) + 1;
                nodes.Add(copy);
            }
            await WriteAsync(nodes, ct).ConfigureAwait(false);
            return copy.Clone();
        }
        finally { _gate.Release(); }
    }

    public async Task DeleteAsync(int localId, CancellationToken ct = default)
    {
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        try
        {
            var nodes = await ReadAsync(ct).ConfigureAwait(false);
            if (nodes.RemoveAll(item => item.LocalId == localId) > 0)
                await WriteAsync(nodes, ct).ConfigureAwait(false);
        }
        finally { _gate.Release(); }
    }

    private async Task<List<UserNode>> ReadAsync(CancellationToken ct)
    {
        var encoded = await _secrets.GetAsync(_account, ct).ConfigureAwait(false);
        if (string.IsNullOrWhiteSpace(encoded) || encoded.Length > MaxSerializedBytes)
            return [];
        try
        {
            return (JsonSerializer.Deserialize<List<UserNode>>(encoded, Json) ?? [])
                .Where(node => node is { LocalId: > 0 })
                .GroupBy(node => node.LocalId)
                .Select(group => group.First())
                .Take(MaxNodes)
                .ToList();
        }
        catch (JsonException)
        {
            return [];
        }
    }

    private async Task WriteAsync(List<UserNode> nodes, CancellationToken ct)
    {
        var json = JsonSerializer.Serialize(nodes, Json);
        if (json.Length > MaxSerializedBytes)
            throw new InvalidOperationException("nodes_too_large");
        await _secrets.SetAsync(_account, json, ct).ConfigureAwait(false);
    }
}
