using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Mac.ViewModels;
using LeikwanBridge.Mac.Services;
using Xunit;

namespace LeikwanBridge.Mac.Tests.ViewModels;

public sealed class MainWindowViewModelTests
{
    [Fact]
    public void PairingNavigationDoesNotEnterProxyOrStartConnections()
    {
        var controller = new FakeController(new DesktopClientSnapshot { IsSignedIn = true });
        var vm = new MainWindowViewModel(controller, dispatch: action => action());

        vm.ShowPairingCommand.Execute(null);

        Assert.Equal("pairing", vm.SelectedSection);
        Assert.False(vm.IsConnectionsVisible);
        Assert.False(vm.IsDashboardVisible);
        Assert.Empty(controller.AcquiredForwards);

        vm.ShowConnectionsCommand.Execute(null);
        Assert.Equal("connections", vm.SelectedSection);
        Assert.True(vm.IsConnectionsVisible);
    }

    [Fact]
    public async Task UnpairedRouteNeverBorrowsThePreviouslySelectedSavedNode()
    {
        var connections = new CapturingMacConnectionService();
        await connections.SaveProfileAsync(new MacConnectionProfile { Id = 4, Name = "test-web", Server = "private.test" });
        await connections.SaveSelectionAsync(123, 4);
        var controller = new FakeController(new DesktopClientSnapshot
        {
            Forwards =
            [
                new DesktopForwardSnapshot(123, "First route", "single", 12001, false, "stopped"),
                new DesktopForwardSnapshot(124, "Second route", "single", 12002, true, "running"),
            ],
        });
        var vm = new MainWindowViewModel(controller, connections, () => controller.ForwardConfigs, dispatch: action => action());
        vm.SelectedConnectionMode = MacConnectionMode.Proxy;

        vm.SelectedConnectionForward = vm.Forwards[1];

        Assert.Null(vm.SelectedConnectionProfile);
        Assert.False(connections.Selection.ForwardProfiles.ContainsKey(124));
        await vm.StartConnectionCommand.ExecuteAsync(null);
        Assert.Empty(controller.AcquiredForwards);
        Assert.False(vm.IsLocalConnectionActive);
    }

    [Fact]
    public async Task ExistingLibraryDoesNotAutomaticallyBindFirstRouteOnLoad()
    {
        var connections = new CapturingMacConnectionService();
        await connections.SaveProfileAsync(new MacConnectionProfile { Id = 4, Name = "test-web", Server = "private.test" });
        var controller = new FakeController(new DesktopClientSnapshot
        {
            Forwards = [new DesktopForwardSnapshot(123, "First route", "single", 12001, false, "stopped")],
        });

        var vm = new MainWindowViewModel(controller, connections, () => controller.ForwardConfigs, dispatch: action => action());
        vm.SelectedConnectionMode = MacConnectionMode.Proxy;

        Assert.Null(vm.SelectedConnectionProfile);
        Assert.Empty(connections.Selection.ForwardProfiles);
    }

    [Fact]
    public async Task PairingRowsExposeAnExplicitDefaultNodeAndUnpairedRoutesStayOutOfConnectionsSelector()
    {
        var connections = new CapturingMacConnectionService();
        await connections.SaveProfileAsync(new MacConnectionProfile { Id = 4, Name = "test-web", Server = "private.test" });
        var controller = new FakeController(new DesktopClientSnapshot
        {
            Forwards =
            [
                new DesktopForwardSnapshot(123, "First route", "single", 12001, false, "stopped"),
                new DesktopForwardSnapshot(124, "Second route", "single", 12002, false, "stopped"),
            ],
        });

        var vm = new MainWindowViewModel(controller, connections, () => controller.ForwardConfigs, dispatch: action => action());
        vm.SelectedConnectionMode = MacConnectionMode.Proxy;

        Assert.Equal(2, vm.ConnectionRoutes.Count);
        Assert.Empty(vm.PairedConnectionRoutes);
        Assert.Equal(0, vm.ConnectionRoutes[0].NodeOptions[0].Id);
        Assert.Equal("未选择已保存节点", vm.ConnectionRoutes[0].NodeOptions[0].Name);
        Assert.Null(vm.SelectedConnectionForward?.SelectedNode);
    }

