using System.Net;
using System.Runtime.CompilerServices;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.Json.Nodes;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.Core.Tests.Runtime;

internal static class UltraLeaseKeyTestDefaults
{
    // Clients built without an explicit store use UltraLeaseKeyStore.Default;
    // keep test runs from writing a key into the real agent state directory.
    [ModuleInitializer]
    internal static void RedirectDefaultLeaseKeyDirectory()
        => UltraLeaseKeyStore.OverrideDefaultDirectory(Path.Combine(
            Path.GetTempPath(),
            "leikwan-desktop-tests",
            "lease-key-" + Guid.NewGuid().ToString("N")));
}

public sealed class UltraSealedLeaseTests
{
    // docs/specs/2026-10-02-ultra-sealed-lease-v1.md section 5.
    private const string VectorScalarHex = "899847890f5f32c53126b22dc726d290a22e63fdc2bbc6dd2f063685199e5dfb";
    private const string VectorPublic = "BJB5i5gLTy4P7-eKXbTqzY_-YNVJKKEaugOx14nJAuEHrTi_gY9XreyCRnuXCE86Ecgpq1pTbXRGQ0BCPfIHhJs";
    private const string VectorLeaseId = "ul2_test-vector-0001";
    private const string VectorSealed = """
        {
          "v": 1,
          "alg": "ECDH-P256+HKDF-SHA256+A256GCM",
          "kid": "c188836f4cbb9983ef0ce2f209a32767",
          "epk": "BNcv3DbxPypDUaly9lSNYnpyfC2TFG6vjXq2CPkud-Y_eUPFQEtJv4ppTMWnLJ8FaohlckDI6vfBZQR1Ge8IdxU",
          "iv": "ABEiM0RVZneImaq7",
          "ct": "PBnTWzpuLWhTPFhpX86NSK30Xe3CbjjHaQOs6PSBQ0uymvpO2hvnLDuWaaTqdrVWBWORXa7dQfFmzKxr4pN6xJ6Bi-IEiHBKOE8MgpV1REPUugS3rInpQhM66G9rY1ZrWmSTysuUlhoyRo_HeRgGT5uPETJfdiOThqzck9ixD8_ykEXHkO0VstbSUnc09hBqChXJX92Jmuf_tSb-9DOgvnfxd7A8_UDn4x52VVC6HuGQwOXG11gWfhUFSI62OMIJbdvKo3u6TmeV7GHL"
        }
        """;
    private const string VectorPlaintext = "{\"endpoint\":{\"ip\":\"203.0.113.7\",\"port\":40123},\"credential\":{\"user\":\"lg-user-1\",\"password\":\"lg-secret-1\"},\"external_uot\":{\"enabled\":true,\"sidecar_target\":\"198.51.100.9:7443\",\"token\":\"tok\"}}";

    private const string Target = "198.51.100.10:443";

    [Fact]
    public void SpecVector_OpensToExactPlaintext()
    {
        using var key = UltraLeaseDeviceKey.FromPrivateScalar(Convert.FromHexString(VectorScalarHex));
        Assert.Equal(VectorPublic, key.HeaderValue);
        Assert.Equal("c188836f4cbb9983ef0ce2f209a32767", key.KeyId);
        using var envelope = JsonDocument.Parse(VectorSealed);

        var plaintext = UltraLeaseSeal.TryOpen(envelope.RootElement, VectorLeaseId, key, out var failure);

        Assert.NotNull(plaintext);
        Assert.Equal("", failure);
        Assert.Equal(VectorPlaintext, Encoding.UTF8.GetString(plaintext!));
    }

