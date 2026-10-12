using System.Text;
using LeikwanBridge.App.ViewModels;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Nodes;
using Xunit;

namespace LeikwanBridge.App.Tests.ViewModels;

/// <summary>Windows wiring of the shared cloud-node sync: profiles, pairings, write-through.</summary>
public sealed class CloudNodesViewModelTests : IDisposable
{
    private readonly string _stateDir = Path.Combine(Path.GetTempPath(), "leikwan-cloud-nodes-" + Guid.NewGuid().ToString("N"));
    private readonly MainViewModel _vm = new();
    private readonly FakeApi _api = new();

    public CloudNodesViewModelTests()
    {
        Directory.CreateDirectory(_stateDir);
        _vm.Initialize(new AgentConfig
        {
            StateDir = _stateDir,
            ProxySingBoxBin = Path.Combine(_stateDir, "missing-sing-box.exe"),
            ClientId = 7,
            ActiveClientId = 7,
        });
        _vm.HostedNodeApiFactory = () => _api;
    }

    [Fact]
    public async Task ImportAcceptsAllThreeProtocolsAndSkipsDuplicates()
    {
        await _vm.InitializeProxyAsync();
        var result = await _vm.ImportNodesAsync(
            "ss://" + B64("aes-256-gcm:pw") + "@ss.example.test:8388?plugin=obfs-local%3Bobfs%3Dhttp#SS\n"
            + "socks5://alice:wonder@198.51.100.7:1080#Socks\n"
            + "vless://11111111-2222-3333-4444-555555555555@v.example.test:443?type=ws&security=tls&path=%2Fray#V\n"
            + "socks5://alice:wonder@198.51.100.7:1080#Again");

        Assert.Equal(3, result.Added.Count);
        Assert.Equal(1, result.Duplicates);
        var socks = _vm.ProxyProfiles.Single(profile => profile.Protocol == WindowsProxyProtocol.Socks5);
        Assert.Equal(("alice", "wonder"), (socks.Username, socks.Password));
        Assert.Equal("obfs-local", _vm.ProxyProfiles.Single(profile => profile.Name == "SS").Plugin);
        Assert.True(File.Exists(Path.Combine(_stateDir, "proxy-profiles.json")));
        Assert.DoesNotContain("wonder", File.ReadAllText(Path.Combine(_stateDir, "proxy-profiles.json")), StringComparison.Ordinal);
    }

    [Fact]
    public async Task CloudNodesBecomeProfilesAndPanelPairingsSelectTheNode()
    {
        await _vm.InitializeProxyAsync();
        _vm.ApplyForwardSnapshot([Forward(101)], _ => false);
        _api.Listing = Listing("r1", [(11, "Tokyo", "socks5://198.51.100.7:1080#Tokyo")], [(101, 11)]);
        var applied = 0;
        _vm.CloudPairingsApplied += () => applied++;

        await _vm.SyncHostedNodesIfChangedAsync("r1");

        var hosted = Assert.Single(_vm.ProxyProfiles);
        Assert.Equal(11, hosted.HostedId);
        Assert.Equal(hosted.Id, _vm.Config.ProxyProfileIdsByForwardId[101]);
        Assert.Equal(1, applied);
        Assert.True(Assert.Single(_vm.ProxyForwardRows).IsCloudNode);
        Assert.Equal(1, _vm.HostedNodeCount);
        Assert.True(File.Exists(Path.Combine(_stateDir, "hosted-nodes-state.json")));
    }

    [Fact]
    public async Task PickingAHostedNodeForAForwardIsPushedToThePanel()
    {
        await _vm.InitializeProxyAsync();
        _vm.ApplyForwardSnapshot([Forward(101)], _ => false);
        _api.Listing = Listing("r1", [(11, "Tokyo", "socks5://198.51.100.7:1080#Tokyo")], []);
        await _vm.RefreshNodesFromCloudAsync();
        await _vm.ImportNodesAsync("ss://" + B64("aes-256-gcm:pw") + "@ss.example.test:8388#Local");
        var hosted = _vm.ProxyProfiles.Single(profile => profile.IsHosted);
        var local = _vm.ProxyProfiles.Single(profile => !profile.IsHosted);

        _vm.SetProxyProfileForForward(101, local.Id);
        await Task.Delay(200);
        Assert.DoesNotContain(_api.Calls, call => call.StartsWith("PAIR", StringComparison.Ordinal));

        _vm.SetProxyProfileForForward(101, hosted.Id);
        for (var i = 0; i < 50 && !_api.Calls.Contains("PAIR 101 11"); i++)
            await Task.Delay(20);
        Assert.Contains("PAIR 101 11", _api.Calls);
    }

