using System.Text.Json;
using System.ComponentModel;
using System.Security.Cryptography;
using LeikwanBridge.Core.Models;

namespace LeikwanBridge.Core.Services;

public sealed class WindowsProxyProfileStore
{
    public const int MaxProfiles = 64;
    public const int MaxSerializedBytes = 512 * 1024;

    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        PropertyNameCaseInsensitive = true,
        WriteIndented = false,
    };

    private readonly string _path;
    private readonly IWindowsProxySecretProtector _protector;
    private readonly SemaphoreSlim _gate = new(1, 1);
    private const string ProtectedPrefix = "LWPROXY-DPAPI-1:";

    public WindowsProxyProfileStore(string path, IWindowsProxySecretProtector? protector = null)
    {
        if (string.IsNullOrWhiteSpace(path))
            throw new ArgumentException("profile path required", nameof(path));
        _path = path;
        _protector = protector ?? new WindowsDpapiSecretProtector();
    }

    public async Task<IReadOnlyList<WindowsProxyProfile>> LoadAsync(CancellationToken ct = default)
    {
        await _gate.WaitAsync(ct);
        try
        {
            if (!File.Exists(_path))
                return [];
            var info = new FileInfo(_path);
            if (info.Length > MaxSerializedBytes)
                return [];
            var stored = await File.ReadAllTextAsync(_path, ct);
            var json = DecodeStoredPayload(stored);
            if (json is null)
                return [];
            try
            {
                return Normalize(JsonSerializer.Deserialize<List<WindowsProxyProfile>>(json, JsonOptions) ?? []);
            }
            catch (JsonException)
            {
                return [];
            }
        }
        finally
        {
            _gate.Release();
        }
    }

    public async Task SaveAsync(IEnumerable<WindowsProxyProfile> profiles, CancellationToken ct = default)
    {
        ArgumentNullException.ThrowIfNull(profiles);
        var normalized = Normalize(profiles.ToList());
        var json = normalized.Count == 0
            ? string.Empty
            : JsonSerializer.Serialize(normalized, JsonOptions);
        var stored = EncodeStoredPayload(json);
        if (stored.Length > MaxSerializedBytes)
            throw new InvalidOperationException("proxy_profiles_too_large");

        await _gate.WaitAsync(ct);
        try
        {
            var directory = Path.GetDirectoryName(_path);
            if (!string.IsNullOrWhiteSpace(directory))
                Directory.CreateDirectory(directory);
            var tempPath = _path + ".tmp-" + Guid.NewGuid().ToString("N");
            try
            {
                await File.WriteAllTextAsync(tempPath, stored, ct);
                File.Move(tempPath, _path, true);
            }
            finally
            {
                try { if (File.Exists(tempPath)) File.Delete(tempPath); } catch (IOException) { }
            }
        }
        finally
        {
            _gate.Release();
        }
    }

    private static List<WindowsProxyProfile> Normalize(List<WindowsProxyProfile> profiles)
    {
        var result = new List<WindowsProxyProfile>(Math.Min(profiles.Count, MaxProfiles));
        var ids = new HashSet<int>();
        foreach (var source in profiles)
        {
            if (source is null || !WindowsProxyProtocol.IsSupported(source.Protocol))
                continue;
            var profile = source.Clone();
            profile.Protocol = WindowsProxyProtocol.Normalize(profile.Protocol);
            profile.Name = Bound(profile.Name, 120);
            profile.Server = Bound(profile.Server, 255);
            profile.Method = Bound(profile.Method, 120);
            profile.Password = Bound(profile.Password, 4096);
            profile.Uuid = Bound(profile.Uuid, 128);
            profile.Flow = Bound(profile.Flow, 128);
            profile.Transport = Bound(profile.Transport, 16).ToLowerInvariant();
            profile.Host = Bound(profile.Host, 255);
            profile.Path = Bound(profile.Path, 2048);
            profile.ServiceName = Bound(profile.ServiceName, 255);
            profile.ServerName = Bound(profile.ServerName, 255);
            profile.Fingerprint = Bound(profile.Fingerprint, 64);
            profile.RealityPublicKey = Bound(profile.RealityPublicKey, 256);
            profile.RealityShortId = Bound(profile.RealityShortId, 128);
            profile.Alpn = Bound(profile.Alpn, 256);
            profile.Plugin = Bound(profile.Plugin, 32).ToLowerInvariant();
            profile.PluginOpts = Bound(profile.PluginOpts, 512);
            profile.Username = Bound(profile.Username, 255);
            if (profile.HostedId < 0)
                profile.HostedId = 0;
            if (profile.Id <= 0 || !ids.Add(profile.Id))
                profile.Id = NextId(ids);
            if (profile.Port is < 1 or > 65535)
                profile.Port = 443;
            if (!WindowsProxyProtocol.VlessTransportOptions.Contains(profile.Transport, StringComparer.Ordinal))
                profile.Transport = "tcp";
            result.Add(profile);
            if (result.Count >= MaxProfiles)
                break;
        }
        return result;
    }

    private static int NextId(HashSet<int> ids)
    {
        for (var candidate = 1; candidate <= MaxProfiles * 4; candidate++)
            if (ids.Add(candidate))
                return candidate;
        return ids.Count + 1;
    }

    private static string Bound(string? value, int max)
    {
        var normalized = value?.Trim() ?? string.Empty;
        return normalized.Length <= max ? normalized : normalized[..max];
    }

    private string EncodeStoredPayload(string json)
    {
        if (string.IsNullOrEmpty(json) || !_protector.IsAvailable)
            return json;
        var plaintext = System.Text.Encoding.UTF8.GetBytes(json);
        try
        {
            var protectedBytes = _protector.Protect(plaintext);
            return ProtectedPrefix + Convert.ToBase64String(protectedBytes);
        }
        finally
        {
            System.Security.Cryptography.CryptographicOperations.ZeroMemory(plaintext);
        }
    }

    private string? DecodeStoredPayload(string stored)
    {
        if (!stored.StartsWith(ProtectedPrefix, StringComparison.Ordinal))
            return stored;
        if (!_protector.IsAvailable)
            return null;
        try
        {
            var protectedBytes = Convert.FromBase64String(stored[ProtectedPrefix.Length..]);
            var plaintext = _protector.Unprotect(protectedBytes);
            try { return System.Text.Encoding.UTF8.GetString(plaintext); }
            finally { System.Security.Cryptography.CryptographicOperations.ZeroMemory(plaintext); }
        }
        catch (FormatException) { return null; }
        catch (CryptographicException) { return null; }
        catch (Win32Exception) { return null; }
    }
}
