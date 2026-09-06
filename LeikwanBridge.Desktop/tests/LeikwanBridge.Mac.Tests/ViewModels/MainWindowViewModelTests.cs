using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Mac.ViewModels;
using Xunit;

namespace LeikwanBridge.Mac.Tests.ViewModels;

public sealed class MainWindowViewModelTests
{
    [Fact]
    public void NativeRouteScopeSelectorFollowsSelectedCarrier()
    {
        var capability = new NativeOutboundCapability
        {
            Enabled = true,
            Protocol = "socks5",
            LeaseProtocol = "leikwan-ultra-native-socks5-v1",
            Transport = "tcp+udp",
            RouteScopes = ["cn_only", "global"],
            DefaultRouteScope = "global",
            Forwards =
            [
                new NativeOutboundForward(159, "Hong Kong -> China Mainland", "a", "tcp+udp", ["cn_only", "global"], "global"),
                new NativeOutboundForward(160, "China Mainland -> Hong Kong", "b", "tcp+udp", ["global"], "global"),
            ],
        };
        var controller = new FakeController(new DesktopClientSnapshot { NativeOutbound = capability });
        var vm = new MainWindowViewModel(controller, dispatch: action => action());

        vm.SelectedNativeForwardId = 160;
        Assert.False(vm.IsNativeRouteScopeSelectable);

        vm.SelectedNativeForwardId = 159;
        Assert.True(vm.IsNativeRouteScopeSelectable);
    }

    [Fact]
    public void NativeOutboundDrawerIsCollapsedByDefault()
    {
        var capability = new NativeOutboundCapability
        {
            Enabled = true,
            Protocol = "socks5",
            LeaseProtocol = "leikwan-ultra-native-socks5-v1",
            Transport = "tcp+udp",
            RouteScopes = ["cn_only", "global"],
            Forwards =
            [
                new NativeOutboundForward(159, "Hong Kong -> China Mainland", "a", "tcp+udp", ["cn_only", "global"], "cn_only"),
            ],
        };
        var vm = new MainWindowViewModel(
            new FakeController(new DesktopClientSnapshot { NativeOutbound = capability }),
            dispatch: action => action());

        Assert.True(vm.IsNativeOutboundVisible);
        Assert.False(vm.IsNativeOutboundExpanded);
    }

    [Fact]
    public void NativeOutboundDrawerStaysHiddenWithoutCarrierRows()
    {
        var capability = new NativeOutboundCapability
        {
            Enabled = true,
            Protocol = "socks5",
            LeaseProtocol = "leikwan-ultra-native-socks5-v1",
            Transport = "tcp+udp",
        };
        var vm = new MainWindowViewModel(
            new FakeController(new DesktopClientSnapshot { NativeOutbound = capability }),
            dispatch: action => action());

        Assert.False(vm.IsNativeOutboundVisible);
        Assert.False(vm.IsNativeOutboundExpanded);
    }

    [Fact]
    public void NativeUnrestrictedRouteIsEntitlementGatedAndOffByDefault()
    {
        var capability = new NativeOutboundCapability
        {
            Enabled = true,
            Protocol = "socks5",
            LeaseProtocol = "leikwan-ultra-native-socks5-v1",
            Transport = "tcp+udp",
            Forwards =
            [
                new NativeOutboundForward(159, "Mainland China -> International", "a", "tcp+udp", ["global"], "global", true),
                new NativeOutboundForward(160, "Other carrier", "b", "tcp+udp", ["global"], "global", false),
            ],
        };
        var vm = new MainWindowViewModel(
            new FakeController(new DesktopClientSnapshot { NativeOutbound = capability }),
            dispatch: action => action());

        vm.SelectedNativeForwardId = 159;
        Assert.True(vm.IsNativeUnrestrictedRouteVisible);
        Assert.False(vm.NativeUnrestrictedRoute);

        vm.SelectedNativeForwardId = 160;
        Assert.False(vm.IsNativeUnrestrictedRouteVisible);
        Assert.False(vm.NativeUnrestrictedRoute);
    }

