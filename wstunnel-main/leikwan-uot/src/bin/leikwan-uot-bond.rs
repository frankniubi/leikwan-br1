use clap::Parser;

#[derive(Parser)]
struct Args {
    #[arg(long)]
    config: String,
}

#[tokio::main]
async fn main() -> anyhow::Result<()> {
    let args = Args::parse();
    leikwan_uot::client_runtime::run_from_path(&args.config).await
}
