#!/usr/bin/env bash
set -euo pipefail

# One-click Docker installer for the headless TCP/UDP client.
# The default path builds a local image from the published, hash-checked
# runtime manifest. A private-registry image can be selected with
# LEIKWAN_IMAGE/--image and will be pulled instead.

BASE_URL="${LEIKWAN_INSTALL_BASE_URL:-https://v3.leikwanhost.com}"
INSTALL_DIR="${LEIKWAN_DOCKER_DIR:-${HOME}/.config/leikwan/docker-client}"
IMAGE="${LEIKWAN_IMAGE:-leikwan/bridge:local}"
PANEL_URL="${LEIKWAN_PANEL_URL:-https://v3.leikwanhost.com}"
CLIENT_ID="${LEIKWAN_CLIENT_ID:-}"
PLATFORM="${LEIKWAN_DOCKER_PLATFORM:-}"
TOKEN="${LEIKWAN_AGENT_TOKEN:-}"

die() {
  echo "install-docker-client: $*" >&2
  exit 1
}

usage() {
  cat >&2 <<'EOF'
Usage:
  curl -fsSL https://v3.leikwanhost.com/artifacts/install-docker-client.sh | bash -s -- \
    --client-id <id> --agent-token <token>

Options:
  --client-id <id>          LeiKwan client id (or LEIKWAN_CLIENT_ID)
  --agent-token <token>     agent token (or LEIKWAN_AGENT_TOKEN)
  --panel <url>             panel URL (or LEIKWAN_PANEL_URL)
  --image <name:tag>        private-registry image; skips local build
  --platform <platform>     buildx platform, e.g. linux/amd64 or linux/arm64
  --dir <path>              installation directory
  --help
EOF
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --client-id) [ "$#" -ge 2 ] || die "--client-id requires a value"; CLIENT_ID="$2"; shift 2 ;;
    --agent-token) [ "$#" -ge 2 ] || die "--agent-token requires a value"; TOKEN="$2"; shift 2 ;;
    --panel) [ "$#" -ge 2 ] || die "--panel requires a value"; PANEL_URL="$2"; shift 2 ;;
    --image) [ "$#" -ge 2 ] || die "--image requires a value"; IMAGE="$2"; shift 2 ;;
    --platform) [ "$#" -ge 2 ] || die "--platform requires a value"; PLATFORM="$2"; shift 2 ;;
    --dir) [ "$#" -ge 2 ] || die "--dir requires a value"; INSTALL_DIR="$2"; shift 2 ;;
    --help|-h) usage; exit 0 ;;
    *) die "unknown option: $1" ;;
  esac
done

command -v docker >/dev/null 2>&1 || die "Docker is not installed or not on PATH"
docker compose version >/dev/null 2>&1 || die "Docker Compose v2 is required"
if [ -z "$CLIENT_ID" ] && [ -r /dev/tty ]; then
  printf 'Client id: ' >&2
  IFS= read -r CLIENT_ID < /dev/tty
fi
[ -n "$CLIENT_ID" ] || die "--client-id (or LEIKWAN_CLIENT_ID) is required"
case "$CLIENT_ID" in *[!0-9]*|"") die "client id must be a positive integer" ;; esac

if [ -z "$TOKEN" ]; then
  if [ -r /dev/tty ]; then
    printf 'Agent token: ' >&2
    IFS= read -r -s TOKEN < /dev/tty
    printf '\n' >&2
  else
    die "--agent-token (or LEIKWAN_AGENT_TOKEN) is required when stdin is not interactive"
  fi
fi
[ -n "$TOKEN" ] || die "agent token must not be empty"

mkdir -p "$INSTALL_DIR/secrets"
chmod 700 "$INSTALL_DIR" "$INSTALL_DIR/secrets"
umask 077
printf '%s\n' "$TOKEN" > "$INSTALL_DIR/secrets/agent-token"
unset TOKEN

download() {
  url="$1"
  target="$2"
  if command -v curl >/dev/null 2>&1; then
    curl --fail --silent --show-error --location --retry 3 --connect-timeout 10 "$url" -o "$target"
  elif command -v wget >/dev/null 2>&1; then
    wget -qO "$target" "$url"
  else
    die "curl or wget is required"
  fi
}

download "$BASE_URL/artifacts/docker-client/docker-compose.yml" "$INSTALL_DIR/docker-compose.yml"
download "$BASE_URL/artifacts/docker-client/Dockerfile" "$INSTALL_DIR/Dockerfile"
download "$BASE_URL/artifacts/docker-client/fetch-artifacts.py" "$INSTALL_DIR/fetch-artifacts.py"
download "$BASE_URL/artifacts/docker-client/docker-entrypoint.sh" "$INSTALL_DIR/docker-entrypoint.sh"
download "$BASE_URL/artifacts/docker-client/docker-healthcheck.sh" "$INSTALL_DIR/docker-healthcheck.sh"
chmod 755 "$INSTALL_DIR/docker-entrypoint.sh" "$INSTALL_DIR/docker-healthcheck.sh"

cat > "$INSTALL_DIR/.env" <<EOF
LEIKWAN_IMAGE=$IMAGE
LEIKWAN_PANEL_URL=$PANEL_URL
LEIKWAN_CLIENT_ID=$CLIENT_ID
EOF
if [ -n "$PLATFORM" ]; then
  printf 'LEIKWAN_DOCKER_PLATFORM=%s\n' "$PLATFORM" >> "$INSTALL_DIR/.env"
fi
chmod 600 "$INSTALL_DIR/.env"

if [ "$IMAGE" = "leikwan/bridge:local" ]; then
  build_args=(build --pull)
  if docker buildx version >/dev/null 2>&1; then
    build_args=(buildx build --load --pull)
  else
    case "$(uname -m)" in
      x86_64|amd64) target_arch=amd64 ;;
      aarch64|arm64) target_arch=arm64 ;;
      *) die "unsupported host architecture for Docker build: $(uname -m)" ;;
    esac
    build_args+=(--build-arg "TARGETARCH=$target_arch")
  fi
  if [ -n "$PLATFORM" ]; then
    build_args+=(--platform "$PLATFORM")
  fi
  docker "${build_args[@]}" \
    --build-arg "ARTIFACT_BASE=$BASE_URL" \
    -t "$IMAGE" \
    -f "$INSTALL_DIR/Dockerfile" \
    "$INSTALL_DIR"
else
  docker pull "$IMAGE"
fi

(cd "$INSTALL_DIR" && docker compose --env-file .env up -d)
echo "LeiKwan Docker client is running."
echo "Install directory: $INSTALL_DIR"
echo "Logs: docker compose --env-file '$INSTALL_DIR/.env' -f '$INSTALL_DIR/docker-compose.yml' logs -f"
