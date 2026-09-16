import os, pathlib, stat
p = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1\artifacts\versions.json")
st = p.stat()
print("mode", oct(st.st_mode), "size", st.st_size)
print("readonly?", not os.access(p, os.W_OK))
print("reparse?", bool(st.st_file_attributes & stat.FILE_ATTRIBUTE_REPARSE_POINT) if hasattr(st, "st_file_attributes") else "n/a")
import ctypes
attrs = ctypes.windll.kernel32.GetFileAttributesW(str(p))
print("attrs", hex(attrs))
try:
    with open(p, "rb") as f:
        data = f.read()
    print("read ok", len(data))
except Exception as e:
    print("read failed", e)
tmp = p.with_suffix(".json.probe")
try:
    tmp.write_bytes(b"probe")
    print("temp write ok")
    os.replace(tmp, p)
    print("replace ok")
except Exception as e:
    print("write failed", type(e).__name__, e)