    [Fact]
    public async Task NativeStartPropagatesExplicitUnrestrictedPolicyToLeaseAndConnection()
    {
        const int forwardId = 159;
        const string direction = "Mainland China -> International";
        var capability = new NativeOutboundCapability
        {
            Enabled = true,
            Protocol = "socks5",
            LeaseProtocol = "leikwan-ultra-native-socks5-v1",
            Transport = "tcp+udp",
            Forwards =
            [
                new NativeOutboundForward(forwardId, direction, "a", "tcp+udp", ["global"], "global", true),
            ],
        };
        var controller = new FakeController(new DesktopClientSnapshot { NativeOutbound = capability })
        {
            NativeLease = new NativeOutboundLease(
                forwardId,
                "native-unrestricted",
                new string('f', 64),
                DateTimeOffset.UtcNow.AddMinutes(2),
                "tcp+udp",
                direction,
                "global",
                NativeOutboundLease.ComputeScopeDigestV2(forwardId, direction, "tcp+udp", "global", "unrestricted"),
                "203.0.113.32",
                5438,
                System.Text.Encoding.UTF8.GetBytes("user"),
                System.Text.Encoding.UTF8.GetBytes("password"),
                routePolicy: "unrestricted"),
        };
        var connections = new CapturingMacConnectionService();
        var vm = new MainWindowViewModel(controller, connections, dispatch: action => action());

        vm.SelectedNativeForwardId = forwardId;
        vm.NativeUnrestrictedRoute = true;

        await vm.StartNativeConnectionCommand.ExecuteAsync(null);

        Assert.Equal("unrestricted", controller.LastNativeRoutePolicy);
        Assert.Equal("unrestricted", connections.LastNativeRoutePolicy);
    }

    [Fact]
    public void NativeOutboundDrawerCollapsesWhenCapabilityIsRevoked()
    {
        var capability = new NativeOutboundCapability
        {
            Enabled = true,
            Protocol = "socks5",
            LeaseProtocol = "leikwan-ultra-native-socks5-v1",
            Transport = "tcp+udp",
            Forwards =
            [
                new NativeOutboundForward(159, "Hong Kong -> China Mainland", "a", "tcp+udp", ["cn_only"], "cn_only"),
            ],
        };
        var controller = new FakeController(new DesktopClientSnapshot { NativeOutbound = capability });
        var vm = new MainWindowViewModel(controller, dispatch: action => action());

        vm.IsNativeOutboundExpanded = true;
        controller.RaiseNativeOutbound(null);

        Assert.False(vm.IsNativeOutboundExpanded);
        Assert.False(vm.IsNativeOutboundVisible);
    }

    [Fact]
    public void NativeIntlOnlyScopeIsPreservedForInternationalCarrier()
    {
        var capability = new NativeOutboundCapability
        {
            Enabled = true,
            Protocol = "socks5",
            LeaseProtocol = "leikwan-ultra-native-socks5-v1",
            Transport = "tcp+udp",
            RouteScopes = ["intl_only", "global"],
            DefaultRouteScope = "intl_only",
            Forwards =
            [
                new NativeOutboundForward(201, "Mainland China -> International", "a", "tcp+udp", ["intl_only", "global"], "intl_only"),
            ],
        };
        var vm = new MainWindowViewModel(
            new FakeController(new DesktopClientSnapshot { NativeOutbound = capability }),
            dispatch: action => action());

        vm.SelectedNativeForwardId = 201;
        vm.SelectedNativeRouteScope = "intl_only";
        vm.NativeBypassChinaMainland = true;

        Assert.Equal("intl_only", vm.SelectedNativeRouteScope);
        Assert.Contains(vm.NativeRouteScopeOptions, option => option.Key == "intl_only");
        Assert.False(vm.IsNativeBypassChinaEnabled);
        Assert.False(vm.NativeBypassChinaMainland);
    }

