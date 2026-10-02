using System.Buffers;
using System.Collections.Concurrent;
using System.ComponentModel;
using System.Security.Cryptography;
using System.Text;
using System.Text.Encodings.Web;
using System.Text.Json;
using Serilog;

namespace LeikwanBridge.Core.Services;

/// <summary>
/// This device's P-256 lease key (docs/specs/2026-10-02-ultra-sealed-lease-v1.md).
/// The panel seals the LeiGod relay endpoint and credential of every Ultra
/// lease to the public half, so an agent_token alone no longer yields a
/// working line. The private half never leaves the process except through
/// <see cref="UltraLeaseKeyStore"/>.
/// </summary>
public sealed class UltraLeaseDeviceKey : IDisposable
{
    public const string HeaderName = "X-Leikwan-Lease-Key";

    private const string P256Oid = "1.2.840.10045.3.1.7";

    private readonly ECDiffieHellman _key;
    private readonly byte[] _publicPoint;
    private readonly object _gate = new();

    private UltraLeaseDeviceKey(ECDiffieHellman key)
    {
        try
        {
            var parameters = key.ExportParameters(includePrivateParameters: false);
            if (!IsP256(parameters.Curve)
                || parameters.Q.X is not { Length: 32 } x
                || parameters.Q.Y is not { Length: 32 } y)
                throw new CryptographicException("lease_key_not_p256");
            _publicPoint = new byte[65];
            _publicPoint[0] = 0x04;
            x.CopyTo(_publicPoint, 1);
            y.CopyTo(_publicPoint, 33);
        }
        catch
        {
            key.Dispose();
            throw;
        }
        _key = key;
        HeaderValue = UltraLeaseSeal.Base64UrlEncode(_publicPoint);
        KeyId = Convert.ToHexString(SHA256.HashData(_publicPoint)).ToLowerInvariant()[..32];
    }

    /// <summary>base64url (no padding) of the 65-byte uncompressed point.</summary>
    public string HeaderValue { get; }

    /// <summary>First 32 hex chars of sha256(public point); the panel echoes it as "kid".</summary>
    public string KeyId { get; }

    public byte[] PublicKey => (byte[]) _publicPoint.Clone();

    public static UltraLeaseDeviceKey Generate()
        => new(ECDiffieHellman.Create(ECCurve.NamedCurves.nistP256));

    /// <summary>Imports a raw 32-byte P-256 private scalar (test vectors).</summary>
    public static UltraLeaseDeviceKey FromPrivateScalar(ReadOnlySpan<byte> scalar)
    {
        if (scalar.Length != 32)
            throw new CryptographicException("lease_key_scalar_invalid");
        var d = scalar.ToArray();
        try
        {
            return new UltraLeaseDeviceKey(ECDiffieHellman.Create(new ECParameters
            {
                Curve = ECCurve.NamedCurves.nistP256,
                D = d,
            }));
        }
        finally
        {
            CryptographicOperations.ZeroMemory(d);
        }
    }

    public static UltraLeaseDeviceKey ImportPkcs8(ReadOnlySpan<byte> pkcs8)
    {
        var key = ECDiffieHellman.Create();
        try
        {
            key.ImportPkcs8PrivateKey(pkcs8, out var read);
            if (read != pkcs8.Length)
                throw new CryptographicException("lease_key_trailing_data");
        }
        catch
        {
            key.Dispose();
            throw;
        }
        return new UltraLeaseDeviceKey(key);
    }

    public byte[] ExportPkcs8()
    {
        lock (_gate)
            return _key.ExportPkcs8PrivateKey();
    }

    /// <summary>Raw ECDH shared secret: the 32-byte big-endian x coordinate.</summary>
    internal byte[] DeriveSharedSecret(ReadOnlySpan<byte> peerPoint)
    {
        if (peerPoint.Length != 65 || peerPoint[0] != 0x04)
            throw new CryptographicException("lease_peer_key_invalid");
        ECDiffieHellman peer;
        try
        {
            peer = ECDiffieHellman.Create(new ECParameters
            {
                Curve = ECCurve.NamedCurves.nistP256,
                Q = new ECPoint { X = peerPoint[1..33].ToArray(), Y = peerPoint[33..].ToArray() },
            });
        }
        catch (PlatformNotSupportedException ex)
        {
            // CNG reports a point that is not on the curve this way.
            throw new CryptographicException("lease_peer_key_invalid", ex);
        }
        using (peer)
        {
            lock (_gate)
                return _key.DeriveRawSecretAgreement(peer.PublicKey);
        }
    }

