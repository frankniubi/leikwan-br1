using System.Net.Http.Headers;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using LeikwanBridge.Core.Models;
using Serilog;

namespace LeikwanBridge.Core.Services;

public sealed class MasterApiClient : IDisposable
{
    public const string AgentVersion = "2026.04.09.1";
    public const string NativeOutboundLeaseProtocol = "leikwan-ultra-native-socks5-v1";

    private readonly HttpClient _http;
    private readonly string _masterUrl;
    private readonly string _agentToken;
    private readonly string _deviceId;
    private readonly bool _ownsHttpClient;
    private readonly Action<HttpRequestMessage>? _onRequestForTest;
    private readonly ControlPlaneEndpointSupervisor _endpointSupervisor;
    private readonly ExternalUotPolicyGrantCache _externalUotPolicyGrants = new();

    public string LastErrorMessage { get; private set; } = "";

    /// <summary>Set when the last TestConnectionAsync was refused by the online device limit.</summary>
    public DeviceLimitReachedException? LastDeviceLimit { get; private set; }

    public Uri CurrentControlPlaneEndpoint => _endpointSupervisor.CurrentEndpoint;

    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower,
        DefaultIgnoreCondition = System.Text.Json.Serialization.JsonIgnoreCondition.WhenWritingNull,
    };

    public MasterApiClient(
        string masterUrl,
        string agentToken,
        string deviceId = "",
        string deviceName = "",
        string devicePlatform = "",
        Func<HttpClient>? httpClientFactory = null,
        bool ownsHttpClient = true,
        Action<HttpRequestMessage>? onRequestForTest = null,
        ClientPlatformIdentity? platformIdentity = null,
        IReadOnlyList<string>? fallbackEndpoints = null)
    {
        if (platformIdentity is not null)
        {
            deviceId = platformIdentity.DeviceId;
            deviceName = platformIdentity.DeviceName;
            devicePlatform = platformIdentity.Platform;
        }
        _masterUrl = masterUrl.TrimEnd('/');
        _agentToken = agentToken;
        _deviceId = deviceId.Trim();
        _http = httpClientFactory?.Invoke() ?? LeikwanHttpClientFactory.CreateFor(new Uri(_masterUrl));
        _ownsHttpClient = ownsHttpClient;
        _onRequestForTest = onRequestForTest;
        _endpointSupervisor = new ControlPlaneEndpointSupervisor(
            _masterUrl,
            fallbackEndpoints ?? DefaultFallbackEndpoints(_masterUrl),
            (request, ct) => _http.SendAsync(request, ct));
        _http.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", _agentToken);
        var userAgentProduct = platformIdentity?.UserAgentProduct ?? "Leikwan-Windows-GUI";
        var appVersion = platformIdentity?.AppVersion ?? AgentVersion;
        _http.DefaultRequestHeaders.UserAgent.ParseAdd($"{userAgentProduct}/{appVersion}");
        _http.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        AddOptionalHeader("X-Device-Id", deviceId);
        AddOptionalHeader("X-Device-Name", deviceName);
        AddOptionalHeader("X-Device-Platform", devicePlatform);
    }

    public async Task<DesktopLoginResponse> DesktopLoginAsync(string email, string password, CancellationToken ct = default)
    {
        var url = $"{_masterUrl}/api/client/login";
        var payload = new { email, password };
        var json = JsonSerializer.Serialize(payload, JsonOptions);
        using var content = new StringContent(json, Encoding.UTF8, "application/json");
        using var request = new HttpRequestMessage(HttpMethod.Post, url) { Content = content };
        var response = await SendAsync(request, ct);
        response.EnsureSuccessStatusCode();
        var body = await response.Content.ReadAsStringAsync(ct);
        return JsonSerializer.Deserialize<DesktopLoginResponse>(body, JsonOptions)
               ?? throw new InvalidOperationException("null desktop login response");
    }

    public async Task<ClientSelectionResponse> SelectClientAsync(string sessionToken, int clientId, CancellationToken ct = default)
    {
        var url = $"{_masterUrl}/api/client/select";
        var payload = new { session_token = sessionToken, client_id = clientId };
        var json = JsonSerializer.Serialize(payload, JsonOptions);
        using var content = new StringContent(json, Encoding.UTF8, "application/json");
        using var request = new HttpRequestMessage(HttpMethod.Post, url) { Content = content };
        var response = await SendAsync(request, ct);
        response.EnsureSuccessStatusCode();
        var body = await response.Content.ReadAsStringAsync(ct);
        return JsonSerializer.Deserialize<ClientSelectionResponse>(body, JsonOptions)
               ?? throw new InvalidOperationException("null client selection response");
    }

    public async Task<PullResponse> PullConfigsAsync(string? sinceRevision = null, int waitSeconds = 0, CancellationToken ct = default)
    {
        var query = new List<string>();
        if (!string.IsNullOrWhiteSpace(sinceRevision))
            query.Add($"since_revision={Uri.EscapeDataString(sinceRevision)}");
        if (waitSeconds > 0)
            query.Add($"wait_seconds={waitSeconds}");

        var url = $"{_masterUrl}/api/client/configs/pull";
        if (query.Count > 0)
            url += "?" + string.Join("&", query);

        Log.Debug("pulling configs from {Url}", url);
        using var request = new HttpRequestMessage(HttpMethod.Get, url);
        AddOfflineValidityHeader(request);
        var response = await SendAsync(request, ct);

        if (response.StatusCode == System.Net.HttpStatusCode.NotModified)
            return new PullResponse { Ok = true, NotModified = true, ConfigRevision = sinceRevision ?? "" };

        await EnsureSuccessOrThrowAsync(response, ct);
        var json = await response.Content.ReadAsStringAsync(ct);
        try
        {
            var result = JsonSerializer.Deserialize<PullResponse>(json, JsonOptions)
                ?? throw new MasterApiException((int) response.StatusCode, "master_api_empty_response");
            if (result.Ok && !result.NotModified)
            {
                using var document = JsonDocument.Parse(json);
                _externalUotPolicyGrants.Replace(result, document.RootElement);
            }
            return result;
        }
        catch (JsonException ex)
        {
            var contentType = response.Content.Headers.ContentType?.MediaType ?? "unknown";
            throw new MasterApiException(
                (int) response.StatusCode,
                $"master_api_invalid_json_response (HTTP {(int) response.StatusCode}, content-type {contentType})",
                ex);
        }
    }

    public async Task<UltraLease?> ResolveUltraLeaseAsync(ForwardConfig forward, CancellationToken ct = default)
    {
        var leases = await ResolveUltraLeasesCoreAsync(forward, autoSelect: false, ct);
        var first = leases.FirstOrDefault();
        foreach (var lease in leases.Skip(1))
            lease.Dispose();
        return first;
    }

    /// <summary>
    /// Resolves the short-lived native SOCKS5 lease for an entitled Ultra
    /// carrier. The capability is supplied by the pull response and is kept
    /// out of the serialized forward state; the panel remains the authority
    /// for the actual endpoint and credentials.
    /// </summary>
    public Task<NativeOutboundLease?> ResolveNativeOutboundLeaseAsync(
        ForwardConfig forward,
        string routeScope,
        CancellationToken ct = default)
        => ResolveNativeOutboundLeaseAsync(forward, forward.NativeOutbound, routeScope, "restricted", ct);

    public Task<NativeOutboundLease?> ResolveNativeOutboundLeaseAsync(
        ForwardConfig forward,
        NativeOutboundCapability? capability,
        string routeScope,
        CancellationToken ct = default)
        => ResolveNativeOutboundLeaseAsync(forward, capability, routeScope, "restricted", ct);

    public async Task<NativeOutboundLease?> ResolveNativeOutboundLeaseAsync(
        ForwardConfig forward,
        NativeOutboundCapability? capability,
        string routeScope,
        string routePolicy,
        CancellationToken ct = default)
    {
        var leases = await ResolveNativeOutboundLeasesAsync(
            forward,
            capability,
            routeScope,
            routePolicy,
            autoSelect: false,
            ct: ct).ConfigureAwait(false);
        if (leases.Count == 0)
            return null;
        var first = leases[0];
        foreach (var lease in leases.Skip(1))
            lease.Dispose();
        return first;
    }

    public Task<IReadOnlyList<NativeOutboundLease>> ResolveNativeOutboundLeasesAsync(
        ForwardConfig forward,
        NativeOutboundCapability? capability,
        string routeScope,
        string routePolicy,
        CancellationToken ct = default)
        => ResolveNativeOutboundLeasesAsync(
            forward,
            capability,
            routeScope,
            routePolicy,
            autoSelect: true,
            ct: ct);

    /// <summary>
    /// Pulls an uncached policy snapshot immediately before requesting a
    /// native lease batch. Ultra session tickets rotate independently of the
    /// stable config revision, so a UI-held ForwardConfig can remain otherwise
    /// current while its ticket has already been replaced by the Panel.
    /// </summary>
    public async Task<IReadOnlyList<NativeOutboundLease>> ResolveFreshNativeOutboundLeasesAsync(
        int forwardId,
        string routeScope,
        string routePolicy,
        CancellationToken ct = default)
    {
        if (forwardId <= 0)
            return [];

        var pull = await PullConfigsAsync(ct: ct).ConfigureAwait(false);
        if (!pull.Ok || pull.NotModified || pull.NativeOutbound is not { Enabled: true } capability)
            return [];
        var forward = pull.Forwards.FirstOrDefault(item => item.ForwardId == forwardId);
        if (forward is null)
            return [];

        forward.NativeOutbound = capability;
        return await ResolveNativeOutboundLeasesAsync(
            forward,
            capability,
            routeScope,
            routePolicy,
            autoSelect: true,
            ct: ct).ConfigureAwait(false);
    }

    private async Task<IReadOnlyList<NativeOutboundLease>> ResolveNativeOutboundLeasesAsync(
        ForwardConfig forward,
        NativeOutboundCapability? capability,
        string routeScope,
        string routePolicy,
        bool autoSelect,
        CancellationToken ct)
    {
        ArgumentNullException.ThrowIfNull(forward);
        var policy = forward.UltraAccess;
        var carrier = capability?.FindForward(forward.ForwardId);
        var normalizedScope = routeScope.Trim().ToLowerInvariant();
        var normalizedPolicy = NativeOutboundLease.NormalizeRoutePolicy(routePolicy);
        if (policy is null
            || capability is null
            || !capability.Enabled
            || !string.Equals(capability.Protocol, "socks5", StringComparison.OrdinalIgnoreCase)
            || !string.Equals(capability.LeaseProtocol, NativeOutboundLeaseProtocol, StringComparison.Ordinal)
            || !string.Equals(capability.Transport, "tcp+udp", StringComparison.OrdinalIgnoreCase)
            || carrier is null
            || !carrier.RouteScopes.Contains(normalizedScope, StringComparer.OrdinalIgnoreCase)
            || !string.Equals(carrier.Transport, "tcp+udp", StringComparison.OrdinalIgnoreCase)
            || string.IsNullOrWhiteSpace(carrier.Direction)
            || (normalizedPolicy == "unrestricted" && !carrier.UnrestrictedAllowed)
            || policy.GetValidationFailure(forward, DateTimeOffset.UtcNow) is not null)
            return [];

        var expectedDigest = normalizedPolicy == "unrestricted"
            ? NativeOutboundLease.ComputeScopeDigestV2(
                forward.ForwardId,
                carrier.Direction,
                carrier.Transport,
                normalizedScope,
                normalizedPolicy)
            : NativeOutboundLease.ComputeScopeDigest(
                forward.ForwardId,
                carrier.Direction,
                carrier.Transport,
                normalizedScope);
        var nonce = Convert.ToHexString(RandomNumberGenerator.GetBytes(16)).ToLowerInvariant();
        var payload = new
        {
            device_id = _deviceId,
            forwards = new[]
            {
                new
                {
                    purpose = "native_outbound",
                    forward_id = forward.ForwardId,
                    session_ticket = policy.SessionTicket,
                    policy_generation = policy.PolicyGeneration,
                    nonce,
                    target_digest = expectedDigest,
                    transport = "tcp+udp",
                    route_scope = normalizedScope,
                    route_policy = normalizedPolicy,
                    selection_mode = autoSelect ? "auto" : "single",
                    max_candidates = autoSelect ? 4 : 1,
                },
            },
        };

        byte[] requestBytes = [];
        try
        {
            requestBytes = JsonSerializer.SerializeToUtf8Bytes(payload, JsonOptions);
            using var content = new ByteArrayContent(requestBytes);
            content.Headers.ContentType = new MediaTypeHeaderValue("application/json");
            using var request = new HttpRequestMessage(
                HttpMethod.Post,
                $"{_masterUrl}/api/client/ultra/lease")
            {
                Content = content,
            };
            var response = await SendAsync(request, ct);
            await EnsureSuccessOrThrowAsync(response, ct);
            var responseBytes = await response.Content.ReadAsByteArrayAsync(ct);
            try
            {
                using var document = JsonDocument.Parse(responseBytes);
                if (!document.RootElement.TryGetProperty("leases", out var leases)
                    || leases.ValueKind != JsonValueKind.Array
                    || leases.GetArrayLength() is < 1 or > 4)
                    return [];
                var parsed = new List<NativeOutboundLease>(Math.Min(leases.GetArrayLength(), autoSelect ? 4 : 1));
                try
                {
                    foreach (var item in leases.EnumerateArray().Take(autoSelect ? 4 : 1))
                    {
                        var parsedLease = ParseNativeOutboundLease(
                            item,
                            forward,
                            policy,
                            carrier,
                            normalizedScope,
                            normalizedPolicy,
                            expectedDigest);
                        if (parsedLease is null)
                        {
                            foreach (var existing in parsed)
                                existing.Dispose();
                            return [];
                        }
                        parsed.Add(parsedLease);
                    }
                    return parsed;
                }
                catch
                {
                    foreach (var existing in parsed)
                        existing.Dispose();
                    throw;
                }
            }
            finally
            {
                CryptographicOperations.ZeroMemory(responseBytes);
            }
        }
        catch (JsonException)
        {
            return [];
        }
        catch (KeyNotFoundException)
        {
            return [];
        }
        catch (InvalidOperationException)
        {
            return [];
        }
        catch (FormatException)
        {
            return [];
        }
        catch (OverflowException)
        {
            return [];
        }
        finally
        {
            CryptographicOperations.ZeroMemory(requestBytes);
        }
    }

    /// <summary>
    /// Resolves one stable Ultra lease for callers that explicitly require a
    /// single candidate. Desktop production polling uses the bounded auto
    /// selection method below and keeps the chosen path sticky in the runtime.
    /// </summary>
    public Task<IReadOnlyList<UltraLease>> ResolveUltraLeasesAsync(ForwardConfig forward, CancellationToken ct = default)
        => ResolveUltraLeasesCoreAsync(forward, autoSelect: false, ct);

    /// <summary>
    /// Resolves a bounded candidate batch for the runtime's initial quality
    /// selection. The runtime activates only one candidate per generation.
    /// </summary>
    public Task<IReadOnlyList<UltraLease>> ResolveUltraLeasesAutoAsync(ForwardConfig forward, CancellationToken ct = default)
        => ResolveUltraLeasesCoreAsync(forward, autoSelect: true, ct);

    private async Task<IReadOnlyList<UltraLease>> ResolveUltraLeasesCoreAsync(
        ForwardConfig forward,
        bool autoSelect,
        CancellationToken ct)
    {
        var policy = forward.UltraAccess;
        if (policy is null)
        {
            Log.Warning("Ultra lease skipped for forward {ForwardId}; policy missing", forward.ForwardId);
            return [];
        }

        var policyFailure = policy.GetValidationFailure(forward, DateTimeOffset.UtcNow);
        if (policyFailure is not null)
        {
            Log.Warning(
                "Ultra lease skipped for forward {ForwardId}; policy invalid ({Reason}, protocol={Protocol}, expiry={ExpiresAt}, quota={Quota}, transport={Transport})",
                forward.ForwardId,
                policyFailure,
                policy.ProtocolValue,
                policy.ExpiresAt,
                policy.QuotaRemainingBytes,
                 policy.Transport);
            return [];
        }
        var nonce = Convert.ToHexString(RandomNumberGenerator.GetBytes(16)).ToLowerInvariant();
        var payload = new
        {
            device_id = _deviceId,
            forwards = new[]
            {
                new
                {
                    forward_id = forward.ForwardId,
                    session_ticket = policy.SessionTicket,
                    policy_generation = policy.PolicyGeneration,
                    nonce,
                    target_digest = policy.TargetDigest,
                    transport = policy.Transport,
                    selection_mode = autoSelect ? "auto" : "single",
                    max_candidates = autoSelect ? 4 : 1,
                },
            },
        };
        byte[] requestBytes = [];
        try
        {
            requestBytes = JsonSerializer.SerializeToUtf8Bytes(payload, JsonOptions);
            using var content = new ByteArrayContent(requestBytes);
            content.Headers.ContentType = new MediaTypeHeaderValue("application/json");
            using var request = new HttpRequestMessage(HttpMethod.Post, $"{_masterUrl}/api/client/ultra/lease") { Content = content };
            AddOfflineValidityHeader(request);
            var response = await SendAsync(request, ct);
            await EnsureSuccessOrThrowAsync(response, ct);
            var responseBytes = await response.Content.ReadAsByteArrayAsync(ct);
            try
            {
                using var document = JsonDocument.Parse(responseBytes);
                var maxCandidates = autoSelect ? 4 : 1;
                if (!document.RootElement.TryGetProperty("leases", out var leases)
                    || leases.ValueKind != JsonValueKind.Array
                    || leases.GetArrayLength() is < 1
                    || leases.GetArrayLength() > 4)
                    return [];
                var parsed = new List<UltraLease>(Math.Min(leases.GetArrayLength(), maxCandidates));
                try
                {
                    foreach (var item in leases.EnumerateArray().Take(maxCandidates))
                    {
                        var lease = ParseUltraLease(item, forward, policy);
                        if (lease is null || !_externalUotPolicyGrants.TryAttach(forward, lease))
                        {
                            lease?.Dispose();
                            foreach (var parsedLease in parsed)
                                parsedLease.Dispose();
                            return [];
                        }
                        parsed.Add(lease);
                    }
                }
                catch (Exception ex) when (ex is JsonException
                    or KeyNotFoundException
                    or InvalidOperationException
                    or FormatException
                    or OverflowException)
                {
                    foreach (var parsedLease in parsed)
                        parsedLease.Dispose();
                    return [];
                }
                return parsed;
            }
            finally
            {
                CryptographicOperations.ZeroMemory(responseBytes);
            }
        }
        catch (JsonException)
        {
            return [];
        }
        catch (KeyNotFoundException)
        {
            return [];
        }
        catch (InvalidOperationException)
        {
            return [];
        }
        catch (FormatException)
        {
            return [];
        }
        catch (OverflowException)
        {
            return [];
        }
        finally
        {
            CryptographicOperations.ZeroMemory(requestBytes);
        }
    }

    private static UltraLease? ParseUltraLease(JsonElement item, ForwardConfig forward, UltraPolicy policy)
    {
        var returnedForward = item.GetProperty("forward_id").GetInt32();
        var returnedGeneration = item.GetProperty("policy_generation").GetString() ?? "";
        var returnedTicket = item.GetProperty("session_ticket").GetString() ?? "";
        var protocol = item.GetProperty("protocol").GetString() ?? "";
        var targetDigest = item.GetProperty("target_digest").GetString() ?? "";
        var transport = item.GetProperty("transport").GetString() ?? "";
        var selectionTier = UltraLease.SelectionTierUnknown;
        if (item.TryGetProperty("selection_tier", out var selectionTierElement))
        {
            selectionTier = (selectionTierElement.GetString() ?? "").Trim().ToLowerInvariant();
            if (!UltraLease.IsSelectionTier(selectionTier))
                return null;
        }
        if (!string.Equals(protocol, UltraPolicy.Protocol, StringComparison.Ordinal)
            || returnedForward != forward.ForwardId
            || !string.Equals(returnedGeneration, policy.PolicyGeneration, StringComparison.Ordinal)
            || !string.Equals(returnedTicket, policy.SessionTicket, StringComparison.Ordinal)
            || !string.Equals(targetDigest, policy.TargetDigest, StringComparison.Ordinal)
            || !string.Equals(transport, policy.Transport, StringComparison.OrdinalIgnoreCase))
            return null;
        var endpoint = item.GetProperty("endpoint");
        var credential = item.GetProperty("credential");
        var candidateKeyHash = item.TryGetProperty("candidate_key_hash", out var candidateHashElement)
            ? (candidateHashElement.GetString() ?? "").Trim().ToLowerInvariant()
            : "";
        if (candidateKeyHash.Length != 0
            && (candidateKeyHash.Length != 64
                || candidateKeyHash.Any(character => character is < '0' or > '9' and < 'a' or > 'f')))
            return null;
        // An enabled but unusable sidecar grant invalidates the lease. Dropping
        // it would silently select native UDP instead of the required UoT route.
        ExternalUotGrant? externalUot = null;
        if (item.TryGetProperty("external_uot", out var externalUotElement))
        {
            externalUot = ExternalUotGrant.TryParse(externalUotElement, out var externalUotFailure);
            if (externalUot is null && externalUotFailure.Length != 0)
            {
                Log.Warning(
                    "Ultra lease for forward {ForwardId} rejected an unusable external_uot grant ({Reason})",
                    forward.ForwardId,
                    externalUotFailure);
                return null;
            }
        }
        var policyExpiresAt = item.TryGetProperty("policy_expires_at", out var policyExpiresAtElement)
            ? policyExpiresAtElement.GetDateTimeOffset()
            : default;
        var policyRenewAfter = item.TryGetProperty("policy_renew_after", out var policyRenewAfterElement)
            ? policyRenewAfterElement.GetDateTimeOffset()
            : default;
        if (policyExpiresAt > policy.ExpiresAt
            && UltraGrantValidity.IsWithinAcceptedWindow(policyExpiresAt, DateTimeOffset.UtcNow)
            && (policyRenewAfter == default || policyRenewAfter < policyExpiresAt))
        {
            forward.UltraAccess = new UltraPolicy
            {
                ProtocolValue = policy.ProtocolValue,
                ForwardId = policy.ForwardId,
                PolicyGeneration = policy.PolicyGeneration,
                SessionTicket = policy.SessionTicket,
                IssuedAt = policy.IssuedAt,
                RenewAfter = policyRenewAfter == default ? policy.RenewAfter : policyRenewAfter,
                ExpiresAt = policyExpiresAt,
                ServiceState = policy.ServiceState,
                Transport = policy.Transport,
                RateLimitMbps = policy.RateLimitMbps,
                QuotaRemainingBytes = policy.QuotaRemainingBytes,
                TargetDigest = policy.TargetDigest,
            };
        }
        return new UltraLease
        {
            Protocol = protocol,
            LeaseId = item.GetProperty("lease_id").GetString() ?? "",
            SessionTicket = returnedTicket,
            ForwardId = returnedForward,
            PolicyGeneration = returnedGeneration,
            TargetDigest = targetDigest,
            ExpiresAt = item.GetProperty("expires_at").GetDateTimeOffset(),
            Transport = transport,
            RateLimitMbps = item.GetProperty("rate_limit_mbps").GetInt64(),
            QuotaRemainingBytes = item.GetProperty("quota_remaining_bytes").GetInt64(),
            SelectionRef = item.GetProperty("selection_ref").GetString() ?? "",
            SelectionTier = selectionTier,
            CandidateKeyHash = candidateKeyHash,
            NonceHash = item.GetProperty("nonce_hash").GetString() ?? "",
            ProviderIp = endpoint.GetProperty("ip").GetString() ?? "",
            ProviderPort = endpoint.GetProperty("port").GetInt32(),
            User = Encoding.UTF8.GetBytes(credential.GetProperty("user").GetString() ?? ""),
            Password = Encoding.UTF8.GetBytes(credential.GetProperty("password").GetString() ?? ""),
            ExternalUot = externalUot,
            PolicyExpiresAt = policyExpiresAt,
            PolicyRenewAfter = policyRenewAfter,
        };
    }

    private static NativeOutboundLease? ParseNativeOutboundLease(
        JsonElement item,
        ForwardConfig forward,
        UltraPolicy policy,
        NativeOutboundForward carrier,
        string routeScope,
        string routePolicy,
        string expectedDigest)
    {
        var protocol = item.GetProperty("protocol").GetString() ?? "";
        var purpose = item.GetProperty("purpose").GetString() ?? "";
        var returnedForward = item.GetProperty("forward_id").GetInt32();
        var generation = item.GetProperty("policy_generation").GetString() ?? "";
        var transport = item.GetProperty("transport").GetString() ?? "";
        var direction = item.GetProperty("direction").GetString() ?? "";
        var returnedScope = item.GetProperty("route_scope").GetString() ?? "";
        var returnedPolicy = item.TryGetProperty("route_policy", out var policyElement)
            ? NativeOutboundLease.NormalizeRoutePolicy(policyElement.GetString())
            : "restricted";
        var scopeDigest = item.GetProperty("scope_digest").GetString() ?? "";
        if (!string.Equals(protocol, NativeOutboundLeaseProtocol, StringComparison.Ordinal)
            || !string.Equals(purpose, "native_outbound", StringComparison.Ordinal)
            || returnedForward != forward.ForwardId
            || !string.Equals(generation, policy.PolicyGeneration, StringComparison.Ordinal)
            || !string.Equals(transport, "tcp+udp", StringComparison.OrdinalIgnoreCase)
            || !string.Equals(direction, carrier.Direction, StringComparison.Ordinal)
            || !string.Equals(returnedScope, routeScope, StringComparison.Ordinal)
            || !string.Equals(returnedPolicy, routePolicy, StringComparison.Ordinal)
            || !string.Equals(scopeDigest, expectedDigest, StringComparison.OrdinalIgnoreCase))
            return null;

        var expiresAt = item.GetProperty("expires_at").GetDateTimeOffset();
        var now = DateTimeOffset.UtcNow;
        if (expiresAt <= now || expiresAt > policy.ExpiresAt.AddSeconds(1)
            || !UltraGrantValidity.IsWithinAcceptedWindow(expiresAt, now))
            return null;

        var endpoint = item.GetProperty("endpoint");
        var endpointHost = endpoint.GetProperty("ip").GetString() ?? "";
        var endpointPort = endpoint.GetProperty("port").GetInt32();
        if (!System.Net.IPAddress.TryParse(endpointHost, out _)
            || endpointPort is < 1 or > 65535)
            return null;

        var credential = item.GetProperty("credential");
        var rateLimit = item.TryGetProperty("rate_limit_mbps", out var rateLimitElement)
            && rateLimitElement.TryGetInt64(out var parsedRateLimit)
            ? parsedRateLimit
            : policy.RateLimitMbps;
        var quota = item.TryGetProperty("quota_remaining_bytes", out var quotaElement)
            && quotaElement.TryGetInt64(out var parsedQuota)
            ? parsedQuota
            : policy.QuotaRemainingBytes;
        var selectionRef = item.TryGetProperty("selection_ref", out var selectionElement)
            ? selectionElement.GetString() ?? ""
            : "native-" + Convert.ToHexString(
                SHA256.HashData(Encoding.UTF8.GetBytes("native-selection\n" + (item.GetProperty("lease_id").GetString() ?? ""))))
                .ToLowerInvariant();
        var selectionTier = item.TryGetProperty("selection_tier", out var tierElement)
            ? tierElement.GetString() ?? UltraLease.SelectionTierUnknown
            : UltraLease.SelectionTierUnknown;
        var candidateKeyHash = item.TryGetProperty("candidate_key_hash", out var candidateHashElement)
            ? (candidateHashElement.GetString() ?? "").Trim().ToLowerInvariant()
            : "";
        if (candidateKeyHash.Length != 0
            && (candidateKeyHash.Length != 64
                || candidateKeyHash.Any(character => character is < '0' or > '9' and < 'a' or > 'f')))
            return null;
        var user = Encoding.UTF8.GetBytes(credential.GetProperty("user").GetString() ?? "");
        var password = Encoding.UTF8.GetBytes(credential.GetProperty("password").GetString() ?? "");
        var lease = new NativeOutboundLease(
            returnedForward,
            item.GetProperty("lease_id").GetString() ?? "",
            generation,
            expiresAt,
            transport,
            direction,
            returnedScope,
            scopeDigest,
            endpointHost,
            endpointPort,
            user,
            password,
            rateLimit,
            quota,
            selectionRef,
            selectionTier,
            returnedPolicy,
            candidateKeyHash);
        if (user.Length is 0 or > 255 || password.Length is 0 or > 255
            || !lease.IsUsable(now))
        {
            lease.Dispose();
            return null;
        }
        return lease;
    }

    public async Task<HeartbeatResponse> SendHeartbeatAsync(HeartbeatPayload payload, CancellationToken ct = default)
    {
        var url = $"{_masterUrl}/api/client/heartbeat";
        var json = JsonSerializer.Serialize(payload, JsonOptions);
        using var content = new StringContent(json, Encoding.UTF8, "application/json");
        using var request = new HttpRequestMessage(HttpMethod.Post, url) { Content = content };
        Log.Debug("sending heartbeat to {Url}", url);
        var response = await SendAsync(request, ct);
        await ThrowIfDeviceLimitReachedAsync(response, ct);
        response.EnsureSuccessStatusCode();
        var body = await response.Content.ReadAsStringAsync(ct);
        if (string.IsNullOrWhiteSpace(body))
            return new HeartbeatResponse { Ok = true };
        return JsonSerializer.Deserialize<HeartbeatResponse>(body, JsonOptions)
               ?? new HeartbeatResponse { Ok = true };
    }

    public async Task SubmitDiagnosticsAsync(string requestToken, List<object> results, string error, CancellationToken ct = default)
    {
        var url = $"{_masterUrl}/api/client/diagnostics/result";
        var payload = new { request_token = requestToken, results, error };
        var json = JsonSerializer.Serialize(payload, JsonOptions);
        using var content = new StringContent(json, Encoding.UTF8, "application/json");
        using var request = new HttpRequestMessage(HttpMethod.Post, url) { Content = content };
        Log.Debug("submitting diagnostics to {Url}", url);
        var response = await SendAsync(request, ct);
        await ThrowIfDeviceLimitReachedAsync(response, ct);
        response.EnsureSuccessStatusCode();
    }

    public async Task SubmitDebugLogChunkAsync(
        string collectionUuid,
        int sequence,
        string sha256,
        string chunkBase64,
        string source,
        CancellationToken ct = default)
    {
        var url = $"{_masterUrl}/api/client/debug/log";
        var payload = new
        {
            collection_uuid = collectionUuid,
            sequence,
            sha256,
            chunk_base64 = chunkBase64,
            source,
        };
        var json = JsonSerializer.Serialize(payload, JsonOptions);
        using var content = new StringContent(json, Encoding.UTF8, "application/json");
        using var request = new HttpRequestMessage(HttpMethod.Post, url) { Content = content };
        Log.Debug("uploading sanitized debug log chunk sequence={Sequence}", sequence);
        var response = await SendAsync(request, ct);
        await EnsureSuccessOrThrowAsync(response, ct);
    }

    public async Task<bool> TestConnectionAsync(CancellationToken ct = default)
    {
        try
        {
            LastErrorMessage = "";
            LastDeviceLimit = null;
            var result = await PullConfigsAsync(ct: ct);
            return result.Ok;
        }
        catch (DeviceLimitReachedException ex)
        {
            // The token was accepted; only the online device limit is full.
            LastErrorMessage = ex.Message;
            LastDeviceLimit = ex;
            Log.Warning("master API test connection refused: online device limit reached ({Active}/{Limit})",
                ex.ActiveOnlineDevices, ex.OnlineDeviceLimit);
            return false;
        }
        catch (MasterApiException ex)
        {
            LastErrorMessage = ex.Message;
            Log.Warning(ex, "master API test connection failed");
            return false;
        }
        catch (Exception ex)
        {
            LastErrorMessage = ex.Message;
            Log.Warning(ex, "master API test connection failed");
            return false;
        }
    }

    public static string BuildWindowsDeviceId(int clientId, string machineName)
    {
        var normalizedMachine = string.IsNullOrWhiteSpace(machineName)
            ? "unknown-machine"
            : machineName.Trim().ToLowerInvariant();
        var hash = Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes($"windows|{clientId}|{normalizedMachine}")))
            .ToLowerInvariant()[..16];
        return $"windows-client-{clientId}-{hash}";
    }

    /// <summary>
    /// Asks the panel for a grant that outlives a short control-plane outage
    /// (W1). Only the ordinary Ultra config pull and lease resolve/renew carry
    /// it: the native-outbound lease keeps today's short grant, and a panel
    /// that does not know the header answers exactly as before. The header is
    /// set on the request, so <see cref="SendAsync"/> copies it to every mirror
    /// attempt.
    /// </summary>
    internal static void AddOfflineValidityHeader(HttpRequestMessage request)
    {
        request.Headers.Remove(UltraGrantValidity.OfflineValidityHeader);
        request.Headers.TryAddWithoutValidation(
            UltraGrantValidity.OfflineValidityHeader,
            UltraGrantValidity.RequestedOfflineValidityHeaderValue);
    }

    private void AddOptionalHeader(string name, string value)
    {
        if (!string.IsNullOrWhiteSpace(value))
            _http.DefaultRequestHeaders.TryAddWithoutValidation(name, value.Trim());
    }

    private async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken ct)
    {
        var requestUri = request.RequestUri
            ?? throw new InvalidOperationException("master_api_request_uri_missing");
        var relativePath = requestUri.PathAndQuery;
        var body = request.Content is null
            ? null
            : await request.Content.ReadAsByteArrayAsync(ct).ConfigureAwait(false);
        var contentHeaders = request.Content?.Headers
            .ToDictionary(header => header.Key, header => header.Value.ToArray(), StringComparer.OrdinalIgnoreCase)
            ?? new Dictionary<string, string[]>(StringComparer.OrdinalIgnoreCase);
        var requestHeaders = request.Headers
            .ToDictionary(header => header.Key, header => header.Value.ToArray(), StringComparer.OrdinalIgnoreCase);

        return await _endpointSupervisor.ExecuteAsync(
            request.Method,
            relativePath,
            endpoint =>
            {
                var next = new HttpRequestMessage(request.Method, new Uri(endpoint, relativePath));
                foreach (var header in requestHeaders)
                    next.Headers.TryAddWithoutValidation(header.Key, header.Value);
                if (body is not null)
                {
                    var content = new ByteArrayContent(body);
                    foreach (var header in contentHeaders)
                        content.Headers.TryAddWithoutValidation(header.Key, header.Value);
                    next.Content = content;
                }
                _onRequestForTest?.Invoke(next);
                return next;
            },
            static (response, _) => Task.FromResult(response),
            ct).ConfigureAwait(false);
    }

    private static IReadOnlyList<string> DefaultFallbackEndpoints(string masterUrl)
    {
        if (Uri.TryCreate(masterUrl, UriKind.Absolute, out var endpoint))
        {
            if (string.Equals(endpoint.Host, "v3.leikwanhost.com", StringComparison.OrdinalIgnoreCase))
                return ["https://api-leikwanbridge.nbwly.com"];
            if (string.Equals(endpoint.Host, "api-leikwanbridge.nbwly.com", StringComparison.OrdinalIgnoreCase))
                return ["https://v3.leikwanhost.com"];
        }
        return [];
    }

    private static async Task EnsureSuccessOrThrowAsync(HttpResponseMessage response, CancellationToken ct)
    {
        if (response.IsSuccessStatusCode)
            return;

        var body = await response.Content.ReadAsStringAsync(ct);
        throw BuildApiException(response, body);
    }

    /// <summary>
    /// Endpoints that historically used EnsureSuccessStatusCode (heartbeat,
    /// diagnostics) keep throwing HttpRequestException for every other failure,
    /// but a refused device must not be reported as a transport error.
    /// </summary>
    private static async Task ThrowIfDeviceLimitReachedAsync(HttpResponseMessage response, CancellationToken ct)
    {
        if (response.IsSuccessStatusCode)
            return;

        var body = await response.Content.ReadAsStringAsync(ct);
        if (TryParseDeviceLimit((int) response.StatusCode, body) is { } limit)
            throw limit;
    }

    private static MasterApiException BuildApiException(HttpResponseMessage response, string body)
    {
        var statusCode = (int) response.StatusCode;
        if (TryParseDeviceLimit(statusCode, body) is { } limit)
            return limit;

        try
        {
            using var document = JsonDocument.Parse(body);
            var root = document.RootElement;
            var error = root.ValueKind == JsonValueKind.Object
                && root.TryGetProperty("error", out var errorElement)
                && errorElement.ValueKind == JsonValueKind.String
                    ? errorElement.GetString() ?? ""
                    : "";
            if (!string.IsNullOrWhiteSpace(error))
                return new MasterApiException(statusCode, error, errorCode: error);
        }
        catch
        {
            // Fall through to HTTP fallback.
        }

        return new MasterApiException(statusCode, $"HTTP {statusCode} {response.ReasonPhrase}".Trim());
    }

    /// <summary>
    /// Recognizes the panel's client_online_limit_reached answer. The counts
    /// are read from the top-level fields first and from the nested details
    /// object that older panels sent.
    /// </summary>
    internal static DeviceLimitReachedException? TryParseDeviceLimit(int statusCode, string body)
    {
        if (string.IsNullOrWhiteSpace(body))
            return null;
        try
        {
            using var document = JsonDocument.Parse(body);
            var root = document.RootElement;
            if (root.ValueKind != JsonValueKind.Object
                || !root.TryGetProperty("error", out var errorElement)
                || errorElement.ValueKind != JsonValueKind.String
                || !string.Equals(errorElement.GetString(), DeviceLimitReachedException.Code, StringComparison.OrdinalIgnoreCase))
                return null;

            var hasDetails = root.TryGetProperty("details", out var details) && details.ValueKind == JsonValueKind.Object;
            var limit = ReadCount(root, "online_device_limit")
                ?? (hasDetails ? ReadCount(details, "online_device_limit") : null)
                ?? 0;
            var active = ReadCount(root, "active_online_devices")
                ?? (hasDetails ? ReadCount(details, "active_online_devices") : null)
                ?? 0;
            return new DeviceLimitReachedException(statusCode, limit, active);
        }
        catch (JsonException)
        {
            return null;
        }
    }

    private static int? ReadCount(JsonElement owner, string name)
    {
        if (!owner.TryGetProperty(name, out var element))
            return null;
        if (element.ValueKind == JsonValueKind.Number && element.TryGetInt32(out var number))
            return Math.Max(0, number);
        if (element.ValueKind == JsonValueKind.String
            && int.TryParse(element.GetString(), System.Globalization.NumberStyles.None,
                System.Globalization.CultureInfo.InvariantCulture, out var parsed))
            return parsed;
        return null;
    }

    public void Dispose()
    {
        _externalUotPolicyGrants.Dispose();
        if (_ownsHttpClient)
            _http.Dispose();
    }
}

