import pathlib
root = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1")
p = root / "LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Program.cs"
t = p.read_text(encoding="utf-8").replace("Services.MacHeadlessOptions.Flag", "LeikwanBridge.Core.Services.HeadlessClientOptions.Flag")
p.write_text(t, encoding="utf-8")
t = root / "LeikwanBridge.Desktop/tests/LeikwanBridge.Mac.Tests/Services/MacHeadlessHostTests.cs"
s = t.read_text(encoding="utf-8").replace("MacHeadlessOptions", "HeadlessClientOptions")
t.write_text(s, encoding="utf-8")
print("done")
