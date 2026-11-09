using System.Text;
using LeikwanBridge.Core.Helpers;
using Xunit;

namespace LeikwanBridge.Core.Tests.Helpers;

public sealed class AsciiHeaderValueTests
{
    [Theory]
    [InlineData("windows-device-123")]
    [InlineData("Leikwan Bridge on DEVBOX")]
    [InlineData("windows-desktop")]
    public void Encode_KeepsPrintableAsciiUnchanged(string value)
    {
        Assert.Equal(value, AsciiHeaderValue.Encode(value));
    }

    [Fact]
    public void Encode_WrapsNonAsciiTextInUtf8Base64EncodedWord()
    {
        var encoded = AsciiHeaderValue.Encode("办公机");

        Assert.Equal("=?UTF-8?B?" + Convert.ToBase64String(Encoding.UTF8.GetBytes("办公机")) + "?=", encoded);
        Assert.DoesNotContain(encoded, c => c > '~');
    }

    [Theory]
    [InlineData("tab\there")]
    [InlineData("line\nbreak")]
    public void Encode_WrapsControlCharactersToo(string value)
    {
        var encoded = AsciiHeaderValue.Encode(value);

        Assert.StartsWith("=?UTF-8?B?", encoded);
        Assert.Equal(value, Encoding.UTF8.GetString(Convert.FromBase64String(
            encoded["=?UTF-8?B?".Length..^2])));
    }
}
