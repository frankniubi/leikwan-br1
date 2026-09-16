import pathlib, re
root = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1")
mac = root / "LeikwanBridge.Desktop/src/LeikwanBridge.Mac/Services/MacHeadlessHost.cs"
text = mac.read_text(encoding="utf-8")
lines = text.split("\n")
# locate the options block: line 11 (index 10) .. line 204 (index 203) exclusive of the host comment at 205
start = next(i for i, l in enumerate(lines) if l.startswith("// Command line surface of the windowless client:"))
host = next(i for i, l in enumerate(lines) if l.startswith("// The headless loop:"))
block = "\n".join(lines[start:host]).rstrip() + "\n"
rest = "\n".join(lines[:start] + lines[host:])
assert "MacHeadlessOptions" in block and "MacHeadlessHost" not in block
core_block = block.replace("MacHeadlessOptions", "HeadlessClientOptions")
core_block = core_block.replace(
    "// Command line surface of the windowless client:",
    "// Command line surface of the windowless clients (macOS and Windows).")
core_block = core_block.replace(
    "// The headless client runs the exact same managed payload, Core services and\n// lease/heartbeat code as the windowed application; only the process lifecycle\n// differs. That keeps the runtime attestation (fingerprint + self hash), the\n// update pinning and the failover behaviour identical between both surfaces.",
    "// The windowless clients run the exact same managed payload, Core services and\n// lease/heartbeat code as the windowed applications; only the process lifecycle\n// differs. That keeps the runtime attestation (fingerprint + self hash), the\n// update pinning and the failover behaviour identical between both surfaces.")
core = root / "LeikwanBridge.Desktop/src/LeikwanBridge.Core/Services/HeadlessClientOptions.cs"
core.write_text("using System.Globalization;\n\nnamespace LeikwanBridge.Core.Services;\n\n" + core_block, encoding="utf-8")
rest = rest.replace("MacHeadlessOptions", "HeadlessClientOptions")
mac.write_text(rest, encoding="utf-8")
print("core file lines:", len(core_block.split(chr(10))))
print("mac file lines:", len(rest.split(chr(10))))
print("remaining refs:", rest.count("MacHeadlessOptions"))
