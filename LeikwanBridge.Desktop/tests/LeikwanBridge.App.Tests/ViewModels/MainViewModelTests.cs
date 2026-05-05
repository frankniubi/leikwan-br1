using LeikwanBridge.App.ViewModels;
using LeikwanBridge.Core.Models;
using System.Reflection;
using Xunit;

namespace LeikwanBridge.App.Tests.ViewModels;

public sealed class MainViewModelTests
{
    [Fact]
    public void Initialize_WithValidConfig_HidesSetupWizard()
    {
        var vm = new MainViewModel();

        vm.Initialize(new AgentConfig
        {
            MasterUrl = "https://v3.leikwanhost.com",
            DesktopSessionToken = "desktop-session-token",
            SignedInEmail = "ops@example.com",
            ActiveClientId = 1,
            ActiveClientName = "Client 1",
            ClientId = 1,
            AgentToken = "token",
        });

        Assert.False(vm.ShowSetupWizard);
        Assert.True(vm.ShouldAutoConnectOnStartup);
    }

    [Fact]
    public void Initialize_WithoutDesktopSession_ShowsLoginScreen()
    {
        var vm = new MainViewModel();

        vm.Initialize(new AgentConfig
        {
            MasterUrl = "https://v3-mirror.leikwanhost.com",
        });

        Assert.True(vm.ShowSetupWizard);
        Assert.False(vm.ShouldAutoConnectOnStartup);
    }

    [Fact]
    public void AppDefaults_UsesMirrorMasterUrl()
    {
        Assert.Equal("https://v3-mirror.leikwanhost.com", AppDefaults.DefaultMasterUrl);
    }

    [Fact]
    public void CompleteDesktopLogin_WithSingleClient_StoresPendingSelectionWithoutPicker()
    {
        var vm = new MainViewModel();
        vm.Initialize(new AgentConfig { MasterUrl = "https://v3-mirror.leikwanhost.com" });

        vm.CompleteDesktopLogin(new DesktopLoginResponse
        {
            Ok = true,
            SessionToken = "desktop-session-token",
            UserId = 8,
            Email = "ops@example.com",
            Username = "Ops",
            Clients =
            [
                new DesktopClientChoice { Id = 26, Name = "Client 26" },
            ],
        });

        Assert.False(vm.ShowClientPicker);
        Assert.Equal(26, vm.PendingClientSelectionId);
    }

    [Fact]
    public void CompleteDesktopLogin_WithMultipleClients_ShowsPicker()
    {
        var vm = new MainViewModel();
        vm.Initialize(new AgentConfig { MasterUrl = "https://v3-mirror.leikwanhost.com" });

        vm.CompleteDesktopLogin(new DesktopLoginResponse
        {
            Ok = true,
            SessionToken = "desktop-session-token",
            UserId = 8,
            Email = "ops@example.com",
            Username = "Ops",
            Clients =
            [
                new DesktopClientChoice { Id = 26, Name = "Client 26" },
                new DesktopClientChoice { Id = 27, Name = "Client 27" },
            ],
        });

        Assert.True(vm.ShowClientPicker);
        Assert.Equal(2, vm.AvailableClientChoices.Count);
    }

    [Fact]
    public void CompleteClientSelection_StoresActiveClientAndHidesPicker()
    {
        var vm = new MainViewModel();
        vm.Initialize(new AgentConfig { MasterUrl = "https://v3-mirror.leikwanhost.com" });

        vm.CompleteClientSelection(new ClientSelectionResponse
        {
            Ok = true,
            ClientId = 27,
            ClientName = "Client 27",
            AgentToken = "agent-token-27",
        });

        Assert.False(vm.ShowClientPicker);
        Assert.False(vm.ShowSetupWizard);
        Assert.Equal(27, vm.Config.ActiveClientId);
        Assert.Equal("Client 27", vm.Config.ActiveClientName);
        Assert.Equal("agent-token-27", vm.Config.AgentToken);
    }

    [Fact]
    public void SignOutForTests_ClearsSessionAndClientState()
    {
        var vm = new MainViewModel();
        vm.Initialize(new AgentConfig
        {
            MasterUrl = "https://v3-mirror.leikwanhost.com",
            DesktopSessionToken = "desktop-session-token",
            SignedInEmail = "ops@example.com",
            ActiveClientId = 26,
            ActiveClientName = "Client 26",
            ClientId = 26,
            AgentToken = "agent-token-26",
        });

        vm.SignOutForTests();

        Assert.True(vm.ShowSetupWizard);
        Assert.Equal(string.Empty, vm.Config.DesktopSessionToken);
        Assert.Equal(0, vm.Config.ActiveClientId);
        Assert.Equal(string.Empty, vm.Config.AgentToken);
    }

    [Fact]
    public void ApplyForwardSnapshot_AutoSelectsMptcpFilter_WhenMptcpForwardExists()
    {
        var vm = new MainViewModel();

        vm.ApplyForwardSnapshot(
        [
            CreateForward(24, "mptcp"),
            CreateForward(26, "bond"),
        ], _ => false);

        Assert.Equal("MPTCP", vm.ActiveModeFilter);
        Assert.Single(vm.VisibleForwards);
        Assert.Equal(24, vm.VisibleForwards[0].ForwardId);
        Assert.NotNull(vm.SelectedForward);
        Assert.Equal(24, vm.SelectedForward!.ForwardId);
    }

    [Fact]
    public void ApplyForwardSnapshot_FallsBackToAllFilter_WhenNoMptcpForwardExists()
    {
        var vm = new MainViewModel();

        vm.ApplyForwardSnapshot(
        [
            CreateForward(26, "bond"),
            CreateForward(27, "single"),
        ], _ => false);

        Assert.Equal("All", vm.ActiveModeFilter);
        Assert.Equal(2, vm.VisibleForwards.Count);
        Assert.NotNull(vm.SelectedForward);
    }

    [Fact]
    public void SelectForward_UpdatesSelectedForward_AndShowsForwardsView()
    {
        var vm = new MainViewModel();
        vm.ApplyForwardSnapshot(
        [
            CreateForward(24, "mptcp"),
            CreateForward(26, "bond"),
        ], _ => false);

        var target = vm.VisibleForwards.Last();
        vm.SelectForwardCommand.Execute(target);

        Assert.Equal(target.ForwardId, vm.SelectedForward?.ForwardId);
        Assert.Equal("Forwards", vm.CurrentView);
    }

    [Fact]
    public void OnPullStateChanged_ClearsTransientErrorStatus_AfterSuccessfulSync()
    {
        var vm = new MainViewModel
        {
            ConnectionStatus = "Error: The SSL connection could not be established",
        };

        var method = typeof(MainViewModel).GetMethod("OnPullStateChanged", BindingFlags.Instance | BindingFlags.NonPublic);
        Assert.NotNull(method);

        method!.Invoke(vm, [new PullState
        {
            Status = "ok",
            LastSuccessAt = DateTimeOffset.UtcNow.ToUnixTimeSeconds(),
        }]);

        Assert.Equal("DashConnected", vm.ConnectionStatus);
        Assert.NotEqual("Never", vm.LastSyncTime);
    }

    private static ForwardConfig CreateForward(int forwardId, string mode)
    {
        return new ForwardConfig
        {
            ForwardId = forwardId,
            Mode = mode,
            ListenPort = 10000 + forwardId,
            Target = "193.227.109.82:5201",
            Transport = "tcp",
            Endpoints =
            [
                new EndpointConfig
                {
                    Host = "127.0.0.1",
                    Port = 443,
                },
            ],
        };
    }
}
