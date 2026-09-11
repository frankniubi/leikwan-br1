# luci-app-leikwan

LuCI web interface for **leikwand**, the Leikwan 会聚 forwarding/egress agent for
OpenWrt 24.10. The package is script-only: JSON menus, a ucode rpcd backend and
client-side JavaScript views — nothing is compiled.

会聚（Leikwan）OpenWrt 24.10 的 LuCI 管理界面。软件包只包含脚本：JSON 菜单、
ucode 编写的 rpcd 后端，以及 LuCI 的 JS 视图，没有任何编译步骤。

## How it talks to the agent / 与主程序的通信方式

LuCI never opens the control socket itself. Every view calls the rpcd object
**luci.leikwand**, which runs **leikwand ctl <command> [k=v ...]** as root and
republishes the single JSON line (**{"ok":true,"data":...}** or
**{"ok":false,"error":"..."}**) it prints on stdout.

LuCI 不直接访问控制套接字：所有视图都通过 rpcd 对象 **luci.leikwand**，由它以 root
身份执行 **leikwand ctl <命令> [k=v ...]**，并把命令输出的那一行 JSON 原样返回。

* The rpcd backend is **root/usr/libexec/rpcd/luci.leikwand** (ucode). It implements
  status, forwards, logs, diagnose, test_connection, enroll, pair, sign_out,
  forward_action, reload, get_config and set_config.
* set_config accepts only the documented non-secret UCI options of
  /etc/config/leikwan, validates every value against its type, bounds and
  enumeration whitelist, commits UCI and then calls the reload control command.
* A stopped or missing leikwand never raises an ubus error: the backend answers
  {"ok":false,"error":"agent_unavailable"} and each page renders a
  “服务未运行 (service stopped)” notice instead of throwing.

## Pages / 页面

| Menu 菜单 | View 视图 | Description 说明 |
| --- | --- | --- |
| 首次配置向导 (First-boot setup) | setup.js | Visible only while /etc/leikwan/secret.json is absent, i.e. before the device is enrolled. 仅在设备尚未注册时显示。 |
| 概览 (Overview) | overview.js | Live status card (panel, client, device, mode, uptime, last sync, errors), the forward table with RTT / p95 / loss / candidates / traffic and Start-Stop-Restart buttons, plus a compact uplink strip and a gateway strip. Auto-refreshes every 5 s. 实时状态卡、线路表格（延迟/丢包/候选/流量与启停控制）、链路与网关摘要，每 5 秒刷新。 |
| 配置向导 (Setup Wizard) | setup.js | Two ways online: a pairing code issued by the panel (fastest) or the panel account e-mail + password with an optional client ID. Includes the panel URL field, “save panel URL”, “test connection” and result banners. 两种上线方式：配对码或账号密码；含面板地址、保存、连通性测试与结果提示。 |
| 转发线路 (Forward Lines) | lines.js | Per-line detail: candidate count, selection tier, RTT/p95/loss, HA failures, lease countdown, zone, restarts, traffic and per-line Start/Stop/Restart. 每条线路的详细指标、租约倒计时与启停控制。 |
| 路由与网关 (Routing & Gateway) | routing.js | Operating mode, gateway mode (carrier line, route scope/policy, TUN address and MTU, DNS mode and servers, bypass-CN, LAN redirect) and uplink HA (interfaces, probe targets, interval/timeout/failure and recovery thresholds, scope, route table, fwmark). 网关模式与多 WAN 高可用配置。 |
| 多 WAN 高可用 (Uplinks) | uplinks.js | Live WAN state: up/healthy/active, gateway, metric, latency, loss, consecutive successes/failures, time since the last switch and the last error. 各 WAN 链路的实时健康与切换状态。 |
| 日志与诊断 (Logs & Diagnostics) | logs.js | Log viewer with a level filter, manual/10 s auto refresh, a “run diagnostics” button that renders every check as pass/fail and copy/download of the JSON report. 日志查看、级别过滤与诊断报告的复制/下载。 |
| 高级设置 (Advanced) | advanced.js | Panel and fallback URL, device name, enable switch, sync intervals, cold-start grace, restart cooldown, lease renew lead, max candidates, log level, auto-update, runtime paths, sign-out (with confirmation) and “reload configuration”. 面板地址、周期参数、运行路径以及注销/重新加载操作。 |

## Security notes / 安全说明

* The agent token is never part of the web UI. It is not in UCI, not in any form
  and not in any RPC reply; leikwand keeps it in /etc/leikwan/secret.json
  (mode 0600) and privileged control commands read their own per-boot token from
  /var/run/leikwand/ctl.token.
* 凭证不进入 Web 界面：UCI 里没有 token，表单里没有 token，RPC 返回里也没有；
  它只保存在 /etc/leikwan/secret.json（0600）。
* The account password typed into the setup wizard is used once, is never stored
  in UCI and is cleared from the DOM after the attempt. It reaches the agent
  through the documented leikwand ctl enroll email=... password=... contract,
  i.e. as an argument of a short-lived root process.
* The ACL in root/usr/share/rpcd/acl.d/luci-app-leikwan.json grants read/write on
  uci: leikwan, the luci.leikwand methods, read access to /var/run/leikwand/* and
  exec on /usr/bin/leikwand only.

## Files / 文件

    Makefile                                       OpenWrt package (LUCI_DEPENDS:=+luci-base +leikwand, recommends sing-box)
    root/etc/uci-defaults/90-luci-leikwan          creates leikwan.main once, clears the LuCI cache
    root/usr/share/luci/menu.d/luci-app-leikwan.json
    root/usr/share/rpcd/acl.d/luci-app-leikwan.json
    root/usr/libexec/rpcd/luci.leikwand            ucode rpcd backend
    htdocs/luci-static/resources/view/leikwan/     overview, setup, lines, routing, uplinks, logs, advanced

## Notes and assumptions / 说明与前提

* The menu lives under admin/services/leikwan. The first-boot entry keys off
  /etc/leikwan/secret.json, i.e. the default data_dir; if data_dir is moved the
  entry simply stays hidden and the wizard remains reachable through
  “配置向导 (Setup Wizard)”.
* uplink_scope is a free-form lowercase token in the backend whitelist because the
  agent accepts any token for it; the UI ships the default relay.
* gateway_dns_mode offers remote and local, matching the two DNS detours the agent
  renders into the sing-box configuration.
* Numeric values are written to UCI as plain decimal strings (for example
  uplink_fwmark 19531), which is what the agent parses.
