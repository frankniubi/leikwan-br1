using LeikwanBridge.Mac.Services;
using LeikwanBridge.Mac.ViewModels;
using Xunit;

namespace LeikwanBridge.Mac.Tests.ViewModels;

public sealed class TrafficAndNodesTests
{
    private static IReadOnlyDictionary<string, TrafficCounter> Counters(params (string Key, long In, long Out)[] items)
        => items.ToDictionary(item => item.Key, item => new TrafficCounter(item.In, item.Out));

    [Fact]
    public void FirstSampleOnlyPrimesTheBaseline()
    {
        var monitor = new TrafficMonitor();
        monitor.Sample(DateTimeOffset.UnixEpoch, Counters(("a", 9_000_000, 9_000)));

        Assert.Equal(0, monitor.DownloadRate);
        Assert.Equal(0, monitor.SessionDownload);
    }

    [Fact]
    public void DeltaIsSpreadOverTheTimeSinceTheLastChangeAndHeld()
    {
        var monitor = new TrafficMonitor();
        var t = DateTimeOffset.UnixEpoch;
        monitor.Sample(t, Counters(("a", 0, 0)));
        monitor.Sample(t.AddSeconds(1), Counters(("a", 0, 0)));
        monitor.Sample(t.AddSeconds(5), Counters(("a", 5000, 500)));

        Assert.Equal(1000, monitor.DownloadRate);
        Assert.Equal(100, monitor.UploadRate);

        // The runtime writes every ~5 s: the rate is held between writes...
        monitor.Sample(t.AddSeconds(8), Counters(("a", 5000, 500)));
        Assert.Equal(1000, monitor.DownloadRate);
        // ...and dropped once it has been quiet longer than the hold window.
        monitor.Sample(t.AddSeconds(13), Counters(("a", 5000, 500)));
        Assert.Equal(0, monitor.DownloadRate);
        Assert.Equal(5000, monitor.SessionDownload);
        Assert.Equal(500, monitor.SessionUpload);
    }

    [Fact]
    public void RestartedOrNewRuntimesCountTheirWholeValue()
    {
        var monitor = new TrafficMonitor();
        var t = DateTimeOffset.UnixEpoch;
        monitor.Sample(t, Counters(("a", 10_000, 0)));
        // "a" restarted (counter went backwards) and "b" is a new runtime.
        monitor.Sample(t.AddSeconds(2), Counters(("a", 300, 0), ("b", 700, 0)));

        Assert.Equal(1000, monitor.SessionDownload);
        Assert.Equal(500, monitor.DownloadRate);
    }

    [Fact]
    public void BytesFormatUsesBinaryUnits()
    {
        Assert.Equal("0 B", TrafficMonitor.FormatBytes(0));
        Assert.Equal("1.5 KB", TrafficMonitor.FormatBytes(1536));
        Assert.Equal("3.8 MB/s", TrafficMonitor.FormatRate(3.8 * 1024 * 1024));
        Assert.Equal("120 GB", TrafficMonitor.FormatBytes(120.0 * 1024 * 1024 * 1024));
    }

    [Fact]
    public void CounterReaderFindsMacUltraAndNativeStatusFiles()
    {
        var root = Path.Combine(Path.GetTempPath(), "lk-traffic-" + Guid.NewGuid().ToString("N"));
        try
        {
            var state = Path.Combine(root, "state");
            var runtime = Path.Combine(root, "runtime");
            Directory.CreateDirectory(Path.Combine(state, "runtime", "ultra-101-abc"));
            Directory.CreateDirectory(Path.Combine(runtime, "native-usage", "native-7-x"));
            File.WriteAllText(Path.Combine(state, "runtime", "ultra-101-abc", "status.json"), """{"forward_id":101,"bytes_in":2048,"bytes_out":512}""");
            File.WriteAllText(Path.Combine(state, "runtime", "ultra-forward-9.status.json"), """{"forward_id":9,"bytes_in":10}""");
            File.WriteAllText(Path.Combine(runtime, "native-usage", "native-7-x", "status.json"), """{"forward_id":7,"bytes_in":1,"bytes_out":2}""");
            Directory.CreateDirectory(Path.Combine(state, "runtime", "ultra-102-broken"));
            File.WriteAllText(Path.Combine(state, "runtime", "ultra-102-broken", "status.json"), "{not json");

            var counters = new MacTrafficCounterReader(() => state, runtime).Read();

            Assert.Equal(3, counters.Count);
            Assert.Equal(2048 + 10 + 1, counters.Values.Sum(item => item.BytesIn));
            Assert.Equal(512 + 2, counters.Values.Sum(item => item.BytesOut));
        }
        finally
        {
            Directory.Delete(root, recursive: true);
        }
    }

    [Fact]
    public void CounterReaderToleratesMissingDirectories()
        => Assert.Empty(new MacTrafficCounterReader(() => Path.Combine(Path.GetTempPath(), Guid.NewGuid().ToString("N")), null).Read());

