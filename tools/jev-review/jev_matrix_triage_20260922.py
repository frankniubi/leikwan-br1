#!/usr/bin/env python3
"""Jev decision review: why the matrix is 1/11 and what to fix next.

Run:  TYPESAFE_API_KEY=... python tools/jev-review/jev_matrix_triage_20260922.py
Every question is a falsifiable Choice/Noul/Score with segment-level evidence.
"""

import os

from typesafe_sdk import Choice, Noul, Score, TypeSafeClient

API_KEY = os.environ.get("TYPESAFE_API_KEY")
if not API_KEY:
    raise SystemExit("Set TYPESAFE_API_KEY (see C:/Users/frankniubi/.claude/settings.json)")


def run() -> None:
    with TypeSafeClient() as client:
        resp = client.system_one(
            state={
                "acceptance_criterion": "TCP+UDP each 20Mbps x 600s, sequential per direction (never concurrent on same sidecar), PASS = throughput 20Mbps + idle 0 + UDP loss <0.1%",
                "current_matrix": "11 directions: only 46 SHA->HKG passes on Linux/macOS/Windows. 47 fails Linux+Windows idle 598/600 (cfwg 3 + self-update 2, Ultra zero errors) but macOS PASS. 48 throughput cap 14.48 Mbps. 49/50 cfwg + no_healthy. 51 near-PASS (3-platform zero interruptions, Windows 18.829 Mbps). 52 no_healthy 90. 53 all blocked no listener despite panel lease. 54 retry PASS on Linux. 56 98% UDP loss.",
                "forward_170_anchor": {
                    "uptime": "48h stable on same Windows host as failing E2E tests, same relay data plane, target 185.220.238.143:10001 tcp+udp, sidecar same host",
                    "ha_failures": 17023,
                    "ha_events": 16,
                    "queue_metrics": "queue_blocked=0 queue_timeout=0 queue_drop=0 socket_buffer_error=False operator_quarantine=False",
                    "lease_failures": 779,
                    "all_forwards_lease_failures": "policy_generation_invalid 1065 lease_device_live_limit 4367 http502 73",
                    "behavior_on_lease_failure": "preserving current runtime and retrying config sync (never stops healthy runtime)",
                    "bytes": "bytes_in 3.9GB bytes_out 1.3GB state ready, 8 candidate_feedback all success canary_probe_ok RTT 47ms tcp 76ms udp",
                    "lesson": "170 survives massive control-plane noise because it preserves last known-good runtime; data-plane health is independent of lease-plane churn. A forward claimed degraded by lease noise still carries bytes.",
                },
                "already_fixed_and_verified": [
                    "Panel revoked-generation epoch folding (revocationIndex + LeigodTokenBroker) validated STALE_UNEXPIRED=0 and byte-identical live generations; live 600s self-heal from revoked 9520cd4a2e56 to bbda7965fe2e under zero interruptions",
                    "Both-path guard RecordRefusedPolicyGeneration in ReconcileForwardAsync + RefreshUltraForwardAsync (17c42b23 only covered Refresh; fixed)",
                    "Windows lease delivery bounded retry 20s/250ms (direction 46 macOS 598/600 -> 0)",
                    "Sidecar hot reload SIGHUP+marker atomic.Pointer to 5 hosts PID-invariant verified",
                    "NetworkProbe passive table read, Panel target-change reprovision-or-refuse + health verdict",
                ],
            },
            questions={
                "primary_cause": Choice(
                    instructions="Given forward 170 stability (same host, same relay data plane, survives 17023 ha_failures and 779 lease failures by preserving runtime) vs the E2E matrix 1/11, what is the PRIMARY cause of the remaining failures?",
                    criteria={
                        "A_product_defect": "Reliable datagram / UoT transport itself is broken at protocol level",
                        "B_control_plane_coupling": "Client self-healing + CFWG watchdog coupling cause E2E failures; product data plane is sound as proven by 170 and isolated 600s runs",
                        "C_relay_capacity": "Relay-leg or sidecar raw capacity cannot sustain 20Mbps",
                        "D_lease_material": "Panel lease-plane / material staleness is primary",
                    },
                ),
                "next_fix_priority": Choice(
                    instructions="What is the single highest-priority fix to lift the matrix from 1/11 toward enterprise-grade coverage without re-breaking direction 46?",
                    criteria={
                        "fix_dotnet_selfheal_verify": "Re-verify the .NET revoked-generation self-heal (already deployed) on all remaining directions and gate on it - 46 already proves it works",
                        "fix_cfwg_decoupling": "Decouple CFWG watchdog whole-client restart from Ultra forwards (directions 47/50: 3 restarts per 10min with Ultra zero errors)",
                        "fix_relay_capacity": "Scale relay-leg/sidecar capacity to sustain 40Mbps without 74% drop",
                        "fix_lease_material": "Fix lease material refresh for 46/47/53/56 no_healthy",
                    },
                ),
                "forward_170_valid_anchor": Noul(
                    instructions="Is forward 170 a valid anchor proving the Ultra data plane can achieve enterprise-grade continuity, given it runs on the same Windows host and same relay data plane as the failing E2E tests?",
                    criteria={
                        "true": "Yes, same host + same relay plane makes it a valid control; the contrast isolates control-plane coupling",
                        "false": "No, 170 is on a different path/material/sidecar and does not generalize",
                    },
                ),
                "enterprise_ready": Noul(
                    instructions="Given 10 of 11 directions still fail for distinct reasons, is the system enterprise-grade highly available yet?",
                    criteria={
                        "true": "Yes, enterprise-grade HA achieved",
                        "false": "No, still not enterprise-grade",
                    },
                ),
                "confidence": Score(
                    instructions="How well-supported is the diagnosis that control-plane coupling (not product data plane) dominates, based on the forward-170 contrast and segmented 600s measurements?",
                    criteria=[
                        "speculative: evidence thin or ambiguous",
                        "plausible: some evidence but alternatives viable",
                        "well-supported: evidence strongly favors this diagnosis",
                        "conclusive: the forward-170 contrast and segmented measurements make this clear",
                    ],
                ),
            },
        )
        print("primary_cause:", resp.choices["primary_cause"].choice, resp.choices["primary_cause"].confidence, dict(resp.choices["primary_cause"].probabilities))
        print("next_fix:", resp.choices["next_fix_priority"].choice, resp.choices["next_fix_priority"].confidence, dict(resp.choices["next_fix_priority"].probabilities))
        print("forward_170 anchor:", resp.nouls["forward_170_valid_anchor"].noul)
        print("enterprise_ready:", resp.nouls["enterprise_ready"].noul)
        print("confidence:", resp.scores["confidence"].score, resp.scores["confidence"].confidence, dict(resp.scores["confidence"].probabilities))
        # Persist
        import json

        out = {
            "primary_cause": {
                "choice": resp.choices["primary_cause"].choice,
                "confidence": resp.choices["primary_cause"].confidence,
                "probs": dict(resp.choices["primary_cause"].probabilities),
            },
            "next_fix": {
                "choice": resp.choices["next_fix_priority"].choice,
                "confidence": resp.choices["next_fix_priority"].confidence,
                "probs": dict(resp.choices["next_fix_priority"].probabilities),
            },
            "forward_170_anchor": resp.nouls["forward_170_valid_anchor"].noul,
            "enterprise_ready": resp.nouls["enterprise_ready"].noul,
            "confidence": {
                "score": resp.scores["confidence"].score,
                "confidence": resp.scores["confidence"].confidence,
                "probs": dict(resp.scores["confidence"].probabilities),
            },
        }
        with open("tools/jev-review/jev_matrix_triage_20260922.json", "w", encoding="utf-8") as f:
            json.dump(out, f, indent=2)
        print("Wrote tools/jev-review/jev_matrix_triage_20260922.json")


if __name__ == "__main__":
    run()
