import importlib.util
from pathlib import Path

spec = importlib.util.spec_from_file_location("runtime_identity", Path(__file__).parents[1] / "scripts/runtime-build-identity.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

def test_identity_is_explicit_and_platform_independent():
    a = module.identity()
    assert a == module.identity()
    assert len(a["source_sha256"]) == 64
    assert a["fingerprint"] == a["version"] + "." + a["source_sha256"][:12]
