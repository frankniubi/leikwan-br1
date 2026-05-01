use std::path::PathBuf;

fn main() {
    if std::env::var("CARGO_CFG_TARGET_OS").ok().as_deref() != Some("windows") {
        return;
    }

    let manifest_dir = PathBuf::from(env!("CARGO_MANIFEST_DIR"));
    let icon_path = manifest_dir
        .join("..")
        .join("..")
        .join("LeikwanBridge.Desktop")
        .join("src")
        .join("LeikwanBridge.App")
        .join("Resources")
        .join("Icons")
        .join("leikwan-host.ico");

    println!("cargo:rerun-if-changed={}", icon_path.display());

    let mut res = winres::WindowsResource::new();
    res.set_icon(icon_path.to_str().expect("icon path should be valid utf-8"));
    res.set("CompanyName", "LeiKwan Host");
    res.set("ProductName", "LeiKwan Ingress");
    res.set("FileDescription", "LeiKwan plain TCP ingress runtime");
    res.compile()
        .expect("failed to compile Windows resources for leikwan-ingress");
}
