using System.Diagnostics;
using System.Security.Cryptography;
using System.Text.Json;

namespace LeikwanBridge.Core.Services;

public static class UotRuntimeCapabilityProbe
{
    private const int MaxOutputBytes = 64 * 1024;

    public static string BuildIdentity(string executablePath)
    {
        if (!File.Exists(executablePath))
            return string.Empty;

        var sha256 = Convert.ToHexString(SHA256.HashData(File.ReadAllBytes(executablePath)))
            .ToLowerInvariant();
        try
        {
            var buildInfo = Probe(executablePath);
            return $"{sha256}:{JsonSerializer.Serialize(buildInfo)}";
        }
        catch
        {
            return $"{sha256}:probe_failed";
        }
    }

    public static void RequireFeedbackCapability(string executablePath)
    {
        var buildInfo = Probe(executablePath);
        if (buildInfo.ProtocolVersion != 2
            || !buildInfo.SchedulerModes.Contains("feedback_drr", StringComparer.Ordinal)
            || !buildInfo.Capabilities.Contains("leg-progress-v1", StringComparer.Ordinal))
        {
            throw new InvalidOperationException(
                "native UoT runtime lacks feedback_drr/leg-progress-v1");
        }
    }

    private static BuildInfo Probe(string executablePath)
    {
        if (!File.Exists(executablePath))
            throw new InvalidOperationException("native UoT runtime capability probe failed");

        using var process = new Process
        {
            StartInfo = new ProcessStartInfo
            {
                FileName = executablePath,
                UseShellExecute = false,
                CreateNoWindow = true,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
            },
        };
        process.StartInfo.ArgumentList.Add("--build-info-json");
        if (!process.Start())
            throw new InvalidOperationException("native UoT runtime capability probe failed");

        var stdoutTask = process.StandardOutput.ReadToEndAsync();
        var stderrTask = process.StandardError.ReadToEndAsync();
        if (!process.WaitForExit(2_000))
        {
            try { process.Kill(entireProcessTree: true); } catch { }
            throw new InvalidOperationException("native UoT runtime capability probe timed out");
        }
        Task.WaitAll(stdoutTask, stderrTask);
        var stdout = stdoutTask.Result;
        var stderr = stderrTask.Result;
        if (stdout.Length > MaxOutputBytes || stderr.Length > MaxOutputBytes || process.ExitCode != 0)
            throw new InvalidOperationException("native UoT runtime capability probe failed");

        try
        {
            return JsonSerializer.Deserialize<BuildInfo>(stdout)
                ?? throw new InvalidOperationException("native UoT runtime capability probe failed");
        }
        catch (JsonException ex)
        {
            throw new InvalidOperationException("native UoT runtime capability probe failed", ex);
        }
    }

    private sealed class BuildInfo
    {
        [System.Text.Json.Serialization.JsonPropertyName("protocol_version")]
        public int ProtocolVersion { get; set; }

        [System.Text.Json.Serialization.JsonPropertyName("capabilities")]
        public List<string> Capabilities { get; set; } = [];

        [System.Text.Json.Serialization.JsonPropertyName("scheduler_modes")]
        public List<string> SchedulerModes { get; set; } = [];
    }
}
