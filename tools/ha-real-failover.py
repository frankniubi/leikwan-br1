#!/usr/bin/env python3
"""Guarded production sidecar failover drill for GitHub Actions.

The workflow supplies credentials through environment variables only.  This
tool deliberately stops one sidecar, waits for the panel TTL to expire, asks
the deployed pool implementation to assign the same real forward/client tuple
again, probes the selected standby's real UoT/DNS path, and restores the
faulted service.  It never prints passwords, bearer secrets, or response
bodies.
"""

from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import shlex
import subprocess
import sys
import time


WORK = Path(".gha-ha")
WORK.mkdir(exist_ok=True)
CLIENT_FILE = WORK / "client.json"


def required(name: str) -> str:
    value = os.environ.get(name, "")
    if not value:
        raise SystemExit(f"missing required environment variable: {name}")
    return value


def nodes() -> list[dict]:
    try:
        value = json.loads(required("ULTRA_HA_NODES_JSON"))
    except json.JSONDecodeError as exc:
        raise SystemExit(f"ULTRA_HA_NODES_JSON is not valid JSON: {exc}") from exc
    if not isinstance(value, list) or not value:
        raise SystemExit("ULTRA_HA_NODES_JSON must be a non-empty array")
    for node in value:
        if not isinstance(node, dict) or not node.get("id") or not node.get("host"):
            raise SystemExit("each HA node needs id and host")
        if not node.get("target"):
            raise SystemExit(f"HA node {node.get('id')} has no target")
    return value


def node_for(node_id: str) -> dict:
    for node in nodes():
        if node["id"] == node_id:
            return node
    raise SystemExit(f"fault node not present in ULTRA_HA_NODES_JSON: {node_id}")


def ssh(node: dict, command: str, *, stdin: bytes | None = None, timeout: int = 20) -> str:
    password = str(node.get("password") or "")
    if not password:
        raise SystemExit(f"node {node['id']} has no SSH password in the Actions secret")
    user = str(node.get("user") or "root")
    port = str(node.get("port") or 22)
    env = os.environ.copy()
    env["SSHPASS"] = password
    argv = [
        "sshpass", "-e", "ssh",
        "-o", "BatchMode=no",
        "-o", "StrictHostKeyChecking=no",
        "-o", "UserKnownHostsFile=/dev/null",
        "-o", "ConnectTimeout=8",
        "-p", port,
        f"{user}@{node['host']}",
        command,
    ]
    result = subprocess.run(
        argv, input=stdin, capture_output=True, timeout=timeout, env=env
    )
    output = (result.stdout + result.stderr).decode("utf-8", "replace")
    if result.returncode:
        raise SystemExit(f"SSH command failed on {node['id']} (exit {result.returncode}): {output[-500:]}")
    return output


def panel_node() -> dict:
    return {
        "id": "panel",
        "host": required("ULTRA_HA_PANEL_SSH_HOST"),
        "port": os.environ.get("ULTRA_HA_PANEL_SSH_PORT", "22"),
        "user": os.environ.get("ULTRA_HA_PANEL_SSH_USER", "root"),
        "password": required("ULTRA_HA_PANEL_SSH_PASSWORD"),
    }


def panel_php(script: str) -> str:
    panel = panel_node()
    remote = "/tmp/gha-ultra-ha-probe.php"
    ssh(panel, f"cat > {remote}", stdin=script.encode("utf-8"))
    try:
        return ssh(panel, f"php {remote}")
    finally:
        try:
            ssh(panel, f"rm -f {remote}")
        except SystemExit:
            pass


def json_line(output: str) -> dict:
    for line in reversed(output.splitlines()):
        try:
            value = json.loads(line)
        except json.JSONDecodeError:
            continue
        if isinstance(value, dict):
            return value
    raise SystemExit(f"expected JSON result, got: {output[-500:]}")


