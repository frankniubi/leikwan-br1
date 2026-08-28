namespace LeikwanBridge.Core.Services.Platform;

public interface IAutostartService
{
    Task<bool> IsEnabledAsync(CancellationToken ct);
    Task SetEnabledAsync(bool enabled, CancellationToken ct);
}
