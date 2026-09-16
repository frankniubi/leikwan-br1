import pathlib
root = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1")
ws = root / "LeikwanBridge.Desktop/src/LeikwanBridge.Headless/WindowsClientStack.cs"
t = ws.read_text(encoding="utf-8").replace("using LeikwanBridge.App;\n", "").replace("AppDefaults.GetInstallRoot()", "ClientDefaults.ResolveInstallRoot()")
ws.write_text(t, encoding="utf-8")
hr = root / "LeikwanBridge.Desktop/src/LeikwanBridge.Headless/HeadlessRunner.cs"
t = hr.read_text(encoding="utf-8").replace("using LeikwanBridge.App;\n", "")
t = t.replace("AppDefaults.NormalizeMasterUrl", "ClientDefaults.NormalizeMasterUrl").replace("AppDefaults.DefaultMasterUrl", "ClientDefaults.DefaultMasterUrl")
hr.write_text(t, encoding="utf-8")
pr = root / "LeikwanBridge.Desktop/src/LeikwanBridge.Headless/Program.cs"
t = pr.read_text(encoding="utf-8")
t = t.replace("""        var defaultLogFile = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData),
            "Leikwan", "ClientAgent", "logs", "headless-.log");""", """        var defaultLogFile = Path.Combine(ClientDefaults.ResolveSharedStateDirectory(), "logs", "headless-.log");""")
pr.write_text(t, encoding="utf-8")
print("rewired to ClientDefaults")
