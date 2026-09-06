using System.Diagnostics;
using System.Text;
using System.Text.Json;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services.Ultra;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class NativeOutboundRelayHostTests
{
    [Fact]
    public async Task StartCandidates_UsesShortPrivateUnixControlPath()
    {
        using var temp = new TempStateDirectory();
        var socketRoot = Path.Combine(Path.GetPathRoot(temp.Path)!, $"lk-{Guid.NewGuid():N}"[..11]);
        Directory.CreateDirectory(socketRoot);
        var binary = Path.Combine(temp.Path, "ultra-client");
        File.WriteAllText(binary, "fixture");
        string? controlPath = null;
        string? statusPath = null;
        Process? process = null;
        var processId = 0;
        var host = new NativeOutboundRelayHost(
            windows: false,
            unixSocketRoot: socketRoot,
            processStarter: info =>
            {
                var configPath = info.ArgumentList[1];
                using var document = JsonDocument.Parse(File.ReadAllBytes(configPath));
                controlPath = document.RootElement.GetProperty("control_socket").GetString();
                statusPath = document.RootElement.GetProperty("status_path").GetString();
                File.WriteAllText(controlPath!, "socket-fixture");
                process = Process.Start(new ProcessStartInfo
                {
                    FileName = OperatingSystem.IsWindows() ? "powershell.exe" : "/bin/sleep",
                    Arguments = OperatingSystem.IsWindows() ? "-NoProfile -Command Start-Sleep -Seconds 30" : "30",
                    UseShellExecute = false,
                    CreateNoWindow = true,
                    RedirectStandardOutput = true,
                    RedirectStandardError = true,
                });
                processId = process!.Id;
                return process!;
            },
            unixSender: (_, _, _, _) => Task.FromResult(true),
            portProbe: (_, _) => Task.FromResult(true));
        try
        {
            var lease = CreateLease();
            var endpoint = await host.StartAsync(42, binary, 34567, lease, temp.Path);
            Assert.Equal("127.0.0.1", endpoint.Host);
            Assert.NotNull(controlPath);
            Assert.True(Encoding.UTF8.GetByteCount(controlPath!) < 100, controlPath);
            Assert.StartsWith(socketRoot, controlPath, StringComparison.Ordinal);
            Assert.True(File.Exists(controlPath));
            Assert.NotNull(statusPath);
            Assert.Contains("native-usage", statusPath!, StringComparison.Ordinal);
            Assert.Contains($"{Path.DirectorySeparatorChar}native-usage{Path.DirectorySeparatorChar}", statusPath!, StringComparison.OrdinalIgnoreCase);
        }
        finally
        {
            await host.StopAsync();
            Assert.True(processId > 0);
            Assert.ThrowsAny<Exception>(() => Process.GetProcessById(processId));
            Assert.False(controlPath is not null && File.Exists(controlPath));
            Assert.True(statusPath is not null && Directory.Exists(Path.GetDirectoryName(statusPath!)));
            await host.DisposeAsync();
        }
    }

    private static NativeOutboundLease CreateLease()
    {
        var digest = NativeOutboundLease.ComputeScopeDigest(42, "Mainland China -> International", "tcp+udp", "global");
        return new NativeOutboundLease(
            42, "native-lease-test", new string('a', 64), DateTimeOffset.UtcNow.AddMinutes(2),
            "tcp+udp", "Mainland China -> International", "global", digest, "203.0.113.20", 5438,
            "user"u8.ToArray(), "password"u8.ToArray(), rateLimitMbps: 20, quotaRemainingBytes: 1024,
            selectionRef: "selection-reference-test", selectionTier: UltraLease.SelectionTierPaid);
    }
}
