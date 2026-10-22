#!/usr/bin/env bash
# install.sh — interactive TUI installer for OpenSinop (macOS/Linux)
#
# Runs the application as a Docker Swarm stack, following the structure of
# established self-hosting installers: preflight checks, Docker Engine
# auto-install via get.docker.com when Docker is missing, a swarm overlay
# network, the database password kept in a Docker secret, and services
# created with `docker service create`.
#
# Usage:
#   bash <(curl -fsSL https://raw.githubusercontent.com/koamishin/OpenSinop/main/install.sh)   # bash / zsh
#   curl -fsSL https://raw.githubusercontent.com/koamishin/OpenSinop/main/install.sh | bash    # any shell, incl. fish
#   bash install.sh --update   # update an existing installation
#   bash install.sh --yes      # non-interactive, accept every default
#
# Environment overrides:
#   EDUFLOW_VERSION      application image tag (default: latest)
#   EDUFLOW_SOURCE_URL   source tarball used to build the image when the pull
#                        fails (default: the koamishin/OpenSinop main branch)
#   DOCKER_VERSION       pin Docker Engine (e.g. 28.5.0) when the installer installs it
#   DISTRO_FAMILY        override Linux distribution detection for Docker install
#   ADVERTISE_ADDR       address for `docker swarm init` on multi-interface hosts
#   ENDPOINT_MODE=dnsrr  force service endpoint mode (Proxmox LXC hosts without IPVS)
#   APP_MEMORY_LIMIT     memory ceiling for the application container (default: 2g)

if [ -z "${BASH_VERSION:-}" ]; then
    printf 'install.sh requires bash. Run it with: bash install.sh\n' >&2
    exit 1
fi

set -Eeuo pipefail

# True when the script itself is piped to bash (`curl … | bash`); stdin then
# carries script text and must never be read for answers.
SCRIPT_FROM_STDIN=0
[ -n "${BASH_SOURCE[0]:-}" ] || SCRIPT_FROM_STDIN=1

ROOT="$(pwd)"
ENV_FILE="$ROOT/.env"

# Swarm object names. Changing these changes the deployed stack identity.
APP_SERVICE="opensinop"
DB_SERVICE="opensinop-db"
REDIS_SERVICE="opensinop-redis"
NETWORK="opensinop-network"
DB_SECRET="opensinop_db_password"

IMAGE_VERSION="${EDUFLOW_VERSION:-latest}"
IMAGE="ghcr.io/koamishin/opensinop:${IMAGE_VERSION}"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'

if [ -t 1 ]; then COLOR=1; else COLOR=0; fi
c() { [ "$COLOR" = 1 ] && printf '%b' "$1$2$RESET" || printf '%s' "$2"; }

title() {
    echo; printf '%s\n' "$(c "$CYAN" "==============================================================================")"
    printf '%s\n' "$(c "$CYAN$BOLD" "$1")" | sed 's/^/  /'
    printf '%s\n' "$(c "$CYAN" "==============================================================================")"
}
info() { printf '  %s\n' "$1"; }
warn() { printf '  %s\n' "$(c "$YELLOW" "[warn] $1")"; }
ok() { printf '  %s\n' "$(c "$GREEN" "[ok]   $1")"; }
err() { printf '  %s\n' "$(c "$RED" "[error] $1")"; }

# Where interactive answers are read from. Empty means stdin. Callers never
# see script text as an answer, and stderr is never redirected.
INPUT_SOURCE=""

read_line() {  # read one line from the input source into the variable named in $1
    local __var="$1" __secret="${2:-}"
    if [ "$__secret" = "secret" ]; then
        if [ -n "$INPUT_SOURCE" ]; then
            IFS= read -rs "$__var" < "$INPUT_SOURCE" || true
        else
            IFS= read -rs "$__var" || true
        fi
        printf '\n' >&2
    elif [ -n "$INPUT_SOURCE" ]; then
        IFS= read -r "$__var" < "$INPUT_SOURCE" || true
    else
        # Plain fd 0: reopening /dev/stdin would reseek a redirected file on Linux.
        IFS= read -r "$__var" || true
    fi
}

ask() {
    local prompt="$1" default="${2:-}" value
    if [ "${NON_INTERACTIVE:-0}" = "1" ]; then
        printf '%s' "$default"
        return
    fi
    if [ -n "$default" ]; then
        printf '  %s [%s]: ' "$prompt" "$default" >&2
    else
        printf '  %s: ' "$prompt" >&2
    fi
    read_line value
    value="${value:-$default}"
    printf '%s' "$value"
}

ask_secret() {
    local prompt="$1" default="${2:-}" value
    if [ "${NON_INTERACTIVE:-0}" = "1" ]; then
        printf '%s' "$default"
        return
    fi
    if [ -n "$default" ]; then
        printf '  %s [hidden]: ' "$prompt" >&2
    else
        printf '  %s: ' "$prompt" >&2
    fi
    read_line value secret
    value="${value:-$default}"
    printf '%s' "$value"
}

ask_choice() {
    # prompt default choice1 choice2 ...
    local prompt="$1" default="$2"; shift 2
    local choices=("$@")
    if [ "${NON_INTERACTIVE:-0}" = "1" ]; then
        printf '%s' "$default"
        return
    fi
    printf '  %s\n' "$(c "$BOLD" "$prompt")" >&2
    local i=1
    for ch in "${choices[@]}"; do
        if [ "$ch" = "$default" ]; then
            printf '    %d. %s (default)\n' "$i" "$ch" >&2
        else
            printf '    %d. %s\n' "$i" "$ch" >&2
        fi
        i=$((i+1))
    done
    printf '  Select: ' >&2
    local value idx
    read_line value
    value="${value:-$default}"
    if [[ "$value" =~ ^[0-9]+$ ]] && [ "$value" -ge 1 ] 2>/dev/null && [ "$value" -le "${#choices[@]}" ]; then
        printf '%s' "${choices[$((value-1))]}"
        return
    fi
    for ch in "${choices[@]}"; do
        [ "$ch" = "$value" ] && { printf '%s' "$value"; return; }
    done
    printf '%s' "$default"
}

yes_no() {
    local prompt="$1" default="${2:-yes}" ans
    if [ "${NON_INTERACTIVE:-0}" = "1" ]; then
        printf '%s' "$default"
        return
    fi
    printf '  %s [%s]: ' "$prompt" "$default" >&2
    read_line ans
    ans="${ans:-$default}"
    case "$(printf '%s' "$ans" | tr '[:upper:]' '[:lower:]')" in
        y|yes|true|1) printf 'yes' ;;
        *) printf 'no' ;;
    esac
}

