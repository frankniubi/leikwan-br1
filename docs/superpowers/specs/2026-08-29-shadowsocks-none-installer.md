# Shadowsocks None Installer Specification

## Goal

Add a standalone installer for a Shadowsocks server using the `none` method,
with an interactive first-run experience for the listen port and password.

## Scope

- Target Debian 11/12/13 and Ubuntu hosts using `systemd` and `apt-get`.
- Download a pinned official sing-box glibc release binary and verify its
  SHA-256 before installation.
- Configure one service named `shadowsocks-none` by default.
- Ask for the port and password when those values are not supplied as CLI
  options. Password input must not echo.
- Keep the installer safe to rerun: validate before mutation, back up files,
  write files atomically, and restore the previous state if activation fails.
- Run sing-box as a dedicated unprivileged system user. Grant only
  `CAP_NET_BIND_SERVICE` so ports below 1024 remain usable.
- Verify the active systemd unit and socket listener after activation.

## Non-goals

- No firewall rule changes. The installer reports the port and leaves firewall
  policy to the operator.
- No panel registration, client profile generation, or topology changes.
- No automatic removal of an existing Shadowsocks installation.
- No claim that `none` provides confidentiality. The installer must print a
  clear plaintext-transport warning.

## Inputs

```text
install/install-shadowsocks-none.sh [options]

--listen-address <address>  default: 0.0.0.0
--port <1-65535>            prompt when omitted; default prompt value 8388
--password <value>         prompt when omitted; confirmation required
--service-name <name>       default: shadowsocks-none
--config-dir <directory>    default: /etc/leikwan-shadowsocks-none
--no-start                  install files but do not enable/start the unit
--help
```

When either `--port` or `--password` is missing and stdin is not a terminal,
the installer must fail with a usage error instead of blocking. Passwords may
contain spaces but may not contain CR/LF and must be non-empty. The service
name must match `[A-Za-z0-9_][A-Za-z0-9_.-]{0,31}`; the listen address must be non-empty and
contain no whitespace or CR/LF.

## Files and runtime contract

- Config: `${CONFIG_DIR}/config.json` (mode `0640`, owned by the
  service user and its dedicated primary group).
- Runtime binary: `/usr/local/libexec/leikwan-shadowsocks-none/sing-box`
  (mode `0755`, owned by root).
- Unit: `/etc/systemd/system/${SERVICE_NAME}.service` (mode `0644`).
- Service user: a system user named `${SERVICE_NAME}` with no login shell or
  home directory.
- Config fields: a sing-box `shadowsocks` inbound with `listen`, `listen_port`,
  `method: "none"`, and `password`, plus a `direct` outbound. Omitting the
  inbound `network` field enables both TCP and UDP.
- Unit must use the absolute sing-box path, restart on failure, set
  `NoNewPrivileges=true`, `PrivateTmp=true`, `ProtectSystem=full`, and bound
  capabilities.

## Safety and rollback

Before replacing an existing config or unit, copy it to
`/var/backups/leikwan-shadowsocks-none/<UTC timestamp>/`. Temporary files must
be created in the destination directory and installed with `mv` after content
and permissions are set. If `daemon-reload`, enable, start, or post-start
verification fails, restore the backed-up files (or remove newly created files),
reload systemd, and restart the previous unit when one existed.

## Verification

The installer must verify:

1. Required commands and package availability.
2. Valid JSON and expected `method`/port in the rendered config.
3. `systemctl is-active` for the requested unit unless `--no-start` is used.
4. A TCP or UDP listener on the requested port via `ss` when started.

Output must include the service name, config path, listen address/port, and a
plaintext warning, but must never print the password.