    [Theory]
    [InlineData("ct")]
    [InlineData("iv")]
    [InlineData("epk")]
    public void SpecVector_FlippedByteFails(string field)
    {
        using var key = UltraLeaseDeviceKey.FromPrivateScalar(Convert.FromHexString(VectorScalarHex));
        var node = JsonNode.Parse(VectorSealed)!.AsObject();
        var bytes = UltraLeaseSeal.Base64UrlDecode(node[field]!.GetValue<string>())!;
        bytes[^1] ^= 0x01;
        node[field] = UltraLeaseSeal.Base64UrlEncode(bytes);
        using var envelope = JsonDocument.Parse(node.ToJsonString());

        Assert.Null(UltraLeaseSeal.TryOpen(envelope.RootElement, VectorLeaseId, key, out var failure));
        Assert.NotEqual("", failure);
    }

    [Fact]
    public void SpecVector_WrongLeaseIdFails()
    {
        using var key = UltraLeaseDeviceKey.FromPrivateScalar(Convert.FromHexString(VectorScalarHex));
        using var envelope = JsonDocument.Parse(VectorSealed);

        Assert.Null(UltraLeaseSeal.TryOpen(envelope.RootElement, VectorLeaseId + "x", key, out var failure));
        Assert.Equal("decrypt", failure);
    }

    [Theory]
    [InlineData("v", "2", "version")]
    [InlineData("alg", "\"ECDH-P384+HKDF-SHA256+A256GCM\"", "algorithm")]
    [InlineData("kid", "\"00000000000000000000000000000000\"", "key_id")]
    [InlineData("iv", "\"AB+iM0RVZneImaq7\"", "iv")]
    public void SpecVector_RejectsEnvelopeItCannotTrust(string field, string json, string expectedFailure)
    {
        using var key = UltraLeaseDeviceKey.FromPrivateScalar(Convert.FromHexString(VectorScalarHex));
        var node = JsonNode.Parse(VectorSealed)!.AsObject();
        node[field] = JsonNode.Parse(json);
        using var envelope = JsonDocument.Parse(node.ToJsonString());

        Assert.Null(UltraLeaseSeal.TryOpen(envelope.RootElement, VectorLeaseId, key, out var failure));
        Assert.Equal(expectedFailure, failure);
    }

    [Fact]
    public async Task UltraLease_SendsKeyHeaderAndOpensSealedMaterial()
    {
        using var keyDirectory = new TempStateDirectory();
        var store = new UltraLeaseKeyStore(keyDirectory.Path);
        var generation = new string('a', 64);
        var handler = new LeaseHandler(store, (key, request) => UltraLeaseResponse(generation, key, LeaseSealing.Sealed));
        using var http = new HttpClient(handler) { Timeout = TimeSpan.FromSeconds(5) };
        using var api = CreateClient(http, store);

        using var lease = await api.ResolveUltraLeaseAsync(CreateUltraForward(generation));

        Assert.NotNull(lease);
        Assert.Equal(store.GetOrCreate().HeaderValue, handler.LastLeaseKeyHeader);
        Assert.Equal("203.0.113.20", lease!.ProviderIp);
        Assert.Equal(5438, lease.ProviderPort);
        Assert.Equal("line-user", Encoding.UTF8.GetString(lease.User));
        Assert.Equal("line-password", Encoding.UTF8.GetString(lease.Password));
        Assert.Equal("lease-a", lease.LeaseId);
    }

    [Fact]
    public async Task UltraLease_WithoutSealedIsUsedAsBefore()
    {
        using var keyDirectory = new TempStateDirectory();
        var store = new UltraLeaseKeyStore(keyDirectory.Path);
        var generation = new string('a', 64);
        var handler = new LeaseHandler(store, (key, request) => UltraLeaseResponse(generation, key, LeaseSealing.Clear));
        using var http = new HttpClient(handler) { Timeout = TimeSpan.FromSeconds(5) };
        using var api = CreateClient(http, store);

        using var lease = await api.ResolveUltraLeaseAsync(CreateUltraForward(generation));

        Assert.NotNull(lease);
        Assert.False(string.IsNullOrEmpty(handler.LastLeaseKeyHeader));
        Assert.Equal("203.0.113.20", lease!.ProviderIp);
        Assert.Equal("line-password", Encoding.UTF8.GetString(lease.Password));
    }

