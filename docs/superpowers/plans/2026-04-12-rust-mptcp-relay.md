# Rust MPTCP Relay Implementation Plan (Part 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Python MPTCP relay (leikwan_mptcp_client.py / leikwan_mptcp_sidecar.py) with a Rust binary using splice(2) to eliminate user-space data copying and achieve 500Mbps+ throughput.

**Architecture:** A single Rust binary `leikwan-mptcp-relay` with two modes (`client` / `server`). Client mode: listen TCP locally → splice → MPTCP socket to sidecar. Server mode: listen MPTCP → splice → TCP to target. Uses libc splice(2) via pipes for data forwarding. Shares Cargo workspace with leikwan-bond. MPTCP endpoints managed via `ip mptcp` CLI commands (same as current Python approach).

**Tech Stack:** Rust, tokio (async runtime), libc (splice/pipe syscalls), clap (CLI), hmac-sha256 (session tokens), serde_json (config)

**Depends on:** Plan A (parameter optimization) should be deployed first for sysctl tuning.

---

### Task 1: Scaffold the Rust crate in the Cargo workspace

**Files:**
- Create: `wstunnel-main/leikwan-mptcp-relay/Cargo.toml`
- Create: `wstunnel-main/leikwan-mptcp-relay/src/main.rs`
- Modify: `wstunnel-main/Cargo.toml` (workspace members)

- [ ] **Step 1: Check workspace structure**

```bash
ls wstunnel-main/Cargo.toml
cat wstunnel-main/Cargo.toml | head -20
```

Verify the workspace root and existing members.

- [ ] **Step 2: Create Cargo.toml for leikwan-mptcp-relay**

```toml
[package]
name = "leikwan-mptcp-relay"
version = "0.1.0"
edition = "2021"

[dependencies]
tokio = { version = "1", features = ["full"] }
clap = { version = "4", features = ["derive"] }
serde = { version = "1", features = ["derive"] }
serde_json = "1"
hmac = "0.12"
sha2 = "0.10"
base64 = "0.22"
libc = "0.2"
log = "0.4"
env_logger = "0.11"
anyhow = "1"
```

- [ ] **Step 3: Create minimal main.rs**

```rust
use clap::{Parser, Subcommand};

#[derive(Parser)]
#[command(name = "leikwan-mptcp-relay")]
#[command(about = "MPTCP relay with splice(2) forwarding")]
struct Cli {
    #[command(subcommand)]
    command: Commands,
}

#[derive(Subcommand)]
enum Commands {
    /// Run as client: listen TCP locally, connect MPTCP to sidecar
    Client {
        #[arg(long)]
        config: String,
    },
    /// Run as server: listen MPTCP, connect TCP to target
    Server {
        #[arg(long)]
        config: String,
    },
}

fn main() -> anyhow::Result<()> {
    env_logger::init();
    let cli = Cli::parse();
    match cli.command {
        Commands::Client { config } => {
            eprintln!("client mode, config: {config}");
            Ok(())
        }
        Commands::Server { config } => {
            eprintln!("server mode, config: {config}");
            Ok(())
        }
    }
}
```

- [ ] **Step 4: Add to workspace**

Add `"leikwan-mptcp-relay"` to the workspace members list in `wstunnel-main/Cargo.toml`.

- [ ] **Step 5: Build to verify**

```bash
cd wstunnel-main && cargo build -p leikwan-mptcp-relay
```

Expected: successful build, binary at `target/debug/leikwan-mptcp-relay`.

- [ ] **Step 6: Commit**

```bash
git add wstunnel-main/leikwan-mptcp-relay/ wstunnel-main/Cargo.toml
git commit -m "feat: scaffold leikwan-mptcp-relay Rust crate in workspace"
```

---

### Task 2: Implement config parsing and session token verification

**Files:**
- Create: `wstunnel-main/leikwan-mptcp-relay/src/config.rs`
- Create: `wstunnel-main/leikwan-mptcp-relay/src/token.rs`
- Modify: `wstunnel-main/leikwan-mptcp-relay/src/main.rs`

- [ ] **Step 1: Create config.rs**

