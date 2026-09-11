using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using LeikwanBridge.Core.Services.Platform;

namespace LeikwanBridge.Mac.Services;

// The default macOS secret backend. Secrets live in a private JSON file
// inside the application support directory (0600 file, 0700 directory), so
// first launch never touches the login keychain and never triggers the
// classic keychain authorization dialog. The login keychain is only read
// once, during MigrateLegacyAsync, to import values created by older
// releases that stored secrets in the keychain.
public sealed class MacFileSecretStore : ISecretStore
{
    private static readonly TimeSpan DefaultNativeOperationTimeout = TimeSpan.FromSeconds(2);

    private readonly string _filePath;
    private readonly string _markerPath;
    private readonly IMacKeychainNative? _keychainMigrationSource;
    private readonly TimeSpan _nativeOperationTimeout;
    private readonly SemaphoreSlim _gate = new(1, 1);
    private int _warningRaised;

    public MacFileSecretStore(
        string filePath,
        IMacKeychainNative? keychainMigrationSource = null,
        TimeSpan? nativeOperationTimeout = null)
    {
        if (string.IsNullOrWhiteSpace(filePath))
            throw new ArgumentException("secret file path required", nameof(filePath));
        _filePath = filePath;
        _markerPath = filePath + ".migrated";
        _keychainMigrationSource = keychainMigrationSource;
        _nativeOperationTimeout = nativeOperationTimeout ?? DefaultNativeOperationTimeout;
        if (_nativeOperationTimeout <= TimeSpan.Zero)
            throw new ArgumentOutOfRangeException(nameof(nativeOperationTimeout));
    }

    public event Action<string>? WarningRaised;

    public async Task<string?> GetAsync(string name, CancellationToken ct)
    {
        MacSecretAccount.Validate(name);
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        try
        {
            return ReadFile(_filePath).GetValueOrDefault(name);
        }
        finally
        {
            _gate.Release();
        }
    }

    public async Task SetAsync(string name, string value, CancellationToken ct)
    {
        MacSecretAccount.Validate(name);
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        try
        {
            var values = ReadFile(_filePath);
            values[name] = value;
            WriteFile(_filePath, values);
        }
        finally
        {
            _gate.Release();
        }
    }

    public async Task DeleteAsync(string name, CancellationToken ct)
    {
        MacSecretAccount.Validate(name);
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        try
        {
            var values = ReadFile(_filePath);
            if (!values.Remove(name))
                return;
            if (values.Count == 0)
            {
                try
                {
                    File.Delete(_filePath);
                }
                catch (IOException)
                {
                }
                return;
            }
            WriteFile(_filePath, values);
        }
        finally
        {
            _gate.Release();
        }
    }

    // One-time upgrade migration: import every secret an older release may
    // have stored in the login keychain, then delete the keychain items so
    // the keychain is never touched again. A marker file keeps later launches
    // entirely keychain-free. Missing values in the secret file are never
    // overwritten, and an unavailable keychain leaves the marker unwritten so
    // the sweep is retried on the next launch.
    public async Task MigrateLegacyAsync(CancellationToken ct)
    {
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        try
        {
            if (File.Exists(_markerPath))
                return;
            var values = ReadFile(_filePath);
            var changed = false;
            var keychainReachable = true;
            if (_keychainMigrationSource is { } keychain)
            {
                foreach (var account in MacSecretAccount.MigrationAccountNames())
                {
                    if (values.ContainsKey(account))
                        continue;
                    MacKeychainReadResult result;
                    try
                    {
                        result = await MacSecretStoreNativeRunner.RunAsync(
                            () => keychain.Read(MacKeychainSecretStore.ServiceName, account),
                            ct,
                            _nativeOperationTimeout,
                            cleanupIfAbandoned: value => CryptographicOperations.ZeroMemory(value.Secret)).ConfigureAwait(false);
                    }
                    catch (OperationCanceledException) when (ct.IsCancellationRequested)
                    {
                        throw;
                    }
                    catch
                    {
                        keychainReachable = false;
                        Warn("keychain_migration_failed");
                        break;
                    }

                    try
                    {
                        if (result.Status == MacKeychainStatus.Success)
                        {
                            values[account] = Encoding.UTF8.GetString(result.Secret);
                            changed = true;
                            await RemoveKeychainItemAsync(keychain, account, ct).ConfigureAwait(false);
                        }
                        else if (result.Status != MacKeychainStatus.NotFound)
                        {
                            keychainReachable = false;
                        }
                    }
                    finally
                    {
                        CryptographicOperations.ZeroMemory(result.Secret);
                    }
                }
            }

            if (changed)
                WriteFile(_filePath, values);
            if (keychainReachable)
                WriteMigrationMarker();
        }
        finally
        {
            _gate.Release();
        }
    }