    public void Dispose() => _key.Dispose();

    private static bool IsP256(ECCurve curve)
    {
        if (!curve.IsNamed)
            return false;
        if (string.Equals(curve.Oid.Value, P256Oid, StringComparison.Ordinal))
            return true;
        return curve.Oid.FriendlyName is "nistP256" or "ECDSA_P256" or "ECDH_P256" or "secp256r1" or "prime256v1";
    }
}

/// <summary>
/// Persists one lease key per device so the panel sees the same key on every
/// request, restart and update. Windows keeps the PKCS#8 bytes under DPAPI in
/// the account's own scope (the GUI user or LocalSystem for the service), in a
/// file named after the account SID so two accounts sharing the state
/// directory never overwrite each other's key. Other platforms keep an
/// owner-only (0600) file in the agent's private state directory. A missing or
/// unreadable key is replaced by a new one; a key that cannot be written is
/// still used for the life of the process.
/// </summary>
public sealed class UltraLeaseKeyStore
{
    private const string ProtectedPrefix = "LWLEASEKEY-DPAPI-1:";
    private const string PlainPrefix = "LWLEASEKEY-PKCS8-1:";
    private const int MaxStoredBytes = 16 * 1024;

    private static readonly ConcurrentDictionary<string, UltraLeaseKeyStore> SharedStores = new(
        OperatingSystem.IsWindows() ? StringComparer.OrdinalIgnoreCase : StringComparer.Ordinal);
    private static volatile string? _defaultDirectoryOverride;

    private readonly IWindowsProxySecretProtector? _protector;
    private readonly object _gate = new();
    private UltraLeaseDeviceKey? _key;

    public UltraLeaseKeyStore(string directory, IWindowsProxySecretProtector? protector = null)
    {
        if (string.IsNullOrWhiteSpace(directory))
            throw new ArgumentException("lease key directory required", nameof(directory));
        Directory = directory;
        _protector = protector ?? (OperatingSystem.IsWindows() ? new WindowsDpapiSecretProtector() : null);
        KeyPath = Path.Combine(directory, KeyFileName());
    }

    public string Directory { get; }

    public string KeyPath { get; }

    /// <summary>False while the key in use exists only in memory (it could not be written).</summary>
    public bool IsPersisted { get; private set; }

    /// <summary>The store for the platform's default agent state directory.</summary>
    public static UltraLeaseKeyStore Default => ForDirectory(DefaultDirectory);

    public static string DefaultDirectory => _defaultDirectoryOverride ?? PlatformDefaultDirectory();

    /// <summary>
    /// Points <see cref="Default"/> somewhere else, for hosts that keep their
    /// state outside the platform default and for test runs.
    /// </summary>
    public static void OverrideDefaultDirectory(string? directory)
        => _defaultDirectoryOverride = string.IsNullOrWhiteSpace(directory) ? null : directory;

    /// <summary>
    /// One store per directory per process, so every MasterApiClient of the
    /// process presents the same key.
    /// </summary>
    public static UltraLeaseKeyStore ForDirectory(string directory)
    {
        if (string.IsNullOrWhiteSpace(directory))
            throw new ArgumentException("lease key directory required", nameof(directory));
        return SharedStores.GetOrAdd(Path.GetFullPath(directory), path => new UltraLeaseKeyStore(path));
    }

    public UltraLeaseDeviceKey GetOrCreate()
    {
        lock (_gate)
        {
            if (_key is not null)
                return _key;
            var existed = File.Exists(KeyPath);
            if (existed && TryLoad(out var loaded))
            {
                IsPersisted = true;
                return _key = loaded;
            }
            if (existed)
                Log.Warning("ultra lease key at {Path} is unreadable; generating a new device lease key", KeyPath);

            var generated = UltraLeaseDeviceKey.Generate();
            if (TryPersist(generated, overwrite: existed))
            {
                IsPersisted = true;
                return _key = generated;
            }
            // Another process of the same account may have created the key
            // between the existence check and the write: adopt that one.
            if (!existed && File.Exists(KeyPath) && TryLoad(out var raced))
            {
                generated.Dispose();
                IsPersisted = true;
                return _key = raced;
            }
            Log.Warning("ultra lease key could not be written to {Path}; using an in-memory key for this run", KeyPath);
            IsPersisted = false;
            return _key = generated;
        }
    }

