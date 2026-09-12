// macOS end-to-end console harness for the LeiKwan Bridge client.
//
// Runs the production Core/Mac code paths outside the Avalonia UI so the
// behaviour that used to require a human in front of the GUI can be asserted
// from a shell:
//   * MacFileSecretStore set/get/delete with 0600 file / 0700 directory and
//     atomic writes, plus the one-time legacy keychain migration (no prompt).
//   * Panel direct egress: physical interface selection, default-route
//     classification and real HTTPS requests through LeikwanHttpClientFactory.
//   * MacAppUpdateService.CheckAsync against both panel endpoints, the same
//     call the GUI makes.
//
// Every line is written to stdout and to LKB_HARNESS_OUTPUT (default
// /tmp/lkb-harness-report.txt). Exit code 0 = all checks passed.
using System.Diagnostics;
using System.Net;
using System.Net.NetworkInformation;
using System.Net.Sockets;
using System.Runtime.InteropServices;
using System.Text;
using System.Text.Json;
using LeikwanBridge.Core.Models;
using LeikwanBridge.Core.Services;
using LeikwanBridge.Core.Services.PanelEgress;
using LeikwanBridge.Mac.Services;

namespace LeikwanBridge.Harness;

internal static class Program
{
    private static readonly StringBuilder Transcript = new();
    private static int _passes;
    private static int _failures;

    private static void Line(string text)
    {
        Console.WriteLine(text);
        Console.Out.Flush();
        Transcript.AppendLine(text);
    }

    private static void Check(string name, bool ok, string detail = "")
    {
        if (ok)
            _passes++;
        else
            _failures++;
        Line($"{(ok ? "PASS" : "FAIL")} | {name}{(detail.Length > 0 ? " | " + detail : string.Empty)}");
    }

    private static string Trim(string value, int max = 300)
    {
        var normalized = (value ?? string.Empty).Replace('\r', ' ').Replace('\n', ' ').Trim();
        return normalized.Length <= max ? normalized : normalized[..max] + "...";
    }

    private static (int ExitCode, string Output) RunTool(string fileName, string arguments)
    {
        try
        {
            using var process = new Process
            {
                StartInfo = new ProcessStartInfo(fileName, arguments)
                {
                    RedirectStandardOutput = true,
                    RedirectStandardError = true,
                    UseShellExecute = false,
                    CreateNoWindow = true,
                },
            };
            process.Start();
            var output = process.StandardOutput.ReadToEnd() + process.StandardError.ReadToEnd();
            process.WaitForExit(15000);
            return (process.HasExited ? process.ExitCode : -1, output);
        }
        catch (Exception exception)
        {
            return (-1, exception.GetType().Name + ": " + exception.Message);
        }
    }

    private static async Task<int> Main()
    {
        Line("# LeiKwan Bridge macOS e2e harness");
        Line($"utc_start={DateTime.UtcNow:O}");
        Line($"runtime={RuntimeInformation.FrameworkDescription} os={RuntimeInformation.OSDescription} arch={RuntimeInformation.ProcessArchitecture}");
        Line($"pid={Environment.ProcessId} user={Environment.UserName}");

        try
        {
            await SecretStoreSectionAsync();
            await MigrationSectionAsync();
            await SelfSeededMigrationSectionAsync();
            await PanelEgressSectionAsync();
            await UpdateCheckSectionAsync();
        }
        catch (Exception exception)
        {
            Check("harness_unhandled_exception", false, Trim(exception.ToString(), 600));
        }

        Line(string.Empty);
        Line($"# summary pass={_passes} fail={_failures}");
        Line($"utc_end={DateTime.UtcNow:O}");

        var output = Environment.GetEnvironmentVariable("LKB_HARNESS_OUTPUT") ?? "/tmp/lkb-harness-report.txt";
        await File.WriteAllTextAsync(output, Transcript.ToString());
        Line($"report_written={output}");
        return _failures == 0 ? 0 : 1;
    }

