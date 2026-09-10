/// MPTCP socket creation and tuning.
///
/// Uses IPPROTO_MPTCP (262) with libc to create sockets that the
/// standard library doesn't support directly.
use std::io;
use std::net::SocketAddr;

/// Socket tuning parameters.
#[derive(Debug, Clone)]
#[allow(dead_code)]
pub struct SocketConfig {
    pub congestion_control: String,
    pub buffer_bytes: usize,
    pub allow_tcp_fallback: bool,
}

// ---------- Linux implementation ----------

#[cfg(target_os = "linux")]
mod platform {
    use super::*;
    use std::net::{TcpListener, TcpStream};
    use std::os::unix::io::FromRawFd;

    /// IPPROTO_MPTCP — not yet in libc crate constants.
    const IPPROTO_MPTCP: libc::c_int = 262;

    /// Create an MPTCP socket, tune it, and connect to `addr`.
    /// Falls back to TCP if `config.allow_tcp_fallback` is true and
    /// MPTCP socket creation fails.
    pub fn mptcp_connect(addr: SocketAddr, config: &SocketConfig) -> io::Result<TcpStream> {
        let (domain, sa, sa_len) = sockaddr_from(addr);

        let fd = unsafe { libc::socket(domain, libc::SOCK_STREAM, IPPROTO_MPTCP) };
        if fd < 0 {
            let err = io::Error::last_os_error();
            if config.allow_tcp_fallback {
                log::warn!(
                    "MPTCP socket creation failed ({}), falling back to TCP",
                    err
                );
                return tcp_connect_fallback(addr, config);
            }
            return Err(err);
        }

        tune_fd(fd, config);

        let ret = unsafe { libc::connect(fd, &sa as *const libc::sockaddr_storage as *const libc::sockaddr, sa_len) };
        if ret < 0 {
            let err = io::Error::last_os_error();
            unsafe { libc::close(fd); }
            if config.allow_tcp_fallback {
                log::warn!("MPTCP connect failed ({}), falling back to TCP", err);
                return tcp_connect_fallback(addr, config);
            }
            return Err(err);
        }

        Ok(unsafe { TcpStream::from_raw_fd(fd) })
    }

    /// Create an MPTCP server socket, tune it, bind and listen on `addr`.
    pub fn mptcp_listen(addr: SocketAddr, config: &SocketConfig) -> io::Result<TcpListener> {
        let (domain, sa, sa_len) = sockaddr_from(addr);

        let fd = unsafe { libc::socket(domain, libc::SOCK_STREAM, IPPROTO_MPTCP) };
        if fd < 0 {
            let err = io::Error::last_os_error();
            if config.allow_tcp_fallback {
                log::warn!(
                    "MPTCP socket creation failed ({}), falling back to TCP listener",
                    err
                );
                return tcp_listen_fallback(addr, config);
            }
            return Err(err);
        }

        // SO_REUSEADDR
        let one: libc::c_int = 1;
        unsafe {
            libc::setsockopt(
                fd,
                libc::SOL_SOCKET,
                libc::SO_REUSEADDR,
                &one as *const _ as *const libc::c_void,
                std::mem::size_of::<libc::c_int>() as libc::socklen_t,
            );
        }

        tune_fd(fd, config);

        let ret = unsafe { libc::bind(fd, &sa as *const libc::sockaddr_storage as *const libc::sockaddr, sa_len) };
        if ret < 0 {
            let err = io::Error::last_os_error();
            unsafe { libc::close(fd); }
            return Err(err);
        }

        let ret = unsafe { libc::listen(fd, 128) };
        if ret < 0 {
            let err = io::Error::last_os_error();
            unsafe { libc::close(fd); }
            return Err(err);
        }

        Ok(unsafe { TcpListener::from_raw_fd(fd) })
    }

