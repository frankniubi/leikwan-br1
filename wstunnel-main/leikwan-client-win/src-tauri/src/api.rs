use reqwest::Client;
use serde::{Deserialize, Serialize};

#[derive(Clone)]
pub struct PanelApi {
    client: Client,
    base_url: String,
    token: Option<String>,
}

#[derive(Serialize)]
struct LoginRequest {
    email: String,
    password: String,
}

#[derive(Deserialize)]
pub struct LoginResponse {
    pub ok: bool,
    pub token: String,
    pub user_id: u64,
    pub username: String,
    pub client_id: u64,
    pub client_name: String,
    pub clients: Vec<ClientInfo>,
}

#[derive(Deserialize, Clone, Debug)]
pub struct ClientInfo {
    pub id: u64,
    pub name: String,
}

#[derive(Deserialize, Serialize, Clone, Debug)]
pub struct ForwardInfo {
    pub id: u64,
    pub name: String,
    pub target_host: String,
    pub target_port: u16,
    pub listen_port: u16,
    pub client_mode: String,
    pub transport_mode: String,
    pub service_status: String,
    pub enabled: bool,
}

#[derive(Deserialize, Debug)]
pub struct ForwardConfig {
    pub mode: String,
    pub transport: String,
    pub endpoints: Vec<serde_json::Value>,
    pub bonding: Option<serde_json::Value>,
    pub mptcp: Option<serde_json::Value>,
    pub connection_policy: serde_json::Value,
}

#[derive(Deserialize)]
pub struct HeartbeatResponse {
    pub ok: bool,
}

#[derive(Deserialize, Debug)]
pub struct PullConfigsResponse {
    pub ok: bool,
    pub client: serde_json::Value,
    pub forwards: Vec<serde_json::Value>,
}

impl PanelApi {
    pub fn new(base_url: &str) -> Self {
        Self {
            client: Client::builder()
                .timeout(std::time::Duration::from_secs(10))
                .build()
                .expect("HTTP client"),
            base_url: base_url.trim_end_matches('/').to_string(),
            token: None,
        }
    }

    pub fn set_token(&mut self, token: String) {
        self.token = Some(token);
    }

    pub fn is_authenticated(&self) -> bool {
        self.token.is_some()
    }

    pub async fn login(&mut self, email: &str, password: &str) -> anyhow::Result<LoginResponse> {
        let resp = self.client
            .post(format!("{}/api/client/login", self.base_url))
            .json(&LoginRequest { email: email.into(), password: password.into() })
            .send()
            .await?
            .error_for_status()?
            .json::<LoginResponse>()
            .await?;
        if !resp.ok {
            anyhow::bail!("login failed");
        }
        self.token = Some(resp.token.clone());
        Ok(resp)
    }

    pub async fn heartbeat(&self) -> anyhow::Result<HeartbeatResponse> {
        let token = self.token.as_deref().ok_or_else(|| anyhow::anyhow!("not authenticated"))?;
        let resp = self.client
            .post(format!("{}/api/client/heartbeat", self.base_url))
            .bearer_auth(token)
            .send()
            .await?
            .error_for_status()?
            .json::<HeartbeatResponse>()
            .await?;
        Ok(resp)
    }

    pub async fn pull_configs(&self) -> anyhow::Result<PullConfigsResponse> {
        let token = self.token.as_deref().ok_or_else(|| anyhow::anyhow!("not authenticated"))?;
        let resp = self.client
            .get(format!("{}/api/client/configs/pull", self.base_url))
            .bearer_auth(token)
            .send()
            .await?
            .error_for_status()?
            .json::<PullConfigsResponse>()
            .await?;
        Ok(resp)
    }

    pub async fn get_forward_config(&self, forward_id: u64) -> anyhow::Result<ForwardConfig> {
        let token = self.token.as_deref().ok_or_else(|| anyhow::anyhow!("not authenticated"))?;
        let resp = self.client
            .get(format!("{}/api/client/config/{forward_id}", self.base_url))
            .bearer_auth(token)
            .send()
            .await?
            .error_for_status()?
            .json::<ForwardConfig>()
            .await?;
        Ok(resp)
    }
}
