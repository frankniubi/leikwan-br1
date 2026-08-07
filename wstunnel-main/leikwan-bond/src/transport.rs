use anyhow::{Context, anyhow};
use quinn::{ClientConfig as QuinnClientConfig, Endpoint, ServerConfig as QuinnServerConfig, TransportConfig};
use rcgen::generate_simple_self_signed;
use rustls::client::danger::{HandshakeSignatureValid, ServerCertVerified, ServerCertVerifier};
use rustls::pki_types::{CertificateDer, PrivateKeyDer, PrivatePkcs8KeyDer, ServerName, UnixTime};
use rustls::{DigitallySignedStruct, Error as RustlsError, RootCertStore, SignatureScheme};
use std::collections::{HashMap, HashSet};
use std::fmt;
use std::net::{IpAddr, SocketAddr};
use std::pin::Pin;
use std::str::FromStr;
use std::sync::{Arc, Mutex as StdMutex, OnceLock};
use std::task::{Context as TaskContext, Poll};
use std::time::Duration;
use tokio::io::{AsyncRead, AsyncReadExt, AsyncWrite, AsyncWriteExt, ReadBuf};
use tokio::net::{TcpStream, lookup_host};
use tokio::sync::Mutex as AsyncMutex;
use tokio::time::timeout;
use log::debug;
use url::Url;

const QUIC_ALPN: &[u8] = b"leikwan-bond/1";
const QUIC_HANDSHAKE_TIMEOUT: Duration = Duration::from_secs(5);

static QUIC_CONNECTION_POOLS: OnceLock<StdMutex<HashMap<String, Arc<AsyncMutex<QuicConnectionPool>>>>> =
    OnceLock::new();

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum LegTransport {
    Tcp,
    Quic,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum LegRole {
    Primary,
    Helper,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum LegDial {
    Direct,
    Socks5 {
        line_id: u32,
        lease_generation: u64,
        connect_target: Option<SocketAddr>,
    },
    IngressTcp {
        route_key: String,
        auth_ref: String,
    },
}

#[derive(Clone)]
struct LegAuthToken(String);

impl fmt::Debug for LegAuthToken {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        formatter.write_str("[redacted]")
    }
}

impl LegTransport {
    pub fn default_priority(self) -> u16 {
        match self {
            Self::Quic => 0,
            Self::Tcp => 10,
        }
    }
}

#[derive(Clone)]
pub struct LegSpec {
    pub transport: LegTransport,
    pub dial: LegDial,
    pub host: String,
    pub port: u16,
    pub server_name: Option<String>,
    pub insecure: bool,
    pub priority: u16,
    pub role: LegRole,
    pub endpoint_id: Option<u64>,
    pub leg_route_id: Option<u32>,
    pub node_id: Option<u64>,
    pub display_host: Option<String>,
    pub original: String,
    ingress_auth_token: Option<LegAuthToken>,
}

impl fmt::Debug for LegSpec {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("LegSpec")
            .field("transport", &self.transport)
            .field("dial", &self.dial)
            .field("host", &self.host)
            .field("port", &self.port)
            .field("server_name", &self.server_name)
            .field("insecure", &self.insecure)
            .field("priority", &self.priority)
            .field("role", &self.role)
            .field("endpoint_id", &self.endpoint_id)
            .field("leg_route_id", &self.leg_route_id)
            .field("node_id", &self.node_id)
            .field("display_host", &self.display_host)
            .finish()
    }
}

impl LegSpec {
    pub(crate) fn with_socks_connect_target(mut self, target: SocketAddr) -> anyhow::Result<Self> {
        if target.ip().is_unspecified() || target.port() == 0 {
            return Err(anyhow!("SOCKS leg connect target is invalid"));
        }
        match &mut self.dial {
            LegDial::Socks5 { connect_target, .. } => *connect_target = Some(target),
            _ => return Err(anyhow!("connect target can only be assigned to a SOCKS leg")),
        }
        Ok(self)
    }

    pub(crate) fn with_ingress_auth_token(mut self, auth_token: &str) -> anyhow::Result<Self> {
        if auth_token.is_empty()
            || auth_token.len() > 2048
            || auth_token.chars().any(char::is_whitespace)
        {
            return Err(anyhow!("resolved ingress auth token is invalid"));
        }
        if !matches!(&self.dial, LegDial::IngressTcp { .. }) {
            return Err(anyhow!("ingress auth can only be assigned to an ingress leg"));
        }
        self.ingress_auth_token = Some(LegAuthToken(auth_token.to_string()));
        Ok(self)
    }
}

pub fn parse_leg_specs(raw_legs: Vec<String>) -> anyhow::Result<Vec<LegSpec>> {
    let specs = raw_legs
        .into_iter()
        .map(|raw| parse_leg_spec(&raw))
        .collect::<anyhow::Result<Vec<_>>>()?;
    let mut route_ids = HashSet::new();
    for spec in &specs {
        if let Some(route_id) = spec.leg_route_id {
            if !route_ids.insert(route_id) {
                return Err(anyhow!("duplicate bond leg route ID {route_id}"));
            }
        }
    }
    Ok(specs)
}

