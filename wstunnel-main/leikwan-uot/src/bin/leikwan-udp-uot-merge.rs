use clap::{ArgGroup, Parser};

#[derive(Parser)]
#[command(group(
    ArgGroup::new("mode")
        .required(true)
        .args(["config", "build_info_json"])
))]
struct Args {
    #[arg(long)]
    config: Option<String>,
    #[arg(long)]
    build_info_json: bool,
}

fn main() -> anyhow::Result<()> {
    let args = Args::parse();
    if args.build_info_json {
        println!(
            "{}",
            serde_json::to_string(&leikwan_uot::build_info("leikwan-udp-uot-merge"))?
        );
        return Ok(());
    }
    let runtime = tokio::runtime::Builder::new_multi_thread()
        .enable_all()
        .build()?;
    runtime.block_on(leikwan_uot::sidecar_runtime::run_from_path(
        args.config.as_deref().expect("clap requires config"),
    ))
}