    [Theory]
    [InlineData(LeaseSealing.TamperedCiphertext)]
    [InlineData(LeaseSealing.WrongLeaseId)]
    [InlineData(LeaseSealing.OtherDeviceKey)]
    public async Task UltraLease_ThatDoesNotOpenFailsWithUnsealCode(LeaseSealing sealing)
    {
        using var keyDirectory = new TempStateDirectory();
        var store = new UltraLeaseKeyStore(keyDirectory.Path);
        var generation = new string('a', 64);
        var handler = new LeaseHandler(store, (key, request) => UltraLeaseResponse(generation, key, sealing));
        using var http = new HttpClient(handler) { Timeout = TimeSpan.FromSeconds(5) };
        using var api = CreateClient(http, store);

        var error = await Assert.ThrowsAsync<MasterApiException>(
            () => api.ResolveUltraLeasesAutoAsync(CreateUltraForward(generation)));

        Assert.Equal(UltraLeaseSeal.UnsealFailedCode, error.ErrorCode);
        Assert.DoesNotContain("line-password", error.Message, StringComparison.Ordinal);
    }

    [Fact]
    public async Task UltraLease_KeyConflictSurfacesCodeAndKeepsKey()
    {
        using var keyDirectory = new TempStateDirectory();
        var store = new UltraLeaseKeyStore(keyDirectory.Path);
        var before = store.GetOrCreate().HeaderValue;
        var generation = new string('a', 64);
        var handler = new LeaseHandler(store, (key, request) => new HttpResponseMessage(HttpStatusCode.Conflict)
        {
            Content = new StringContent("{\"ok\":false,\"error\":\"lease_device_key_conflict\"}", Encoding.UTF8, "application/json"),
        });
        using var http = new HttpClient(handler) { Timeout = TimeSpan.FromSeconds(5) };
        using var api = CreateClient(http, store);

        var error = await Assert.ThrowsAsync<MasterApiException>(
            () => api.ResolveUltraLeasesAutoAsync(CreateUltraForward(generation)));

        Assert.Equal(UltraLeaseSeal.DeviceKeyConflictCode, error.ErrorCode);
        Assert.Equal(409, error.StatusCode);
        Assert.Equal(before, store.GetOrCreate().HeaderValue);
        Assert.Equal(before, new UltraLeaseKeyStore(keyDirectory.Path).GetOrCreate().HeaderValue);
    }

    [Fact]
    public async Task NativeOutboundLease_SendsKeyHeaderAndOpensSealedMaterial()
    {
        using var keyDirectory = new TempStateDirectory();
        var store = new UltraLeaseKeyStore(keyDirectory.Path);
        const int forwardId = 158;
        const string direction = "Ultra Hong Kong to Mainland China";
        const string routeScope = "cn_only";
        var generation = new string('a', 64);
        var handler = new LeaseHandler(store, (key, request) => NativeLeaseResponse(forwardId, direction, generation, routeScope, key));
        using var http = new HttpClient(handler) { Timeout = TimeSpan.FromSeconds(5) };
        using var api = CreateClient(http, store);

        using var lease = await api.ResolveNativeOutboundLeaseAsync(
            CreateNativeForward(forwardId, direction, generation),
            routeScope);

        Assert.NotNull(lease);
        Assert.Equal(store.GetOrCreate().HeaderValue, handler.LastLeaseKeyHeader);
        Assert.Equal("203.0.113.20", lease!.EndpointHost);
        Assert.Equal(5438, lease.EndpointPort);
    }

    [Fact]
    public void KeyStore_PersistsKeyAcrossInstances()
    {
        using var keyDirectory = new TempStateDirectory();
        var first = new UltraLeaseKeyStore(keyDirectory.Path);
        var header = first.GetOrCreate().HeaderValue;

        var second = new UltraLeaseKeyStore(keyDirectory.Path);

        Assert.Equal(header, second.GetOrCreate().HeaderValue);
        Assert.True(first.IsPersisted);
        Assert.True(second.IsPersisted);
        Assert.Same(second.GetOrCreate(), second.GetOrCreate());
        Assert.Same(UltraLeaseKeyStore.ForDirectory(keyDirectory.Path), UltraLeaseKeyStore.ForDirectory(keyDirectory.Path));
        var point = UltraLeaseSeal.Base64UrlDecode(header)!;
        Assert.Equal(65, point.Length);
        Assert.Equal(0x04, point[0]);
    }