pub fn parse_leg_spec(raw: &str) -> anyhow::Result<LegSpec> {
    let trimmed = raw.trim();
    if trimmed.is_empty() {
        return Err(anyhow!("bond leg target cannot be empty"));
    }

    if !trimmed.contains("://") {
        let address = SocketAddr::from_str(trimmed)
            .with_context(|| format!("invalid tcp bond leg target {trimmed}"))?;
        return Ok(LegSpec {
            transport: LegTransport::Tcp,
            dial: LegDial::Direct,
            host: address.ip().to_string(),
            port: address.port(),
            server_name: None,
            insecure: false,
            priority: LegTransport::Tcp.default_priority(),
            role: LegRole::Primary,
            endpoint_id: None,
            leg_route_id: None,
            node_id: None,
            display_host: None,
            original: trimmed.to_string(),
            ingress_auth_token: None,
        });
    }

    let url = Url::parse(trimmed).with_context(|| format!("invalid bond leg URI {trimmed}"))?;
    if !url.username().is_empty() || url.password().is_some() {
        return Err(anyhow!("inline bond leg credentials are forbidden"));
    }
    let (transport, internal_scheme) = match url.scheme() {
        "tcp" => (LegTransport::Tcp, None),
        "quic" => (LegTransport::Quic, None),
        "socks5" => (LegTransport::Tcp, Some("socks5")),
        "ingress+tcp" => (LegTransport::Tcp, Some("ingress+tcp")),
        other => {
            return Err(anyhow!(
                "unsupported bond leg scheme {other}"
            ));
        }
    };
    if internal_scheme.is_some()
        && ((!url.path().is_empty() && url.path() != "/") || url.fragment().is_some())
    {
        return Err(anyhow!("internal bond leg URI cannot contain a path or fragment"));
    }
    let host = url
        .host_str()
        .ok_or_else(|| anyhow!("bond leg URI is missing host: {trimmed}"))?
        .to_string();
    let port = url
        .port()
        .ok_or_else(|| anyhow!("bond leg URI is missing port: {trimmed}"))?;
    let mut server_name = None;
    let mut insecure = false;
    let mut priority = transport.default_priority();
    let mut role = LegRole::Primary;
    let mut endpoint_id = None;
    let mut leg_route_id = None;
    let mut node_id = None;
    let mut display_host = None;
    let mut line_id = None;
    let mut lease_generation = None;
    let mut route_key = None;
    let mut auth_ref = None;
    let mut seen_internal_keys = HashSet::new();
    for (key, value) in url.query_pairs() {
        let normalized_key = key.to_ascii_lowercase();
        if forbidden_leg_query_key(&normalized_key) {
            return Err(anyhow!("bond leg URI contains a forbidden credential or target override"));
        }
        if let Some(scheme) = internal_scheme {
            if !seen_internal_keys.insert(normalized_key.clone()) {
                return Err(anyhow!("duplicate metadata in internal bond leg URI"));
            }
            if !allowed_internal_query_key(scheme, &normalized_key) {
                return Err(anyhow!("unsupported metadata in internal bond leg URI"));
            }
        }
        match normalized_key.as_str() {
            "server_name" | "sni" => {
                if !value.is_empty() {
                    server_name = Some(value.to_string());
                }
            }
            "insecure" => {
                let normalized = value.trim().to_ascii_lowercase();
                insecure = matches!(normalized.as_str(), "1" | "true" | "yes" | "on");
            }
            "priority" => {
                match value.parse::<u16>() {
                    Ok(parsed) => priority = parsed,
                    Err(_) if internal_scheme.is_some() => {
                        return Err(anyhow!("invalid priority in internal bond leg URI"));
                    }
                    Err(_) => {}
                }
            }
            "role" => {
                role = match value.as_ref() {
                    "helper" => LegRole::Helper,
                    "primary" => LegRole::Primary,
                    _ if internal_scheme.is_some() => {
                        return Err(anyhow!("invalid role in internal bond leg URI"));
                    }
                    _ => LegRole::Primary,
                };
            }
            "endpoint_id" => {
                match value.parse::<u64>().ok().filter(|candidate| *candidate > 0) {
                    Some(parsed) => endpoint_id = Some(parsed),
                    None if internal_scheme.is_some() => {
                        return Err(anyhow!("invalid endpoint_id in internal bond leg URI"));
                    }
                    None => {}
                }
            }
            "leg_route_id" => {
                let parsed = value
                    .parse::<u32>()
                    .ok()
                    .filter(|candidate| *candidate > 0)
                    .ok_or_else(|| anyhow!("invalid leg_route_id in bond leg URI: {trimmed}"))?;
                leg_route_id = Some(parsed);
            }
            "node_id" => {
                match value.parse::<u64>().ok().filter(|candidate| *candidate > 0) {
                    Some(parsed) => node_id = Some(parsed),
                    None if internal_scheme.is_some() => {
                        return Err(anyhow!("invalid node_id in internal bond leg URI"));
                    }
                    None => {}
                }
            }
            "display_host" | "host_label" => {
                if !value.is_empty() {
                    display_host = Some(value.to_string());
                }
            }
            "line_id" => {
                line_id = Some(
                    value
                        .parse::<u32>()
                        .ok()
                        .filter(|candidate| *candidate > 0)
                        .ok_or_else(|| anyhow!("invalid line_id in internal bond leg URI"))?,
                );
            }
            "lease_generation" => {
                lease_generation = Some(
                    value
                        .parse::<u64>()
                        .ok()
                        .filter(|candidate| *candidate > 0)
                        .ok_or_else(|| anyhow!("invalid lease_generation in internal bond leg URI"))?,
                );
            }
            "route_key" => {
                let value = value.to_string();
                if !valid_opaque_field(&value, 256) {
                    return Err(anyhow!("invalid route_key in internal bond leg URI"));
                }
                route_key = Some(value);
            }
            "auth_ref" => {
                let value = value.to_string();
                if !valid_auth_ref(&value) {
                    return Err(anyhow!("invalid auth_ref in internal bond leg URI"));
                }
                auth_ref = Some(value);
            }
            _ => {}
        }
    }

    let dial = match internal_scheme {
        Some("socks5") => {
            let proxy_ip = host
                .parse::<IpAddr>()
                .context("internal SOCKS host must be a loopback IP address")?;
            if !proxy_ip.is_loopback() {
                return Err(anyhow!("internal SOCKS host must be loopback"));
            }
            LegDial::Socks5 {
                line_id: line_id.ok_or_else(|| anyhow!("SOCKS leg is missing line_id"))?,
                lease_generation: lease_generation
                    .ok_or_else(|| anyhow!("SOCKS leg is missing lease_generation"))?,
                connect_target: None,
            }
        }
        Some("ingress+tcp") => {
            if !(7000..=9000).contains(&port) {
                return Err(anyhow!("authenticated ingress port must be in 7000-9000"));
            }
            LegDial::IngressTcp {
                route_key: route_key.ok_or_else(|| anyhow!("ingress leg is missing route_key"))?,
                auth_ref: auth_ref.ok_or_else(|| anyhow!("ingress leg is missing auth_ref"))?,
            }
        }
        _ => LegDial::Direct,
    };
    if internal_scheme.is_some() && leg_route_id.is_none() {
        return Err(anyhow!("internal bond leg is missing leg_route_id"));
    }
    if matches!(internal_scheme, Some("ingress+tcp")) && endpoint_id.is_none() {
        return Err(anyhow!("ingress leg is missing endpoint_id"));
    }

    Ok(LegSpec {
        transport,
        dial,
        host,
        port,
        server_name,
        insecure,
        priority,
        role,
        endpoint_id,
        leg_route_id,
        node_id,
        display_host,
        original: trimmed.to_string(),
        ingress_auth_token: None,
    })
}