cmd_exists() { command -v "$1" >/dev/null 2>&1; }

port_in_use() {
    local p="$1"
    if cmd_exists ss; then
        ss -tln 2>/dev/null | grep -E -q "[:.]${p}[[:space:]]" && return 0
    elif cmd_exists netstat; then
        netstat -an 2>/dev/null | grep -E 'LISTEN(ING)?' | grep -E -q "[:.]${p}[[:space:]]" && return 0
    elif cmd_exists lsof; then
        lsof -iTCP:"$p" -sTCP:LISTEN >/dev/null 2>&1 && return 0
    fi
    if { exec 3<>"/dev/tcp/127.0.0.1/$p"; } 2>/dev/null; then
        exec 3<&- || true
        return 0
    fi
    return 1
}

redis_ping() {
    local host="$1" port="$2" pass="$3"
    if [[ -z "$pass" || "$pass" == "null" ]]; then pass=""; fi
    if cmd_exists nc; then
        local out
        if [ -n "$pass" ]; then
            out=$(printf 'AUTH %s\r\nPING\r\n' "$pass" | nc -w 3 "$host" "$port" 2>/dev/null || true)
        else
            out=$(printf 'PING\r\n' | nc -w 3 "$host" "$port" 2>/dev/null || true)
        fi
        printf '%s' "$out" | grep -qi pong && return 0 || return 1
    fi
    if ! { exec 3<>"/dev/tcp/$host/$port"; } 2>/dev/null; then
        return 2
    fi
    if [ -n "$pass" ]; then printf 'AUTH %s\r\n' "$pass" >&3; fi
    printf 'PING\r\n' >&3
    local line
    IFS= read -t 3 -r line <&3 || true
    exec 3<&- || true
    printf '%s' "$line" | grep -qi pong
}

rpc_test() {
    local url="$1"
    [ -n "$url" ] || { printf 'skipped'; return; }
    if ! cmd_exists curl; then printf 'curl not available'; return; fi
    local resp
    resp=$(curl -fsS -m 8 -H 'Content-Type: application/json' \
        -d '{"jsonrpc":"2.0","method":"eth_chainId","params":[],"id":1}' "$url" 2>/dev/null || true)
    if printf '%s' "$resp" | grep -q '"result"'; then
        printf '%s' "$resp" | sed -n 's/.*"result":"*\([^"]*\)".*/\1/p' | head -1
        return 0
    fi
    printf 'FAIL'
}

gen_key() {
    if cmd_exists openssl; then
        printf 'base64:%s' "$(openssl rand -base64 32 2>/dev/null | tr -d '\n')"
    else
        local raw
        raw=$(head -c 32 /dev/urandom | base64 | tr -d '\n')
        printf 'base64:%s' "$raw"
    fi
}

# Secure single-line password, with the same fallback chain the reference
# installers use (openssl → /dev/urandom → checksums → date+hostname).
generate_random_password() {
    local password=""
    if cmd_exists openssl; then
        password="$(openssl rand -base64 32 | tr -d '=+/' | cut -c1-32)"
    elif [ -r /dev/urandom ]; then
        password="$(tr -dc 'A-Za-z0-9' < /dev/urandom 2>/dev/null | head -c 32 || true)"
    elif cmd_exists sha256sum; then
        password="$(date +%s%N | sha256sum | base64 | head -c 32 || true)"
    elif cmd_exists shasum; then
        password="$(date +%s%N | shasum -a 256 | base64 | head -c 32 || true)"
    else
        password="$(printf '%s-%s-%s-%s' "$(date +%s%N)" "$(hostname)" "$$" "$RANDOM" | base64 | tr -d '=+/' | head -c 32 || true)"
    fi
    if [ -z "$password" ] || [ "${#password}" -lt 20 ]; then
        err "Failed to generate a random password."
        exit 1
    fi
    printf '%s' "$password"
}


# ---- Argument parsing ----
UPDATE=0
NON_INTERACTIVE=0
for arg in "$@"; do
    case "$arg" in
        --update|-u) UPDATE=1 ;;
        --yes|-y|--defaults|-d|--non-interactive) NON_INTERACTIVE=1 ;;
        --help|-h)
            cat <<'EOF'
Usage: install.sh [--update] [--yes|-y]

  Sets the application up as a Docker Swarm stack: the app, PostgreSQL 16 and
  Redis 7 run as services on an overlay network, and the database password is
  kept in a Docker secret. When Docker is missing on Linux, the installer
  offers to install it with the official convenience script on
  Debian/RHEL/openSUSE families and with the distribution package manager on
  Arch, Alpine, Void, Gentoo and their derivatives.

  --update, -u   Update an existing installation (pull or rebuild the image
                 and roll the application service). When the registry image
                 cannot be pulled, the installer builds it from the public
                 source tree instead.
  --yes, -y      Non-interactive: accept every default. Also NON_INTERACTIVE=1.

Environment:
  EDUFLOW_VERSION      application image tag (default: latest)
  EDUFLOW_SOURCE_URL   source tarball used for the fallback image build
                       (default: koamishin/OpenSinop main branch tarball)
  DOCKER_VERSION       pin Docker Engine (e.g. 28.5.0) when it gets installed
  DISTRO_FAMILY        override distribution detection for the Docker install
                       (debian|rhel|suse|arch|alpine|void|gentoo|nixos)
  ADVERTISE_ADDR       address for `docker swarm init` on multi-interface hosts
  ENDPOINT_MODE=dnsrr  force service endpoint mode (LXC hosts without IPVS)
  APP_MEMORY_LIMIT     memory ceiling for the application container (default: 2g)
EOF
            exit 0
            ;;
    esac
done

# Choose where answers come from. When the script is piped into bash, stdin
# holds script text, so answers must come from the terminal instead.
if [ "$NON_INTERACTIVE" = "0" ] && [ ! -t 0 ] && [ "$SCRIPT_FROM_STDIN" = "1" ]; then
    if { true < /dev/tty; } 2>/dev/null; then
        INPUT_SOURCE="/dev/tty"
    else
        NON_INTERACTIVE=1
        warn "No terminal available to read answers; using defaults (like --yes)."
    fi
fi

env_value() {
    local key="$1" value=""
    if [ -f "$ENV_FILE" ]; then
        value="$(grep -E "^${key}=" "$ENV_FILE" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' || true)"
    fi
    printf '%s' "$value"
}

