# TCP acceleration toolkit (Debian 12 / Python-only)

这个目录是一套无需编译的通用 TCP 加速旁路工具。核心脚本是 `tcp_accel.py`：本地接入侧终止一条普通 TCP 连接，把字节流拆成多条长期 TCP lane 发到远端 sidecar，远端按 chunk 顺序重组后再连接真实目标。反向数据也走同一组 lane，因此可以直接加速 `iperf3 -R`、HTTPS、SSH、数据库等普通 TCP 业务。

## 能解决什么

- 单条 TCP 在弱线路或慢启动/丢包下起速慢：用多条 lane 并行起速。
- 多线路共同协调：`remotes` 可写多个远端入口，lane 按顺序轮询分配。
- 任意 `ip:port`：
  - 固定端口映射：本地 `listen` -> 远端真实 `target`。
  - SOCKS5：应用按 SOCKS5 CONNECT 指定任意目标，远端 sidecar 动态连接。
- 不改 wire protocol，不需要内核模块，不需要编译 Rust/C；Debian 12 自带 `python3` 即可运行。

> 只加速 TCP。UDP/QUIC/WireGuard 本身不会被这个工具直接加速；如果要加速某个 TCP 代理或 TCP 封装入口，把那个 TCP 端口放到 `forwards` 或 SOCKS5 后面即可。

## 远端是否需要 sidecar？

需要。远端必须运行同一个 `tcp_accel.py server`，它就是 sidecar。原因是本地 client 发出去的是多条加速 lane，不是真实业务 TCP；必须由远端 sidecar 重组后再连接真实目标。

最简单拓扑：

```text
应用/本机 -> tcp_accel client -> 多条 TCP lane -> tcp_accel server(sidecar) -> 任意真实目标 ip:port
```

多线路拓扑：

```text
应用/本机 -> tcp_accel client
           -> lane1 -> bridge/socks/line1 -> tcp_accel server -> target
           -> lane2 -> bridge/socks/line2 -> tcp_accel server -> target
           -> lane3 -> bridge/socks/line3 -> tcp_accel server -> target
```

## Debian 12 一键安装

在本 worktree 根目录运行：

```bash
sudo bash tools/tcp-accel/install_debian12.sh all
```

安装内容：

- `/opt/leigod/tcp-accel/tcp_accel.py`
- `/opt/leigod/tcp-accel/README.md`
- `/opt/leigod/tcp-accel/examples/`
- `/etc/leigod/tcp-accel/token`
- `leigod-tcp-accel-server.service`
- `leigod-tcp-accel-client@.service`
- 如果 worktree 里有 `scripts/leigod_bond.py`，同时安装 `leigod-tcp-accel-bridge@.service`

分角色安装也可以：

```bash
sudo bash tools/tcp-accel/install_debian12.sh server
sudo bash tools/tcp-accel/install_debian12.sh client
sudo bash tools/tcp-accel/install_debian12.sh bridge
```

## 1. 启动远端 sidecar

远端 Debian 12：

```bash
sudo install -m 0600 tools/tcp-accel/examples/server.env /etc/leigod/tcp-accel/server.env
sudo editor /etc/leigod/tcp-accel/server.env
sudo systemctl enable --now leigod-tcp-accel-server.service
sudo systemctl status leigod-tcp-accel-server.service --no-pager
```

默认监听：`0.0.0.0:29030`。请在防火墙上只放行可信 client/bridge 的来源 IP。

远端 token 必须和 client 使用的 token 一致。最简单做法是在 server 生成 `/etc/leigod/tcp-accel/token` 后，把同一份 token 安全复制到 client 的同路径。

## 2. 固定端口映射 + SOCKS5 任意目标

本地/接入侧 Debian 12：

```bash
sudo cp /etc/leigod/tcp-accel/client-example.json /etc/leigod/tcp-accel/client-main.json
sudo editor /etc/leigod/tcp-accel/client-main.json
sudo python3 /opt/leigod/tcp-accel/tcp_accel.py check-client-config --config /etc/leigod/tcp-accel/client-main.json
sudo systemctl enable --now leigod-tcp-accel-client@main.service
sudo journalctl -u leigod-tcp-accel-client@main.service -f
```

最小配置：