fn forbidden_leg_query_key(key: &str) -> bool {
    matches!(
        key,
        "auth"
            | "auth_token"
            | "token"
            | "password"
            | "passwd"
            | "secret"
            | "user"
            | "username"
            | "target"
            | "target_host"
            | "target_port"
            | "connect_target"
            | "egress"
            | "upstream"
    )
}

fn allowed_internal_query_key(scheme: &str, key: &str) -> bool {
    let common = matches!(
        key,
        "leg_route_id" | "priority" | "role" | "node_id" | "display_host" | "host_label"
    );
    common
        || match scheme {
            "socks5" => matches!(key, "line_id" | "lease_generation"),
            "ingress+tcp" => matches!(key, "endpoint_id" | "route_key" | "auth_ref"),
            _ => false,
        }
}

fn valid_opaque_field(value: &str, max_len: usize) -> bool {
    !value.is_empty()
        && value.len() <= max_len
        && value.chars().all(|character| character.is_ascii_graphic())
}

fn valid_auth_ref(value: &str) -> bool {
    !value.is_empty()
        && value.len() <= 128
        && value
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'-' | b'_' | b'.' | b':'))
}

pub struct QuicBiStream {
    send: quinn::SendStream,
    recv: quinn::RecvStream,
    _connection: quinn::Connection,
    _endpoint: Option<Endpoint>,
}

impl QuicBiStream {
    fn new(
        connection: quinn::Connection,
        endpoint: Option<Endpoint>,
        send: quinn::SendStream,
        recv: quinn::RecvStream,
    ) -> Self {
        Self {
            send,
            recv,
            _connection: connection,
            _endpoint: endpoint,
        }
    }
}

