"""Fail if the standalone client and sidecar copies of transport code diverge."""
from pathlib import Path

tools = Path(__file__).resolve().parents[1]
names = [
    "datagram_budget.go", "datagram_budget_test.go",
    "datagram_drops_linux.go", "datagram_drops_other.go",
    "datagram_socket_unix.go", "datagram_socket_windows.go", "datagram_socket_other.go",
    "reliable_datagram.go", "reliable_datagram_test.go", "reliable_carrier.go",
]
for name in names:
    assert (tools / "ultra-client" / name).read_bytes() == (tools / "uot-sidecar" / name).read_bytes(), name
print(f"PASS: {len(names)} shared transport files are byte-identical")
