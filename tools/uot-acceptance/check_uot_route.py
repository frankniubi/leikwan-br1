"""Local preflight only. Does not connect, print tokens, or substitute for live proof."""
import argparse
import json
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument("--config", required=True, type=Path)
parser.add_argument("--expected-sidecar", required=True)
args = parser.parse_args()
config = json.loads(args.config.read_text(encoding="utf-8-sig"))
uot = config.get("external_uot") or {}
failures = []
if uot.get("enabled") is not True:
    failures.append("external_uot.enabled must be true; direct UDP fallback forbidden")
if uot.get("sidecar_target") != args.expected_sidecar:
    failures.append("sidecar target differs from expected endpoint")
if config.get("native_socks5"):
    failures.append("native_socks5 routing needs separate sidecar-path proof")
if failures:
    raise SystemExit("FAIL: " + "; ".join(failures))
print("CONFIG_PASS: external UoT configured; runtime hash and live sidecar session proof still required")