# Memory ceiling for the application container. docker/php.ini sets
# memory_limit = -1 so an attacker cannot trigger the CVE-2026-109618 out-of-memory
# condition at a predictable moment; this container limit is the availability
# boundary that replaces it.
APP_MEMORY_LIMIT="${APP_MEMORY_LIMIT:-$(env_value APP_MEMORY_LIMIT)}"
APP_MEMORY_LIMIT="${APP_MEMORY_LIMIT:-2g}"


# ---- Docker Engine ----
DOCKER_CMD=(docker)   # becomes (sudo docker) when the daemon needs elevation
SUDO=()

resolve_docker() {
    if ! cmd_exists docker; then
        return 1
    fi
    if docker info >/dev/null 2>&1; then
        DOCKER_CMD=(docker)
        return 0
    fi
    if [ "$(id -u)" != "0" ] && cmd_exists sudo && sudo docker info >/dev/null 2>&1; then
        DOCKER_CMD=(sudo docker)
        warn "Docker needs elevated permissions; using sudo for this run."
        info "Add your user to the 'docker' group and re-login to drop sudo: sudo usermod -aG docker $(id -un)"
        return 0
    fi
    return 1
}

detect_os_family() {  # echoes debian|rhel|suse|arch|alpine|void|gentoo|nixos|unknown
    if [ -n "${DISTRO_FAMILY:-}" ]; then
        printf '%s' "$DISTRO_FAMILY"
        return
    fi
    local os_id="" os_like=""
    if [ -r /etc/os-release ]; then
        os_id="$(. /etc/os-release 2>/dev/null && printf '%s' "${ID:-}" || true)"
        os_like="$(. /etc/os-release 2>/dev/null && printf '%s' "${ID_LIKE:-}" || true)"
    fi
    os_id="$(printf '%s' "$os_id" | tr '[:upper:]' '[:lower:]')"
    os_like="$(printf '%s' "$os_like" | tr '[:upper:]' '[:lower:]')"
    case "$os_id" in
        debian|ubuntu|raspbian|linuxmint|lmde|pop|kali|elementary|zorin|neon|parrot|devuan|mx|antix|tuxedo|deepin)
            printf 'debian'; return ;;
        rhel|centos|rocky|almalinux|alma|ol|oracle|fedora|amzn|amazon|scientific|virtuozzo|cloudlinux|nobara|bazzite)
            printf 'rhel'; return ;;
        opensuse*|sles|sled|suse)
            printf 'suse'; return ;;
        arch|archlinux|archarm|garuda|manjaro|endeavouros|cachyos|arcolinux|artix|blackarch|steamos|omarchy)
            printf 'arch'; return ;;
        alpine|postmarketos)
            printf 'alpine'; return ;;
        void)
            printf 'void'; return ;;
        gentoo|funtoo)
            printf 'gentoo'; return ;;
        nixos)
            printf 'nixos'; return ;;
    esac
    case " $os_like " in
        *" ubuntu "*|*" debian "*) printf 'debian'; return ;;
        *" rhel "*|*" centos "*|*" fedora "*) printf 'rhel'; return ;;
        *" suse "*) printf 'suse'; return ;;
        *" arch "*) printf 'arch'; return ;;
        *" alpine "*) printf 'alpine'; return ;;
    esac
    # Marker files, then whatever package manager the host actually has.
    if [ -f /etc/arch-release ]; then printf 'arch'; return; fi
    if [ -f /etc/alpine-release ]; then printf 'alpine'; return; fi
    if [ -f /etc/debian_version ]; then printf 'debian'; return; fi
    if [ -f /etc/redhat-release ]; then printf 'rhel'; return; fi
    if cmd_exists pacman; then printf 'arch'; return; fi
    if cmd_exists apk; then printf 'alpine'; return; fi
    if cmd_exists apt-get; then printf 'debian'; return; fi
    if cmd_exists dnf || cmd_exists yum; then printf 'rhel'; return; fi
    if cmd_exists zypper; then printf 'suse'; return; fi
    if cmd_exists xbps-install; then printf 'void'; return; fi
    if cmd_exists emerge; then printf 'gentoo'; return; fi
    printf 'unknown'
}

native_docker_install() {  # native_docker_install <family> — from the distribution repositories
    local family="$1"
    case "$family" in
        debian)
            cmd_exists apt-get || return 1
            "${SUDO[@]}" apt-get update -qq || true
            "${SUDO[@]}" apt-get install -y docker.io || return 1
            ;;
        rhel)
            local pm="" pkg
            if cmd_exists dnf; then pm=dnf; elif cmd_exists yum; then pm=yum; else return 1; fi
            for pkg in docker docker.io moby-engine; do
                if "${SUDO[@]}" "$pm" install -y "$pkg"; then
                    break
                fi
            done
            ;;
        suse)
            cmd_exists zypper || return 1
            "${SUDO[@]}" zypper --non-interactive install docker || return 1
            ;;
        arch)
            cmd_exists pacman || return 1
            if ! "${SUDO[@]}" pacman -S --noconfirm docker; then
                "${SUDO[@]}" pacman -Sy --noconfirm docker || return 1
            fi
            ;;
        alpine)
            cmd_exists apk || return 1
            "${SUDO[@]}" apk add --no-cache docker || return 1
            ;;
        void)
            cmd_exists xbps-install || return 1
            "${SUDO[@]}" xbps-install -Sy docker || return 1
            ;;
        gentoo)
            cmd_exists emerge || return 1
            "${SUDO[@]}" emerge --ask=n --quiet app-containers/docker || return 1
            ;;
        *)
            return 1
            ;;
    esac
    cmd_exists docker
}

start_docker_daemon() {
    if cmd_exists systemctl && [ -d /run/systemd/system ]; then
        "${SUDO[@]}" systemctl enable --now docker >/dev/null 2>&1 || true
    elif cmd_exists rc-service; then
        "${SUDO[@]}" rc-update add docker default >/dev/null 2>&1 || true
        "${SUDO[@]}" rc-service docker start >/dev/null 2>&1 || true
    elif cmd_exists sv; then
        "${SUDO[@]}" ln -sf /etc/sv/docker /var/service/docker >/dev/null 2>&1 || true
        "${SUDO[@]}" sv start docker >/dev/null 2>&1 || true
    elif cmd_exists service; then
        "${SUDO[@]}" service docker start >/dev/null 2>&1 || true
    fi
}

wait_for_docker_daemon() {  # up to ~15s for the daemon socket to answer
    local attempt
    local probe=(docker)
    if [ "${#SUDO[@]}" -gt 0 ]; then
        probe=("${SUDO[@]}" docker)
    fi
    for ((attempt = 0; attempt < 15; attempt++)); do
        if "${probe[@]}" info >/dev/null 2>&1; then
            return 0
        fi
        sleep 1
    done
    return 1
}