    [Fact]
    public void KeyStore_DoesNotStoreThePrivateKeyInClearOnWindows()
    {
        using var keyDirectory = new TempStateDirectory();
        var store = new UltraLeaseKeyStore(keyDirectory.Path);
        var key = store.GetOrCreate();
        var stored = File.ReadAllText(store.KeyPath);
        var pkcs8 = Convert.ToBase64String(key.ExportPkcs8());

        Assert.DoesNotContain(pkcs8, stored, StringComparison.Ordinal);
        if (OperatingSystem.IsWindows())
        {
            Assert.StartsWith("LWLEASEKEY-DPAPI-1:", stored, StringComparison.Ordinal);
            Assert.Contains(System.Security.Principal.WindowsIdentity.GetCurrent().User!.Value, store.KeyPath, StringComparison.Ordinal);
        }
        else
        {
            Assert.Equal(UnixFileMode.UserRead | UnixFileMode.UserWrite, File.GetUnixFileMode(store.KeyPath));
        }
    }

    [Fact]
    public void KeyStore_ReplacesAnUnreadableKey()
    {
        using var keyDirectory = new TempStateDirectory();
        var original = new UltraLeaseKeyStore(keyDirectory.Path);
        var originalHeader = original.GetOrCreate().HeaderValue;
        File.WriteAllText(original.KeyPath, "LWLEASEKEY-DPAPI-1:bm90LWEta2V5");

        var replacement = new UltraLeaseKeyStore(keyDirectory.Path);
        var replacementHeader = replacement.GetOrCreate().HeaderValue;

        Assert.NotEqual(originalHeader, replacementHeader);
        Assert.True(replacement.IsPersisted);
        Assert.Equal(replacementHeader, new UltraLeaseKeyStore(keyDirectory.Path).GetOrCreate().HeaderValue);
    }

    public enum LeaseSealing
    {
        Clear,
        Sealed,
        TamperedCiphertext,
        WrongLeaseId,
        OtherDeviceKey,
    }

    private static MasterApiClient CreateClient(HttpClient http, UltraLeaseKeyStore store)
        => new(
            "https://v3.leikwanhost.com",
            "agent-token",
            "windows-client-42-device",
            httpClientFactory: () => http,
            ownsHttpClient: false,
            leaseKeyStore: store);

    private static ForwardConfig CreateUltraForward(string generation)
    {
        var now = DateTimeOffset.UtcNow;
        return new ForwardConfig
        {
            ForwardId = 42,
            BackendMode = "ultra",
            Mode = "single",
            Target = Target,
            Transport = "tcp",
            UltraAccess = new UltraPolicy
            {
                ProtocolValue = UltraPolicy.Protocol,
                ForwardId = 42,
                PolicyGeneration = generation,
                SessionTicket = "ticket-a",
                IssuedAt = now,
                RenewAfter = now.AddMinutes(1),
                ExpiresAt = now.AddMinutes(4),
                ServiceState = "active",
                Transport = "tcp",
                RateLimitMbps = 20,
                QuotaRemainingBytes = 1024 * 1024,
                TargetDigest = UltraPolicy.ComputeTargetDigest(Target),
            },
        };
    }

