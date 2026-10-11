using System.Reflection;
using LeikwanBridge.App.ViewModels;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using Xunit;

namespace LeikwanBridge.App.Tests.ViewModels;

/// <summary>
/// A panel-declared native outbound rule is configuration only: the Windows UI
/// must label the row and never reach the runtime manager to start or stop it.
/// </summary>
public sealed class NativeOutboundRuleViewModelTests
{
    [Fact]
    public void NativeOutboundRuleRowIsLabelledAndOffersNoStartOrStop()
    {
        var vm = new MainViewModel();

        vm.ApplyForwardSnapshot([CreateNativeOutboundForward(180), CreateOrdinaryForward(181)], _ => false);

        var native = vm.Forwards.Single(forward => forward.ForwardId == 180);
        Assert.True(native.Summary.IsNativeOutboundRule);
        Assert.False(native.Summary.CanStart);
        Assert.False(native.Summary.CanStop);

        var ordinary = vm.Forwards.Single(forward => forward.ForwardId == 181);
        Assert.False(ordinary.Summary.IsNativeOutboundRule);
        Assert.True(ordinary.Summary.CanStart);
        Assert.False(ordinary.Summary.CanStop);
    }

    [Fact]
    public void NativeOutboundRuleRowStaysUnstartableAfterLifecycleRefresh()
    {
        var vm = new MainViewModel();
        vm.ApplyForwardSnapshot([CreateNativeOutboundForward(180)], _ => true);

        var native = vm.Forwards.Single(forward => forward.ForwardId == 180);
        Assert.False(native.Summary.CanStart);
        Assert.False(native.Summary.CanStop);
        Assert.True(native.Summary.IsNativeOutboundRule);
    }

    [Fact]
    public async Task StartAllSkipsNativeOutboundRules()
    {
        var vm = new MainViewModel();
        var stateDir = CreateTempStateDir();
        try
        {
            var runtime = new RecordingForwardRuntimeManager(new StateManager(stateDir), new ProcessSupervisor());
            InjectRuntimeManager(vm, runtime);
            vm.ApplyForwardSnapshot([CreateNativeOutboundForward(180), CreateOrdinaryForward(181)], _ => false);

            await vm.StartAllCommand.ExecuteAsync(null);

            Assert.Equal([181], runtime.AppliedForwardIds);
        }
        finally
        {
            TryDelete(stateDir);
        }
    }

    [Fact]
    public async Task NativeOutboundRuleStartStopAndToggleCommandsNeverReachTheRuntimeManager()
    {
        var vm = new MainViewModel();
        var stateDir = CreateTempStateDir();
        try
        {
            var runtime = new RecordingForwardRuntimeManager(new StateManager(stateDir), new ProcessSupervisor());
            InjectRuntimeManager(vm, runtime);
            vm.ApplyForwardSnapshot([CreateNativeOutboundForward(180)], _ => true);
            var native = vm.Forwards.Single(forward => forward.ForwardId == 180);

            await vm.StartForwardCommand.ExecuteAsync(native);
            await vm.StopForwardCommand.ExecuteAsync(native);
            vm.ToggleForwardCommand.Execute(native);

            Assert.Empty(runtime.AppliedForwardIds);
            Assert.Empty(runtime.StoppedForwardIds);
            Assert.True(vm.Forwards.Single(forward => forward.ForwardId == 180).Summary.IsNativeOutboundRule);
        }
        finally
        {
            TryDelete(stateDir);
        }
    }

    private static void InjectRuntimeManager(MainViewModel vm, ForwardRuntimeManager runtime)
        => typeof(MainViewModel)
            .GetField("_runtimeManager", BindingFlags.Instance | BindingFlags.NonPublic)!
            .SetValue(vm, runtime);

    private static string CreateTempStateDir()
    {
        var path = Path.Combine(Path.GetTempPath(), "leikwan-native-rule-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(path);
        return path;
    }

    private static void TryDelete(string path)
    {
        try
        {
            Directory.Delete(path, recursive: true);
        }
        catch (IOException)
        {
        }
    }

    private static ForwardConfig CreateNativeOutboundForward(int forwardId)
    {
        return new ForwardConfig
        {
            ForwardId = forwardId,
            ServiceStatus = "active",
            BackendMode = "ultra",
            Mode = "single",
            ListenPort = 54544,
            Target = "198.51.100.10:443",
            Transport = "tcp",
            NativeOutboundEnabled = true,
            NativeDirection = "Mainland China -> Hong Kong",
        };
    }

    private static ForwardConfig CreateOrdinaryForward(int forwardId)
    {
        return new ForwardConfig
        {
            ForwardId = forwardId,
            ServiceStatus = "active",
            Mode = "single",
            ListenPort = 12081,
            Target = "193.227.109.82:5201",
            Transport = "tcp",
        };
    }

    private sealed class RecordingForwardRuntimeManager : ForwardRuntimeManager
    {
        public RecordingForwardRuntimeManager(StateManager stateManager, ProcessSupervisor supervisor)
            : base(new AgentConfig(), supervisor, stateManager, binaryExists: _ => true, portListeningProbe: _ => true)
        {
        }

        public List<int> AppliedForwardIds { get; } = [];
        public List<int> StoppedForwardIds { get; } = [];

        public override void ApplyForward(ForwardConfig forward)
            => AppliedForwardIds.Add(forward.ForwardId);

        public override void StopForward(int forwardId)
            => StoppedForwardIds.Add(forwardId);
    }
}
