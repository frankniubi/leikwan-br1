
import hashlib, struct, pathlib
def sha(b): return hashlib.sha256(b).hexdigest()
def codesig(path):
    d = pathlib.Path(path).read_bytes()
    magic, cputype, cpusub, ftype, ncmds, sizeofcmds, flags = struct.unpack_from('<IIIIIII', d, 0)
    off = 32 if magic == 0xFEEDFACF else 28
    found = None
    for i in range(ncmds):
        cmd, size = struct.unpack_from('<II', d, off)
        if cmd == 0x1d:
            (dataoff, datasize) = struct.unpack_from('<II', d, off+8)
            found = (dataoff, datasize)
        off += size
    return magic, cputype, len(d), found
for p in ["artifacts/ultra/ultra-client_macos_arm64","artifacts/ultra/ultra-client_macos_amd64",
          ".build/macos-rt-20260913-arm64/LeiKwan Bridge.app/Contents/Resources/bin/ultra-client"]:
    try:
        magic,cpu,size,sig = codesig(p)
        print(f"{p}: magic={hex(magic)} cpu={hex(cpu)} size={size} LC_CODE_SIGNATURE={sig}")
    except Exception as e:
        print(p, "ERR", e)
