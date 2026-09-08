using LeikwanBridge.App.ViewModels;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;
using System.Net;
using System.Net.Sockets;
using System.Reflection;

namespace LeikwanBridge.App.Tests.ViewModels;

public sealed class WindowsSavedNodeFlowTests : IDisposable
{
    private readonly string _stateDir = Path.Combine(Path.GetTempPath(), "leikwan-saved-node-" + Guid.NewGuid().ToString("N"));
    private readonly MainViewModel _vm = new();

    public WindowsSavedNodeFlowTests()
    {
        Directory.CreateDirectory(_stateDir);
        _vm.Initialize(new AgentConfig { StateDir = _stateDir, ProxySingBoxBin = Path.Combine(_stateDir, "missing-sing-box.exe") });
    }

    [Fact]
    public async Task SavingNodeSelectsItAndRemembersPairSeparatelyForEachForward()
    {
        await _vm.InitializeProxyAsync();
        _vm.ApplyForwardSnapshot([Forward(59), Forward(60)], _ => false);
        Assert.Equal(59, _vm.SelectedProxyForwardId);
        await SaveNodeAsync("first.example.test", 30526);
        var first = _vm.SelectedProxyProfileId;
        Assert.True(first > 0);
        Assert.Equal(first, _vm.Config.ProxyProfileIdsByForwardId[59]);

        _vm.SelectedProxyForwardId = 60;
        _vm.ProxyEditor.Load(null);
        await SaveNodeAsync("second.example.test", 443);
        var second = _vm.SelectedProxyProfileId;
        Assert.NotEqual(first, second);
        Assert.Equal(second, _vm.Config.ProxyProfileIdsByForwardId[60]);

        _vm.SelectedProxyForwardId = 59;
        Assert.Equal(first, _vm.SelectedProxyProfileId);
        Assert.Equal("first.example.test", _vm.ProxyEditor.Server);
        Assert.Equal("30526", _vm.ProxyEditor.PortText);
        _vm.ApplyForwardSnapshot([Forward(60), Forward(59)], _ => false);
        Assert.Equal(59, _vm.SelectedProxyForwardId);
        Assert.Equal(first, _vm.SelectedProxyProfileId);
    }

    [Fact]
    public async Task DeletingSavedNodeRemovesPairWithoutDeletingOtherSavedNodes()
    {
        await _vm.InitializeProxyAsync();
        _vm.ApplyForwardSnapshot([Forward(59)], _ => false);
        await SaveNodeAsync("first.example.test", 30526);
        var first = _vm.SelectedProxyProfileId;
        _vm.ProxyEditor.Load(null);
        await SaveNodeAsync("second.example.test", 443);
        _vm.SelectedProxyProfileId = first;

        await _vm.DeleteProxyProfileAsync(first);

        Assert.DoesNotContain(first, _vm.Config.ProxyProfileIdsByForwardId.Values);
        Assert.Equal("second.example.test", Assert.Single(_vm.ProxyProfiles).Server);
        Assert.Equal(0, _vm.SelectedProxyProfileId);
        Assert.True(Assert.Single(_vm.ProxyForwardRows).IsUnbound);
    }

    [Fact]
    public async Task ConnectWithoutSavedNodeDoesNotSaveDraftOrStartDirectly()
    {
        await _vm.InitializeProxyAsync();
        _vm.ApplyForwardSnapshot([Forward(59)], _ => false);
        _vm.ProxyEditor.Server = "draft.example.test";
        _vm.ProxyEditor.Password = "draft-secret";

        var error = await Assert.ThrowsAsync<InvalidOperationException>(() => _vm.StartProxyAsync(WindowsProxyMode.Http, 0));

        Assert.Equal("proxy_saved_profile_required", error.Message);
        Assert.Empty(_vm.ProxyProfiles);
        Assert.False(File.Exists(Path.Combine(_stateDir, "proxy-profiles.json")));
    }

