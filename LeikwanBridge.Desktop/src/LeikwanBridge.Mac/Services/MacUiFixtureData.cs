namespace LeikwanBridge.Mac.Services;

/// <summary>
/// In-memory node library for the <c>--ui-fixture</c> screenshot mode only. It shows
/// every cloud state with documentation-range addresses and never touches Keychain or
/// the panel.
/// </summary>
public sealed class MacUiFixtureNodeLibrary : INodeLibrary
{
    private readonly List<NodeListItem> _nodes =
    [
        new("1", "Tokyo SS", "Shadowsocks", "203.0.113.10:8388", NodeCloudState.Hosted, [101]),
        new("2", "Osaka backup", "Shadowsocks", "203.0.113.24:443", NodeCloudState.LocalOnly, []),
        new("3", "Home WireGuard", "WireGuard", "198.51.100.7:51820", NodeCloudState.Unsupported, [103]),
        new("4", "Singapore VLESS", "VLESS", "edge.example.net:443", NodeCloudState.Hosted, []),
    ];

    public event Action? Changed;

    public bool IsCloudAvailable => true;

    public IReadOnlyList<NodeListItem> Nodes => _nodes;

    public Task ReloadAsync(CancellationToken ct = default) => Task.CompletedTask;

    public Task<NodeListItem> ImportShareLinkAsync(string link, CancellationToken ct = default)
        => Task.FromException<NodeListItem>(new NodeLibraryException("proxy_protocol_unsupported"));

    public Task UploadAsync(string key, CancellationToken ct = default)
    {
        var index = _nodes.FindIndex(node => node.Key == key);
        if (index >= 0)
            _nodes[index] = _nodes[index] with { CloudState = NodeCloudState.Hosted };
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
