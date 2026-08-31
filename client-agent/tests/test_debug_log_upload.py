import importlib.util
import pathlib
import tempfile
import unittest


ROOT = pathlib.Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("client_agent_debug_upload", ROOT / "client-agent" / "client_agent.py")
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(MODULE)


class DebugLogUploadTests(unittest.TestCase):
    def test_redaction_removes_credentials(self):
        source = "Authorization: Bearer abc\npassword=secret token: xyz\nline=healthy"
        output = MODULE.redact_debug_text(source)
        self.assertNotIn("abc", output)
        self.assertNotIn("secret", output)
        self.assertNotIn("xyz", output)
        self.assertIn("line=healthy", output)

    def test_upload_is_chunked_retried_and_resumable(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            log = root / "client.log"
            log.write_text("password=hidden\n" + ("x" * (MODULE.DEBUG_LOG_CHUNK_BYTES + 17)), encoding="utf-8")
            calls = []
            failures = {0}

            def post(payload):
                calls.append(payload)
                if len(calls) == 1 and 0 in failures:
                    failures.remove(0)
                    raise RuntimeError("temporary network error")
                return {"ok": True}

            metadata = {
                "debug_collection": {
                    "uuid": "123e4567-e89b-42d3-a456-426614174000",
                    "status": "active",
                    "expires_at": "2999-01-01T00:00:00Z",
                }
            }
            result = MODULE.upload_debug_collection(
                "https://panel.example.test",
                "agent-token",
                metadata,
                root,
                {"debug_log_paths": [str(log)]},
                post=post,
            )
            self.assertEqual("complete", result["status"])
            self.assertGreaterEqual(len(calls), 2)
            self.assertTrue(all("hidden" not in str(item) for item in calls))
            state = MODULE.json.loads((root / "debug-upload-state.json").read_text())
            self.assertEqual(len({item["sequence"] for item in calls}), state["next_sequence"])

    def test_inactive_collection_does_not_read_or_upload(self):
        with tempfile.TemporaryDirectory() as directory:
            result = MODULE.upload_debug_collection(
                "https://panel.example.test",
                "agent-token",
                {"debug_collection": {"uuid": "", "status": "closed"}},
                pathlib.Path(directory),
                {},
                post=lambda payload: self.fail("inactive collection uploaded"),
            )
            self.assertEqual("inactive", result["status"])


if __name__ == "__main__":
    unittest.main()
