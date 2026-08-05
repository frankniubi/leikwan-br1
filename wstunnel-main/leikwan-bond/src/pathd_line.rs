use crate::pathd::{LocalLeaseSpec, ProviderSpec};
use anyhow::{anyhow, bail, Context, Result};
use serde::Serialize;
use sha2::{Digest, Sha256};
use std::fmt;
use std::fs::{self, File, OpenOptions};
use std::io::{Read, Write};
use std::net::SocketAddr;
#[cfg(unix)]
use std::os::unix::fs::{OpenOptionsExt, PermissionsExt};
use std::path::{Path, PathBuf};
use std::process::Stdio;
use std::time::{Duration, SystemTime, UNIX_EPOCH};
use tokio::process::{Child, Command};
use tokio::time::timeout;
use uuid::Uuid;

#[cfg(not(test))]
const CHILD_STARTUP_GRACE: Duration = Duration::from_millis(250);
#[cfg(test)]
const CHILD_STARTUP_GRACE: Duration = Duration::from_millis(100);

pub trait LineClock: Send + Sync {
    fn unix_now(&self) -> i64;
}

#[derive(Clone, Copy, Debug, Default)]
pub struct SystemLineClock;

impl LineClock for SystemLineClock {
    fn unix_now(&self) -> i64 {
        SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .map(|duration| duration.as_secs() as i64)
            .unwrap_or_default()
    }
}

#[derive(Clone, Debug)]
pub struct LineRuntimePaths {
    root: PathBuf,
}

impl LineRuntimePaths {
    pub fn new(root: impl Into<PathBuf>) -> Self {
        Self { root: root.into() }
    }

    pub fn root(&self) -> &Path {
        &self.root
    }

    pub fn session_path(&self) -> PathBuf {
        self.root.join("session.json")
    }

    pub fn leases_dir(&self) -> PathBuf {
        self.root.join("leases")
    }

    pub fn lease_toml(&self, lease_id: &str) -> PathBuf {
        self.leases_dir()
            .join(format!("{}.toml", lease_id.to_ascii_lowercase()))
    }

    pub fn cleanup_secrets(&self) -> Result<()> {
        remove_file_if_present(&self.session_path())?;
        match fs::remove_dir(self.leases_dir()) {
            Ok(()) => Ok(()),
            Err(err)
                if matches!(
                    err.kind(),
                    std::io::ErrorKind::NotFound | std::io::ErrorKind::DirectoryNotEmpty
                ) =>
            {
                Ok(())
            }
            Err(err) => Err(err.into()),
        }
    }
}

pub struct LineRuntime {
    line_id: u32,
    lease_id: String,
    lease_generation: u64,
    config_sha256: String,
    provider_session_sha256: String,
    provider_binary: PathBuf,
    socks_addr: SocketAddr,
    config_path: PathBuf,
    child: Child,
    pid: u32,
}

#[derive(Clone, Debug, Serialize, PartialEq, Eq)]
pub struct LocalLineStatus {
    pub line_id: u32,
    pub lease_id: String,
    pub lease_generation: u64,
    pub service_state: String,
    pub tcp_status: String,
    pub tcp_connect_ms: Option<u64>,
    pub config_sha256: String,
    pub last_error_code: Option<String>,
}

impl fmt::Debug for LineRuntime {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        formatter
            .debug_struct("LineRuntime")
            .field("line_id", &self.line_id)
            .field("lease_id", &self.lease_id)
            .field("lease_generation", &self.lease_generation)
            .field("config_sha256", &self.config_sha256)
            .field("socks_addr", &self.socks_addr)
            .field("pid", &self.pid)
            .finish()
    }
}

