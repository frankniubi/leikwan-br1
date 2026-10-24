using System.Globalization;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace LeikwanBridge.Core.Models;

/// <summary>
/// The panel's minimum desktop client version, carried as the top-level
/// <c>client_update</c> object of a config pull. The panel sends it only to the
/// windows-desktop and macos-desktop platforms and omits it when no minimum is
/// configured. Every malformed shape (wrong JSON type, empty or unparseable
/// version) reads as "no requirement": the gate fails open and never blocks a
/// client because of a parse error.
/// </summary>
[JsonConverter(typeof(ClientUpdatePolicyJsonConverter))]
public sealed class ClientUpdatePolicy
{
    private const int MaxComponents = 8;

    public string MinimumVersion { get; init; } = "";

    /// <summary>True when <paramref name="currentVersion"/> is older than this policy's minimum.</summary>
    public bool IsRequiredFor(string? currentVersion) => IsUpdateRequired(currentVersion, MinimumVersion);

    /// <summary>
    /// True only when both versions parse and the current version is lower.
    /// Components compare numerically, missing components count as 0 and any
    /// <c>+</c>/<c>-</c> suffix is ignored, so "1.0.59" equals "1.0.59.0" and
    /// "0.1.20.68" is lower than "0.1.20.69-beta".
    /// </summary>
    public static bool IsUpdateRequired(string? currentVersion, string? minimumVersion)
    {
        if (!TryParseVersion(minimumVersion, out var minimum))
            return false;
        if (!TryParseVersion(currentVersion, out var current))
            return false;
        return Compare(current, minimum) < 0;
    }

    public static bool TryParseVersion(string? value, out IReadOnlyList<long> components)
    {
        components = [];
        var normalized = (value ?? string.Empty).Trim();
        var suffix = normalized.IndexOfAny(['+', '-']);
        if (suffix >= 0)
            normalized = normalized[..suffix];
        if (normalized.Length == 0)
            return false;

        var parts = normalized.Split('.');
        if (parts.Length > MaxComponents)
            return false;
        var parsed = new long[parts.Length];
        for (var index = 0; index < parts.Length; index++)
        {
            if (!long.TryParse(parts[index], NumberStyles.None, CultureInfo.InvariantCulture, out parsed[index]))
                return false;
        }
        components = parsed;
        return true;
    }

    private static int Compare(IReadOnlyList<long> left, IReadOnlyList<long> right)
    {
        var length = Math.Max(left.Count, right.Count);
        for (var index = 0; index < length; index++)
        {
            var a = index < left.Count ? left[index] : 0;
            var b = index < right.Count ? right[index] : 0;
            if (a != b)
                return a.CompareTo(b);
        }
        return 0;
    }
}

/// <summary>
/// Reads <c>client_update</c> without ever failing the pull: an object yields a
/// policy (its <c>minimum_version</c> as text, empty when missing or not a
/// string/number); any other JSON type - PHP renders an empty map as <c>[]</c> -
/// is skipped and read as absent.
/// </summary>
public sealed class ClientUpdatePolicyJsonConverter : JsonConverter<ClientUpdatePolicy?>
{
    public override bool HandleNull => true;

    public override ClientUpdatePolicy? Read(ref Utf8JsonReader reader, Type typeToConvert, JsonSerializerOptions options)
    {
        if (reader.TokenType != JsonTokenType.StartObject)
        {
            reader.Skip();
            return null;
        }

        using var document = JsonDocument.ParseValue(ref reader);
        var minimum = string.Empty;
        if (document.RootElement.TryGetProperty("minimum_version", out var value))
        {
            minimum = value.ValueKind switch
            {
                JsonValueKind.String => value.GetString() ?? string.Empty,
                JsonValueKind.Number => value.GetRawText(),
                _ => string.Empty,
            };
        }
        return new ClientUpdatePolicy { MinimumVersion = minimum.Trim() };
    }

    public override void Write(Utf8JsonWriter writer, ClientUpdatePolicy? value, JsonSerializerOptions options)
    {
        if (value is null)
        {
            writer.WriteNullValue();
            return;
        }
        writer.WriteStartObject();
        writer.WriteString("minimum_version", value.MinimumVersion);
        writer.WriteEndObject();
    }
}

/// <summary>
/// In-memory (never persisted) record of the last minimum-version policy a
/// pull carried. A pull without <c>client_update</c> - including one that
/// failed or was not modified - leaves the remembered policy untouched, so a
/// flaky panel cannot lift the gate; a pull that carries the object replaces
/// it (an empty or unparseable minimum lifts it, failing open).
/// </summary>
public sealed class ClientUpdateRequirement
{
    public ClientUpdateRequirement(string currentVersion)
    {
        CurrentVersion = currentVersion ?? string.Empty;
    }

    public string CurrentVersion { get; }

    public string MinimumVersion { get; private set; } = string.Empty;

    public bool IsRequired => ClientUpdatePolicy.IsUpdateRequired(CurrentVersion, MinimumVersion);

    /// <summary>Records a pull's policy; returns true when <see cref="IsRequired"/> or the minimum changed.</summary>
    public bool Observe(ClientUpdatePolicy? policy)
    {
        if (policy is null)
            return false;
        var wasRequired = IsRequired;
        var previousMinimum = MinimumVersion;
        MinimumVersion = policy.MinimumVersion ?? string.Empty;
        return wasRequired != IsRequired
            || !string.Equals(previousMinimum, MinimumVersion, StringComparison.Ordinal);
    }
}
