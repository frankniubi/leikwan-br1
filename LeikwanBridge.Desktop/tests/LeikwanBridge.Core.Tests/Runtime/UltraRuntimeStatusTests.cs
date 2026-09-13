using LeikwanBridge.Core.Services.Ultra;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

public sealed class UltraRuntimeStatusTests
{
    [Fact]
    public void ReadsCeilingOversizeDropsAndAttestationWhenTheRuntimePublishedThem()
    {
        using var temp = new TempStateDirectory();
        var statusPath = Path.Combine(temp.Path, "status.json");
        File.WriteAllText(statusPath, $$"""
        {
          "forward_id": 180,
          "udp_payload_ceiling": 1440,
          "udp_oversize_drops": 12,
          "runtime_attestation": {
            "fingerprint": "0f3a91c2",
            "self_sha256": "{{new string('0', 64)}}",
            "debugger": false,
            "instrumented": true
          }
        }
        """);

        Assert.Equal(1440, UltraRuntimeStatus.ReadPayloadCeiling(statusPath));
        Assert.Equal(12, UltraRuntimeStatus.ReadOversizeDrops(statusPath));
        var attestation = UltraRuntimeStatus.ReadAttestation(statusPath);
        Assert.NotNull(attestation);
        Assert.Equal("0f3a91c2", attestation.Fingerprint);
        Assert.Equal(new string('0', 64), attestation.SelfSha256);
        Assert.False(attestation.Debugger);
        Assert.True(attestation.Instrumented);
    }

    [Fact]
    public void DevelopmentFingerprintIsAcceptedWhileItsOptionalFieldsStayAbsent()
    {
        using var temp = new TempStateDirectory();
        var statusPath = Path.Combine(temp.Path, "status.json");
        File.WriteAllText(statusPath, """
        {
          "forward_id": 180,
          "runtime_attestation": { "fingerprint": "development" }
        }
        """);

        var attestation = UltraRuntimeStatus.ReadAttestation(statusPath);

        Assert.NotNull(attestation);
        Assert.Equal("development", attestation.Fingerprint);
        Assert.Null(attestation.SelfSha256);
        Assert.Null(attestation.Debugger);
        Assert.Null(attestation.Instrumented);
    }

    [Fact]
    public void ReleasedVersionFingerprintIsAccepted()
    {
        // The build scripts inject main.runtimeBuildVersion=<version>.<shortsha>,
        // which is what every shipped runtime reports.
        using var temp = new TempStateDirectory();
        var statusPath = Path.Combine(temp.Path, "status.json");
        File.WriteAllText(statusPath, $$"""
        {
          "forward_id": 180,
          "runtime_attestation": {
            "fingerprint": "2026.09.12.1.4b106b02",
            "self_sha256": "{{new string('a', 64)}}",
            "debugger": false,
            "instrumented": false
          }
        }
        """);

        var attestation = UltraRuntimeStatus.ReadAttestation(statusPath);

        Assert.NotNull(attestation);
        Assert.Equal("2026.09.12.1.4b106b02", attestation.Fingerprint);
        Assert.Equal(new string('a', 64), attestation.SelfSha256);
    }

    [Theory]
    [InlineData("2026.09.12.1.4B106B02")]   // fingerprints are lowercased at build time
    [InlineData("2026.09.12.1.zz")]         // the short sha must be hex
    [InlineData("2026.09.12.1.4b106b")]     // 6 hex characters is too short (released short shas are 7+)
    [InlineData("not-a-fingerprint")]
    [InlineData("0f3a91c")]                 // hex build ids are 8..64 characters
    public void MalformedFingerprintsAreRejected(string fingerprint)
    {
        using var temp = new TempStateDirectory();
        var statusPath = Path.Combine(temp.Path, "status.json");
        File.WriteAllText(statusPath, $$"""
        { "forward_id": 180, "runtime_attestation": { "fingerprint": "{{fingerprint}}" } }
        """);

        Assert.Null(UltraRuntimeStatus.ReadAttestation(statusPath));
    }

