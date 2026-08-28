namespace LeikwanBridge.Core.Services.Platform;

public interface ISecretStore
{
    Task<string?> GetAsync(string name, CancellationToken ct);
    Task SetAsync(string name, string value, CancellationToken ct);
    Task DeleteAsync(string name, CancellationToken ct);
}
