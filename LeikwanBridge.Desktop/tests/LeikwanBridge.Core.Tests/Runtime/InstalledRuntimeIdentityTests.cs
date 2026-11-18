using System.Security.Cryptography;
using LeikwanBridge.Core.Services.Ultra;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class InstalledRuntimeIdentityTests
{
    private const string Fingerprint = "2026.11.13.1.1ed417036c60";

    [Fact]
    public void Read_ReportsVersionStampAndFileHash()
    {
        using var temp = new TempStateDirectory();
        var binary = WriteBinary(temp.Path, "runtime-bytes");
        var identity = new InstalledRuntimeIdentity(() => binary, (_, _) => Fingerprint + "\n");

        var result = identity.Read();

        Assert.NotNull(result);
        Assert.Equal(Fingerprint, result!.Fingerprint);
        Assert.Equal(Sha256(binary), result.SelfSha256);
        Assert.Null(result.Debugger);
        Assert.Null(result.Instrumented);
    }

    [Fact]
    public void Read_RunsTheBinaryOncePerFileVersion()
    {
        using var temp = new TempStateDirectory();
        var binary = WriteBinary(temp.Path, "runtime-bytes");
        var runs = 0;
        var identity = new InstalledRuntimeIdentity(() => binary, (_, _) =>
        {
            runs++;
            return runs == 1 ? Fingerprint : "2026.11.19.1.abcdef1";
        });

        Assert.Equal(Fingerprint, identity.Read()!.Fingerprint);
        Assert.Equal(Fingerprint, identity.Read()!.Fingerprint);
        Assert.Equal(1, runs);

        // An update replaces the binary: the new one is read, not the cached identity.
        File.WriteAllText(binary, "replaced-runtime-bytes");
        File.SetLastWriteTimeUtc(binary, DateTime.UtcNow.AddMinutes(1));
        var replaced = identity.Read();
        Assert.Equal("2026.11.19.1.abcdef1", replaced!.Fingerprint);
        Assert.Equal(Sha256(binary), replaced.SelfSha256);
        Assert.Equal(2, runs);
    }

    [Theory]
    [InlineData(null)]
    [InlineData("")]
    [InlineData("ultra-client version unknown")]
    [InlineData("2026.11.13")]
    public void Read_RefusesAnUnusableVersionStamp(string? output)
    {
        using var temp = new TempStateDirectory();
        var binary = WriteBinary(temp.Path, "runtime-bytes");
        var identity = new InstalledRuntimeIdentity(() => binary, (_, _) => output);

        Assert.Null(identity.Read());
    }

    [Fact]
    public void Read_RetriesAFailedVersionReadOnlyAfterTheRetryInterval()
    {
        using var temp = new TempStateDirectory();
        var binary = WriteBinary(temp.Path, "runtime-bytes");
        var now = DateTimeOffset.UtcNow;
        var runs = 0;
        var identity = new InstalledRuntimeIdentity(
            () => binary,
            (_, _) => ++runs == 1 ? null : Fingerprint,
            () => now);

        Assert.Null(identity.Read());
        Assert.Null(identity.Read());
        Assert.Equal(1, runs);

        now = now.AddMinutes(11);
        Assert.Equal(Fingerprint, identity.Read()!.Fingerprint);
        Assert.Equal(2, runs);
    }

    [Fact]
    public void Read_ReturnsNullForAMissingBinaryOrAFailingResolver()
    {
        using var temp = new TempStateDirectory();
        var runs = 0;
        var missing = new InstalledRuntimeIdentity(
            () => Path.Combine(temp.Path, "missing", "ultra-client"),
            (_, _) => { runs++; return Fingerprint; });
        var blank = new InstalledRuntimeIdentity(() => " ", (_, _) => { runs++; return Fingerprint; });
        var failing = new InstalledRuntimeIdentity(
            () => throw new InvalidOperationException("no config"),
            (_, _) => { runs++; return Fingerprint; });

        Assert.Null(missing.Read());
        Assert.Null(blank.Read());
        Assert.Null(failing.Read());
        Assert.Equal(0, runs);
    }

    [Fact]
    public void Read_ReturnsACopyCallersCannotPoison()
    {
        using var temp = new TempStateDirectory();
        var binary = WriteBinary(temp.Path, "runtime-bytes");
        var identity = new InstalledRuntimeIdentity(() => binary, (_, _) => Fingerprint);

        identity.Read()!.Fingerprint = "tampered";

        Assert.Equal(Fingerprint, identity.Read()!.Fingerprint);
    }

    [Fact]
    public void Read_RunsTheRealBinaryWithVersionFlag()
    {
        using var temp = new TempStateDirectory();
        var binary = WriteVersionScript(temp.Path, Fingerprint, exitCode: 0);
        var identity = new InstalledRuntimeIdentity(() => binary);

        var result = identity.Read();

        Assert.NotNull(result);
        Assert.Equal(Fingerprint, result!.Fingerprint);
        Assert.Equal(Sha256(binary), result.SelfSha256);
    }

    [Fact]
    public void Read_IgnoresABinaryThatFailsItsVersionCommand()
    {
        using var temp = new TempStateDirectory();
        var binary = WriteVersionScript(temp.Path, Fingerprint, exitCode: 3);
        var identity = new InstalledRuntimeIdentity(() => binary);

        Assert.Null(identity.Read());
    }

    private static string WriteBinary(string directory, string content)
    {
        var path = Path.Combine(directory, "ultra-client");
        File.WriteAllText(path, content);
        return path;
    }

    /// <summary>
    /// A stand-in for ultra-client that answers <c>--version</c> the way the
    /// real binary does (one line, the build stamp).
    /// </summary>
    private static string WriteVersionScript(string directory, string fingerprint, int exitCode)
    {
        if (OperatingSystem.IsWindows())
        {
            var script = Path.Combine(directory, "ultra-client.cmd");
            File.WriteAllText(script, $"@echo off\r\nif not \"%1\"==\"--version\" exit /b 9\r\necho {fingerprint}\r\nexit /b {exitCode}\r\n");
            return script;
        }
        var path = Path.Combine(directory, "ultra-client");
        File.WriteAllText(path, $"#!/bin/sh\n[ \"$1\" = \"--version\" ] || exit 9\necho {fingerprint}\nexit {exitCode}\n");
        File.SetUnixFileMode(path, UnixFileMode.UserRead | UnixFileMode.UserWrite | UnixFileMode.UserExecute);
        return path;
    }

    private static string Sha256(string path)
        => Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(path))).ToLowerInvariant();
}
