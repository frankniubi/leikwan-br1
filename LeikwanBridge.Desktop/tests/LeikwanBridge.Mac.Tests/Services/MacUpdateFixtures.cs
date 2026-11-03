using System.Buffers.Binary;
using System.IO.Compression;
using System.Net;
using System.Security.Cryptography;
using System.Text;
using LeikwanBridge.Mac.Services;

namespace LeikwanBridge.Mac.Tests.Services;

/// <summary>Minimal LeiKwan bundles, update zips and a stub panel for the
/// headless self-update tests.</summary>
internal static class MacUpdateFixtures
{
    public const string BundleName = "LeiKwan Bridge.app";

    public static string CreateBundle(string root, string version, string marker, string architecture = "arm64", string name = BundleName)
    {
        var bundle = Path.Combine(root, name);
        var macOs = Path.Combine(bundle, "Contents", "MacOS");
        var helpers = Path.Combine(bundle, "Contents", "Resources", "bin");
        Directory.CreateDirectory(macOs);
        Directory.CreateDirectory(helpers);
        File.WriteAllText(Path.Combine(bundle, "Contents", "Info.plist"), $$"""
        <?xml version="1.0" encoding="UTF-8"?>
        <!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
        <plist version="1.0"><dict>
          <key>CFBundleIdentifier</key><string>com.leikwan.bridge</string>
          <key>CFBundleShortVersionString</key><string>{{version}}</string>
          <key>CFBundleExecutable</key><string>LeikwanBridge.Mac</string>
        </dict></plist>
        """);
        var executable = ThinMachO(architecture);
        File.WriteAllBytes(Path.Combine(macOs, "LeikwanBridge.Mac"), executable);
        File.WriteAllBytes(Path.Combine(helpers, "ultra-client"), executable);
        File.WriteAllText(Path.Combine(bundle, "Contents", "Resources", "marker.txt"), marker);
        File.WriteAllText(Path.Combine(bundle, "Contents", "Resources", "helper-manifest.json"), $$"""
        {
          "architecture": "{{architecture}}",
          "helpers": [
            { "name": "ultra-client", "path": "bin/ultra-client", "sha256": "{{Sha256(executable)}}", "size_bytes": {{executable.Length}} }
          ]
        }
        """);
        return bundle;
    }

    public static string Marker(string bundle)
        => File.ReadAllText(Path.Combine(bundle, "Contents", "Resources", "marker.txt"));

    public static byte[] ZipBundle(string bundle)
    {
        using var stream = new MemoryStream();
        using (var archive = new ZipArchive(stream, ZipArchiveMode.Create, leaveOpen: true))
        {
            var parent = Directory.GetParent(bundle)!.FullName;
            foreach (var file in Directory.EnumerateFiles(bundle, "*", SearchOption.AllDirectories))
            {
                var entry = archive.CreateEntry(Path.GetRelativePath(parent, file).Replace('\\', '/'), CompressionLevel.NoCompression);
                using var source = File.OpenRead(file);
                using var destination = entry.Open();
                source.CopyTo(destination);
            }
        }

        return stream.ToArray();
    }

    public static string Manifest(string version, byte[] package, string? sha = null, long? size = null)
        => $$"""
        {
          "component": "desktop-client-app",
          "manifest": {
            "macos_client_update_arm64": { "version": "{{version}}", "artifact_url": "https://panel.example.test/artifacts/macos/leikwan-bridge-update-arm64.zip", "sha256": "{{sha ?? Sha256(package).ToLowerInvariant()}}", "size_bytes": {{size ?? package.Length}}, "architecture": "arm64", "format": "zip" },
            "macos_client_app_arm64": { "version": "{{version}}", "artifact_url": "https://panel.example.test/artifacts/macos/leikwan-bridge-arm64.dmg", "sha256": "{{new string('A', 64)}}", "size_bytes": 13, "architecture": "arm64", "format": "dmg" }
          }
        }
        """;

