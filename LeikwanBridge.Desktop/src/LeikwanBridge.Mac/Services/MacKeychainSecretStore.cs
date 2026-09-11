using System.Runtime.InteropServices;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using LeikwanBridge.Core.Services.Platform;

namespace LeikwanBridge.Mac.Services;

public enum MacKeychainStatus
{
    Success,
    NotFound,
    Unavailable,
    Error,
}

public sealed record MacKeychainReadResult(MacKeychainStatus Status, byte[] Secret);

public interface IMacKeychainNative
{
    MacKeychainReadResult Read(string service, string account);
    MacKeychainStatus Set(string service, string account, byte[] secret);
    MacKeychainStatus Delete(string service, string account);
}

public sealed class MacKeychainSecretStore : ISecretStore
{
    public const string ServiceName = "com.leikwan.bridge";
    private static readonly TimeSpan DefaultNativeOperationTimeout = TimeSpan.FromSeconds(2);

    private readonly IMacKeychainNative _native;
    private readonly string _fallbackPath;
    private readonly TimeSpan _nativeOperationTimeout;
    private readonly SemaphoreSlim _gate = new(1, 1);
    private int _warningRaised;
    private int _fallbackActivated;

    public MacKeychainSecretStore(
        IMacKeychainNative? native,
        string fallbackPath,
        TimeSpan? nativeOperationTimeout = null)
    {
        _native = native ?? new MacKeychainNative();
        _fallbackPath = fallbackPath;
        _nativeOperationTimeout = nativeOperationTimeout ?? DefaultNativeOperationTimeout;
        if (_nativeOperationTimeout <= TimeSpan.Zero)
            throw new ArgumentOutOfRangeException(nameof(nativeOperationTimeout));
    }

    public event Action<string>? WarningRaised;
    public bool UsesFallback => Volatile.Read(ref _fallbackActivated) != 0;

