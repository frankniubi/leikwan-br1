use clap::Parser;
use leikwan_bond::cli::{self, Cli};

fn main() {
    env_logger::Builder::from_env(env_logger::Env::default().default_filter_or("info")).init();

    let cli = Cli::parse();
    let result = cli::prepare(cli).and_then(|prepared| {
        let rt = tokio::runtime::Builder::new_multi_thread()
            .enable_all()
            .build()
            .map_err(|err| anyhow::anyhow!("failed to build tokio runtime: {err}"))?;
        rt.block_on(prepared.run())
    });

    if let Err(e) = result {
        log::error!("fatal: {:#}", e);
        std::process::exit(1);
    }
}
