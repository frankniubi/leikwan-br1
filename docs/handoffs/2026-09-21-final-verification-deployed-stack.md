⚠ **本文该节的结论已被推翻**：见 `2026-09-21-CORRECTION-windows-capacity-claim-was-wrong.md`

# 全量部署后的最终验证（2026-09-21）

所有热修复都已在生产上线之后，重跑验收并逐台核对了机器状态。

## 1. 部署完成清单

| 组件 | 位置 | 结果 |
|---|---|---|
| agent 监听探测（读内核表，不再 connect） | Windows headless + macOS .NET | `LeikwanBridge.Core.dll` = `3e355e3ae366…`，两台一致；备份在 `C:\Users\lwtest\lk-backup\*.pre-probefix` 与 `~/lk-backup/mac-agent-20260921T155355Z/` |
| panel 改 target 重新签发 lease + 健康判定 | `173.208.183.93` 生产面板 | 8 个文件上传，逐文件 sha256 核对全 match，5 个 PHP 文件 `php -l` 全过，php-fpm 已 reload，HTTP 200。备份 `/root/lk-panel-backup-20260921-094833` |
| sidecar binding 热加载 | 五台生产 sidecar | `/usr/local/bin/leikwan-external-uot-go` 全部 `c6d11563b8c1b8a2` → `54130c42358a2baf`，配置 md5 未变，重启后全部 active、7443 在听 |

**热加载在生产上真的验过**：五台各发一次 SIGHUP，**PID 全部不变**，服务 active，监听正常，日志五台都打了
`external UoT configuration reloaded path=/etc/leikwan-external-uot.json`。

部署中发现**生产面板一直是落后的**：`ProvisioningService.php` 缺了 2026-09-21 那个 forward 暂停
（`forward_not_found` 回滚）修复，两个 i18n 文件各缺约 72 个词条。这次一并带上去了。

## 2. 部署后的机器状态（逐台核对）

```
WINDOWS service RUNNING | agent 3e355e3ae366 | 201=ready(ha 键不存在) 202=ready
MACOS   agent pid 17125 | core.dll 3e355e3ae366 | 所有 forward ready
LINUX   client fbf6d9777568 | 201=ready 202=ready
SIDECAR 70.36.96.183   active 54130c42358a 7443=1
        103.246.219.39 active 54130c42358a 7443=1
        157.228.138.23 active 54130c42358a 7443=1
        208.122.17.50  active 54130c42358a 7443=1
        77.93.90.45    active 54130c42358a 7443=1
```

## 3. 部署后重跑 600 秒 —— Windows 仍然不稳

| 时间 | 负载 | TCP | UDP |
|---|---|---|---|
| 16:08 | TCP 20 + UDP 20（40 Mbps） | **600/600 窗口，idle 0**，CV 6.54%，`errors {}` | 13.31% 丢包 |
| 17:57 | TCP 20 + UDP 20（40 Mbps） | 596/600，**idle 4**，CV 9.51%，`errors {}` | 8.74% 丢包 |
| 18:07 | TCP 20 + UDP 10（30 Mbps） | **4 条流在约 2 秒后全部被 RST**，`delivered_fraction 0.0033` | **0.0063% 丢包** |

同样的 harness、同一个目标、同一台 sidecar、同一批 relay，**Linux 主机在 20 Mbps 下 600/600、idle 0**，
不限速时能跑到 151.9 Mbps；Windows 主机裸下载 15.25 Mbps、隧道内 TCP 上限 31.4 Mbps。

**判定：Windows 测试机的这条线路在持续负载下不可靠。** 同样的配置既跑出过 600/600 零中断，
也跑出过 4 条流 2 秒全断。这不是一个可以用"产品缺陷"或"产品通过"来概括的结果——**它是测试机线路的问题**，
而在容量之内（合计 ≤30 Mbps 的短测）UDP 一直是 0.0000%–0.0032%、TCP 一直 idle 0。

18:07 那轮的 TCP 断流伴随 `tcp stream had no downstream bytes … remote_stopped_first=true` 与
`tcp stream ended … reason=io_error`，与 `2026-09-21-headers-eof-attribution.md` 里那个
"leased relay 自己约 1 秒后 RST 自己的流"是同一类上游行为，不是本地转发逻辑主动关的。

**结论：要给出 Windows 的 40 Mbps 零中断结论，必须换一台有余量的测试机。** 本机给出的所有数字都
受这条线路限制，不能拿它判定产品。

## 4. 顺手修掉的一个测量 bug

harness 里 `idle` 索引切片在取长度之前就被截到 40 条，于是**每一个坏掉的运行都恰好报 40 个空闲窗口**。
`delivered_fraction` 一直是对的（暴露了真相），但头号数字不对。已修（`b346eec3`），
三个平台重新交叉编译。