    /// Tune socket options on a raw fd.
    pub fn tune_fd(fd: libc::c_int, config: &SocketConfig) {
        let buf = config.buffer_bytes as libc::c_int;

        unsafe {
            // Send buffer
            libc::setsockopt(
                fd,
                libc::SOL_SOCKET,
                libc::SO_SNDBUF,
                &buf as *const _ as *const libc::c_void,
                std::mem::size_of::<libc::c_int>() as libc::socklen_t,
            );
            // Receive buffer
            libc::setsockopt(
                fd,
                libc::SOL_SOCKET,
                libc::SO_RCVBUF,
                &buf as *const _ as *const libc::c_void,
                std::mem::size_of::<libc::c_int>() as libc::socklen_t,
            );
            // TCP_NODELAY
            let one: libc::c_int = 1;
            libc::setsockopt(
                fd,
                libc::IPPROTO_TCP,
                libc::TCP_NODELAY,
                &one as *const _ as *const libc::c_void,
                std::mem::size_of::<libc::c_int>() as libc::socklen_t,
            );
            // TCP_CONGESTION
            if !config.congestion_control.is_empty() {
                let cc = config.congestion_control.as_bytes();
                libc::setsockopt(
                    fd,
                    libc::IPPROTO_TCP,
                    libc::TCP_CONGESTION,
                    cc.as_ptr() as *const libc::c_void,
                    cc.len() as libc::socklen_t,
                );
            }
        }
    }

    /// Tune buffer sizes on a UDP socket fd (no TCP-specific options).
    pub fn tune_udp_fd(fd: libc::c_int, buffer_bytes: usize) {
        let buf = buffer_bytes as libc::c_int;
        unsafe {
            libc::setsockopt(
                fd,
                libc::SOL_SOCKET,
                libc::SO_SNDBUF,
                &buf as *const _ as *const libc::c_void,
                std::mem::size_of::<libc::c_int>() as libc::socklen_t,
            );
            libc::setsockopt(
                fd,
                libc::SOL_SOCKET,
                libc::SO_RCVBUF,
                &buf as *const _ as *const libc::c_void,
                std::mem::size_of::<libc::c_int>() as libc::socklen_t,
            );
        }
    }

    /// Create an MPTCP socket, bind to `local_addr`, tune it, and connect to
    /// `remote_addr`. Used for bond mode where each leg must go via a specific
    /// WG interface.
    pub fn mptcp_connect_bound(
        local_addr: SocketAddr,
        remote_addr: SocketAddr,
        config: &SocketConfig,
    ) -> io::Result<TcpStream> {
        let (domain, remote_sa, remote_sa_len) = sockaddr_from(remote_addr);
        let (_, local_sa, local_sa_len) = sockaddr_from(local_addr);

        let fd = unsafe { libc::socket(domain, libc::SOCK_STREAM, IPPROTO_MPTCP) };
        if fd < 0 {
            let err = io::Error::last_os_error();
            if config.allow_tcp_fallback {
                log::warn!(
                    "MPTCP socket creation failed ({}), falling back to TCP (bound)",
                    err
                );
                return tcp_connect_bound_fallback(local_addr, remote_addr, config);
            }
            return Err(err);
        }

        tune_fd(fd, config);

        // Bind to the specific local WG address.
        let ret = unsafe {
            libc::bind(
                fd,
                &local_sa as *const libc::sockaddr_storage as *const libc::sockaddr,
                local_sa_len,
            )
        };
        if ret < 0 {
            let err = io::Error::last_os_error();
            unsafe { libc::close(fd); }
            return Err(err);
        }

        let ret = unsafe {
            libc::connect(
                fd,
                &remote_sa as *const libc::sockaddr_storage as *const libc::sockaddr,
                remote_sa_len,
            )
        };
        if ret < 0 {
            let err = io::Error::last_os_error();
            unsafe { libc::close(fd); }
            if config.allow_tcp_fallback {
                log::warn!("MPTCP connect (bound) failed ({}), falling back to TCP", err);
                return tcp_connect_bound_fallback(local_addr, remote_addr, config);
            }
            return Err(err);
        }

        Ok(unsafe { TcpStream::from_raw_fd(fd) })
    }

    // ---- helpers ----