impl AsyncRead for QuicBiStream {
    fn poll_read(
        mut self: Pin<&mut Self>,
        cx: &mut TaskContext<'_>,
        buf: &mut ReadBuf<'_>,
    ) -> Poll<std::io::Result<()>> {
        Pin::new(&mut self.recv).poll_read(cx, buf)
    }
}

impl AsyncWrite for QuicBiStream {
    fn poll_write(
        mut self: Pin<&mut Self>,
        cx: &mut TaskContext<'_>,
        buf: &[u8],
    ) -> Poll<std::io::Result<usize>> {
        match Pin::new(&mut self.send).poll_write(cx, buf) {
            Poll::Ready(Ok(value)) => Poll::Ready(Ok(value)),
            Poll::Ready(Err(err)) => Poll::Ready(Err(quic_write_error(err))),
            Poll::Pending => Poll::Pending,
        }
    }

    fn poll_flush(mut self: Pin<&mut Self>, cx: &mut TaskContext<'_>) -> Poll<std::io::Result<()>> {
        Pin::new(&mut self.send).poll_flush(cx)
    }

    fn poll_shutdown(mut self: Pin<&mut Self>, cx: &mut TaskContext<'_>) -> Poll<std::io::Result<()>> {
        Pin::new(&mut self.send).poll_shutdown(cx)
    }
}

pub enum LegIo {
    Tcp(TcpStream),
    Quic(QuicBiStream),
}

impl LegIo {
    pub fn transport(&self) -> LegTransport {
        match self {
            Self::Tcp(_) => LegTransport::Tcp,
            Self::Quic(_) => LegTransport::Quic,
        }
    }
}

impl AsyncRead for LegIo {
    fn poll_read(
        mut self: Pin<&mut Self>,
        cx: &mut TaskContext<'_>,
        buf: &mut ReadBuf<'_>,
    ) -> Poll<std::io::Result<()>> {
        match &mut *self {
            Self::Tcp(stream) => Pin::new(stream).poll_read(cx, buf),
            Self::Quic(stream) => Pin::new(stream).poll_read(cx, buf),
        }
    }
}

impl AsyncWrite for LegIo {
    fn poll_write(
        mut self: Pin<&mut Self>,
        cx: &mut TaskContext<'_>,
        buf: &[u8],
    ) -> Poll<std::io::Result<usize>> {
        match &mut *self {
            Self::Tcp(stream) => Pin::new(stream).poll_write(cx, buf),
            Self::Quic(stream) => Pin::new(stream).poll_write(cx, buf),
        }
    }

    fn poll_flush(mut self: Pin<&mut Self>, cx: &mut TaskContext<'_>) -> Poll<std::io::Result<()>> {
        match &mut *self {
            Self::Tcp(stream) => Pin::new(stream).poll_flush(cx),
            Self::Quic(stream) => Pin::new(stream).poll_flush(cx),
        }
    }

    fn poll_shutdown(mut self: Pin<&mut Self>, cx: &mut TaskContext<'_>) -> Poll<std::io::Result<()>> {
        match &mut *self {
            Self::Tcp(stream) => Pin::new(stream).poll_shutdown(cx),
            Self::Quic(stream) => Pin::new(stream).poll_shutdown(cx),
        }
    }
}

fn quic_write_error(err: quinn::WriteError) -> std::io::Error {
    std::io::Error::other(err.to_string())
}

struct QuicConnectionPool {
    endpoint: Endpoint,
    remote_addr: SocketAddr,
    server_name: String,
    label: String,
    connection: Option<quinn::Connection>,
}

impl QuicConnectionPool {
    async fn open_stream(&mut self, connect_timeout: Duration) -> anyhow::Result<LegIo> {
        loop {
            if let Some(connection) = self.connection.as_ref() {
                match timeout(connect_timeout, connection.open_bi()).await {
                    Ok(Ok((send, recv))) => {
                        return Ok(LegIo::Quic(QuicBiStream::new(
                            connection.clone(),
                            None,
                            send,
                            recv,
                        )));
                    }
                    Ok(Err(err)) => {
                        debug!(
                            "discarding cached quic leg connection {} because opening a stream failed: {:?}",
                            self.label, err
                        );
                        self.connection = None;
                    }
                    Err(_) => {
                        debug!(
                            "timed out opening cached quic stream on {} - reconnecting",
                            self.label
                        );
                        self.connection = None;
                    }
                }
            }

            let connecting = self
                .endpoint
                .connect(self.remote_addr, &self.server_name)
                .with_context(|| format!("failed to start quic connection for {}", self.label))?;
            let connection = timeout(connect_timeout, connecting)
                .await
                .with_context(|| format!("timed out during quic handshake for {}", self.label))?
                .with_context(|| format!("quic handshake failed for {}", self.label))?;
            self.connection = Some(connection);
        }
    }
}