fetch_and_build_image() {  # build $IMAGE from the public source tarball
    local url="${EDUFLOW_SOURCE_URL:-https://codeload.github.com/koamishin/OpenSinop/tar.gz/refs/heads/main}"
    local dir
    if ! cmd_exists curl; then
        err "curl is required to download the application source."
        return 1
    fi
    if ! dir="$(mktemp -d "${TMPDIR:-/tmp}/opensinop-source.XXXXXX")"; then
        err "Could not create a temporary directory for the source build."
        return 1
    fi
    info "Downloading the application source from $url ..."
    if ! curl -fsSL "$url" | tar -xz -C "$dir" --strip-components=1; then
        err "Could not download the application source."
        rm -rf "$dir"
        return 1
    fi
    if [ ! -f "$dir/docker/Dockerfile" ]; then
        err "The downloaded source tree does not contain docker/Dockerfile."
        rm -rf "$dir"
        return 1
    fi
    info "Building $IMAGE from source; this can take several minutes ..."
    if ! "${DOCKER_CMD[@]}" build -t "$IMAGE" -f "$dir/docker/Dockerfile" "$dir"; then
        err "docker build failed."
        rm -rf "$dir"
        return 1
    fi
    rm -rf "$dir"
    ok "Application image built from source."
    return 0
}

install_docker() {
    if [ "$(uname)" = "Darwin" ]; then
        err "Docker is not installed."
        info "Install Docker Desktop for Mac, then re-run this installer:"
        info "https://docs.docker.com/desktop/setup/install/mac-install/"
        return 1
    fi
    if [ "$(id -u)" = "0" ]; then
        SUDO=()
    elif cmd_exists sudo; then
        SUDO=(sudo)
    else
        err "Installing Docker requires root."
        info "Re-run this installer as root (or with sudo), or install Docker Engine manually:"
        info "https://docs.docker.com/engine/install/"
        return 1
    fi

    local family
    family="$(detect_os_family)"
    info "Detected platform $(uname -s)/$(uname -m), distribution family: $family."

    if [ "$family" = "nixos" ]; then
        err "NixOS manages system services declaratively; this script will not change your configuration."
        info "Enable Docker in configuration.nix and run nixos-rebuild switch:"
        info "  virtualisation.docker.enable = true;"
        return 1
    fi

    if [ "$(yes_no "Docker is not installed. Install Docker Engine now?" "yes")" != "yes" ]; then
        err "Docker is required for this installation."
        info "Install Docker Engine, then re-run: https://docs.docker.com/engine/install/"
        return 1
    fi

    title "Installing Docker Engine"
    local script_ok=0
    case "$family" in
        debian|rhel|suse)
            # The official convenience script covers these families. Everywhere
            # else (and whenever it fails) the distribution packages are used.
            if ! cmd_exists curl; then
                warn "curl is not available; using the $family package manager instead."
            else
                local version_args=()
                if [ -n "${DOCKER_VERSION:-}" ]; then
                    version_args=(--version "$DOCKER_VERSION")
                    info "Pinning Docker Engine to $DOCKER_VERSION."
                fi
                info "Running the official convenience script (https://get.docker.com) ..."
                if curl -fsSL https://get.docker.com | "${SUDO[@]}" sh -s -- "${version_args[@]}"; then
                    script_ok=1
                else
                    warn "The convenience script did not succeed; using the $family package manager instead."
                fi
            fi
            ;;
    esac

    if [ "$script_ok" = 1 ]; then
        if [ -n "${DOCKER_VERSION:-}" ] && cmd_exists apt-mark; then
            "${SUDO[@]}" apt-mark hold docker-ce docker-ce-cli docker-ce-rootless-extras >/dev/null 2>&1 || true
            info "Held Docker packages at $DOCKER_VERSION (apt-mark hold)."
        fi
    else
        if [ -n "${DOCKER_VERSION:-}" ]; then
            warn "Version pinning only applies to the official script; installing the distribution package."
        fi
        if [ "$family" != "unknown" ]; then
            info "Installing Docker from the $family distribution repositories ..."
        fi
        if ! native_docker_install "$family"; then
            err "Could not install Docker automatically on this system."
            info "Install Docker Engine manually, then re-run: https://docs.docker.com/engine/install/"
            info "Distribution packages: apt-get install docker.io | dnf install docker | pacman -S docker | apk add docker | zypper install docker"
            return 1
        fi
    fi

    start_docker_daemon
    if ! wait_for_docker_daemon; then
        warn "Docker was installed but the daemon has not answered yet; continuing."
    fi
    if [ "${#SUDO[@]}" -gt 0 ]; then
        if cmd_exists usermod; then
            "${SUDO[@]}" usermod -aG docker "$(id -un)" >/dev/null 2>&1 || true
        elif cmd_exists addgroup; then
            "${SUDO[@]}" addgroup "$(id -un)" docker >/dev/null 2>&1 || true
        fi
    fi
    ok "Docker Engine installed."
    return 0
}

ensure_docker() {
    if ! cmd_exists docker; then
        if ! install_docker; then
            return 1
        fi
    fi
    if resolve_docker; then
        return 0
    fi
    err "Docker is installed, but the daemon is not reachable."
    info "Start Docker and make sure your user can access it (add yourself to the 'docker' group or use sudo)."
    return 1
}


# ---- Docker Swarm ----
is_proxmox_lxc() {
    if [ "${container:-}" = "lxc" ]; then
        return 0
    fi
    if grep -q "container=lxc" /proc/1/environ 2>/dev/null; then
        return 0
    fi
    return 1
}

detect_private_ip() {
    if cmd_exists ip; then
        ip -o -4 addr show scope global 2>/dev/null \
            | awk '$2 !~ /^(docker|br-|veth)/ {print $4}' \
            | cut -d/ -f1 \
            | grep -E '^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.)' \
            | head -n1 \
            || true
    fi
}