    [Fact]
    public async Task ConnectionCannotStartUntilModeIsSelected()
    {
        var connections = new CapturingMacConnectionService();
        await connections.SaveProfileAsync(new MacConnectionProfile { Id = 4, Name = "test-web", Server = "private.test" });
        await connections.SaveSelectionAsync(123, 4);
        var controller = new FakeController(new DesktopClientSnapshot
        {
            Forwards = [new DesktopForwardSnapshot(123, "First route", "single", 12001, false, "stopped")],
        });
        var vm = new MainWindowViewModel(controller, connections, () => controller.ForwardConfigs, dispatch: action => action());

        Assert.Null(vm.SelectedConnectionMode);
        Assert.False(vm.CanStartLocalConnection);
        await vm.StartConnectionCommand.ExecuteAsync(null);
        Assert.Empty(controller.AcquiredForwards);
        Assert.Contains("mode", vm.LocalConnectionError, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public async Task TwoRouteRowsShowIndependentNamesAndStartTheRowsOwnPair()
    {
        var connections = new CapturingMacConnectionService();
        await connections.SaveProfileAsync(new MacConnectionProfile { Id = 4, Name = "test-web", Server = "secret-a.test" });
        await connections.SaveProfileAsync(new MacConnectionProfile { Id = 5, Name = "work-wg", Server = "secret-b.test" });
        await connections.SaveSelectionAsync(123, 4);
        await connections.SaveSelectionAsync(124, 5);
        var controller = new FakeController(new DesktopClientSnapshot
        {
            Forwards =
            [
                new DesktopForwardSnapshot(123, "Forward #123 · First", "single", 12001, false, "stopped"),
                new DesktopForwardSnapshot(124, "Forward #124 · Second", "single", 12002, true, "running"),
            ],
        });
        var vm = new MainWindowViewModel(controller, connections, () => controller.ForwardConfigs, dispatch: action => action());
        vm.SelectedConnectionMode = MacConnectionMode.Proxy;

        Assert.Equal(new[] { "test-web", "work-wg" }, vm.ConnectionRoutes.Select(route => route.SavedNodeName));
        Assert.False(vm.IsLocalConnectionActive);
        await vm.ConnectionRoutes[0].StartConnectionCommand.ExecuteAsync(null);
        Assert.Equal(new[] { 123 }, controller.AcquiredForwards);
        Assert.Equal(4, connections.LastProfileId);
        Assert.Equal(0, connections.DirectStartCalls);
    }

    [Theory]
    [InlineData("wireguard", "tcp", false)]
    [InlineData("wireguard", "udp", true)]
    [InlineData("wireguard", "tcp+udp", true)]
    [InlineData("shadowsocks", "udp", false)]
    [InlineData("shadowsocks", "tcp", true)]
    [InlineData("shadowsocks", "tcp+udp", true)]
    [InlineData("external", "tcp", true)]
    [InlineData("external", "udp", true)]
    [InlineData("external", "tcp+udp", true)]
    public async Task SavedNodeProtocolMustMatchSelectedTransportBeforeAcquire(string protocol, string transport, bool compatible)
    {
        var controller = new FakeController(new DesktopClientSnapshot
        {
            Forwards = [new DesktopForwardSnapshot(123, "Route", "single", 12001, false, "stopped")],
        }) { ForwardTransport = transport };
        var connections = new CapturingMacConnectionService();
        await connections.SaveProfileAsync(new MacConnectionProfile { Id = 4, Protocol = protocol, Name = "Node", Server = "node.example.test" });
        await connections.SaveSelectionAsync(123, 4);
        var vm = new MainWindowViewModel(controller, connections, () => controller.ForwardConfigs, dispatch: action => action());
        vm.SelectedConnectionMode = MacConnectionMode.Proxy;

        await vm.StartConnectionCommand.ExecuteAsync(null);

        Assert.Equal(compatible ? 1 : 0, controller.AcquiredForwards.Count);
        Assert.Equal(compatible ? 1 : 0, connections.RoutedStartCalls);
        Assert.Equal(0, connections.DirectStartCalls);
        if (!compatible)
        {
            Assert.Contains("selected route", vm.LocalConnectionError, StringComparison.OrdinalIgnoreCase);
            Assert.Contains(vm.Logs, message => message.EndsWith("proxy_forward_transport_mismatch", StringComparison.Ordinal));
        }
    }

    [Theory]
    [InlineData(true, true)]
    [InlineData(false, true)]
    [InlineData(true, false)]
    public async Task RemovedOrStoppedForwardDuringHelperStartupCannotBecomeConnected(bool removed, bool publishChange)
    {
        var started = new TaskCompletionSource<CancellationToken>(TaskCreationOptions.RunContinuationsAsynchronously);
        var completeHelper = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        var controller = new FakeController(new DesktopClientSnapshot
        {
            Forwards = [new DesktopForwardSnapshot(123, "Route", "single", 12001, false, "stopped")],
        });
        var connections = new CapturingMacConnectionService
        {
            RoutedStartHandler = async cancellation =>
            {
                started.SetResult(cancellation);
                await completeHelper.Task;
            },
        };
        await connections.SaveProfileAsync(new MacConnectionProfile { Id = 4, Name = "Node", Server = "node.example.test" });
        await connections.SaveSelectionAsync(123, 4);
        var vm = new MainWindowViewModel(controller, connections, () => controller.ForwardConfigs, dispatch: action => action());
        vm.SelectedConnectionMode = MacConnectionMode.Proxy;

        var starting = vm.StartConnectionCommand.ExecuteAsync(null);
        var startupToken = await started.Task;
        var changed = removed ? Array.Empty<DesktopForwardSnapshot>()
            : [new DesktopForwardSnapshot(123, "Route", "single", 12001, false, "stopped")];
        if (publishChange)
            controller.RaiseForwards(changed);
        else
            controller.SetForwardsWithoutNotification(changed);
        try
        {
            if (publishChange)
                Assert.True(startupToken.IsCancellationRequested);
        }
        finally
        {
            completeHelper.SetResult();
            await starting;
        }

        Assert.Equal(MacConnectionState.Disconnected, connections.Snapshot.State);
        Assert.False(vm.IsLocalConnectionActive);
        Assert.Equal([123], controller.ReleasedForwards);
        Assert.NotEmpty(vm.LocalConnectionError);
        Assert.Equal(0, connections.DirectStartCalls);
    }

    [Fact]
    public async Task RestoredPairingAndRouteSwitchSelectEachSavedNode()
    {
        var connections = new CapturingMacConnectionService();
        await connections.SaveProfileAsync(new MacConnectionProfile { Id = 4, Name = "First", Server = "first.test" });
        await connections.SaveProfileAsync(new MacConnectionProfile { Id = 5, Name = "Second", Server = "second.test" });
        await connections.SaveSelectionAsync(123, 4);
        await connections.SaveSelectionAsync(124, 5);
        var controller = new FakeController(new DesktopClientSnapshot
        {
            Forwards =
            [
                new DesktopForwardSnapshot(123, "First route", "single", 12001, false, "stopped"),
                new DesktopForwardSnapshot(124, "Second route", "single", 12002, false, "stopped"),
            ],
        });

        var vm = new MainWindowViewModel(controller, connections, () => controller.ForwardConfigs, dispatch: action => action());
        vm.SelectedConnectionMode = MacConnectionMode.Proxy;

        Assert.Equal(124, vm.SelectedConnectionForward?.ForwardId);
        Assert.Equal(5, vm.SelectedConnectionProfile?.Id);
        vm.SelectedConnectionForward = vm.Forwards[0];
        Assert.Equal(4, vm.SelectedConnectionProfile?.Id);
        Assert.Equal(123, connections.Selection.ForwardId);
        Assert.False(vm.IsProxyEditorExpanded);
    }

    [Fact]
    public async Task MissingSavedRouteDoesNotSelectADifferentRouteOrStartDirectly()
    {
        var connections = new CapturingMacConnectionService();
        await connections.SaveProfileAsync(new MacConnectionProfile { Id = 4, Name = "First", Server = "first.test" });
        await connections.SaveSelectionAsync(124, 4);
        var controller = new FakeController(new DesktopClientSnapshot
        {
            Forwards = [new DesktopForwardSnapshot(123, "Other route", "single", 12001, false, "stopped")],
        });
        var vm = new MainWindowViewModel(controller, connections, () => controller.ForwardConfigs, dispatch: action => action());
        vm.SelectedConnectionMode = MacConnectionMode.Proxy;

        await vm.StartConnectionCommand.ExecuteAsync(null);

        Assert.Null(vm.SelectedConnectionForward);
        Assert.Empty(controller.AcquiredForwards);
        Assert.Equal(0, connections.DirectStartCalls);
        Assert.Equal(0, connections.RoutedStartCalls);
        Assert.NotEmpty(vm.LocalConnectionError);
    }

    [Fact]
    public async Task StopWaitsForOwnedForwardReleaseEvenWhenDisconnectSnapshotArrivesFirst()
    {
        var released = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        var controller = new FakeController(new DesktopClientSnapshot
        {
            Forwards = [new DesktopForwardSnapshot(123, "Route", "single", 12001, false, "stopped")],
        }) { ReleaseCompletion = released.Task };
        var connections = new CapturingMacConnectionService();
        var vm = new MainWindowViewModel(controller, connections, () => controller.ForwardConfigs, dispatch: action => action());
        vm.SelectedConnectionMode = MacConnectionMode.Proxy;
        vm.ProxyImportUri = "ss://aes-256-gcm:secret@example.test:8443#Tokyo";
        await vm.ImportProxyUriCommand.ExecuteAsync(null);
        vm.SelectedConnectionForward = vm.ConnectionRoutes[0];
        vm.SelectedConnectionProfile = connections.Profiles.Single();
        await vm.StartConnectionCommand.ExecuteAsync(null);

        var stopping = vm.StopConnectionCommand.ExecuteAsync(null);

        Assert.False(stopping.IsCompleted);
        released.SetResult();
        await stopping;
        Assert.Equal([123], controller.ReleasedForwards);
    }

    [Fact]
    public async Task StopAllTreatsAnAlreadyDisconnectedRuntimeAsStopped()
    {
        var controller = new FakeController(new DesktopClientSnapshot())
        {
            StopAllException = new InvalidOperationException("client_not_connected"),
        };
        var vm = new MainWindowViewModel(controller, dispatch: action => action());

        await vm.StopAllCommand.ExecuteAsync(null);

        Assert.Equal(1, controller.StopAllCalls);
        Assert.True(vm.IsNoticeVisible);
        Assert.Contains("already stopped", vm.NoticeText, StringComparison.OrdinalIgnoreCase);
    }

    [Fact]
    public async Task StopDuringForwardPreparationCancelsWithoutLaunchingProxy()
    {
        var preparing = new TaskCompletionSource(TaskCreationOptions.RunContinuationsAsynchronously);
        var controller = new FakeController(new DesktopClientSnapshot
        {
            IsSignedIn = true,
            Forwards = [new DesktopForwardSnapshot(123, "Route", "single", 12001, false, "stopped")],
        })
        {
            AcquireHandler = async cancellation =>
            {
                preparing.SetResult();
                await Task.Delay(Timeout.Infinite, cancellation);
                throw new InvalidOperationException("unreachable");
            },
        };
        var connections = new CapturingMacConnectionService();
        var vm = new MainWindowViewModel(controller, connections, () => controller.ForwardConfigs, dispatch: action => action());
        vm.SelectedConnectionMode = MacConnectionMode.Proxy;
        vm.ProxyImportUri = "ss://aes-256-gcm:secret@example.test:8443#Tokyo";
        await vm.ImportProxyUriCommand.ExecuteAsync(null);

        vm.SelectedConnectionForward = vm.ConnectionRoutes[0];
        vm.SelectedConnectionProfile = connections.Profiles.Single();
        var start = vm.StartConnectionCommand.ExecuteAsync(null);
        await preparing.Task;
        await vm.StopConnectionCommand.ExecuteAsync(null);
        await start;

        Assert.Equal(0, connections.RoutedStartCalls);
        Assert.False(vm.IsLocalConnectionActive);
        Assert.Empty(vm.LocalConnectionError);
    }

    [Fact]
    public async Task ImportSavesAndSelectsTheOriginalNodeBeforeStart()
    {
        var connections = new CapturingMacConnectionService();
        var vm = new MainWindowViewModel(new FakeController(new DesktopClientSnapshot()), connections, dispatch: action => action());
        vm.ProxyImportUri = "ss://aes-256-gcm:secret@example.test:8443#Tokyo";

        vm.ImportProxyUriCommand.Execute(null);
        if (vm.ImportProxyUriCommand is CommunityToolkit.Mvvm.Input.IAsyncRelayCommand import)
            await import.ExecutionTask!;

        var saved = Assert.Single(connections.Profiles);
        Assert.Equal(saved.Id, vm.SelectedConnectionProfile?.Id);
        Assert.Equal("example.test", saved.Server);
        Assert.Equal(8443, saved.Port);
        Assert.Equal(0, connections.RoutedStartCalls);
        Assert.False(vm.IsProxyEditorExpanded);
    }

    [Fact]
    public async Task SelectedNodeStartAcquiresChosenForwardBeforeLaunchingProxyAndReleasesItOnStop()
    {
        var controller = new FakeController(new DesktopClientSnapshot
        {
            IsSignedIn = true, IsConnected = true,
            Forwards = [new DesktopForwardSnapshot(123, "Selected route", "single", 12001, false, "stopped")],
        });
        var connections = new CapturingMacConnectionService();
        var vm = new MainWindowViewModel(controller, connections, () => controller.ForwardConfigs, dispatch: action => action());
        vm.SelectedConnectionMode = MacConnectionMode.Proxy;
        vm.ProxyImportUri = "ss://aes-256-gcm:secret@example.test:8443#Tokyo";
        vm.ImportProxyUriCommand.Execute(null);
        if (vm.ImportProxyUriCommand is CommunityToolkit.Mvvm.Input.IAsyncRelayCommand import)
            await import.ExecutionTask!;
        vm.SelectedConnectionForward = vm.ConnectionRoutes[0];
        vm.SelectedConnectionProfile = connections.Profiles.Single();

        await vm.StartConnectionCommand.ExecuteAsync(null);

        Assert.Equal([123], controller.AcquiredForwards);
        Assert.Equal(12001, connections.LastForwardListenPort);
        Assert.Equal(1, connections.RoutedStartCalls);
        Assert.Equal(0, connections.DirectStartCalls);
        Assert.Equal(123, connections.Selection.ForwardId);
        Assert.Equal(vm.SelectedConnectionProfile!.Id, connections.Selection.ProfileId);
        await vm.StopConnectionCommand.ExecuteAsync(null);
        Assert.Equal([123], controller.ReleasedForwards);
    }

    [Theory]
    [InlineData(true)]
    [InlineData(false)]
    public async Task ForwardOrProxyStartFailureNeverFallsBackToOriginalNode(bool failForward)
    {
        var controller = new FakeController(new DesktopClientSnapshot
        {
            Forwards = [new DesktopForwardSnapshot(123, "Selected route", "single", 12001, false, "stopped")],
        }) { ThrowOnAcquire = failForward };
        var connections = new CapturingMacConnectionService { ThrowOnRoutedStart = !failForward };
        var vm = new MainWindowViewModel(controller, connections, () => controller.ForwardConfigs, dispatch: action => action());
        vm.SelectedConnectionMode = MacConnectionMode.Proxy;
        vm.ProxyImportUri = "ss://aes-256-gcm:secret@example.test:8443#Tokyo";
        vm.ImportProxyUriCommand.Execute(null);
        if (vm.ImportProxyUriCommand is CommunityToolkit.Mvvm.Input.IAsyncRelayCommand import)
            await import.ExecutionTask!;
        vm.SelectedConnectionForward = vm.ConnectionRoutes[0];
        vm.SelectedConnectionProfile = connections.Profiles.Single();

        await vm.StartConnectionCommand.ExecuteAsync(null);

        Assert.NotEmpty(vm.LocalConnectionError);
        Assert.Equal(0, connections.DirectStartCalls);
        Assert.Equal(failForward ? 0 : 1, connections.RoutedStartCalls);
        Assert.Equal(failForward ? [] : new[] {123}, controller.ReleasedForwards);
    }

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
        await vm.StopConnectionCommand.ExecuteAsync(null);
        Assert.True(vm.NativeUnrestrictedRoute);
        vm.SelectedNativeConnectionMode = LeikwanBridge.Mac.Services.MacConnectionMode.Proxy;
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
        vm.RememberLoginInfo = false;
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

    [Fact]
    public async Task CheckFailureShowsErrorAndRetryRerunsTheCheck()
    {
        var controller = new FakeController(new DesktopClientSnapshot());
        var attempts = 0;
        var vm = new MainWindowViewModel(
            controller,
            checkForUpdatesWithProgress: (_, _) =>
            {
                attempts++;
                if (attempts == 1)
                    throw new InvalidOperationException("manifest unreachable");
                return Task.CompletedTask;
            },
            dispatch: action => action());

        await vm.CheckForUpdatesCommand.ExecuteAsync(null);

        Assert.True(vm.ShowUpdateError);
        Assert.Contains("manifest unreachable", vm.UpdateErrorMessage);
        Assert.True(vm.IsUpdateBannerVisible);
        Assert.False(vm.IsUpdateInProgress);

        await vm.RetryUpdateCommand.ExecuteAsync(null);

        Assert.Equal(2, attempts);
        Assert.False(vm.ShowUpdateError);
        Assert.Equal(string.Empty, vm.UpdateErrorMessage);
    }

    [Fact]
    public async Task InstallFailureShowsErrorAndRetryRerunsTheInstall()
    {
        var controller = new FakeController(new DesktopClientSnapshot());
        var installCalls = 0;
        var vm = new MainWindowViewModel(
            controller,
            installUpdateWithProgress: (_, _) =>
            {
                installCalls++;
                if (installCalls == 1)
                    throw new InvalidOperationException("update_hash_mismatch");
                return Task.CompletedTask;
            },
            dispatch: action => action());

        controller.RaiseUpdate("2.0.0");
        await vm.InstallUpdateCommand.ExecuteAsync(null);

        Assert.True(vm.ShowUpdateError);
        Assert.Contains("update_hash_mismatch", vm.UpdateErrorMessage);
        Assert.False(vm.IsUpdateInstalling);

        await vm.RetryUpdateCommand.ExecuteAsync(null);

        Assert.Equal(2, installCalls);
        Assert.False(vm.ShowUpdateError);
    }

    [Fact]
    public void UpdateProgressReportsPhasePercentAndMessage()
    {
        var vm = new MainWindowViewModel(new FakeController(new DesktopClientSnapshot()), dispatch: action => action());

        vm.ReportUpdateProgress(UpdateProgress.Downloading(37, "Downloading update... (37 bytes)"));

        Assert.Equal("Downloading update", vm.UpdatePhaseText);
        Assert.Equal(37, vm.UpdatePercent);
        Assert.Equal("Downloading update... (37 bytes)", vm.UpdateProgressMessage);
        Assert.True(vm.IsUpdateInProgress);

        vm.ReportUpdateFinished();
        Assert.False(vm.IsUpdateInProgress);
        Assert.Equal(0, vm.UpdatePercent);
    }

    [Fact]
    public void EndpointSelectionPersistsImmediately()
    {
        var controller = new FakeController(new DesktopClientSnapshot());
        string? savedPanelUrl = null;
        var vm = new MainWindowViewModel(
            controller,
            savePreferences: (url, _, _, _) =>
            {
                savedPanelUrl = url;
                return Task.CompletedTask;
            },
            dispatch: action => action());

        vm.SelectedPanelEndpoint = PanelEndpoints.Options.First(option => option.Key == "aliyun-cdn");

        Assert.Equal(PanelEndpoints.AliyunCdnUrl, savedPanelUrl);
        Assert.Equal(PanelEndpoints.AliyunCdnUrl, vm.PanelUrl);
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
        public List<int> AcquiredForwards { get; } = [];
        public List<int> ReleasedForwards { get; } = [];
        public bool ThrowOnAcquire { get; init; }
        public Exception? StopAllException { get; init; }
        public int StopAllCalls { get; private set; }
        public string ForwardTransport { get; init; } = "tcp+udp";
        public IReadOnlyList<ForwardConfig> ForwardConfigs => Snapshot.Forwards.Select(forward => new ForwardConfig
        {
            ForwardId = forward.ForwardId, ListenPort = forward.ListenPort, Transport = ForwardTransport,
        }).ToList();
        public Task ReleaseCompletion { get; init; } = Task.CompletedTask;
        public Func<CancellationToken, Task<ProxyForwardLease>>? AcquireHandler { get; init; }

        public Task<ProxyForwardLease> AcquireProxyForwardAsync(int forwardId, CancellationToken ct = default)
        {
            AcquiredForwards.Add(forwardId);
            if (AcquireHandler is not null)
                return AcquireHandler(ct);
            if (ThrowOnAcquire)
                throw new InvalidOperationException("forward_not_ready");
            RaiseForwards(Snapshot.Forwards.Select(forward => forward.ForwardId == forwardId
                ? forward with { IsRunning = true, Status = "running" } : forward).ToList());
            return Task.FromResult(new ProxyForwardLease(
                new ForwardConfig { ForwardId = forwardId, ListenPort = 12001, Transport = ForwardTransport },
                ownsForward: true,
                _ => { ReleasedForwards.Add(forwardId); return ReleaseCompletion; }));
        }

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
        public Task StopAllAsync(CancellationToken ct = default)
        {
            StopAllCalls++;
            return StopAllException is null
                ? Task.CompletedTask
                : Task.FromException(StopAllException);
        }
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

        public void SetForwardsWithoutNotification(IReadOnlyList<DesktopForwardSnapshot> forwards)
            => Snapshot = Snapshot with { Forwards = forwards };
        public void RaiseForwards(IReadOnlyList<DesktopForwardSnapshot> forwards)
        {
            SetForwardsWithoutNotification(forwards);
            ForwardsChanged?.Invoke(forwards);
        }
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

        private readonly List<MacConnectionProfile> _profiles = [];
        public IReadOnlyList<MacConnectionProfile> Profiles => _profiles;
        public MacConnectionSelection Selection { get; private set; } = MacConnectionSelection.Empty;
        public int RoutedStartCalls { get; private set; }
        public int DirectStartCalls { get; private set; }
        public int LastForwardListenPort { get; private set; }
        public int LastProfileId { get; private set; }
        public bool ThrowOnRoutedStart { get; init; }
        public Func<CancellationToken, Task>? RoutedStartHandler { get; init; }
        public LeikwanBridge.Mac.Services.MacConnectionSnapshot Snapshot { get; private set; } =
            LeikwanBridge.Mac.Services.MacConnectionSnapshot.Disconnected;
        public DateTimeOffset? ActiveNativeLeaseExpiresAt { get; private set; }
        public string? ActiveNativeLeasePolicyGeneration { get; private set; }
        public bool IsNativeOutboundActive { get; private set; }
        public string? LastNativeRoutePolicy { get; private set; }

        public Task InitializeAsync(CancellationToken ct = default) => Task.CompletedTask;
        public Task SaveProfileAsync(LeikwanBridge.Mac.Services.MacConnectionProfile profile, CancellationToken ct = default)
        {
            if (profile.Id == 0)
                profile.Id = _profiles.Count + 1;
            _profiles.RemoveAll(item => item.Id == profile.Id);
            _profiles.Add(profile.Clone());
            return Task.CompletedTask;
        }
        public Task SaveSelectionAsync(int forwardId, int profileId, CancellationToken ct = default)
        {
            var pairs = new Dictionary<int, int>(Selection.ForwardProfiles);
            if (forwardId > 0 && profileId > 0)
                pairs[forwardId] = profileId;
            Selection = new MacConnectionSelection(forwardId, profileId, pairs);
            return Task.CompletedTask;
        }
        public Task DeleteProfileAsync(int profileId, CancellationToken ct = default) => Task.CompletedTask;
        public Task StartAsync(
            LeikwanBridge.Mac.Services.MacConnectionMode mode,
            int profileId,
            string panelUrl,
            IEnumerable<ForwardConfig> forwards,
            CancellationToken ct = default)
        {
            DirectStartCalls++;
            return Task.CompletedTask;
        }
        public async Task StartRoutedAsync(MacConnectionMode mode, int profileId, int forwardListenPort,
            string panelUrl, IEnumerable<ForwardConfig> forwards, CancellationToken ct = default)
        {
            RoutedStartCalls++;
            LastProfileId = profileId;
            LastForwardListenPort = forwardListenPort;
            if (ThrowOnRoutedStart)
                throw new InvalidOperationException("proxy_start_failed");
            if (RoutedStartHandler is not null)
                await RoutedStartHandler(ct);
            Snapshot = new MacConnectionSnapshot(MacConnectionState.Connected, mode, "Node", 2080, 0, "");
            SnapshotChanged?.Invoke(Snapshot);
        }
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
