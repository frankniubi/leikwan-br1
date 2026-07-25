use anyhow::{bail, Result};
use std::path::Path;

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ClientStrategy {
    SpeedMax,
    LatencyBest,
    Random,
}

impl ClientStrategy {
    pub fn parse(value: &str) -> Result<Self> {
        match value.trim().to_ascii_lowercase().as_str() {
            "speed_max" => Ok(Self::SpeedMax),
            "latency_best" => Ok(Self::LatencyBest),
            "random" => Ok(Self::Random),
            other => bail!("unsupported client strategy {other}"),
        }
    }
}

pub fn load_strategy(path: &Path, fallback: ClientStrategy) -> ClientStrategy {
    std::fs::read_to_string(path)
        .ok()
        .and_then(|value| ClientStrategy::parse(&value).ok())
        .unwrap_or(fallback)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn strategy_parser_accepts_only_canonical_values() {
        assert_eq!(ClientStrategy::parse("speed_max").unwrap(), ClientStrategy::SpeedMax);
        assert_eq!(ClientStrategy::parse("latency_best").unwrap(), ClientStrategy::LatencyBest);
        assert_eq!(ClientStrategy::parse("random").unwrap(), ClientStrategy::Random);
        assert!(ClientStrategy::parse("race").is_err());
    }

    #[test]
    fn missing_or_invalid_strategy_file_uses_latency_best() {
        let path = std::env::temp_dir().join(format!(
            "leikwan-bond-strategy-{}-{}.txt",
            std::process::id(),
            std::time::SystemTime::now()
                .duration_since(std::time::UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        assert_eq!(load_strategy(&path, ClientStrategy::LatencyBest), ClientStrategy::LatencyBest);
        std::fs::write(&path, "invalid\n").unwrap();
        assert_eq!(load_strategy(&path, ClientStrategy::LatencyBest), ClientStrategy::LatencyBest);
        let _ = std::fs::remove_file(path);
    }
}