ensure_swarm() {
    local state control advertise init_args=()
    state="$("${DOCKER_CMD[@]}" info --format '{{.Swarm.LocalNodeState}}' 2>/dev/null || true)"
    control="$("${DOCKER_CMD[@]}" info --format '{{.Swarm.ControlAvailable}}' 2>/dev/null || true)"
    if [ "$state" = "active" ] && [ "$control" = "true" ]; then
        ok "Docker Swarm is active on this node."
        return 0
    fi
    if [ "$state" = "active" ]; then
        err "This Docker node is a Swarm worker; services must be created on a manager."
        info "Run this installer on the Swarm manager, or leave the swarm and re-run: docker swarm leave"
        return 1
    fi
    advertise="${ADVERTISE_ADDR:-$(detect_private_ip)}"
    if [ -n "$advertise" ]; then
        init_args=(--advertise-addr "$advertise")
    fi
    if ! "${DOCKER_CMD[@]}" swarm init "${init_args[@]}"; then
        err "docker swarm init failed."
        info "On hosts with several network interfaces, set the address explicitly:"
        info "  ADVERTISE_ADDR=<host-ip> bash install.sh"
        return 1
    fi
    ok "Docker Swarm initialized."
}

ensure_network() {
    if "${DOCKER_CMD[@]}" network ls -q -f "name=^${NETWORK}$" 2>/dev/null | grep -q .; then
        ok "Overlay network $NETWORK already present."
        return 0
    fi
    if ! "${DOCKER_CMD[@]}" network create --driver overlay --attachable "$NETWORK" >/dev/null; then
        err "Could not create the overlay network $NETWORK."
        return 1
    fi
    ok "Created overlay network $NETWORK."
}

ensure_secret() {  # ensure_secret <name> <value>
    local name="$1" value="$2"
    if [ -z "$value" ]; then
        err "Refusing to create an empty Docker secret $name."
        return 1
    fi
    if "${DOCKER_CMD[@]}" secret ls -q -f "name=^${name}$" 2>/dev/null | grep -q .; then
        ok "Docker secret $name already exists; keeping it (its value cannot be read back)."
        return 0
    fi
    if ! printf '%s' "$value" | "${DOCKER_CMD[@]}" secret create "$name" - >/dev/null; then
        err "Could not create Docker secret $name."
        return 1
    fi
    ok "Created Docker secret $name."
}

service_exists() {  # service_exists <name>
    "${DOCKER_CMD[@]}" service ls -q -f "name=^$1$" 2>/dev/null | grep -q .
}

service_container_id() {  # service_container_id <service> -> running container id or empty
    "${DOCKER_CMD[@]}" ps -q -f status=running -f "label=com.docker.swarm.service.name=$1" 2>/dev/null | head -n1 || true
}

remove_service() {  # remove_service <name>
    if service_exists "$1"; then
        info "Removing existing service $1 (volumes and data are kept)."
        "${DOCKER_CMD[@]}" service rm "$1" >/dev/null
    fi
}

wait_for_app_health() {
    local healthy=0 cid status attempt
    for ((attempt = 0; attempt < 24; attempt++)); do
        cid="$(service_container_id "$APP_SERVICE")"
        status=""
        if [ -n "$cid" ]; then
            status="$("${DOCKER_CMD[@]}" inspect --format '{{.State.Health.Status}}' "$cid" 2>/dev/null || true)"
        fi
        if [ "$status" = "healthy" ]; then
            healthy=1
            break
        fi
        if [ "$status" = "unhealthy" ]; then
            break
        fi
        sleep 5
    done
    if [ "$healthy" = 1 ]; then
        ok "Application is healthy."
        return 0
    fi
    warn "Application did not report healthy yet; see 'docker service logs $APP_SERVICE'."
    return 1
}


# ---- Update an existing installation ----
if [ "$UPDATE" = "1" ]; then
    title "OpenSinop Update (Docker Swarm)"
    if ! ensure_docker; then
        exit 1
    fi
    if [ ! -f "$ENV_FILE" ]; then
        err "No .env found in $ROOT. Run the installer first."
        exit 1
    fi
    if ! service_exists "$APP_SERVICE"; then
        err "No Swarm service named '$APP_SERVICE' found on this Docker host."
        info "Run install.sh once to create it. (Compose-based installations are not updated by this script.)"
        exit 1
    fi

    DB_CONN="$(env_value DB_CONNECTION)"; DB_CONN="${DB_CONN:-pgsql}"
    if [ "$DB_CONN" = "pgsql" ]; then
        DB_USER="$(env_value DB_USERNAME)"; DB_USER="${DB_USER:-opensinop}"
        DB_NAME="$(env_value DB_DATABASE)"; DB_NAME="${DB_NAME:-opensinop}"
        DB_CID="$(service_container_id "$DB_SERVICE")"
        if [ -n "$DB_CID" ]; then
            backup_dir="$ROOT/backups"
            mkdir -p "$backup_dir"
            backup_file="$backup_dir/opensinop-$(date +%Y%m%d-%H%M%S).sql"
            if "${DOCKER_CMD[@]}" exec "$DB_CID" sh -c "pg_dump -U '$DB_USER' '$DB_NAME'" > "$backup_file" 2>/dev/null; then
                ok "Database backup written to $backup_file"
            else
                warn "Database backup failed; continuing update."
                rm -f "$backup_file"
            fi
        fi
    elif [ "$DB_CONN" = "sqlite" ]; then
        APP_CID="$(service_container_id "$APP_SERVICE")"
        if [ -n "$APP_CID" ]; then
            backup_dir="$ROOT/backups"
            mkdir -p "$backup_dir"
            backup_file="$backup_dir/opensinop-$(date +%Y%m%d-%H%M%S).sqlite"
            DB_FILE="$(env_value DB_DATABASE)"; DB_FILE="${DB_FILE:-/app/storage/database.sqlite}"
            if "${DOCKER_CMD[@]}" exec "$APP_CID" sh -c "sqlite3 '$DB_FILE' '.backup /tmp/backup.sqlite' && cat /tmp/backup.sqlite" > "$backup_file" 2>/dev/null; then
                ok "Database backup written to $backup_file"
            fi
        fi
    fi

    UPDATE_RESOLVE_ARGS=()
    if [ -f "$ROOT/Dockerfile" ]; then
        title "Building application image"
        info "Found a local Dockerfile; building $IMAGE ..."
        if ! "${DOCKER_CMD[@]}" build -t "$IMAGE" -f "$ROOT/Dockerfile" "$ROOT"; then
            err "docker build failed."
            exit 1
        fi
        UPDATE_RESOLVE_ARGS=(--no-resolve-image)
    else
        title "Pulling latest application image"
        if ! "${DOCKER_CMD[@]}" pull "$IMAGE"; then
            warn "Could not pull $IMAGE from the registry."
            info "The image may be private or not published; building it from source instead."
            if ! fetch_and_build_image; then
                err "Could not obtain the application image."
                info "If the ghcr.io image is private, authenticate first: docker login ghcr.io (token with read:packages)."
                info "Or clone the repository and run install.sh from it so the image is built locally."
                exit 1
            fi
            UPDATE_RESOLVE_ARGS=(--no-resolve-image)
        fi
    fi

    title "Updating application service"
    if ! "${DOCKER_CMD[@]}" service update "${UPDATE_RESOLVE_ARGS[@]}" --image "$IMAGE" --limit-memory "$APP_MEMORY_LIMIT" "$APP_SERVICE"; then
        err "docker service update failed."
        exit 1
    fi
    wait_for_app_health || true
    APP_URL="$(env_value APP_URL)"; APP_URL="${APP_URL:-http://localhost:8000}"
    info "App URL: $APP_URL"
    info "Note: database migrations run automatically on container start."
    info "Note: changes to .env need a full re-run of install.sh to reach the service."
    ok "Update complete."
    exit 0