    private static async Task SecretStoreSectionAsync()
    {
        Line(string.Empty);
        Line("## 1. private file secret store (MacFileSecretStore)");
        var root = Path.Combine(Path.GetTempPath(), "lkb-harness-secrets-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(root);
        var secretsPath = Path.Combine(root, "secrets.json");
        var warnings = new List<string>();
        var store = new MacFileSecretStore(secretsPath, new MacKeychainNative());
        store.WarningRaised += code => warnings.Add(code);

        await store.SetAsync("client-agent-9001", "probe-value", CancellationToken.None);
        Check("secret_file_created", File.Exists(secretsPath), secretsPath);
        Check(
            "secret_file_mode_0600",
            File.Exists(secretsPath) && ((int)File.GetUnixFileMode(secretsPath) & 0x1FF) == 0x180,
            File.Exists(secretsPath) ? Convert.ToString((int)File.GetUnixFileMode(secretsPath) & 0x1FF, 8) : "missing");
        Check(
            "secret_dir_mode_0700",
            ((int)File.GetUnixFileMode(root) & 0x1FF) == 0x1C0,
            Convert.ToString((int)File.GetUnixFileMode(root) & 0x1FF, 8));
        Check("secret_read_roundtrip", await store.GetAsync("client-agent-9001", CancellationToken.None) == "probe-value");
        Check(
            "secret_write_is_atomic_no_temp_leftovers",
            Directory.GetFiles(root, "*.tmp").Length == 0,
            string.Join(",", Directory.GetFiles(root, "*.tmp")));
        Check("secret_no_warnings", warnings.Count == 0, string.Join(",", warnings));

        await store.DeleteAsync("client-agent-9001", CancellationToken.None);
        Check("secret_delete_removes_last_value", !File.Exists(secretsPath));

        var multiRoot = Path.Combine(root, "multi");
        var multiPath = Path.Combine(multiRoot, "secrets.json");
        var multiStore = new MacFileSecretStore(multiPath, new MacKeychainNative());
        await multiStore.SetAsync("client-agent-9001", "a", CancellationToken.None);
        await multiStore.SetAsync("client-agent-9002", "b", CancellationToken.None);
        await multiStore.DeleteAsync("client-agent-9001", CancellationToken.None);
        Check("secret_multi_value_survives_delete", await multiStore.GetAsync("client-agent-9002", CancellationToken.None) == "b");
        var invalid = false;
        try
        {
            await multiStore.SetAsync("not-a-valid-account", "x", CancellationToken.None);
        }
        catch (ArgumentException)
        {
            invalid = true;
        }
        Check("secret_rejects_invalid_account", invalid);
    }

    private static async Task MigrationSectionAsync()
    {
        Line(string.Empty);
        Line("## 2. one-time legacy keychain migration");
        var root = Path.Combine(Path.GetTempPath(), "lkb-harness-migrate-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(root);
        var secretsPath = Path.Combine(root, "secrets.json");
        var account = Environment.GetEnvironmentVariable("LKB_MIGRATION_ACCOUNT") ?? "client-agent-57";
        var allowAnyApp = string.Equals(Environment.GetEnvironmentVariable("LKB_KEYCHAIN_ALLOW_ANY"), "1", StringComparison.Ordinal);
        var service = MacKeychainSecretStore.ServiceName;
        Line($"migration_account={account} keychain_allow_any_app={allowAnyApp}");

        RunTool("/usr/bin/security", $"delete-generic-password -s {service} -a {account}");
        var allowFlag = allowAnyApp ? "-A " : string.Empty;
        var (addExit, addOut) = RunTool("/usr/bin/security", $"add-generic-password {allowFlag}-s {service} -a {account} -w testvalue -U");
        Check("legacy_keychain_item_created", addExit == 0, $"exit={addExit} {Trim(addOut, 120)}");
        var (findBefore, _) = RunTool("/usr/bin/security", $"find-generic-password -s {service} -a {account}");
        Check("legacy_keychain_item_present_before_migration", findBefore == 0, $"find_exit={findBefore}");

        var warnings = new List<string>();
        var store = new MacFileSecretStore(secretsPath, new MacKeychainNative(), TimeSpan.FromSeconds(8));
        store.WarningRaised += code => warnings.Add(code);
        using var promptGuard = new CancellationTokenSource(TimeSpan.FromSeconds(40));
        var stopwatch = Stopwatch.StartNew();
        var completed = false;
        try
        {
            var migration = store.MigrateLegacyAsync(promptGuard.Token);
            var finished = await Task.WhenAny(migration, Task.Delay(TimeSpan.FromSeconds(30)));
            if (ReferenceEquals(finished, migration) && migration.IsCompletedSuccessfully)
            {
                await migration;
                completed = true;
            }
            else
            {
                _ = migration.ContinueWith(task => _ = task.Exception, TaskScheduler.Default);
            }
        }
        catch (Exception exception)
        {
            Check("migration_completed_without_prompt", false, Trim(exception.Message, 200));
        }
        stopwatch.Stop();
        Check(
            "migration_completed_without_prompt",
            completed,
            $"elapsed_ms={stopwatch.ElapsedMilliseconds} warnings={string.Join(",", warnings)}");
        Check(
            "migration_imported_secret_value",
            completed && await store.GetAsync(account, CancellationToken.None) == "testvalue");
        var (findAfter, _) = RunTool("/usr/bin/security", $"find-generic-password -s {service} -a {account}");
        Check("migration_deleted_keychain_item", findAfter != 0, $"find_exit_after={findAfter}");
        Check("migration_marker_written", File.Exists(secretsPath + ".migrated"));
        Check(
            "migration_secret_file_mode_0600",
            File.Exists(secretsPath) && ((int)File.GetUnixFileMode(secretsPath) & 0x1FF) == 0x180,
            File.Exists(secretsPath) ? Convert.ToString((int)File.GetUnixFileMode(secretsPath) & 0x1FF, 8) : "missing");

        stopwatch.Restart();
        await store.MigrateLegacyAsync(CancellationToken.None);
        stopwatch.Stop();
        Check("migration_second_run_is_noop", stopwatch.ElapsedMilliseconds < 2000, $"elapsed_ms={stopwatch.ElapsedMilliseconds}");
        RunTool("/usr/bin/security", $"delete-generic-password -s {service} -a {account}");
    }

    private static async Task SelfSeededMigrationSectionAsync()
    {
        Line(string.Empty);
        Line("## 2b. one-time legacy keychain migration (item owned by this binary)");
        var root = Path.Combine(Path.GetTempPath(), "lkb-harness-self-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(root);
        var secretsPath = Path.Combine(root, "secrets.json");
        var account = Environment.GetEnvironmentVariable("LKB_MIGRATION_SELF_ACCOUNT") ?? "client-agent-60";
        var service = MacKeychainSecretStore.ServiceName;
        var native = new MacKeychainNative();
        native.Delete(service, account);
        var setStatus = native.Set(service, account, Encoding.UTF8.GetBytes("self-seeded-value"));
        Check("self_seed_keychain_item_created", setStatus == MacKeychainStatus.Success, $"status={setStatus}");
        var readBack = native.Read(service, account);
        Check(
            "self_seed_keychain_item_readable_by_owner",
            readBack.Status == MacKeychainStatus.Success && Encoding.UTF8.GetString(readBack.Secret) == "self-seeded-value",
            $"status={readBack.Status}");

        var warnings = new List<string>();
        var store = new MacFileSecretStore(secretsPath, new MacKeychainNative(), TimeSpan.FromSeconds(8));
        store.WarningRaised += code => warnings.Add(code);
        var stopwatch = Stopwatch.StartNew();
        var completed = false;
        try
        {
            var migration = store.MigrateLegacyAsync(CancellationToken.None);
            var finished = await Task.WhenAny(migration, Task.Delay(TimeSpan.FromSeconds(30)));
            if (ReferenceEquals(finished, migration) && migration.IsCompletedSuccessfully)
            {
                await migration;
                completed = true;
            }
        }
        catch (Exception exception)
        {
            Check("self_seed_migration_completed", false, Trim(exception.Message, 200));
        }
        stopwatch.Stop();
        Check("self_seed_migration_completed", completed, $"elapsed_ms={stopwatch.ElapsedMilliseconds} warnings={string.Join(",", warnings)}");
        Check("self_seed_migration_imported_value", completed && await store.GetAsync(account, CancellationToken.None) == "self-seeded-value");
        var readAfter = native.Read(service, account);
        Check("self_seed_migration_deleted_keychain_item", readAfter.Status == MacKeychainStatus.NotFound, $"status={readAfter.Status}");
        Check("self_seed_migration_marker_written", File.Exists(secretsPath + ".migrated"));
        Check(
            "self_seed_migration_secret_file_mode_0600",
            File.Exists(secretsPath) && ((int)File.GetUnixFileMode(secretsPath) & 0x1FF) == 0x180,
            File.Exists(secretsPath) ? Convert.ToString((int)File.GetUnixFileMode(secretsPath) & 0x1FF, 8) : "missing");
        await store.MigrateLegacyAsync(CancellationToken.None);
        native.Delete(service, account);
    }

    private static async Task PanelEgressSectionAsync()
    {
        Line(string.Empty);
        Line("## 3. panel direct egress");
        PanelDirectEgress.Initialize();
        Check("wellknown_host_cloudflare", PanelDirectEgress.IsWellKnownHost(PanelDirectEgress.CloudflarePanelHost));
        Check("wellknown_host_aliyun", PanelDirectEgress.IsWellKnownHost(PanelDirectEgress.AliyunPanelHost));
        Check("third_party_host_not_wellknown", !PanelDirectEgress.IsWellKnownHost("example.com"));

        var provider = new SystemPhysicalEgressInterfaceProvider();
        var preferred = provider.GetPreferredInterface(AddressFamily.InterNetwork);
        Line(
            $"physical_interface_v4={preferred?.Name ?? "<none>"} index={preferred?.Index} "
            + $"gateway={preferred?.Gateway} default_route_holder={preferred?.IsDefaultRouteHolder}");
        Check("physical_interface_selected", preferred is not null);
        Check(
            "physical_interface_is_not_tunnel",
            preferred is not null && !preferred.Name.StartsWith("utun", StringComparison.OrdinalIgnoreCase),
            preferred?.Name ?? "<none>");

        var probe = new MacDefaultRouteProbe();
        var v4 = probe.GetDefault("-inet");
        var v6 = probe.GetDefault("-inet6");
        Line($"default_route_v4_interface={v4?.InterfaceName} gateway={v4?.Gateway}");
        Line($"default_route_v6_interface={v6?.InterfaceName} gateway={v6?.Gateway}");
        Line($"default_route_v4_is_physical={provider.IsDefaultRoutePhysical(AddressFamily.InterNetwork)}");
        Check("default_route_classification_available", v4 is not null, $"v4_interface={v4?.InterfaceName}");

        foreach (var adapter in NetworkInterface.GetAllNetworkInterfaces().OrderBy(item => item.Name, StringComparer.Ordinal))
        {
            Line($"iface={adapter.Name} type={adapter.NetworkInterfaceType} status={adapter.OperationalStatus}");
        }
        var addresses = NetworkInterface.GetAllNetworkInterfaces()
            .SelectMany(adapter => adapter.GetIPProperties().UnicastAddresses
                .Where(unicast => unicast.Address.AddressFamily == AddressFamily.InterNetwork)
                .Select(unicast => adapter.Name + "=" + unicast.Address))
            .ToList();
        Line("ipv4_addresses=" + string.Join(", ", addresses));

        var (utunExit, utunOut) = RunTool("/sbin/ifconfig", "utun0");
        Line(utunExit == 0
            ? "ifconfig_utun0=" + Trim(utunOut, 200)
            : "ifconfig_utun0=absent");

        await HttpProbeAsync(PanelDirectEgress.CloudflarePanelHost, "/api/health");
        await HttpProbeAsync(PanelDirectEgress.CloudflarePanelHost, "/artifacts/versions.json");
        await HttpProbeAsync(PanelDirectEgress.AliyunPanelHost, "/api/health");
        await HttpProbeAsync(PanelDirectEgress.AliyunPanelHost, "/artifacts/versions.json");
    }

    private static async Task HttpProbeAsync(string host, string path)
    {
        var url = "https://" + host + path;
        using var client = LeikwanHttpClientFactory.CreateDefault();
        var stopwatch = Stopwatch.StartNew();
        try
        {
            using var response = await client.GetAsync(url, CancellationToken.None);
            var body = await response.Content.ReadAsStringAsync();
            stopwatch.Stop();
            Check($"http_get_{host}{path}", (int)response.StatusCode == 200, $"status={(int)response.StatusCode} elapsed_ms={stopwatch.ElapsedMilliseconds} bytes={body.Length}");
            if (path.EndsWith("versions.json", StringComparison.Ordinal) && body.Length > 2)
            {
                using var document = JsonDocument.Parse(body);
                if (document.RootElement.TryGetProperty("macos_client_update_arm64", out var entry))
                {
                    Line($"manifest_macos_client_update_arm64_version={entry.GetProperty("version").GetString()} sha256={entry.GetProperty("sha256").GetString()}");
                }
            }
        }
        catch (Exception exception)
        {
            stopwatch.Stop();
            Check($"http_get_{host}{path}", false, $"{exception.GetType().Name}: {Trim(exception.Message, 200)} elapsed_ms={stopwatch.ElapsedMilliseconds}");
        }
    }

    private static async Task UpdateCheckSectionAsync()
    {
        Line(string.Empty);
        Line("## 4. update check (MacAppUpdateService, the GUI call path)");
        var bundlePath = Environment.GetEnvironmentVariable("LKB_BUNDLE") ?? "/Applications/LeiKwan Bridge.app";
        var updatesRoot = Path.Combine(Path.GetTempPath(), "lkb-harness-updates");
        Directory.CreateDirectory(updatesRoot);
        Line($"bundle={bundlePath} updates_root={updatesRoot}");

        var configPath = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.UserProfile),
            "Library", "Application Support", "LeiKwan", "ClientAgent", "config.json");
        var panelUrl = PanelDirectEgress.CloudflarePanelHost;
        if (File.Exists(configPath))
        {
            try
            {
                using var document = JsonDocument.Parse(File.ReadAllText(configPath));
                if (document.RootElement.TryGetProperty("master_url", out var master) && master.GetString() is { Length: > 0 } configured)
                    panelUrl = configured;
                Line($"config_master_url={panelUrl}");
                Line($"config_use_mac_keychain={document.RootElement.TryGetProperty("use_mac_keychain", out var keychain) && keychain.ValueKind == JsonValueKind.True}");
            }
            catch (Exception exception)
            {
                Line($"config_parse_failed={Trim(exception.Message, 160)}");
            }
        }

        var endpoints = new List<string> { "https://" + panelUrl.Replace("https://", string.Empty) };
        foreach (var option in PanelEndpoints.Options)
        {
            if (!endpoints.Contains(option.Url, StringComparer.OrdinalIgnoreCase))
                endpoints.Add(option.Url);
        }

        foreach (var endpoint in endpoints)
        {
            using var updater = new MacAppUpdateService(updatesRoot, bundlePath, "arm64");
            var stopwatch = Stopwatch.StartNew();
            try
            {
                using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(45));
                var artifact = await updater.CheckAsync(
                    endpoint,
                    "1.0.28",
                    new Progress<UpdateProgress>(_ => { }),
                    timeout.Token);
                stopwatch.Stop();
                Check(
                    $"update_check_{new Uri(endpoint).Host}",
                    true,
                    artifact is null
                        ? $"up_to_date elapsed_ms={stopwatch.ElapsedMilliseconds}"
                        : $"update_available version={artifact.Version} sha256={artifact.Sha256} elapsed_ms={stopwatch.ElapsedMilliseconds}");
            }
            catch (Exception exception)
            {
                stopwatch.Stop();
                Check(
                    $"update_check_{new Uri(endpoint).Host}",
                    false,
                    $"{exception.GetType().Name}: {Trim(exception.Message, 200)} elapsed_ms={stopwatch.ElapsedMilliseconds}");
                Line("update_check_exception=" + Trim(exception.ToString(), 800));
            }
        }
    }
}
