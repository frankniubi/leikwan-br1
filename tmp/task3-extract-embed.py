
import hashlib, pathlib
lines = pathlib.Path("docs/handoffs/2026-09-14-ultra-review-probe-operator-note.md").read_text(encoding="utf-8").split("\n")
def extract(marker):
    start = next(i for i, line in enumerate(lines) if line.startswith("sudo tee ") and marker in line)
    end = next(i for i in range(start + 1, len(lines)) if lines[i] == "ULTRA_EOF")
    return ("\n".join(lines[start + 1:end]) + "\n").encode("utf-8")
for marker, out in (("ultra_line_review.py", "tmp/task3-embed-probe.py"), ("ultra-source-agent.py", "tmp/task3-embed-agent.py")):
    data = extract(marker)
    pathlib.Path(out).write_bytes(data)
    print(out, hashlib.sha256(data).hexdigest())