    [Fact]
    public async Task LoginValidationAndClientSelectionCallOnlyControllerCommands()
    {
        var controller = new FakeController(new DesktopClientSnapshot());
        var vm = new MainWindowViewModel(controller, dispatch: action => action());

        await vm.LoginCommand.ExecuteAsync(null);

        Assert.Equal("Enter a valid panel URL, client ID, and token.", vm.ValidationMessage);
        Assert.Equal(0, controller.AgentTokenLoginCalls);

        vm.PanelUrl = "https://panel.example.test";
        vm.ClientIdText = "21";
        vm.AgentToken = "agent-token-21";
        await vm.LoginCommand.ExecuteAsync(null);
        Assert.Equal(1, controller.AgentTokenLoginCalls);
        controller.RaiseLog("connected");
        Assert.Contains(vm.Logs, message => message.EndsWith("connected", StringComparison.Ordinal));
        Assert.DoesNotContain("agent-token-21", string.Join("\n", vm.Logs));

        controller.RaiseSnapshot(new DesktopClientSnapshot
        {
            IsSignedIn = true,
            RequiresClientSelection = true,
            AvailableClients =
            [
                new DesktopClientChoice { Id = 21, Name = "Client 21" },
                new DesktopClientChoice { Id = 22, Name = "Client 22" },
            ],
        });
        Assert.True(vm.IsClientPickerVisible);
        Assert.Equal(2, vm.Clients.Count);

        await vm.SelectClientCommand.ExecuteAsync(22);
        Assert.Equal([22], controller.SelectedClients);
    }

    [Fact]
    public async Task ImmediateNoticeAndForwardRowsExposeOnlySupportedMacModes()
    {
        var controller = new FakeController(new DesktopClientSnapshot { IsSignedIn = true, IsConnected = true });
        var vm = new MainWindowViewModel(controller, dispatch: action => action());

        controller.RaiseNotice(MacForwardPolicy.UnsupportedModeNotice);
        controller.RaiseForwards(
        [
            new DesktopForwardSnapshot(1, "Forward 1", "single", 12001, false, "stopped"),
            new DesktopForwardSnapshot(2, "Forward 2", "bond", 12002, true, "running"),
            new DesktopForwardSnapshot(3, "Forward 3", "ultra", 12003, true, "running"),
        ]);

        Assert.True(vm.IsNoticeVisible);
        Assert.Equal(MacForwardPolicy.UnsupportedModeNotice, vm.NoticeText);
        Assert.Equal(["single", "bond", "ultra"], vm.Forwards.Select(forward => forward.Mode).ToArray());
        await vm.Forwards[0].StartCommand.ExecuteAsync(null);
        await vm.Forwards[1].StopCommand.ExecuteAsync(null);
        Assert.Equal([1], controller.StartedForwards);
        Assert.Equal([2], controller.StoppedForwards);
    }

    [Fact]
    public async Task SnapshotBusyUpdateAndPreferencesDriveExpectedSurfaces()
    {
        var controller = new FakeController(new DesktopClientSnapshot());
        var installCalls = 0;
        var vm = new MainWindowViewModel(
            controller,
            closeToTray: true,
            installUpdate: _ =>
            {
                installCalls++;
                return Task.CompletedTask;
            },
            dispatch: action => action());

        controller.RaiseSnapshot(new DesktopClientSnapshot { IsSignedIn = true, IsConnected = true, IsBusy = true });
        controller.RaiseUpdate("2.0.0");

        Assert.True(vm.IsBusy);
        Assert.True(vm.IsDashboardVisible);
        Assert.True(vm.IsUpdateVisible);
        Assert.Equal("2.0.0", vm.UpdateVersion);
        Assert.True(vm.CloseToTray);
        await vm.InstallUpdateCommand.ExecuteAsync(null);
        Assert.Equal(1, installCalls);
    }

