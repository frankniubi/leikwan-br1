import json
import pathlib
import struct
import unittest


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]
ARTIFACT_PATH = REPO_ROOT / "artifacts" / "ultra" / "ultra-client_linux_amd64"
VERSIONS_PATH = REPO_ROOT / "artifacts" / "versions.json"


def elf_sections(data: bytes) -> set[str]:
    if data[:6] != b"\x7fELF\x02\x01":
        raise AssertionError("artifact is not a little-endian ELF64 binary")
    section_offset = struct.unpack_from("<Q", data, 40)[0]
    section_entry_size = struct.unpack_from("<H", data, 58)[0]
    section_count = struct.unpack_from("<H", data, 60)[0]
    names_index = struct.unpack_from("<H", data, 62)[0]
    names_header = section_offset + names_index * section_entry_size
    names_offset = struct.unpack_from("<Q", data, names_header + 24)[0]
    names_size = struct.unpack_from("<Q", data, names_header + 32)[0]
    names = data[names_offset : names_offset + names_size]
    result: set[str] = set()
    for index in range(section_count):
        header = section_offset + index * section_entry_size
        name_offset = struct.unpack_from("<I", data, header)[0]
        if name_offset >= len(names):
            continue
        end = names.find(b"\0", name_offset)
        if end < 0:
            continue
        result.add(names[name_offset:end].decode("ascii", errors="ignore"))
    return result


def gnu_stack_flags(data: bytes) -> int | None:
    program_offset = struct.unpack_from("<Q", data, 32)[0]
    program_entry_size = struct.unpack_from("<H", data, 54)[0]
    program_count = struct.unpack_from("<H", data, 56)[0]
    for index in range(program_count):
        header = program_offset + index * program_entry_size
        segment_type, flags = struct.unpack_from("<II", data, header)
        if segment_type == 0x6474E551:
            return flags
    return None


class UltraArtifactHardeningTests(unittest.TestCase):
    @unittest.skipUnless(
        ARTIFACT_PATH.is_file(),
        "release binary artifacts/ultra/ultra-client_linux_amd64 is not tracked in git; runs on the release machine",
    )
    def test_linux_artifact_is_obfuscated_pie_and_stripped(self) -> None:
        data = ARTIFACT_PATH.read_bytes()
        sections = elf_sections(data)

        self.assertEqual(3, struct.unpack_from("<H", data, 16)[0], "ELF must be ET_DYN PIE")
        self.assertEqual(0, (gnu_stack_flags(data) or -1) & 1, "GNU stack must not be executable")
        self.assertFalse(any(name.startswith(".debug") for name in sections))
        self.assertNotIn(".symtab", sections)
        self.assertNotIn(".strtab", sections)
        self.assertNotIn(".note.gnu.build-id", sections)
        # garble renames main.* identifiers and encrypts -literals strings, so a
        # genuinely obfuscated runtime contains none of these distinctive source
        # symbols. A plain `go build -ldflags '-s -w'` strips the symbol table but
        # keeps the pclntab, so every one of these would still ship in cleartext.
        for marker in [
            b"leikwan-ultra-policy-v2",
            b"tools/ultra-client",
            b"C:\\Users\\",
            b"/mnt/c/",
            b"parseTarget",
            b"credentialChecksum",
            b"runtimeAttestation",
            b"copyInbound",
            b"selfExecutableHash",
            b"ParseNativeRoutePolicy",
        ]:
            self.assertFalse(marker in data, f"artifact leaks plaintext marker: {marker!r}")

    def test_linux_runtime_manifest_and_build_script_hardening(self) -> None:
        manifest = json.loads(VERSIONS_PATH.read_text(encoding="utf-8"))["ultra_client_runtime"]
        self.assertEqual("garble-v0.17.0", manifest.get("obfuscator"))
        # garble tiny mode was dropped (b089a4e2): the runtime built with it
        # crashed silently under sustained UDP load, so it must not come back.
        self.assertEqual(
            ["cgo-disabled", "pie", "relro", "nx-stack", "trimpath", "stripped", "garble-identifiers", "garble-literals"],
            manifest.get("hardening"),
        )

        build_script = (REPO_ROOT / "scripts" / "build-ultra-linux.ps1").read_text(encoding="utf-8")
        for marker in ["v0.17.0", "-literals", "-buildmode=pie", "-trimpath", "-buildvcs=false", "-s -w -buildid="]:
            self.assertIn(marker, build_script)
        self.assertNotIn("-tiny", build_script)

        # A supplied -ShortSha must survive: reading $LASTEXITCODE before git ran
        # replaced it with "unknown" (a fresh session reports $null, which is
        # -ne 0), so the stamped fingerprint never matched the release manifest
        # and the panel could not recognise a released runtime.
        for script_name in ("build-ultra-linux.ps1", "build-ultra-windows.ps1", "build-ultra-android.ps1"):
            script = (REPO_ROOT / "scripts" / script_name).read_text(encoding="utf-8")
            self.assertIn('if ($LASTEXITCODE -ne 0) { $shortSha = "" }', script, script_name)
            self.assertNotIn('if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($shortSha))', script, script_name)

    def test_build_scripts_enforce_obfuscation(self) -> None:
        # Every builder that ships an obfuscated runtime must verify the produced
        # binary instead of trusting that garble ran: a plain `go build` keeps the
        # pclntab, so without this guard an unobfuscated binary ships silently (the
        # 2026.09.25 linux/macOS/windows runtimes did exactly that: obfuscator=none).
        markers = [
            "leikwan-ultra-policy-v2",
            "parseTarget",
            "credentialChecksum",
            "runtimeAttestation",
        ]

        for script_name in ("build-ultra-linux.ps1", "build-ultra-macos.ps1"):
            script = (REPO_ROOT / "scripts" / script_name).read_text(encoding="utf-8")
            self.assertIn("function Assert-RuntimeObfuscated", script, script_name)
            self.assertIn("Assert-RuntimeObfuscated $candidatePath", script, script_name)
            for marker in markers:
                self.assertIn(marker, script, f"{script_name} missing marker {marker}")

        # Windows keeps garble opt-in (Defender false positives) but must still
        # verify obfuscation when -Obfuscate is requested.
        windows = (REPO_ROOT / "scripts" / "build-ultra-windows.ps1").read_text(encoding="utf-8")
        self.assertIn("function Assert-RuntimeObfuscated", windows)
        self.assertIn("if ($Obfuscate) { Assert-RuntimeObfuscated $candidatePath }", windows)
        self.assertIn("[switch]$Obfuscate", windows)

        macos_sh = (REPO_ROOT / "scripts" / "build-macos-client.sh").read_text(encoding="utf-8")
        self.assertIn("assert_ultra_obfuscated", macos_sh)
        self.assertIn('assert_ultra_obfuscated "$helper_dir/ultra-client"', macos_sh)
        for marker in markers:
            self.assertIn(marker, macos_sh, f"build-macos-client.sh missing marker {marker}")


if __name__ == "__main__":
    unittest.main()
