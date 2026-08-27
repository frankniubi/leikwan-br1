using System.Text.Json;
using LeikwanBridge.Core.Services.Platform;

namespace LeikwanBridge.Mac.Services;

public sealed class MacConnectionProfileStore
{
    public const string SecretAccount = "connection-profiles";
    private const int MaxProfiles = 64;
    private const int MaxSerializedBytes = 512 * 1024;

    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        PropertyNameCaseInsensitive = true,
        WriteIndented = false,
    };

    private readonly ISecretStore _secrets;
    private readonly SemaphoreSlim _gate = new(1, 1);

    public MacConnectionProfileStore(ISecretStore secrets)
    {
        _secrets = secrets;
    }

    public async Task<IReadOnlyList<MacConnectionProfile>> LoadAsync(CancellationToken ct)
    {
        await _gate.WaitAsync(ct);
        try
        {
            var encoded = await _secrets.GetAsync(SecretAccount, ct);
            if (string.IsNullOrWhiteSpace(encoded) || encoded.Length > MaxSerializedBytes)
                return [];
            try
            {
                var profiles = JsonSerializer.Deserialize<List<MacConnectionProfile>>(encoded, JsonOptions) ?? [];
                return Normalize(profiles);
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

    public async Task SaveAsync(IEnumerable<MacConnectionProfile> profiles, CancellationToken ct)
    {
        ArgumentNullException.ThrowIfNull(profiles);
        var normalized = Normalize(profiles.ToList());
        var encoded = normalized.Count == 0
            ? string.Empty
            : JsonSerializer.Serialize(normalized, JsonOptions);
        if (encoded.Length > MaxSerializedBytes)
            throw new InvalidOperationException("connection_profiles_too_large");

        await _gate.WaitAsync(ct);
        try
        {
            if (normalized.Count == 0)
                await _secrets.DeleteAsync(SecretAccount, ct);
            else
                await _secrets.SetAsync(SecretAccount, encoded, ct);
        }
        finally
        {
            _gate.Release();
        }
    }

    private static List<MacConnectionProfile> Normalize(List<MacConnectionProfile> profiles)
    {
        var normalized = new List<MacConnectionProfile>(Math.Min(profiles.Count, MaxProfiles));
        var ids = new HashSet<int>();
        foreach (var source in profiles)
        {
            if (source is null || !MacConnectionProtocol.IsSupported(source.Protocol))
                continue;
            var profile = source.Clone();
            profile.Protocol = profile.Protocol.Trim().ToLowerInvariant();
            profile.Name = Bound(profile.Name, 120);
            profile.Server = Bound(profile.Server, 255);
            profile.Host = Bound(profile.Host, 255);
            profile.Path = Bound(profile.Path, 2048);
            profile.ServiceName = Bound(profile.ServiceName, 255);
            profile.ServerName = Bound(profile.ServerName, 255);
            if (profile.Id <= 0 || !ids.Add(profile.Id))
                profile.Id = NextId(ids);
            if (profile.Port is < 1 or > 65535)
                profile.Port = 443;
            if (profile.Mtu is < 576 or > 9000)
                profile.Mtu = 1420;
            normalized.Add(profile);
            if (normalized.Count >= MaxProfiles)
                break;
        }
        return normalized;
    }

    private static int NextId(HashSet<int> ids)
    {
        for (var candidate = 1; candidate <= MaxProfiles * 4; candidate++)
        {
            if (ids.Add(candidate))
                return candidate;
        }
        return ids.Count + 1;
    }

    private static string Bound(string value, int max)
    {
        var normalized = value?.Trim() ?? string.Empty;
        return normalized.Length <= max ? normalized : normalized[..max];
    }
}