def state_summary() -> dict:
    script = r"""
from pathlib import Path
import json, time
root = Path('/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/storage/uot-pool')
rows = []
for path in root.glob('*.json'):
    try:
        item = json.loads(path.read_text())
        rows.append({
            'target': item.get('target'),
            'healthy': item.get('healthy') is True,
            'age_s': round(time.time() - int(item.get('at', 0))),
        })
    except Exception:
        pass
print(json.dumps({'states': rows}, separators=(',', ':')))
"""
    return json_line(ssh(panel_node(), f"python3 - <<'PY'\n{script}\nPY"))


def chosen_client(origin: str, forward_id: int, desired_target: str) -> dict:
    # This executes the deployed ExternalUoTPool implementation, not a local
    # reimplementation, so the drill follows the exact production hash.
    php = f"""<?php
ini_set('display_errors', '0');
error_reporting(0);
spl_autoload_register(static function (string $class): void {{
    $prefix = 'Leikwan\\\\Master\\\\';
    if (!str_starts_with($class, $prefix)) return;
    $path = '/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/' .
        str_replace(chr(92), '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) require_once $path;
}});
$config = new Leikwan\\Master\\Config('/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/.env');
$pool = new Leikwan\\Master\\Support\\ExternalUoTPool($config);
for ($client = 1; $client <= 200000; $client++) {{
    $forward = [[
        'id' => {forward_id},
        'external_uot' => [
            'enabled' => true,
            'sidecar_target' => {json.dumps(origin)},
            'token' => 'gha-drill',
        ],
    ]];
    try {{ $result = $pool->assign($client, 'github-actions-ha-drill', $forward); }}
    catch (Throwable $e) {{ continue; }}
    $target = $result[0]['external_uot']['sidecar_target'] ?? '';
    if ($target === {json.dumps(desired_target)}) {{
        echo json_encode(['client_id' => $client, 'target' => $target, 'forward_id' => {forward_id}]), chr(10);
        exit(0);
    }}
}}
fwrite(STDERR, "no client hash assigned to requested target\\n");
exit(2);
"""
    return json_line(panel_php(php))


def assigned_client(client: dict, origin: str) -> dict:
    php = f"""<?php
ini_set('display_errors', '0');
error_reporting(0);
spl_autoload_register(static function (string $class): void {{
    $prefix = 'Leikwan\\\\Master\\\\';
    if (!str_starts_with($class, $prefix)) return;
    $path = '/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/src/' .
        str_replace(chr(92), '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) require_once $path;
}});
$config = new Leikwan\\Master\\Config('/www/wwwroot/v3.leikwanhost.com/leikwan-v3/master/.env');
$pool = new Leikwan\\Master\\Support\\ExternalUoTPool($config);
$forward = [[
    'id' => {int(client['forward_id'])},
    'external_uot' => [
        'enabled' => true,
        'sidecar_target' => {json.dumps(origin)},
        'token' => 'gha-drill',
    ],
]];
try {{
    $result = $pool->assign({int(client['client_id'])}, 'github-actions-ha-drill', $forward);
    echo json_encode(['target' => $result[0]['external_uot']['sidecar_target'] ?? '']), chr(10);
}} catch (Throwable $e) {{
    echo json_encode(['error' => get_class($e)]), "\\n";
    exit(3);
}}
"""
    return json_line(panel_php(php))


def probe(node: dict) -> None:
    command = r"""python3 - <<'PY'
import importlib.util, json
spec = importlib.util.spec_from_file_location("pool_sync", "/usr/local/sbin/leikwan-uot-pool-sync.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
settings = json.load(open("/etc/leikwan-uot-pool-sync.json"))
config = json.load(open(settings.get("config_path", "/etc/leikwan-external-uot.json")))
module.probe(config)
print("real_uot_dns_probe=PASS bindings=%d" % len(config.get("forwards", {})))
PY"""
    output = ssh(node, command, timeout=30)
    if "real_uot_dns_probe=PASS" not in output:
        raise SystemExit(f"real UoT probe did not pass on {node['id']}")
    print(f"{node['id']}: {output.strip().splitlines()[-1]}")


