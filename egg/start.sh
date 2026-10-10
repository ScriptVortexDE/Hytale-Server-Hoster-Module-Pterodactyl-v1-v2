#!/bin/bash
# Hytale launcher for Pterodactyl (Hytale Hosting module).
#
# 1. Finds the game files: the node-wide read-only mount (/mnt/hytale/current)
#    or a local copy in /home/container.
# 2. Fetches a game session from the Panel's session broker using this server's
#    own broker token and passes it to the server through the environment
#    variables documented in Hytale's Server Provider Authentication Guide.
# 3. Starts the server with Java.
#
# Tokens are never written to disk or put on a command line.

set -uo pipefail
umask 077

log() { printf '[hytale] %s\n' "$*"; }
die() { printf '[hytale] ERROR: %s\n' "$*" >&2; exit 1; }
is_true() { case "${1:-}" in 1|true|TRUE|yes|on) return 0 ;; *) return 1 ;; esac; }

cd /home/container || die "/home/container is missing."

# --- Game files ---------------------------------------------------------------

FILES_SOURCE="${HYTALE_FILES_SOURCE:-auto}"
MOUNT_DIR="${HYTALE_MOUNT_DIR:-/mnt/hytale}"
GAME_DIR=""

if [ "$FILES_SOURCE" != "local" ] && [ -f "$MOUNT_DIR/current/Server/HytaleServer.jar" ] && [ -f "$MOUNT_DIR/current/Assets.zip" ]; then
    GAME_DIR="$MOUNT_DIR/current"
    log "Using shared game files $(cat "$MOUNT_DIR/current/VERSION" 2>/dev/null || echo '') from the node."
elif [ "$FILES_SOURCE" != "mount" ] && [ -f ./Server/HytaleServer.jar ] && [ -f ./Assets.zip ]; then
    GAME_DIR="/home/container"
    log "Using local game files $(cat ./.hytale-version 2>/dev/null || echo '')."
elif [ "$FILES_SOURCE" = "mount" ]; then
    die "The shared Hytale files are not mounted at $MOUNT_DIR. Ask your provider to attach the Hytale mount to this server."
else
    die "No Hytale server files found. Reinstall the server or ask your provider to attach the Hytale mount."
fi

SERVER_JAR="$GAME_DIR/Server/HytaleServer.jar"
ASSETS_ZIP="$GAME_DIR/Assets.zip"
AOT_CACHE="$GAME_DIR/Server/HytaleServer.aot"

EXTRA_SERVER=()
if [ -n "${EXTRA_SERVER_ARGS:-}" ]; then
    read -r -a EXTRA_SERVER <<<"$EXTRA_SERVER_ARGS"
    for argument in "${EXTRA_SERVER[@]}"; do
        case "$argument" in
            --auth-mode*|--session-token*|--identity-token*|--bind*|--assets*)
                die "The server argument '${argument%%=*}' is managed by your provider and cannot be overridden." ;;
        esac
    done
fi

# --- Session tokens -----------------------------------------------------------

TOKEN_PATTERN='^[A-Za-z0-9._~+/=:-]{1,8192}$'

