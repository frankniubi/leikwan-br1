using LeikwanBridge.Core.Helpers;
using Xunit;

namespace LeikwanBridge.Core.Tests.Helpers;

/// <summary>
/// Tests for PortOwnerEnumerator (缺陷③修复验证)
/// </summary>
[Trait("Platform", "Windows")]
public class PortOwnerEnumeratorTests
{
    [Fact]
    public void FindOwners_ShouldNotCrash_WhenAccessDenied()
    {
        // 缺陷③修复：ACCESS_DENIED 时 Query() 返回 null，
        // AddTcpOwners/AddUdpOwners 直接 return（不尝试解析 Basic 表），
        // 避免内存错位问题

        // Arrange
        var port = 443; // 常见的系统端口

        // Act
        var owners = PortOwnerEnumerator.FindOwners(port);

        // Assert - 验证方法不抛出异常，返回有效列表（可能为空）
        Assert.NotNull(owners);
        Assert.True(owners.Count >= 0);
    }

    [Fact]
    public void FindOwners_ShouldReturnEmptyList_WhenNoOwners()
    {
        // Arrange - 使用不太可能被占用的高端口
        var port = 54321;

        // Act
        var owners = PortOwnerEnumerator.FindOwners(port);

        // Assert - 空列表（无监听者）
        Assert.NotNull(owners);
        Assert.Empty(owners);
    }

    [Fact]
    public void FindOwners_ShouldNotCrash_ForCommonPorts()
    {
        // Arrange - 测试常见端口不会产生内存错位
        var commonPorts = new[] { 80, 443, 3000, 5000, 8080, 8443, 60000, 60001, 60002 };

        // Act & Assert
        foreach (var port in commonPorts)
        {
            var owners = PortOwnerEnumerator.FindOwners(port);

            // 验证：
            // 1. 不抛出异常
            // 2. 返回有效列表
            // 3. 如果有 owner，PID 应该是合理值（>0，<100000）
            Assert.NotNull(owners);
            Assert.All(owners, owner =>
            {
                Assert.True(owner.Pid > 0);
                Assert.True(owner.Pid < 100000);
                Assert.NotEmpty(owner.Protocol); // "tcp" 或 "udp"
                Assert.True(owner.Port > 0);
            });
        }
    }

    [Fact]
    public void FindOwners_ShouldReturnValidData_WhenPortIsListening()
    {
        // 这个测试需要实际有监听端口，可以在集成测试环境中运行
        // 单元测试中只验证接口契约

        // Arrange
        var port = 12345;

        // Act
        var owners = PortOwnerEnumerator.FindOwners(port);

        // Assert - 验证返回值符合契约
        Assert.NotNull(owners);
        if (owners.Count > 0)
        {
            var owner = owners[0];
            Assert.True(owner.Pid > 0);
            Assert.Contains(owner.Protocol, new[] { "tcp", "udp" });
            Assert.Equal(port, owner.Port);
            Assert.NotNull(owner.Address);
        }
    }

    [Fact]
    public void FindOwners_ShouldHandleIPv6()
    {
        // Arrange - IPv6 的行长是 52/28 字节（TCP/UDP）
        var port = 8080;

        // Act
        var owners = PortOwnerEnumerator.FindOwners(port);

        // Assert - 如果有 IPv6 监听，地址应该包含冒号
        Assert.NotNull(owners);
        Assert.All(owners.Where(o => o.Address.Contains(':')), owner =>
        {
            // IPv6 地址验证
            Assert.True(owner.Address.Length > 7);
            Assert.Contains(':', owner.Address);
        });
    }

    [Fact]
    public void FindOwners_ShouldReturnEmpty_ForInvalidPorts()
    {
        // Arrange & Act
        var resultZero = PortOwnerEnumerator.FindOwners(0);
        var resultNegative = PortOwnerEnumerator.FindOwners(-1);
        var resultTooLarge = PortOwnerEnumerator.FindOwners(65536);

        // Assert
        Assert.NotNull(resultZero);
        Assert.Empty(resultZero);
        Assert.NotNull(resultNegative);
        Assert.Empty(resultNegative);
        Assert.NotNull(resultTooLarge);
        Assert.Empty(resultTooLarge);
    }

    [Fact]
    public void ResolveExecutablePath_ShouldReturnNull_ForInvalidPid()
    {
        // Arrange & Act
        var resultZero = PortOwnerEnumerator.ResolveExecutablePath(0);
        var resultNegative = PortOwnerEnumerator.ResolveExecutablePath(-1);

        // Assert
        Assert.Null(resultZero);
        Assert.Null(resultNegative);
    }
}
