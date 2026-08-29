# Shadowsocks None Installer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a safe, rerunnable Debian/Ubuntu installer for a pinned official sing-box server configured with Shadowsocks `method: "none"` and interactive port/password prompts.

**Architecture:** A standalone Bash installer under `install/` validates inputs, downloads and hash-verifies a pinned official sing-box glibc archive, renders JSON with Python, and owns a dedicated systemd unit and service account. Existing files are backed up and replaced atomically; failed activation restores the prior state. No panel or existing client-agent code is changed.

**Tech Stack:** Bash (`set -Eeuo pipefail`), `apt-get`, `systemd`, sing-box, Python 3 JSON serialization, POSIX shell regression tests.

**Spec:** `docs/superpowers/specs/2026-08-29-shadowsocks-none-installer.md`

## Global Constraints

- Target Debian 11/12/13 and Ubuntu hosts using `systemd` and `apt-get`.
- Use sing-box `1.13.12`; amd64 archive SHA-256 is `11cf6d5fb93c60525771bc5652b46b734ee033ef72831056735fc658243e1fdb`; arm64 archive SHA-256 is `7a899581e82252a6475591fcb50fbecd4faf61c2b2a8a9bc8546cc93239bc48d`.
- `method` must be exactly `none`; print a plaintext warning.
- Prompt for missing port/password on a TTY; fail instead of blocking on non-TTY input.
- Passwords never appear in normal output.
- Do not change firewall policy or unrelated dirty worktree files.
- Back up existing config/unit before mutation and roll back activation failures.
- Run sing-box as a dedicated unprivileged system user and group with only bind capability.

---

### Task 1: Add failing installer contract tests

**Files:**
- Create: `install/tests/test_shadowsocks_none_installer.sh`
- Test target: `install/install-shadowsocks-none.sh`

**Interfaces:**
- Consumes the installer source as text and runs `bash -n`.
- Produces a shell test that exits non-zero while the installer is absent or
  misses any required contract.

- [ ] **Step 1: Write the failing test**

  Add a `need()` helper around `grep -Eq --` and assert the following source
  contracts before invoking `bash -n`:

  ```bash
  need 'set -Eeuo pipefail'
  need '--port'; need '--password'; need '--no-start'
  need 'read[[:space:]]+-r[[:space:]]+-s[[:space:]]+-p'
  need 'sing-box'; need 'method[^[:alnum:]]*[=:][^[:alnum:]]*none'
  need 'systemctl[[:space:]]+is-active'
  need '/var/backups/leikwan-shadowsocks-none'
  ! grep -Eiq '(^|[[:space:]])(iptables|ip6tables|ufw|nft)[[:space:]]' "$SCRIPT"
  ```

- [ ] **Step 2: Run the test and verify the expected failure**

  Run:

  ```bash
  bash install/tests/test_shadowsocks_none_installer.sh
  ```

  Expected: FAIL because `install/install-shadowsocks-none.sh` does not yet
  exist.

### Task 2: Implement the installer to the contract

**Files:**
- Create: `install/install-shadowsocks-none.sh`

**Interfaces:**
- CLI options and defaults are exactly those in the spec.
- Interactive input uses a visible port prompt and hidden password plus
  confirmation; non-TTY input without both values fails.
- Exit code is non-zero on invalid input, unsupported host, package failure,
  service activation failure, or listener verification failure.

- [ ] **Step 1: Implement argument parsing and interactive input**

  Use `read -r -p` for the port, `read -r -s -p` for the password and a second
  confirmation. Apply the spec's port, address, password, and service-name
  validation before any `apt-get`, file, or systemd mutation.

  ```bash
  if (( !PORT_SET )); then
    [[ -t 0 ]] || die "--port and --password are required when stdin is not a terminal"
    read -r -p "Listen port [8388]: " PORT
    PORT="${PORT:-8388}"
  fi
  if (( !PASSWORD_SET )); then
    read -r -s -p "Password: " PASSWORD; printf '\\n' >&2
    read -r -s -p "Confirm password: " PASSWORD_CONFIRM; printf '\\n' >&2
    [[ "$PASSWORD" == "$PASSWORD_CONFIRM" ]] || die "passwords do not match"
  fi
  ```

