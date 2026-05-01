/// Linux splice(2) based zero-copy forwarding.
///
/// On non-Linux platforms, stubs are provided so the crate compiles
/// (the binary itself only makes sense on Linux).
use std::io;

#[cfg(target_os = "linux")]
use std::os::unix::io::RawFd;

// ---------- Linux implementation ----------

#[cfg(target_os = "linux")]
pub fn create_pipe() -> io::Result<(RawFd, RawFd)> {
    let mut fds: [libc::c_int; 2] = [0; 2];
    let flags = libc::O_NONBLOCK | libc::O_CLOEXEC;
    let ret = unsafe { libc::pipe2(fds.as_mut_ptr(), flags) };
    if ret < 0 {
        return Err(io::Error::last_os_error());
    }

    // Try to enlarge pipe buffer to 4 MB for better throughput.
    let pipe_size: libc::c_int = 4 * 1024 * 1024;
    unsafe {
        let actual = libc::fcntl(fds[0], libc::F_SETPIPE_SZ, pipe_size);
        if actual < 0 {
            log::warn!("F_SETPIPE_SZ failed: {}, pipe stays at default size", io::Error::last_os_error());
        } else if actual < pipe_size {
            log::warn!("F_SETPIPE_SZ requested {} but got {}", pipe_size, actual);
        }
        libc::fcntl(fds[1], libc::F_SETPIPE_SZ, pipe_size);
    }

    Ok((fds[0], fds[1]))
}

#[cfg(target_os = "linux")]
pub fn close_fd(fd: RawFd) {
    unsafe {
        libc::close(fd);
    }
}

/// Maximum bytes per splice call. Matches the pipe buffer size for
/// optimal throughput — one syscall can fill/drain the entire pipe.
#[cfg(target_os = "linux")]
pub const SPLICE_MAX_BYTES: usize = 4 * 1024 * 1024; // 4 MB

/// Splice bytes from `src` fd through a pipe to `dst` fd.
///
/// Returns the number of bytes moved, or 0 on EOF.
/// The src fd MUST be in blocking mode — the src-to-pipe splice uses
/// only SPLICE_F_MOVE (no NONBLOCK) so it blocks until data arrives
/// and returns 0 only on true EOF.
#[cfg(target_os = "linux")]
pub fn splice_once(
    src: RawFd,
    pipe_wr: RawFd,
    pipe_rd: RawFd,
    dst: RawFd,
    max_bytes: usize,
) -> io::Result<usize> {
    // Step 1: splice from src into the write end of the pipe.
    // No SPLICE_F_NONBLOCK — the src fd is set to blocking by the caller,
    // so this blocks until data arrives and returns 0 only on true EOF.
    let n_in = loop {
        let ret = unsafe {
            libc::splice(
                src,
                std::ptr::null_mut(),
                pipe_wr,
                std::ptr::null_mut(),
                max_bytes,
                libc::SPLICE_F_MOVE as libc::c_uint,
            )
        };
        if ret < 0 {
            let err = io::Error::last_os_error();
            if err.kind() == io::ErrorKind::WouldBlock {
                // Should not happen with blocking fd, but retry defensively.
                std::thread::yield_now();
                continue;
            }
            return Err(err);
        }
        break ret;
    };

    if n_in == 0 {
        return Ok(0); // True EOF on src
    }

    // Step 2: splice from the read end of the pipe into dst.
    let mut remaining = n_in as usize;
    while remaining > 0 {
        let n_out = unsafe {
            libc::splice(
                pipe_rd,
                std::ptr::null_mut(),
                dst,
                std::ptr::null_mut(),
                remaining,
                (libc::SPLICE_F_MOVE | libc::SPLICE_F_MORE) as libc::c_uint,
            )
        };

        if n_out < 0 {
            let err = io::Error::last_os_error();
            if err.kind() == io::ErrorKind::WouldBlock {
                // Pipe has data but dst isn't ready — spin briefly.
                std::thread::yield_now();
                continue;
            }
            return Err(err);
        }
        if n_out == 0 {
            // dst closed
            return Ok((n_in as usize) - remaining);
        }
        remaining -= n_out as usize;
    }

    Ok(n_in as usize)
}

// ---------- Non-Linux stubs ----------

#[cfg(not(target_os = "linux"))]
#[allow(dead_code)]
pub const SPLICE_MAX_BYTES: usize = 4 * 1024 * 1024;

#[cfg(not(target_os = "linux"))]
#[allow(dead_code)]
pub fn create_pipe() -> io::Result<(i32, i32)> {
    Err(io::Error::new(
        io::ErrorKind::Unsupported,
        "splice is only supported on Linux",
    ))
}

#[cfg(not(target_os = "linux"))]
#[allow(dead_code)]
pub fn close_fd(_fd: i32) {}

#[cfg(not(target_os = "linux"))]
#[allow(dead_code)]
pub fn splice_once(
    _src: i32,
    _pipe_wr: i32,
    _pipe_rd: i32,
    _dst: i32,
    _max_bytes: usize,
) -> io::Result<usize> {
    Err(io::Error::new(
        io::ErrorKind::Unsupported,
        "splice is only supported on Linux",
    ))
}