impl LineRuntime {
    pub async fn start(
        lease: &LocalLeaseSpec,
        provider: &ProviderSpec,
        paths: &LineRuntimePaths,
        clock: &dyn LineClock,
    ) -> Result<Self> {
        validate_line_material(lease, provider, clock)?;
        ensure_private_dir(paths.root())?;
        ensure_private_dir(&paths.leases_dir())?;
        write_secret_atomic(
            &paths.session_path(),
            provider.session_json.expose_secret().as_bytes(),
            &provider.session_sha256,
        )?;
        let config_path = paths.lease_toml(&lease.lease_id);
        write_secret_atomic(
            &config_path,
            lease.config_toml.expose_secret().as_bytes(),
            &lease.config_sha256,
        )?;

        let mut command = Command::new(&provider.binary);
        command
            .arg("up")
            .arg("--session")
            .arg(paths.session_path())
            .arg("--config")
            .arg(&config_path)
            .stdin(Stdio::null())
            .stdout(Stdio::null())
            .stderr(Stdio::null())
            .kill_on_drop(true);
        let mut child = match command.spawn() {
            Ok(child) => child,
            Err(err) => {
                let _ = fs::remove_file(&config_path);
                return Err(err).context("failed to start provider runtime");
            }
        };
        let pid = child
            .id()
            .ok_or_else(|| anyhow!("provider runtime has no process ID"))?;
        if let Ok(waited) = timeout(CHILD_STARTUP_GRACE, child.wait()).await {
            let status = waited.context("failed waiting for provider runtime startup")?;
            let _ = fs::remove_file(&config_path);
            bail!("provider runtime exited during startup with status {status}");
        }

        Ok(Self {
            line_id: lease.line_id,
            lease_id: lease.lease_id.to_ascii_lowercase(),
            lease_generation: lease.lease_generation,
            config_sha256: lease.config_sha256.to_ascii_lowercase(),
            provider_session_sha256: provider.session_sha256.to_ascii_lowercase(),
            provider_binary: provider.binary.clone(),
            socks_addr: lease.socks_addr,
            config_path,
            child,
            pid,
        })
    }

    pub fn matches(&mut self, lease: &LocalLeaseSpec, provider: &ProviderSpec) -> bool {
        self.child.try_wait().ok().flatten().is_none()
            && self.line_id == lease.line_id
            && self.lease_id.eq_ignore_ascii_case(&lease.lease_id)
            && self.lease_generation == lease.lease_generation
            && self
                .config_sha256
                .eq_ignore_ascii_case(&lease.config_sha256)
            && self
                .provider_session_sha256
                .eq_ignore_ascii_case(&provider.session_sha256)
            && self.provider_binary == provider.binary
            && self.socks_addr == lease.socks_addr
    }

    pub fn line_id(&self) -> u32 {
        self.line_id
    }

    pub fn lease_id(&self) -> &str {
        &self.lease_id
    }

    pub fn lease_generation(&self) -> u64 {
        self.lease_generation
    }

    pub fn config_sha256(&self) -> &str {
        &self.config_sha256
    }

    pub fn socks_addr(&self) -> SocketAddr {
        self.socks_addr
    }

    pub fn config_path(&self) -> &Path {
        &self.config_path
    }

    pub fn pid(&self) -> u32 {
        self.pid
    }

    pub fn is_alive(&mut self) -> bool {
        self.child.try_wait().ok().flatten().is_none()
    }

    pub fn status(&mut self) -> LocalLineStatus {
        let active = self.child.try_wait().ok().flatten().is_none();
        LocalLineStatus {
            line_id: self.line_id,
            lease_id: self.lease_id.clone(),
            lease_generation: self.lease_generation,
            service_state: if active { "active" } else { "failed" }.to_string(),
            tcp_status: String::from("unknown"),
            tcp_connect_ms: None,
            config_sha256: self.config_sha256.clone(),
            last_error_code: if active {
                None
            } else {
                Some(String::from("provider_process_exited"))
            },
        }
    }

    pub async fn stop(mut self, grace: Duration) -> Result<()> {
        terminate_child(&mut self.child, self.pid, grace).await?;
        remove_file_if_present(&self.config_path)?;
        Ok(())
    }
}

impl Drop for LineRuntime {
    fn drop(&mut self) {
        let _ = self.child.start_kill();
        let _ = fs::remove_file(&self.config_path);
    }
}

