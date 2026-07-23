using System.Net;

namespace LeikwanBridge.Core.Services;

public interface ILeikwanDnsResolver
{
    Task<IReadOnlyList<IPAddress>> ResolveAAsync(string host, CancellationToken ct = default);
}
