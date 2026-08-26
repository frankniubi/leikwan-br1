namespace LeikwanBridge.Core.Services.Platform;

public interface IClientPlatformPaths
{
    string ConfigPath { get; }
    string StateDirectory { get; }
    string RuntimeDirectory { get; }
    string LogDirectory { get; }
    string BundleDirectory { get; }
    string BinDirectory { get; }
}
