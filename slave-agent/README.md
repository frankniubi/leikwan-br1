# Slave Agent

`slave_agent.py` 负责：

1. 节点注册。
2. 定时心跳。
3. 拉取 Master 任务。
4. 写入 `wstunnel server` 的 systemd unit。
5. 启停、删除服务。
6. 使用 `nftables` 记录端口流量并回报给 Master。

## 运行方式

注册：

```bash
python3 slave_agent.py --config /etc/leikwan-slave/config.json register
```

常驻运行：

```bash
python3 slave_agent.py --config /etc/leikwan-slave/config.json run
```

查看版本：

```bash
python3 slave_agent.py version
```

安装器在目标机上还会写入：

```bash
/usr/local/bin/leikwan-slave-update check
/usr/local/bin/leikwan-slave-update
```
