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