    [Fact]
    public async Task ConnectWithSavedNodeButNoForwardFailsWithoutDialingOriginalServer()
    {
        await _vm.InitializeProxyAsync();
        await SaveNodeAsync("saved.example.test", 30526);

        var error = await Assert.ThrowsAsync<InvalidOperationException>(() =>
            _vm.StartProxyAsync(WindowsProxyMode.Http, _vm.SelectedProxyProfileId));

        Assert.Equal("proxy_forward_required", error.Message);
        Assert.NotEqual(WindowsProxyConnectionState.Connected, _vm.ProxySnapshot.State);
        Assert.Equal("saved.example.test", Assert.Single(_vm.ProxyProfiles).Server);
    }

    [Fact]
    public void UnavailableForwardCannotRemainSelectedAfterRefresh()
    {
        _vm.ApplyForwardSnapshot([Forward(59), Forward(60)], _ => false);
        _vm.SelectedProxyForwardId = 59;
        var suspended = Forward(59);
        suspended.ServiceStatus = "suspended";
        _vm.ApplyForwardSnapshot([suspended, Forward(60)], _ => false);

        Assert.Equal(60, _vm.SelectedProxyForwardId);
        Assert.Single(_vm.ProxyForwardOptions);
    }

    [Theory]
    [InlineData(false)]
    [InlineData(true)]
    public async Task ConnectStartsForwardBeforeProxyAndStopOnlyReleasesOwnedForward(bool preexisting)
    {
        var host = new ProxyProcessHost();
        using var runtime = InstallRuntime(host);
        var forward = runtime.Forward;
        if (preexisting)
            runtime.StartListener();
        await _vm.InitializeProxyAsync();
        _vm.ApplyForwardSnapshot([forward], _ => preexisting);
        await SaveNodeAsync("node.example.test", 30526);
        _vm.ProxyEditor.Password = "unsaved-secret";

        await _vm.StartProxyAsync(WindowsProxyMode.Http, _vm.SelectedProxyProfileId);

        Assert.True(host.SawForwardReady);
        Assert.Contains("127.0.0.1", host.LastConfig);
        Assert.Contains("saved-secret", host.LastConfig);
        Assert.DoesNotContain("unsaved-secret", host.LastConfig);
        Assert.Equal(preexisting ? 0 : 1, runtime.StartCalls);
        Assert.Equal("node.example.test", Assert.Single(_vm.ProxyProfiles).Server);
        await _vm.StopProxyAsync();
        Assert.False(host.IsRunning);
        Assert.Equal(preexisting ? 0 : 1, runtime.StopCalls);
        Assert.Equal(preexisting, runtime.Running);
    }

    [Fact]
    public async Task SingBoxStartFailureReleasesNewForwardAndLeavesSavedNodeUntouched()
    {
        var host = new ProxyProcessHost { StartFailure = new InvalidOperationException("test_start_failed") };
        using var runtime = InstallRuntime(host);
        await _vm.InitializeProxyAsync();
        _vm.ApplyForwardSnapshot([runtime.Forward], _ => false);
        await SaveNodeAsync("node.example.test", 30526);

        await Assert.ThrowsAsync<InvalidOperationException>(() =>
            _vm.StartProxyAsync(WindowsProxyMode.Http, _vm.SelectedProxyProfileId));

        Assert.False(host.IsRunning);
        Assert.False(runtime.Running);
        Assert.Equal(1, runtime.StopCalls);
        Assert.Equal(WindowsProxyConnectionState.Error, _vm.ProxySnapshot.State);
        Assert.Equal(30526, Assert.Single(_vm.ProxyProfiles).Port);
    }

    [Fact]
    public async Task DisposeReleasesOwnedForwardAsWellAsProxyRuntime()
    {
        var host = new ProxyProcessHost();
        using var runtime = InstallRuntime(host);
        await _vm.InitializeProxyAsync();
        _vm.ApplyForwardSnapshot([runtime.Forward], _ => false);
        await SaveNodeAsync("node.example.test", 30526);
        await _vm.StartProxyAsync(WindowsProxyMode.Http, _vm.SelectedProxyProfileId);

        _vm.DisposeProxyForShutdown();

        Assert.False(host.IsRunning);
        Assert.False(runtime.Running);
        Assert.Equal(1, runtime.StopCalls);
    }