pub(crate) fn validate_line_material(
    lease: &LocalLeaseSpec,
    provider: &ProviderSpec,
    clock: &dyn LineClock,
) -> Result<()> {
    validate_provider(provider)?;
    validate_lease(lease, clock.unix_now())?;
    if !provider.allows_local_socks_port(lease.socks_addr.port()) {
        bail!("local lease endpoint is outside the configured SOCKS port range");
    }
    Ok(())
}

fn validate_provider(provider: &ProviderSpec) -> Result<()> {
    provider.validate_local_socks_range()?;
    if !provider.binary.is_absolute() || provider.session_json.expose_secret().trim().is_empty() {
        bail!("provider runtime material is incomplete");
    }
    validate_sha256(&provider.session_sha256)?;
    if sha256_bytes(provider.session_json.expose_secret().as_bytes())
        != provider.session_sha256.to_ascii_lowercase()
    {
        bail!("provider session hash mismatch");
    }
    let metadata = fs::metadata(&provider.binary).context("provider runtime binary is missing")?;
    if !metadata.is_file() {
        bail!("provider runtime binary is not a file");
    }
    #[cfg(unix)]
    if metadata.permissions().mode() & 0o111 == 0 {
        bail!("provider runtime binary is not executable");
    }
    Ok(())
}

fn validate_lease(lease: &LocalLeaseSpec, now: i64) -> Result<()> {
    let uuid = Uuid::parse_str(&lease.lease_id).context("local lease ID is not a UUID")?;
    if lease.line_id == 0 || uuid.get_version_num() != 4 || lease.lease_generation == 0 {
        bail!("local lease fence is invalid");
    }
    if lease.expires_at_unix <= now {
        bail!("local lease is expired");
    }
    if !lease.socks_addr.ip().is_loopback() || lease.socks_addr.port() == 0 {
        bail!("local lease endpoint is not loopback");
    }
    validate_sha256(&lease.config_sha256)?;
    if lease.config_toml.expose_secret().trim().is_empty()
        || sha256_bytes(lease.config_toml.expose_secret().as_bytes())
            != lease.config_sha256.to_ascii_lowercase()
    {
        bail!("local lease config hash mismatch");
    }
    Ok(())
}

fn validate_sha256(value: &str) -> Result<()> {
    if value.len() != 64 || !value.bytes().all(|byte| byte.is_ascii_hexdigit()) {
        bail!("SHA-256 value is invalid");
    }
    Ok(())
}

fn ensure_private_dir(path: &Path) -> Result<()> {
    fs::create_dir_all(path)
        .with_context(|| format!("create runtime directory {}", path.display()))?;
    #[cfg(unix)]
    fs::set_permissions(path, fs::Permissions::from_mode(0o700))
        .with_context(|| format!("secure runtime directory {}", path.display()))?;
    Ok(())
}

fn write_secret_atomic(path: &Path, contents: &[u8], expected_sha256: &str) -> Result<()> {
    validate_sha256(expected_sha256)?;
    let actual = sha256_bytes(contents);
    if actual != expected_sha256.to_ascii_lowercase() {
        bail!("secret material hash mismatch");
    }
    let parent = path
        .parent()
        .ok_or_else(|| anyhow!("runtime file has no parent"))?;
    ensure_private_dir(parent)?;
    write_private_atomic(path, contents)?;
    if sha256_file(path)? != actual {
        bail!("secret material hash changed after commit");
    }
    Ok(())
}

pub(crate) fn write_private_atomic(path: &Path, contents: &[u8]) -> Result<()> {
    let actual = sha256_bytes(contents);
    if path.exists() && sha256_file(path).ok().as_deref() == Some(actual.as_str()) {
        #[cfg(unix)]
        fs::set_permissions(path, fs::Permissions::from_mode(0o600))?;
        return Ok(());
    }
    let parent = path
        .parent()
        .ok_or_else(|| anyhow!("runtime file has no parent"))?;
    fs::create_dir_all(parent)
        .with_context(|| format!("create file directory {}", parent.display()))?;
    let name = path
        .file_name()
        .and_then(|value| value.to_str())
        .unwrap_or("material");
    let temporary = parent.join(format!(".{name}.{}.tmp", Uuid::now_v7()));
    let mut options = OpenOptions::new();
    options.write(true).create_new(true);
    #[cfg(unix)]
    options.mode(0o600);
    let result = (|| -> Result<()> {
        let mut file = options.open(&temporary)?;
        file.write_all(contents)?;
        file.sync_all()?;
        drop(file);
        if sha256_file(&temporary)? != actual {
            bail!("runtime file hash changed while writing");
        }
        fs::rename(&temporary, path)?;
        #[cfg(unix)]
        fs::set_permissions(path, fs::Permissions::from_mode(0o600))?;
        File::open(parent)?.sync_all()?;
        Ok(())
    })();
    if result.is_err() {
        let _ = fs::remove_file(&temporary);
    }
    result
}