```rust
use serde::Deserialize;
use std::path::Path;

#[derive(Debug, Deserialize)]
pub struct ClientConfig {
    pub forward_id: u64,
    pub listen_port: u16,
    pub transport: String,
    pub remote_host: String,
    pub remote_port: u16,
    pub tcp_session_token: String,
    #[serde(default)]
    pub udp_session_token: String,
    #[serde(default = "default_udp_mode")]
    pub udp_mode: String,
    #[serde(default = "default_cc")]
    pub tcp_congestion_control: String,
    #[serde(default = "default_buffer")]
    pub socket_buffer_bytes: usize,
    #[serde(default = "default_connect_timeout")]
    pub connect_timeout_ms: u64,
    #[serde(default = "default_idle_timeout")]
    pub relay_idle_timeout_seconds: u64,
    #[serde(default)]
    pub speed_limit_mbps: u64,
    #[serde(default)]
    pub manage_mptcp_endpoints: bool,
    #[serde(default)]
    pub mptcp_endpoint_device: String,
    #[serde(default)]
    pub mptcp_endpoint_id_base: u32,
    #[serde(default)]
    pub mptcp_endpoints: Vec<serde_json::Value>,
    #[serde(default)]
    pub underlay: serde_json::Value,
    #[serde(default)]
    pub endpoints: Vec<serde_json::Value>,
    #[serde(default = "default_initcwnd")]
    pub initcwnd: u32,
    #[serde(default = "default_initrwnd")]
    pub initrwnd: u32,
    #[serde(default = "default_add_addr_timeout")]
    pub add_addr_timeout: u32,
    #[serde(default = "default_tcp_wmem_max")]
    pub tcp_wmem_max: u64,
    #[serde(default = "default_tcp_rmem_max")]
    pub tcp_rmem_max: u64,
}

#[derive(Debug, Deserialize)]
pub struct ServerConfig {
    pub listen_host: String,
    pub listen_port: u16,
    pub token_secret: String,
    #[serde(default = "default_cc")]
    pub tcp_congestion_control: String,
    #[serde(default = "default_buffer")]
    pub socket_buffer_bytes: usize,
    #[serde(default)]
    pub allow_tcp_fallback: bool,
    #[serde(default)]
    pub stats_path: String,
    #[serde(default)]
    pub manage_mptcp_endpoints: bool,
    #[serde(default)]
    pub underlay: serde_json::Value,
}

fn default_udp_mode() -> String { "stream_compat".into() }
fn default_cc() -> String { "bbr".into() }
fn default_buffer() -> usize { 8 * 1024 * 1024 }
fn default_connect_timeout() -> u64 { 3000 }
fn default_idle_timeout() -> u64 { 120 }
fn default_initcwnd() -> u32 { 80 }
fn default_initrwnd() -> u32 { 80 }
fn default_add_addr_timeout() -> u32 { 5 }
fn default_tcp_wmem_max() -> u64 { 16777216 }
fn default_tcp_rmem_max() -> u64 { 16777216 }

pub fn load_client_config(path: &Path) -> anyhow::Result<ClientConfig> {
    let content = std::fs::read_to_string(path)?;
    Ok(serde_json::from_str(&content)?)
}

pub fn load_server_config(path: &Path) -> anyhow::Result<ServerConfig> {
    let content = std::fs::read_to_string(path)?;
    Ok(serde_json::from_str(&content)?)
}
```

- [ ] **Step 2: Create token.rs**

Port the JWT-HMAC session token verification from leikwan-bond (or the Python equivalent). The token format is: base64url(header).base64url(payload).base64url(signature) where signature = HMAC-SHA256(secret, header.payload).

```rust
use hmac::{Hmac, Mac};
use sha2::Sha256;
use base64::{engine::general_purpose::URL_SAFE_NO_PAD, Engine};

type HmacSha256 = Hmac<Sha256>;

#[derive(Debug)]
pub struct TokenPayload {
    pub target_host: String,
    pub target_port: u16,
    pub target_protocol: String,
    pub service_id: u64,
    pub forward_id: u64,
}

pub fn verify_token(token: &str, secret: &str) -> anyhow::Result<TokenPayload> {
    let parts: Vec<&str> = token.split('.').collect();
    if parts.len() != 3 {
        anyhow::bail!("invalid token format");
    }
    let message = format!("{}.{}", parts[0], parts[1]);
    let signature = URL_SAFE_NO_PAD.decode(parts[2])?;

    let mut mac = HmacSha256::new_from_slice(secret.as_bytes())?;
    mac.update(message.as_bytes());
    mac.verify_slice(&signature)?;

    let payload_bytes = URL_SAFE_NO_PAD.decode(parts[1])?;
    let payload: serde_json::Value = serde_json::from_slice(&payload_bytes)?;

    Ok(TokenPayload {
        target_host: payload["target_host"].as_str().unwrap_or("127.0.0.1").to_string(),
        target_port: payload["target_port"].as_u64().unwrap_or(0) as u16,
        target_protocol: payload["target_protocol"].as_str().unwrap_or("tcp").to_string(),
        service_id: payload["service_id"].as_u64().unwrap_or(0),
        forward_id: payload["forward_id"].as_u64().unwrap_or(0),
    })
}
```

- [ ] **Step 3: Wire modules into main.rs**