public class MasterApiException : Exception
{
    public MasterApiException(int statusCode, string message, Exception? innerException = null, string errorCode = "")
        : base(message, innerException)
    {
        StatusCode = statusCode;
        ErrorCode = errorCode ?? string.Empty;
    }

    public int StatusCode { get; }

    /// <summary>The panel's machine-readable error code, or empty when the body had none.</summary>
    public string ErrorCode { get; }
}

/// <summary>
/// The panel refused this device because the client's online device limit is
/// full (HTTP 429 client_online_limit_reached). Devices that are already
/// online are never refused, so this is a business answer, not an outage: the
/// saved profile stays valid and a slot frees when another device has been
/// silent for about three minutes, is kicked, or the limit is raised.
/// </summary>
public sealed class DeviceLimitReachedException : MasterApiException
{
    public const string Code = "client_online_limit_reached";

    public DeviceLimitReachedException(int statusCode, int onlineDeviceLimit, int activeOnlineDevices)
        : base(statusCode, FormatMessage(activeOnlineDevices, onlineDeviceLimit), errorCode: Code)
    {
        OnlineDeviceLimit = Math.Max(0, onlineDeviceLimit);
        ActiveOnlineDevices = Math.Max(0, activeOnlineDevices);
    }

    /// <summary>The client's online device limit; 0 when the panel did not say.</summary>
    public int OnlineDeviceLimit { get; }

