using System.Text;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Nodes;
using LeikwanBridge.Core.Services.Platform;
using Xunit;

namespace LeikwanBridge.Core.Tests.Nodes;

public sealed class HostedNodeSyncServiceTests
{
    private const string SocksUri = "socks5://alice:wonder@198.51.100.7:1080#Home";
    private static readonly string SsUri = "ss://" + Convert.ToBase64String(Encoding.UTF8.GetBytes("aes-256-gcm:pw")).TrimEnd('=') + "@ss.example.com:8388#SS";

    [Fact]
    public async Task CloudToLocalMaterializesNodesAndPairings()
    {
        var (sync, api, repo, _) = Create();
        api.Listing = Listing("r1", [(11, "Home", SocksUri)], [(101, 11, "target_match")]);
        IReadOnlyList<HostedNodePairing>? changed = null;
        sync.PanelPairingsChanged += pairings => changed = pairings;

        Assert.True(await sync.SyncIfRevisionChangedAsync("r1"));

        var nodes = await repo.LoadAsync();
        var node = Assert.Single(nodes);
        Assert.Equal(11, node.HostedId);
        Assert.Equal("wonder", node.Password);
        var pairing = Assert.Single(sync.Pairings);
        Assert.Equal((101, node.LocalId, "target_match"), (pairing.ForwardId, pairing.LocalNodeId, pairing.Source));
        Assert.NotNull(changed);
        Assert.Equal("r1", sync.Status.Revision);

        // Same revision, same client: no fetch.
        api.Calls.Clear();
        Assert.False(await sync.SyncIfRevisionChangedAsync("r1"));
        Assert.Empty(api.Calls);
        // An older panel without the field: nothing happens.
        Assert.False(await sync.SyncIfRevisionChangedAsync(null));
    }

    [Fact]
    public async Task EmptyRevisionWithoutHostedRowsSkipsTheFetch()
    {
        var (sync, api, _, state) = Create();
        Assert.False(await sync.SyncIfRevisionChangedAsync(""));
        Assert.Empty(api.Calls);
        Assert.Equal(7, state.State.ClientId);
    }

    [Fact]
    public async Task NewHostedNodeAdoptsTheEqualLocalRowAndLocalOnlyNodesStay()
    {
        var (sync, api, repo, _) = Create();
        var local = await repo.SaveAsync(UserNodeShareLink.Parse("socks5://alice:wonder@198.51.100.7:1080#Mine"));
        var other = await repo.SaveAsync(UserNodeShareLink.Parse(SsUri));
        api.Listing = Listing("r1", [(11, "Cloud name", SocksUri)], []);

        await sync.RefreshAsync();

        var nodes = await repo.LoadAsync();
        Assert.Equal(2, nodes.Count);
        var adopted = nodes.Single(node => node.LocalId == local.LocalId);
        Assert.Equal(11, adopted.HostedId);
        Assert.Equal("Cloud name", adopted.Name);
        Assert.Null(nodes.Single(node => node.LocalId == other.LocalId).HostedId);
    }

    [Fact]
    public async Task CloudEditAndDeleteReachTheDevice()
    {
        var (sync, api, repo, _) = Create();
        api.Listing = Listing("r1", [(11, "Home", SocksUri)], []);
        await sync.RefreshAsync();
        var localId = (await repo.LoadAsync()).Single().LocalId;

        api.Listing = Listing("r2", [(11, "Renamed", "socks5://alice:changed@198.51.100.7:1080#Renamed")], []);
        await sync.SyncIfRevisionChangedAsync("r2");
        var edited = (await repo.LoadAsync()).Single();
        Assert.Equal((localId, "Renamed", "changed"), (edited.LocalId, edited.Name, edited.Password));

        api.Listing = Listing("", [], []);
        await sync.SyncIfRevisionChangedAsync("");
        Assert.Empty(await repo.LoadAsync());
    }

    [Fact]
    public async Task ClientSwitchForcesAResyncAndDropsTheOldClientsRows()
    {
        var clientId = 7;
        var (sync, api, repo, _) = Create(() => clientId);
        api.Listing = Listing("same", [(11, "Home", SocksUri)], []);
        await sync.SyncIfRevisionChangedAsync("same");

        clientId = 8;
        api.Listing = Listing("same", [(21, "SS", SsUri)], []);
        Assert.True(await sync.SyncIfRevisionChangedAsync("same"));
        Assert.Equal(21, (await repo.LoadAsync()).Single().HostedId);
    }