```rust
mod config;
mod token;

use clap::{Parser, Subcommand};
use std::path::PathBuf;

#[derive(Parser)]
#[command(name = "leikwan-mptcp-relay")]
#[command(about = "MPTCP relay with splice(2) forwarding")]
struct Cli {
    #[command(subcommand)]
    command: Commands,
}

#[derive(Subcommand)]
enum Commands {
    Client {
        #[arg(long)]
        config: PathBuf,
    },
    Server {
        #[arg(long)]
        config: PathBuf,
    },
}

fn main() -> anyhow::Result<()> {
    env_logger::init();
    let cli = Cli::parse();
    match cli.command {
        Commands::Client { config } => {
            let cfg = config::load_client_config(&config)?;
            log::info!("client mode: forward_id={}, remote={}:{}", cfg.forward_id, cfg.remote_host, cfg.remote_port);
            Ok(())
        }
        Commands::Server { config } => {
            let cfg = config::load_server_config(&config)?;
            log::info!("server mode: listen={}:{}", cfg.listen_host, cfg.listen_port);
            Ok(())
        }
    }
}
```

- [ ] **Step 4: Build and test config loading**

```bash
cd wstunnel-main && cargo build -p leikwan-mptcp-relay
# Create a test config
echo '{"forward_id":20,"listen_port":11009,"transport":"tcp","remote_host":"10.203.1.3","remote_port":9000,"tcp_session_token":"test"}' > /tmp/test-mptcp-config.json
./target/debug/leikwan-mptcp-relay client --config /tmp/test-mptcp-config.json
```

Expected: logs "client mode: forward_id=20, remote=10.203.1.3:9000"

- [ ] **Step 5: Commit**

```bash
git add wstunnel-main/leikwan-mptcp-relay/src/
git commit -m "feat: add config parsing and JWT token verification for mptcp-relay"
```

---

### Task 3: Implement splice(2) forwarding core

**Files:**
- Create: `wstunnel-main/leikwan-mptcp-relay/src/splice.rs`

- [ ] **Step 1: Create splice.rs with pipe + splice forwarding**

```rust
use std::io;
use std::os::unix::io::RawFd;

const SPLICE_F_MOVE: libc::c_uint = 1;
const SPLICE_F_NONBLOCK: libc::c_uint = 2;
const PIPE_BUF_SIZE: libc::c_int = 1048576; // 1MB

/// Create a pipe with the buffer enlarged to 1MB.
pub fn create_pipe() -> io::Result<(RawFd, RawFd)> {
    let mut fds = [0 as RawFd; 2];
    let ret = unsafe { libc::pipe2(fds.as_mut_ptr(), libc::O_NONBLOCK | libc::O_CLOEXEC) };
    if ret < 0 {
        return Err(io::Error::last_os_error());
    }
    // Enlarge pipe buffer
    unsafe { libc::fcntl(fds[0], libc::F_SETPIPE_SZ, PIPE_BUF_SIZE) };
    Ok((fds[0], fds[1]))
}

/// Close a file descriptor.
pub fn close_fd(fd: RawFd) {
    unsafe { libc::close(fd) };
}

/// Splice bytes from src_fd into pipe_wr, then from pipe_rd into dst_fd.
/// Returns total bytes moved, or 0 if src is EOF.
pub fn splice_once(src_fd: RawFd, pipe_wr: RawFd, pipe_rd: RawFd, dst_fd: RawFd, max_bytes: usize) -> io::Result<usize> {
    let flags = SPLICE_F_MOVE | SPLICE_F_NONBLOCK;
    // splice: source → pipe
    let n_in = unsafe {
        libc::splice(src_fd, std::ptr::null_mut(), pipe_wr, std::ptr::null_mut(), max_bytes, flags)
    };
    if n_in < 0 {
        let err = io::Error::last_os_error();
        if err.kind() == io::ErrorKind::WouldBlock {
            return Ok(0);
        }
        return Err(err);
    }
    if n_in == 0 {
        return Ok(0); // EOF
    }
    // splice: pipe → destination (drain all n_in bytes)
    let mut remaining = n_in as usize;
    while remaining > 0 {
        let n_out = unsafe {
            libc::splice(pipe_rd, std::ptr::null_mut(), dst_fd, std::ptr::null_mut(), remaining, SPLICE_F_MOVE)
        };
        if n_out < 0 {
            return Err(io::Error::last_os_error());
        }
        if n_out == 0 {
            break;
        }
        remaining -= n_out as usize;
    }
    Ok(n_in as usize)
}
```

- [ ] **Step 2: Add module to main.rs**

Add `mod splice;` to `main.rs`.

- [ ] **Step 3: Build**

```bash
cd wstunnel-main && cargo build -p leikwan-mptcp-relay
```

- [ ] **Step 4: Commit**

```bash
git add wstunnel-main/leikwan-mptcp-relay/src/splice.rs wstunnel-main/leikwan-mptcp-relay/src/main.rs
git commit -m "feat: implement splice(2) forwarding core for mptcp-relay"
```

---

### Task 4: Implement MPTCP socket creation and tuning

**Files:**
- Create: `wstunnel-main/leikwan-mptcp-relay/src/mptcp.rs`