fetch_session() {
    local url="${HYTALE_BROKER_URL:-}" token="${HYTALE_BROKER_TOKEN:-}" uuid="${P_SERVER_UUID:-}"

    if [ -z "$url" ] || [ -z "$token" ] || [ -z "$uuid" ]; then
        log "The session broker is not configured for this server yet."
        return 1
    fi
    case "$url" in
        https://*) ;;
        http://*) log "WARNING: The session broker URL does not use HTTPS." ;;
        *) log "The session broker URL is invalid."; return 1 ;;
    esac

    local headers response code attempt=1 delay=5
    headers="$(mktemp)" && response="$(mktemp)" || return 1
    # The bearer token goes through a 0600 file instead of curl's command line.
    printf 'Authorization: Bearer %s\nX-Hytale-Server: %s\n' "$token" "$uuid" >"$headers"

    while :; do
        : >"$response"
        code="$(curl -sS --proto '=https,http' --max-time 20 --connect-timeout 10 \
            -X POST -H @"$headers" -o "$response" -w '%{http_code}' "$url" 2>/dev/null || true)"

        if [ "$code" = "200" ]; then
            break
        fi

        sed -n 's/^ERROR=//p' "$response" | head -n1 | while IFS= read -r message; do log "Session broker: $message"; done
        case "$code" in
            429|500|502|503|504|000)
                if [ "$attempt" -ge 5 ]; then
                    break
                fi
                log "Session broker unavailable (HTTP $code), retrying in ${delay}s ($attempt/5)..."
                sleep "$delay"
                attempt=$((attempt + 1))
                delay=$((delay * 2))
                ;;
            *) break ;;
        esac
    done

    rm -f "$headers"

    if [ "$code" != "200" ]; then
        rm -f "$response"
        return 1
    fi

    local key value session="" identity="" expires=""
    while IFS='=' read -r key value; do
        [[ "$value" =~ $TOKEN_PATTERN ]] || continue
        case "$key" in
            HYTALE_SERVER_SESSION_TOKEN) session="$value" ;;
            HYTALE_SERVER_IDENTITY_TOKEN) identity="$value" ;;
            HYTALE_SESSION_EXPIRES_AT) expires="$value" ;;
        esac
    done <"$response"
    rm -f "$response"

    if [ -z "$session" ] || [ -z "$identity" ]; then
        log "The session broker returned an incomplete response."
        return 1
    fi

    export HYTALE_SERVER_SESSION_TOKEN="$session"
    export HYTALE_SERVER_IDENTITY_TOKEN="$identity"
    log "Game session received${expires:+ (valid until $expires, refreshed automatically by the server)}."
    return 0
}

# The broker token is only needed for the request above; keep it away from the
# Java process and any mod running in it.
if fetch_session; then
    :
elif is_true "${HYTALE_REQUIRE_BROKER:-0}"; then
    unset HYTALE_BROKER_TOKEN
    die "Could not get a game session from the provider. Try again in a few minutes or contact support."
else
    log "Starting without provider authentication. Players cannot join until the server is authenticated;"
    log "you can authenticate it with your own Hytale account by typing: /auth login device"
fi
unset HYTALE_BROKER_TOKEN

# --- Java command -------------------------------------------------------------

RAM_PERCENT="${JAVA_RAM_PERCENT:-85}"
[[ "$RAM_PERCENT" =~ ^[0-9]{2}$ ]] || RAM_PERCENT=85

JAVA_ARGS=(-Xms128M "-XX:MaxRAMPercentage=${RAM_PERCENT}" -Dterminal.jline=false -Dterminal.ansi=true)

if is_true "${USE_AOT_CACHE:-1}" && [ -f "$AOT_CACHE" ]; then
    JAVA_ARGS+=("-XX:AOTCache=${AOT_CACHE}")
fi

if [ -n "${EXTRA_JVM_ARGS:-}" ]; then
    read -r -a EXTRA_JVM <<<"$EXTRA_JVM_ARGS"
    JAVA_ARGS+=("${EXTRA_JVM[@]}")
fi

SERVER_ARGS=(--assets "$ASSETS_ZIP" --bind "0.0.0.0:${SERVER_PORT:-5520}" --auth-mode authenticated)

if is_true "${ENABLE_BACKUPS:-0}"; then
    mkdir -p backups
    SERVER_ARGS+=(--backup --backup-dir backups)
    [[ "${BACKUP_FREQUENCY:-}" =~ ^[0-9]+$ ]] && SERVER_ARGS+=(--backup-frequency "$BACKUP_FREQUENCY")
fi
is_true "${DISABLE_SENTRY:-0}" && SERVER_ARGS+=(--disable-sentry)
is_true "${ACCEPT_EARLY_PLUGINS:-0}" && SERVER_ARGS+=(--accept-early-plugins)

[ "${#EXTRA_SERVER[@]}" -gt 0 ] && SERVER_ARGS+=("${EXTRA_SERVER[@]}")

log "Starting Hytale server on port ${SERVER_PORT:-5520}/udp."
exec java "${JAVA_ARGS[@]}" -jar "$SERVER_JAR" "${SERVER_ARGS[@]}"
