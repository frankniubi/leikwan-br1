use anyhow::{anyhow, Result};
use base64::engine::general_purpose::URL_SAFE_NO_PAD;
use base64::Engine;
use hmac::{Hmac, Mac};
use serde::Deserialize;
use sha2::Sha256;

type HmacSha256 = Hmac<Sha256>;

/// Decoded JWT payload with routing information.
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

/// Verify a session token and extract the payload.
///
/// Token format: `base64url(payload_json).base64url(HMAC-SHA256(payload_bytes))`
/// The HMAC is computed over the raw payload bytes (before base64 encoding).
pub fn verify_token(token: &str, secret: &str) -> Result<TokenPayload> {
    let dot_pos = token.find('.').ok_or_else(|| anyhow!("invalid token format: no dot separator"))?;
    let payload_b64 = &token[..dot_pos];
    let signature_b64 = &token[dot_pos + 1..];

    if payload_b64.is_empty() || signature_b64.is_empty() {
        return Err(anyhow!("invalid token format: empty payload or signature"));
    }

    // Decode payload bytes
    let payload_bytes = URL_SAFE_NO_PAD
        .decode(payload_b64)
        .map_err(|e| anyhow!("failed to decode payload: {}", e))?;

    // Verify HMAC-SHA256 signature over raw payload bytes
    let mut mac = HmacSha256::new_from_slice(secret.as_bytes())
        .map_err(|e| anyhow!("failed to create HMAC key: {}", e))?;
    mac.update(&payload_bytes);

    let expected_sig = URL_SAFE_NO_PAD
        .decode(signature_b64)
        .map_err(|e| anyhow!("failed to decode signature: {}", e))?;

    mac.verify_slice(&expected_sig)
        .map_err(|_| anyhow!("token signature verification failed"))?;

    // Parse payload JSON
    let payload: TokenPayload = serde_json::from_slice(&payload_bytes)
        .map_err(|e| anyhow!("failed to parse token payload: {}", e))?;

    Ok(payload)
}

#[cfg(test)]
mod tests {
    use super::*;
    use base64::Engine;
    use hmac::Mac;

    #[test]
    fn test_verify_valid_token() {
        let secret = "test-secret-key";
        let payload = r#"{"target_host":"10.0.0.1","target_port":8080,"target_protocol":"tcp","service_id":"svc1","forward_id":42}"#;

        let payload_b64 = URL_SAFE_NO_PAD.encode(payload.as_bytes());

        // HMAC over raw payload bytes (not base64)
        let mut mac = HmacSha256::new_from_slice(secret.as_bytes()).unwrap();
        mac.update(payload.as_bytes());
        let sig = mac.finalize().into_bytes();
        let sig_b64 = URL_SAFE_NO_PAD.encode(&sig);

        let token = format!("{}.{}", payload_b64, sig_b64);
        let result = verify_token(&token, secret).unwrap();
        assert_eq!(result.target_host, "10.0.0.1");
        assert_eq!(result.target_port, 8080);
        assert_eq!(result.forward_id, 42);
    }

    #[test]
    fn test_verify_bad_signature() {
        let payload = r#"{"target_host":"10.0.0.1","target_port":8080}"#;
        let payload_b64 = URL_SAFE_NO_PAD.encode(payload.as_bytes());
        let token = format!("{}.badsignature", payload_b64);
        let result = verify_token(&token, "secret");
        assert!(result.is_err());
    }

    #[test]
    fn test_verify_malformed_token() {
        let result = verify_token("notokenformat", "secret");
        assert!(result.is_err());
    }
}