- [ ] **Step 1: Create mptcp.rs**

```rust
use std::io;
use std::net::{SocketAddr, TcpListener, TcpStream};
use std::os::unix::io::{AsRawFd, FromRawFd, RawFd};

const IPPROTO_MPTCP: libc::c_int = 262;

/// Create an MPTCP client socket connected to addr.
pub fn mptcp_connect(addr: SocketAddr, config: &SocketConfig) -> io::Result<TcpStream> {
    let family = if addr.is_ipv4() { libc::AF_INET } else { libc::AF_INET6 };
    let fd = unsafe { libc::socket(family, libc::SOCK_STREAM, IPPROTO_MPTCP) };
    if fd < 0 {
        // Fallback to TCP if MPTCP not available and fallback enabled
        if config.allow_tcp_fallback {
            let fd = unsafe { libc::socket(family, libc::SOCK_STREAM, libc::IPPROTO_TCP) };
            if fd < 0 {
                return Err(io::Error::last_os_error());
            }
            tune_fd(fd, config);
            let stream = unsafe { TcpStream::from_raw_fd(fd) };
            stream.connect(addr)?;
            return Ok(stream);
        }
        return Err(io::Error::last_os_error());
    }
    tune_fd(fd, config);
    let stream = unsafe { TcpStream::from_raw_fd(fd) };
    stream.connect(addr)?;
    Ok(stream)
}

/// Create an MPTCP server socket bound and listening.
pub fn mptcp_listen(addr: SocketAddr, config: &SocketConfig) -> io::Result<TcpListener> {
    let family = if addr.is_ipv4() { libc::AF_INET } else { libc::AF_INET6 };
    let fd = unsafe { libc::socket(family, libc::SOCK_STREAM, IPPROTO_MPTCP) };
    if fd < 0 {
        if config.allow_tcp_fallback {
            let fd = unsafe { libc::socket(family, libc::SOCK_STREAM, libc::IPPROTO_TCP) };
            if fd < 0 {
                return Err(io::Error::last_os_error());
            }
            tune_fd(fd, config);
            set_reuseaddr(fd);
            let listener = unsafe { TcpListener::from_raw_fd(fd) };
            listener.bind(addr)?;
            listener.set_nonblocking(true)?;
            return Ok(listener);
        }
        return Err(io::Error::last_os_error());
    }
    tune_fd(fd, config);
    set_reuseaddr(fd);
    let listener = unsafe { TcpListener::from_raw_fd(fd) };
    listener.bind(addr)?;
    listener.set_nonblocking(true)?;
    Ok(listener)
}

pub struct SocketConfig {
    pub congestion_control: String,
    pub buffer_bytes: usize,
    pub allow_tcp_fallback: bool,
}

fn tune_fd(fd: RawFd, config: &SocketConfig) {
    unsafe {
        // Buffer sizes
        let buf = config.buffer_bytes as libc::c_int;
        libc::setsockopt(fd, libc::SOL_SOCKET, libc::SO_SNDBUF, &buf as *const _ as _, std::mem::size_of::<libc::c_int>() as _);
        libc::setsockopt(fd, libc::SOL_SOCKET, libc::SO_RCVBUF, &buf as *const _ as _, std::mem::size_of::<libc::c_int>() as _);

        // TCP_NODELAY
        let one: libc::c_int = 1;
        libc::setsockopt(fd, libc::IPPROTO_TCP, libc::TCP_NODELAY, &one as *const _ as _, std::mem::size_of::<libc::c_int>() as _);

        // Congestion control
        if !config.congestion_control.is_empty() {
            let cc = config.congestion_control.as_bytes();
            libc::setsockopt(fd, libc::IPPROTO_TCP, libc::TCP_CONGESTION, cc.as_ptr() as _, cc.len() as _);
        }
    }
}

fn set_reuseaddr(fd: RawFd) {
    unsafe {
        let one: libc::c_int = 1;
        libc::setsockopt(fd, libc::SOL_SOCKET, libc::SO_REUSEADDR, &one as *const _ as _, std::mem::size_of::<libc::c_int>() as _);
    }
}
```

- [ ] **Step 2: Add module, build**

Add `mod mptcp;` to main.rs. Build with `cargo build -p leikwan-mptcp-relay`.

- [ ] **Step 3: Commit**

```bash
git add wstunnel-main/leikwan-mptcp-relay/src/mptcp.rs wstunnel-main/leikwan-mptcp-relay/src/main.rs
git commit -m "feat: implement MPTCP socket creation with tuning (bbr, 8MB buffers, TCP fallback)"
```

---

### Task 5: Implement the client relay loop

**Files:**
- Create: `wstunnel-main/leikwan-mptcp-relay/src/client.rs`
- Modify: `wstunnel-main/leikwan-mptcp-relay/src/main.rs`

- [ ] **Step 1: Create client.rs**