    [Fact]
    public async Task RuntimeExitClearsProxyAndOwnedForwardInsteadOfLeavingConnectedState()
    {
        var host = new ProxyProcessHost();
        using var runtime = InstallRuntime(host);
        await _vm.InitializeProxyAsync();
        _vm.ApplyForwardSnapshot([runtime.Forward], _ => false);
        await SaveNodeAsync("node.example.test", 30526);
        await _vm.StartProxyAsync(WindowsProxyMode.Http, _vm.SelectedProxyProfileId);
        await host.StopAsync();

        await _vm.CheckProxyForwardHealthAsync();

        Assert.False(runtime.Running);
        Assert.Equal(1, runtime.StopCalls);
        Assert.Equal(WindowsProxyConnectionState.Error, _vm.ProxySnapshot.State);
        Assert.Equal("proxy_runtime_unavailable", _vm.ProxySnapshot.ErrorCode);
    }

    [Fact]
    public async Task SavedForwardSelectionWaitsForFirstPanelSnapshotBeforeElevatedStart()
    {
        _vm.Config.ProxySelectedForwardId = 59;
        using var cancellation = new CancellationTokenSource(TimeSpan.FromSeconds(2));
        var waiting = _vm.WaitForProxyForwardSelectionAsync(cancellation.Token);
        Assert.False(waiting.IsCompleted);

        _vm.ApplyForwardSnapshot([Forward(59)], _ => false);
        await waiting;

        Assert.Equal(59, _vm.SelectedProxyForwardId);
    }

    [Fact]
    public async Task ExternalNodeStartsOnlyForwardAndShowsItsListenerPort()
    {
        var host = new ProxyProcessHost();
        using var runtime = InstallRuntime(host);
        await _vm.InitializeProxyAsync();
        _vm.ApplyForwardSnapshot([runtime.Forward], _ => false);
        _vm.ProxyEditor.Protocol = "external";
        _vm.ProxyEditor.Server = "198.51.100.9";
        _vm.ProxyEditor.PortText = "51820";
        await _vm.SaveProxyProfileAsync();

        await _vm.StartProxyAsync(WindowsProxyMode.Http, _vm.SelectedProxyProfileId);

        Assert.Equal("external", Assert.Single(_vm.ProxyProfiles).Protocol);
        Assert.True(runtime.Running);
        Assert.False(host.IsRunning);
        Assert.Empty(host.LastConfig);
        Assert.Equal(WindowsProxyConnectionState.Connected, _vm.ProxySnapshot.State);
        Assert.Equal(runtime.Forward.ListenPort, _vm.ProxySnapshot.ProxyPort);
        Assert.False(_vm.ProxySnapshot.SystemProxyEnabled);
        Assert.False(_vm.ProxySnapshot.ProcessProxyEnabled);
        await _vm.CheckProxyForwardHealthAsync();
        Assert.Equal(WindowsProxyConnectionState.Connected, _vm.ProxySnapshot.State);
        await _vm.StopProxyAsync();
        Assert.False(runtime.Running);
        Assert.Equal(WindowsProxyConnectionState.Disconnected, _vm.ProxySnapshot.State);
    }

    [Fact]
    public async Task UriImportSavesAndSelectsNodeWithoutRequiringManualSave()
    {
        await _vm.InitializeProxyAsync();
        _vm.ApplyForwardSnapshot([Forward(59)], _ => false);

        await _vm.ImportProxyProfileAsync("ss://aes-256-gcm:import-secret@198.51.100.10:30526#imported");

        var saved = Assert.Single(_vm.ProxyProfiles);
        Assert.Equal(saved.Id, _vm.SelectedProxyProfileId);
        Assert.Equal("198.51.100.10", saved.Server);
        Assert.Equal("import-secret", saved.Password);
        Assert.True(File.Exists(Path.Combine(_stateDir, "proxy-profiles.json")));
    }

