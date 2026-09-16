import hashlib, json, pathlib, re, sys

NOTE = pathlib.Path("docs/handoffs/2026-09-14-ultra-review-probe-operator-note.md")
PROBE = pathlib.Path("source-agent/ultra_line_review.py")
AGENT = pathlib.Path("source-agent/ultra_source_agent.py")
PROBE_PATH = "/opt/ultra-source/runtime/ultra_line_review.py"
AGENT_PATH = "/usr/local/bin/ultra-source-agent.py"
PROBE_BASE_MD5 = "a9cc8b87b1bebf8a370bda023c9981ec"
AGENT_BASE_MD5 = "db911ae5522e8e5986c6b8855a588dd3"
BASE_PROBE = pathlib.Path(r"C:\Users\frankniubi\Downloads\task3-prefix\source-agent\ultra_line_review.py")
BASE_AGENT = pathlib.Path(r"C:\Users\frankniubi\Downloads\task3-prefix\source-agent\ultra_source_agent.py")

BT = chr(96)
FENCE = BT * 3

def digests(data):
    return hashlib.sha256(data).hexdigest(), hashlib.md5(data).hexdigest(), len(data)

probe_bytes = PROBE.read_bytes()
agent_bytes = AGENT.read_bytes()
probe_sha, probe_md5, probe_len = digests(probe_bytes)
agent_sha, agent_md5, agent_len = digests(agent_bytes)
base_probe_sha, base_probe_md5, base_probe_len = digests(BASE_PROBE.read_bytes())
base_agent_sha, base_agent_md5, base_agent_len = digests(BASE_AGENT.read_bytes())
assert base_probe_md5 == PROBE_BASE_MD5, "baseline probe md5 no longer matches the documented host revision"
assert base_agent_md5 == AGENT_BASE_MD5, "baseline agent md5 changed"
assert b"\r" not in probe_bytes and b"\r" not in agent_bytes, "source files must be LF"
assert BT.encode() not in probe_bytes and BT.encode() not in agent_bytes, "no backticks in embedded files"

blocks = {}
blocks["FILE_DISCOVERY"] = FENCE + "bash\n" + "\n".join([
    "sudo systemctl list-units --type=service --all | grep -i ultra-source",
    "systemctl cat ultra-source-agent | sed -n '1,25p'",
    "sudo python3 - <<'PY'",
    "import json",
    "cfg = json.load(open('/etc/ultra-source-agent.json'))",
    "for key in ('review_script', 'review_interval_seconds', 'review_big_bytes',",
    "            'review_require_big_packet', 'max_reviews_per_cycle', 'state_dir'):",
    "    print(key, '=', cfg.get(key))",
    "PY",
    "python3 -c \"import sys; sys.path.insert(0, '/opt/ultra-source/runtime'); import native_udp as n; ",
    "print('native_udp:', n.__file__); print('has unwrap_down:', hasattr(n, 'unwrap_down'))\"",
]) + "\n" + FENCE

blocks["DISCOVERY_EXPECTED"] = FENCE + "text\n" + "\n".join([
    "unit                     ultra-source-agent",
    "ExecStart                /usr/bin/python3 /usr/local/bin/ultra-source-agent.py --config /etc/ultra-source-agent.json",
    "review_script            /opt/ultra-source/runtime/ultra_line_review.py",
    "review_interval_seconds  20",
    "review_big_bytes         1400",
    "review_require_big_packet true",
    "max_reviews_per_cycle    1",
]) + "\n" + FENCE

blocks["BACKUP_BLOCK"] = FENCE + "bash\n" + "\n".join([
    "STAMP=$(date -u +%Y%m%dT%H%M%SZ)",
    "BACKUP=/root/leikwan-source-agent-backups/$STAMP",
    "sudo mkdir -p \"$BACKUP\"",
    "sudo cp -a @@PROBE_PATH@@ \"$BACKUP/ultra_line_review.py\"",
    "sudo cp -a @@AGENT_PATH@@ \"$BACKUP/ultra_source_agent.py\"",
    "sudo sha256sum \"$BACKUP/ultra_line_review.py\" \"$BACKUP/ultra_source_agent.py\" | sudo tee \"$BACKUP/SHA256SUMS\"",
    "sudo ls -l \"$BACKUP\"",
    "echo \"BACKUP_DIR=$BACKUP\"",
]) + "\n" + FENCE