```rust
use crate::config::ClientConfig;
use crate::mptcp::{mptcp_connect, SocketConfig};
use crate::splice::{close_fd, create_pipe, splice_once};

use std::net::{SocketAddr, TcpListener, TcpStream};
use std::os::unix::io::AsRawFd;
use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::Arc;

const SPLICE_CHUNK: usize = 1048576; // 1MB

struct Stats {
    bytes_in: AtomicU64,
    bytes_out: AtomicU64,
    active_connections: AtomicU64,
}

pub fn run_client(config: ClientConfig) -> anyhow::Result<()> {
    let listen_addr: SocketAddr = format!("127.0.0.1:{}", config.listen_port).parse()?;
    let remote_addr: SocketAddr = format!("{}:{}", config.remote_host, config.remote_port).parse()?;
    let listener = TcpListener::bind(listen_addr)?;
    log::info!("listening on {listen_addr}, relaying to {remote_addr} via MPTCP");

    let socket_config = Arc::new(SocketConfig {
        congestion_control: config.tcp_congestion_control.clone(),
        buffer_bytes: config.socket_buffer_bytes,
        allow_tcp_fallback: false,
    });

    let stats = Arc::new(Stats {
        bytes_in: AtomicU64::new(0),
        bytes_out: AtomicU64::new(0),
        active_connections: AtomicU64::new(0),
    });

    for incoming in listener.incoming() {
        let local_stream = incoming?;
        let remote_addr = remote_addr;
        let sc = Arc::clone(&socket_config);
        let st = Arc::clone(&stats);

        std::thread::spawn(move || {
            if let Err(e) = handle_connection(local_stream, remote_addr, &sc, &st) {
                log::error!("connection error: {e}");
            }
        });
    }
    Ok(())
}

fn handle_connection(
    local: TcpStream,
    remote_addr: SocketAddr,
    socket_config: &SocketConfig,
    stats: &Stats,
) -> anyhow::Result<()> {
    stats.active_connections.fetch_add(1, Ordering::Relaxed);

    let mptcp = mptcp_connect(remote_addr, socket_config)?;
    let local_fd = local.as_raw_fd();
    let mptcp_fd = mptcp.as_raw_fd();

    // Forward pipe: local → mptcp
    let (fwd_pr, fwd_pw) = create_pipe()?;
    // Backward pipe: mptcp → local
    let (bwd_pr, bwd_pw) = create_pipe()?;

    let done = Arc::new(AtomicBool::new(false));
    let done2 = Arc::clone(&done);

    // Forward thread: local TCP → MPTCP
    let fwd_handle = std::thread::spawn(move || -> u64 {
        let mut total: u64 = 0;
        loop {
            if done2.load(Ordering::Relaxed) {
                break;
            }
            match splice_once(local_fd, fwd_pw, fwd_pr, mptcp_fd, SPLICE_CHUNK) {
                Ok(0) => break, // EOF
                Ok(n) => total += n as u64,
                Err(e) if e.kind() == std::io::ErrorKind::WouldBlock => {
                    std::thread::sleep(std::time::Duration::from_micros(100));
                }
                Err(_) => break,
            }
        }
        done2.store(true, Ordering::Relaxed);
        close_fd(fwd_pr);
        close_fd(fwd_pw);
        total
    });

    // Backward: MPTCP → local TCP (in current thread)
    let mut total_back: u64 = 0;
    loop {
        if done.load(Ordering::Relaxed) {
            break;
        }
        match splice_once(mptcp_fd, bwd_pw, bwd_pr, local_fd, SPLICE_CHUNK) {
            Ok(0) => break,
            Ok(n) => total_back += n as u64,
            Err(e) if e.kind() == std::io::ErrorKind::WouldBlock => {
                std::thread::sleep(std::time::Duration::from_micros(100));
            }
            Err(_) => break,
        }
    }
    done.store(true, Ordering::Relaxed);
    close_fd(bwd_pr);
    close_fd(bwd_pw);

    let total_fwd = fwd_handle.join().unwrap_or(0);
    stats.bytes_out.fetch_add(total_fwd, Ordering::Relaxed);
    stats.bytes_in.fetch_add(total_back, Ordering::Relaxed);
    stats.active_connections.fetch_sub(1, Ordering::Relaxed);

    log::info!("connection done: fwd={total_fwd} bwd={total_back}");
    Ok(())
}
```

- [ ] **Step 2: Wire client into main.rs**