async fn terminate_child(child: &mut Child, pid: u32, grace: Duration) -> Result<()> {
    if child.try_wait()?.is_some() {
        return Ok(());
    }
    #[cfg(unix)]
    unsafe {
        libc::kill(pid as libc::pid_t, libc::SIGTERM);
    }
    #[cfg(not(unix))]
    child.start_kill()?;
    if timeout(grace, child.wait()).await.is_err() {
        child.start_kill()?;
        let _ = child.wait().await?;
    }
    Ok(())
}

fn remove_file_if_present(path: &Path) -> Result<()> {
    match fs::remove_file(path) {
        Ok(()) => Ok(()),
        Err(err) if err.kind() == std::io::ErrorKind::NotFound => Ok(()),
        Err(err) => Err(err.into()),
    }
}

pub fn sha256_bytes(contents: &[u8]) -> String {
    format!("{:x}", Sha256::digest(contents))
}

pub fn sha256_file(path: &Path) -> Result<String> {
    let mut file = File::open(path)?;
    let mut digest = Sha256::new();
    let mut buffer = [0u8; 8192];
    loop {
        let read = file.read(&mut buffer)?;
        if read == 0 {
            break;
        }
        digest.update(&buffer[..read]);
    }
    Ok(format!("{:x}", digest.finalize()))
}

pub fn process_is_alive(pid: u32) -> bool {
    #[cfg(target_os = "linux")]
    {
        Path::new("/proc").join(pid.to_string()).exists()
    }
    #[cfg(not(target_os = "linux"))]
    {
        let _ = pid;
        false
    }
}