pub async fn connect_leg(spec: &LegSpec, connect_timeout: Duration) -> anyhow::Result<LegIo> {
    match &spec.dial {
        LegDial::Socks5 { connect_target, .. } => {
            let connect_target = connect_target
                .ok_or_else(|| anyhow!("SOCKS leg is missing its authorized connect target"))?;
            connect_socks5_leg(spec, connect_target, connect_timeout).await
        }
        LegDial::IngressTcp { route_key, .. } => {
            let auth_token = spec
                .ingress_auth_token
                .as_ref()
                .ok_or_else(|| anyhow!("ingress leg auth reference is unresolved"))?;
            let remote = endpoint_target(&spec.host, spec.port);
            let stream = timeout(
                connect_timeout,
                leikwan_ingress::client::connect_authenticated(
                    &remote,
                    route_key,
                    &auth_token.0,
                ),
            )
            .await
            .with_context(|| format!("timed out connecting authenticated ingress leg {}", spec.original))?
            .with_context(|| format!("failed to connect authenticated ingress leg {}", spec.original))?;
            let _ = stream.set_nodelay(true);
            Ok(LegIo::Tcp(stream))
        }
        LegDial::Direct => match spec.transport {
            LegTransport::Tcp => {
                let target = endpoint_target(&spec.host, spec.port);
                let stream = timeout(connect_timeout, TcpStream::connect(&target))
                    .await
                    .with_context(|| format!("timed out connecting tcp leg {}", spec.original))?
                    .with_context(|| format!("failed to connect tcp leg {}", spec.original))?;
                let _ = stream.set_nodelay(true);
                Ok(LegIo::Tcp(stream))
            }
            LegTransport::Quic => connect_quic_leg(spec, connect_timeout).await,
        },
    }
}

async fn connect_socks5_leg(
    spec: &LegSpec,
    connect_target: SocketAddr,
    connect_timeout: Duration,
) -> anyhow::Result<LegIo> {
    let proxy = endpoint_target(&spec.host, spec.port);
    let stream = timeout(connect_timeout, async {
        let mut stream = TcpStream::connect(&proxy).await?;
        stream.write_all(&[5, 1, 0]).await?;
        let mut greeting = [0u8; 2];
        stream.read_exact(&mut greeting).await?;
        if greeting != [5, 0] {
            return Err(anyhow!("SOCKS proxy rejected no-auth negotiation"));
        }

        let mut request = Vec::with_capacity(22);
        request.extend_from_slice(&[5, 1, 0]);
        match connect_target.ip() {
            IpAddr::V4(address) => {
                request.push(1);
                request.extend_from_slice(&address.octets());
            }
            IpAddr::V6(address) => {
                request.push(4);
                request.extend_from_slice(&address.octets());
            }
        }
        request.extend_from_slice(&connect_target.port().to_be_bytes());
        stream.write_all(&request).await?;

        let mut response = [0u8; 4];
        stream.read_exact(&mut response).await?;
        if response[0] != 5 || response[2] != 0 {
            return Err(anyhow!("invalid SOCKS CONNECT response"));
        }
        if response[1] != 0 {
            return Err(anyhow!("SOCKS CONNECT failed with reply code {}", response[1]));
        }
        match response[3] {
            1 => {
                let mut address_and_port = [0u8; 6];
                stream.read_exact(&mut address_and_port).await?;
            }
            4 => {
                let mut address_and_port = [0u8; 18];
                stream.read_exact(&mut address_and_port).await?;
            }
            3 => {
                let length = stream.read_u8().await? as usize;
                let mut domain_and_port = vec![0u8; length.saturating_add(2)];
                stream.read_exact(&mut domain_and_port).await?;
            }
            other => return Err(anyhow!("unsupported SOCKS response address type {other}")),
        }
        anyhow::Ok(stream)
    })
    .await
    .with_context(|| format!("timed out connecting SOCKS leg {}", spec.original))?
    .with_context(|| format!("failed to connect SOCKS leg {}", spec.original))?;
    let _ = stream.set_nodelay(true);
    Ok(LegIo::Tcp(stream))
}

fn endpoint_target(host: &str, port: u16) -> String {
    match host.parse::<IpAddr>() {
        Ok(IpAddr::V6(_)) => format!("[{host}]:{port}"),
        _ => format!("{host}:{port}"),
    }
}

pub fn default_quic_listen(tcp_listen: &str) -> String {
    tcp_listen.to_string()
}