    private async Task RemoveKeychainItemAsync(IMacKeychainNative keychain, string account, CancellationToken ct)
    {
        try
        {
            var status = await MacSecretStoreNativeRunner.RunAsync(
                () => keychain.Delete(MacKeychainSecretStore.ServiceName, account),
                ct,
                _nativeOperationTimeout).ConfigureAwait(false);
            if (status is not (MacKeychainStatus.Success or MacKeychainStatus.NotFound))
                Warn("keychain_migration_delete_failed");
        }
        catch
        {
            Warn("keychain_migration_delete_failed");
        }
    }

    private void WriteMigrationMarker()
    {
        try
        {
            var directory = Path.GetDirectoryName(_markerPath);
            if (string.IsNullOrWhiteSpace(directory))
                throw new InvalidOperationException("secret_migration_marker_path_invalid");
            Directory.CreateDirectory(directory);
            File.WriteAllText(_markerPath, "");
            if (!OperatingSystem.IsWindows())
                File.SetUnixFileMode(_markerPath, UnixFileMode.UserRead | UnixFileMode.UserWrite);
        }
        catch (IOException)
        {
        }
    }

    private void Warn(string code)
    {
        if (Interlocked.Exchange(ref _warningRaised, 1) == 0)
            WarningRaised?.Invoke(code);
    }

    internal static Dictionary<string, string> ReadFile(string path)
    {
        if (!File.Exists(path))
            return [];
        byte[] encoded = [];
        try
        {
            encoded = File.ReadAllBytes(path);
            return JsonSerializer.Deserialize<Dictionary<string, string>>(encoded) ?? [];
        }
        catch (JsonException)
        {
            return [];
        }
        finally
        {
            CryptographicOperations.ZeroMemory(encoded);
        }
    }

    internal static void WriteFile(string path, Dictionary<string, string> values)
    {
        var directory = Path.GetDirectoryName(path);
        if (string.IsNullOrWhiteSpace(directory))
            throw new InvalidOperationException("secret_file_path_invalid");
        Directory.CreateDirectory(directory);
        if (!OperatingSystem.IsWindows())
            File.SetUnixFileMode(directory, UnixFileMode.UserRead | UnixFileMode.UserWrite | UnixFileMode.UserExecute);
        var temporary = path + "." + Guid.NewGuid().ToString("N") + ".tmp";
        var encoded = JsonSerializer.SerializeToUtf8Bytes(values);
        try
        {
            File.WriteAllBytes(temporary, encoded);
            if (!OperatingSystem.IsWindows())
                File.SetUnixFileMode(temporary, UnixFileMode.UserRead | UnixFileMode.UserWrite);
            File.Move(temporary, path, overwrite: true);
            if (!OperatingSystem.IsWindows())
                File.SetUnixFileMode(path, UnixFileMode.UserRead | UnixFileMode.UserWrite);
        }
        finally
        {
            CryptographicOperations.ZeroMemory(encoded);
            try
            {
                File.Delete(temporary);
            }
            catch (IOException)
            {
            }
        }
    }
}
