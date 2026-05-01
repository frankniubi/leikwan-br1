use std::collections::HashMap;
use std::path::Path;

use anyhow::{Context, Result, anyhow, bail};
use serde::Deserialize;
use tokio::io::{AsyncReadExt, AsyncWriteExt, copy_bidirectional};
use tokio::net::{TcpListener, TcpStream};
use tokio::task::JoinSet;
use tracing::{info, warn};

use crate::protocol::{Command, MAX_LINE_BYTES, OK_LINE, ParsedClientLine, parse_client_line};

#[derive(Debug, Clone, Deserialize)]
struct RouteEntry {
    route_key: Option<String>,
    path_prefix: Option<String>,
    backend_host: String,
    backend_port: u16,
    service_name: Option<String>,
    ingress_protocol: Option<String>,
    ingress_auth_token: Option<String>,
}

#[derive(Debug, Deserialize)]
struct RoutesFile {
    routes: Vec<RouteEntry>,
}

fn read_routes(routes_file: &Path) -> Result<HashMap<String, RouteEntry>> {
    let raw = std::fs::read_to_string(routes_file)
        .with_context(|| format!("failed to read routes file {}", routes_file.display()))?;
    let payload: RoutesFile = serde_json::from_str(&raw)
        .with_context(|| format!("failed to parse routes file {}", routes_file.display()))?;

    let mut routes = HashMap::new();
    for entry in payload.routes {
        let ingress_protocol = entry
            .ingress_protocol
            .clone()
            .unwrap_or_else(|| "wss_path_mux".to_string());
        if ingress_protocol != "plain_tcp_mux" {
            continue;
        }
        let route_key = entry
            .route_key
            .clone()
            .or_else(|| entry.path_prefix.clone())
            .unwrap_or_default()
            .trim_matches('/')
            .to_string();
        if route_key.is_empty() || entry.backend_port == 0 {
            continue;
        }
        routes.insert(route_key, entry);
    }
    Ok(routes)
}

async fn read_line(stream: &mut TcpStream) -> Result<String> {
    let mut buf = Vec::with_capacity(64);
    loop {
        let mut byte = [0u8; 1];
        let read = stream
            .read(&mut byte)
            .await
            .context("failed to read request line")?;
        if read == 0 {
            break;
        }
        buf.push(byte[0]);
        if byte[0] == b'\n' {
            break;
        }
        if buf.len() >= MAX_LINE_BYTES {
            bail!("request line too large");
        }
    }
    if buf.is_empty() {
        bail!("empty request line");
    }
    String::from_utf8(buf).map_err(|_| anyhow!("request line is not valid utf-8"))
}

fn resolve_route<'a>(routes: &'a HashMap<String, RouteEntry>, request: &ParsedClientLine) -> Result<&'a RouteEntry> {
    let route = routes
        .get(&request.route_key)
        .ok_or_else(|| anyhow!("route_not_found"))?;
    let expected = route
        .ingress_auth_token
        .as_deref()
        .unwrap_or("")
        .trim();
    if expected.is_empty() {
        bail!("route_auth_missing");
    }
    if expected != request.auth_token {
        bail!("route_auth_invalid");
    }
    Ok(route)
}

async fn handle_connection(mut inbound: TcpStream, peer: std::net::SocketAddr, routes_file: &Path) -> Result<()> {
    let line = read_line(&mut inbound).await?;
    let parsed = parse_client_line(&line)?;
    let routes = read_routes(routes_file)?;
    let route = resolve_route(&routes, &parsed)?;
    let backend_target = format!("{}:{}", route.backend_host, route.backend_port);

    match parsed.command {
        Command::Ping => {
            inbound
                .write_all(OK_LINE.as_bytes())
                .await
                .context("failed to write ping response")?;
            inbound.flush().await.context("failed to flush ping response")?;
            info!(
                "plain tcp mux probe ok route={} service={} peer={peer}",
                parsed.route_key,
                route.service_name.clone().unwrap_or_default()
            );
            Ok(())
        }
        Command::Open => {
            let mut outbound = TcpStream::connect(&backend_target)
                .await
                .with_context(|| format!("failed to connect backend {backend_target}"))?;
            inbound
                .write_all(OK_LINE.as_bytes())
                .await
                .context("failed to write open response")?;
            inbound.flush().await.context("failed to flush open response")?;
            info!(
                "plain tcp mux open route={} service={} backend={} peer={peer}",
                parsed.route_key,
                route.service_name.clone().unwrap_or_default(),
                backend_target
            );
            let _ = copy_bidirectional(&mut inbound, &mut outbound)
                .await
                .context("plain tcp mux relay failed")?;
            Ok(())
        }
    }
}

async fn serve_listener(listener: TcpListener, routes_file: std::path::PathBuf) -> Result<()> {
    loop {
        let (inbound, peer) = listener
            .accept()
            .await
            .context("failed to accept mux connection")?;
        let routes_file = routes_file.clone();
        tokio::spawn(async move {
            if let Err(err) = handle_connection(inbound, peer, &routes_file).await {
                warn!("mux connection from {peer} failed: {err:#}");
            }
        });
    }
}

pub async fn run_mux(listens: &[String], routes_file: &Path) -> Result<()> {
    let mut join_set = JoinSet::new();
    let mut bound_count = 0usize;

    for listen in listens {
        match TcpListener::bind(listen).await {
            Ok(listener) => {
                info!(
                    "leikwan-ingress mux listening on {}, routes {}",
                    listen,
                    routes_file.display()
                );
                bound_count += 1;
                let routes_file = routes_file.to_path_buf();
                join_set.spawn(async move { serve_listener(listener, routes_file).await });
            }
            Err(err) if bound_count > 0 => {
                warn!("failed to also bind mux listener {}: {err:#}", listen);
            }
            Err(err) => {
                warn!("failed to bind mux listener {}: {err:#}", listen);
            }
        }
    }

    if bound_count == 0 {
        bail!("failed to bind any mux listener");
    }

    while let Some(result) = join_set.join_next().await {
        match result {
            Ok(Ok(())) => {}
            Ok(Err(err)) => return Err(err),
            Err(err) => return Err(anyhow!("mux listener task failed: {err}")),
        }
    }

    bail!("mux listener tasks exited unexpectedly");
}
