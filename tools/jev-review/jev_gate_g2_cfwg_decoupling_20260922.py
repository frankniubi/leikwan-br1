#!/usr/bin/env python3
"""G2 gate: CFWG watchdog decoupling from Ultra."""

import os
from typesafe_sdk import Choice, Noul, Score, TypeSafeClient

API_KEY = os.environ.get("TYPESAFE_API_KEY")
if not API_KEY:
    raise SystemExit("Set TYPESAFE_API_KEY")

def run() -> None:
    with TypeSafeClient() as client:
        resp = client.system_one(
            state={
                "gate": "G2 CFWG watchdog decoupling",
                "fix_summary": "CFWG watchdog now (a) filters backend_mode==ultra from configured_forward_ids, (b) hard-skips ultra forwards in tick even if explicitly pinned, (c) drops client_agent from restart_actions allow-list and refuses it in restart_runtime with warning. 24 existing watchdog tests still PASS. Direction 47/50 matrix failures were cfwg 3 + self-update 2 per 600s with Ultra zero errors -> whole-client restart killed healthy Ultra data plane. Fix makes watchdog per-forward only (wstunnel/wireguard) and never touches Ultra.",
                "evidence": {
                    "before_matrix": "47 Linux idle 598/600 cfwg 3 + self-update 2 Ultra zero errors; 50 Linux idle 571/600 cfwg 3 Ultra zero errors; macOS 47 PASS (no watchdog)",
                    "forward_170": "same relay plane, ha_failures 17023 but preserved runtime -> data plane sound when not killed by control plane",
                    "after_fix_files": "client-agent/cfwg_latency_watchdog.py: normalize_config drops client_agent, configured_forward_ids skips ultra, tick skips ultra, restart_runtime refuses client_agent",
                    "tests": "24/24 watchdog tests PASS including circuit breaker, dwell, cooldown",
                },
                "open_items": "49/52 no_healthy 64-90, 53 no-listener all platforms, 56 98% UDP loss, throughput caps 48/50",
            },
            questions={
                "gate_pass": Noul(
                    instructions="Does the CFWG decoupling fix correctly address the 47/50 idle 598/600 failures without regressing Ultra, given it filters ultra forwards and removes whole-client restart?",
                    criteria={"true": "Yes, fix is correct and complete for this gate", "false": "No, missing or incorrect"},
                ),
                "next_priority": Choice(
                    instructions="Given matrix after G2, what is the single next gate?",
                    criteria={
                        "reverify_46": "Re-verify direction 46 600s PASS still holds (regression guard)",
                        "no_healthy": "Fix no_healthy_candidate for 49/52 (panel lease material / candidate health)",
                        "no_listener": "Fix .NET no-listener dispatch for 53/54/56",
                        "throughput": "Raise per-direction relay throughput cap (48 14.48 Mbps etc)",
                        "udp_loss": "Investigate UDP 98% loss direction 56 path segmentation",
                    },
                ),
                "confidence": Score(
                    instructions="How well supported is that G2 is sufficient before moving to next gate?",
                    criteria=["speculative", "plausible", "well-supported", "conclusive"],
                ),
            },
        )
        print("gate_pass:", resp.nouls["gate_pass"].noul)
        print("next_priority:", resp.choices["next_priority"].choice, resp.choices["next_priority"].confidence, dict(resp.choices["next_priority"].probabilities))
        print("confidence:", resp.scores["confidence"].score, resp.scores["confidence"].confidence, dict(resp.scores["confidence"].probabilities))
        import json
        out = {
            "gate_pass": {"noul": resp.nouls["gate_pass"].noul},
            "next_priority": {"choice": resp.choices["next_priority"].choice, "confidence": resp.choices["next_priority"].confidence, "probs": dict(resp.choices["next_priority"].probabilities)},
            "confidence": {"score": resp.scores["confidence"].score, "confidence": resp.scores["confidence"].confidence, "probs": dict(resp.scores["confidence"].probabilities)},
        }
        with open("tools/jev-review/jev_gate_g2_cfwg_decoupling_20260922.json", "w", encoding="utf-8") as f:
            json.dump(out, f, indent=2)
        print("Wrote jev_gate_g2 json")

if __name__ == "__main__":
    run()