    private static ForwardConfig CreateNativeForward(int forwardId, string direction, string generation)
    {
        var now = DateTimeOffset.UtcNow;
        return new ForwardConfig
        {
            ForwardId = forwardId,
            BackendMode = "ultra",
            Target = Target,
            Transport = "tcp+udp",
            UltraAccess = new UltraPolicy
            {
                ProtocolValue = UltraPolicy.Protocol,
                ForwardId = forwardId,
                PolicyGeneration = generation,
                SessionTicket = "ticket-native",
                IssuedAt = now,
                RenewAfter = now.AddMinutes(1),
                ExpiresAt = now.AddMinutes(4),
                ServiceState = "active",
                Transport = "tcp+udp",
                QuotaRemainingBytes = 1024 * 1024,
                TargetDigest = UltraPolicy.ComputeTargetDigest(Target),
            },
            NativeOutbound = new NativeOutboundCapability
            {
                Enabled = true,
                Protocol = "socks5",
                LeaseProtocol = MasterApiClient.NativeOutboundLeaseProtocol,
                Transport = "tcp+udp",
                ForwardIds = [forwardId],
                DefaultForwardId = forwardId,
                RouteScopes = ["cn_only", "global"],
                DefaultRouteScope = "global",
                Forwards =
                [
                    new NativeOutboundForward(
                        forwardId,
                        direction,
                        NativeOutboundLease.ComputeScopeDigest(forwardId, direction, "tcp+udp", "global"),
                        "tcp+udp",
                        ["cn_only", "global"],
                        "global"),
                ],
            },
        };
    }

    private static HttpResponseMessage UltraLeaseResponse(string generation, UltraLeaseDeviceKey deviceKey, LeaseSealing sealing)
    {
        var lease = new JsonObject
        {
            ["protocol"] = "leikwan-ultra-policy-v2",
            ["forward_id"] = 42,
            ["session_ticket"] = "ticket-a",
            ["lease_id"] = "lease-a",
            ["policy_generation"] = generation,
            ["expires_at"] = DateTimeOffset.UtcNow.AddMinutes(3).ToString("yyyy-MM-dd'T'HH:mm:ss'Z'"),
            ["transport"] = "tcp",
            ["rate_limit_mbps"] = 20,
            ["quota_remaining_bytes"] = 1048576,
            ["target_digest"] = UltraPolicy.ComputeTargetDigest(Target),
            ["selection_ref"] = "rrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrr",
            ["nonce_hash"] = new string('b', 64),
        };
        var secret = new JsonObject
        {
            ["endpoint"] = new JsonObject { ["ip"] = "203.0.113.20", ["port"] = 5438 },
            ["credential"] = new JsonObject { ["user"] = "line-user", ["password"] = "line-password" },
        };
        return LeaseBatch(lease, secret, deviceKey, sealing);
    }

    private static HttpResponseMessage NativeLeaseResponse(
        int forwardId,
        string direction,
        string generation,
        string routeScope,
        UltraLeaseDeviceKey deviceKey)
    {
        var lease = new JsonObject
        {
            ["protocol"] = MasterApiClient.NativeOutboundLeaseProtocol,
            ["purpose"] = "native_outbound",
            ["forward_id"] = forwardId,
            ["lease_id"] = "native-lease-a",
            ["policy_generation"] = generation,
            ["expires_at"] = DateTimeOffset.UtcNow.AddMinutes(3).ToString("yyyy-MM-dd'T'HH:mm:ss'Z'"),
            ["transport"] = "tcp+udp",
            ["direction"] = direction,
            ["route_scope"] = routeScope,
            ["route_policy"] = "restricted",
            ["scope_digest"] = NativeOutboundLease.ComputeScopeDigest(forwardId, direction, "tcp+udp", routeScope),
        };
        var secret = new JsonObject
        {
            ["endpoint"] = new JsonObject { ["ip"] = "203.0.113.20", ["port"] = 5438 },
            ["credential"] = new JsonObject { ["user"] = "native-user", ["password"] = "native-password" },
        };
        return LeaseBatch(lease, secret, deviceKey, LeaseSealing.Sealed);
    }

