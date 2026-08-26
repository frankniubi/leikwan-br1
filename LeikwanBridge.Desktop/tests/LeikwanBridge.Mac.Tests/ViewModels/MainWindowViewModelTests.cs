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

        Assert.Equal("Enter the panel URL, email, and password.", vm.ValidationMessage);
        Assert.Equal(0, controller.LoginCalls);

        vm.PanelUrl = "https://panel.example.test";
        vm.Email = "user@example.test";
        vm.Password = "password-canary";
        await vm.LoginCommand.ExecuteAsync(null);
        Assert.Equal(1, controller.LoginCalls);
        controller.RaiseLog("connected");
        Assert.Contains(vm.Logs, message => message.EndsWith("connected", StringComparison.Ordinal));
        Assert.DoesNotContain("password-canary", string.Join("\n", vm.Logs));

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

    private sealed class FakeController(DesktopClientSnapshot initial) : IDesktopClientController
    {
        public event Action<DesktopClientSnapshot>? SnapshotChanged;
        public event Action<IReadOnlyList<DesktopForwardSnapshot>>? ForwardsChanged;
        public event Action<string>? NoticeRaised;
        public event Action<string>? LogRaised;
        public event Action<string>? UpdateAvailable;

        public DesktopClientSnapshot Snapshot { get; private set; } = initial;
        public int LoginCalls { get; private set; }
        public List<int> SelectedClients { get; } = [];
        public List<int> StartedForwards { get; } = [];
        public List<int> StoppedForwards { get; } = [];

        public Task LoginAsync(string panelUrl, string email, string password, CancellationToken ct = default)
        {
            LoginCalls++;
            return Task.CompletedTask;
        }

        public Task SelectClientAsync(int clientId, CancellationToken ct = default)
        {
            SelectedClients.Add(clientId);
            return Task.CompletedTask;
        }

        public Task ConnectAsync(CancellationToken ct = default) => Task.CompletedTask;
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
