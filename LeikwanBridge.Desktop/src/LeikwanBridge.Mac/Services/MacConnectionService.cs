using System.Security.Cryptography;
using System.Text;
using LeikwanBridge.Core.Models;
using Serilog;

namespace LeikwanBridge.Mac.Services;

public sealed class MacConnectionService : IMacConnectionService
{
    private readonly MacPlatformPaths _paths;
    private readonly MacConnectionProfileStore _store;
    private readonly MacBypassResolver _resolver;
    private readonly IMacConnectionProcessHost _processHost;
    private readonly SemaphoreSlim _gate = new(1, 1);
    private readonly List<MacConnectionProfile> _profiles = [];
    private readonly int _proxyPort;
    private MacConnectionSnapshot _snapshot = MacConnectionSnapshot.Disconnected;
    private string? _configPath;
    private bool _disposed;

    public MacConnectionService(
        MacPlatformPaths paths,
        MacConnectionProfileStore store,
        MacBypassResolver? resolver = null,
        IMacConnectionProcessHost? processHost = null,
        int proxyPort = MacConnectionConfigBuilder.DefaultProxyPort)
    {
        _paths = paths;
        _store = store;
        _resolver = resolver ?? new MacBypassResolver();
        _processHost = processHost ?? new MacConnectionProcessHost();
        _proxyPort = proxyPort;
    }

    public event Action<MacConnectionSnapshot>? SnapshotChanged;

    public IReadOnlyList<MacConnectionProfile> Profiles
    {
        get
        {
            lock (_profiles)
                return _profiles.Select(profile => profile.Clone()).ToList();
        }
    }

    public MacConnectionSnapshot Snapshot => _snapshot;

    public async Task InitializeAsync(CancellationToken ct = default)
    {
        await _gate.WaitAsync(ct);
        try
        {
            _profiles.Clear();
            _profiles.AddRange(await _store.LoadAsync(ct));
        }
        finally
        {
            _gate.Release();
        }
    }

    public async Task SaveProfileAsync(MacConnectionProfile profile, CancellationToken ct = default)
    {
        ArgumentNullException.ThrowIfNull(profile);
        await _gate.WaitAsync(ct);
        try
        {
            MacConnectionConfigBuilder.Build(profile, MacConnectionMode.Proxy, [], _proxyPort);
            var copy = profile.Clone();
            if (copy.Id <= 0)
                copy.Id = NextId();
            var index = _profiles.FindIndex(item => item.Id == copy.Id);
            if (index >= 0)
                _profiles[index] = copy;
            else
                _profiles.Add(copy);
            await _store.SaveAsync(_profiles, ct);
        }
        finally
        {
            _gate.Release();
        }
    }

    public async Task DeleteProfileAsync(int profileId, CancellationToken ct = default)
    {
        if (profileId <= 0)
            return;
        await _gate.WaitAsync(ct);
        try
        {
            _profiles.RemoveAll(profile => profile.Id == profileId);
            await _store.SaveAsync(_profiles, ct);
        }
        finally
        {
            _gate.Release();
        }
    }