    [Fact]
    public async Task UploadTurnsTheLocalRowIntoTheHostedRow()
    {
        var (sync, api, repo, _) = Create();
        var local = await repo.SaveAsync(UserNodeShareLink.Parse(SsUri));
        api.OnCreate = request =>
        {
            Assert.Equal(UserNodeShareLink.Format(local), request.Uri);
            Assert.Equal(101, request.PairForwardId);
            var listing = Listing("r1", [(31, "SS", SsUri)], [(101, 31, "explicit")]);
            listing.NodeId = 31;
            listing.Created = true;
            return listing;
        };

        var uploaded = await sync.UploadAsync(local.LocalId, 101);

        Assert.Equal((local.LocalId, 31L), (uploaded.LocalId, uploaded.HostedId!.Value));
        Assert.Single(await repo.LoadAsync());
        Assert.Equal(local.LocalId, sync.PairingFor(101)!.LocalNodeId);
    }

    [Fact]
    public async Task UploadAllCountsOutcomesAndLeavesUnsupportedNodesLocal()
    {
        var (sync, api, repo, _) = Create();
        var a = await repo.SaveAsync(UserNodeShareLink.Parse(SocksUri));
        var b = await repo.SaveAsync(UserNodeShareLink.Parse(SsUri));
        var c = await repo.SaveAsync(UserNodeShareLink.Parse("socks5://198.51.100.9:1080#Bad"));
        var legacy = await repo.SaveAsync(UserNodeShareLink.Parse("ss://" + Convert.ToBase64String(Encoding.UTF8.GetBytes("rc4-md5:x")) + "@o.example.com:1#Old"));
        api.OnBatch = request =>
        {
            Assert.Equal(3, request.Nodes.Count);
            var listing = Listing("r1", [(11, "Home", SocksUri), (12, "SS", SsUri)], []);
            listing.Results =
            [
                new HostedNodeBatchResult { Ref = a.LocalId.ToString(), Ok = true, NodeId = 11, Created = false },
                new HostedNodeBatchResult { Ref = b.LocalId.ToString(), Ok = true, NodeId = 12, Created = true },
                new HostedNodeBatchResult { Ref = c.LocalId.ToString(), Ok = false, Error = "hosted_node_invalid", Field = "server" },
            ];
            return listing;
        };

        var result = await sync.UploadAllLocalAsync();

        Assert.Equal((1, 1, 1, 1), (result.Uploaded, result.AlreadyHosted, result.Unsupported, result.Failed));
        Assert.Equal("server", Assert.Single(result.Failures).Field);
        var nodes = await repo.LoadAsync();
        Assert.Equal(4, nodes.Count);
        Assert.Null(nodes.Single(node => node.LocalId == legacy.LocalId).HostedId);
        Assert.Null(nodes.Single(node => node.LocalId == c.LocalId).HostedId);
    }

    [Fact]
    public async Task WriteThroughFailureLeavesTheLocalCopyUnchanged()
    {
        var (sync, api, repo, _) = Create();
        api.Listing = Listing("r1", [(11, "Home", SocksUri)], []);
        await sync.RefreshAsync();
        var hosted = (await repo.LoadAsync()).Single();
        api.Fail = new HostedNodeException("hosted_node_invalid", "password", 422);

        var edited = hosted.Clone();
        edited.Password = "new";
        var error = await Assert.ThrowsAsync<HostedNodeException>(() => sync.SaveAsync(edited));

        Assert.Equal("password", error.Field);
        Assert.Equal("wonder", (await repo.LoadAsync()).Single().Password);
        Assert.Equal("hosted_node_invalid", sync.Status.LastError);
        await Assert.ThrowsAsync<HostedNodeException>(() => sync.DeleteAsync(hosted.LocalId));
        Assert.Single(await repo.LoadAsync());
    }

    [Fact]
    public async Task DeletingAHostedNodeGoesToThePanelAndALocalNodeStaysLocal()
    {
        var (sync, api, repo, _) = Create();
        api.Listing = Listing("r1", [(11, "Home", SocksUri)], []);
        await sync.RefreshAsync();
        var local = await repo.SaveAsync(UserNodeShareLink.Parse(SsUri));
        var hosted = (await repo.LoadAsync()).Single(node => node.IsHosted);

        api.Listing = Listing("", [], []);
        await sync.DeleteAsync(hosted.LocalId);
        Assert.Contains("DELETE 11", api.Calls);

        api.Calls.Clear();
        await sync.DeleteAsync(local.LocalId);
        Assert.Empty(api.Calls);
        Assert.Empty(await repo.LoadAsync());
    }

