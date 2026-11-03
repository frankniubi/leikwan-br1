//! In-process host for the leikwan ingress and bond runtimes.
//!
//! Android starts `libleikwan_ingress.so` and `libleikwan_bond.so` as child
//! processes. iOS forbids child processes, so the packet tunnel links this
//! static library instead and starts each runtime on its own thread and tokio
//! runtime, with exactly the argument vector Android would have used.

use std::collections::HashMap;
use std::ffi::{c_char, c_void, CStr, CString};
use std::panic::{catch_unwind, AssertUnwindSafe};
use std::ptr;
use std::sync::atomic::{AtomicI32, AtomicI64, Ordering};
use std::sync::{mpsc, Arc, Mutex, OnceLock};
use std::thread::JoinHandle;
use std::time::Duration;

use clap::{Parser, Subcommand};
use tokio::sync::oneshot;

pub const STATE_UNKNOWN: i32 = 0;
pub const STATE_RUNNING: i32 = 1;
pub const STATE_EXITED: i32 = 2;
pub const STATE_FAILED: i32 = 3;

/// How long start() waits for an immediate failure (bad listen address,
/// port already bound) so the caller gets it synchronously.
const STARTUP_GRACE: Duration = Duration::from_millis(300);
const SHUTDOWN_GRACE: Duration = Duration::from_millis(500);

/// Mirrors the `client` subcommand of the leikwan-ingress binary.
#[derive(Parser, Debug)]
#[command(name = "leikwan-ingress")]
struct IngressCli {
    #[command(subcommand)]
    command: IngressCommand,
}

#[derive(Subcommand, Debug)]
enum IngressCommand {
    Client {
        #[arg(long)]
        listen: String,
        #[arg(long)]
        remote: String,
        #[arg(long = "route-key")]
        route_key: String,
        #[arg(long = "auth-token")]
        auth_token: String,
    },
}

enum Job {
    Ingress {
        listen: String,
        remote: String,
        route_key: String,
        auth_token: String,
    },
    Bond(leikwan_bond::cli::Prepared),
}

impl Job {
    fn parse(kind: &str, args: &[String]) -> anyhow::Result<Job> {
        match kind {
            "ingress" => {
                let argv = std::iter::once("leikwan-ingress".to_string()).chain(args.iter().cloned());
                let cli = IngressCli::try_parse_from(argv)?;
                match cli.command {
                    IngressCommand::Client {
                        listen,
                        remote,
                        route_key,
                        auth_token,
                    } => Ok(Job::Ingress {
                        listen,
                        remote,
                        route_key,
                        auth_token,
                    }),
                }
            }
            "bond" => {
                let argv = std::iter::once("leikwan-bond".to_string()).chain(args.iter().cloned());
                let cli = leikwan_bond::cli::Cli::try_parse_from(argv)?;
                match leikwan_bond::cli::prepare(cli)? {
                    prepared @ leikwan_bond::cli::Prepared::Client { .. } => Ok(Job::Bond(prepared)),
                    leikwan_bond::cli::Prepared::Server { .. } => {
                        Err(anyhow::anyhow!("bond server mode is not available on iOS"))
                    }
                }
            }
            other => Err(anyhow::anyhow!("unknown runtime kind {other}")),
        }
    }

    /// Ingress legs are thin relays; the bond scheduler gets two workers.
    fn worker_threads(&self) -> usize {
        match self {
            Job::Ingress { .. } => 1,
            Job::Bond(_) => 2,
        }
    }

    async fn run(self) -> anyhow::Result<()> {
        match self {
            Job::Ingress {
                listen,
                remote,
                route_key,
                auth_token,
            } => leikwan_ingress::client::run_client(&listen, &remote, &route_key, &auth_token).await,
            Job::Bond(prepared) => prepared.run().await,
        }
    }
}

#[derive(Default)]
struct Status {
    state: AtomicI32,
    error: Mutex<String>,
}

struct Entry {
    shutdown: Option<oneshot::Sender<()>>,
    thread: Option<JoinHandle<()>>,
    status: Arc<Status>,
}

fn registry() -> &'static Mutex<HashMap<i64, Entry>> {
    static REGISTRY: OnceLock<Mutex<HashMap<i64, Entry>>> = OnceLock::new();
    REGISTRY.get_or_init(|| Mutex::new(HashMap::new()))
}

static NEXT_HANDLE: AtomicI64 = AtomicI64::new(1);

fn run_job(job: Job, shutdown: oneshot::Receiver<()>) -> Result<(), String> {
    let workers = job.worker_threads();
    let mut builder = if workers <= 1 {
        tokio::runtime::Builder::new_current_thread()
    } else {
        tokio::runtime::Builder::new_multi_thread()
    };
    if workers > 1 {
        builder.worker_threads(workers);
    }
    let runtime = builder
        .enable_all()
        .build()
        .map_err(|err| format!("failed to build tokio runtime: {err}"))?;
    let result = runtime.block_on(async move {
        tokio::select! {
            outcome = job.run() => outcome.map_err(|err| format!("{err:#}")),
            _ = shutdown => Ok(()),
        }
    });
    runtime.shutdown_timeout(SHUTDOWN_GRACE);
    result
}