    [Fact]
    public async Task TcpProxyOnUdpOnlyForwardFailsBeforeStartingAnyRuntime()
    {
        var host = new ProxyProcessHost();
        using var runtime = InstallRuntime(host);
        runtime.Forward.Transport = "udp";
        await _vm.InitializeProxyAsync();
        _vm.ApplyForwardSnapshot([runtime.Forward], _ => false);
        await SaveNodeAsync("node.example.test", 30526);

        var error = await Assert.ThrowsAsync<InvalidOperationException>(() =>
            _vm.StartProxyAsync(WindowsProxyMode.Http, _vm.SelectedProxyProfileId));

        Assert.Equal("proxy_forward_tcp_required", error.Message);
        Assert.Equal(0, runtime.StartCalls);
        Assert.False(host.IsRunning);
    }

    [Theory]
    [InlineData("vless://11111111-1111-1111-1111-111111111111@198.51.100.10:443#not-ss")]
    [InlineData("https://example.test/subscription")]
    public async Task UriImportRejectsNonShadowsocksWithoutChangingDraftOrSavedNodes(string uri)
    {
        await _vm.InitializeProxyAsync();
        _vm.ProxyEditor.Name = "unsaved-draft";
        _vm.ProxyEditor.Server = "192.0.2.10";

        var error = await Assert.ThrowsAsync<InvalidOperationException>(() => _vm.ImportProxyProfileAsync(uri));

        Assert.Equal("proxy_import_shadowsocks_only", error.Message);
        Assert.Equal("unsaved-draft", _vm.ProxyEditor.Name);
        Assert.Equal("192.0.2.10", _vm.ProxyEditor.Server);
        Assert.Empty(_vm.ProxyProfiles);
    }

    [Theory]
    [InlineData(true)]
    [InlineData(false)]
    public async Task ForwardRemovedOrStoppedDuringProxyStartupNeverPublishesConnected(bool remove)
    {
        var release = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        var host = new ProxyProcessHost { StartRelease = release.Task };
        using var runtime = InstallRuntime(host);
        await _vm.InitializeProxyAsync();
        _vm.ApplyForwardSnapshot([runtime.Forward], _ => false);
        await SaveNodeAsync("node.example.test", 30526);
        var states = new List<WindowsProxyConnectionState>();
        _vm.PropertyChanged += (_, args) =>
        {
            if (args.PropertyName == nameof(MainViewModel.ProxySnapshot)) states.Add(_vm.ProxySnapshot.State);
        };
        var starting = _vm.StartProxyAsync(WindowsProxyMode.Http, _vm.SelectedProxyProfileId);
        await host.StartEntered.Task.WaitAsync(TimeSpan.FromSeconds(3));
        Assert.Equal(WindowsProxyConnectionState.Starting, _vm.ProxySnapshot.State);

        if (remove)
            _vm.ApplyForwardSnapshot([], _ => false);
        else
            runtime.StopForward(runtime.Forward.ForwardId);
        release.SetResult();
        await Assert.ThrowsAnyAsync<Exception>(() => starting);

        Assert.DoesNotContain(WindowsProxyConnectionState.Connected, states);
        Assert.False(host.IsRunning);
        Assert.False(runtime.Running);
        Assert.NotEqual(WindowsProxyConnectionState.Connected, _vm.ProxySnapshot.State);
    }

    [Fact]
    public async Task RowTitleUsesIdAndUnmodifiedDirectionAndNodeNameIsExact()
    {
        await _vm.InitializeProxyAsync();
        var forward = Forward(59);
        forward.ForwardCombinationDirection = "CAN->HKG";
        _vm.ApplyForwardSnapshot([forward], _ => false);
        _vm.ProxyEditor.Name = "test-web";
        await SaveNodeAsync("node.example.test", 30526);

        var row = Assert.Single(_vm.ProxyForwardRows);
        Assert.Equal("Forward #59 \u00b7 CAN->HKG", row.Title);
        Assert.Equal("test-web", row.SelectedProfileName);
        Assert.DoesNotContain("30526", row.Title);
        Assert.DoesNotContain("198.51.100", row.Title);
    }