fi


# ---- Fresh installation ----
title "OpenSinop TUI Installer (bash)"
info "Interactive setup for Docker-based self-hosting."
info "The application runs as a Docker Swarm stack: app, PostgreSQL 16 and Redis 7"
info "as services on an overlay network, with the database password in a Docker secret."
info "Press Enter to accept a suggested default."
echo

if ! ensure_docker; then
    exit 1
fi
ok "Docker is ready."

# Existing .env backup
if [ -f "$ENV_FILE" ]; then
    cp "$ENV_FILE" "$ROOT/.env.previous" 2>/dev/null || true
    warn "Existing .env backed up to .env.previous"
fi

title "Application"
APP_NAME=$(ask "Application name" "OpenSinop")
APP_ENV="production"
APP_DEBUG="false"
DEFAULT_PORT="8000"
if port_in_use "$DEFAULT_PORT"; then
    for candidate in 8080 8088 8888 8008; do
        if ! port_in_use "$candidate"; then
            DEFAULT_PORT="$candidate"
            break
        fi
    done
    if [ "$DEFAULT_PORT" = "8000" ]; then
        warn "Ports 8000, 8080, 8088, 8888 and 8008 all appear to be in use."
    else
        info "Port 8000 is in use; suggesting $DEFAULT_PORT instead."
    fi
fi
APP_PORT=$(ask "Published HTTP port" "$DEFAULT_PORT")
APP_URL=$(ask "Public URL" "http://localhost:$APP_PORT")
APP_KEY="$(gen_key)"
ok "Generated APP_KEY"

title "Database"
DB_CONNECTION=$(ask_choice "Database driver" "pgsql" pgsql mysql sqlite)
if [ "$DB_CONNECTION" = "sqlite" ]; then
    # Keep SQLite inside the persisted storage volume so it survives container recreation.
    DB_HOST=""; DB_PORT=""; DB_DATABASE="/app/storage/database.sqlite"; DB_USERNAME=""; DB_PASSWORD=""
elif [ "$DB_CONNECTION" = "mysql" ]; then
    warn "No MySQL service is created; DB_HOST must point at an existing server."
    DB_HOST=$(ask "Database host" "db")
    DB_PORT=3306
    DB_DATABASE="opensinop"
    DB_USERNAME="opensinop"
    DB_PASSWORD="opensinop"
else
    DB_HOST="opensinop-db"
    DB_PORT=5432
    DB_DATABASE="opensinop"
    DB_USERNAME="opensinop"
    DB_PASSWORD="$(env_value DB_PASSWORD)"
    if [ -z "$DB_PASSWORD" ]; then
        DB_PASSWORD="$(generate_random_password)"
        ok "Generated a random database password (kept in .env and a Docker secret)."
    else
        ok "Keeping the existing database password from .env"
    fi
fi

# Common environment defaults (production)
CACHE_STORE="database"
SESSION_DRIVER="database"
QUEUE_CONNECTION="database"
REDIS_HOST="opensinop-redis"
REDIS_PORT="6379"
REDIS_PASSWORD="null"

# Mail defaults
MAIL_MAILER="log"
MAIL_HOST="127.0.0.1"
MAIL_PORT="2525"
MAIL_USERNAME=""
MAIL_PASSWORD=""
MAIL_SCHEME="null"
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="$APP_NAME"

title "School identity"
EDUFLOW_INSTITUTION=$(ask "Institution name" "My School")
EDUFLOW_COUNTRY=$(ask "Country (2-letter code)" "US" | tr '[:lower:]' '[:upper:]')
EDUFLOW_TIMEZONE=$(ask "Timezone" "UTC")
EDUFLOW_CURRENCY=$(ask "Accounting currency" "USD" | tr '[:lower:]' '[:upper:]')

title "Lepton agent (Circle + Arc)"
info "Driver selection:"
info "  • fake      - In-memory simulated ledger (no credentials needed; safe for testing/demos)"
info "  • developer - Circle Developer-Controlled Wallets over the REST API (no Circle CLI required)"
LEPTON_DRIVER=$(ask_choice "Lepton driver" "fake" fake developer)
LEPTON_CHAIN=$(ask_choice "Chain" "ARC-TESTNET" ARC-TESTNET ARC-MAINNET)
LEPTON_DRY_RUN=$(ask_choice "Dry run (no real transfers)" "true" true false)
LEPTON_TREASURY_ADDRESS=$(ask "Treasury wallet address (empty for fake)" "")
LEPTON_RPC_URL=$(ask "Arc RPC URL (empty for default)" "")

LEPTON_CIRCLE_API_KEY=""
LEPTON_ENTITY_SECRET=""
LEPTON_WALLET_SET_ID=""
if [ "$LEPTON_DRIVER" = "developer" ]; then
    title "Circle Developer REST API"
    info "Configure Developer-Controlled Wallets credentials (api.circle.com/v1/w3s):"
    LEPTON_CIRCLE_API_KEY=$(ask_secret "Circle API Key")
    LEPTON_ENTITY_SECRET=$(ask_secret "Entity Secret (32-byte / 64 hex characters)")
    LEPTON_WALLET_SET_ID=$(ask "Wallet Set ID (optional)" "")
fi

# AI providers are configured in the admin panel (Settings → AI). The wizard
# never asks for an endpoint; values already present in .env are kept as-is.
AI_PROVIDER="$(env_value AI_PROVIDER)"
AI_LOCAL_URL="$(env_value AI_LOCAL_URL)"
AI_LOCAL_MODEL="$(env_value AI_LOCAL_MODEL)"
AI_LOCAL_API_KEY="$(env_value AI_LOCAL_API_KEY)"
OPENAI_API_KEY="$(env_value OPENAI_API_KEY)"
ANTHROPIC_API_KEY="$(env_value ANTHROPIC_API_KEY)"