    /// <summary>Devices online for the client when this one was refused; 0 when unknown.</summary>
    public int ActiveOnlineDevices { get; }

    public bool HasCounts => OnlineDeviceLimit > 0;

    /// <summary>English text; the desktop UIs substitute their localized copy.</summary>
    public static string FormatMessage(int activeOnlineDevices, int onlineDeviceLimit)
    {
        var counts = onlineDeviceLimit > 0
            ? $" ({Math.Max(0, activeOnlineDevices)} of {onlineDeviceLimit} devices online)"
            : string.Empty;
        return $"Device limit reached{counts}. Disconnect another device or ask your provider to raise the device limit. "
            + "This device reconnects automatically about 3 minutes after another device disconnects.";
    }

    /// <summary>Finds a device-limit refusal in an exception or its inner exceptions.</summary>
    public static bool TryFind(Exception? exception, [System.Diagnostics.CodeAnalysis.NotNullWhen(true)] out DeviceLimitReachedException? limit)
    {
        for (var depth = 0; exception is not null && depth < 8; depth++)
        {
            if (exception is DeviceLimitReachedException found)
            {
                limit = found;
                return true;
            }
            exception = exception is AggregateException { InnerExceptions.Count: 1 } aggregate
                ? aggregate.InnerExceptions[0]
                : exception.InnerException;
        }
        limit = null;
        return false;
    }
}
