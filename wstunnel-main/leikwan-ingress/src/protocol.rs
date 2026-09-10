use anyhow::{Result, anyhow, bail};

pub const MAX_LINE_BYTES: usize = 4096;
pub const OK_LINE: &str = "OK\n";

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Command {
    Open,
    Ping,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ParsedClientLine {
    pub command: Command,
    pub route_key: String,
    pub auth_token: String,
}

pub fn parse_client_line(line: &str) -> Result<ParsedClientLine> {
    let trimmed = line.trim();
    if trimmed.is_empty() {
        bail!("empty client line");
    }

    let parts: Vec<&str> = trimmed.split_whitespace().collect();
    if parts.len() != 5 {
        bail!("invalid client line field count");
    }
    if parts[0] != "LEIKWAN-INGRESS" {
        bail!("invalid client line magic");
    }
    if parts[1] != "1" {
        bail!("unsupported client line version");
    }
    let command = match parts[2] {
        "OPEN" => Command::Open,
        "PING" => Command::Ping,
        other => return Err(anyhow!("unsupported command {other}")),
    };
    let route_key = parts[3].trim().to_string();
    let auth_token = parts[4].trim().to_string();
    if route_key.is_empty() {
        bail!("route key missing");
    }
    if auth_token.is_empty() {
        bail!("auth token missing");
    }
    Ok(ParsedClientLine {
        command,
        route_key,
        auth_token,
    })
}

pub fn build_client_line(command: Command, route_key: &str, auth_token: &str) -> String {
    let command_str = match command {
        Command::Open => "OPEN",
        Command::Ping => "PING",
    };
    format!(
        "LEIKWAN-INGRESS 1 {command_str} {} {}\n",
        route_key.trim(),
        auth_token.trim()
    )
}
