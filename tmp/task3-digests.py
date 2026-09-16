
import hashlib
paths = {
    "NEW probe": r"source-agent\ultra_line_review.py",
    "NEW agent": r"source-agent\ultra_source_agent.py",
    "BASE probe (worktree)": r"C:\Users\frankniubi\Downloads\task3-prefix\source-agent\ultra_line_review.py",
    "BASE agent (worktree)": r"C:\Users\frankniubi\Downloads\task3-prefix\source-agent\ultra_source_agent.py",
}
for label, path in paths.items():
    data = open(path, "rb").read()
    print("%-22s md5=%s sha256=%s bytes=%d" % (label, hashlib.md5(data).hexdigest(), hashlib.sha256(data).hexdigest(), len(data)))
print()
print("handoff claims the deployed 171 probe md5 = a9cc8b87b1bebf8a370bda023c9981ec")