    private bool UsesDpapi => _protector is { IsAvailable: true };

    private bool TryLoad(out UltraLeaseDeviceKey key)
    {
        key = null!;
        byte[] pkcs8 = [];
        try
        {
            var info = new FileInfo(KeyPath);
            if (info.Length is <= 0 or > MaxStoredBytes)
                return false;
            if (!UsesDpapi && !OperatingSystem.IsWindows())
                EnsureOwnerOnly(KeyPath);
            var stored = File.ReadAllText(KeyPath).Trim();
            var prefix = UsesDpapi ? ProtectedPrefix : PlainPrefix;
            if (!stored.StartsWith(prefix, StringComparison.Ordinal))
                return false;
            var blob = Convert.FromBase64String(stored[prefix.Length..]);
            pkcs8 = UsesDpapi ? _protector!.Unprotect(blob) : blob;
            key = UltraLeaseDeviceKey.ImportPkcs8(pkcs8);
            return true;
        }
        catch (Exception ex) when (ex is IOException
            or UnauthorizedAccessException
            or FormatException
            or CryptographicException
            or Win32Exception
            or PlatformNotSupportedException)
        {
            Log.Debug("ultra lease key load failed ({ErrorType})", ex.GetType().Name);
            return false;
        }
        finally
        {
            CryptographicOperations.ZeroMemory(pkcs8);
        }
    }

    private bool TryPersist(UltraLeaseDeviceKey key, bool overwrite)
    {
        var temporary = KeyPath + "." + Guid.NewGuid().ToString("N") + ".tmp";
        byte[] pkcs8 = [];
        try
        {
            CreatePrivateDirectory(Directory);
            pkcs8 = key.ExportPkcs8();
            var stored = UsesDpapi
                ? ProtectedPrefix + Convert.ToBase64String(_protector!.Protect(pkcs8))
                : PlainPrefix + Convert.ToBase64String(pkcs8);
            var options = new FileStreamOptions
            {
                Mode = FileMode.CreateNew,
                Access = FileAccess.Write,
                Share = FileShare.None,
            };
            if (!OperatingSystem.IsWindows())
                options.UnixCreateMode = UnixFileMode.UserRead | UnixFileMode.UserWrite;
            using (var stream = new FileStream(temporary, options))
            using (var writer = new StreamWriter(stream, new UTF8Encoding(encoderShouldEmitUTF8Identifier: false)))
                writer.Write(stored + "\n");
            File.Move(temporary, KeyPath, overwrite);
            if (!OperatingSystem.IsWindows())
                EnsureOwnerOnly(KeyPath);
            return true;
        }
        catch (Exception ex) when (ex is IOException
            or UnauthorizedAccessException
            or CryptographicException
            or Win32Exception
            or PlatformNotSupportedException)
        {
            Log.Debug("ultra lease key write failed ({ErrorType})", ex.GetType().Name);
            return false;
        }
        finally
        {
            CryptographicOperations.ZeroMemory(pkcs8);
            try
            {
                if (File.Exists(temporary))
                    File.Delete(temporary);
            }
            catch (Exception ex) when (ex is IOException or UnauthorizedAccessException)
            {
            }
        }
    }

    private static void CreatePrivateDirectory(string directory)
    {
        if (System.IO.Directory.Exists(directory))
            return;
        if (OperatingSystem.IsWindows())
            System.IO.Directory.CreateDirectory(directory);
        else
            System.IO.Directory.CreateDirectory(
                directory,
                UnixFileMode.UserRead | UnixFileMode.UserWrite | UnixFileMode.UserExecute);
    }

    private static void EnsureOwnerOnly(string path)
    {
        if (OperatingSystem.IsWindows())
            return;
        const UnixFileMode ownerOnly = UnixFileMode.UserRead | UnixFileMode.UserWrite;
        if (File.GetUnixFileMode(path) != ownerOnly)
            File.SetUnixFileMode(path, ownerOnly);
    }

    private static string KeyFileName()
    {
        if (!OperatingSystem.IsWindows())
            return "ultra-lease-key";
        string sid;
        try
        {
            using var identity = System.Security.Principal.WindowsIdentity.GetCurrent();
            sid = identity.User?.Value ?? "";
        }
        catch (Exception ex) when (ex is System.Security.SecurityException or UnauthorizedAccessException)
        {
            sid = "";
        }
        if (sid.Length == 0 || sid.Any(character => character is not ('S' or '-' or >= '0' and <= '9')))
            sid = "account";
        return $"ultra-lease-key.{sid}.dpapi";
    }