    [Fact]
    public void BusyStateDisplaysActualConnectionPhase()
    {
        var controller = new FakeController(new DesktopClientSnapshot());
        var vm = new MainWindowViewModel(controller, dispatch: action => action());
        controller.RaiseSnapshot(new DesktopClientSnapshot { IsSignedIn = true, IsBusy = true, ConnectionStatus = "connecting" });
        Assert.Contains("Starting", vm.BusyMessage);
        controller.RaiseLog("preparing_config");
        Assert.Equal("Starting routes", vm.BusyMessage);
        controller.RaiseLog("preparing_routes:2/5");
        Assert.Equal("Starting routes 2/5", vm.BusyMessage);
        controller.RaiseSnapshot(new DesktopClientSnapshot { IsSignedIn = false, IsBusy = true });
        Assert.Equal("Signing in", vm.BusyMessage);
    }

    [Fact]
    public async Task ClientIdTokenLoginUsesTheSelectedAuthenticationMode()
    {
        var controller = new FakeController(new DesktopClientSnapshot());
        var vm = new MainWindowViewModel(controller, dispatch: action => action());

        vm.PanelUrl = "https://panel.example.test";
        vm.ClientIdText = "27";
        vm.AgentToken = "agent-token-27";
        await vm.LoginCommand.ExecuteAsync(null);

        Assert.Equal(1, controller.AgentTokenLoginCalls);
        Assert.Equal((27, "agent-token-27"), controller.LastAgentTokenLogin);
        Assert.Empty(vm.AgentToken);
    }

    [Fact]
    public void MacLoginOffersClientIdTokenAsTheOnlyAuthenticationMode()
    {
        var vm = new MainWindowViewModel(new FakeController(new DesktopClientSnapshot()), dispatch: action => action());

        Assert.Equal([MainWindowViewModel.ClientIdTokenAuthentication], vm.AuthenticationModes);
        Assert.Equal(MainWindowViewModel.ClientIdTokenAuthentication, vm.SelectedAuthenticationMode);
        Assert.False(vm.IsEmailPasswordAuthentication);
        Assert.True(vm.IsClientIdTokenAuthentication);
    }

    [Fact]
    public async Task RefreshCommandUsesInPlaceControllerRefresh()
    {
        var controller = new FakeController(new DesktopClientSnapshot
        {
            IsSignedIn = true,
            IsConnected = true,
            ConnectionStatus = "connected",
        });
        var vm = new MainWindowViewModel(controller, dispatch: action => action());

        await vm.RefreshCommand.ExecuteAsync(null);

        Assert.Equal(1, controller.RefreshCalls);
        Assert.Equal(0, controller.ConnectCalls);
    }

    [Fact]
    public async Task TokenLoginDoesNotReportConnectionFailureAsSignInFailure()
    {
        var controller = new FakeController(new DesktopClientSnapshot())
        {
            SignInOnAgentToken = true,
            ThrowOnConnect = true,
        };
        var vm = new MainWindowViewModel(controller, dispatch: action => action());
        vm.PanelUrl = "https://panel.example.test";
        vm.ClientIdText = "27";
        vm.AgentToken = "agent-token-27";

        await vm.LoginCommand.ExecuteAsync(null);

        Assert.Equal(1, controller.AgentTokenLoginCalls);
        Assert.False(vm.IsLoginVisible);
        Assert.Equal("Signed in, but the client could not connect.", vm.ValidationMessage);
        Assert.True(vm.IsNoticeVisible);
        Assert.Equal(vm.ValidationMessage, vm.NoticeText);
    }

    private sealed class FakeController(DesktopClientSnapshot initial) : IDesktopClientController
    {
        public event Action<DesktopClientSnapshot>? SnapshotChanged;
        public event Action<IReadOnlyList<DesktopForwardSnapshot>>? ForwardsChanged;
        public event Action<NativeOutboundCapability?>? NativeOutboundChanged;
        public event Action<string>? NoticeRaised;
        public event Action<string>? LogRaised;
        public event Action<string>? UpdateAvailable;

