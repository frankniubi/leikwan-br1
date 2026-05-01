from pathlib import Path
import sys


def fail(message: str) -> None:
    print(message, file=sys.stderr)
    raise SystemExit(1)


source_path = Path(__file__).resolve().parent.parent / "migrations" / "20260424_add_knowledgebase_threads.sql"
raw = source_path.read_bytes()

try:
    text = raw.decode("utf-8")
except UnicodeDecodeError as exc:
    fail(f"knowledgebase migration is not valid utf-8: {exc}")

required_snippets = [
    "Getting Started: First Forward Checklist",
    "Connection Modes: Single, Race, Bond",
    "Bond Sidecar Setup",
    "Troubleshooting Common Mistakes",
    "Install Client",
    "How To Use The Forward",
    "Client Public IP",
    "Tenant -&gt; Clients",
    "Tenant -&gt; Sidecars",
    "Download Windows App",
    "Download Android App",
    "Copy Install Command",
    "Create Forward",
    "Forward Combination",
    "Local Listen Port",
    "View Diagnostics",
    "第一條轉發",
    "安裝 Client",
    "如何使用轉發後的服務",
    "外部使用",
    "點擊",
    "複製",
    "貼上",
]

for snippet in required_snippets:
    if snippet not in text:
        fail(f"missing expected utf-8 knowledgebase snippet: {snippet}")

mojibake_markers = [
    "\ufffd",
    "绗",
    "鍠",
    "鐧",
    "瑷",
    "鏄",
    "濓",
    "锛",
    "銆",
]

for mojibake_marker in mojibake_markers:
    if mojibake_marker in text:
        fail(f"mojibake marker found in knowledgebase migration: {mojibake_marker.encode('unicode_escape').decode('ascii')}")

print("ok")
