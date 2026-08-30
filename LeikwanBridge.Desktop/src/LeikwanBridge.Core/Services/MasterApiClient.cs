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

    private readonly HttpClient _http;
    private readonly string _masterUrl;
    private readonly string _agentToken;
    private readonly string _deviceId;
    private readonly bool _ownsHttpClient;
    private readonly Action<HttpRequestMessage>? _onRequestForTest;

    public string LastErrorMessage { get; private set; } = "";

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
        ClientPlatformIdentity? platformIdentity = null)
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
        var response = await SendAsync(request, ct);

        if (response.StatusCode == System.Net.HttpStatusCode.NotModified)
            return new PullResponse { Ok = true, NotModified = true, ConfigRevision = sinceRevision ?? "" };

        await EnsureSuccessOrThrowAsync(response, ct);
        var json = await response.Content.ReadAsStringAsync(ct);
        try
        {
            return JsonSerializer.Deserialize<PullResponse>(json, JsonOptions)
                   ?? throw new MasterApiException((int) response.StatusCode, "master_api_empty_response");
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
                        if (lease is null)
                        {
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
        if (!string.Equals(protocol, UltraPolicy.Protocol, StringComparison.Ordinal)
            || returnedForward != forward.ForwardId
            || !string.Equals(returnedGeneration, policy.PolicyGeneration, StringComparison.Ordinal)
            || !string.Equals(returnedTicket, policy.SessionTicket, StringComparison.Ordinal)
            || !string.Equals(targetDigest, policy.TargetDigest, StringComparison.Ordinal)
            || !string.Equals(transport, policy.Transport, StringComparison.OrdinalIgnoreCase))
            return null;
        var endpoint = item.GetProperty("endpoint");
        var credential = item.GetProperty("credential");
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
            NonceHash = item.GetProperty("nonce_hash").GetString() ?? "",
            ProviderIp = endpoint.GetProperty("ip").GetString() ?? "",
            ProviderPort = endpoint.GetProperty("port").GetInt32(),
            User = Encoding.UTF8.GetBytes(credential.GetProperty("user").GetString() ?? ""),
            Password = Encoding.UTF8.GetBytes(credential.GetProperty("password").GetString() ?? ""),
        };
    }

    public async Task<HeartbeatResponse> SendHeartbeatAsync(HeartbeatPayload payload, CancellationToken ct = default)
    {
        var url = $"{_masterUrl}/api/client/heartbeat";
        var json = JsonSerializer.Serialize(payload, JsonOptions);
        using var content = new StringContent(json, Encoding.UTF8, "application/json");
        using var request = new HttpRequestMessage(HttpMethod.Post, url) { Content = content };
        Log.Debug("sending heartbeat to {Url}", url);
        var response = await SendAsync(request, ct);
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
        response.EnsureSuccessStatusCode();
    }

    public async Task<bool> TestConnectionAsync(CancellationToken ct = default)
    {
        try
        {
            LastErrorMessage = "";
            var result = await PullConfigsAsync(ct: ct);
            return result.Ok;
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

    private void AddOptionalHeader(string name, string value)
    {
        if (!string.IsNullOrWhiteSpace(value))
            _http.DefaultRequestHeaders.TryAddWithoutValidation(name, value.Trim());
    }

    private Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken ct)
    {
        _onRequestForTest?.Invoke(request);
        return _http.SendAsync(request, ct);
    }

    private static async Task EnsureSuccessOrThrowAsync(HttpResponseMessage response, CancellationToken ct)
    {
        if (response.IsSuccessStatusCode)
            return;

        var body = await response.Content.ReadAsStringAsync(ct);
        var message = BuildApiErrorMessage(response, body);
        throw new MasterApiException((int) response.StatusCode, message);
    }

    private static string BuildApiErrorMessage(HttpResponseMessage response, string body)
    {
        try
        {
            using var document = JsonDocument.Parse(body);
            var root = document.RootElement;
            var error = root.TryGetProperty("error", out var errorElement)
                ? errorElement.GetString() ?? ""
                : "";

            if (string.Equals(error, "client_online_limit_reached", StringComparison.OrdinalIgnoreCase))
            {
                var limit = 0;
                var active = 0;
                if (root.TryGetProperty("details", out var details) && details.ValueKind == JsonValueKind.Object)
                {
                    if (details.TryGetProperty("online_device_limit", out var limitElement))
                        limit = limitElement.GetInt32();
                    if (details.TryGetProperty("active_online_devices", out var activeElement))
                        active = activeElement.GetInt32();
                }

                var suffix = limit > 0 ? $" ({active} / {limit})" : "";
                return $"Online device limit reached{suffix}. Close another active client for this profile or raise the client device limit in the panel.";
            }

            if (!string.IsNullOrWhiteSpace(error))
                return error;
        }
        catch
        {
            // Fall through to HTTP fallback.
        }

        return $"HTTP {(int) response.StatusCode} {response.ReasonPhrase}".Trim();
    }

    public void Dispose()
    {
        if (_ownsHttpClient)
            _http.Dispose();
    }
}

public sealed class MasterApiException : Exception
{
    public MasterApiException(int statusCode, string message, Exception? innerException = null)
        : base(message, innerException)
    {
        StatusCode = statusCode;
    }

    public int StatusCode { get; }
}
