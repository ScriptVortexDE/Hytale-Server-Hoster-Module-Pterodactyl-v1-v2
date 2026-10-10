#!/bin/bash
# Installs the shared Hytale files sync on a Wings node. Run from the repository
# root as root: ./node/install-node.sh
set -euo pipefail

[ "$(id -u)" -eq 0 ] || { echo "Run as root." >&2; exit 1; }
cd "$(dirname "$0")/.."

MOUNT_SOURCE="${HYTALE_MOUNT_SOURCE:-/var/lib/pterodactyl/mounts/hytale}"

install -m 0755 node/hytale-files-sync.sh /usr/local/sbin/hytale-files-sync.sh
install -d -m 0755 /usr/local/share/hytale-hosting
install -m 0644 egg/start.sh /usr/local/share/hytale-hosting/start.sh
install -m 0644 node/hytale-files-sync.service /etc/systemd/system/hytale-files-sync.service
install -m 0644 node/hytale-files-sync.timer /etc/systemd/system/hytale-files-sync.timer
install -d -m 0755 "$MOUNT_SOURCE"

if ! grep -qsF "$MOUNT_SOURCE" /etc/pterodactyl/config.yml; then
    cat <<EOF

Add the mount source to Wings' allowed_mounts in /etc/pterodactyl/config.yml:

  allowed_mounts:
    - $MOUNT_SOURCE

then run: systemctl restart wings
EOF
fi

systemctl daemon-reload

cat <<EOF

Next steps:
  1. hytale-files-sync.sh --login      (sign in once with the device code)
  2. hytale-files-sync.sh              (first download, several GB)
  3. systemctl enable --now hytale-files-sync.timer
EOF
