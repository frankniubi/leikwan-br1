using System.Security.Cryptography;

namespace LeikwanBridge.Mac.Services;

public sealed record MacNativeRuleSetPaths(string GeoIpPath, string GeoSitePath);

public static class MacNativeRuleSetStore
{
    public const string GeoIpSha256 = "ebee603fdf402314b44b9f653cdcf6d9cc9c41e84e2b3515e3123c5c920a93bc";
    public const string GeoSiteSha256 = "7e5edaa3156918ebb732629f7b4ab3f03040b9940456438f976c2046033fc15c";

    public static async Task<MacNativeRuleSetPaths> EnsureAsync(string directory, CancellationToken ct = default)
    {
        directory = Path.GetFullPath(directory);
        Directory.CreateDirectory(directory);
        if (!OperatingSystem.IsWindows())
            File.SetUnixFileMode(directory, UnixFileMode.UserRead | UnixFileMode.UserWrite | UnixFileMode.UserExecute);
        var ip = await ExtractAsync(directory, "geoip-cn", GeoIpSha256, ct).ConfigureAwait(false);
        var site = await ExtractAsync(directory, "geosite-cn", GeoSiteSha256, ct).ConfigureAwait(false);
        return new MacNativeRuleSetPaths(ip, site);
    }

    private static async Task<string> ExtractAsync(string directory, string name, string hash, CancellationToken ct)
    {
        var path = Path.Combine(directory, $"{name}-{hash[..16]}.srs");
        if (File.Exists(path))
        {
            using var existing = File.OpenRead(path);
            if (Convert.ToHexString(await SHA256.HashDataAsync(existing, ct).ConfigureAwait(false)).Equals(hash, StringComparison.OrdinalIgnoreCase))
                return path;
        }
        using var resource = typeof(MacNativeRuleSetStore).Assembly.GetManifestResourceStream($"LeikwanBridge.Mac.RuleSets.{name}.srs")
            ?? throw new InvalidOperationException("connection_rule_set_resource_missing");
        using var buffer = new MemoryStream();
        await resource.CopyToAsync(buffer, ct).ConfigureAwait(false);
        var bytes = buffer.ToArray();
        if (!Convert.ToHexString(SHA256.HashData(bytes)).Equals(hash, StringComparison.OrdinalIgnoreCase))
            throw new InvalidOperationException("connection_rule_set_integrity_failed");
        var temporary = path + "." + Guid.NewGuid().ToString("N") + ".tmp";
        try
        {
            var options = new FileStreamOptions { Mode = FileMode.CreateNew, Access = FileAccess.Write };
            if (!OperatingSystem.IsWindows()) options.UnixCreateMode = UnixFileMode.UserRead | UnixFileMode.UserWrite;
            await using (var output = new FileStream(temporary, options))
            {
                await output.WriteAsync(bytes, ct).ConfigureAwait(false);
                await output.FlushAsync(ct).ConfigureAwait(false);
            }
            File.Move(temporary, path, overwrite: true);
            return path;
        }
        finally { File.Delete(temporary); }
    }
}
