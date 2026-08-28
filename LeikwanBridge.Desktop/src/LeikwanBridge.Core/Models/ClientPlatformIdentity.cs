namespace LeikwanBridge.Core.Models;

public sealed record ClientPlatformIdentity(
    string Platform,
    string DeviceId,
    string DeviceName,
    string UserAgentProduct,
    string AppVersion,
    bool SupportsMptcp);