/// Starts a runtime; returns its handle or the startup error.
pub fn start(kind: &str, args: Vec<String>) -> Result<i64, String> {
    let job = Job::parse(kind, &args).map_err(|err| format!("{err:#}"))?;
    let status = Arc::new(Status::default());
    status.state.store(STATE_RUNNING, Ordering::SeqCst);
    let (shutdown_tx, shutdown_rx) = oneshot::channel::<()>();
    let (early_tx, early_rx) = mpsc::channel::<Result<(), String>>();
    let thread_status = Arc::clone(&status);
    let thread = std::thread::Builder::new()
        .name(format!("lk-{kind}"))
        .spawn(move || {
            let outcome = catch_unwind(AssertUnwindSafe(|| run_job(job, shutdown_rx)))
                .unwrap_or_else(|_| Err("runtime panicked".to_string()));
            match &outcome {
                Ok(()) => thread_status.state.store(STATE_EXITED, Ordering::SeqCst),
                Err(message) => {
                    if let Ok(mut error) = thread_status.error.lock() {
                        *error = message.clone();
                    }
                    thread_status.state.store(STATE_FAILED, Ordering::SeqCst);
                }
            }
            let _ = early_tx.send(outcome);
        })
        .map_err(|err| format!("failed to spawn runtime thread: {err}"))?;

    match early_rx.recv_timeout(STARTUP_GRACE) {
        Ok(Err(message)) => {
            let _ = thread.join();
            return Err(message);
        }
        Ok(Ok(())) => {
            let _ = thread.join();
            return Err("runtime exited immediately".to_string());
        }
        Err(_) => {}
    }

    let handle = NEXT_HANDLE.fetch_add(1, Ordering::SeqCst);
    let entry = Entry {
        shutdown: Some(shutdown_tx),
        thread: Some(thread),
        status,
    };
    registry()
        .lock()
        .map_err(|_| "runtime registry poisoned".to_string())?
        .insert(handle, entry);
    Ok(handle)
}

/// Stops a runtime and forgets its handle; false for an unknown handle.
pub fn stop(handle: i64) -> bool {
    let entry = match registry().lock() {
        Ok(mut map) => map.remove(&handle),
        Err(_) => None,
    };
    let Some(mut entry) = entry else {
        return false;
    };
    if let Some(shutdown) = entry.shutdown.take() {
        let _ = shutdown.send(());
    }
    if let Some(thread) = entry.thread.take() {
        let _ = thread.join();
    }
    true
}

pub fn state(handle: i64) -> i32 {
    match registry().lock() {
        Ok(map) => map
            .get(&handle)
            .map(|entry| entry.status.state.load(Ordering::SeqCst))
            .unwrap_or(STATE_UNKNOWN),
        Err(_) => STATE_UNKNOWN,
    }
}

pub fn last_error(handle: i64) -> String {
    match registry().lock() {
        Ok(map) => map
            .get(&handle)
            .and_then(|entry| entry.status.error.lock().ok().map(|error| error.clone()))
            .unwrap_or_default(),
        Err(_) => String::new(),
    }
}

type LogFn = extern "C" fn(level: i32, message: *const c_char, context: *mut c_void);

struct CallbackLogger {
    callback: LogFn,
    context: usize,
    max_level: log::LevelFilter,
}

impl log::Log for CallbackLogger {
    fn enabled(&self, metadata: &log::Metadata) -> bool {
        metadata.level() <= self.max_level
    }

    fn log(&self, record: &log::Record) {
        if !self.enabled(record.metadata()) {
            return;
        }
        let text = format!("[{}] {}", record.target(), record.args()).replace('\0', " ");
        if let Ok(message) = CString::new(text) {
            (self.callback)(record.level() as usize as i32, message.as_ptr(), self.context as *mut c_void);
        }
    }

    fn flush(&self) {}
}

fn level_filter(max_level: i32) -> log::LevelFilter {
    match max_level {
        i32::MIN..=0 => log::LevelFilter::Off,
        1 => log::LevelFilter::Error,
        2 => log::LevelFilter::Warn,
        3 => log::LevelFilter::Info,
        4 => log::LevelFilter::Debug,
        _ => log::LevelFilter::Trace,
    }
}

unsafe fn write_c_string(buffer: *mut c_char, buffer_len: usize, message: &str) {
    if buffer.is_null() || buffer_len == 0 {
        return;
    }
    let bytes = message.as_bytes();
    let count = bytes.len().min(buffer_len - 1);
    ptr::copy_nonoverlapping(bytes.as_ptr() as *const c_char, buffer, count);
    *buffer.add(count) = 0;
}