    private static HttpResponseMessage LeaseBatch(JsonObject lease, JsonObject secret, UltraLeaseDeviceKey deviceKey, LeaseSealing sealing)
    {
        var root = new JsonObject { ["ok"] = true };
        if (sealing == LeaseSealing.Clear)
        {
            foreach (var property in secret.ToList())
            {
                secret.Remove(property.Key);
                lease[property.Key] = property.Value;
            }
        }
        else
        {
            using var otherKey = UltraLeaseDeviceKey.Generate();
            var sealTo = sealing == LeaseSealing.OtherDeviceKey ? otherKey : deviceKey;
            var aadLeaseId = (string) lease["lease_id"]! + (sealing == LeaseSealing.WrongLeaseId ? "-other" : "");
            var envelope = Seal(Encoding.UTF8.GetBytes(secret.ToJsonString()), sealTo.PublicKey, aadLeaseId);
            if (sealing == LeaseSealing.TamperedCiphertext)
            {
                var ct = UltraLeaseSeal.Base64UrlDecode((string) envelope["ct"]!)!;
                ct[0] ^= 0x80;
                envelope["ct"] = UltraLeaseSeal.Base64UrlEncode(ct);
            }
            lease["sealed"] = envelope;
            root["lease_seal"] = new JsonObject { ["v"] = 1, ["kid"] = envelope["kid"]!.GetValue<string>() };
        }
        root["leases"] = new JsonArray(lease);
        return new HttpResponseMessage(HttpStatusCode.OK)
        {
            Content = new StringContent(root.ToJsonString(), Encoding.UTF8, "application/json"),
        };
    }

    // Mirror of UltraLeaseSealer::seal on the panel.
    private static JsonObject Seal(byte[] plaintext, byte[] devicePublic, string leaseId)
    {
        using var ephemeral = ECDiffieHellman.Create(ECCurve.NamedCurves.nistP256);
        using var device = ECDiffieHellman.Create(new ECParameters
        {
            Curve = ECCurve.NamedCurves.nistP256,
            Q = new ECPoint { X = devicePublic[1..33], Y = devicePublic[33..] },
        });
        var parameters = ephemeral.ExportParameters(false);
        var epk = new byte[65];
        epk[0] = 0x04;
        parameters.Q.X!.CopyTo(epk, 1);
        parameters.Q.Y!.CopyTo(epk, 33);
        var shared = ephemeral.DeriveRawSecretAgreement(device.PublicKey);
        var key = HKDF.DeriveKey(
            HashAlgorithmName.SHA256,
            shared,
            32,
            [.. epk, .. devicePublic],
            Encoding.UTF8.GetBytes(UltraLeaseSeal.Info));
        var iv = RandomNumberGenerator.GetBytes(12);
        var ciphertext = new byte[plaintext.Length];
        var tag = new byte[16];
        using (var aes = new AesGcm(key, 16))
            aes.Encrypt(iv, plaintext, ciphertext, tag, Encoding.UTF8.GetBytes(UltraLeaseSeal.Info + "|" + leaseId));
        return new JsonObject
        {
            ["v"] = 1,
            ["alg"] = UltraLeaseSeal.Algorithm,
            ["kid"] = Convert.ToHexString(SHA256.HashData(devicePublic)).ToLowerInvariant()[..32],
            ["epk"] = UltraLeaseSeal.Base64UrlEncode(epk),
            ["iv"] = UltraLeaseSeal.Base64UrlEncode(iv),
            ["ct"] = UltraLeaseSeal.Base64UrlEncode([.. ciphertext, .. tag]),
        };
    }

    private sealed class LeaseHandler(
        UltraLeaseKeyStore store,
        Func<UltraLeaseDeviceKey, HttpRequestMessage, HttpResponseMessage> respond) : HttpMessageHandler
    {
        public string? LastLeaseKeyHeader { get; private set; }

        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            Assert.Equal("/api/client/ultra/lease", request.RequestUri?.AbsolutePath);
            LastLeaseKeyHeader = request.Headers.TryGetValues(UltraLeaseDeviceKey.HeaderName, out var values)
                ? values.Single()
                : null;
            Assert.Equal(store.GetOrCreate().HeaderValue, LastLeaseKeyHeader);
            var response = respond(store.GetOrCreate(), request);
            response.RequestMessage = request;
            return Task.FromResult(response);
        }
    }
}