    [Fact]
    public void LocalLibraryProjectsCloudStateAndPairings()
    {
        var profiles = new List<MacConnectionProfile>
        {
            new() { Id = 1, Name = "Tokyo", Protocol = MacConnectionProtocol.Shadowsocks, Server = "203.0.113.1", Port = 8388 },
            new() { Id = 2, Name = "Home", Protocol = MacConnectionProtocol.WireGuard, Server = "2001:db8::1", Port = 51820 },
        };
        var selection = new MacConnectionSelection(0, 0, new Dictionary<int, int> { [101] = 1, [103] = 1 });

        var nodes = MacLocalNodeLibrary.Project(profiles, selection);

        Assert.Equal(NodeCloudState.LocalOnly, nodes[0].CloudState);
        Assert.Equal([101, 103], nodes[0].PairedForwardIds);
        Assert.Equal("203.0.113.1:8388", nodes[0].Endpoint);
        Assert.Equal(NodeCloudState.Unsupported, nodes[1].CloudState);
        Assert.Equal("[2001:db8::1]:51820", nodes[1].Endpoint);
    }

    [Fact]
    public async Task NodesPageShowsCardsAndRunsCloudActions()
    {
        var library = new MacUiFixtureNodeLibrary();
        var paired = new List<(string Key, int ForwardId)>();
        var page = new NodesViewModel(
            () => library,
            (key, forwardId) => { paired.Add((key, forwardId)); return Task.CompletedTask; },
            key => library.DeleteAsync(key),
            action => action());
        page.Refresh([new NodeServiceOption(101, "Forward 101"), new NodeServiceOption(102, "Forward 102")]);

        Assert.Equal(4, page.Nodes.Count);
        var local = page.Nodes.Single(node => node.IsLocalOnly);
        Assert.True(page.UploadCommand.CanExecute(local));
        Assert.False(page.UploadCommand.CanExecute(page.Nodes.First(node => node.IsHosted)));

        await page.UploadCommand.ExecuteAsync(local);
        Assert.DoesNotContain(page.Nodes, node => node.IsLocalOnly);

        page.Nodes[0].SelectedService = page.Nodes[0].ServiceOptions.Single(option => option.ForwardId == 102);
        await Task.Yield();
        Assert.Contains(("1", 102), paired);

        await page.SyncAllCommand.ExecuteAsync(null);
        Assert.Contains("already in cloud 2", page.StatusMessage);
    }

    [Fact]
    public async Task CloudActionsAreDisabledWithoutCloudAndImportErrorsAreReadable()
    {
        var connections = new FakeConnections();
        var library = new MacLocalNodeLibrary(connections);
        var page = new NodesViewModel(() => library, (_, _) => Task.CompletedTask, key => library.DeleteAsync(key), action => action());
        page.Refresh([]);

        Assert.False(page.SyncAllCommand.CanExecute(null));
        Assert.False(page.RefreshFromCloudCommand.CanExecute(null));

        page.ImportLink = "vmess://abc";
        await page.ImportShareLinkCommand.ExecuteAsync(null);
        Assert.Equal("This share link protocol is not supported on macOS yet.", page.ErrorMessage);

        page.ImportLink = "ss://YWVzLTI1Ni1nY206cGFzcw@203.0.113.5:8388#Edge";
        await page.ImportShareLinkCommand.ExecuteAsync(null);
        Assert.Equal(string.Empty, page.ErrorMessage);
        var card = Assert.Single(page.Nodes);
        Assert.Equal("Edge", card.Name);
        Assert.True(card.IsLocalOnly);
    }

    private sealed class FakeConnections : IMacConnectionService
    {
        private readonly List<MacConnectionProfile> _profiles = [];
        public event Action<MacConnectionSnapshot>? SnapshotChanged { add { } remove { } }
        public IReadOnlyList<MacConnectionProfile> Profiles => _profiles;
        public MacConnectionSnapshot Snapshot => MacConnectionSnapshot.Disconnected;
        public DateTimeOffset? ActiveNativeLeaseExpiresAt => null;
        public string? ActiveNativeLeasePolicyGeneration => null;
        public bool IsNativeOutboundActive => false;
        public Task InitializeAsync(CancellationToken ct = default) => Task.CompletedTask;

        public Task SaveProfileAsync(MacConnectionProfile profile, CancellationToken ct = default)
        {
            var copy = profile.Clone();
            copy.Id = _profiles.Count + 1;
            _profiles.Add(copy);
            return Task.CompletedTask;
        }

        public Task DeleteProfileAsync(int profileId, CancellationToken ct = default)
        {
            _profiles.RemoveAll(profile => profile.Id == profileId);
            return Task.CompletedTask;
        }

        public Task StartAsync(MacConnectionMode mode, int profileId, string panelUrl, IEnumerable<LeikwanBridge.Core.Models.ForwardConfig> forwards, CancellationToken ct = default)
            => Task.CompletedTask;

        public Task StartNativeAsync(MacConnectionMode mode, LeikwanBridge.Core.Models.NativeOutboundLease lease, string panelUrl, IEnumerable<LeikwanBridge.Core.Models.ForwardConfig> forwards, bool bypassChinaMainland = false, CancellationToken ct = default)
            => Task.CompletedTask;

        public Task<bool> TryRefreshNativeAsync(LeikwanBridge.Core.Models.NativeOutboundLease lease, CancellationToken ct = default)
            => Task.FromResult(false);

        public Task StopAsync(CancellationToken ct = default) => Task.CompletedTask;
        public ValueTask DisposeAsync() => ValueTask.CompletedTask;
    }
}