blocks["DRIFT_BLOCK"] = FENCE + "bash\n" + "\n".join([
    "sudo bash -c 'BASE=" + PROBE_BASE_MD5 + "; NEW=" + probe_md5 + "; P=@@PROBE_PATH@@; ",
    "GOT=$(md5sum \"$P\" | cut -d\" \" -f1); echo \"installed probe md5: $GOT\"; ",
    "if [ \"$GOT\" = \"$NEW\" ]; then echo \"already on the new probe - nothing to install\"; exit 0; fi; ",
    "if [ \"$GOT\" != \"$BASE\" ]; then echo \"REFUSING: unknown probe revision - back it up and report it\"; exit 1; fi; ",
    "echo \"OK: documented baseline detected, safe to install\"'",
    "",
    "# the agent file: record what is there, and only continue if it looks like the agent",
    "sudo bash -c 'A=@@AGENT_PATH@@; ",
    "echo \"installed agent md5: $(md5sum \"$A\" | cut -d\" \" -f1)\"; ",
    "grep -n \"^AGENT_VERSION\" \"$A\"; ",
    "grep -c \"def run_review\" \"$A\"; ",
    "grep -c \"def process_reviews\" \"$A\"'",
]) + "\n" + FENCE

blocks["DIGEST_TABLE"] = "\n".join([
    "| file | sha256 | md5 | bytes |",
    "| ---- | ------ | --- | ----- |",
    "| new probe (@@PROBE_PATH@@) | " + probe_sha + " | " + probe_md5 + " | " + str(probe_len) + " |",
    "| new agent (@@AGENT_PATH@@) | " + agent_sha + " | " + agent_md5 + " | " + str(agent_len) + " |",
    "| baseline probe (documented deployed) | " + base_probe_sha + " | " + base_probe_md5 + " | " + str(base_probe_len) + " |",
    "| baseline agent (repo 1893e05b) | " + base_agent_sha + " | " + base_agent_md5 + " | " + str(base_agent_len) + " |",
])

blocks["INSTALL_PROBE"] = "\n".join([
    "### 3a. the probe",
    "",
    FENCE + "bash",
    "sudo cp -a @@PROBE_PATH@@ /tmp/ultra_line_review.py.before-upgrade",
    "sudo tee @@PROBE_PATH@@ >/dev/null <<'ULTRA_EOF'",
    probe_bytes.decode("utf-8"),
    "ULTRA_EOF",
    "sudo sed -i 's/\\r$//' @@PROBE_PATH@@",
    "sudo chmod 755 @@PROBE_PATH@@",
    "sudo chown root:root @@PROBE_PATH@@",
    FENCE,
])

blocks["INSTALL_AGENT"] = "\n".join([
    "### 3b. the agent",
    "",
    FENCE + "bash",
    "sudo cp -a @@AGENT_PATH@@ /tmp/ultra_source_agent.py.before-upgrade",
    "sudo tee @@AGENT_PATH@@ >/dev/null <<'ULTRA_EOF'",
    agent_bytes.decode("utf-8"),
    "ULTRA_EOF",
    "sudo sed -i 's/\\r$//' @@AGENT_PATH@@",
    "sudo chmod 755 @@AGENT_PATH@@",
    "sudo chown root:root @@AGENT_PATH@@",
    FENCE,
])

blocks["INSTALL_VERIFY"] = "\n".join([
    "### 3c. check the bytes and the syntax before restarting",
    "",
    FENCE + "bash",
    "sha256sum @@PROBE_PATH@@ @@AGENT_PATH@@",
    "sudo python3 -m py_compile @@PROBE_PATH@@ @@AGENT_PATH@@",
    "python3 @@PROBE_PATH@@ --help | head -20",
    FENCE,
    "",
    "The two sha256 values must equal the @@BT@@new probe@@BT@@ / @@BT@@new agent@@BT@@ rows in step 2.",
    "@@BT@@py_compile@@BT@@ prints nothing when both files are valid Python.",
])
note = NOTE.read_text(encoding="utf-8")
for key, value in blocks.items():
    note = note.replace("@@" + key + "@@", value)
