using System.Net;
using System.Net.Sockets;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;

namespace LeikwanBridge.Mac.Services;

internal static class MacUltraLeakFixture
{
    private const int ForwardId = 9042;
    private const int MaximumInputBytes = 8 * 1024;

    public static async Task<int> RunAsync(string[] args, CancellationToken ct = default)
    {
        if (!string.Equals(
                Environment.GetEnvironmentVariable("LEIKWAN_TEST_ULTRA_CANARY"),
                "1",
                StringComparison.Ordinal))
            return 2;
        if (!TryParseArguments(args, out var ultraBinary, out var stateRoot, out var releaseFile))
            return 2;

        byte[] input = [];
        CanaryInput? canary = null;
        MacProcessSupervisor? supervisor = null;
        MacUltraRuntimeHost? host = null;
        try
        {
            input = await ReadBoundedInputAsync(Console.OpenStandardInput(), ct);
            canary = JsonSerializer.Deserialize<CanaryInput>(input);
            if (canary is null || !IsValidCanary(canary))
                return 2;
            if (!File.Exists(ultraBinary))
                return 2;

            Directory.CreateDirectory(stateRoot);
            SetPrivateDirectory(stateRoot);
            var config = new AgentConfig
            {
                StateDir = stateRoot,
                UltraBin = ultraBinary,
            };
            var state = new StateManager(stateRoot);
            supervisor = new MacProcessSupervisor();
            host = new MacUltraRuntimeHost(
                config,
                supervisor,
                state,
                portListeningProbe: CanConnectToListener);
            var listenPort = ReserveTcpPort();
            var generation = new string('a', 64);
            var targetDigest = UltraPolicy.ComputeTargetDigest(canary.Target);
            var forward = CreateForward(canary.Target, listenPort, generation, targetDigest);

            host.Start(
                forward,
                CreateLease(canary, generation, targetDigest, 'b'),
                "canary-contract",
                "canary_fixture");
            if (!host.Refresh(forward, CreateLease(canary, generation, targetDigest, 'c')))
            {
                Console.Out.WriteLine("FAILED refresh");
                return 1;
            }

            Console.Out.WriteLine("READY");
            Console.Out.Flush();
            // The gate scans the app bundle, the update ZIP and the mounted DMG
            // before it releases the fixture; on a hosted Intel runner that took
            // longer than the previous 60 s.
            var deadline = DateTime.UtcNow.AddMinutes(10);
            while (!File.Exists(releaseFile) && DateTime.UtcNow < deadline)
                await Task.Delay(50, ct);
            if (!File.Exists(releaseFile))
            {
                Console.Out.WriteLine("FAILED release_timeout");
                return 1;
            }
            return 0;
        }
        catch (Exception error)
        {
            // The type only: a message could carry the canary material.
            Console.Out.WriteLine($"FAILED {error.GetType().Name}");
            return 1;
        }
        finally
        {
            host?.Stop(ForwardId);
            supervisor?.Dispose();
            CryptographicOperations.ZeroMemory(input);
            if (canary is not null)
                canary.Clear();
            Console.Out.WriteLine("DONE");
            Console.Out.Flush();
        }
    }

    private static ForwardConfig CreateForward(
        string target,
        int listenPort,
        string generation,
        string targetDigest)
    {
        return new ForwardConfig
        {
            ForwardId = ForwardId,
            BackendMode = "ultra",
            Mode = "single",
            ClientMode = "single",
            ListenPort = listenPort,
            Target = target,
            Transport = "tcp",
            UltraAccess = new UltraPolicy
            {
                ProtocolValue = UltraPolicy.Protocol,
                ForwardId = ForwardId,
                PolicyGeneration = generation,
                SessionTicket = "canary-session-ticket",
                ExpiresAt = DateTimeOffset.UtcNow.AddMinutes(4),
                ServiceState = "active",
                Transport = "tcp",
                QuotaRemainingBytes = 1024 * 1024,
                TargetDigest = targetDigest,
            },
        };
    }

    private static UltraLease CreateLease(
        CanaryInput canary,
        string generation,
        string targetDigest,
        char nonceCharacter)
    {
        return new UltraLease
        {
            LeaseId = "ul2_canary_fixture",
            SessionTicket = "canary-session-ticket",
            ForwardId = ForwardId,
            PolicyGeneration = generation,
            TargetDigest = targetDigest,
            ExpiresAt = DateTimeOffset.UtcNow.AddMinutes(3),
            Transport = "tcp",
            RateLimitMbps = 20,
            QuotaRemainingBytes = 1024 * 1024,
            SelectionRef = "canary-selection-reference-0001",
            NonceHash = new string(nonceCharacter, 64),
            ProviderIp = canary.ProviderIp,
            ProviderPort = 5438,
            User = Encoding.UTF8.GetBytes(canary.User),
            Password = Encoding.UTF8.GetBytes(canary.Password),
        };
    }