```json
{
  "token_file": "/etc/leigod/tcp-accel/token",
  "remotes": ["SERVER_PUBLIC_IP:29030"],
  "lanes": 4,
  "minimum_lanes": 1,
  "join_timeout_ms": 1200,
  "chunk_size": 32768,
  "lane_queue_frames": 8,
  "tcp_user_timeout_ms": 30000,
  "forwards": [
    {"name": "web", "listen": "0.0.0.0:15443", "target": "1.1.1.1:443"}
  ],
  "socks5": [
    {"name": "any-target", "listen": "0.0.0.0:1080"}
  ]
}
```

使用固定映射：

```bash
curl -vk --resolve example.local:15443:127.0.0.1 https://example.local:15443/
```

使用 SOCKS5 任意目标：

```bash
curl --socks5-hostname 127.0.0.1:1080 https://1.1.1.1/
ssh -o ProxyCommand='nc -x 127.0.0.1:1080 %h %p' user@TARGET_HOST
```

测速例子：把 `forwards` 写成 `0.0.0.0:15210 -> 10.7.1.1:5210` 后：

```bash
iperf3 -c 127.0.0.1 -p 15210 -R -P 1 -t 60
```

## 3. 多线路协同

`remotes` 中的每一项都是一个 lane 入口。直接多 sidecar：

```json
"remotes": [
  "SERVER_A_PUBLIC_IP:29030",
  "SERVER_B_PUBLIC_IP:29030",
  "SERVER_C_PUBLIC_IP:29030"
]
```

如果多条线路只能通过本机不同 SOCKS/专线出口访问同一个远端 sidecar，可以先启动 bridge，再让 client 连 bridge 端口：

```bash
sudo cp /etc/leigod/tcp-accel/bridge-example.json /etc/leigod/tcp-accel/bridge-main.json
sudo editor /etc/leigod/tcp-accel/bridge-main.json
sudo systemctl enable --now leigod-tcp-accel-bridge@main.service
```

然后 client 使用：

```json
"remotes": [
  "BRIDGE_HOST:8230",
  "BRIDGE_HOST:8231",
  "BRIDGE_HOST:8232",
  "BRIDGE_HOST:8233"
]
```

推荐起步参数：

- `lanes`: 4
- `minimum_lanes`: 1 或 2；弱线路场景建议 1，避免坏腿拖死全连接。
- `chunk_size`: 32768；延迟更低可试 16384，追峰值可试 65536。
- `lane_queue_frames`: 8；避免单 lane 堆太深导致 ACK/反馈滞后。
- `tcp_user_timeout_ms`: 30000。

## 4. 直接前台运行（不用 systemd）

远端：

```bash
python3 tools/tcp-accel/tcp_accel.py --log-level INFO server \
  --listen 0.0.0.0:29030 \
  --token-file /etc/leigod/tcp-accel/token
```

本地固定目标：

```bash
python3 tools/tcp-accel/tcp_accel.py --log-level INFO client \
  --listen 0.0.0.0:15210 \
  --remote SERVER_PUBLIC_IP:29030 \
  --target 10.7.1.1:5210 \
  --lanes 4 \
  --minimum-lanes 1 \
  --chunk-size 32768 \
  --lane-queue-frames 8 \
  --tcp-user-timeout-ms 30000 \
  --token-file /etc/leigod/tcp-accel/token
```

本地多映射/SOCKS5：

```bash
python3 tools/tcp-accel/tcp_accel.py --log-level INFO client-config \
  --config tools/tcp-accel/examples/client-direct.json
```

## 5. 本地验证

在 worktree 根目录：

```bash
python3 -m unittest discover -s tools/tcp-accel -p 'test_*.py' -v
python3 -m py_compile tools/tcp-accel/tcp_accel.py tools/tcp-accel/test_tcp_accel.py
python3 -m json.tool tools/tcp-accel/examples/client-direct.json >/dev/null
python3 -m json.tool tools/tcp-accel/examples/client-through-bridge.json >/dev/null
python3 -m json.tool tools/tcp-accel/examples/bridge.json >/dev/null
bash -n tools/tcp-accel/install_debian12.sh
```

也可以运行：

```bash
bash tools/tcp-accel/selftest_debian12.sh
```

## 回滚

```bash
sudo systemctl disable --now leigod-tcp-accel-client@main.service || true
sudo systemctl disable --now leigod-tcp-accel-server.service || true
sudo systemctl disable --now leigod-tcp-accel-bridge@main.service || true
```

如果原业务只是把目标从真实 `ip:port` 改到本地加速端口，回滚时把业务配置切回原 `ip:port` 即可。