def preflight(args: argparse.Namespace) -> None:
    if os.environ.get("HA_CONFIRM_PRODUCTION", "").lower() != "true":
        raise SystemExit("set HA_CONFIRM_PRODUCTION=true through the protected Actions environment")
    fault = node_for(args.fault_node)
    for node in nodes():
        output = ssh(node, "systemctl is-active leikwan-external-uot.service; systemctl is-active leikwan-uot-pool-sync.service", timeout=15)
        states = [line.strip() for line in output.splitlines() if line.strip() in {"active", "inactive", "failed", "unknown"}]
        if states.count("active") < 2:
            raise SystemExit(f"{node['id']} is not fully active before the drill")
    origin = args.test_origin or str(fault.get("origin") or "")
    if not origin:
        raise SystemExit("--test-origin is required when the node JSON has no origin")
    if not args.forward_id:
        output = ssh(fault, "python3 -c \"import json; print(next(iter(json.load(open('/etc/leikwan-external-uot.json'))['forwards'])))\"")
        args.forward_id = int(output.strip().splitlines()[-1])
    client = chosen_client(origin, args.forward_id, str(fault["target"]))
    CLIENT_FILE.write_text(json.dumps({"origin": origin, **client}), encoding="utf-8")
    print(json.dumps({"pool_states": state_summary(), "drill_client": client, "origin": origin}, separators=(",", ":")))
    print(f"preflight=PASS fault_node={fault['id']} forward_id={args.forward_id}")


def inject(args: argparse.Namespace) -> None:
    fault = node_for(args.fault_node)
    ssh(fault, "systemctl stop leikwan-external-uot.service && ! systemctl is-active --quiet leikwan-external-uot.service")
    (WORK / "injected").write_text(fault["id"], encoding="utf-8")
    print(f"fault_injected=PASS node={fault['id']} target={fault['target']}")


def verify(args: argparse.Namespace) -> None:
    fault = node_for(args.fault_node)
    if not CLIENT_FILE.is_file():
        raise SystemExit("missing preflight client tuple")
    client = json.loads(CLIENT_FILE.read_text(encoding="utf-8"))
    result = assigned_client(client, client["origin"])
    target = result.get("target", "")
    if not target or target == fault["target"]:
        raise SystemExit(f"failover did not move the assignment: {target!r}")
    standby = next((node for node in nodes() if node.get("target") == target), None)
    if standby is None:
        raise SystemExit(f"panel assigned an unknown standby target: {target}")
    probe(standby)
    print(json.dumps({"failover": "PASS", "old_target": fault["target"], "new_target": target,
                      "pool_states": state_summary()}, separators=(",", ":")))


def restore(args: argparse.Namespace) -> None:
    if not (WORK / "injected").is_file():
        print("restore=SKIP no fault was injected")
        return
    fault = node_for(args.fault_node)
    ssh(fault, "systemctl start leikwan-external-uot.service && systemctl is-active --quiet leikwan-external-uot.service")
    deadline = time.time() + max(30, int(args.restore_timeout))
    while time.time() < deadline:
        try:
            probe(fault)
            print(f"restore=PASS node={fault['id']}")
            (WORK / "injected").unlink(missing_ok=True)
            return
        except SystemExit:
            time.sleep(5)
    raise SystemExit(f"restored sidecar did not pass real UoT probe within {args.restore_timeout}s")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("phase", choices=["preflight", "inject", "verify", "restore"])
    parser.add_argument("--fault-node", default=os.environ.get("HA_FAULT_NODE", ""))
    parser.add_argument("--test-origin", default=os.environ.get("HA_TEST_ORIGIN", ""))
    parser.add_argument("--forward-id", type=int, default=int(os.environ.get("HA_FORWARD_ID", "0") or 0))
    parser.add_argument("--restore-timeout", default=os.environ.get("HA_RESTORE_TIMEOUT", "120"))
    args = parser.parse_args()
    if args.phase == "preflight":
        preflight(args)
    elif args.phase == "inject":
        inject(args)
    elif args.phase == "verify":
        verify(args)
    else:
        restore(args)


if __name__ == "__main__":
    main()
