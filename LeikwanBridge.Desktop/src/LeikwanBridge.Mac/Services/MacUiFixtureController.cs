using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;

namespace LeikwanBridge.Mac.Services;

public sealed class MacUiFixtureController : IDesktopClientController
{
    public MacUiFixtureController(string state)
    {
        Snapshot = string.Equals(state, "login", StringComparison.Ordinal)
            ? new DesktopClientSnapshot
            {
                PanelUrl = "https://panel.example.test",
                ConnectionStatus = "disconnected",
            }
            : new DesktopClientSnapshot
            {
                IsSignedIn = true,
                IsConnected = true,
                PanelUrl = "https://panel.example.test",
                SignedInUsername = "macOS QA",
                SignedInEmail = "qa@example.test",
                ActiveClientName = "Mac fixture client",
                ConnectionStatus = "connected",
                Forwards = string.Equals(state, "dashboard", StringComparison.Ordinal)
                    ? []
                    :
                    [
                        new DesktopForwardSnapshot(101, "Forward 101", "single", 12101, true, "running"),
                        new DesktopForwardSnapshot(102, "Forward 102", "bond", 12102, false, "stopped"),
                        new DesktopForwardSnapshot(103, "Forward 103", "ultra", 12103, true, "running"),
                    ],
            };
    }

    public event Action<DesktopClientSnapshot>? SnapshotChanged { add { } remove { } }
    public event Action<IReadOnlyList<DesktopForwardSnapshot>>? ForwardsChanged { add { } remove { } }
    public event Action<NativeOutboundCapability?>? NativeOutboundChanged { add { } remove { } }
    public event Action<string>? NoticeRaised { add { } remove { } }
    public event Action<string>? LogRaised { add { } remove { } }
    public event Action<string>? UpdateAvailable { add { } remove { } }

    public DesktopClientSnapshot Snapshot { get; }
    public NativeOutboundCapability? NativeOutbound => Snapshot.NativeOutbound;

    public Task LoginAsync(string panelUrl, string email, string password, CancellationToken ct = default)
        => Task.CompletedTask;

    public Task LoginWithAgentTokenAsync(string panelUrl, int clientId, string agentToken, CancellationToken ct = default)
        => Task.CompletedTask;

    public Task SelectClientAsync(int clientId, CancellationToken ct = default) => Task.CompletedTask;
    public Task ConnectAsync(CancellationToken ct = default) => Task.CompletedTask;
    public Task RefreshAsync(CancellationToken ct = default) => Task.CompletedTask;
    public Task DisconnectAsync(CancellationToken ct = default) => Task.CompletedTask;
    public Task StartForwardAsync(int forwardId, CancellationToken ct = default) => Task.CompletedTask;
    public Task StopForwardAsync(int forwardId, CancellationToken ct = default) => Task.CompletedTask;
    public Task StartAllAsync(CancellationToken ct = default) => Task.CompletedTask;
    public Task StopAllAsync(CancellationToken ct = default) => Task.CompletedTask;
    public Task<NativeOutboundLease?> ResolveNativeOutboundLeaseAsync(int forwardId, string routeScope, CancellationToken ct = default)
        => Task.FromResult<NativeOutboundLease?>(null);
    public Task SignOutAsync(CancellationToken ct = default) => Task.CompletedTask;
    public ValueTask DisposeAsync() => ValueTask.CompletedTask;
}