    [Fact]
    public async Task RowListenerUsesForwardPortAndUpdatesWithoutChangingSavedNode()
    {
        await _vm.InitializeProxyAsync();
        var forward = Forward(59);
        forward.ListenPort = 30522;
        _vm.ApplyForwardSnapshot([forward], _ => false);
        await SaveNodeAsync("node.example.test", 443);
        var row = Assert.Single(_vm.ProxyForwardRows);
        Assert.Equal("127.0.0.1:30522", row.ListenerEndpoint);
        Assert.Equal("BOND", row.ModeLabel);

        var notifications = new List<string?>();
        row.PropertyChanged += (_, args) => notifications.Add(args.PropertyName);
        var updated = Forward(59);
        updated.ListenPort = 32469;
        updated.BackendMode = "ultra";
        _vm.ApplyForwardSnapshot([updated], _ => false);

        Assert.Same(row, Assert.Single(_vm.ProxyForwardRows));
        Assert.Equal("127.0.0.1:32469", row.ListenerEndpoint);
        Assert.Equal("ULTRA", row.ModeLabel);
        Assert.Contains("ListenerEndpoint", notifications);
        Assert.Contains("ModeLabel", notifications);
        Assert.Equal(443, Assert.Single(_vm.ProxyProfiles).Port);
    }

    [Fact]
    public async Task SelectingUnboundForwardDoesNotBorrowAnotherRowsSavedProfile()
    {
        await _vm.InitializeProxyAsync();
        _vm.ApplyForwardSnapshot([Forward(59), Forward(60)], _ => false);
        await SaveNodeAsync("node.example.test", 30526);
        var profileId = _vm.SelectedProxyProfileId;

        _vm.SelectedProxyForwardId = 60;

        Assert.Equal(0, _vm.SelectedProxyProfileId);
        Assert.False(_vm.Config.ProxyProfileIdsByForwardId.ContainsKey(60));
        Assert.Equal(profileId, _vm.ProxyForwardRows[0].SelectedProfileId);
        Assert.True(_vm.ProxyForwardRows[1].IsUnbound);
        Assert.False(_vm.ProxyForwardRows[1].CanConnect);
    }

    [Fact]
    public async Task PairingChoicesExposeAnExplicitDefaultOption()
    {
        await _vm.InitializeProxyAsync();
        _vm.ApplyForwardSnapshot([Forward(59)], _ => false);

        var row = Assert.Single(_vm.ProxyForwardRows);

        Assert.NotEmpty(row.Profiles);
        Assert.Equal(0, row.Profiles[0].Id);
        Assert.Equal(_vm.UnboundProxyProfileText, row.Profiles[0].Name);
        Assert.True(row.IsUnbound);
        Assert.Empty(_vm.PairedProxyForwardRows);
    }

    [Fact]
    public async Task RowPairingsAreIndependentAndPollingPreservesRowIdentity()
    {
        await _vm.InitializeProxyAsync();
        _vm.ApplyForwardSnapshot([Forward(59), Forward(60)], _ => false);
        await SaveNodeAsync("one.example.test", 30526);
        var first = _vm.SelectedProxyProfileId;
        _vm.SelectedProxyForwardId = 60;
        await SaveNodeAsync("two.example.test", 443);
        var second = _vm.SelectedProxyProfileId;
        var row59 = _vm.ProxyForwardRows[0];
        var row60 = _vm.ProxyForwardRows[1];

        row59.SelectedProfileId = second;
        row60.SelectedProfileId = first;
        _vm.ApplyForwardSnapshot([Forward(59), Forward(60)], _ => false);

        Assert.Same(row59, _vm.ProxyForwardRows[0]);
        Assert.Same(row60, _vm.ProxyForwardRows[1]);
        Assert.Equal(second, row59.SelectedProfileId);
        Assert.Equal(first, row60.SelectedProfileId);
        Assert.Equal(second, _vm.Config.ProxyProfileIdsByForwardId[59]);
        Assert.Equal(first, _vm.Config.ProxyProfileIdsByForwardId[60]);
    }

    [Fact]
    public async Task StaleProfilePairStaysUnboundWithoutFallback()
    {
        await _vm.InitializeProxyAsync();
        _vm.ApplyForwardSnapshot([Forward(59), Forward(60)], _ => false);
        await SaveNodeAsync("one.example.test", 30526);
        _vm.Config.ProxyProfileIdsByForwardId[60] = int.MaxValue;
        _vm.ApplyForwardSnapshot([Forward(59), Forward(60)], _ => false);

        Assert.True(_vm.ProxyForwardRows[1].IsUnbound);
        Assert.Equal(0, _vm.ProxyForwardRows[1].SelectedProfileId);
        Assert.False(_vm.ProxyForwardRows[1].CanConnect);
    }

