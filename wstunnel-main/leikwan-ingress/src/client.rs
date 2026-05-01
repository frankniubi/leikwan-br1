use anyhow::{Context, Result, anyhow, bail};
use tokio::io::{AsyncReadExt, AsyncWriteExt, copy_bidirectional};
use tokio::net::{TcpListener, TcpStream};
use tracing::{error, info, warn};

use crate::protocol::{Command, MAX_LINE_BYTES, OK_LINE, build_client_line};

async fn read_line(stream: &mut TcpStream) -> Result<String> {
    let mut buf = Vec::with_capacity(64);
    loop {
        let mut byte = [0u8; 1];
        let read = stream
            .read(&mut byte)
            .await
            .context("failed to read response line")?;
        if read == 0 {
            break;
        }
        buf.push(byte[0]);
        if byte[0] == b'\n' {
            break;
        }
        if buf.len() >= MAX_LINE_BYTES {
            bail!("response line too large");
        }
    }

    if buf.is_empty() {
        bail!("empty response line");
    }

    String::from_utf8(buf).map_err(|_| anyhow!("response line is not valid utf-8"))
}

async fn proxy_one_connection(
    mut local_stream: TcpStream,
    remote: &str,
    route_key: &str,
    auth_token: &str,
) -> Result<()> {
    let mut remote_stream = TcpStream::connect(remote)
        .await
        .with_context(|| format!("failed to connect remote ingress {remote}"))?;

    let hello = build_client_line(Command::Open, route_key, auth_token);
    remote_stream
        .write_all(hello.as_bytes())
        .await
        .context("failed to send ingress open line")?;
    remote_stream
        .flush()
        .await
        .context("failed to flush ingress open line")?;

    let response = read_line(&mut remote_stream).await?;
    if response != OK_LINE {
        bail!("unexpected ingress open response: {}", response.trim());
    }

    let _ = copy_bidirectional(&mut local_stream, &mut remote_stream)
        .await
        .context("plain tcp ingress relay failed")?;
    Ok(())
}

pub async fn run_client(listen: &str, remote: &str, route_key: &str, auth_token: &str) -> Result<()> {
    let listener = TcpListener::bind(listen)
        .await
        .with_context(|| format!("failed to bind local ingress listener {listen}"))?;
    info!("leikwan-ingress client listening on {listen}, forwarding to {remote}");

    loop {
        let (local_stream, peer) = listener
            .accept()
            .await
            .context("failed to accept local ingress connection")?;
        let remote = remote.to_string();
        let route_key = route_key.to_string();
        let auth_token = auth_token.to_string();
        tokio::spawn(async move {
            if let Err(err) = proxy_one_connection(local_stream, &remote, &route_key, &auth_token).await {
                warn!("client ingress connection from {peer:?} failed: {err:#}");
            }
        });
    }
}

pub async fn run_probe(remote: &str, route_key: &str, auth_token: &str) -> Result<()> {
    let mut remote_stream = TcpStream::connect(remote)
        .await
        .with_context(|| format!("failed to connect remote ingress {remote}"))?;
    let ping = build_client_line(Command::Ping, route_key, auth_token);
    remote_stream
        .write_all(ping.as_bytes())
        .await
        .context("failed to send ingress ping")?;
    remote_stream
        .flush()
        .await
        .context("failed to flush ingress ping")?;
    let response = read_line(&mut remote_stream).await?;
    if response != OK_LINE {
        error!("unexpected probe response: {}", response.trim());
        bail!("unexpected ingress probe response: {}", response.trim());
    }
    Ok(())
}
