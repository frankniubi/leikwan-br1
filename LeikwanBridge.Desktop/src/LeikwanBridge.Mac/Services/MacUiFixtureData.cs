using LeikwanBridge.Core.Nodes;

namespace LeikwanBridge.Mac.Services;

/// <summary>
/// In-memory node library for the <c>--ui-fixture</c> screenshot mode only. It shows
/// every cloud state with documentation-range addresses and never touches Keychain or
/// the panel.
/// </summary>
public sealed class MacUiFixtureNodeLibrary : INodeLibrary
{
    private sealed record Entry(string Key, UserNode? Node, string Name, string Protocol, string Endpoint, NodeCloudState State, int[] Paired);

    private readonly List<Entry> _nodes =
    [
        Create("1", "ss://YWVzLTI1Ni1nY206Zml4dHVyZQ@203.0.113.10:8388#Tokyo%20SS", NodeCloudState.Hosted, [101]),
        Create("2", "ss://YWVzLTI1Ni1nY206Zml4dHVyZQ@203.0.113.24:443#Osaka%20backup", NodeCloudState.LocalOnly, []),
        new("3", null, "Home WireGuard", "WireGuard", "198.51.100.7:51820", NodeCloudState.Unsupported, [103]),
        Create("4", "vless://11111111-2222-3333-4444-555555555555@edge.example.net:443?encryption=none&security=tls&sni=edge.example.net&type=ws&path=%2Fray#Singapore%20VLESS", NodeCloudState.Hosted, []),
    ];

    private static Entry Create(string key, string link, NodeCloudState state, int[] paired)
    {
        var node = UserNodeShareLink.Parse(link);
        node.LocalId = int.Parse(key, System.Globalization.CultureInfo.InvariantCulture);
        node.HostedId = state == NodeCloudState.Hosted ? 900 + node.LocalId : null;
        return new Entry(key, node, node.Name, node.DisplayProtocol, node.DisplayEndpoint, state, paired);
    }

    public event Action? Changed;

    public event Action<IReadOnlyList<NodePairing>>? PanelPairingsChanged { add { } remove { } }

    public bool IsCloudAvailable => true;

    public IReadOnlyList<NodeListItem> Nodes
        => _nodes.Select(entry => new NodeListItem(entry.Key, entry.Name, entry.Protocol, entry.Endpoint, entry.State, entry.Paired)).ToList();

    public Task ReloadAsync(CancellationToken ct = default) => Task.CompletedTask;

    public Task<NodeListItem> ImportShareLinkAsync(string link, CancellationToken ct = default)
        => Task.FromException<NodeListItem>(new NodeLibraryException("proxy_protocol_unsupported"));

    public Task UploadAsync(string key, CancellationToken ct = default)
    {
        var index = _nodes.FindIndex(node => node.Key == key);
        if (index >= 0)
            _nodes[index] = _nodes[index] with { State = NodeCloudState.Hosted };
        Changed?.Invoke();
        return Task.CompletedTask;
    }

    public Task<NodeSyncSummary> SyncAllAsync(CancellationToken ct = default)
        => Task.FromResult(new NodeSyncSummary(0, 2, 1, 0));

    public Task RefreshFromCloudAsync(CancellationToken ct = default) => Task.CompletedTask;

    public Task PairAsync(int forwardId, string? key, CancellationToken ct = default) => Task.CompletedTask;

    public Task DeleteAsync(string key, CancellationToken ct = default)
    {
        _nodes.RemoveAll(node => node.Key == key);
        Changed?.Invoke();
        return Task.CompletedTask;
    }

    public UserNode? GetEditableNode(string key) => _nodes.FirstOrDefault(node => node.Key == key)?.Node?.Clone();

    public Task SaveNodeAsync(UserNode node, CancellationToken ct = default)
    {
        var index = _nodes.FindIndex(entry => entry.Node?.LocalId == node.LocalId);
        if (index >= 0)
            _nodes[index] = _nodes[index] with { Node = node.Clone(), Name = node.Name, Endpoint = node.DisplayEndpoint };
        Changed?.Invoke();
        return Task.CompletedTask;
    }
}

/// <summary>Deterministic synthetic runtime counters for fixture screenshots.</summary>
public sealed class MacUiFixtureTraffic
{
    private long _in;
    private long _out;
    private int _step;

    public IReadOnlyDictionary<string, TrafficCounter> Next()
    {
        _step++;
        var wave = 0.55 + 0.45 * Math.Sin(_step / 4.0) * Math.Cos(_step / 11.0);
        _in += (long)(3.6 * 1024 * 1024 * Math.Max(0.05, wave));
        _out += (long)(420 * 1024 * Math.Max(0.05, 1 - wave));
        return new Dictionary<string, TrafficCounter>
        {
            ["fixture/ultra-101/status.json"] = new(_in + 8_600_000_000, _out + 1_200_000_000),
        };
    }
}