    [Fact]
    public async Task RowConnectUsesItsOwnForwardAndOnlyOwnerCanStopSession()
    {
        var host = new ProxyProcessHost();
        using var runtime = InstallRuntime(host);
        await _vm.InitializeProxyAsync();
        _vm.ApplyForwardSnapshot([runtime.Forward, Forward(60)], _ => false);
        await SaveNodeAsync("one.example.test", 30526);
        var row = _vm.ProxyForwardRows[0];
        _vm.SelectedProxyForwardId = 60;

        await row.ConnectAsync();

        Assert.Equal(59, _vm.SelectedProxyForwardId);
        Assert.Equal(59, runtime.LastStartedForwardId);
        Assert.True(row.IsActive);
        Assert.True(row.CanDisconnect);
        Assert.False(_vm.ProxyForwardRows[1].CanDisconnect);
        await _vm.ProxyForwardRows[1].DisconnectAsync();
        Assert.True(host.IsRunning);
        await row.DisconnectAsync();
        Assert.False(host.IsRunning);
        Assert.False(row.IsActive);
    }

    [Fact]
    public async Task StartingRowHasCancelAndBlocksOtherRowsUntilCancellationCompletes()
    {
        var release = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        var host = new ProxyProcessHost { StartRelease = release.Task };
        using var runtime = InstallRuntime(host);
        await _vm.InitializeProxyAsync();
        _vm.ApplyForwardSnapshot([runtime.Forward, Forward(60)], _ => false);
        await SaveNodeAsync("one.example.test", 30526);
        var row = _vm.ProxyForwardRows[0];
        var starting = row.ConnectAsync();
        await host.StartEntered.Task.WaitAsync(TimeSpan.FromSeconds(3));

        Assert.True(row.CanDisconnect);
        Assert.True(row.IsStarting);
        Assert.False(_vm.ProxyForwardRows[1].CanConnect);
        await row.DisconnectAsync();
        await Assert.ThrowsAnyAsync<OperationCanceledException>(() => starting);

        Assert.False(row.IsActive);
        Assert.False(runtime.Running);
        Assert.False(host.IsRunning);
    }

    private ListenerRuntime InstallRuntime(ProxyProcessHost host)
    {
        var runtime = new ListenerRuntime(_vm.Config, new StateManager(_stateDir));
        host.ForwardReady = () => runtime.Running;
        var executable = Path.Combine(_stateDir, "test-sing-box.exe");
        File.WriteAllText(executable, "test");
        var service = new WindowsProxyConnectionService(
            new WindowsProxyProfileStore(Path.Combine(_stateDir, "proxy-profiles.json")), executable,
            Path.Combine(_stateDir, "runtime"), host, new SystemProxy(), new ProcessAdapter());
        service.SnapshotChanged += snapshot => typeof(MainViewModel)
            .GetMethod("HandleProxySnapshotChanged", BindingFlags.Instance | BindingFlags.NonPublic)!
            .Invoke(_vm, [snapshot]);
        typeof(MainViewModel).GetField("_runtimeManager", BindingFlags.Instance | BindingFlags.NonPublic)!.SetValue(_vm, runtime);
        typeof(MainViewModel).GetField("_proxyService", BindingFlags.Instance | BindingFlags.NonPublic)!.SetValue(_vm, service);
        return runtime;
    }

    private async Task SaveNodeAsync(string server, int port)
    {
        _vm.ProxyEditor.Server = server;
        _vm.ProxyEditor.PortText = port.ToString();
        _vm.ProxyEditor.Password = "saved-secret";
        await _vm.SaveProxyProfileAsync();
    }

    private static ForwardConfig Forward(int id) => new()
    {
        ForwardId = id, ListenPort = 11000 + id, Mode = "bond", Target = "198.51.100.8:443", Transport = "tcp+udp",
    };

