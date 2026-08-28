using LeikwanBridge.Core.Helpers;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Mac.ViewModels;
using Xunit;

namespace LeikwanBridge.Mac.Tests.ViewModels;

public sealed class MainWindowViewModelTests
{
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
        public event Action<string>? NoticeRaised;
        public event Action<string>? LogRaised;
        public event Action<string>? UpdateAvailable;

        public DesktopClientSnapshot Snapshot { get; private set; } = initial;
        public int LoginCalls { get; private set; }
        public int AgentTokenLoginCalls { get; private set; }
        public int ConnectCalls { get; private set; }
        public int RefreshCalls { get; private set; }
        public (int ClientId, string Token) LastAgentTokenLogin { get; private set; }
        public bool SignInOnAgentToken { get; init; }
        public bool ThrowOnConnect { get; init; }
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
        public Task SignOutAsync(CancellationToken ct = default) => Task.CompletedTask;
        public ValueTask DisposeAsync() => ValueTask.CompletedTask;

        public void RaiseSnapshot(DesktopClientSnapshot snapshot)
        {
            Snapshot = snapshot;
            SnapshotChanged?.Invoke(snapshot);
        }

        public void RaiseForwards(IReadOnlyList<DesktopForwardSnapshot> forwards) => ForwardsChanged?.Invoke(forwards);
        public void RaiseNotice(string notice) => NoticeRaised?.Invoke(notice);
        public void RaiseUpdate(string version) => UpdateAvailable?.Invoke(version);
        public void RaiseLog(string message) => LogRaised?.Invoke(message);
    }
}