    public async Task StartAsync(
        MacConnectionMode mode,
        int profileId,
        string panelUrl,
        IEnumerable<ForwardConfig> forwards,
        CancellationToken ct = default)
    {
        await _gate.WaitAsync(ct);
        try
        {
            ObjectDisposedException.ThrowIf(_disposed, this);
            var profile = _profiles.FirstOrDefault(item => item.Id == profileId)
                ?? throw new KeyNotFoundException("connection_profile_not_found");
            Publish(new MacConnectionSnapshot(
                MacConnectionState.Starting,
                mode,
                profile.Name,
                _proxyPort,
                0,
                ""));
            await StopCoreAsync(ct);
            var resolution = await _resolver.ResolveAsync(panelUrl, forwards ?? [], ct);
            var compiled = MacConnectionConfigBuilder.Build(
                profile,
                mode,
                resolution.All,
                _proxyPort);
            var executable = _paths.BinDirectory.TrimEnd('/', '\\') + "/sing-box";
            if (!File.Exists(executable))
                throw new FileNotFoundException("sing-box helper is missing", executable);
            var configPath = Path.Combine(
                _paths.RuntimeDirectory,
                $"connection-{Guid.NewGuid():N}.json");
            await WriteEphemeralConfigAsync(configPath, compiled.Json, ct);
            _configPath = configPath;
            try
            {
                await _processHost.StartAsync(executable, configPath, mode, ct);
            }
            finally
            {
                SecureDelete(configPath);
                _configPath = null;
            }
            Publish(new MacConnectionSnapshot(
                MacConnectionState.Connected,
                mode,
                profile.Name,
                _proxyPort,
                compiled.BypassIps.Count + compiled.BypassDomains.Count,
                ""));
            Log.Information("mac_connection_started {Mode} {Protocol}", mode, profile.Protocol);
        }
        catch (Exception exception)
        {
            Publish(new MacConnectionSnapshot(
                MacConnectionState.Error,
                mode,
                _snapshot.ActiveProfileName,
                _proxyPort,
                0,
                ErrorCode(exception)));
            throw;
        }
        finally
        {
            _gate.Release();
        }
    }

    public async Task StopAsync(CancellationToken ct = default)
    {
        await _gate.WaitAsync(ct);
        try
        {
            await StopCoreAsync(ct);
            Publish(MacConnectionSnapshot.Disconnected);
        }
        finally
        {
            _gate.Release();
        }
    }

    public async ValueTask DisposeAsync()
    {
        if (_disposed)
            return;
        _disposed = true;
        await StopAsync(CancellationToken.None);
        await _processHost.DisposeAsync();
        _gate.Dispose();
    }

    private async Task StopCoreAsync(CancellationToken ct)
    {
        await _processHost.StopAsync(ct);
        if (_configPath is not null)
            SecureDelete(_configPath);
        _configPath = null;
    }

    private static async Task WriteEphemeralConfigAsync(string path, string json, CancellationToken ct)
    {
        var bytes = Encoding.UTF8.GetBytes(json);
        try
        {
            await File.WriteAllBytesAsync(path, bytes, ct);
            if (!OperatingSystem.IsWindows())
                File.SetUnixFileMode(path, UnixFileMode.UserRead | UnixFileMode.UserWrite);
        }
        finally
        {
            CryptographicOperations.ZeroMemory(bytes);
        }
    }

    private static void SecureDelete(string path)
    {
        try
        {
            if (!File.Exists(path))
                return;
            var length = new FileInfo(path).Length;
            if (length is > 0 and <= 4 * 1024 * 1024)
            {
                using var stream = new FileStream(path, FileMode.Open, FileAccess.Write, FileShare.Read, 4096, FileOptions.WriteThrough);
                var zeros = new byte[(int)length];
                stream.Write(zeros, 0, zeros.Length);
                stream.Flush(true);
                CryptographicOperations.ZeroMemory(zeros);
            }
            File.Delete(path);
        }
        catch (IOException)
        {
            try { File.Delete(path); } catch (IOException) { }
        }
        catch (UnauthorizedAccessException)
        {
            try { File.Delete(path); } catch (IOException) { }
        }
    }

    private int NextId()
    {
        for (var id = 1; id <= 256; id++)
        {
            if (_profiles.All(profile => profile.Id != id))
                return id;
        }
        return _profiles.Count + 1;
    }

    private void Publish(MacConnectionSnapshot snapshot)
    {
        _snapshot = snapshot;
        SnapshotChanged?.Invoke(snapshot);
    }

    private static string ErrorCode(Exception exception)
    {
        var code = exception switch
        {
            FileNotFoundException => "sing_box_missing",
            KeyNotFoundException => "connection_profile_not_found",
            UnauthorizedAccessException => "permission_required",
            _ => exception.Message,
        };
        var normalized = new string((code ?? "connection_failed").ToLowerInvariant()
            .Where(character => character is >= 'a' and <= 'z' or >= '0' and <= '9' or '_')
            .Take(64)
            .ToArray());
        return normalized.Length == 0 ? "connection_failed" : normalized;
    }
}
