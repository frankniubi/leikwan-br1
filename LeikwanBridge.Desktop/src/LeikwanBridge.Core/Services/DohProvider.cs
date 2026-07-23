using System.Net;

namespace LeikwanBridge.Core.Services;

public sealed record DohProvider(string Name, Uri Endpoint, IReadOnlyList<IPAddress> BootstrapAddresses);