```rust
mod client;
mod config;
mod mptcp;
mod splice;
mod token;

use clap::{Parser, Subcommand};
use std::path::PathBuf;

#[derive(Parser)]
#[command(name = "leikwan-mptcp-relay")]
struct Cli {
    #[command(subcommand)]
    command: Commands,
}

#[derive(Subcommand)]
enum Commands {
    Client { #[arg(long)] config: PathBuf },
    Server { #[arg(long)] config: PathBuf },
}

fn main() -> anyhow::Result<()> {
    env_logger::init();
    let cli = Cli::parse();
    match cli.command {
        Commands::Client { config } => {
            let cfg = config::load_client_config(&config)?;
            client::run_client(cfg)
        }
        Commands::Server { config } => {
            let cfg = config::load_server_config(&config)?;
            log::info!("server mode: listen={}:{}", cfg.listen_host, cfg.listen_port);
            // TODO: implement server mode in Task 6
            Ok(())
        }
    }
}
```

- [ ] **Step 3: Build**

```bash
cd wstunnel-main && cargo build -p leikwan-mptcp-relay
```

- [ ] **Step 4: Commit**

```bash
git add wstunnel-main/leikwan-mptcp-relay/src/
git commit -m "feat: implement client relay loop with splice(2) forwarding"
```

---

### Task 6: Implement the server relay loop

**Files:**
- Create: `wstunnel-main/leikwan-mptcp-relay/src/server.rs`
- Modify: `wstunnel-main/leikwan-mptcp-relay/src/main.rs`

- [ ] **Step 1: Create server.rs**

The server side listens on MPTCP, authenticates via session token, and relays to the target backend via TCP + splice.

```rust
use crate::config::ServerConfig;
use crate::mptcp::{mptcp_listen, SocketConfig};
use crate::splice::{close_fd, create_pipe, splice_once};
use crate::token::verify_token;

use std::io::{Read, Write};
use std::net::{SocketAddr, TcpStream};
use std::os::unix::io::AsRawFd;
use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::Arc;

const SPLICE_CHUNK: usize = 1048576;
const TOKEN_HEADER_LEN: usize = 2; // u16 big-endian length prefix

pub fn run_server(config: ServerConfig) -> anyhow::Result<()> {
    let listen_addr: SocketAddr = format!("{}:{}", config.listen_host, config.listen_port).parse()?;
    let socket_config = SocketConfig {
        congestion_control: config.tcp_congestion_control.clone(),
        buffer_bytes: config.socket_buffer_bytes,
        allow_tcp_fallback: config.allow_tcp_fallback,
    };
    let listener = mptcp_listen(listen_addr, &socket_config)?;
    log::info!("server listening on {listen_addr} (MPTCP)");

    let secret = Arc::new(config.token_secret.clone());

    for incoming in listener.incoming() {
        let stream = match incoming {
            Ok(s) => s,
            Err(e) => {
                log::warn!("accept error: {e}");
                continue;
            }
        };
        let secret = Arc::clone(&secret);
        let sc = SocketConfig {
            congestion_control: config.tcp_congestion_control.clone(),
            buffer_bytes: config.socket_buffer_bytes,
            allow_tcp_fallback: false,
        };
        std::thread::spawn(move || {
            if let Err(e) = handle_server_connection(stream, &secret, &sc) {
                log::error!("server connection error: {e}");
            }
        });
    }
    Ok(())
}

fn handle_server_connection(
    mut mptcp_stream: TcpStream,
    secret: &str,
    socket_config: &SocketConfig,
) -> anyhow::Result<()> {
    // Read session token: 2-byte length prefix + token bytes
    let mut len_buf = [0u8; TOKEN_HEADER_LEN];
    mptcp_stream.read_exact(&mut len_buf)?;
    let token_len = u16::from_be_bytes(len_buf) as usize;
    if token_len > 8192 {
        anyhow::bail!("token too large: {token_len}");
    }
    let mut token_buf = vec![0u8; token_len];
    mptcp_stream.read_exact(&mut token_buf)?;
    let token_str = std::str::from_utf8(&token_buf)?;

    let payload = verify_token(token_str, secret)?;
    log::info!("authenticated: forward_id={}, target={}:{}", payload.forward_id, payload.target_host, payload.target_port);

    // Connect to target backend
    let target_addr: SocketAddr = format!("{}:{}", payload.target_host, payload.target_port).parse()?;
    let target_stream = TcpStream::connect(target_addr)?;
    // Tune target socket
    let target_fd = target_stream.as_raw_fd();
    let mptcp_fd = mptcp_stream.as_raw_fd();

    // Splice forwarding
    let (fwd_pr, fwd_pw) = create_pipe()?;
    let (bwd_pr, bwd_pw) = create_pipe()?;
    let done = Arc::new(AtomicBool::new(false));
    let done2 = Arc::clone(&done);

    // Forward: MPTCP → target
    let fwd_handle = std::thread::spawn(move || -> u64 {
        let mut total: u64 = 0;
        loop {
            if done2.load(Ordering::Relaxed) { break; }
            match splice_once(mptcp_fd, fwd_pw, fwd_pr, target_fd, SPLICE_CHUNK) {
                Ok(0) => break,
                Ok(n) => total += n as u64,
                Err(e) if e.kind() == std::io::ErrorKind::WouldBlock => {
                    std::thread::sleep(std::time::Duration::from_micros(100));
                }
                Err(_) => break,
            }
        }
        done2.store(true, Ordering::Relaxed);
        close_fd(fwd_pr);
        close_fd(fwd_pw);
        total
    });

    // Backward: target → MPTCP
    let mut total_back: u64 = 0;
    loop {
        if done.load(Ordering::Relaxed) { break; }
        match splice_once(target_fd, bwd_pw, bwd_pr, mptcp_fd, SPLICE_CHUNK) {
            Ok(0) => break,
            Ok(n) => total_back += n as u64,
            Err(e) if e.kind() == std::io::ErrorKind::WouldBlock => {
                std::thread::sleep(std::time::Duration::from_micros(100));
            }
            Err(_) => break,
        }
    }
    done.store(true, Ordering::Relaxed);
    close_fd(bwd_pr);
    close_fd(bwd_pw);

    let total_fwd = fwd_handle.join().unwrap_or(0);
    log::info!("server connection done: in={total_fwd} out={total_back}");
    Ok(())
}
```

