
import socket, struct, secrets, time, sys

TARGET = ("45.143.235.221", 10809)

def dns_query(ident):
    return struct.pack('!6H', ident, 0x0100, 1, 0, 0, 0) + b'\x07example\x03com\x00' + struct.pack('!HH', 1, 1)

print("== 1. plain UDP DNS query to target (3s) ==")
try:
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM); s.settimeout(3)
    ident = secrets.randbelow(65536)
    s.sendto(dns_query(ident), TARGET)
    data, addr = s.recvfrom(4096)
    print("   reply from", addr, "len", len(data), "id_match", struct.unpack('!H', data[:2])[0] == ident, "flags", hex(struct.unpack('!H', data[2:4])[0]))
except Exception as exc:
    print("   no reply:", type(exc).__name__, exc)
finally:
    try: s.close()
    except Exception: pass

print("== 2. plain empty UDP datagram to target (3s) ==")
try:
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM); s.settimeout(3)
    s.sendto(b"", TARGET)
    data, addr = s.recvfrom(4096)
    print("   reply from", addr, "len", len(data), data[:32].hex())
except Exception as exc:
    print("   no reply:", type(exc).__name__, exc)
finally:
    try: s.close()
    except Exception: pass

print("== 3. SS-none framed DNS query to 1.1.1.1:53 via target UDP (4s) ==")
try:
    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM); s.settimeout(4)
    ident = secrets.randbelow(65536)
    frame = b'\x01' + socket.inet_aton('1.1.1.1') + struct.pack('!H', 53) + dns_query(ident)
    s.sendto(frame, TARGET)
    data, addr = s.recvfrom(4096)
    print("   reply from", addr, "len", len(data), "first bytes", data[:24].hex())
except Exception as exc:
    print("   no reply:", type(exc).__name__, exc)
finally:
    try: s.close()
    except Exception: pass

print("== 4. TCP connect to target ==")
try:
    t = socket.create_connection(TARGET, timeout=5)
    t.settimeout(4)
    print("   connected")
    ident = secrets.randbelow(65536)
    req = b'\x01' + socket.inet_aton('1.1.1.1') + struct.pack('!H', 80) + b'GET /cdn-cgi/trace HTTP/1.1\r\nHost: one.one.one.one\r\nConnection: close\r\n\r\n'
    t.sendall(req)
    try:
        data = t.recv(512)
        print("   downstream bytes:", len(data), data[:80])
    except Exception as exc:
        print("   no downstream:", type(exc).__name__, exc)
    t.close()
except Exception as exc:
    print("   tcp connect failed:", type(exc).__name__, exc)
