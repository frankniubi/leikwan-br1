using LeikwanBridge.App.ViewModels;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using System.Text.Json;
using System.Reflection;
using Xunit;

namespace LeikwanBridge.App.Tests.ViewModels;

public sealed class MainViewModelTests
{
    [Fact]
    public void NativeRouteScopeSelectorFollowsSelectedCarrier()
    {
        var vm = new MainViewModel
        {
            NativeOutboundCapability = new NativeOutboundCapability
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
            },
        };

        vm.SelectedNativeForwardId = 160;
        Assert.False(vm.IsNativeRouteScopeSelectable);

        vm.SelectedNativeForwardId = 159;
        Assert.True(vm.IsNativeRouteScopeSelectable);
    }

    [Fact]
    public void NativeOutboundDrawerIsCollapsedByDefault()
    {
        var vm = new MainViewModel
        {
            NativeOutboundCapability = new NativeOutboundCapability
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
            },
        };

        Assert.True(vm.IsNativeOutboundVisible);
        Assert.False(vm.IsNativeOutboundExpanded);
    }

    [Fact]
    public void MainlandToInternationalCarrierExposesIntlOnlyAndDisablesChinaBypass()
    {
        var vm = new MainViewModel
        {
            NativeOutboundCapability = new NativeOutboundCapability
            {
                Enabled = true,
                Protocol = "socks5",
                LeaseProtocol = "leikwan-ultra-native-socks5-v1",
                Transport = "tcp+udp",
                RouteScopes = ["intl_only", "global"],
                DefaultRouteScope = "intl_only",
                Forwards =
                [
                    new NativeOutboundForward(
                        201,
                        "Mainland China -> International",
                        "a",
                        "tcp+udp",
                        ["intl_only", "global"],
                        "intl_only"),
                ],
            },
        };

        vm.SelectedNativeForwardId = 201;
        vm.SelectedNativeRouteScope = "intl_only";
        vm.NativeBypassChinaMainland = true;

        Assert.True(vm.IsNativeRouteScopeSelectable);
        Assert.True(vm.IsNativeRouteIntlOnly);
        Assert.False(vm.IsNativeBypassChinaEnabled);
        Assert.False(vm.NativeBypassChinaMainland);
    }

    [Fact]
    public void ApplyForwardSnapshot_DisplaysForwardIdPortAndDirection()
    {
        var vm = new MainViewModel();
        var config = JsonSerializer.Deserialize<ForwardConfig>("""
            {
                "forward_id": 59,
                "mode": "bond",
                "listen_port": 11059,
                "target": "193.227.109.82:5201",
                "transport": "tcp+udp",
                "forward_combination_direction": "CAN->HKG"
            }
            """)!;
        vm.ApplyForwardSnapshot([config], _ => true);

        var forward = Assert.Single(vm.Forwards);
        Assert.Equal(59, forward.ForwardId);
        Assert.Equal("Forward #59 | Port 11059 | Direction CAN->HKG", forward.DisplayName);
        Assert.Equal(ForwardLifecycleState.Connected, forward.Summary.Lifecycle);
        Assert.Equal(ForwardQualityState.Good, forward.Summary.Quality);
        Assert.True(forward.Summary.CanStop);
        Assert.False(forward.Summary.CanStart);
    }

    [Fact]
    public void ApplyForwardSnapshot_WhenDirectionMissing_UsesStablePlaceholder()
    {
        var vm = new MainViewModel();
        vm.ApplyForwardSnapshot([CreateForward(26, "bond")], _ => false);

        var forward = Assert.Single(vm.Forwards);

        Assert.Equal("Forward #26 | Port 10026 | Direction -", forward.DisplayName);
    }

    [Fact]
    public void ApplyForwardSnapshot_StoppedForward_IsNotReportedRunning()
    {
        var vm = new MainViewModel();
        vm.ApplyForwardSnapshot([CreateForward(26, "bond")], _ => false);

        var forward = Assert.Single(vm.Forwards);
        Assert.Equal(ForwardLifecycleState.Unavailable, forward.Summary.Lifecycle);
        Assert.False(forward.Summary.CanStop);
        Assert.True(forward.Summary.CanStart);
    }

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
        Assert.True(vm.HasSignedInEmail);
        Assert.Equal("ops@example.com", vm.SignedInEmail);
    }

    [Fact]
    public void Initialize_PreservesSelectedProxyGameAndMirrorsLaterChanges()
    {
        var vm = new MainViewModel();
        var config = new AgentConfig
        {
            ProxyActiveGameId = "steam",
        };

        vm.Initialize(config);

        Assert.Equal("steam", vm.SelectedProxyGameId);
        vm.SelectedProxyGameId = "valorant";
        Assert.Equal("valorant", config.ProxyActiveGameId);
    }

    [Fact]
    public async Task AddProxyGameFolderCreatesNamedProfileWithAllExecutableProcessNames()
    {
        var stateDir = Path.Combine(Path.GetTempPath(), "leikwan-proxy-game-folder-" + Guid.NewGuid().ToString("N"));
        var gameDir = Path.Combine(stateDir, "game");
        Directory.CreateDirectory(Path.Combine(gameDir, "bin"));
        await File.WriteAllTextAsync(Path.Combine(gameDir, "launcher.exe"), "test");
        await File.WriteAllTextAsync(Path.Combine(gameDir, "bin", "game.exe"), "test");
        await File.WriteAllTextAsync(Path.Combine(gameDir, "readme.txt"), "not an executable");

        var vm = new MainViewModel();
        try
        {
            vm.Initialize(new AgentConfig
            {
                StateDir = stateDir,
                ProxySingBoxBin = Path.Combine(stateDir, "sing-box.exe"),
            });
            await vm.InitializeProxyAsync();

            var profile = await vm.AddProxyGameFolderAsync(gameDir, "HKG Game");

            Assert.Equal("HKG Game", profile.DisplayName);
            Assert.Equal(
                ["game.exe", "launcher.exe"],
                profile.ProcessPatterns.OrderBy(value => value, StringComparer.OrdinalIgnoreCase).ToArray());
            Assert.Contains(vm.ProxyGameOptions, option => option.Id == profile.Id);
        }
        finally
        {
            vm.DisposeProxyForShutdown();
            try { Directory.Delete(stateDir, recursive: true); } catch (IOException) { }
        }
    }

    [Fact]
    public async Task RefreshProxyGameOptionsPreservesSelectedGameAndDisplayName()
    {
        var stateDir = Path.Combine(Path.GetTempPath(), "leikwan-proxy-game-selection-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(stateDir);
        var executable = Path.Combine(stateDir, "game.exe");
        await File.WriteAllTextAsync(executable, "test");

        var vm = new MainViewModel();
        try
        {
            vm.Initialize(new AgentConfig
            {
                StateDir = stateDir,
                ProxySingBoxBin = Path.Combine(stateDir, "sing-box.exe"),
            });
            await vm.InitializeProxyAsync();
            var profile = await vm.AddProxyGameAsync(executable, "My Game");

            vm.SelectedProxyGameId = profile.Id;
            vm.RefreshProxyGameOptions();

            Assert.Equal(profile.Id, vm.SelectedProxyGameId);
            Assert.Equal("My Game", vm.SelectedProxyGameDisplayName);
        }
        finally
        {
            vm.DisposeProxyForShutdown();
            try { Directory.Delete(stateDir, recursive: true); } catch (IOException) { }
        }
    }

    [Fact]
    public void Initialize_WithTokenOnlyConfig_HidesSetupWizard()
    {
        var vm = new MainViewModel();

        vm.Initialize(new AgentConfig
        {
            MasterUrl = "https://v3.leikwanhost.com",
            ClientId = 1,
            AgentToken = "agent-token",
        });

        Assert.False(vm.ShowSetupWizard);
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
        Assert.False(vm.HasSignedInEmail);
    }

    [Fact]
    public async Task ShutdownForExit_IsIdempotentAndBlocksLateInitialization()
    {
        var vm = new MainViewModel();
        vm.Initialize(new AgentConfig
        {
            MasterUrl = "https://v3.leikwanhost.com",
            ClientId = 1,
            ActiveClientId = 1,
            AgentToken = "agent-token",
        });

        var first = vm.ShutdownForExitAsync();
        var second = vm.ShutdownForExitAsync();

        Assert.Same(first, second);
        await first;
        Assert.True(vm.IsShuttingDown);
        await Assert.ThrowsAsync<OperationCanceledException>(() => vm.InitializeProxyAsync());
    }

    [Fact]
    public async Task SaveProxyProfileAfterInitializationKeepsEditorValuesAndAddsLibraryEntry()
    {
        var stateDir = Path.Combine(Path.GetTempPath(), "leikwan-proxy-vm-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(stateDir);
        var vm = new MainViewModel();
        try
        {
            vm.Initialize(new AgentConfig
            {
                StateDir = stateDir,
                ProxySingBoxBin = Path.Combine(stateDir, "sing-box.exe"),
            });
            await vm.InitializeProxyAsync();

            vm.ProxyEditor.Name = "HKG test";
            vm.ProxyEditor.Protocol = WindowsProxyProtocol.Shadowsocks2022;
            vm.ProxyEditor.Server = "east.example.test";
            vm.ProxyEditor.PortText = "30526";
            vm.ProxyEditor.Method = "2022-blake3-aes-256-gcm";
            vm.ProxyEditor.Password = "test-secret";

            await vm.SaveProxyProfileAsync();

            var profile = Assert.Single(vm.ProxyProfiles);
            Assert.Equal("HKG test", profile.Name);
            Assert.Equal("east.example.test", profile.Server);
            Assert.Equal(30526, profile.Port);
            Assert.Equal(profile.Id, vm.Config.ProxyActiveProfileId);
            Assert.True(File.Exists(Path.Combine(stateDir, "proxy-profiles.json")));
        }
        finally
        {
            vm.DisposeProxyForShutdown();
            try { Directory.Delete(stateDir, recursive: true); } catch (IOException) { }
        }
    }

    [Fact]
    public void ApplyUsageSnapshot_FormatsDashboardCounters()
    {
        var vm = new MainViewModel();

        vm.ApplyUsageSnapshot(new RuntimeUsageSnapshot(1536, 2048));

        Assert.Equal("1.5 KB", vm.TotalBytesIn);
        Assert.Equal("2.0 KB", vm.TotalBytesOut);
    }

    [Fact]
    public void AppDefaults_UsesAuthoritativeMasterUrl()
    {
        Assert.Equal("https://v3.leikwanhost.com", AppDefaults.DefaultMasterUrl);
        Assert.Equal(
            AppDefaults.DefaultMasterUrl,
            AppDefaults.NormalizeMasterUrl(AppDefaults.LegacyMasterUrl + "/"));
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
    public void ShowUpdatePrompt_SetsUpdatePromptState()
    {
        var vm = new MainViewModel();

        vm.ShowUpdatePromptForTests("0.1.15.0");

        Assert.True(vm.ShowUpdatePrompt);
        Assert.Equal("0.1.15.0", vm.AvailableUpdateVersion);
    }

    [Fact]
    public void DeferUpdateForRestart_StoresPendingUpdateState()
    {
        var vm = new MainViewModel();
        vm.Initialize(new AgentConfig { MasterUrl = "https://v3-mirror.leikwanhost.com" });

        vm.DeferUpdateForRestartForTests(
            version: "0.1.15.0",
            installerPath: @"C:\Users\demo\AppData\Local\LeiKwan Bridge\updates\leikwan-bridge-setup-0.1.15.0.exe",
            sha256: "ABC123");

        Assert.Equal("0.1.15.0", vm.Config.PendingUpdateVersion);
        Assert.Equal("ABC123", vm.Config.PendingUpdateSha256);
        Assert.False(vm.ShowUpdatePrompt);
    }

    [Fact]
    public void ReportNoUpdateAvailable_SetsVisibleStatusMessage()
    {
        var vm = new MainViewModel();

        vm.ReportNoUpdateAvailableForTests("0.1.15.0");

        Assert.Equal("Already up to date (0.1.15.0).", vm.ConnectionStatus);
        Assert.False(vm.ShowUpdatePrompt);
    }

    [Fact]
    public void ReportUpdateCheckFailed_SetsVisibleStatusMessage()
    {
        var vm = new MainViewModel();

        vm.ReportUpdateCheckFailedForTests("manifest unreachable");

        Assert.Equal("Update check failed: manifest unreachable", vm.ConnectionStatus);
        Assert.False(vm.ShowUpdatePrompt);
    }


    [Fact]
    public void ApplyForwardSnapshot_BuildsConnectionQualityOverviewForMptcpWireGuardLegs()
    {
        var vm = new MainViewModel();
        var forward = CreateMptcpForwardWithWireGuardLegs();

        vm.ApplyForwardSnapshot([forward], _ => true);

        var quality = Assert.Single(vm.ConnectionQualityForwards);
        Assert.Equal(47, quality.ForwardId);
        Assert.Equal("Multi-path", quality.ModeDisplay);
        Assert.Equal("Running", quality.StatusText);
        Assert.Contains("1 WG leg", quality.SummaryText);
        var leg = Assert.Single(quality.Legs);
        Assert.Equal("WG slave transit", leg.Kind);
        Assert.Equal("10.203.201.133 -> 10.203.201.134 -> 10.203.201.135", leg.RouteText);
        Assert.Equal("111.231.3.220:7329", leg.RemoteText);
    }

    [Fact]
    public void ApplyForwardSnapshot_BuildsConnectionQualityOverviewForBondHealth()
    {
        var vm = new MainViewModel();
        var stateDir = Path.Combine(Path.GetTempPath(), "leikwan-quality-test-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(stateDir);
        try
        {
            var stateManager = new StateManager(stateDir);
            stateManager.SaveBondEndpointHealth(new Dictionary<string, Dictionary<string, BondEndpointHealthEntry>>
            {
                ["59"] = new()
                {
                    ["457"] = new BondEndpointHealthEntry
                    {
                        Role = "healthy",
                        LastLatencyMs = 12.4,
                        QualityScore = 0.938,
                        ConsecutiveSuccesses = 4,
                    },
                    ["458"] = new BondEndpointHealthEntry
                    {
                        Role = "quarantined",
                        LastError = "probe_failed",
                        QualityScore = 0.0,
                        ConsecutiveFailures = 3,
                    },
                },
            });

            vm.ApplyForwardSnapshot([CreateBondForwardWithEndpoints()], _ => true, stateManager);

            var quality = Assert.Single(vm.ConnectionQualityForwards);
            Assert.Equal(59, quality.ForwardId);
        Assert.Equal("Bond", quality.ModeDisplay);
            Assert.Contains("2 legs", quality.SummaryText);
            Assert.Contains("best 12.4 ms", quality.SummaryText);
            Assert.Equal(2, quality.Legs.Count);
            Assert.Equal("healthy", quality.Legs[0].Role);
            Assert.Equal("12.4 ms", quality.Legs[0].LatencyText);
            Assert.Equal("94%", quality.Legs[0].QualityText);
            Assert.Equal("quarantined", quality.Legs[1].Role);
            Assert.Equal("probe_failed", quality.Legs[1].IssueText);
        }
        finally
        {
            if (Directory.Exists(stateDir))
                Directory.Delete(stateDir, recursive: true);
        }
    }

    [Fact]
    public void ApplyForwardSnapshot_SelectsFirstForwardAndShowsAll()
    {
        var vm = new MainViewModel();

        vm.ApplyForwardSnapshot([CreateForward(24, "mptcp"), CreateForward(26, "bond")], _ => false);

        Assert.Equal(2, vm.Forwards.Count);
        Assert.Equal(2, vm.ForwardCount);
        Assert.NotNull(vm.SelectedForward);
        Assert.Equal(24, vm.SelectedForward!.ForwardId);
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

        var target = vm.Forwards.Last();
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


    private static ForwardConfig CreateMptcpForwardWithWireGuardLegs()
    {
        return new ForwardConfig
        {
            ForwardId = 47,
            Mode = "mptcp",
            ListenPort = 11047,
            Target = "193.227.109.82:5201",
            Transport = "tcp",
            Endpoints =
            [
                new EndpointConfig
                {
                    EndpointId = 457,
                    Host = "111.231.3.220",
                    Port = 7329,
                },
            ],
            Mptcp = new MptcpConfig
            {
                Enabled = true,
                SidecarHost = "10.203.201.135",
                SidecarPort = 8898,
                Underlays =
                [
                    new UnderlayConfig
                    {
                        Type = "wireguard",
                        Mode = "slave_transit",
                        Legs =
                        [
                            new UnderlayLeg
                            {
                                EndpointId = 457,
                                LocalAddress = "10.203.201.133",
                                PeerAddress = "10.203.201.134",
                                MptcpRemoteAddress = "10.203.201.135",
                                PeerPublicHost = "111.231.3.220",
                                PeerUdpPort = 7329,
                            },
                        ],
                    },
                ],
            },
        };
    }

    private static ForwardConfig CreateBondForwardWithEndpoints()
    {
        return new ForwardConfig
        {
            ForwardId = 59,
            Mode = "bond",
            ListenPort = 11059,
            Target = "193.227.109.82:5201",
            Transport = "tcp+udp",
            Endpoints =
            [
                new EndpointConfig
                {
                    EndpointId = 457,
                    Host = "111.231.3.220",
                    Port = 7329,
                    HealthRole = "healthy",
                },
                new EndpointConfig
                {
                    EndpointId = 458,
                    Host = "111.231.3.221",
                    Port = 7330,
                    HealthRole = "healthy",
                },
            ],
        };
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
