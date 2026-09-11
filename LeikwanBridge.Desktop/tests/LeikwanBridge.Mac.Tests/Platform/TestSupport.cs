using System.Diagnostics;
using LeikwanBridge.Mac.Services;

namespace LeikwanBridge.Mac.Tests.Platform;

internal sealed class TemporaryDirectory : IDisposable
{
    public TemporaryDirectory()
    {
        Path = System.IO.Path.Combine(System.IO.Path.GetTempPath(), $"lw-mac-platform-{Guid.NewGuid():N}");
        Directory.CreateDirectory(Path);
    }

    public string Path { get; }

    public void Dispose()
    {
        if (Directory.Exists(Path))
            Directory.Delete(Path, recursive: true);
    }
}

internal sealed class FakeCommandRunner(Func<ProcessStartInfo, MacCommandResult> handler) : IMacCommandRunner
{
    public Task<MacCommandResult> RunAsync(ProcessStartInfo startInfo, CancellationToken ct)
    {
        return Task.FromResult(handler(startInfo));
    }
}

internal sealed class FakeKeychainNative : IMacKeychainNative
{
    private readonly Dictionary<string, byte[]> _values = [];
    public bool Available { get; set; } = true;
    public byte[]? LastSetBuffer { get; private set; }
    public int ReadCount { get; private set; }
    public int DeleteCount { get; private set; }
    public List<string> ReadAccounts { get; } = [];
    public List<string> DeletedAccounts { get; } = [];

    public MacKeychainReadResult Read(string service, string account)
    {
        ReadCount++;
        ReadAccounts.Add(account);
        if (!Available)
            return new MacKeychainReadResult(MacKeychainStatus.Unavailable, []);
        return _values.TryGetValue(account, out var value)
            ? new MacKeychainReadResult(MacKeychainStatus.Success, value.ToArray())
            : new MacKeychainReadResult(MacKeychainStatus.NotFound, []);
    }

    public MacKeychainStatus Set(string service, string account, byte[] secret)
    {
        if (!Available)
            return MacKeychainStatus.Unavailable;
        LastSetBuffer = secret;
        _values[account] = secret.ToArray();
        return MacKeychainStatus.Success;
    }

    public MacKeychainStatus Delete(string service, string account)
    {
        DeleteCount++;
        DeletedAccounts.Add(account);
        if (!Available)
            return MacKeychainStatus.Unavailable;
        _values.Remove(account);
        return MacKeychainStatus.Success;
    }
}

internal sealed class BlockingKeychainNative(
    ManualResetEventSlim entered,
    ManualResetEventSlim release) : IMacKeychainNative
{
    public MacKeychainReadResult Read(string service, string account)
    {
        entered.Set();
        release.Wait();
        return new MacKeychainReadResult(MacKeychainStatus.Unavailable, []);
    }

    public MacKeychainStatus Set(string service, string account, byte[] secret)
        => MacKeychainStatus.Unavailable;

    public MacKeychainStatus Delete(string service, string account)
        => MacKeychainStatus.Unavailable;
}