    private sealed class ListenerSupervisor : ProcessSupervisor
    {
        public bool Running { get; set; }
        public override bool IsRunning(int forwardId) => Running;
        public override IReadOnlyList<int> GetProcessIds(int forwardId) => Running ? [Environment.ProcessId] : [];
    }

    private sealed class ListenerRuntime : ForwardRuntimeManager
    {
        private readonly ListenerSupervisor _supervisor;
        private readonly TcpListener _listener = new(IPAddress.Loopback, 0);
        public ForwardConfig Forward { get; }
        public int StartCalls { get; private set; }
        public int LastStartedForwardId { get; private set; }
        public int StopCalls { get; private set; }
        public bool Running => _supervisor.Running;

        public ListenerRuntime(AgentConfig config, StateManager state) : this(config, state, new ListenerSupervisor()) { }
        private ListenerRuntime(AgentConfig config, StateManager state, ListenerSupervisor supervisor) : base(config, supervisor, state)
        {
            _supervisor = supervisor;
            _listener.Start();
            Forward = new ForwardConfig { ForwardId = 59, ListenPort = ((IPEndPoint)_listener.LocalEndpoint).Port,
                Target = "198.51.100.8:443", Transport = "tcp" };
        }
        public void StartListener() => _supervisor.Running = true;
        public override void RestartForward(ForwardConfig forward, bool force, string reason)
        {
            LastStartedForwardId = forward.ForwardId;
            StartCalls++;
            StartListener();
        }
        public override void StopForward(int forwardId)
        {
            StopCalls++;
            _supervisor.Running = false;
            _listener.Stop();
        }
        public new void Dispose()
        {
            _listener.Stop();
            base.Dispose();
        }
    }

    private sealed class ProxyProcessHost : IWindowsProxyProcessHost
    {
        public bool IsRunning { get; private set; }
        public bool IsTunReady => IsRunning;
        public Func<bool> ForwardReady { get; set; } = () => false;
        public bool SawForwardReady { get; private set; }
        public string LastConfig { get; private set; } = string.Empty;
        public Exception? StartFailure { get; init; }
        public Task? StartRelease { get; init; }
        public TaskCompletionSource StartEntered { get; } = new(TaskCreationOptions.RunContinuationsAsynchronously);
        public async Task StartAsync(string executablePath, string configPath, WindowsProxyMode mode, CancellationToken ct = default)
        {
            SawForwardReady = ForwardReady();
            LastConfig = File.ReadAllText(configPath);
            IsRunning = true;
            StartEntered.TrySetResult();
            if (StartRelease is not null) await StartRelease.WaitAsync(ct);
            if (StartFailure is not null) throw StartFailure;
        }
        public Task<bool> WaitForTunReadyAsync(CancellationToken ct = default) => Task.FromResult(IsTunReady);
        public Task<bool> WaitForListenerAsync(int port, CancellationToken ct = default) => Task.FromResult(IsRunning);
        public Task StopAsync(CancellationToken ct = default) { IsRunning = false; return Task.CompletedTask; }
        public ValueTask DisposeAsync() { IsRunning = false; return ValueTask.CompletedTask; }
    }

    private sealed class SystemProxy : IWindowsSystemProxyManager
    {
        public bool IsEnabled { get; private set; }
        public Task EnableAsync(int port, CancellationToken ct = default) { IsEnabled = true; return Task.CompletedTask; }
        public Task DisableAsync(CancellationToken ct = default) { IsEnabled = false; return Task.CompletedTask; }
    }

    private sealed class ProcessAdapter : IWindowsProcessProxyAdapter
    {
        public bool IsAvailable => true;
        public string AvailabilityReason => string.Empty;
        public Task StartAsync(int socksPort, IEnumerable<WindowsProcessProxyRule> rules, CancellationToken ct = default) => Task.CompletedTask;
        public Task StopAsync(CancellationToken ct = default) => Task.CompletedTask;
        public ValueTask DisposeAsync() => ValueTask.CompletedTask;
    }

    public void Dispose()
    {
        _vm.DisposeProxyForShutdown();
        try { Directory.Delete(_stateDir, recursive: true); } catch (IOException) { }
    }
}