    [Fact]
    public async Task PairingAHostedNodeIsPushedAndALocalPickIsNot()
    {
        var (sync, api, repo, _) = Create();
        api.Listing = Listing("r1", [(11, "Home", SocksUri)], []);
        await sync.RefreshAsync();
        var hosted = (await repo.LoadAsync()).Single();
        var local = await repo.SaveAsync(UserNodeShareLink.Parse(SsUri));

        Assert.False(await sync.PushPairingAsync(101, local.LocalId));
        Assert.DoesNotContain(api.Calls, call => call.StartsWith("PAIR", StringComparison.Ordinal));

        api.Listing = Listing("r2", [(11, "Home", SocksUri)], [(101, 11, "explicit")]);
        Assert.True(await sync.PushPairingAsync(101, hosted.LocalId));
        Assert.Contains("PAIR 101 11", api.Calls);
        Assert.False(await sync.PushPairingAsync(101, hosted.LocalId));
    }

    [Fact]
    public async Task PanelPairingChangesAreReportedOnlyWhenTheyChange()
    {
        var (sync, api, _, _) = Create();
        var reports = new List<IReadOnlyList<HostedNodePairing>>();
        sync.PanelPairingsChanged += reports.Add;
        api.Listing = Listing("r1", [(11, "Home", SocksUri), (12, "SS", SsUri)], [(101, 11, "explicit")]);
        await sync.RefreshAsync();
        api.Listing = Listing("r2", [(11, "Home", SocksUri), (12, "SS", SsUri)], [(101, 11, "explicit"), (102, 12, "explicit")]);
        await sync.RefreshAsync();

        Assert.Equal(2, reports.Count);
        Assert.Equal(102, Assert.Single(reports[1]).ForwardId);
    }

    [Fact]
    public async Task ImportSkipsDuplicatesAndInvalidLines()
    {
        var (sync, _, repo, _) = Create();
        var result = await sync.ImportAsync(SocksUri + "\n" + SocksUri.Replace("#Home", "#Again") + "\nvmess://e30=\n" + SsUri);
        Assert.Equal(2, result.Added.Count);
        Assert.Equal(1, result.Duplicates);
        Assert.Equal(1, result.Invalid);
        Assert.Equal(2, (await repo.LoadAsync()).Count);

        var bad = await sync.ImportAsync("vless://not-a-uuid@a.example.com:1");
        Assert.Empty(bad.Added);
        Assert.Equal("node_uuid_invalid", bad.FirstError);
    }

    [Fact]
    public async Task OfflineCallsFailWithAStableCode()
    {
        var sync = new HostedNodeSyncService(() => null, new SecretStoreUserNodeRepository(new MemorySecrets()), new InMemoryHostedNodeSyncStateStore(), () => 7);
        var error = await Assert.ThrowsAsync<HostedNodeException>(() => sync.RefreshAsync());
        Assert.Equal("hosted_nodes_offline", error.Code);
    }

    [Fact]
    public async Task WindowsProfilesAreTheNodeRepository()
    {
        var host = new FakeProfileHost();
        host.Items.Add(new WindowsProxyProfile { Id = 3, Name = "ext", Protocol = WindowsProxyProtocol.External, Server = "1.2.3.4", Port = 5 });
        host.Items.Add(new WindowsProxyProfile { Id = 4, Name = "old", Protocol = WindowsProxyProtocol.Shadowsocks, Server = "o.example.com", Port = 1, Method = "aes-256-gcm", Password = "pw", Enabled = false });
        var repo = new WindowsProxyProfileNodeRepository(host);
        var api = new FakeApi();
        var sync = new HostedNodeSyncService(api, repo, new InMemoryHostedNodeSyncStateStore(), () => 7);
        api.Listing = Listing("r1", [(11, "Home", SocksUri), (12, "SS22", "ss://" + Convert.ToBase64String(Encoding.UTF8.GetBytes("2022-blake3-aes-128-gcm:" + Convert.ToBase64String(new byte[16])))
            + "@s.example.com:443#SS22"), (13, "old", "ss://" + Convert.ToBase64String(Encoding.UTF8.GetBytes("aes-256-gcm:pw")).TrimEnd('=') + "@o.example.com:1#old")], []);

        await sync.RefreshAsync();

        Assert.Equal(4, host.Items.Count);
        Assert.Equal(WindowsProxyProtocol.External, host.Items.Single(item => item.Id == 3).Protocol);
        var socks = host.Items.Single(item => item.HostedId == 11);
        Assert.Equal((WindowsProxyProtocol.Socks5, "alice", "wonder"), (socks.Protocol, socks.Username, socks.Password));
        Assert.Equal(WindowsProxyProtocol.Shadowsocks2022, host.Items.Single(item => item.HostedId == 12).Protocol);
        var adopted = host.Items.Single(item => item.Id == 4);
        Assert.Equal(13, adopted.HostedId);
        Assert.False(adopted.Enabled);
    }

