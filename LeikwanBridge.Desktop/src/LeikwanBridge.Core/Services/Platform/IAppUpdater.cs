using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services.Platform;

public interface IAppUpdater
{
    Task<ClientAppArtifact?> CheckAsync(string panelUrl, string currentVersion, CancellationToken ct = default);
    Task<PreparedAppUpdate> PrepareAsync(ClientAppArtifact artifact, CancellationToken ct = default);
    Task<AppUpdateLaunchResult> ApplyAsync(
        PreparedAppUpdate update,
        bool deferUntilRestart,
        CancellationToken ct = default);
}
