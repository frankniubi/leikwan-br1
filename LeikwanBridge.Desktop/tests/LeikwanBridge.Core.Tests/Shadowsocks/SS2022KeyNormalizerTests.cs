using System;
using LeikwanBridge.Core.Shadowsocks;
using Xunit;

namespace LeikwanBridge.Core.Tests.Shadowsocks;

public class SS2022KeyNormalizerTests
{
    [Fact]
    public void ExactLengthAes256KeyPassesThrough()
    {
        var key = "MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI="; // 32 bytes
        var result = SS2022KeyNormalizer.NormalizePassword(key, "2022-blake3-aes-256-gcm");
        Assert.Null(result.Error);
        Assert.Equal(key, result.Normalized);
    }

    [Fact]
    public void ExactLengthAes128KeyPassesThrough()
    {
        var key = "MTIzNDU2Nzg5MDEyMzQ1Ng=="; // 16 bytes
        var result = SS2022KeyNormalizer.NormalizePassword(key, "2022-blake3-aes-128-gcm");
        Assert.Null(result.Error);
        Assert.Equal(key, result.Normalized);
    }

    [Fact]
    public void DoublePaddedBase64IsNormalized()
    {
        var key = "MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=";
        var result = SS2022KeyNormalizer.NormalizePassword(key, "2022-blake3-aes-256-gcm");
        Assert.Null(result.Error);
        Assert.Equal("MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=", result.Normalized);
    }

    [Fact]
    public void OverLongKeyIsHashedToTargetLength()
    {
        // base64 of "This is a much longer key that exceeds 32 bytes" (47 bytes)
        var longKey = "VGhpcyBpcyBhIG11Y2ggbG9uZ2VyIGtleSB0aGF0IGV4Y2VlZHMgMzIgYnl0ZXM=";
        var result = SS2022KeyNormalizer.NormalizePassword(longKey, "2022-blake3-aes-256-gcm");
        Assert.Null(result.Error);
        // SHA256 of the decoded bytes, truncated to 32, re-encoded unpadded
        Assert.Equal(44, result.Normalized!.Length);
    }

    [Fact]
    public void OverLongKeyHashMatchesSha256Truncation()
    {
        // base64 of 59 ASCII bytes
        var key = "YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXowMTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=";
        var result = SS2022KeyNormalizer.NormalizePassword(key, "2022-blake3-aes-256-gcm");
        Assert.Null(result.Error);
        Assert.Equal("NqNX0VGXr0Tvoc7+bFbeqUol4l1isa6GVPwNfzS/NfM=", result.Normalized);
    }

    [Fact]
    public void RawAscii32CharKeyIsAcceptedAndReencoded()
    {
        var rawKey = "a!b@c#d$e%f^g&h*i(j)k-l=m[n]o{p}"; // 32 ASCII chars, not valid base64
        var result = SS2022KeyNormalizer.NormalizePassword(rawKey, "2022-blake3-aes-256-gcm");
        Assert.Null(result.Error);
        Assert.Equal("YSFiQGMjZCRlJWZeZyZoKmkoailrLWw9bVtuXW97cH0=", result.Normalized);
    }

    [Fact]
    public void MultiUserIpskUpskBothExactLength()
    {
        var key = "MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=:YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXowMTIzNDU=";
        var result = SS2022KeyNormalizer.NormalizePassword(key, "2022-blake3-aes-256-gcm");
        Assert.Null(result.Error);
        Assert.Equal(key, result.Normalized);
    }

    [Fact]
    public void MultiUserWithOneOverLongSegmentNormalizesThatSegmentOnly()
    {
        var exactKey = "MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=";
        var longKey = "VGhpcyBpcyBhIG11Y2ggbG9uZ2VyIGtleSB0aGF0IGV4Y2VlZHMgMzIgYnl0ZXM=";
        var result = SS2022KeyNormalizer.NormalizePassword($"{exactKey}:{longKey}", "2022-blake3-aes-256-gcm");
        Assert.Null(result.Error);
        var parts = result.Normalized!.Split(':');
        Assert.Equal(2, parts.Length);
        Assert.Equal(exactKey, parts[0]);
        Assert.Equal(44, parts[1].Length); // hashed segment, padded
    }

    [Fact]
    public void UnderLengthKeyReturnsError()
    {
        var shortKey = "c2hvcnQ="; // "short" = 5 bytes
        var result = SS2022KeyNormalizer.NormalizePassword(shortKey, "2022-blake3-aes-256-gcm");
        Assert.NotNull(result.Error);
        Assert.Contains("密钥长度", result.Error);
        Assert.Contains("32 字节", result.Error);
    }

    [Fact]
    public void EmptyPskReturnsError()
    {
        var result = SS2022KeyNormalizer.NormalizePassword("", "2022-blake3-aes-256-gcm");
        Assert.NotNull(result.Error);
        Assert.Contains("PSK 为空", result.Error);
    }

    [Fact]
    public void NonSS2022MethodPassesThroughUnchanged()
    {
        var password = "some-legacy-password";
        var result = SS2022KeyNormalizer.NormalizePassword(password, "aes-256-gcm");
        Assert.Null(result.Error);
        Assert.Equal(password, result.Normalized);
    }

    [Fact]
    public void Chacha20Poly1305Requires32Bytes()
    {
        var key = "MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=";
        var result = SS2022KeyNormalizer.NormalizePassword(key, "2022-blake3-chacha20-poly1305");
        Assert.Null(result.Error);
        Assert.Equal(key, result.Normalized);
    }
}
