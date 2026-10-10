#!/bin/bash
# Hytale installation script (Hytale Hosting module).
# Runs in ghcr.io/pterodactyl/installers:debian with the server files at /mnt/server.
set -euo pipefail

SERVER_DIR=/mnt/server
FILES_SOURCE="${HYTALE_FILES_SOURCE:-auto}"
DOWNLOADER_URL="https://downloader.hytale.com/hytale-downloader.zip"

log() { printf '[install] %s\n' "$*"; }

mkdir -p "$SERVER_DIR" "$SERVER_DIR/mods" "$SERVER_DIR/logs"
cd "$SERVER_DIR"

if [ "$FILES_SOURCE" = "local" ]; then
    case "$(uname -m)" in
        x86_64|amd64) BINARY=hytale-downloader-linux-amd64 ;;
        *) log "The Hytale Downloader has no build for $(uname -m). Use the shared node mount instead."; exit 1 ;;
    esac

    WORK="$(mktemp -d)"
    trap 'rm -rf "$WORK"' EXIT

    log "Downloading the official Hytale Downloader..."
    curl -fsSL --proto '=https' "$DOWNLOADER_URL" -o "$WORK/downloader.zip"
    unzip -p "$WORK/downloader.zip" "$BINARY" >"$WORK/hytale-downloader"
    chmod 0700 "$WORK/hytale-downloader"

    # Credentials stay with this server (its owner's own Hytale account).
    mkdir -p "$SERVER_DIR/.hytale-downloader"
    chmod 0700 "$SERVER_DIR/.hytale-downloader"
    export HOME="$SERVER_DIR/.hytale-downloader"

    ARGS=(-download-path "$WORK/game.zip" -skip-update-check)
    if [ -n "${HYTALE_PATCHLINE:-}" ] && [ "${HYTALE_PATCHLINE}" != "release" ]; then
        ARGS+=(-patchline "$HYTALE_PATCHLINE")
    fi

    log "Starting the download. On the first install, open the URL below and enter the code"
    log "to sign in with a Hytale account that owns the game."
    (cd "$HOME" && "$WORK/hytale-downloader" "${ARGS[@]}")

    [ -f "$WORK/game.zip" ] || { log "The download did not produce a game package."; exit 1; }

    log "Extracting server files..."
    rm -rf "$SERVER_DIR/Server"
    unzip -q -o "$WORK/game.zip" 'Server/*' 'Assets.zip' -d "$SERVER_DIR"
    [ -f "$SERVER_DIR/Server/HytaleServer.jar" ] && [ -f "$SERVER_DIR/Assets.zip" ] || { log "The game package is incomplete."; exit 1; }

    (cd "$HOME" && "$WORK/hytale-downloader" -print-version -skip-update-check 2>/dev/null | tail -n1 >"$SERVER_DIR/.hytale-version") || true
else
    log "Game files are provided by the node-wide Hytale mount (/mnt/hytale)."
fi

log "Installing the launcher..."
cat >"$SERVER_DIR/hytale-start.sh" <<'HYTALE_START_SH'
__HYTALE_START_SH__
HYTALE_START_SH
chmod 0750 "$SERVER_DIR/hytale-start.sh"

log "Installation complete."
