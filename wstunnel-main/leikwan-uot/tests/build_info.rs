use std::process::Command;

use serde_json::{json, Value};

#[test]
fn binaries_report_the_feedback_capability_contract() {
    for (path, name) in [
        (env!("CARGO_BIN_EXE_leikwan-uot-bond"), "leikwan-uot-bond"),
        (
            env!("CARGO_BIN_EXE_leikwan-udp-uot-merge"),
            "leikwan-udp-uot-merge",
        ),
    ] {
        let output = Command::new(path)
            .arg("--build-info-json")
            .output()
            .unwrap();
        assert!(
            output.status.success(),
            "{}",
            String::from_utf8_lossy(&output.stderr)
        );
        let value: Value = serde_json::from_slice(&output.stdout).unwrap();
        assert_eq!(value["binary"], name);
        assert_eq!(value["version"], env!("CARGO_PKG_VERSION"));
        assert_eq!(value["protocol_version"], 2);
        assert_eq!(
            value["capabilities"],
            json!(["leg-progress-v1", "selective-first-wins-v1"])
        );
        assert_eq!(value["scheduler_modes"], json!(["legacy", "feedback_drr"]));
    }
}