    public async Task<string?> GetAsync(string name, CancellationToken ct)
    {
        MacSecretAccount.Validate(name);
        await _gate.WaitAsync(ct).ConfigureAwait(false);
        try
        {
            if (UsesFallback)
                return LoadFallback().GetValueOrDefault(name);

            MacKeychainReadResult result;
            try
            {
                result = await MacSecretStoreNativeRunner.RunAsync(
                    () => _native.Read(ServiceName, name),
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
                ActivateFallback();
                return LoadFallback().GetValueOrDefault(name);
            }

            try
            {
                if (result.Status == MacKeychainStatus.Success)
                    return Encoding.UTF8.GetString(result.Secret);
                if (result.Status == MacKeychainStatus.NotFound)
                    return await MigrateFallbackValueAsync(name, ct).ConfigureAwait(false);
            }
            finally
            {
                CryptographicOperations.ZeroMemory(result.Secret);
            }
            ActivateFallback();
            return LoadFallback().GetValueOrDefault(name);
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
            if (UsesFallback)
            {
                var fallbackValues = LoadFallback();
                fallbackValues[name] = value;
                SaveFallback(fallbackValues);
                return;
            }

            var secret = Encoding.UTF8.GetBytes(value);
            MacKeychainStatus status;
            try
            {
                status = await MacSecretStoreNativeRunner.RunAsync(
                    () => _native.Set(ServiceName, name, secret),
                    ct,
                    _nativeOperationTimeout,
                    cleanupOnCompletion: () => CryptographicOperations.ZeroMemory(secret)).ConfigureAwait(false);
            }
            catch (OperationCanceledException) when (ct.IsCancellationRequested)
            {
                throw;
            }
            catch
            {
                status = MacKeychainStatus.Unavailable;
            }
            if (status == MacKeychainStatus.Success)
            {
                RemoveFallbackValue(name);
                return;
            }
            ActivateFallback();
            var values = LoadFallback();
            values[name] = value;
            SaveFallback(values);
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
            if (UsesFallback)
            {
                RemoveFallbackValue(name);
                return;
            }

            MacKeychainStatus status;
            try
            {
                status = await MacSecretStoreNativeRunner.RunAsync(
                    () => _native.Delete(ServiceName, name),
                    ct,
                    _nativeOperationTimeout).ConfigureAwait(false);
            }
            catch (OperationCanceledException) when (ct.IsCancellationRequested)
            {
                throw;
            }
            catch
            {
                status = MacKeychainStatus.Unavailable;
            }
            if (status is not (MacKeychainStatus.Success or MacKeychainStatus.NotFound))
                ActivateFallback();
            RemoveFallbackValue(name);
        }
        finally
        {
            _gate.Release();
        }
    }

    private void ActivateFallback()
    {
        Interlocked.Exchange(ref _fallbackActivated, 1);
        if (Interlocked.Exchange(ref _warningRaised, 1) == 0)
            WarningRaised?.Invoke("keychain_unavailable");
    }

    private Dictionary<string, string> LoadFallback()
    {
        if (!File.Exists(_fallbackPath))
            return [];
        byte[] encoded = [];
        try
        {
            encoded = File.ReadAllBytes(_fallbackPath);
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

    private async Task<string?> MigrateFallbackValueAsync(string name, CancellationToken ct)
    {
        var values = LoadFallback();
        if (!values.TryGetValue(name, out var value))
            return null;
        var secret = Encoding.UTF8.GetBytes(value);
        MacKeychainStatus status;
        try
        {
            status = await MacSecretStoreNativeRunner.RunAsync(
                () => _native.Set(ServiceName, name, secret),
                ct,
                _nativeOperationTimeout,
                cleanupOnCompletion: () => CryptographicOperations.ZeroMemory(secret)).ConfigureAwait(false);
        }
        catch (OperationCanceledException) when (ct.IsCancellationRequested)
        {
            throw;
        }
        catch
        {
            status = MacKeychainStatus.Unavailable;
        }
        if (status == MacKeychainStatus.Success)
            RemoveFallbackValue(name);
        else
            ActivateFallback();
        return value;
    }

    private void SaveFallback(Dictionary<string, string> values)
    {
        var directory = Path.GetDirectoryName(_fallbackPath);
        if (string.IsNullOrWhiteSpace(directory))
            throw new InvalidOperationException("fallback_secret_path_invalid");
        Directory.CreateDirectory(directory);
        SetPrivateDirectoryMode(directory);
        var temporary = _fallbackPath + "." + Guid.NewGuid().ToString("N") + ".tmp";
        var encoded = JsonSerializer.SerializeToUtf8Bytes(values);
        try
        {
            File.WriteAllBytes(temporary, encoded);
            SetPrivateFileMode(temporary);
            File.Move(temporary, _fallbackPath, overwrite: true);
            SetPrivateFileMode(_fallbackPath);
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

    private void RemoveFallbackValue(string name)
    {
        var values = LoadFallback();
        if (!values.Remove(name))
            return;
        if (values.Count == 0)
        {
            try
            {
                File.Delete(_fallbackPath);
            }
            catch (IOException)
            {
            }
            return;
        }
        SaveFallback(values);
    }

    private static void SetPrivateDirectoryMode(string path)
    {
        if (!OperatingSystem.IsWindows())
            File.SetUnixFileMode(path, UnixFileMode.UserRead | UnixFileMode.UserWrite | UnixFileMode.UserExecute);
    }

    private static void SetPrivateFileMode(string path)
    {
        if (!OperatingSystem.IsWindows())
            File.SetUnixFileMode(path, UnixFileMode.UserRead | UnixFileMode.UserWrite);
    }
}

public sealed class MacKeychainNative : IMacKeychainNative
{
    private const int Success = 0;
    private const int ItemNotFound = -25300;
    private const int NoSuchKeychain = -25294;

    public MacKeychainReadResult Read(string service, string account)
    {
        try
        {
            var serviceBytes = Encoding.UTF8.GetBytes(service);
            var accountBytes = Encoding.UTF8.GetBytes(account);
            var status = SecKeychainFindGenericPassword(
                IntPtr.Zero,
                (uint)serviceBytes.Length,
                serviceBytes,
                (uint)accountBytes.Length,
                accountBytes,
                out var length,
                out var data,
                out var item);
            try
            {
                if (status == ItemNotFound)
                    return new MacKeychainReadResult(MacKeychainStatus.NotFound, []);
                if (status == NoSuchKeychain)
                    return new MacKeychainReadResult(MacKeychainStatus.Unavailable, []);
                if (status != Success || data == IntPtr.Zero || length > 64 * 1024)
                    return new MacKeychainReadResult(MacKeychainStatus.Error, []);
                var secret = new byte[length];
                Marshal.Copy(data, secret, 0, checked((int)length));
                return new MacKeychainReadResult(MacKeychainStatus.Success, secret);
            }
            finally
            {
                if (data != IntPtr.Zero)
                    SecKeychainItemFreeContent(IntPtr.Zero, data);
                if (item != IntPtr.Zero)
                    CFRelease(item);
                CryptographicOperations.ZeroMemory(serviceBytes);
                CryptographicOperations.ZeroMemory(accountBytes);
            }
        }
        catch (DllNotFoundException)
        {
            return new MacKeychainReadResult(MacKeychainStatus.Unavailable, []);
        }
        catch (EntryPointNotFoundException)
        {
            return new MacKeychainReadResult(MacKeychainStatus.Unavailable, []);
        }
    }

    public MacKeychainStatus Set(string service, string account, byte[] secret)
    {
        try
        {
            var serviceBytes = Encoding.UTF8.GetBytes(service);
            var accountBytes = Encoding.UTF8.GetBytes(account);
            var findStatus = SecKeychainFindGenericPassword(
                IntPtr.Zero,
                (uint)serviceBytes.Length,
                serviceBytes,
                (uint)accountBytes.Length,
                accountBytes,
                out _,
                out var existingData,
                out var item);
            try
            {
                if (existingData != IntPtr.Zero)
                    SecKeychainItemFreeContent(IntPtr.Zero, existingData);
                int status;
                if (findStatus == Success && item != IntPtr.Zero)
                {
                    status = SecKeychainItemModifyAttributesAndData(item, IntPtr.Zero, (uint)secret.Length, secret);
                }
                else if (findStatus == ItemNotFound)
                {
                    status = SecKeychainAddGenericPassword(
                        IntPtr.Zero,
                        (uint)serviceBytes.Length,
                        serviceBytes,
                        (uint)accountBytes.Length,
                        accountBytes,
                        (uint)secret.Length,
                        secret,
                        out var addedItem);
                    if (addedItem != IntPtr.Zero)
                        CFRelease(addedItem);
                }
                else if (findStatus == NoSuchKeychain)
                {
                    return MacKeychainStatus.Unavailable;
                }
                else
                {
                    return MacKeychainStatus.Error;
                }
                return status == Success ? MacKeychainStatus.Success : MacKeychainStatus.Error;
            }
            finally
            {
                if (item != IntPtr.Zero)
                    CFRelease(item);
                CryptographicOperations.ZeroMemory(serviceBytes);
                CryptographicOperations.ZeroMemory(accountBytes);
            }
        }
        catch (DllNotFoundException)
        {
            return MacKeychainStatus.Unavailable;
        }
        catch (EntryPointNotFoundException)
        {
            return MacKeychainStatus.Unavailable;
        }
    }

    public MacKeychainStatus Delete(string service, string account)
    {
        try
        {
            var serviceBytes = Encoding.UTF8.GetBytes(service);
            var accountBytes = Encoding.UTF8.GetBytes(account);
            var status = SecKeychainFindGenericPassword(
                IntPtr.Zero,
                (uint)serviceBytes.Length,
                serviceBytes,
                (uint)accountBytes.Length,
                accountBytes,
                out _,
                out var data,
                out var item);
            try
            {
                if (data != IntPtr.Zero)
                    SecKeychainItemFreeContent(IntPtr.Zero, data);
                if (status == ItemNotFound)
                    return MacKeychainStatus.NotFound;
                if (status == NoSuchKeychain)
                    return MacKeychainStatus.Unavailable;
                if (status != Success || item == IntPtr.Zero)
                    return MacKeychainStatus.Error;
                return SecKeychainItemDelete(item) == Success ? MacKeychainStatus.Success : MacKeychainStatus.Error;
            }
            finally
            {
                if (item != IntPtr.Zero)
                    CFRelease(item);
                CryptographicOperations.ZeroMemory(serviceBytes);
                CryptographicOperations.ZeroMemory(accountBytes);
            }
        }
        catch (DllNotFoundException)
        {
            return MacKeychainStatus.Unavailable;
        }
        catch (EntryPointNotFoundException)
        {
            return MacKeychainStatus.Unavailable;
        }
    }

    [DllImport("/System/Library/Frameworks/Security.framework/Security")]
    private static extern int SecKeychainFindGenericPassword(
        IntPtr keychainOrArray,
        uint serviceNameLength,
        byte[] serviceName,
        uint accountNameLength,
        byte[] accountName,
        out uint passwordLength,
        out IntPtr passwordData,
        out IntPtr itemRef);

    [DllImport("/System/Library/Frameworks/Security.framework/Security")]
    private static extern int SecKeychainAddGenericPassword(
        IntPtr keychain,
        uint serviceNameLength,
        byte[] serviceName,
        uint accountNameLength,
        byte[] accountName,
        uint passwordLength,
        byte[] passwordData,
        out IntPtr itemRef);

    [DllImport("/System/Library/Frameworks/Security.framework/Security")]
    private static extern int SecKeychainItemModifyAttributesAndData(
        IntPtr itemRef,
        IntPtr attrList,
        uint length,
        byte[] data);

    [DllImport("/System/Library/Frameworks/Security.framework/Security")]
    private static extern int SecKeychainItemDelete(IntPtr itemRef);

    [DllImport("/System/Library/Frameworks/Security.framework/Security")]
    private static extern int SecKeychainItemFreeContent(IntPtr attrList, IntPtr data);

    [DllImport("/System/Library/Frameworks/CoreFoundation.framework/CoreFoundation")]
    private static extern void CFRelease(IntPtr value);
}