    public static HttpClient Panel(Func<string> manifest, Func<byte[]> package)
        => new(new StubHandler(request => request.RequestUri!.AbsolutePath.EndsWith("client-manifest.json", StringComparison.Ordinal)
            ? new HttpResponseMessage(HttpStatusCode.OK) { Content = new StringContent(manifest(), Encoding.UTF8, "application/json") }
            : new HttpResponseMessage(HttpStatusCode.OK) { Content = new ByteArrayContent(package()) }))
        {
            Timeout = TimeSpan.FromSeconds(10),
        };

    public static MacAppUpdateService Updater(string updatesRoot, string installBundle, HttpClient http)
        => new(updatesRoot, installBundle, "arm64", _ => http, ownsHttpClient: false, waitForCallerExit: false);

    public static string Sha256(byte[] bytes) => Convert.ToHexString(SHA256.HashData(bytes));

    private static byte[] ThinMachO(string architecture)
    {
        var bytes = new byte[32];
        bytes[0] = 0xCF;
        bytes[1] = 0xFA;
        bytes[2] = 0xED;
        bytes[3] = 0xFE;
        BinaryPrimitives.WriteInt32LittleEndian(bytes.AsSpan(4), architecture == "arm64" ? 0x0100000C : 0x01000007);
        return bytes;
    }

    private sealed class StubHandler(Func<HttpRequestMessage, HttpResponseMessage> response) : HttpMessageHandler
    {
        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            var result = response(request);
            result.RequestMessage = request;
            return Task.FromResult(result);
        }
    }
}

/// <summary>Managed stand-in for the native bundle operations.</summary>
internal sealed class ManagedBundleFileSystem : IMacBundleFileSystem
{
    public bool SupportsSwap { get; set; } = true;
    public Func<string, string, bool>? FailMove { get; set; }
    public Func<string, bool>? FailClone { get; set; }
    public List<string> Operations { get; } = [];

    public void Clone(string source, string destination)
    {
        Operations.Add("clone");
        if (FailClone?.Invoke(source) == true)
            throw new IOException("clone failed (test)");
        if (Directory.Exists(destination))
            throw new IOException("exists");
        Directory.CreateDirectory(destination);
        foreach (var directory in Directory.EnumerateDirectories(source, "*", SearchOption.AllDirectories))
            Directory.CreateDirectory(Path.Combine(destination, Path.GetRelativePath(source, directory)));
        foreach (var file in Directory.EnumerateFiles(source, "*", SearchOption.AllDirectories))
            File.Copy(file, Path.Combine(destination, Path.GetRelativePath(source, file)));
    }

    public bool TrySwap(string first, string second)
    {
        Operations.Add("swap");
        if (!SupportsSwap)
            return false;
        var temporary = first + ".swap-tmp";
        Directory.Move(first, temporary);
        Directory.Move(second, first);
        Directory.Move(temporary, second);
        return true;
    }

    public void Move(string source, string destination)
    {
        Operations.Add("move");
        if (FailMove?.Invoke(source, destination) == true)
            throw new IOException("move failed (test)");
        Directory.Move(source, destination);
    }

    public void Delete(string path)
    {
        Operations.Add("delete");
        if (Directory.Exists(path))
            Directory.Delete(path, recursive: true);
    }
}

internal sealed class TempRoot : IDisposable
{
    public TempRoot()
    {
        Path = System.IO.Path.Combine(System.IO.Path.GetTempPath(), "lkb-mac-selfupdate", Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(Path);
    }

    public string Path { get; }

    public string Sub(params string[] parts) => System.IO.Path.Combine([Path, .. parts]);

    public void Dispose()
    {
        try
        {
            Directory.Delete(Path, recursive: true);
        }
        catch (Exception exception) when (exception is IOException or UnauthorizedAccessException)
        {
        }
    }
}