Note: The token exchange protocol (2-byte length prefix + token) must match what the client sends. The client's `handle_connection` in Task 5 needs to be updated to send the token after MPTCP connect, before splice begins. This is addressed in Task 7.

- [ ] **Step 2: Wire server into main.rs**

Update the Server match arm:
```rust
Commands::Server { config } => {
    let cfg = config::load_server_config(&config)?;
    server::run_server(cfg)
}
```

Add `mod server;` to main.rs.

- [ ] **Step 3: Build**

```bash
cd wstunnel-main && cargo build -p leikwan-mptcp-relay
```

- [ ] **Step 4: Commit**

```bash
git add wstunnel-main/leikwan-mptcp-relay/src/
git commit -m "feat: implement server relay loop with token auth and splice forwarding"
```

---

### Task 7: Add session token exchange to client

**Files:**
- Modify: `wstunnel-main/leikwan-mptcp-relay/src/client.rs`

- [ ] **Step 1: Send session token after MPTCP connect**

In `client.rs`, in `handle_connection()`, after `mptcp_connect()` succeeds and before starting the splice loop, send the session token:

```rust
fn handle_connection(
    local: TcpStream,
    remote_addr: SocketAddr,
    socket_config: &SocketConfig,
    stats: &Stats,
    session_token: &str,
) -> anyhow::Result<()> {
    stats.active_connections.fetch_add(1, Ordering::Relaxed);

    let mut mptcp = mptcp_connect(remote_addr, socket_config)?;

    // Send session token: 2-byte BE length + token bytes
    let token_bytes = session_token.as_bytes();
    let len_bytes = (token_bytes.len() as u16).to_be_bytes();
    use std::io::Write;
    mptcp.write_all(&len_bytes)?;
    mptcp.write_all(token_bytes)?;
    mptcp.flush()?;

    // ... rest of splice loop unchanged
```

Update the `run_client()` function to pass `&config.tcp_session_token` to `handle_connection()`.

- [ ] **Step 2: Build and test locally**

```bash
cd wstunnel-main && cargo build -p leikwan-mptcp-relay
```

- [ ] **Step 3: Commit**

```bash
git add wstunnel-main/leikwan-mptcp-relay/src/client.rs
git commit -m "feat: send session token after MPTCP connect in client relay"
```

---

### Task 8: Add MPTCP endpoint management

**Files:**
- Create: `wstunnel-main/leikwan-mptcp-relay/src/endpoint.rs`

- [ ] **Step 1: Create endpoint.rs**

Port the MPTCP endpoint management logic from Python (`configure_mptcp_policy` in leikwan_mptcp_client.py). Uses `ip mptcp` CLI commands.