pub fn restart_backoff(attempt: u32) -> Duration {
    let shift = attempt.min(6);
    Duration::from_secs((1u64 << shift).min(60))
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::pathd::{LocalLeaseSpec, ProviderSpec, SecretString};
    use std::fs;
    use std::os::unix::fs::PermissionsExt;
    use std::path::{Path, PathBuf};

    #[derive(Clone)]
    struct FakeClock(i64);

    impl LineClock for FakeClock {
        fn unix_now(&self) -> i64 {
            self.0
        }
    }

    fn temp_root(label: &str) -> PathBuf {
        std::env::temp_dir().join(format!(
            "leikwan-pathd-line-{label}-{}-{}",
            std::process::id(),
            std::time::SystemTime::now()
                .duration_since(std::time::UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ))
    }

    fn write_executable(root: &Path, body: &str) -> PathBuf {
        fs::create_dir_all(root).unwrap();
        let path = root.join("provider-runtime");
        fs::write(&path, body).unwrap();
        fs::set_permissions(&path, fs::Permissions::from_mode(0o700)).unwrap();
        path
    }

    fn provider(binary: PathBuf, session: &str) -> ProviderSpec {
        ProviderSpec {
            binary,
            session_json: SecretString::new_for_test(session),
            session_sha256: sha256_bytes(session.as_bytes()),
            local_socks_port_min: 20_000,
            local_socks_port_max: 29_999,
        }
    }

    fn lease(config: &str) -> LocalLeaseSpec {
        LocalLeaseSpec {
            line_id: 451,
            lease_id: String::from("00000000-0000-4000-8000-000000000020"),
            lease_generation: 7,
            max_lanes: 2,
            config_sha256: sha256_bytes(config.as_bytes()),
            config_toml: SecretString::new_for_test(config),
            expires_at_unix: 2_000_000_000,
            socks_addr: "127.0.0.1:20451".parse().unwrap(),
        }
    }

    #[tokio::test]
    async fn writes_root_only_material_and_stops_child_without_secret_argv() {
        let root = temp_root("lifecycle");
        let args_log = root.join("args.log");
        let script = format!(
            "#!/bin/sh\nprintf '%s\\n' \"$@\" > '{}'\ntrap 'exit 0' TERM INT\nwhile :; do sleep 1; done\n",
            args_log.display()
        );
        let binary = write_executable(&root, &script);
        let paths = LineRuntimePaths::new(root.join("run"));
        let provider = provider(binary, "session-secret");
        let lease = lease("provider-secret");
        let runtime = LineRuntime::start(&lease, &provider, &paths, &FakeClock(1_900_000_000))
            .await
            .unwrap();
        let pid = runtime.pid();
        let config_path = runtime.config_path().to_path_buf();

        assert_eq!(
            0o600,
            fs::metadata(paths.session_path())
                .unwrap()
                .permissions()
                .mode()
                & 0o777
        );
        assert_eq!(
            0o600,
            fs::metadata(&config_path).unwrap().permissions().mode() & 0o777
        );
        assert_eq!(lease.config_sha256, sha256_file(&config_path).unwrap());
        assert!(runtime.socks_addr().ip().is_loopback());
        let argv = fs::read_to_string(&args_log).unwrap();
        assert!(argv.contains("--session"));
        assert!(argv.contains("--config"));
        assert!(!argv.contains("session-secret"));
        assert!(!argv.contains("provider-secret"));
        assert!(process_is_alive(pid));

        runtime
            .stop(std::time::Duration::from_secs(2))
            .await
            .unwrap();
        assert!(!config_path.exists());
        assert!(!process_is_alive(pid));
        let _ = fs::remove_dir_all(root);
    }

    #[tokio::test]
    async fn rejects_missing_material_bad_hash_expiry_and_stale_generation() {
        let root = temp_root("reject");
        let binary = write_executable(
            &root,
            "#!/bin/sh\ntrap 'exit 0' TERM INT\nwhile :; do sleep 1; done\n",
        );
        let paths = LineRuntimePaths::new(root.join("run"));
        let clock = FakeClock(1_900_000_000);

        let mut missing = provider(binary.clone(), "session-secret");
        missing.session_json = SecretString::new_for_test("");
        assert!(
            LineRuntime::start(&lease("config"), &missing, &paths, &clock)
                .await
                .is_err()
        );

        let provider = provider(binary, "session-secret");
        let mut bad_hash = lease("config");
        bad_hash.config_sha256 = "a".repeat(64);
        assert!(LineRuntime::start(&bad_hash, &provider, &paths, &clock)
            .await
            .is_err());

        let mut expired = lease("config");
        expired.expires_at_unix = clock.unix_now();
        assert!(LineRuntime::start(&expired, &provider, &paths, &clock)
            .await
            .is_err());

        let mut stale = lease("config");
        stale.lease_generation = 0;
        assert!(LineRuntime::start(&stale, &provider, &paths, &clock)
            .await
            .is_err());
        let _ = fs::remove_dir_all(root);
    }

    #[tokio::test]
    async fn child_early_exit_is_rejected_and_material_is_cleaned() {
        let root = temp_root("early-exit");
        let binary = write_executable(&root, "#!/bin/sh\nexit 7\n");
        let paths = LineRuntimePaths::new(root.join("run"));
        let lease = lease("config");
        let result = LineRuntime::start(
            &lease,
            &provider(binary, "session-secret"),
            &paths,
            &FakeClock(1_900_000_000),
        )
        .await;

        assert!(result.is_err());
        assert!(!paths.lease_toml(&lease.lease_id).exists());
        let _ = fs::remove_dir_all(root);
    }

    #[test]
    fn restart_backoff_is_exponential_and_capped() {
        assert_eq!(std::time::Duration::from_secs(1), restart_backoff(0));
        assert_eq!(std::time::Duration::from_secs(8), restart_backoff(3));
        assert_eq!(std::time::Duration::from_secs(60), restart_backoff(99));
    }
}