- [ ] **Step 2: Implement dependency and host checks**

  Require root, `/etc/os-release` with Debian or Ubuntu, `apt-get`, `systemctl`,
  `python3`, `curl`, `tar`, `sha256sum`, and `ss`; install `curl`,
  `ca-certificates`, `tar`, `gzip`, `coreutils`, `python3`, `iproute2`, and
  `mawk` non-interactively when needed. Resolve the extracted sing-box binary.

  ```bash
  case "${ID:-}" in debian|ubuntu) ;; *) die "Debian or Ubuntu is required" ;; esac
  apt-get update
  apt-get install -y curl ca-certificates tar gzip coreutils findutils grep python3 iproute2 mawk
  SING_BOX_URL="https://github.com/SagerNet/sing-box/releases/download/v1.13.12/sing-box-1.13.12-linux-amd64-glibc.tar.gz"
  echo "$EXPECTED_SHA256  $ARCHIVE_TMP" | sha256sum -c -
  ```

- [ ] **Step 3: Implement backup, account, config, and unit rendering**

  Create a UTC backup directory, preserve existing config/unit, create the
  `${SERVICE_NAME}` system user/group, render JSON through Python using an environment
  variable for the password, and atomically install mode `0640` config and
  mode `0644` unit files.

  ```bash
  SS_PASSWORD="$PASSWORD" python3 - "$CONFIG_TMP" "$LISTEN_ADDRESS" "$PORT" <<'PY'
  payload = {"log": {"level": "warn", "timestamp": True},
             "inbounds": [{"type": "shadowsocks", "tag": "ss-in",
                           "listen": sys.argv[2], "listen_port": int(sys.argv[3]),
                           "method": "none", "password": os.environ["SS_PASSWORD"]}],
             "outbounds": [{"type": "direct", "tag": "direct"}]}
  pathlib.Path(sys.argv[1]).write_text(json.dumps(payload, indent=2) + "\\n")
  PY
  chown "$SERVICE_USER:$SERVICE_GROUP" "$CONFIG_TMP"; chmod 0640 "$CONFIG_TMP"
  mv -f -- "$CONFIG_TMP" "$CONFIG_PATH"
  ```

- [ ] **Step 4: Implement activation, listener verification, and rollback**

  Run `systemctl daemon-reload`, enable/start unless `--no-start`, verify
  `systemctl is-active` and `ss` listener state, and use an `ERR` trap to
  restore files and the prior service state after a failed mutation.

  ```bash
  systemctl daemon-reload
  systemctl enable --now "$SERVICE_NAME.service"
  systemctl is-active --quiet "$SERVICE_NAME.service"
  ss -H -lntu | awk -v port="$PORT" '$4 ~ (":" port "$|\\]:" port "$") { found=1 } END { exit !found }'
  ```

- [ ] **Step 5: Run the contract test and syntax validation**

  Run:

  ```bash
  bash install/tests/test_shadowsocks_none_installer.sh
  bash -n install/install-shadowsocks-none.sh
  ```

  Expected: both commands exit `0`.

### Task 3: Exercise non-mutating paths and review the diff

**Files:**
- Modify only if needed: `install/install-shadowsocks-none.sh`,
  `install/tests/test_shadowsocks_none_installer.sh`

**Interfaces:**
- `--help` works without root or package access.
- Invalid port and non-TTY missing credentials fail before mutation.

- [ ] **Step 1: Verify help and invalid-input behavior**

  Run:

  ```bash
  bash install/install-shadowsocks-none.sh --help
  bash install/install-shadowsocks-none.sh --port 0 --password test
  ```

  Expected: help exits `0`; invalid input exits non-zero and does not invoke
  `apt-get`, `systemctl`, or write under `/etc`.

- [ ] **Step 2: Run focused repository tests and inspect the diff**

  Run:

  ```bash
  git diff --check
  git status --short -- install/install-shadowsocks-none.sh install/tests/test_shadowsocks_none_installer.sh docs/superpowers/specs/2026-08-29-shadowsocks-none-installer.md docs/superpowers/plans/2026-08-29-shadowsocks-none-installer.md
  ```

  Expected: no whitespace errors and only the four task files are newly added
  or modified.