title "Live network checks"
rpc_target="${LEPTON_RPC_URL:-https://rpc.testnet.arc.io}"
if [ "$LEPTON_CHAIN" = "ARC-MAINNET" ] && [ -z "$LEPTON_RPC_URL" ]; then
    rpc_target="https://rpc.mainnet.arc.io"
fi
res=$(rpc_test "$rpc_target")
if [ "$res" = "FAIL" ]; then
    warn "Arc RPC check failed for $rpc_target."
elif [ -n "$res" ]; then
    ok "Arc RPC reachable ($rpc_target): chainId=$res"
fi

if [ "$LEPTON_DRIVER" = "developer" ]; then
    circle_code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 https://api.circle.com/v1/w3s/wallets 2>/dev/null || true)"
    case "$circle_code" in
        2*|4*) ok "Circle REST API reachable (HTTP $circle_code)." ;;
        *) warn "Circle REST API check failed (no response from api.circle.com)." ;;
    esac
fi

title "Review"
printf '  APP_NAME=%s\n' "$APP_NAME"
printf '  APP_ENV=%s APP_DEBUG=%s\n' "$APP_ENV" "$APP_DEBUG"
printf '  DB_CONNECTION=%s\n' "$DB_CONNECTION"
if [ "$DB_CONNECTION" = "pgsql" ]; then
    printf '  DB_PASSWORD=(kept in .env and a Docker secret)\n'
fi
printf '  CACHE_STORE=%s SESSION_DRIVER=%s QUEUE_CONNECTION=%s\n' "$CACHE_STORE" "$SESSION_DRIVER" "$QUEUE_CONNECTION"
printf '  LEPTON_DRIVER=%s CHAIN=%s\n' "$LEPTON_DRIVER" "$LEPTON_CHAIN"
echo
if [ "$(yes_no "Write this configuration and start Docker?" "yes")" != "yes" ]; then
    warn "Aborted. No changes were made."
    exit 0
fi

# Write .env
{
    cat <<EOF
APP_NAME="$APP_NAME"
APP_ENV="$APP_ENV"
APP_KEY="$APP_KEY"
APP_DEBUG="$APP_DEBUG"
APP_URL="$APP_URL"
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
BCRYPT_ROUNDS=12
LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=warning
DB_CONNECTION="$DB_CONNECTION"
DB_HOST="$DB_HOST"
DB_PORT="$DB_PORT"
DB_DATABASE="$DB_DATABASE"
DB_USERNAME="$DB_USERNAME"
DB_PASSWORD="$DB_PASSWORD"
CACHE_STORE="$CACHE_STORE"
SESSION_DRIVER="$SESSION_DRIVER"
QUEUE_CONNECTION="$QUEUE_CONNECTION"
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
MAIL_MAILER="$MAIL_MAILER"
MAIL_HOST="$MAIL_HOST"
MAIL_PORT="$MAIL_PORT"
MAIL_USERNAME="$MAIL_USERNAME"
MAIL_PASSWORD="$MAIL_PASSWORD"
MAIL_SCHEME="$MAIL_SCHEME"
MAIL_FROM_ADDRESS="$MAIL_FROM_ADDRESS"
MAIL_FROM_NAME="$MAIL_FROM_NAME"
EDUFLOW_INSTITUTION="$EDUFLOW_INSTITUTION"
EDUFLOW_COUNTRY="$EDUFLOW_COUNTRY"
EDUFLOW_TIMEZONE="$EDUFLOW_TIMEZONE"
EDUFLOW_CURRENCY="$EDUFLOW_CURRENCY"
LEPTON_DRIVER="$LEPTON_DRIVER"
LEPTON_CHAIN="$LEPTON_CHAIN"
LEPTON_DRY_RUN="$LEPTON_DRY_RUN"
LEPTON_TREASURY_ADDRESS="$LEPTON_TREASURY_ADDRESS"
LEPTON_RPC_URL="$LEPTON_RPC_URL"
LEPTON_CIRCLE_API_KEY="$LEPTON_CIRCLE_API_KEY"
LEPTON_ENTITY_SECRET="$LEPTON_ENTITY_SECRET"
LEPTON_WALLET_SET_ID="$LEPTON_WALLET_SET_ID"
LEPTON_CIRCLE_API_URL="https://api.circle.com/v1/w3s"
REDIS_HOST="$REDIS_HOST"
REDIS_PORT="$REDIS_PORT"
REDIS_PASSWORD="$REDIS_PASSWORD"
EOF
} > "$ENV_FILE"
chmod 600 "$ENV_FILE" 2>/dev/null || true
# also persist port in .env for reference
printf 'APP_PORT="%s"\n' "$APP_PORT" >> "$ENV_FILE"
# Keep advisory-provider settings an existing .env carried; a fresh install
# omits them and admins configure AI in the panel (config/ai.php defaults apply).
if [ -n "$AI_PROVIDER" ]; then printf 'AI_PROVIDER="%s"\n' "$AI_PROVIDER" >> "$ENV_FILE"; fi
if [ -n "$AI_LOCAL_URL" ]; then printf 'AI_LOCAL_URL="%s"\n' "$AI_LOCAL_URL" >> "$ENV_FILE"; fi
if [ -n "$AI_LOCAL_MODEL" ]; then printf 'AI_LOCAL_MODEL="%s"\n' "$AI_LOCAL_MODEL" >> "$ENV_FILE"; fi
if [ -n "$AI_LOCAL_API_KEY" ]; then printf 'AI_LOCAL_API_KEY="%s"\n' "$AI_LOCAL_API_KEY" >> "$ENV_FILE"; fi
if [ -n "$OPENAI_API_KEY" ]; then printf 'OPENAI_API_KEY="%s"\n' "$OPENAI_API_KEY" >> "$ENV_FILE"; fi
if [ -n "$ANTHROPIC_API_KEY" ]; then printf 'ANTHROPIC_API_KEY="%s"\n' "$ANTHROPIC_API_KEY" >> "$ENV_FILE"; fi
ok "Wrote $ENV_FILE"

title "Setting up Docker Swarm"
if ! ensure_swarm; then
    exit 1
fi

SWARM_ENDPOINT_ARGS=()
if [ "${ENDPOINT_MODE:-}" = "dnsrr" ]; then
    SWARM_ENDPOINT_ARGS=(--endpoint-mode dnsrr)
    info "Using endpoint mode dnsrr (ENDPOINT_MODE=dnsrr)."