    private static string PlatformDefaultDirectory()
    {
        if (OperatingSystem.IsWindows())
            return Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
                "Leikwan", "ClientAgent", "state");
        if (OperatingSystem.IsMacOS())
            return Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.UserProfile),
                "Library", "Application Support", "LeiKwan", "ClientAgent");
        return Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
            "LeiKwan", "ClientAgent");
    }
}

/// <summary>
/// Opens the "sealed" envelope of an Ultra lease and folds the plaintext keys
/// back into the lease, so the existing parsers consume it unchanged.
/// </summary>
public static class UltraLeaseSeal
{
    public const int Version = 1;
    public const string Algorithm = "ECDH-P256+HKDF-SHA256+A256GCM";
    public const string Info = "leikwan-ultra-lease-v1";

    public const string UnsealFailedCode = "lease_unseal_failed";
    public const string KeyInvalidCode = "lease_key_invalid";
    public const string SealingRequiredCode = "lease_sealing_required";
    public const string DeviceKeyConflictCode = "lease_device_key_conflict";

    private const int TagSize = 16;
    private const int IvSize = 12;

    private static readonly JsonWriterOptions MergeWriterOptions = new()
    {
        // The merged lease is parsed straight back in memory, never rendered.
        Encoder = JavaScriptEncoder.UnsafeRelaxedJsonEscaping,
    };

    public static bool IsKeyRefusal(string? errorCode)
        => errorCode is KeyInvalidCode or SealingRequiredCode or DeviceKeyConflictCode;

    /// <summary>
    /// Decrypts one envelope. Returns the UTF-8 plaintext, or null with a short
    /// failure reason (never secret material) when anything does not check out.
    /// The caller owns and should zero the returned bytes.
    /// </summary>
    public static byte[]? TryOpen(JsonElement envelope, string leaseId, UltraLeaseDeviceKey key, out string failure)
    {
        ArgumentNullException.ThrowIfNull(key);
        failure = "";
        if (envelope.ValueKind != JsonValueKind.Object)
            return Fail("envelope", out failure);
        if (!envelope.TryGetProperty("v", out var version)
            || version.ValueKind != JsonValueKind.Number
            || !version.TryGetInt32(out var versionNumber)
            || versionNumber != Version)
            return Fail("version", out failure);
        if (ReadString(envelope, "alg") != Algorithm)
            return Fail("algorithm", out failure);
        if (!string.Equals(ReadString(envelope, "kid"), key.KeyId, StringComparison.Ordinal))
            return Fail("key_id", out failure);
        var epk = Base64UrlDecode(ReadString(envelope, "epk"));
        if (epk is not { Length: 65 } || epk[0] != 0x04)
            return Fail("epk", out failure);
        var iv = Base64UrlDecode(ReadString(envelope, "iv"));
        if (iv is not { Length: IvSize })
            return Fail("iv", out failure);
        var blob = Base64UrlDecode(ReadString(envelope, "ct"));
        if (blob is null || blob.Length < TagSize)
            return Fail("ct", out failure);

        byte[] shared = [];
        byte[] aesKey = [];
        byte[] salt = new byte[130];
        var plaintext = new byte[blob.Length - TagSize];
        try
        {
            shared = key.DeriveSharedSecret(epk);
            epk.CopyTo(salt, 0);
            key.PublicKey.CopyTo(salt, 65);
            aesKey = HKDF.DeriveKey(HashAlgorithmName.SHA256, shared, 32, salt, Encoding.UTF8.GetBytes(Info));
            using var aes = new AesGcm(aesKey, TagSize);
            aes.Decrypt(
                iv,
                blob.AsSpan(0, blob.Length - TagSize),
                blob.AsSpan(blob.Length - TagSize),
                plaintext,
                Encoding.UTF8.GetBytes(Info + "|" + leaseId));
            return plaintext;
        }
        catch (CryptographicException)
        {
            CryptographicOperations.ZeroMemory(plaintext);
            return Fail("decrypt", out failure);
        }
        finally
        {
            CryptographicOperations.ZeroMemory(shared);
            CryptographicOperations.ZeroMemory(aesKey);
            CryptographicOperations.ZeroMemory(blob);
        }
    }