pub fn build_server_quic_endpoint(bind: &str) -> anyhow::Result<Endpoint> {
    let bind_addr: SocketAddr = bind
        .parse()
        .with_context(|| format!("invalid quic listen address {bind}"))?;
    let host = bind_addr.ip().to_string();
    let mut san_hosts = vec!["localhost".to_string()];
    if bind_addr.ip().is_loopback() {
        san_hosts.push(host.clone());
    } else if !matches!(host.as_str(), "0.0.0.0" | "::") {
        san_hosts.push(host.clone());
    }
    san_hosts.sort();
    san_hosts.dedup();

    let certificate = generate_simple_self_signed(san_hosts).context("failed to generate QUIC self-signed certificate")?;
    let certificate_der = CertificateDer::from(certificate.cert.der().clone());
    let private_key = PrivateKeyDer::Pkcs8(PrivatePkcs8KeyDer::from(certificate.key_pair.serialize_der()));

    let mut tls_config = rustls::ServerConfig::builder()
        .with_no_client_auth()
        .with_single_cert(vec![certificate_der], private_key)
        .context("failed to build QUIC rustls server config")?;
    tls_config.alpn_protocols = vec![QUIC_ALPN.to_vec()];

    let mut server_config = QuinnServerConfig::with_crypto(Arc::new(
        quinn::crypto::rustls::QuicServerConfig::try_from(tls_config)
            .context("failed to convert rustls config for quic server")?,
    ));
    server_config.transport = Arc::new(default_quic_transport_config());

    Endpoint::server(server_config, bind_addr).with_context(|| format!("failed to bind quic listener on {bind}"))
}

pub async fn accept_quic_leg(incoming: quinn::Incoming) -> anyhow::Result<LegIo> {
    let connection = timeout(QUIC_HANDSHAKE_TIMEOUT, incoming)
        .await
        .context("timed out waiting for quic client handshake")?
        .context("failed to accept quic client connection")?;
    let (send, recv) = timeout(QUIC_HANDSHAKE_TIMEOUT, connection.accept_bi())
        .await
        .context("timed out waiting for first quic stream")?
        .context("failed to accept quic stream")?;
    Ok(LegIo::Quic(QuicBiStream::new(connection, None, send, recv)))
}

async fn connect_quic_leg(spec: &LegSpec, connect_timeout: Duration) -> anyhow::Result<LegIo> {
    let target = endpoint_target(&spec.host, spec.port);
    let mut resolved = lookup_host(&target)
        .await
        .with_context(|| format!("failed to resolve quic leg {}", spec.original))?;
    let remote_addr = resolved
        .next()
        .ok_or_else(|| anyhow!("quic leg resolved to no addresses: {}", spec.original))?;

    let bind_addr = if remote_addr.is_ipv4() {
        SocketAddr::from(([0, 0, 0, 0], 0))
    } else {
        SocketAddr::from(([0, 0, 0, 0, 0, 0, 0, 0], 0))
    };
    let mut endpoint = Endpoint::client(bind_addr).context("failed to create quic client endpoint")?;
    endpoint.set_default_client_config(build_quic_client_config(spec)?);

    let server_name = spec
        .server_name
        .clone()
        .or_else(|| {
            if spec.host.parse::<IpAddr>().is_ok() {
                None
            } else {
                Some(spec.host.clone())
            }
        })
        .unwrap_or_else(|| String::from("localhost"));

    let pool_key = format!(
        "{}|{}|{}|{}",
        spec.original,
        remote_addr,
        server_name,
        if spec.insecure { "insecure" } else { "verified" }
    );
    let pool = {
        let pools = QUIC_CONNECTION_POOLS.get_or_init(|| StdMutex::new(HashMap::new()));
        let mut guard = pools.lock().expect("quic pool mutex poisoned");
        if let Some(existing) = guard.get(&pool_key) {
            Arc::clone(existing)
        } else {
            let pool = Arc::new(AsyncMutex::new(QuicConnectionPool {
                endpoint,
                remote_addr,
                server_name: server_name.clone(),
                label: spec.original.clone(),
                connection: None,
            }));
            guard.insert(pool_key, Arc::clone(&pool));
            pool
        }
    };

    let mut guard = pool.lock().await;
    guard.open_stream(connect_timeout).await
}

fn build_quic_client_config(spec: &LegSpec) -> anyhow::Result<QuinnClientConfig> {
    let provider = rustls::crypto::ring::default_provider();
    let mut tls_config = if spec.insecure {
        rustls::ClientConfig::builder()
            .dangerous()
            .with_custom_certificate_verifier(SkipServerVerification::new(provider))
            .with_no_client_auth()
    } else {
        let mut roots = RootCertStore::empty();
        roots.extend(webpki_roots::TLS_SERVER_ROOTS.iter().cloned());
        rustls::ClientConfig::builder()
            .with_root_certificates(roots)
            .with_no_client_auth()
    };
    tls_config.alpn_protocols = vec![QUIC_ALPN.to_vec()];

    let mut client_config = QuinnClientConfig::new(Arc::new(
        quinn::crypto::rustls::QuicClientConfig::try_from(tls_config)
            .context("failed to convert rustls config for quic client")?,
    ));
    client_config.transport_config(Arc::new(default_quic_transport_config()));
    Ok(client_config)
}