        public DesktopClientSnapshot Snapshot { get; private set; } = initial;
        public NativeOutboundCapability? NativeOutbound => Snapshot.NativeOutbound;
        public int LoginCalls { get; private set; }
        public int AgentTokenLoginCalls { get; private set; }
        public int ConnectCalls { get; private set; }
        public int RefreshCalls { get; private set; }
        public (int ClientId, string Token) LastAgentTokenLogin { get; private set; }
        public bool SignInOnAgentToken { get; init; }
        public bool ThrowOnConnect { get; init; }
        public NativeOutboundLease? NativeLease { get; init; }
        public string? LastNativeRoutePolicy { get; private set; }
        public List<int> SelectedClients { get; } = [];
        public List<int> StartedForwards { get; } = [];
        public List<int> StoppedForwards { get; } = [];

        public Task LoginAsync(string panelUrl, string email, string password, CancellationToken ct = default)
        {
            LoginCalls++;
            return Task.CompletedTask;
        }

        public Task LoginWithAgentTokenAsync(string panelUrl, int clientId, string agentToken, CancellationToken ct = default)
        {
            AgentTokenLoginCalls++;
            LastAgentTokenLogin = (clientId, agentToken);
            if (SignInOnAgentToken)
            {
                RaiseSnapshot(new DesktopClientSnapshot
                {
                    IsSignedIn = true,
                    ActiveClientName = $"Client {clientId}",
                });
            }
            return Task.CompletedTask;
        }

        public Task SelectClientAsync(int clientId, CancellationToken ct = default)
        {
            SelectedClients.Add(clientId);
            return Task.CompletedTask;
        }

        public Task ConnectAsync(CancellationToken ct = default)
        {
            ConnectCalls++;
            return ThrowOnConnect
                ? Task.FromException(new InvalidOperationException("runtime unavailable"))
                : Task.CompletedTask;
        }
        public Task RefreshAsync(CancellationToken ct = default)
        {
            RefreshCalls++;
            return Task.CompletedTask;
        }
        public Task DisconnectAsync(CancellationToken ct = default) => Task.CompletedTask;
        public Task StartForwardAsync(int forwardId, CancellationToken ct = default)
        {
            StartedForwards.Add(forwardId);
            return Task.CompletedTask;
        }
        public Task StopForwardAsync(int forwardId, CancellationToken ct = default)
        {
            StoppedForwards.Add(forwardId);
            return Task.CompletedTask;
        }
        public Task StartAllAsync(CancellationToken ct = default) => Task.CompletedTask;
        public Task StopAllAsync(CancellationToken ct = default) => Task.CompletedTask;
        public Task<NativeOutboundLease?> ResolveNativeOutboundLeaseAsync(int forwardId, string routeScope, CancellationToken ct = default)
            => Task.FromResult<NativeOutboundLease?>(null);
        public Task<NativeOutboundLease?> ResolveNativeOutboundLeaseAsync(
            int forwardId,
            string routeScope,
            string routePolicy,
            CancellationToken ct = default)
        {
            LastNativeRoutePolicy = routePolicy;
            return Task.FromResult(NativeLease);
        }
        public Task SignOutAsync(CancellationToken ct = default) => Task.CompletedTask;
        public ValueTask DisposeAsync() => ValueTask.CompletedTask;

        public void RaiseSnapshot(DesktopClientSnapshot snapshot)
        {
            Snapshot = snapshot;
            SnapshotChanged?.Invoke(snapshot);
        }

        public void RaiseForwards(IReadOnlyList<DesktopForwardSnapshot> forwards) => ForwardsChanged?.Invoke(forwards);
        public void RaiseNativeOutbound(NativeOutboundCapability? capability)
        {
            Snapshot = Snapshot with { NativeOutbound = capability };
            NativeOutboundChanged?.Invoke(capability);
        }
        public void RaiseNotice(string notice) => NoticeRaised?.Invoke(notice);
        public void RaiseUpdate(string version) => UpdateAvailable?.Invoke(version);
        public void RaiseLog(string message) => LogRaised?.Invoke(message);
    }