assert "@@FILE_DISCOVERY@@" not in note

simple = {
    "PROBE_PATH": PROBE_PATH,
    "AGENT_PATH": AGENT_PATH,
    "BACKUP_DIR": "/root/leikwan-source-agent-backups/<the STAMP printed above>/",
}
for key, value in simple.items():
    note = note.replace("@@" + key + "@@", value)
note = note.replace("@@BT@@", BT)
NOTE.write_text(note, encoding="utf-8", newline="\n")
leftover = sorted(set(re.findall(r"@@[A-Z_]+@@", note)))
print("probe sha256", probe_sha, "md5", probe_md5, "bytes", probe_len)
print("agent sha256", agent_sha, "md5", agent_md5, "bytes", agent_len)
print("note bytes", len(note.encode("utf-8")))
print("leftover placeholders:", leftover if leftover else "none")

# ---- part 2: the blocks that appear after the install ----
more = {}
more["RESTART_BLOCK"] = FENCE + "bash\n" + "\n".join([
    "sudo systemctl restart ultra-source-agent",
    "sudo systemctl is-active ultra-source-agent",
    "sudo systemctl status ultra-source-agent --no-pager -n 5",
]) + "\n" + FENCE

more["VERIFY_BLOCK"] = FENCE + "bash\n" + "\n".join([
    "sudo bash -c 'set -u",
    "UNIT=ultra-source-agent",
    "AGENT=@@AGENT_PATH@@",
    "PROBE=@@PROBE_PATH@@",
    "want_agent=" + agent_sha,
    "want_probe=" + probe_sha,
    "fail=0",
    "got_agent=$(sha256sum \"$AGENT\" | cut -d\" \" -f1)",
    "got_probe=$(sha256sum \"$PROBE\" | cut -d\" \" -f1)",
    "if [ \"$got_agent\" = \"$want_agent\" ]; then echo \"OK   agent file is the new revision\"; else echo \"FAIL agent file digest: $got_agent\"; fail=1; fi",
    "if [ \"$got_probe\" = \"$want_probe\" ]; then echo \"OK   probe file is the new revision\"; else echo \"FAIL probe file digest: $got_probe\"; fail=1; fi",
    "if python3 \"$PROBE\" --help 2>/dev/null | grep -q -- \"--target\"; then echo \"OK   probe CLI exposes --target/--advertised\"; else echo \"FAIL probe CLI is still the old one\"; fail=1; fi",
    "if python3 -c \"import sys; sys.path.insert(0, \\\"/opt/ultra-source/runtime\\\"); import native_udp as n; [getattr(n, k) for k in (\\\"NativeSession\\\", \\\"LineNotLive\\\", \\\"AuthError\\\")]\" 2>/dev/null; then echo \"OK   probe dependency native_udp is usable\"; else echo \"FAIL probe dependency native_udp\"; fail=1; fi",
    "if systemctl is-active --quiet \"$UNIT\"; then echo \"OK   $UNIT is active\"; else echo \"FAIL $UNIT is not active\"; fail=1; fi",
    "started=$(date -d \"$(systemctl show -p ExecMainStartTimestamp --value \"$UNIT\")\" +%s 2>/dev/null || echo 0)",
    "newest=$(stat -c %Y \"$AGENT\" \"$PROBE\" | sort -n | tail -1)",
    "if [ \"$started\" -ge \"$newest\" ]; then echo \"OK   service started after the new files were written\"; else echo \"FAIL service start predates the new files - restart it\"; fail=1; fi",
    "if [ \"$fail\" -eq 0 ]; then echo \"RESULT: the new review probe is installed and running\"; else echo \"RESULT: NOT verified\"; fi",
    "exit $fail'",
]) + "\n" + FENCE

more["JOURNAL_BLOCK"] = FENCE + "bash\n" + "\n".join([
    "sudo journalctl -u ultra-source-agent --since \"-40 min\" --no-pager | grep -E \"review [0-9]+ line\" | tail -5",
]) + "\n" + FENCE

