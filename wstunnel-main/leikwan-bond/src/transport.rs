use anyhow::{Context, anyhow};
use quinn::{ClientConfig as QuinnClientConfig, Endpoint, ServerConfig as QuinnServerConfig, TransportConfig};
use rcgen::generate_simple_self_signed;
use rustls::client::danger::{HandshakeSignatureValid, ServerCertVerified, ServerCertVerifier};
use rustls::pki_types::{CertificateDer, PrivateKeyDer, PrivatePkcs8KeyDer, ServerName, UnixTime};
use rustls::{DigitallySignedStruct, Error as RustlsError, RootCertStore, SignatureScheme};
use std::collections::HashMap;
use std::fmt;
use std::net::{IpAddr, SocketAddr};
use std::pin::Pin;
use std::str::FromStr;
use std::sync::{Arc, Mutex as StdMutex, OnceLock};
use std::task::{Context as TaskContext, Poll};
use std::time::Duration;
use tokio::io::{AsyncRead, AsyncWrite, ReadBuf};
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
    pub host: String,
    pub port: u16,
    pub server_name: Option<String>,
    pub insecure: bool,
    pub priority: u16,
    pub role: LegRole,
    pub endpoint_id: Option<u64>,
    pub node_id: Option<u64>,
    pub display_host: Option<String>,
    pub original: String,
}

impl fmt::Debug for LegSpec {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        f.debug_struct("LegSpec")
            .field("transport", &self.transport)
            .field("host", &self.host)
            .field("port", &self.port)
            .field("server_name", &self.server_name)
            .field("insecure", &self.insecure)
            .field("priority", &self.priority)
            .field("role", &self.role)
            .field("endpoint_id", &self.endpoint_id)
            .field("node_id", &self.node_id)
            .field("display_host", &self.display_host)
            .finish()
    }
}

pub fn parse_leg_specs(raw_legs: Vec<String>) -> anyhow::Result<Vec<LegSpec>> {
    raw_legs.into_iter().map(|raw| parse_leg_spec(&raw)).collect()
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
            host: address.ip().to_string(),
            port: address.port(),
            server_name: None,
            insecure: false,
            priority: LegTransport::Tcp.default_priority(),
            role: LegRole::Primary,
            endpoint_id: None,
            node_id: None,
            display_host: None,
            original: trimmed.to_string(),
        });
    }

    let url = Url::parse(trimmed).with_context(|| format!("invalid bond leg URI {trimmed}"))?;
    let transport = match url.scheme() {
        "tcp" => LegTransport::Tcp,
        "quic" => LegTransport::Quic,
        other => {
            return Err(anyhow!(
                "unsupported bond leg scheme {other}, expected tcp:// or quic://"
            ));
        }
    };
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
    let mut node_id = None;
    let mut display_host = None;
    for (key, value) in url.query_pairs() {
        match key.as_ref() {
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
                if let Ok(parsed) = value.parse::<u16>() {
                    priority = parsed;
                }
            }
            "role" => {
                role = match value.as_ref() {
                    "helper" => LegRole::Helper,
                    _ => LegRole::Primary,
                };
            }
            "endpoint_id" => {
                if let Ok(parsed) = value.parse::<u64>() {
                    endpoint_id = Some(parsed);
                }
            }
            "node_id" => {
                if let Ok(parsed) = value.parse::<u64>() {
                    node_id = Some(parsed);
                }
            }
            "display_host" | "host_label" => {
                if !value.is_empty() {
                    display_host = Some(value.to_string());
                }
            }
            _ => {}
        }
    }

    Ok(LegSpec {
        transport,
        host,
        port,
        server_name,
        insecure,
        priority,
        role,
        endpoint_id,
        node_id,
        display_host,
        original: trimmed.to_string(),
    })
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
    match spec.transport {
        LegTransport::Tcp => {
            let target = format!("{}:{}", spec.host, spec.port);
            let stream = timeout(connect_timeout, TcpStream::connect(&target))
                .await
                .with_context(|| format!("timed out connecting tcp leg {}", spec.original))?
                .with_context(|| format!("failed to connect tcp leg {}", spec.original))?;
            let _ = stream.set_nodelay(true);
            Ok(LegIo::Tcp(stream))
        }
        LegTransport::Quic => connect_quic_leg(spec, connect_timeout).await,
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
    let target = format!("{}:{}", spec.host, spec.port);
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
    use super::{LegRole, parse_leg_spec};

    #[test]
    fn parse_leg_spec_reads_role_query_param() {
        let spec = parse_leg_spec("tcp://127.0.0.1:20962?priority=10&endpoint_id=184&role=primary").expect("parse");
        assert_eq!(spec.role, LegRole::Primary);

        let helper = parse_leg_spec("tcp://127.0.0.1:20963?priority=10&endpoint_id=185&role=helper").expect("parse");
        assert_eq!(helper.role, LegRole::Helper);
    }
}
