using LeikwanBridge.App.ViewModels;
using Xunit;

namespace LeikwanBridge.App.Tests.ViewModels;

/// <summary>The dashboard forward card shows the target address and port separately.</summary>
public sealed class ForwardTargetTests
{
    [Theory]
    [InlineData("198.51.100.9:443", "198.51.100.9", "443")]
    [InlineData("  198.51.100.9:65535 ", "198.51.100.9", "65535")]
    [InlineData("node.example.com:8443", "node.example.com", "8443")]
    [InlineData("[2001:db8::1]:443", "2001:db8::1", "443")]
    [InlineData("2001:db8::1:443", "2001:db8::1", "443")]
    public void SplitsAddressAndPort(string target, string address, string port)
        => Assert.Equal((address, port), ForwardTarget.Split(target));

    [Theory]
    [InlineData("", "-", "-")]
    [InlineData(null, "-", "-")]
    [InlineData("   ", "-", "-")]
    [InlineData(":443", ":443", "-")]
    [InlineData("198.51.100.9:", "198.51.100.9:", "-")]
    [InlineData("198.51.100.9:0", "198.51.100.9:0", "-")]
    [InlineData("198.51.100.9:70000", "198.51.100.9:70000", "-")]
    [InlineData("198.51.100.9:https", "198.51.100.9:https", "-")]
    public void ShowsTheWholeTargetWhenItHasNoUsablePort(string? target, string address, string port)
        => Assert.Equal((address, port), ForwardTarget.Split(target));
}
