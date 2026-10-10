#!/bin/bash
# Keeps one shared, read-only copy of the Hytale server files on a Wings node.
#
# Layout (mounted into containers at /mnt/hytale, read-only):
#   $MOUNT_SOURCE/releases/<version>/{Server/,Assets.zip,VERSION}
#   $MOUNT_SOURCE/current -> releases/<version>      (switched atomically)
#   $MOUNT_SOURCE/bin/hytale-start.sh                (launcher used by the egg)
#
# Running servers keep the release they started with; they pick up a new
# release on their next restart. Older releases are pruned, keeping $KEEP.
#
# Usage:
#   hytale-files-sync.sh --login   First run: sign in to the Hytale Downloader (device code).
#   hytale-files-sync.sh           Download a new release if one is available.
#   hytale-files-sync.sh --force   Download and install the current release again.
set -euo pipefail
umask 022

MOUNT_SOURCE="${HYTALE_MOUNT_SOURCE:-/var/lib/pterodactyl/mounts/hytale}"
STATE_DIR="${HYTALE_STATE_DIR:-/var/lib/hytale-hosting}"
PATCHLINE="${HYTALE_PATCHLINE:-release}"
KEEP="${HYTALE_KEEP_RELEASES:-3}"
LAUNCHER_SOURCE="${HYTALE_LAUNCHER_SOURCE:-/usr/local/share/hytale-hosting/start.sh}"
DOWNLOADER_URL="https://downloader.hytale.com/hytale-downloader.zip"

log() { printf '[hytale-sync] %s\n' "$*"; }
die() { printf '[hytale-sync] ERROR: %s\n' "$*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Run as root."
command -v curl >/dev/null && command -v unzip >/dev/null && command -v flock >/dev/null || die "curl, unzip and flock are required."
[[ "$KEEP" =~ ^[1-9][0-9]*$ ]] || die "HYTALE_KEEP_RELEASES must be a positive number."
case "$PATCHLINE" in release|pre-release) ;; *) die "HYTALE_PATCHLINE must be release or pre-release." ;; esac

MODE="sync"
case "${1:-}" in
    --login) MODE="login" ;;
    --force) MODE="force" ;;
    "") ;;
    *) die "Unknown option $1" ;;
esac

# Downloader credentials live here, readable by root only. Never copy this
# directory to another node: the refresh token rotates and copies break.
install -d -m 0700 "$STATE_DIR" "$STATE_DIR/home" "$STATE_DIR/bin"
install -d -m 0755 "$MOUNT_SOURCE" "$MOUNT_SOURCE/releases" "$MOUNT_SOURCE/bin"

exec 9>"$STATE_DIR/sync.lock"
flock -n 9 || die "Another sync is running."

DOWNLOADER="$STATE_DIR/bin/hytale-downloader"
if [ ! -x "$DOWNLOADER" ] || [ "$MODE" = "login" ]; then
    case "$(uname -m)" in
        x86_64|amd64) BINARY=hytale-downloader-linux-amd64 ;;
        *) die "The Hytale Downloader has no build for $(uname -m)." ;;
    esac
    log "Fetching the official Hytale Downloader..."
    tmp="$(mktemp -d)"
    curl -fsSL --proto '=https' "$DOWNLOADER_URL" -o "$tmp/downloader.zip"
    unzip -p "$tmp/downloader.zip" "$BINARY" >"$tmp/hytale-downloader"
    install -m 0700 "$tmp/hytale-downloader" "$DOWNLOADER"
    rm -rf "$tmp"
fi

run_downloader() {
    local args=(-skip-update-check)
    [ "$PATCHLINE" = "release" ] || args+=(-patchline "$PATCHLINE")
    (cd "$STATE_DIR/home" && HOME="$STATE_DIR/home" "$DOWNLOADER" "${args[@]}" "$@")
}

install_launcher() {
    if [ -f "$LAUNCHER_SOURCE" ]; then
        install -m 0755 "$LAUNCHER_SOURCE" "$MOUNT_SOURCE/bin/hytale-start.sh.new"
        mv -f "$MOUNT_SOURCE/bin/hytale-start.sh.new" "$MOUNT_SOURCE/bin/hytale-start.sh"
    else
        log "WARNING: Launcher $LAUNCHER_SOURCE not found; servers use the copy from their install."
    fi
}

if [ "$MODE" = "login" ]; then
    log "Sign in with a Hytale account that owns the game. The credentials are stored in $STATE_DIR/home."
    run_downloader -print-version
    exit 0
fi

REMOTE_VERSION="$(run_downloader -print-version 2>/dev/null | tail -n1 | tr -d '\r')"
[[ "$REMOTE_VERSION" =~ ^[A-Za-z0-9._+-]{1,64}$ ]] || die "Could not read the latest version (got '${REMOTE_VERSION}'). Run with --login first."

CURRENT_VERSION="$(cat "$MOUNT_SOURCE/current/VERSION" 2>/dev/null || true)"
install_launcher

if [ "$MODE" != "force" ] && [ "$REMOTE_VERSION" = "$CURRENT_VERSION" ]; then
    log "Up to date ($CURRENT_VERSION)."
    exit 0
fi

log "Installing Hytale $REMOTE_VERSION (current: ${CURRENT_VERSION:-none})..."
WORK="$(mktemp -d "$STATE_DIR/work.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

run_downloader -download-path "$WORK/game.zip"
[ -f "$WORK/game.zip" ] || die "The downloader did not produce a game package."

STAGE="$MOUNT_SOURCE/releases/.staging-$REMOTE_VERSION"
rm -rf "$STAGE"
mkdir -p "$STAGE"
unzip -q "$WORK/game.zip" 'Server/*' 'Assets.zip' -d "$STAGE"
[ -f "$STAGE/Server/HytaleServer.jar" ] && [ -f "$STAGE/Assets.zip" ] || die "The game package is incomplete."
printf '%s\n' "$REMOTE_VERSION" >"$STAGE/VERSION"

# Read-only for everyone, owned by root: containers can read but never change it.
chown -R root:root "$STAGE"
chmod -R u=rwX,go=rX "$STAGE"

TARGET="$MOUNT_SOURCE/releases/$REMOTE_VERSION"
rm -rf "$TARGET"
mv "$STAGE" "$TARGET"

ln -sfn "releases/$REMOTE_VERSION" "$MOUNT_SOURCE/current.new"
mv -Tf "$MOUNT_SOURCE/current.new" "$MOUNT_SOURCE/current"
log "Switched to $REMOTE_VERSION. Servers use it from their next restart."

# Prune old releases, newest first, never the current one.
mapfile -t releases < <(find "$MOUNT_SOURCE/releases" -mindepth 1 -maxdepth 1 -type d ! -name '.*' -printf '%T@ %f\n' | sort -rn | cut -d' ' -f2-)
count=0
for release in "${releases[@]}"; do
    count=$((count + 1))
    if [ "$count" -gt "$KEEP" ] && [ "$release" != "$REMOTE_VERSION" ]; then
        log "Removing old release $release."
        rm -rf "${MOUNT_SOURCE:?}/releases/$release"
    fi
done
