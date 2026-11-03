using System.Text.Json.Nodes;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Nodes;
using LeikwanBridge.Mac.Services;
using Xunit;

namespace LeikwanBridge.Mac.Tests.Services;

public sealed class MacCloudNodesTests
{
    private const string VlessLink =
        "vless://11111111-2222-3333-4444-555555555555@edge.example.net:443?encryption=none&security=tls&type=ws&path=%2Fray#Singapore";

    [Fact]
    public async Task PlainShadowsocksStaysAnEditableProfileAndVlessKeepsItsShareLink()
    {
        var connections = new FakeConnections();
        var repository = new MacProfileNodeRepository(connections);

        var ss = await repository.SaveAsync(UserNodeShareLink.Parse("ss://YWVzLTI1Ni1nY206cGFzcw@203.0.113.5:8388#Tokyo"));
        var vless = await repository.SaveAsync(UserNodeShareLink.Parse(VlessLink));

        var ssProfile = connections.Profiles.Single(profile => profile.Id == ss.LocalId);
        Assert.Equal(MacConnectionProtocol.Shadowsocks, ssProfile.Protocol);
        Assert.Equal("aes-256-gcm", ssProfile.Method);
        Assert.Equal("pass", ssProfile.Password);
        Assert.False(ssProfile.HasShareLink);

        var vlessProfile = connections.Profiles.Single(profile => profile.Id == vless.LocalId);
        Assert.Equal(MacConnectionProtocol.Vless, vlessProfile.Protocol);
        Assert.True(vlessProfile.HasShareLink);
        Assert.Equal("Singapore", vlessProfile.Name);

        // WireGuard profiles are Mac-only and invisible to the sync.
        await connections.SaveProfileAsync(new MacConnectionProfile { Name = "Home", Protocol = MacConnectionProtocol.WireGuard, Server = "198.51.100.7", Port = 51820 });
        var loaded = await repository.LoadAsync();
        Assert.Equal(2, loaded.Count);
        Assert.Equal(UserNodeShareLink.Format(UserNodeShareLink.Parse(VlessLink)), UserNodeShareLink.Format(loaded.Single(node => node.LocalId == vless.LocalId)));
    }

    [Fact]
    public void RoutedVlessDialsTheForwardButKeepsTheRealHostForTlsAndWebSocket()
    {
        var profile = MacProfileNodeRepository.ToProfile(UserNodeShareLink.Parse(VlessLink), null);
        profile.Id = 3;
        // What MacConnectionService.StartRoutedAsync does before building the config.
        profile.Server = "127.0.0.1";
        profile.Port = 12101;

        var config = JsonNode.Parse(MacConnectionConfigBuilder.Build(profile, MacConnectionMode.Proxy, []).Json)!;
        var proxy = config["outbounds"]!.AsArray().Single(item => (string?)item!["tag"] == "proxy")!;

        Assert.Equal("vless", (string?)proxy["type"]);
        Assert.Equal("127.0.0.1", (string?)proxy["server"]);
        Assert.Equal(12101, (int?)proxy["server_port"]);
        Assert.Equal("edge.example.net", (string?)proxy["tls"]!["server_name"]);
        Assert.Contains("edge.example.net", proxy["transport"]!.ToJsonString());
    }

    [Fact]
    public async Task CloudNodesMaterializeAndPanelPairingsReachTheMac()
    {
        var connections = new FakeConnections();
        var panel = new FakePanel();
        panel.Nodes.Add((41, "Singapore", VlessLink));
        panel.Pairings.Add((101, 41));
        var (sync, library) = await CreateAsync(connections, panel);
        IReadOnlyList<NodePairing>? pushed = null;
        library.PanelPairingsChanged += pairings => pushed = pairings;

        Assert.True(await sync.SyncIfRevisionChangedAsync("r1"));

        var hosted = Assert.Single(library.Nodes);
        Assert.Equal(NodeCloudState.Hosted, hosted.CloudState);
        Assert.Equal("VLESS", hosted.Protocol);
        Assert.Equal(41, connections.Profiles.Single().HostedId);
        var pairing = Assert.Single(pushed!);
        Assert.Equal(101, pairing.ForwardId);
        Assert.Equal(hosted.Key, pairing.Key);
    }

