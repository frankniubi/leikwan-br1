import pathlib
import unittest


REPO_ROOT = pathlib.Path(__file__).resolve().parents[2]


class SlaveInstallerHelperTests(unittest.TestCase):
    def test_slave_installer_ensures_time_sync(self) -> None:
        source = (REPO_ROOT / "install" / "install-slave.sh").read_text(encoding="utf-8")

        self.assertIn("ensure_time_sync()", source)
        self.assertIn("timedatectl show -p NTPSynchronized --value", source)
        self.assertIn("apt-get install -y ntp", source)
        self.assertIn("ensure_time_sync", source)

    def test_slave_installer_accepts_panel_manual_install_flags(self) -> None:
        source = (REPO_ROOT / "install" / "install-slave.sh").read_text(encoding="utf-8")

        for snippet in [
            "--tls-mode",
            "--tls-domain",
            "--tls-email",
            "--tls-wildcard",
            "--tls-cert",
            "--tls-key",
            "--cloudflare-email",
            "--cloudflare-global-key",
            "--force-register",
        ]:
            self.assertIn(snippet, source)

    def test_slave_installer_persists_tls_bootstrap_config(self) -> None:
        source = (REPO_ROOT / "install" / "install-slave.sh").read_text(encoding="utf-8")

        for snippet in [
            '"tls_mode":',
            '"tls_domain":',
            '"tls_email":',
            '"tls_wildcard":',
            '"tls_cert_path":',
            '"tls_key_path":',
            '"cloudflare_email":',
            '"cloudflare_global_key":',
        ]:
            self.assertIn(snippet, source)

    def test_slave_installer_only_reregisters_when_forced_or_identity_missing(self) -> None:
        source = (REPO_ROOT / "install" / "install-slave.sh").read_text(encoding="utf-8")

        self.assertIn("FORCE_REGISTER=0", source)
        self.assertIn('--force-register) FORCE_REGISTER=1; shift ;;', source)
        self.assertIn('if [[ "$FORCE_REGISTER" == "1" ]]; then', source)
        self.assertIn('if [[ -z "$NODE_TOKEN" || "$FORCE_REGISTER" == "1" ]]; then', source)


if __name__ == "__main__":
    unittest.main()