fn default_quic_transport_config() -> TransportConfig {
    let mut transport = TransportConfig::default();
    transport.keep_alive_interval(Some(Duration::from_secs(5)));
    transport.max_idle_timeout(Some(Duration::from_secs(30).try_into().unwrap()));
    transport
}

#[derive(Debug)]
struct SkipServerVerification {
    provider: Arc<rustls::crypto::CryptoProvider>,
}

impl SkipServerVerification {
    fn new(provider: rustls::crypto::CryptoProvider) -> Arc<Self> {
        Arc::new(Self {
            provider: Arc::new(provider),
        })
    }
}

impl ServerCertVerifier for SkipServerVerification {
    fn verify_server_cert(
        &self,
        _end_entity: &CertificateDer<'_>,
        _intermediates: &[CertificateDer<'_>],
        _server_name: &ServerName<'_>,
        _ocsp_response: &[u8],
        _now: UnixTime,
    ) -> Result<ServerCertVerified, RustlsError> {
        Ok(ServerCertVerified::assertion())
    }

    fn verify_tls12_signature(
        &self,
        message: &[u8],
        cert: &CertificateDer<'_>,
        dss: &DigitallySignedStruct,
    ) -> Result<HandshakeSignatureValid, RustlsError> {
        rustls::crypto::verify_tls12_signature(message, cert, dss, &self.provider.signature_verification_algorithms)
    }

    fn verify_tls13_signature(
        &self,
        message: &[u8],
        cert: &CertificateDer<'_>,
        dss: &DigitallySignedStruct,
    ) -> Result<HandshakeSignatureValid, RustlsError> {
        rustls::crypto::verify_tls13_signature(message, cert, dss, &self.provider.signature_verification_algorithms)
    }

    fn supported_verify_schemes(&self) -> Vec<SignatureScheme> {
        self.provider.signature_verification_algorithms.supported_schemes()
    }
}

#[cfg(test)]
mod tests {
    use super::{LegDial, LegRole, connect_leg, parse_leg_spec, parse_leg_specs};
    use std::time::{Duration, Instant};
    use tokio::net::TcpListener;

    #[test]
    fn parse_leg_spec_reads_role_query_param() {
        let spec = parse_leg_spec("tcp://127.0.0.1:20962?priority=10&endpoint_id=184&role=primary").expect("parse");
        assert_eq!(spec.role, LegRole::Primary);

        let helper = parse_leg_spec("tcp://127.0.0.1:20963?priority=10&endpoint_id=185&role=helper").expect("parse");
        assert_eq!(helper.role, LegRole::Helper);
    }

    #[test]
    fn parse_leg_spec_keeps_endpoint_and_leg_route_identity_separate() {
        let spec = parse_leg_spec(
            "tcp://127.0.0.1:20962?priority=10&endpoint_id=184&leg_route_id=453",
        )
        .expect("parse");
        assert_eq!(spec.endpoint_id, Some(184));
        assert_eq!(spec.leg_route_id, Some(453));
    }

    #[test]
    fn parse_leg_spec_rejects_zero_leg_route_id() {
        let error = parse_leg_spec("tcp://127.0.0.1:20962?leg_route_id=0")
            .expect_err("zero route must fail");
        assert!(error.to_string().contains("leg_route_id"));
    }

    #[test]
    fn parses_internal_socks_leg_metadata_without_a_target_override() {
        let spec = parse_leg_spec(
            "socks5://127.0.0.1:20451?leg_route_id=1004511&line_id=451&lease_generation=7",
        )
        .expect("parse internal SOCKS leg");

        assert_eq!(spec.leg_route_id, Some(1_004_511));
        assert!(matches!(
            spec.dial,
            LegDial::Socks5 {
                line_id: 451,
                lease_generation: 7,
                connect_target: None,
            }
        ));
    }

    #[test]
    fn parses_internal_authenticated_ingress_leg_metadata() {
        let spec = parse_leg_spec(
            "ingress+tcp://slave.example:7003?leg_route_id=1004531&endpoint_id=316&route_key=fixed&auth_ref=slave-316",
        )
        .expect("parse authenticated ingress leg");

        assert_eq!(spec.leg_route_id, Some(1_004_531));
        assert_eq!(spec.endpoint_id, Some(316));
        assert!(matches!(
            &spec.dial,
            LegDial::IngressTcp { route_key, auth_ref }
                if route_key == "fixed" && auth_ref == "slave-316"
        ));
    }