    [Fact]
    public async Task UploadPairAndDeleteAreWriteThrough()
    {
        var connections = new FakeConnections();
        var panel = new FakePanel();
        var (sync, library) = await CreateAsync(connections, panel);
        await sync.SyncIfRevisionChangedAsync("");

        var local = await library.ImportShareLinkAsync("ss://YWVzLTI1Ni1nY206cGFzcw@203.0.113.5:8388#Tokyo");
        Assert.Equal(NodeCloudState.LocalOnly, local.CloudState);
        Assert.Empty(panel.Calls);

        await library.UploadAsync(local.Key);
        Assert.Contains("CREATE", panel.Calls);
        var hosted = Assert.Single(library.Nodes);
        Assert.Equal(NodeCloudState.Hosted, hosted.CloudState);
        Assert.True(library.IsHosted(hosted.Key));

        var edited = library.GetEditableNode(hosted.Key)!;
        edited.Name = "Tokyo renamed";
        await library.SaveNodeAsync(edited);
        Assert.Contains(panel.Calls, call => call.StartsWith("UPDATE ", StringComparison.Ordinal));

        await library.PairAsync(102, hosted.Key);
        Assert.Contains(panel.Calls, call => call.StartsWith("PAIR 102 ", StringComparison.Ordinal));

        await library.DeleteAsync(hosted.Key);
        Assert.Contains(panel.Calls, call => call.StartsWith("DELETE ", StringComparison.Ordinal));
        Assert.Empty(library.Nodes);
    }

    [Fact]
    public async Task WireGuardImportStaysLocalAndCloudErrorsBecomeStableCodes()
    {
        var connections = new FakeConnections();
        var panel = new FakePanel { Fail = new HostedNodeException("hosted_node_limit_reached") };
        var (_, library) = await CreateAsync(connections, panel);

        var wg = await library.ImportShareLinkAsync("wg://" + Convert.ToBase64String(System.Text.Encoding.UTF8.GetBytes(
            "[Interface]\nPrivateKey = yAnz5TF+lXXJte14tji3zlMNq+hd2rYUIgJBgB3fBmk=\nAddress = 10.8.0.2/32\n"
            + "[Peer]\nPublicKey = bmXOC+F1FxEMF9dyiK2H5/1SUtzH0JuVo51h2wPfgyo=\nEndpoint = 198.51.100.7:51820\n")) + "#Home");
        Assert.Equal(NodeCloudState.Unsupported, wg.CloudState);

        var ss = await library.ImportShareLinkAsync("ss://YWVzLTI1Ni1nY206cGFzcw@203.0.113.5:8388#Tokyo");
        var error = await Assert.ThrowsAsync<NodeLibraryException>(() => library.UploadAsync(ss.Key));
        Assert.Equal("hosted_node_limit_reached", error.Code);
    }

    private static async Task<(HostedNodeSyncService Sync, MacCloudNodeLibrary Library)> CreateAsync(FakeConnections connections, FakePanel panel)
    {
        var sync = new HostedNodeSyncService(
            () => panel,
            new MacProfileNodeRepository(connections),
            new InMemoryHostedNodeSyncStateStore(),
            () => 27);
        await sync.InitializeAsync();
        return (sync, new MacCloudNodeLibrary(connections, sync, () => true));
    }

    private sealed class FakePanel : IHostedNodeApi
    {
        private long _nextId = 100;
        public List<(long Id, string Name, string Uri)> Nodes { get; } = [];
        public List<(int Forward, long Node)> Pairings { get; } = [];
        public List<string> Calls { get; } = [];
        public Exception? Fail { get; set; }

        private Task<HostedNodesResponse> Answer(string call, long? nodeId = null)
        {
            Calls.Add(call);
            if (Fail is not null)
                return Task.FromException<HostedNodesResponse>(Fail);
            return Task.FromResult(new HostedNodesResponse
            {
                Ok = true,
                Revision = "r" + Calls.Count,
                NodeId = nodeId,
                Created = nodeId is not null,
                Nodes = Nodes.Select(node => new HostedNodeDto { Id = node.Id, Name = node.Name, Uri = node.Uri }).ToList(),
                Pairings = Pairings.Select(pairing => new HostedNodePairingDto { ForwardId = pairing.Forward, NodeId = pairing.Node, Source = "explicit" }).ToList(),
            });
        }

        public Task<HostedNodesResponse> ListHostedNodesAsync(CancellationToken ct = default) => Answer("LIST");

