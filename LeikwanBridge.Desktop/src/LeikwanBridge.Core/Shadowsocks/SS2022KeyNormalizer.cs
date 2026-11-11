using System;
using System.Linq;
using System.Security.Cryptography;
using System.Text;

namespace LeikwanBridge.Core.Shadowsocks;

public static class SS2022KeyNormalizer
{
    public record Result(string? Normalized, string? Error);

    /// <summary>
    /// Normalize SS2022 PSK strings to the format sing-box clients accept (base64, decoded length
    /// equals key length). Sing-box servers tolerate longer keys via SHA256 hash truncation; our
    /// outbound configs do not. Xray accepts raw ASCII keys; we convert those to base64.
    /// Applies to both single keys and iPSK:uPSK pairs.
    /// </summary>
    public static Result NormalizePassword(string password, string method)
    {
        // Match Kotlin/Swift: only SS2022 methods are normalized; anything else passes through
        // (the outbound builders only call this for "2022-" methods anyway).
        var requiredLength = method.ToLowerInvariant() switch
        {
            "2022-blake3-aes-128-gcm" => 16,
            "2022-blake3-aes-256-gcm" => 32,
            "2022-blake3-chacha20-poly1305" => 32,
            _ => 0
        };
        if (requiredLength == 0)
        {
            return new Result(password, null);
        }

        var segments = password.Split(':');
        var normalizedParts = new string[segments.Length];
        for (var i = 0; i < segments.Length; i++)
        {
            var result = NormalizeSingleKey(segments[i].Trim(), requiredLength);
            if (result.Error != null)
            {
                var position = segments.Length > 1 ? $"第 {i + 1} 段" : "";
                return new Result(null, $"{position}{result.Error}");
            }
            normalizedParts[i] = result.Normalized!;
        }

        return new Result(string.Join(':', normalizedParts), null);
    }

    private static Result NormalizeSingleKey(string key, int requiredLength)
    {
        if (key.Length == 0)
        {
            return new Result(null, "PSK 为空");
        }

        byte[] decoded;
        try
        {
            // .NET requires correct padding; JS-style lenient decoders accept unpadded input.
            // Re-pad manually so 43-char unpadded base64 decodes instead of falling through
            // to the raw-ASCII path.
            var padded = key.Trim().PadRight((key.Trim().Length + 3) / 4 * 4, '=');
            decoded = Convert.FromBase64String(padded);
        }
        catch (FormatException)
        {
            // Not valid base64 → treat as raw ASCII bytes (Xray compat)
            decoded = Encoding.UTF8.GetBytes(key);
        }

        if (decoded.Length == requiredLength)
        {
            // Exact match: re-encode to canonical padded base64
            return new Result(Convert.ToBase64String(decoded), null);
        }

        if (decoded.Length > requiredLength)
        {
            // Over-long: hash via SHA256 and truncate (sing-box server compat)
            var sha = SHA256.HashData(decoded);
            return new Result(Convert.ToBase64String(sha.AsSpan(0, requiredLength).ToArray()), null);
        }

        var methodName = requiredLength == 16
            ? "2022-blake3-aes-128-gcm"
            : "2022-blake3-aes-256-gcm / chacha20-poly1305";
        return new Result(null, $"密钥长度 {decoded.Length} 字节，{methodName} 需要 {requiredLength} 字节");
    }

}
