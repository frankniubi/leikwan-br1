use anyhow::{anyhow, bail, Context, Result};
use tokio::io::{copy_bidirectional, AsyncReadExt, AsyncWriteExt};
use tokio::net::{TcpListener, TcpStream};
use tracing::{error, info, warn};

use crate::protocol::{build_client_line, Command, MAX_LINE_BYTES, OK_LINE};

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
    let mut remote_stream = connect_authenticated(remote, route_key, auth_token).await?;

    let _ = copy_bidirectional(&mut local_stream, &mut remote_stream)
        .await
        .context("plain tcp ingress relay failed")?;
    Ok(())
}

async fn connect_command(
    remote: &str,
    command: Command,
    route_key: &str,
    auth_token: &str,
) -> Result<TcpStream> {
    if route_key.is_empty()
        || auth_token.is_empty()
        || route_key.chars().any(char::is_whitespace)
        || auth_token.chars().any(char::is_whitespace)
    {
        bail!("ingress route key and auth token must be non-empty single fields");
    }
    let request = build_client_line(command, route_key, auth_token);
    if request.len() >= MAX_LINE_BYTES {
        bail!("ingress request line too large");
    }

    let mut remote_stream = TcpStream::connect(remote)
        .await
        .with_context(|| format!("failed to connect remote ingress {remote}"))?;
    remote_stream
        .write_all(request.as_bytes())
        .await
        .context("failed to send ingress request line")?;
    remote_stream
        .flush()
        .await
        .context("failed to flush ingress request line")?;

    let response = read_line(&mut remote_stream).await?;
    if response != OK_LINE {
        bail!("unexpected ingress response: {}", response.trim());
    }
    Ok(remote_stream)
}

pub async fn connect_authenticated(
    remote: &str,
    route_key: &str,
    auth_token: &str,
) -> Result<TcpStream> {
    connect_command(remote, Command::Open, route_key, auth_token).await
}

pub async fn run_client(
    listen: &str,
    remote: &str,
    route_key: &str,
    auth_token: &str,
) -> Result<()> {
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
            if let Err(err) =
                proxy_one_connection(local_stream, &remote, &route_key, &auth_token).await
            {
                warn!("client ingress connection from {peer:?} failed: {err:#}");
            }
        });
    }
}

pub async fn run_probe(remote: &str, route_key: &str, auth_token: &str) -> Result<()> {
    if let Err(err) = connect_command(remote, Command::Ping, route_key, auth_token).await {
        error!("ingress probe failed: {err:#}");
        return Err(err);
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::connect_authenticated;
    use crate::protocol::OK_LINE;
    use tokio::io::{AsyncReadExt, AsyncWriteExt};
    use tokio::net::TcpListener;

    #[tokio::test]
    async fn authenticated_connect_returns_the_open_relay_stream() {
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let addr = listener.local_addr().unwrap();
        let server = tokio::spawn(async move {
            let (mut stream, _) = listener.accept().await.unwrap();
            let mut line = Vec::new();
            loop {
                let byte = stream.read_u8().await.unwrap();
                line.push(byte);
                if byte == b'\n' {
                    break;
                }
            }
            assert_eq!(
                "LEIKWAN-INGRESS 1 OPEN fixed opaque-token\n",
                String::from_utf8(line).unwrap()
            );
            stream.write_all(OK_LINE.as_bytes()).await.unwrap();
            stream.write_all(b"backend-ready").await.unwrap();
        });

        let mut stream = connect_authenticated(&addr.to_string(), "fixed", "opaque-token")
            .await
            .unwrap();
        let mut response = Vec::new();
        stream.read_to_end(&mut response).await.unwrap();
        assert_eq!(b"backend-ready", response.as_slice());
        server.await.unwrap();
    }
}
