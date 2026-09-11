# Leikwan 会聚 App for OpenWrt

路由器侧的 Leikwan 会聚客户端。三个 opkg 包 + 一个固件构建脚本。

完整设计、运维手册与边界说明见 [`docs/openwrt-convergence-app.md`](../docs/openwrt-convergence-app.md)。

## 包

| 包 | 安装内容 | 说明 |
|---|---|---|
| `leikwand` | `/usr/bin/leikwand`、`/etc/config/leikwan`、`/etc/init.d/leikwand` | 守护进程（Go，静态，无 libc 依赖） |
| `leikwan-runtime` | `/usr/libexec/leikwan/ultra-client` | 转发运行时（`tools/ultra-client`） |
| `luci-app-leikwan` | LuCI 视图、menu.d、acl.d、rpcd ucode 桥 | 图形界面 |

目标：**OpenWrt 24.10**，`x86_64` 与 `aarch64`。

## 目录

```
openwrt/
  leikwand/            # 守护进程包（Makefile、procd init、UCI 默认值、uci-defaults）
  leikwan-runtime/     # 运行时包
  luci-app-leikwan/    # LuCI 包（Makefile、menu.d、acl.d、rpcd ucode、7 个视图）
  image/               # ImageBuilder 固件构建
  build.sh             # 交叉编译两个 Go 程序并交给 SDK 打包
  build-ipk.sh         # 带版本号的封装
```

## 构建

前置：OpenWrt 24.10 SDK（与目标架构一致）、Go 1.21+（用于交叉编译两个二进制）。

```sh
export LEIKWAN_SDK=$HOME/leikwan-build/sdk
openwrt/build-ipk.sh amd64        # 或 arm64
```

脚本会：

1. 用 `CGO_ENABLED=0` 交叉编译 `leikwand` 与 `ultra-client`（静态，无 libc 依赖，musl 直接可用）；
2. 把三个包目录复制进 SDK 的 `package/leikwan/`；
3. 依次 `make package/leikwan/<pkg>/compile`；
4. 打印产出的 `.ipk` 路径。

### 固件

```sh
export LEIKWAN_IB=$HOME/leikwan-build/ib
export LEIKWAN_IPK_DIR=<上一步的 bin/packages/<arch>/base>
openwrt/image/build-image.sh amd64
```

固件预装 luci + 三个包，并预置面板地址。**固件里不含任何机密**：刷完机只需要在
LuCI 里输入一个配对码。

## 为什么 `luci-app-leikwan` 不用 `luci.mk`

它只有脚本（JS 视图 + ucode rpcd 后端 + JSON 菜单/ACL），没有任何 `.po` 翻译文件
（界面文案是中英双语内联的）。因此 `luci.mk` 的 i18n 机制带来不了收益，
却会强迫每次构建都克隆整个 LuCI feed。用普通包声明 `DEPENDS:=+luci-base` 即可。

## 运行测试

```sh
# 守护进程：单元测试（含网关配置结构、L2 多 WAN 决策过程）
cd tools/leikwand && go test ./...

# LuCI 界面：真实渲染 7 个页面 + 交叉校验 rpc/rpcd/ACL 契约
node openwrt/luci-app-leikwan/tests/run-tests.cjs

# 证明上面那套测试确实能抓到缺陷（注入 6 个真实缺陷）
node openwrt/luci-app-leikwan/tests/mutation-check.cjs

# 网关配置 × 真实 sing-box（下载 OpenWrt 24.10 的 sing-box 与 musl 运行库）
tools/leikwand/integration/validate_gateway_config.sh <linux-leikwand 二进制>

# 端到端：真守护进程 + 真运行时 + 模拟面板 + 假中转
tools/leikwand/integration/run_integration.sh <leikwand> <ultra-client>
```

## 文件模式注意事项

`root/usr/libexec/rpcd/luci.leikwand` 必须是可执行文件，否则 rpcd 会静默忽略它。
Windows 检出无法表达 Unix 执行位，因此包的 Makefile 用 `INSTALL_BIN`（强制 0755）
安装这两个脚本，**构建产物与源文件模式无关**。

如果你要在路由器上直接使用这个源码树（而不是 ipk），请自行 `chmod +x`：

```sh
chmod +x luci-app-leikwan/root/usr/libexec/rpcd/luci.leikwand
chmod +x luci-app-leikwan/root/etc/uci-defaults/90-luci-leikwan
chmod +x leikwand/files/leikwand.init leikwand/files/leikwand.uci-defaults
```