elif is_proxmox_lxc; then
    SWARM_ENDPOINT_ARGS=(--endpoint-mode dnsrr)
    warn "Detected an LXC container; using --endpoint-mode dnsrr for Swarm services."
fi

if ! ensure_network; then
    exit 1
fi

DB_SECRET_ARGS=()
if [ "$DB_CONNECTION" = "pgsql" ]; then
    if ! ensure_secret "$DB_SECRET" "$DB_PASSWORD"; then
        exit 1
    fi
    DB_SECRET_ARGS=(--secret source="$DB_SECRET")
fi

# A re-run recreates the services with the new configuration; named volumes
# (and therefore the data) are kept.
remove_service "$APP_SERVICE"
remove_service "$DB_SERVICE"
remove_service "$REDIS_SERVICE"

LOCAL_IMAGE=0
if [ -f "$ROOT/Dockerfile" ]; then
    title "Building application image"
    info "Found a local Dockerfile; building $IMAGE ..."
    if ! "${DOCKER_CMD[@]}" build -t "$IMAGE" -f "$ROOT/Dockerfile" "$ROOT"; then
        err "docker build failed."
        exit 1
    fi
    ok "Application image built."
    LOCAL_IMAGE=1
else
    title "Pulling application image"
    info "No local build context found; fetching $IMAGE ..."
    if ! "${DOCKER_CMD[@]}" pull "$IMAGE"; then
        warn "Could not pull $IMAGE from the registry."
        info "The image may be private or not published; building it from source instead."
        if ! fetch_and_build_image; then
            err "Could not obtain the application image."
            info "If the ghcr.io image is private, authenticate first: docker login ghcr.io (token with read:packages)."
            info "Or clone the repository and run install.sh from it so the image is built locally."
            exit 1
        fi
        LOCAL_IMAGE=1
    fi
    ok "Application image is ready."
fi

RESOLVE_ARGS=()
if [ "$LOCAL_IMAGE" = 1 ]; then
    RESOLVE_ARGS=(--no-resolve-image)
fi

if [ "$DB_CONNECTION" = "pgsql" ]; then
    title "Starting PostgreSQL"
    if ! "${DOCKER_CMD[@]}" service create \
        --name "$DB_SERVICE" \
        --replicas 1 \
        --network "$NETWORK" \
        --mount type=volume,source=opensinop_db,target=/var/lib/postgresql/data \
        --secret source="$DB_SECRET" \
        --env POSTGRES_DB="$DB_DATABASE" \
        --env POSTGRES_USER="$DB_USERNAME" \
        --env POSTGRES_PASSWORD_FILE=/run/secrets/"$DB_SECRET" \
        --update-order stop-first \
        "${SWARM_ENDPOINT_ARGS[@]}" \
        postgres:16-alpine; then
        err "Could not create the $DB_SERVICE service."
        exit 1
    fi
    info "Waiting for PostgreSQL to accept connections ..."
    db_ready=0
    for ((attempt = 0; attempt < 30; attempt++)); do
        db_cid="$(service_container_id "$DB_SERVICE")"
        if [ -n "$db_cid" ] && "${DOCKER_CMD[@]}" exec "$db_cid" pg_isready -U "$DB_USERNAME" -d "$DB_DATABASE" >/dev/null 2>&1; then
            db_ready=1
            break
        fi
        sleep 2
    done
    if [ "$db_ready" = 1 ]; then
        ok "PostgreSQL is ready."
    else
        warn "PostgreSQL did not report ready yet; continuing (the application retries)."
    fi
fi

title "Starting Redis"
if ! "${DOCKER_CMD[@]}" service create \
    --name "$REDIS_SERVICE" \
    --replicas 1 \
    --network "$NETWORK" \
    --mount type=volume,source=opensinop_redis,target=/data \
    --update-order stop-first \
    "${SWARM_ENDPOINT_ARGS[@]}" \
    redis:7-alpine; then
    err "Could not create the $REDIS_SERVICE service."
    exit 1
fi
ok "Redis service created."

# Pass every value written to .env to the application service. DB_PASSWORD is
# replaced by the mounted secret (or passed directly when no database service
# is managed); APP_PORT is Compose-only and unused here.
SERVICE_ENV=()
while IFS= read -r line; do
    case "$line" in
        ''|'#'*) continue ;;
    esac
    key="${line%%=*}"
    value="${line#*=}"
    case "$key" in
        DB_PASSWORD|APP_PORT) continue ;;
    esac
    case "$value" in
        \"*\") value="${value#\"}"; value="${value%\"}" ;;
    esac
    SERVICE_ENV+=(-e "$key=$value")
done < "$ENV_FILE"
if [ "$DB_CONNECTION" = "pgsql" ]; then
    SERVICE_ENV+=(-e "DB_PASSWORD_FILE=/run/secrets/$DB_SECRET")
else
    SERVICE_ENV+=(-e "DB_PASSWORD=$DB_PASSWORD")
fi

title "Starting application"
if ! "${DOCKER_CMD[@]}" service create \
    --name "$APP_SERVICE" \
    --replicas 1 \
    --network "$NETWORK" \
    --mount type=volume,source=opensinop_storage,target=/app/storage \
    --mount type=volume,source=opensinop_cache,target=/app/bootstrap/cache \
    --publish mode=host,published="$APP_PORT",target=8000 \
    --health-cmd healthcheck \
    --health-interval 10s \
    --health-timeout 5s \
    --health-retries 12 \
    --health-start-period 60s \
    --limit-memory "$APP_MEMORY_LIMIT" \
    --update-parallelism 1 \
    --update-order stop-first \
    "${SWARM_ENDPOINT_ARGS[@]}" \
    "${RESOLVE_ARGS[@]}" \
    "${DB_SECRET_ARGS[@]}" \
    "${SERVICE_ENV[@]}" \
    "$IMAGE"; then
    err "Could not create the $APP_SERVICE service."
    exit 1
fi
ok "Application service created."

title "Health check"
wait_for_app_health || true

title "Next steps"
info "Open the app at $APP_URL"
info "Services: docker service ls"
info "Logs: docker service logs $APP_SERVICE"
info "Create the first administrator:"
printf '  %s\n' "docker exec -it \"\$(docker ps -q -f label=com.docker.swarm.service.name=$APP_SERVICE | head -n1)\" php artisan app:bootstrap-admin --name=\"School Operator\" --email=\"operator@example.org\""
info "Circle/Arc features require the Circle CLI and arc-canteen on your workflow host."
ok "Installation complete."
