import json
import os
import pathlib
import time

vpath = pathlib.Path(r"C:\Users\frankniubi\Downloads\leikwan-br1\artifacts\versions.json")
doc = json.loads(vpath.read_text(encoding="utf-8"))
doc["retired_runtime_030b48158478"]["attestation"] = {
    "fingerprint": "2026.09.12.1.4b106b02",
    "self_sha256": "030b48158478de640c47c384bd0c2c7d58f71dc062063700f82d1bb07db8fc63",
}
payload = json.dumps(doc, indent=4, ensure_ascii=False).encode("utf-8") + b"\n"
tmp = vpath.with_suffix(".json.new")
for attempt in range(5):
    try:
        tmp.write_bytes(payload)
        os.replace(tmp, vpath)
        break
    except OSError as exc:
        print("attempt %d failed: %s" % (attempt, exc))
        time.sleep(1)
else:
    raise SystemExit("could not write the manifest")
check = json.loads(vpath.read_text(encoding="utf-8"))
print("retired allowance:", json.dumps(check["retired_runtime_030b48158478"].get("attestation")))
print("ultra runtime:", check["ultra_client_runtime"]["version"], check["ultra_client_runtime"]["attestation"]["fingerprint"])