    // ---------------------------------------------------------------- fakes

    private static (HostedNodeSyncService Sync, FakeApi Api, SecretStoreUserNodeRepository Repo, InMemoryHostedNodeSyncStateStore State) Create(Func<int>? clientId = null)
    {
        var api = new FakeApi();
        var repo = new SecretStoreUserNodeRepository(new MemorySecrets());
        var state = new InMemoryHostedNodeSyncStateStore();
        return (new HostedNodeSyncService(api, repo, state, clientId ?? (() => 7)), api, repo, state);
    }

    private static HostedNodesResponse Listing(string revision, (long Id, string Name, string Uri)[] nodes, (int Forward, long Node, string Source)[] pairings)
        => new()
        {
            Ok = true,
            Revision = revision,
            Nodes = nodes.Select(node => new HostedNodeDto { Id = node.Id, Name = node.Name, Uri = node.Uri }).ToList(),
            Pairings = pairings.Select(pairing => new HostedNodePairingDto { ForwardId = pairing.Forward, NodeId = pairing.Node, Source = pairing.Source }).ToList(),
        };

    private sealed class FakeApi : IHostedNodeApi
    {
        public HostedNodesResponse Listing { get; set; } = new() { Ok = true, Revision = "", Nodes = [], Pairings = [] };
        public Func<HostedNodeUploadRequest, HostedNodesResponse>? OnCreate { get; set; }
        public Func<HostedNodeBatchRequest, HostedNodesResponse>? OnBatch { get; set; }
        public Exception? Fail { get; set; }
        public List<string> Calls { get; } = [];

        private Task<HostedNodesResponse> Answer(string call, Func<HostedNodesResponse>? answer = null)
        {
            Calls.Add(call);
            if (Fail is not null)
                return Task.FromException<HostedNodesResponse>(Fail);
            return Task.FromResult(answer?.Invoke() ?? Listing);
        }

        public Task<HostedNodesResponse> ListHostedNodesAsync(CancellationToken ct = default) => Answer("LIST");
        public Task<HostedNodesResponse> CreateHostedNodeAsync(HostedNodeUploadRequest request, CancellationToken ct = default)
            => Answer("CREATE", OnCreate is null ? null : () => OnCreate(request));
        public Task<HostedNodesResponse> BatchCreateHostedNodesAsync(HostedNodeBatchRequest request, CancellationToken ct = default)
            => Answer("BATCH", OnBatch is null ? null : () => OnBatch(request));
        public Task<HostedNodesResponse> UpdateHostedNodeAsync(long nodeId, HostedNodeUpdateRequest request, CancellationToken ct = default)
            => Answer("UPDATE " + nodeId);
        public Task<HostedNodesResponse> DeleteHostedNodeAsync(long nodeId, CancellationToken ct = default) => Answer("DELETE " + nodeId);
        public Task<HostedNodesResponse> PairHostedNodeAsync(int forwardId, long? nodeId, CancellationToken ct = default)
            => Answer("PAIR " + forwardId + " " + nodeId);
    }

    private sealed class MemorySecrets : ISecretStore
    {
        private readonly Dictionary<string, string> _values = [];
        public Task<string?> GetAsync(string name, CancellationToken ct) => Task.FromResult(_values.TryGetValue(name, out var value) ? value : null);
        public Task SetAsync(string name, string value, CancellationToken ct) { _values[name] = value; return Task.CompletedTask; }
        public Task DeleteAsync(string name, CancellationToken ct) { _values.Remove(name); return Task.CompletedTask; }
    }

    private sealed class FakeProfileHost : IWindowsProxyProfileHost
    {
        public List<WindowsProxyProfile> Items { get; } = [];
        public IReadOnlyList<WindowsProxyProfile> Profiles => Items.Select(item => item.Clone()).ToList();
        public Task SaveProfileAsync(WindowsProxyProfile profile, CancellationToken ct = default)
        {
            if (profile.Id <= 0)
                profile.Id = Items.Count == 0 ? 1 : Items.Max(item => item.Id) + 1;
            Items.RemoveAll(item => item.Id == profile.Id);
            Items.Add(profile.Clone());
            return Task.CompletedTask;
        }
        public Task DeleteProfileAsync(int profileId, CancellationToken ct = default)
        {
            Items.RemoveAll(item => item.Id == profileId);
            return Task.CompletedTask;
        }
    }
}