```rust
use std::process::Command;

pub struct MptcpEndpointConfig {
    pub subflow_limit: u32,
    pub device: String,
    pub id_base: u32,
    pub endpoints: Vec<EndpointEntry>,
}

pub struct EndpointEntry {
    pub address: String,
    pub flags: Vec<String>, // "subflow", "signal", "backup", "fullmesh"
    pub device: String,
    pub id: u32,
}

pub fn configure_mptcp_endpoints(config: &MptcpEndpointConfig) -> anyhow::Result<()> {
    // Set limits
    let limit_str = config.subflow_limit.to_string();
    run_ip(&["mptcp", "limits", "set", "subflow", &limit_str, "add_addr_accepted", &limit_str]);

    // Get current endpoints
    let current = get_current_endpoints()?;

    // Remove stale endpoints with our id_base range
    let id_min = config.id_base;
    let id_max = config.id_base + 9;
    for ep in &current {
        if ep.id >= id_min && ep.id <= id_max {
            let id_str = ep.id.to_string();
            run_ip(&["mptcp", "endpoint", "delete", "id", &id_str]);
        }
    }

    // Add desired endpoints
    for ep in &config.endpoints {
        let mut args = vec!["mptcp", "endpoint", "add", &ep.address];
        if !ep.device.is_empty() {
            args.extend(&["dev", &ep.device]);
        }
        for flag in &ep.flags {
            args.push(flag);
        }
        let id_str = ep.id.to_string();
        args.extend(&["id", &id_str]);
        run_ip(&args);
    }

    Ok(())
}

struct CurrentEndpoint {
    id: u32,
    address: String,
}

fn get_current_endpoints() -> anyhow::Result<Vec<CurrentEndpoint>> {
    let output = Command::new("ip").args(["mptcp", "endpoint", "show"]).output()?;
    let stdout = String::from_utf8_lossy(&output.stdout);
    let mut endpoints = Vec::new();
    for line in stdout.lines() {
        // Parse: "10.203.1.1 id 10 subflow dev lw-mptcp-20"
        let parts: Vec<&str> = line.split_whitespace().collect();
        if parts.len() >= 3 {
            let address = parts[0].to_string();
            let id_idx = parts.iter().position(|&p| p == "id");
            if let Some(idx) = id_idx {
                if let Ok(id) = parts.get(idx + 1).unwrap_or(&"0").parse::<u32>() {
                    endpoints.push(CurrentEndpoint { id, address });
                }
            }
        }
    }
    Ok(endpoints)
}

fn run_ip(args: &[&str]) {
    let status = Command::new("ip").args(args).status();
    match status {
        Ok(s) if !s.success() => log::warn!("ip {:?} exited with {s}", args),
        Err(e) => log::warn!("ip {:?} failed: {e}", args),
        _ => {}
    }
}
```

- [ ] **Step 2: Integrate into client startup**

In `client.rs`, call `endpoint::configure_mptcp_endpoints()` before starting the listener, if `config.manage_mptcp_endpoints` is true.

- [ ] **Step 3: Build and commit**

```bash
cd wstunnel-main && cargo build -p leikwan-mptcp-relay
git add wstunnel-main/leikwan-mptcp-relay/src/
git commit -m "feat: add MPTCP endpoint management via ip mptcp CLI"
```

---

### Task 9: Integrate with client-agent deployment

**Files:**
- Modify: `client-agent/install_forward_runtime.py` (mptcp mode)
- Modify: `artifacts/versions.json`

- [ ] **Step 1: Update install_forward_runtime.py to launch Rust relay**

In the mptcp mode section of `install_forward_runtime.py`, after writing the config JSON file, change the process launch from:

```python
f"python3 /var/lib/leikwan-client-agent/leikwan_mptcp_client.py --config {config_path} &"
```

to:

```python
f"/var/lib/leikwan-client-agent/leikwan-mptcp-relay client --config {config_path} &"
```

Keep the existing config JSON writing logic — the Rust binary reads the same JSON format.

- [ ] **Step 2: Add mptcp-relay to versions.json**

Add a new entry for the `leikwan-mptcp-relay` artifact:
```json
"mptcp-relay": {
    "version": "0.1.0",
    "file": "leikwan-mptcp-relay",
    "platform": "linux-amd64"
}
```

- [ ] **Step 3: Update sidecar_agent.py similarly**

Change the sidecar runtime launch to use `leikwan-mptcp-relay server --config ...` instead of the Python sidecar runtime.

- [ ] **Step 4: Build release binary and upload**

```bash
cd wstunnel-main && cargo build --release -p leikwan-mptcp-relay
# Upload to panel artifact server
```

- [ ] **Step 5: Commit**

```bash
git add client-agent/install_forward_runtime.py sidecar-agent/sidecar_agent.py artifacts/versions.json
git commit -m "feat: integrate Rust MPTCP relay into agent deployment pipeline"
```

---

### Task 10: End-to-end testing

**Files:** None (testing only)

- [ ] **Step 1: Deploy to test environment**

Upload the Rust relay binary and updated agents to the test client/sidecar.

- [ ] **Step 2: iperf3 TCP reverse test**

```bash
iperf3 -c 127.0.0.1 -p 11009 -R -t 10
```

Target: 500Mbps+ sustained average.

- [ ] **Step 3: Verify splice syscalls**

```bash
strace -e trace=splice -p $(pgrep leikwan-mptcp-relay) -c
```

Verify splice calls dominate (not read/write).

- [ ] **Step 4: Verify MPTCP subflows**

```bash
ss -M | grep $(pgrep leikwan-mptcp-relay)
```

All 5 subflows should be active.

- [ ] **Step 5: iperf3 TCP forward test**

```bash
iperf3 -c 127.0.0.1 -p 11009 -t 10
```

Verify forward direction performance is also improved.

- [ ] **Step 6: UDP stream_compat test**

Test UDP forwarding if applicable.

- [ ] **Step 7: Half-close / idle timeout test**

Verify connections close cleanly when one side disconnects.
