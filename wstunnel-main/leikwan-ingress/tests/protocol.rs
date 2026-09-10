use leikwan_ingress::protocol::{Command, parse_client_line};

#[test]
fn parses_open_command() {
    let parsed = parse_client_line("LEIKWAN-INGRESS 1 OPEN a43547b6350f72cd token-1\n").unwrap();
    assert_eq!(parsed.command, Command::Open);
    assert_eq!(parsed.route_key, "a43547b6350f72cd");
    assert_eq!(parsed.auth_token, "token-1");
}

#[test]
fn parses_ping_command() {
    let parsed = parse_client_line("LEIKWAN-INGRESS 1 PING a43547b6350f72cd token-1\n").unwrap();
    assert_eq!(parsed.command, Command::Ping);
    assert_eq!(parsed.route_key, "a43547b6350f72cd");
}