    /// <summary>
    /// Returns true when the lease is usable: either it carries no "sealed"
    /// member (opened stays null, use the lease as-is) or the envelope opened
    /// and <paramref name="opened"/> holds the merged lease.
    /// </summary>
    internal static bool TryOpenLease(
        JsonElement lease,
        UltraLeaseDeviceKey key,
        out UltraOpenedLease? opened,
        out string failure)
    {
        opened = null;
        failure = "";
        if (lease.ValueKind != JsonValueKind.Object || !lease.TryGetProperty("sealed", out var envelope))
            return true;

        var leaseId = "";
        if (lease.TryGetProperty("lease_id", out var leaseIdElement))
        {
            if (leaseIdElement.ValueKind != JsonValueKind.String)
            {
                failure = "lease_id";
                return false;
            }
            leaseId = leaseIdElement.GetString() ?? "";
        }

        var plaintext = TryOpen(envelope, leaseId, key, out failure);
        if (plaintext is null)
            return false;
        var writerBuffer = new ArrayBufferWriter<byte>(4096 + plaintext.Length * 2);
        try
        {
            using var secret = JsonDocument.Parse(plaintext);
            if (secret.RootElement.ValueKind != JsonValueKind.Object)
            {
                failure = "plaintext";
                return false;
            }
            var sealedNames = new HashSet<string>(StringComparer.Ordinal);
            foreach (var property in secret.RootElement.EnumerateObject())
                sealedNames.Add(property.Name);
            using (var writer = new Utf8JsonWriter(writerBuffer, MergeWriterOptions))
            {
                writer.WriteStartObject();
                foreach (var property in lease.EnumerateObject())
                {
                    if (property.NameEquals("sealed") || sealedNames.Contains(property.Name))
                        continue;
                    property.WriteTo(writer);
                }
                foreach (var property in secret.RootElement.EnumerateObject())
                    property.WriteTo(writer);
                writer.WriteEndObject();
            }
            var merged = writerBuffer.WrittenSpan.ToArray();
            try
            {
                opened = new UltraOpenedLease(JsonDocument.Parse(merged), merged);
            }
            catch
            {
                CryptographicOperations.ZeroMemory(merged);
                throw;
            }
            return true;
        }
        catch (JsonException)
        {
            failure = "plaintext";
            return false;
        }
        finally
        {
            writerBuffer.Clear();
            CryptographicOperations.ZeroMemory(plaintext);
        }
    }

    public static string Base64UrlEncode(ReadOnlySpan<byte> value)
        => Convert.ToBase64String(value).TrimEnd('=').Replace('+', '-').Replace('/', '_');

    /// <summary>Strict base64url decoding; padding is tolerated, '+' and '/' are not.</summary>
    public static byte[]? Base64UrlDecode(string? value)
    {
        if (string.IsNullOrEmpty(value))
            return null;
        var trimmed = value.TrimEnd('=');
        if (value.Length - trimmed.Length > 2
            || trimmed.Length % 4 == 1
            || trimmed.Any(character => character is not (>= 'A' and <= 'Z' or >= 'a' and <= 'z' or >= '0' and <= '9' or '-' or '_')))
            return null;
        var standard = trimmed.Replace('-', '+').Replace('_', '/');
        standard = standard.PadRight(standard.Length + (4 - standard.Length % 4) % 4, '=');
        try
        {
            return Convert.FromBase64String(standard);
        }
        catch (FormatException)
        {
            return null;
        }
    }

    private static string? ReadString(JsonElement owner, string name)
        => owner.TryGetProperty(name, out var element) && element.ValueKind == JsonValueKind.String
            ? element.GetString()
            : null;

    private static byte[]? Fail(string reason, out string failure)
    {
        failure = reason;
        return null;
    }
}

/// <summary>A lease whose sealed envelope was opened; zeroes its buffer on dispose.</summary>
internal sealed class UltraOpenedLease : IDisposable
{
    private readonly JsonDocument _document;
    private readonly byte[] _buffer;

    public UltraOpenedLease(JsonDocument document, byte[] buffer)
    {
        _document = document;
        _buffer = buffer;
    }

    public JsonElement Element => _document.RootElement;

    public void Dispose()
    {
        _document.Dispose();
        CryptographicOperations.ZeroMemory(_buffer);
    }
}
