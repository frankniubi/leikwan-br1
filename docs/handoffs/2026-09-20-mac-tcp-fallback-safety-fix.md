# Mac TCP fallback 确定性缺陷修复

日期：2026-09-20。此报告只证明列出的代码缺陷，不把它们直接认定为广港/广新互踢或之前400次EOF的全部根因。

## 修复前的确定性反例

`tools/ultra-client/ha_fallback_safety_regression_test.go` 最初两个测试单轮均FAIL：

1. 单候选两次业务失败进入隔离；`dialCandidateStream` 却通过active fallback重新使用它，且只凭握手成功执行`ClearPathFailure`。整个实验没有任何远端业务回包。
2. fallback的dial期间执行`Revoke(policyGeneration)`；函数仍返回非空连接、`err=nil`，缺少返回前授权复核。

实验使用`net.Pipe`，不需要真实provider、账号、sidecar或公网流量。没有启动新的10分钟压测。

## 代码修复

- `FailSilentPath`记录业务失败隔离来源，与只因探测/建连失败的隔离区分。
- 重复业务读reset导致的隔离有效期内，active fallback不能绕过；健康候选仍可使用。只有单候选且仍在隔离时返回无可用候选；隔离到期后允许恢复尝试，不永久锁死。
- 探测误判时保留active fallback；握手成功不再清除隔离。收到远端业务字节后，原`noteRelayOutcome`才清除对应TCP路径的隔离。
- 普通候选和fallback共享有界、可取消的dial worker；晚到连接自动关闭，worker自行持有并清理租约凭据副本。
- fallback返回前重新核对租约撤销、过期、上下文取消和新产生的业务隔离；无效连接关闭，不返回给调用方。
- TCP全路径故障收敛只清TCP失败计数，不再清UDP失败计数。
- 不把普通EOF、业务目标拒绝或本地写失败提升为全局线路信誉惩罚；不重放已经发送的业务payload。

原fallback测试现在明确分两步：握手成功仍隔离，实际远端响应后才恢复。撤销回归使用probe-only隔离且断言dial实际执行，避免因business隔离被提前拒绝而“假通过”。

## 本地验证

- Windows对应专项单轮PASS，1.630秒：`fallback-fix-windows.log`。
- Linux/WSL相同专项`-race -count=3` PASS，2.951秒：`fallback-fix-linux-race.log`。
- Mac原生同一专项单轮PASS，退出码0：`fallback-fix-mac-native.log`。
- UoT/UDP相关本地回归单轮PASS，15.940秒：`fallback-fix-uot-regression.log`。这是协议代码回归，不是全方向实测。
- 包含新的隔离绕过、dial中撤销、取消与晚到连接清理、隔离到期恢复、TCP/UDP计数隔离测试，以及原有TCP读写/half-close/真实loopback reset专项。
- `git diff --check`无空白错误。

这些是代码回归证明，不是生产线路或11方向UoT验收。

## 备份和部署记录

本次改动前的四个相关文件已单独备份在：
`tmp/mac-tcp-path-20260920/fallback-fix-backup/`。

Mac测试/部署脚本：`tmp/mac-tcp-path-20260920/deploy_fallback_fix.py`。
它先运行Mac原生回归，只有通过后才执行安装；旧helper的版本和SHA必须匹配`.4`已知版本。保存helper、manifest和状态快照，再替换helper、更新manifest、签名验证和重启测试Mac app；失败时恢复旧helper和manifest。

测试Mac已部署`2026.09.20.5.52d81766`，安装脚本返回`DEPLOY_OK`，app签名深度验证通过。备份：
`/Users/leikwan/backups/mac-tcp-fallback-20260920-134458/`。

- 构建产物SHA-256：`e2b5b7923e1e594a3a857862487b3aa9962d2d2a54033c956b96a43141a5321c`。
- Mac重新签名后的安装SHA-256：`4492bbcb99739a5ff85a005d6f7035fc7e4c3f88cac120751d77f6332c0374ba`。签名改变二进制字节，因此不与未签名产物混用。
- 2026-09-20 13:46:37 UTC，Panel session333已回读`.5`版本和相同安装SHA；manifest也与安装helper一致。
- Mac connected=true；forward158/159/170/171为running，180维持stopped；198/199在Panel仍disabled。运行状态不是业务载荷验证。
- 证据：`fallback-fix-deploy-receipt.json`、`fallback-fix-panel-readback.json`、`fallback-fix-mac-readback.json`。

## 仍未完成

- `.5`真实HK/SG业务流10分钟验收尚未执行；旧400次失败的provider侧关闭原因仍未证实。
- 全11方向Mac/Linux UoT能力验收仍未完成，授权、材料和sidecar绑定前置缺口见全方向矩阵。
- 未发布公共更新包，未部署Windows或Linux客户端，未提交或push。