        public Task<HostedNodesResponse> CreateHostedNodeAsync(HostedNodeUploadRequest request, CancellationToken ct = default)
        {
            if (Fail is not null)
                return Answer("CREATE");
            var id = _nextId++;
            Nodes.Add((id, request.Name ?? "", request.Uri));
            return Answer("CREATE", id);
        }

        public Task<HostedNodesResponse> BatchCreateHostedNodesAsync(HostedNodeBatchRequest request, CancellationToken ct = default)
            => Answer("BATCH");

        public Task<HostedNodesResponse> UpdateHostedNodeAsync(long nodeId, HostedNodeUpdateRequest request, CancellationToken ct = default)
            => Answer("UPDATE " + nodeId);

        public Task<HostedNodesResponse> DeleteHostedNodeAsync(long nodeId, CancellationToken ct = default)
        {
            Nodes.RemoveAll(node => node.Id == nodeId);
            Pairings.RemoveAll(pairing => pairing.Node == nodeId);
            return Answer("DELETE " + nodeId);
        }

        public Task<HostedNodesResponse> PairHostedNodeAsync(int forwardId, long? nodeId, CancellationToken ct = default)
        {
            Pairings.RemoveAll(pairing => pairing.Forward == forwardId);
            if (nodeId is > 0)
                Pairings.Add((forwardId, nodeId.Value));
            return Answer("PAIR " + forwardId + " " + nodeId);
        }
    }

    private sealed class FakeConnections : IMacConnectionService
    {
        private readonly List<MacConnectionProfile> _profiles = [];
        private MacConnectionSelection _selection = MacConnectionSelection.Empty;

        public event Action<MacConnectionSnapshot>? SnapshotChanged { add { } remove { } }
        public IReadOnlyList<MacConnectionProfile> Profiles => _profiles.Select(profile => profile.Clone()).ToList();
        public MacConnectionSelection Selection => _selection;
        public MacConnectionSnapshot Snapshot => MacConnectionSnapshot.Disconnected;
        public DateTimeOffset? ActiveNativeLeaseExpiresAt => null;
        public string? ActiveNativeLeasePolicyGeneration => null;
        public bool IsNativeOutboundActive => false;
        public Task InitializeAsync(CancellationToken ct = default) => Task.CompletedTask;

        public Task SaveProfileAsync(MacConnectionProfile profile, CancellationToken ct = default)
        {
            if (profile.Protocol is not MacConnectionProtocol.WireGuard)
                MacConnectionConfigBuilder.Build(profile, MacConnectionMode.Proxy, []);
            var copy = profile.Clone();
            if (copy.Id <= 0)
                copy.Id = _profiles.Count == 0 ? 1 : _profiles.Max(item => item.Id) + 1;
            var index = _profiles.FindIndex(item => item.Id == copy.Id);
            if (index >= 0)
                _profiles[index] = copy;
            else
                _profiles.Add(copy);
            return Task.CompletedTask;
        }

        public Task DeleteProfileAsync(int profileId, CancellationToken ct = default)
        {
            _profiles.RemoveAll(profile => profile.Id == profileId);
            return Task.CompletedTask;
        }

        public Task SaveSelectionAsync(int forwardId, int profileId, CancellationToken ct = default)
        {
            var pairs = new Dictionary<int, int>(_selection.ForwardProfiles);
            if (forwardId > 0 && profileId > 0)
                pairs[forwardId] = profileId;
            _selection = new MacConnectionSelection(forwardId, profileId, pairs);
            return Task.CompletedTask;
        }

        public Task StartAsync(MacConnectionMode mode, int profileId, string panelUrl, IEnumerable<ForwardConfig> forwards, CancellationToken ct = default)
            => Task.CompletedTask;

        public Task StartNativeAsync(MacConnectionMode mode, NativeOutboundLease lease, string panelUrl, IEnumerable<ForwardConfig> forwards, bool bypassChinaMainland = false, CancellationToken ct = default)
            => Task.CompletedTask;

        public Task<bool> TryRefreshNativeAsync(NativeOutboundLease lease, CancellationToken ct = default) => Task.FromResult(false);
        public Task StopAsync(CancellationToken ct = default) => Task.CompletedTask;
        public ValueTask DisposeAsync() => ValueTask.CompletedTask;
    }
}
