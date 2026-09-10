using System.Security.Cryptography;

namespace LeikwanBridge.Core.Helpers;

public static class FileHasher
{
    public static string Sha256(string filePath)
    {
        if (!File.Exists(filePath)) return "";
        using var stream = File.OpenRead(filePath);
        var hash = SHA256.HashData(stream);
        return Convert.ToHexString(hash).ToLowerInvariant();
    }
}