    private static bool TryParseArguments(
        IReadOnlyList<string> args,
        out string ultraBinary,
        out string stateRoot,
        out string releaseFile)
    {
        ultraBinary = string.Empty;
        stateRoot = string.Empty;
        releaseFile = string.Empty;
        for (var index = 0; index + 1 < args.Count; index += 2)
        {
            var value = args[index + 1];
            switch (args[index])
            {
                case "--ultra-bin":
                    ultraBinary = value;
                    break;
                case "--state-root":
                    stateRoot = value;
                    break;
                case "--release-file":
                    releaseFile = value;
                    break;
                default:
                    return false;
            }
        }
        if (args.Count != 6
            || !Path.IsPathFullyQualified(ultraBinary)
            || !Path.IsPathFullyQualified(stateRoot)
            || !Path.IsPathFullyQualified(releaseFile))
            return false;
        var state = Path.GetFullPath(stateRoot);
        var release = Path.GetFullPath(releaseFile);
        var prefix = state.TrimEnd(Path.DirectorySeparatorChar, Path.AltDirectorySeparatorChar)
            + Path.DirectorySeparatorChar;
        return release.StartsWith(prefix, StringComparison.Ordinal);
    }

    private static bool IsValidCanary(CanaryInput canary)
    {
        if (!TryReservedAddress(canary.ProviderIp))
            return false;
        if (!TrySplitTarget(canary.Target, out var host) || !TryReservedAddress(host))
            return false;
        return canary.User.StartsWith("lk_canary_", StringComparison.Ordinal)
            && canary.Password.StartsWith("lk_secret_", StringComparison.Ordinal)
            && canary.User.Length <= 128
            && canary.Password.Length <= 128;
    }

    private static bool TryReservedAddress(string value)
    {
        if (!IPAddress.TryParse(value, out var parsed))
            return false;
        var bytes = parsed.MapToIPv4().GetAddressBytes();
        return bytes is [192, 0, 2, _]
            or [198, 51, 100, _]
            or [203, 0, 113, _];
    }

    private static bool TrySplitTarget(string target, out string host)
    {
        host = string.Empty;
        var separator = target.LastIndexOf(':');
        return separator > 0
            && int.TryParse(target[(separator + 1)..], out var port)
            && port is > 0 and <= 65535
            && (host = target[..separator]).Length > 0;
    }

    private static int ReserveTcpPort()
    {
        var listener = new TcpListener(IPAddress.Loopback, 0);
        listener.Start();
        try
        {
            return ((IPEndPoint)listener.LocalEndpoint).Port;
        }
        finally
        {
            listener.Stop();
        }
    }

    private static bool CanConnectToListener(int port)
    {
        try
        {
            using var client = new TcpClient();
            return client.ConnectAsync(IPAddress.Loopback, port).Wait(TimeSpan.FromMilliseconds(100))
                && client.Connected;
        }
        catch
        {
            return false;
        }
    }

    private static async Task<byte[]> ReadBoundedInputAsync(Stream stream, CancellationToken ct)
    {
        using var output = new MemoryStream();
        var buffer = new byte[1024];
        try
        {
            while (true)
            {
                var read = await stream.ReadAsync(buffer, ct);
                if (read == 0)
                    break;
                if (output.Length + read > MaximumInputBytes)
                    throw new InvalidDataException("canary_input_too_large");
                output.Write(buffer, 0, read);
            }
            return output.ToArray();
        }
        finally
        {
            CryptographicOperations.ZeroMemory(buffer);
        }
    }

    private static void SetPrivateDirectory(string path)
    {
        if (!OperatingSystem.IsWindows())
        {
            File.SetUnixFileMode(
                path,
                UnixFileMode.UserRead | UnixFileMode.UserWrite | UnixFileMode.UserExecute);
        }
    }

    private sealed class CanaryInput
    {
        public string Target { get; set; } = string.Empty;
        public string ProviderIp { get; set; } = string.Empty;
        public string User { get; set; } = string.Empty;
        public string Password { get; set; } = string.Empty;

        public void Clear()
        {
            Target = string.Empty;
            ProviderIp = string.Empty;
            User = string.Empty;
            Password = string.Empty;
        }
    }
}