    [Fact]
    public async Task EditingAndDeletingAHostedNodeGoThroughThePanel()
    {
        await _vm.InitializeProxyAsync();
        _api.Listing = Listing("r1", [(11, "Tokyo", "socks5://alice:wonder@198.51.100.7:1080#Tokyo")], []);
        await _vm.RefreshNodesFromCloudAsync();
        var hosted = Assert.Single(_vm.ProxyProfiles);

        _vm.ProxyEditor.Load(hosted);
        Assert.True(_vm.ProxyEditor.IsHosted);
        _vm.ProxyEditor.Name = "Tokyo 2";
        _api.Listing = Listing("r2", [(11, "Tokyo 2", "socks5://alice:wonder@198.51.100.7:1080#Tokyo%202")], []);
        await _vm.SaveProxyProfileAsync();
        Assert.Contains("UPDATE 11", _api.Calls);
        Assert.Equal("Tokyo 2", Assert.Single(_vm.ProxyProfiles).Name);
        Assert.Equal("alice", _vm.ProxyProfiles[0].Username);

        _api.Listing = Listing("", [], []);
        await _vm.DeleteProxyProfileAsync(hosted.Id);
        Assert.Contains("DELETE 11", _api.Calls);
        Assert.Empty(_vm.ProxyProfiles);
    }

    [Fact]
    public async Task UploadAllReportsCountsAndMarksNodesHosted()
    {
        await _vm.InitializeProxyAsync();
        await _vm.ImportNodesAsync("socks5://198.51.100.9:1080#One");
        var local = Assert.Single(_vm.ProxyProfiles);
        _api.OnBatch = request =>
        {
            var listing = Listing("r1", [(21, "One", "socks5://198.51.100.9:1080#One")], []);
            listing.Results = [new HostedNodeBatchResult { Ref = request.Nodes[0].Ref, Ok = true, NodeId = 21, Created = true }];
            return listing;
        };

        var result = await _vm.UploadAllNodesAsync();

        Assert.Equal(1, result.Uploaded);
        Assert.Equal(21, Assert.Single(_vm.ProxyProfiles).HostedId);
        Assert.Equal(local.Id, _vm.ProxyProfiles[0].Id);
        Assert.False(_vm.HasCloudError);
    }

    [Fact]
    public void ErrorCodesMapToLocalizedTextKeys()
    {
        Assert.Equal("NodesErrorUntrusted", MainViewModel.DescribeCloudError("device_untrusted"));
        Assert.Equal("NodesErrorInvalidLink", MainViewModel.DescribeCloudError("node_uuid_invalid"));
        Assert.Equal("NodesErrorGeneric", MainViewModel.DescribeCloudError("something_else"));
    }

    private static ForwardConfig Forward(int id) => new()
    {
        ForwardId = id, ListenPort = 11000 + id, Mode = "bond", Target = "198.51.100.7:1080", Transport = "tcp+udp", ServiceStatus = "active",
    };

    private static string B64(string value)
        => Convert.ToBase64String(Encoding.UTF8.GetBytes(value)).TrimEnd('=').Replace('+', '-').Replace('/', '_');

    private static HostedNodesResponse Listing(string revision, (long Id, string Name, string Uri)[] nodes, (int Forward, long Node)[] pairings) => new()
    {
        Ok = true,
        Revision = revision,
        Nodes = nodes.Select(node => new HostedNodeDto { Id = node.Id, Name = node.Name, Uri = node.Uri }).ToList(),
        Pairings = pairings.Select(pairing => new HostedNodePairingDto { ForwardId = pairing.Forward, NodeId = pairing.Node, Source = "explicit" }).ToList(),
    };

    private sealed class FakeApi : IHostedNodeApi
    {
        public HostedNodesResponse Listing { get; set; } = new() { Ok = true, Revision = "", Nodes = [], Pairings = [] };
        public Func<HostedNodeBatchRequest, HostedNodesResponse>? OnBatch { get; set; }
        public List<string> Calls { get; } = [];

        private Task<HostedNodesResponse> Answer(string call, HostedNodesResponse? answer = null)
        {
            lock (Calls)
                Calls.Add(call);
            return Task.FromResult(answer ?? Listing);
        }

        public Task<HostedNodesResponse> ListHostedNodesAsync(CancellationToken ct = default) => Answer("LIST");
        public Task<HostedNodesResponse> CreateHostedNodeAsync(HostedNodeUploadRequest request, CancellationToken ct = default) => Answer("CREATE");
        public Task<HostedNodesResponse> BatchCreateHostedNodesAsync(HostedNodeBatchRequest request, CancellationToken ct = default)
            => Answer("BATCH", OnBatch?.Invoke(request));
        public Task<HostedNodesResponse> UpdateHostedNodeAsync(long nodeId, HostedNodeUpdateRequest request, CancellationToken ct = default) => Answer("UPDATE " + nodeId);
        public Task<HostedNodesResponse> DeleteHostedNodeAsync(long nodeId, CancellationToken ct = default) => Answer("DELETE " + nodeId);
        public Task<HostedNodesResponse> PairHostedNodeAsync(int forwardId, long? nodeId, CancellationToken ct = default) => Answer("PAIR " + forwardId + " " + nodeId);
    }

    public void Dispose()
    {
        _vm.DisposeProxyForShutdown();
        try { Directory.Delete(_stateDir, recursive: true); } catch (IOException) { }
    }
}
