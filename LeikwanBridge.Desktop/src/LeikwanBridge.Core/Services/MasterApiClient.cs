using System.Net.Http.Headers;
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

    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower,
        DefaultIgnoreCondition = System.Text.Json.Serialization.JsonIgnoreCondition.WhenWritingNull,
    };

    public MasterApiClient(string masterUrl, string agentToken)
    {
        _masterUrl = masterUrl.TrimEnd('/');
        _agentToken = agentToken;
        _http = new HttpClient { Timeout = TimeSpan.FromSeconds(30) };
        _http.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", _agentToken);
        _http.DefaultRequestHeaders.UserAgent.ParseAdd($"Leikwan-Windows-GUI/{AgentVersion}");
        _http.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
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
        response.EnsureSuccessStatusCode();
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
            var result = await PullConfigsAsync(ct);
            return result.Ok;
        }
        catch
        {
            return false;
        }
    }

    public void Dispose() => _http.Dispose();
}