    #[test]
    fn internal_leg_parser_rejects_inline_credentials_and_target_overrides() {
        for raw in [
            "ingress+tcp://user@slave.example:7003?leg_route_id=1&endpoint_id=316&route_key=fixed&auth_ref=slave-316",
            "ingress+tcp://user:password@slave.example:7003?leg_route_id=1&endpoint_id=316&route_key=fixed&auth_ref=slave-316",
            "ingress+tcp://slave.example:7003?leg_route_id=1&endpoint_id=316&route_key=fixed&auth_ref=slave-316&auth_token=secret",
            "ingress+tcp://slave.example:7003?leg_route_id=1&endpoint_id=316&route_key=fixed&auth_ref=slave-316&password=secret",
            "socks5://127.0.0.1:20451?leg_route_id=1&line_id=451&lease_generation=7&target=198.51.100.1:7443",
            "socks5://127.0.0.1:20451?leg_route_id=1&line_id=451&lease_generation=7&target_host=198.51.100.1",
            "socks5://127.0.0.1:20451?leg_route_id=1&line_id=451&lease_generation=7&target_port=7443",
        ] {
            assert!(parse_leg_spec(raw).is_err(), "must reject {raw}");
        }
    }

    #[test]
    fn internal_leg_parser_rejects_missing_or_ambiguous_metadata() {
        for raw in [
            "socks5://127.0.0.1:20451?line_id=451&lease_generation=7",
            "socks5://127.0.0.1:20451?leg_route_id=1&lease_generation=7",
            "socks5://127.0.0.1:20451?leg_route_id=1&line_id=451",
            "socks5://127.0.0.1:20451?leg_route_id=1&leg_route_id=2&line_id=451&lease_generation=7",
            "ingress+tcp://slave.example:7003?endpoint_id=316&route_key=fixed&auth_ref=slave-316",
            "ingress+tcp://slave.example:7003?leg_route_id=1&route_key=fixed&auth_ref=slave-316",
            "ingress+tcp://slave.example:7003?leg_route_id=1&endpoint_id=316&auth_ref=slave-316",
            "ingress+tcp://slave.example:7003?leg_route_id=1&endpoint_id=316&route_key=fixed",
        ] {
            assert!(parse_leg_spec(raw).is_err(), "must reject {raw}");
        }
    }

    #[test]
    fn internal_leg_parser_rejects_public_socks_unknown_schemes_and_ingress_ports() {
        for raw in [
            "socks5://198.51.100.7:20451?leg_route_id=1&line_id=451&lease_generation=7",
            "http://127.0.0.1:20451?leg_route_id=1&line_id=451&lease_generation=7",
            "ingress+tcp://slave.example:6999?leg_route_id=1&endpoint_id=316&route_key=fixed&auth_ref=slave-316",
            "ingress+tcp://slave.example:9001?leg_route_id=1&endpoint_id=316&route_key=fixed&auth_ref=slave-316",
        ] {
            assert!(parse_leg_spec(raw).is_err(), "must reject {raw}");
        }
    }

    #[test]
    fn parse_leg_specs_rejects_duplicate_route_ids() {
        let error = parse_leg_specs(vec![
            "socks5://127.0.0.1:20451?leg_route_id=1004511&line_id=451&lease_generation=7".to_string(),
            "ingress+tcp://slave.example:7003?leg_route_id=1004511&endpoint_id=316&route_key=fixed&auth_ref=slave-316".to_string(),
        ])
        .expect_err("duplicate route IDs must fail");
        assert!(error.to_string().contains("route"));
    }

    #[tokio::test]
    async fn socks_handshake_obeys_the_single_connect_timeout() {
        let listener = TcpListener::bind("127.0.0.1:0").await.unwrap();
        let proxy = listener.local_addr().unwrap();
        let stalled = tokio::spawn(async move {
            let (_stream, _) = listener.accept().await.unwrap();
            tokio::time::sleep(Duration::from_secs(1)).await;
        });
        let spec = parse_leg_spec(&format!(
            "socks5://{proxy}?leg_route_id=1004511&line_id=451&lease_generation=7"
        ))
        .unwrap()
        .with_socks_connect_target("127.0.0.1:7443".parse().unwrap())
        .unwrap();

        let started = Instant::now();
        let error = match connect_leg(&spec, Duration::from_millis(30)).await {
            Ok(_) => panic!("stalled SOCKS handshake must time out"),
            Err(error) => error,
        };
        assert!(error.to_string().contains("timed out"));
        assert!(started.elapsed() < Duration::from_millis(500));
        stalled.abort();
    }

    #[test]
    fn resolved_ingress_auth_never_appears_in_leg_debug() {
        let spec = parse_leg_spec(
            "ingress+tcp://slave.example:7003?leg_route_id=1004531&endpoint_id=316&route_key=fixed&auth_ref=slave-316",
        )
        .unwrap()
        .with_ingress_auth_token("opaque-ingress-secret")
        .unwrap();

        let debug = format!("{spec:?}");
        assert!(!debug.contains("opaque-ingress-secret"));
        assert!(debug.contains("slave-316"));
    }
}