#[no_mangle]
pub extern "C" fn lk_runtime_set_log(callback: Option<LogFn>, context: *mut c_void, max_level: i32) -> i32 {
    let Some(callback) = callback else {
        return -1;
    };
    let filter = level_filter(max_level);
    let logger = CallbackLogger {
        callback,
        context: context as usize,
        max_level: filter,
    };
    match log::set_boxed_logger(Box::new(logger)) {
        Ok(()) => {
            log::set_max_level(filter);
            0
        }
        Err(_) => -1,
    }
}

/// # Safety
/// `kind` must be a valid C string, `argv` must point to `argc` valid C
/// strings, and `error` must be writable for `error_len` bytes (or null).
#[no_mangle]
pub unsafe extern "C" fn lk_runtime_start(
    kind: *const c_char,
    argv: *const *const c_char,
    argc: usize,
    error: *mut c_char,
    error_len: usize,
) -> i64 {
    let outcome = catch_unwind(AssertUnwindSafe(|| {
        if kind.is_null() || (argc > 0 && argv.is_null()) {
            return Err("invalid arguments".to_string());
        }
        let kind = CStr::from_ptr(kind).to_string_lossy().into_owned();
        let mut args = Vec::with_capacity(argc);
        for index in 0..argc {
            let item = *argv.add(index);
            if item.is_null() {
                return Err(format!("argument {index} is null"));
            }
            args.push(CStr::from_ptr(item).to_string_lossy().into_owned());
        }
        start(&kind, args)
    }))
    .unwrap_or_else(|_| Err("runtime start panicked".to_string()));
    match outcome {
        Ok(handle) => handle,
        Err(message) => {
            write_c_string(error, error_len, &message);
            -1
        }
    }
}

#[no_mangle]
pub extern "C" fn lk_runtime_stop(handle: i64) -> i32 {
    match catch_unwind(|| stop(handle)) {
        Ok(true) => 0,
        _ => -1,
    }
}

#[no_mangle]
pub extern "C" fn lk_runtime_state(handle: i64) -> i32 {
    catch_unwind(|| state(handle)).unwrap_or(STATE_UNKNOWN)
}

/// # Safety
/// `buffer` must be writable for `buffer_len` bytes (or null).
#[no_mangle]
pub unsafe extern "C" fn lk_runtime_last_error(handle: i64, buffer: *mut c_char, buffer_len: usize) -> usize {
    let message = catch_unwind(|| last_error(handle)).unwrap_or_default();
    write_c_string(buffer, buffer_len, &message);
    message.len()
}

#[no_mangle]
pub extern "C" fn lk_runtime_version() -> *const c_char {
    static VERSION: &str = concat!(env!("CARGO_PKG_VERSION"), "\0");
    VERSION.as_ptr() as *const c_char
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::net::TcpListener;

    fn args(values: &[&str]) -> Vec<String> {
        values.iter().map(|value| value.to_string()).collect()
    }

    #[test]
    fn ingress_starts_and_stops() {
        let handle = start(
            "ingress",
            args(&[
                "client",
                "--listen",
                "127.0.0.1:0",
                "--remote",
                "127.0.0.1:9",
                "--route-key",
                "route",
                "--auth-token",
                "token",
            ]),
        )
        .expect("ingress should start");
        assert_eq!(state(handle), STATE_RUNNING);
        assert!(stop(handle));
        assert_eq!(state(handle), STATE_UNKNOWN);
        assert!(!stop(handle));
    }

    #[test]
    fn ingress_bind_conflict_is_reported_synchronously() {
        let occupied = TcpListener::bind("127.0.0.1:0").expect("bind probe port");
        let address = occupied.local_addr().expect("local addr").to_string();
        let error = start(
            "ingress",
            args(&[
                "client",
                "--listen",
                &address,
                "--remote",
                "127.0.0.1:9",
                "--route-key",
                "route",
                "--auth-token",
                "token",
            ]),
        )
        .expect_err("second bind must fail");
        assert!(error.contains("failed to bind"), "unexpected error: {error}");
    }

    #[test]
    fn bond_client_arguments_parse_like_the_binary() {
        let handle = start(
            "bond",
            args(&[
                "client",
                "--protocol",
                "tcp",
                "--listen",
                "127.0.0.1:0",
                "--legs",
                "tcp://127.0.0.1:9?priority=10",
                "--session-token",
                "token",
            ]),
        )
        .expect("bond should start");
        assert!(stop(handle));
    }

    #[test]
    fn bond_server_and_unknown_kinds_are_rejected() {
        assert!(start("bond", args(&["server", "--listen", "127.0.0.1:0", "--token-secret", "s"])).is_err());
        assert!(start("wstunnel", args(&["client"])).is_err());
        assert!(start("ingress", args(&["client", "--listen", "127.0.0.1:0"])).is_err());
    }
}
