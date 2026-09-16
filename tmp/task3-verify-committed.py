
import hashlib, pathlib, subprocess
note = pathlib.Path("docs/handoffs/2026-09-14-ultra-review-probe-operator-note.md").read_text(encoding="utf-8")

def blob(rev_path):
    return subprocess.run(["git", "show", rev_path], capture_output=True, check=True).stdout

pairs = [
    ("source-agent/ultra_line_review.py", "ultra_line_review.py"),
    ("source-agent/ultra_source_agent.py", "ultra-source-agent.py"),
]
lines = note.split("\n")
for path, marker in pairs:
    data = blob("HEAD:" + path)
    sha, md5 = hashlib.sha256(data).hexdigest(), hashlib.md5(data).hexdigest()
    start = next(i for i, line in enumerate(lines) if line.startswith("sudo tee ") and marker in line)
    end = next(i for i in range(start + 1, len(lines)) if lines[i] == "ULTRA_EOF")
    embedded = ("\n".join(lines[start + 1:end]) + "\n").encode("utf-8")
    print("%-38s sha256_in_note=%s md5_in_note=%s embedded_eq_committed=%s" % (path, sha in note, md5 in note, embedded == data))

fixture = pathlib.Path("source-agent/tests/fixtures/review_proof_healthy.json").read_bytes()
print("fixture committed-identical:", subprocess.run(["git", "show", "HEAD:source-agent/tests/fixtures/review_proof_healthy.json"], capture_output=True, check=True).stdout == fixture)
base = blob("1893e05b:source-agent/ultra_line_review.py")
base_md5 = hashlib.md5(base).hexdigest()
print("baseline probe md5:", base_md5, "== documented 171 revision:", base_md5 == "a9cc8b87b1bebf8a370bda023c9981ec", "in note:", base_md5 in note)
print("baseline agent md5 in note:", hashlib.md5(blob("1893e05b:source-agent/ultra_source_agent.py")).hexdigest() in note)