    [Theory]
    [InlineData("""{"forward_id": 180}""")]
    [InlineData("""{"forward_id": 180, "udp_payload_ceiling": 0, "udp_oversize_drops": 0}""")]
    [InlineData("""{"forward_id": 180, "udp_payload_ceiling": -1440, "udp_oversize_drops": -1}""")]
    [InlineData("""{"forward_id": 180, "udp_payload_ceiling": "1440", "udp_oversize_drops": "12"}""")]
    [InlineData("""{"forward_id": 180, "udp_payload_ceiling": 1440.5, "udp_oversize_drops": 12.5}""")]
    [InlineData("not json at all")]
    public void AbsentOrUnusableRuntimeStatusReadsAsNotMeasured(string statusJson)
    {
        using var temp = new TempStateDirectory();
        var statusPath = Path.Combine(temp.Path, "status.json");
        File.WriteAllText(statusPath, statusJson);

        Assert.Equal(0, UltraRuntimeStatus.ReadPayloadCeiling(statusPath));
        Assert.Equal(0, UltraRuntimeStatus.ReadOversizeDrops(statusPath));
        Assert.Null(UltraRuntimeStatus.ReadAttestation(statusPath));
    }

    [Fact]
    public void MissingOrOversizedStatusReadsAsNotMeasured()
    {
        using var temp = new TempStateDirectory();
        var missing = Path.Combine(temp.Path, "absent.json");
        var oversized = Path.Combine(temp.Path, "oversized.json");
        File.WriteAllText(oversized, $$"""{"forward_id": 180, "udp_payload_ceiling": 1440, "padding": "{{new string('x', 70 * 1024)}}"}""");

        Assert.Equal(0, UltraRuntimeStatus.ReadPayloadCeiling(missing));
        Assert.Equal(0, UltraRuntimeStatus.ReadPayloadCeiling(oversized));
        Assert.Null(UltraRuntimeStatus.ReadAttestation(oversized));
    }

    [Theory]
    [InlineData("""{}""")]
    [InlineData("""{"fingerprint": ""}""")]
    [InlineData("""{"fingerprint": "0F3A91C2"}""")]
    [InlineData("""{"fingerprint": "0f3a91c2", "self_sha256": "abcd"}""")]
    [InlineData("""{"fingerprint": "0f3a91c2", "debugger": "yes"}""")]
    [InlineData("""{"fingerprint": "0f3a91c2", "instrumented": 1}""")]
    [InlineData("""["fingerprint"]""")]
    public void MalformedAttestationIsDroppedAsAWhole(string attestationJson)
    {
        using var temp = new TempStateDirectory();
        var statusPath = Path.Combine(temp.Path, "status.json");
        File.WriteAllText(statusPath, $$"""{"forward_id": 180, "udp_payload_ceiling": 1440, "runtime_attestation": {{attestationJson}}}""");

        Assert.Null(UltraRuntimeStatus.ReadAttestation(statusPath));
        // A dropped identity must not take the ceiling it travelled with down.
        Assert.Equal(1440, UltraRuntimeStatus.ReadPayloadCeiling(statusPath));
    }

    [Fact]
    public void ForwardLookupUsesTheNewestNativeInstanceAndFallsBackToTheOrdinaryStatusFile()
    {
        using var temp = new TempStateDirectory();
        var runtimeRoot = Path.Combine(temp.Path, "proxy-runtime");
        var older = Path.Combine(runtimeRoot, "native-usage", "native-180-stopped", "status.json");
        var newer = Path.Combine(runtimeRoot, "native-usage", "native-180-active", "status.json");
        WriteStatus(older, 180, 1400);
        File.SetLastWriteTimeUtc(older, DateTime.UtcNow.AddMinutes(-5));
        WriteStatus(newer, 180, 1440);

        Assert.Equal(1440, UltraRuntimeStatus.ReadForwardPayloadCeiling(runtimeRoot, 180));
        // Another forward's instance directory is not this forward's status.
        Assert.Equal(0, UltraRuntimeStatus.ReadForwardPayloadCeiling(runtimeRoot, 181));
        Assert.Equal(0, UltraRuntimeStatus.ReadForwardPayloadCeiling(Path.Combine(temp.Path, "absent"), 180));

        var ordinaryRoot = Path.Combine(temp.Path, "runtime");
        WriteStatus(Path.Combine(ordinaryRoot, "ultra-forward-180.status.json"), 180, 1472);
        Assert.Equal(1472, UltraRuntimeStatus.ReadForwardPayloadCeiling(ordinaryRoot, 180));
    }

    private static void WriteStatus(string path, int forwardId, int ceiling)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(path)!);
        File.WriteAllText(path, $$"""{"forward_id": {{forwardId}}, "udp_payload_ceiling": {{ceiling}}}""");
    }
}
