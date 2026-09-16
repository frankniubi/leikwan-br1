
import pathlib, re
BT = chr(96)
FENCE = BT * 3
note = pathlib.Path("docs/handoffs/2026-09-14-ultra-review-probe-operator-note.md").read_text(encoding="utf-8")
start = note.index("## 8. STEP 5")
fence = note.index(FENCE + "bash", start) + len(FENCE + "bash\n")
end = note.index(FENCE, fence)
script = note[fence:end]
pathlib.Path("tmp/task3-verify-block.sh").write_text(script, encoding="utf-8", newline="\n")
print("verify block bytes", len(script))
blocks = re.findall(FENCE + "bash\n(.*?)" + FENCE, note, re.S)
for index, block in enumerate(blocks):
    pathlib.Path("tmp/task3-note-block-%02d.sh" % index).write_text(block, encoding="utf-8", newline="\n")
print("bash blocks:", len(blocks))