    private sealed class CapturingMacConnectionService : LeikwanBridge.Mac.Services.IMacConnectionService
    {
        public event Action<LeikwanBridge.Mac.Services.MacConnectionSnapshot>? SnapshotChanged;

        public IReadOnlyList<LeikwanBridge.Mac.Services.MacConnectionProfile> Profiles { get; } = [];
        public LeikwanBridge.Mac.Services.MacConnectionSnapshot Snapshot { get; private set; } =
            LeikwanBridge.Mac.Services.MacConnectionSnapshot.Disconnected;
        public DateTimeOffset? ActiveNativeLeaseExpiresAt { get; private set; }
        public string? ActiveNativeLeasePolicyGeneration { get; private set; }
        public bool IsNativeOutboundActive { get; private set; }
        public string? LastNativeRoutePolicy { get; private set; }

        public Task InitializeAsync(CancellationToken ct = default) => Task.CompletedTask;
        public Task SaveProfileAsync(LeikwanBridge.Mac.Services.MacConnectionProfile profile, CancellationToken ct = default)
            => Task.CompletedTask;
        public Task DeleteProfileAsync(int profileId, CancellationToken ct = default) => Task.CompletedTask;
        public Task StartAsync(
            LeikwanBridge.Mac.Services.MacConnectionMode mode,
            int profileId,
            string panelUrl,
            IEnumerable<ForwardConfig> forwards,
            CancellationToken ct = default)
            => Task.CompletedTask;
        public Task StartNativeAsync(
            LeikwanBridge.Mac.Services.MacConnectionMode mode,
            NativeOutboundLease lease,
            string panelUrl,
            IEnumerable<ForwardConfig> forwards,
            bool bypassChinaMainland = false,
            CancellationToken ct = default)
            => StartNativeAsync(mode, lease, panelUrl, forwards, bypassChinaMainland, "restricted", ct);
        public Task StartNativeAsync(
            LeikwanBridge.Mac.Services.MacConnectionMode mode,
            NativeOutboundLease lease,
            string panelUrl,
            IEnumerable<ForwardConfig> forwards,
            bool bypassChinaMainland,
            string nativeRoutePolicy,
            CancellationToken ct = default)
        {
            LastNativeRoutePolicy = nativeRoutePolicy;
            IsNativeOutboundActive = true;
            ActiveNativeLeaseExpiresAt = lease.ExpiresAt;
            ActiveNativeLeasePolicyGeneration = lease.PolicyGeneration;
            Snapshot = new LeikwanBridge.Mac.Services.MacConnectionSnapshot(
                LeikwanBridge.Mac.Services.MacConnectionState.Connected,
                mode,
                LeikwanBridge.Mac.Services.MacConnectionService.NativeOutboundDisplayName,
                2080,
                0,
                "");
            SnapshotChanged?.Invoke(Snapshot);
            return Task.CompletedTask;
        }
        public Task<bool> TryRefreshNativeAsync(NativeOutboundLease lease, CancellationToken ct = default)
        {
            ActiveNativeLeaseExpiresAt = lease.ExpiresAt;
            ActiveNativeLeasePolicyGeneration = lease.PolicyGeneration;
            return Task.FromResult(true);
        }
        public Task StopAsync(CancellationToken ct = default)
        {
            IsNativeOutboundActive = false;
            ActiveNativeLeaseExpiresAt = null;
            ActiveNativeLeasePolicyGeneration = null;
            Snapshot = LeikwanBridge.Mac.Services.MacConnectionSnapshot.Disconnected;
            SnapshotChanged?.Invoke(Snapshot);
            return Task.CompletedTask;
        }
        public ValueTask DisposeAsync() => ValueTask.CompletedTask;
    }
}
