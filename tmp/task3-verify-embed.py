
import hashlib, pathlib
BT = chr(96)
note = pathlib.Path("docs/handoffs/2026-09-14-ultra-review-probe-operator-note.md").read_text(encoding="utf-8")
lines = note.split("\n")
def extract(marker):
    start = next(i for i, line in enumerate(lines) if line.startswith("sudo tee ") and marker in line)
    end = next(i for i in range(start + 1, len(lines)) if lines[i] == "ULTRA_EOF")
    body = "\n".join(lines[start + 1:end]) + "\n"
    return body.encode("utf-8")
for marker, source in (
    ("ultra_line_review.py", "source-agent/ultra_line_review.py"),
    ("ultra-source-agent.py", "source-agent/ultra_source_agent.py"),
):
    embedded = extract(marker)
    on_disk = pathlib.Path(source).read_bytes()
    print(marker)
    print("  embedded sha256", hashlib.sha256(embedded).hexdigest(), "bytes", len(embedded))
    print("  on disk  sha256", hashlib.sha256(on_disk).hexdigest(), "bytes", len(on_disk))
    print("  identical:", embedded == on_disk)
    if embedded != on_disk:
        print("  tail embedded", repr(embedded[-40:]))
        print("  tail on disk ", repr(on_disk[-40:]))