more["JOURNAL_EXAMPLE"] = FENCE + "text\n" + "\n".join([
    "review 11 line 453 passed=True proof_reason=none end_to_end_ok=30 zero_downstream=0 note=",
    "review 12 line 37 passed=False proof_reason=no_end_to_end_transfer end_to_end_ok=0 zero_downstream=30 note=no_downstream_bytes",
]) + "\n" + FENCE

more["PANEL_SQL"] = FENCE + "sql\n" + "\n".join([
    "SELECT id, line_id, state, completed_at,",
    "       JSON_UNQUOTE(JSON_EXTRACT(result_json, '$.rebuttal'))                     AS rebuttal,",
    "       JSON_UNQUOTE(JSON_EXTRACT(result_json, '$.proof.reason'))                 AS panel_reason,",
    "       JSON_EXTRACT(result_json, '$.detail.proof.end_to_end_ok')                 AS probe_e2e_ok,",
    "       JSON_UNQUOTE(JSON_EXTRACT(result_json, '$.detail.proof.target'))           AS probe_target,",
    "       JSON_EXTRACT(result_json, '$.detail.proof.local_path_only')               AS probe_local_only,",
    "       JSON_EXTRACT(result_json, '$.detail.target_tcp_zero_downstream')          AS zero_downstream_rounds,",
    "       JSON_EXTRACT(result_json, '$.detail.rounds')                              AS rounds,",
    "       JSON_EXTRACT(result_json, '$.detail.target_tcp_min_close_ms')             AS min_close_ms,",
    "       JSON_EXTRACT(result_json, '$.detail.target_tcp_max_close_ms')             AS max_close_ms",
    "  FROM ultra_candidate_reviews",
    " ORDER BY id DESC LIMIT 5;",
]) + "\n" + FENCE

more["ROLLBACK_BLOCK"] = FENCE + "bash\n" + "\n".join([
    "sudo systemctl stop ultra-source-agent",
    "STAMP=$(sudo ls -1 /root/leikwan-source-agent-backups | sort | tail -1)   # or the stamp from step 1",
    "echo \"rolling back from /root/leikwan-source-agent-backups/$STAMP\"",
    "sudo cp -a \"/root/leikwan-source-agent-backups/$STAMP/ultra_line_review.py\" @@PROBE_PATH@@",
    "sudo cp -a \"/root/leikwan-source-agent-backups/$STAMP/ultra_source_agent.py\" @@AGENT_PATH@@",
    "sudo chmod 755 @@PROBE_PATH@@ @@AGENT_PATH@@",
    "sudo chown root:root @@PROBE_PATH@@ @@AGENT_PATH@@",
    "sudo systemctl start ultra-source-agent",
]) + "\n" + FENCE

more["ROLLBACK_VERIFY"] = "\n".join([
    FENCE + "bash",
    "sudo md5sum @@PROBE_PATH@@ @@AGENT_PATH@@",
    "sudo systemctl is-active ultra-source-agent",
    FENCE,
    "",
    "Expected after rollback:",
    "",
    FENCE + "text",
    PROBE_BASE_MD5 + "  " + PROBE_PATH,
    AGENT_BASE_MD5 + "  " + AGENT_PATH,
    "active",
    FENCE,
    "",
    "Roll both files back together. The new agent passes @@BT@@--target@@BT@@/@@BT@@--advertised@@BT@@ to the",
    "probe; the old probe does not accept those flags, so a half rollback (new agent +",
    "old probe) makes every review end as \"probe produced no result (exit 2)\" and the",
    "panel records @@BT@@rebuttal=blocked@@BT@@ for all of them. That is harmless but useless.",
])
note = NOTE.read_text(encoding="utf-8")
for key, value in more.items():
    note = note.replace("@@" + key + "@@", value)
for key, value in simple.items():
    note = note.replace("@@" + key + "@@", value)
note = note.replace("@@BT@@", BT)
leftover = sorted(set(re.findall(r"@@[A-Z_]+@@", note)))
NOTE.write_text(note, encoding="utf-8", newline="\n")
print("leftover placeholders:", leftover if leftover else "none")
print("note bytes", len(note.encode("utf-8")), "lines", note.count(chr(10)) + 1)
