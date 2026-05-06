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
        string devicePlatform = "")
    {
        _masterUrl = masterUrl.TrimEnd('/');
        _agentToken = agentToken;
        _http = new HttpClient { Timeout = TimeSpan.FromSeconds(30) };
        _http.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", _agentToken);
        _http.DefaultRequestHeaders.UserAgent.ParseAdd($"Leikwan-Windows-GUI/{AgentVersion}");
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
        var response = await _http.SendAsync(request, ct);
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
        var response = await _http.SendAsync(request, ct);
        response.EnsureSuccessStatusCode();
        var body = await response.Content.ReadAsStringAsync(ct);
        return JsonSerializer.Deserialize<ClientSelectionResponse>(body, JsonOptions)
               ?? throw new InvalidOperationException("null client selection response");
    }

    public async Task<PullResponse> PullConfigsAsync(CancellationToken ct = default)
    {
        var url = $"{_masterUrl}/api/client/configs/pull";
        Log.Debug("pulling configs from {Url}", url);
        var response = await _http.GetAsync(url, ct);
        await EnsureSuccessOrThrowAsync(response, ct);
        var json = await response.Content.ReadAsStringAsync(ct);
        return JsonSerializer.Deserialize<PullResponse>(json, JsonOptions)
               ?? throw new InvalidOperationException("null pull response");
    }

    public async Task SendHeartbeatAsync(HeartbeatPayload payload, CancellationToken ct = default)
    {
        var url = $"{_masterUrl}/api/client/heartbeat";
        var json = JsonSerializer.Serialize(payload, JsonOptions);
        using var content = new StringContent(json, Encoding.UTF8, "application/json");
        Log.Debug("sending heartbeat to {Url}", url);
        var response = await _http.PostAsync(url, content, ct);
        response.EnsureSuccessStatusCode();
    }

    public async Task SubmitDiagnosticsAsync(string requestToken, List<object> results, string error, CancellationToken ct = default)
    {
        var url = $"{_masterUrl}/api/client/diagnostics/result";
        var payload = new { request_token = requestToken, results, error };
        var json = JsonSerializer.Serialize(payload, JsonOptions);
        using var content = new StringContent(json, Encoding.UTF8, "application/json");
        Log.Debug("submitting diagnostics to {Url}", url);
        var response = await _http.PostAsync(url, content, ct);
        response.EnsureSuccessStatusCode();
    }

    public async Task<bool> TestConnectionAsync(CancellationToken ct = default)
    {
        try
        {
            LastErrorMessage = "";
            var result = await PullConfigsAsync(ct);
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

    public void Dispose() => _http.Dispose();
}

public sealed class MasterApiException : Exception
{
    public MasterApiException(int statusCode, string message)
        : base(message)
    {
        StatusCode = statusCode;
    }

    public int StatusCode { get; }
}
