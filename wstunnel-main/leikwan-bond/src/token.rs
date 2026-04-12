use anyhow::{anyhow, Result};
use base64::engine::general_purpose::URL_SAFE_NO_PAD;
use base64::Engine;
use hmac::{Hmac, Mac};
use serde::Deserialize;
use sha2::Sha256;

type HmacSha256 = Hmac<Sha256>;

#[derive(Debug, Clone, Deserialize)]
#[allow(dead_code)]
pub struct TokenPayload {
    pub target_host: String,
    pub target_port: u16,
    #[serde(default)]
    pub target_protocol: String,
    #[serde(default)]
    pub service_id: u64,
    #[serde(default)]
    pub forward_id: u64,
    #[serde(default)]
    pub issued_at: u64,
    #[serde(default)]
    pub expires_at: u64,
}

pub fn verify_token(token: &str, secret: &str) -> Result<TokenPayload> {
    let dot_pos = token.find('.').ok_or_else(|| anyhow!("invalid token format"))?;
    let payload_b64 = &token[..dot_pos];
    let signature_b64 = &token[dot_pos + 1..];

    let payload_bytes = URL_SAFE_NO_PAD
        .decode(payload_b64)
        .map_err(|e| anyhow!("decode payload: {}", e))?;

    let mut mac = HmacSha256::new_from_slice(secret.as_bytes())
        .map_err(|e| anyhow!("HMAC key: {}", e))?;
    mac.update(&payload_bytes);

    let expected_sig = URL_SAFE_NO_PAD
        .decode(signature_b64)
        .map_err(|e| anyhow!("decode sig: {}", e))?;

    mac.verify_slice(&expected_sig)
        .map_err(|_| anyhow!("token signature verification failed"))?;

    let payload: TokenPayload = serde_json::from_slice(&payload_bytes)
        .map_err(|e| anyhow!("parse payload: {}", e))?;

    Ok(payload)
}
