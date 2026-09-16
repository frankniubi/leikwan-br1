import pathlib, re
root = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1")
mac = root / "LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Services/MacHeadlessHost.cs"
text = mac.read_text(encoding="utf-8")
# drop the local status records and the json context, use the Core ones
start = text.index("public sealed record MacHeadlessForward(")
end = text.index("// launchd stops a daemon with SIGTERM")
removed = text[start:end]
assert "MacHeadlessStatusContext" in removed and "MacHeadlessForward" in removed
text = text[:start] + text[end:]
text = text.replace("MacHeadlessStatus", "HeadlessClientStatus").replace("MacHeadlessForward", "HeadlessClientForward")
text = text.replace("MacHeadlessStatusContext.Default.HeadlessClientStatus", "HeadlessClientStatusContext.Default.HeadlessClientStatus")
mac.write_text(text, encoding="utf-8")
print("removed chars:", len(removed))
print("uses shared context:", "HeadlessClientStatusContext.Default" in text)
