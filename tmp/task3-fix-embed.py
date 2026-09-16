
import pathlib
NOTE = pathlib.Path("docs/handoffs/2026-09-14-ultra-review-probe-operator-note.md")
note = NOTE.read_text(encoding="utf-8")
before = note.count("\n\nULTRA_EOF\n")
note = note.replace("\n\nULTRA_EOF\n", "\nULTRA_EOF\n")
NOTE.write_text(note, encoding="utf-8", newline="\n")
print("extra-blank-line occurrences replaced:", before)