    /// Build a libc sockaddr_storage from a std SocketAddr.
    fn sockaddr_from(addr: SocketAddr) -> (libc::c_int, libc::sockaddr_storage, libc::socklen_t) {
        let mut storage: libc::sockaddr_storage = unsafe { std::mem::zeroed() };
        let len: libc::socklen_t;
        let domain: libc::c_int;

        match addr {
            SocketAddr::V4(v4) => {
                domain = libc::AF_INET;
                let sin: &mut libc::sockaddr_in =
                    unsafe { &mut *(&mut storage as *mut libc::sockaddr_storage as *mut libc::sockaddr_in) };
                sin.sin_family = libc::AF_INET as libc::sa_family_t;
                sin.sin_port = v4.port().to_be();
                sin.sin_addr = libc::in_addr {
                    s_addr: u32::from_ne_bytes(v4.ip().octets()),
                };
                len = std::mem::size_of::<libc::sockaddr_in>() as libc::socklen_t;
            }
            SocketAddr::V6(v6) => {
                domain = libc::AF_INET6;
                let sin6: &mut libc::sockaddr_in6 =
                    unsafe { &mut *(&mut storage as *mut libc::sockaddr_storage as *mut libc::sockaddr_in6) };
                sin6.sin6_family = libc::AF_INET6 as libc::sa_family_t;
                sin6.sin6_port = v6.port().to_be();
                sin6.sin6_addr = libc::in6_addr {
                    s6_addr: v6.ip().octets(),
                };
                sin6.sin6_flowinfo = v6.flowinfo();
                sin6.sin6_scope_id = v6.scope_id();
                len = std::mem::size_of::<libc::sockaddr_in6>() as libc::socklen_t;
            }
        }

        (domain, storage, len)
    }

    fn tcp_connect_fallback(addr: SocketAddr, config: &SocketConfig) -> io::Result<TcpStream> {
        let stream = TcpStream::connect(addr)?;
        use std::os::unix::io::AsRawFd;
        tune_fd(stream.as_raw_fd(), config);
        Ok(stream)
    }

    fn tcp_listen_fallback(addr: SocketAddr, config: &SocketConfig) -> io::Result<TcpListener> {
        let listener = TcpListener::bind(addr)?;
        use std::os::unix::io::AsRawFd;
        tune_fd(listener.as_raw_fd(), config);
        Ok(listener)
    }

    fn tcp_connect_bound_fallback(
        local_addr: SocketAddr,
        remote_addr: SocketAddr,
        config: &SocketConfig,
    ) -> io::Result<TcpStream> {
        use std::os::unix::io::FromRawFd;
        let (domain, remote_sa, remote_sa_len) = sockaddr_from(remote_addr);
        let (_, local_sa, local_sa_len) = sockaddr_from(local_addr);

        let fd = unsafe { libc::socket(domain, libc::SOCK_STREAM, 0) };
        if fd < 0 {
            return Err(io::Error::last_os_error());
        }

        tune_fd(fd, config);

        let ret = unsafe {
            libc::bind(fd, &local_sa as *const _ as *const libc::sockaddr, local_sa_len)
        };
        if ret < 0 {
            let err = io::Error::last_os_error();
            unsafe { libc::close(fd); }
            return Err(err);
        }

        let ret = unsafe {
            libc::connect(fd, &remote_sa as *const _ as *const libc::sockaddr, remote_sa_len)
        };
        if ret < 0 {
            let err = io::Error::last_os_error();
            unsafe { libc::close(fd); }
            return Err(err);
        }

        Ok(unsafe { TcpStream::from_raw_fd(fd) })
    }
}

// ---------- Non-Linux stubs ----------

#[cfg(not(target_os = "linux"))]
mod platform {
    use super::*;
    use std::net::{TcpListener, TcpStream};

    pub fn mptcp_connect(addr: SocketAddr, _config: &SocketConfig) -> io::Result<TcpStream> {
        // On non-Linux, fall back to plain TCP for compilation purposes.
        log::warn!("MPTCP not available on this platform, using plain TCP");
        TcpStream::connect(addr)
    }

    pub fn mptcp_listen(addr: SocketAddr, _config: &SocketConfig) -> io::Result<TcpListener> {
        log::warn!("MPTCP not available on this platform, using plain TCP listener");
        TcpListener::bind(addr)
    }

    pub fn mptcp_connect_bound(
        _local_addr: SocketAddr,
        remote_addr: SocketAddr,
        _config: &SocketConfig,
    ) -> io::Result<TcpStream> {
        log::warn!("MPTCP not available on this platform, using plain TCP (bound ignored)");
        TcpStream::connect(remote_addr)
    }

    #[allow(dead_code)]
    pub fn tune_fd(_fd: i32, _config: &SocketConfig) {}

    #[allow(dead_code)]
    pub fn tune_udp_fd(_fd: i32, _buffer_bytes: usize) {}
}

// Re-export platform implementations at the module level.
pub use platform::{mptcp_connect, mptcp_connect_bound, mptcp_listen};
#[allow(unused_imports)]
pub use platform::tune_fd;
#[allow(unused_imports)]
pub use platform::tune_udp_fd;
