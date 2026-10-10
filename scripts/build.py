#!/usr/bin/env python3
"""Builds the release artifacts.

  egg/egg-hytale.json                     Egg for Panel 1.x and 2.0 (PTDL_v2)
  dist/hytale-hosting-v1/                 Composer package for Panel 1.x
  dist/hytale-hosting-<version>.pteroext  Extension package for Panel 2.0

Run from anywhere: python3 scripts/build.py
"""

import json
import shutil
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
MODULE = ROOT / "module"
DIST = ROOT / "dist"

EGG_VARIABLES = [
    {
        "name": "Game Files Source",
        "description": "Where the server files come from. \"auto\" uses the shared node mount when it is attached and falls back to a local copy. \"local\" downloads the files into this server on install (requires signing in to the Hytale Downloader during installation).",
        "env_variable": "HYTALE_FILES_SOURCE",
        "default_value": "auto",
        "user_viewable": True,
        "user_editable": False,
        "rules": "required|string|in:auto,mount,local",
        "field_type": "text",
    },
    {
        "name": "Patchline",
        "description": "Release channel for local downloads: release or pre-release.",
        "env_variable": "HYTALE_PATCHLINE",
        "default_value": "release",
        "user_viewable": True,
        "user_editable": True,
        "rules": "required|string|in:release,pre-release",
        "field_type": "text",
    },
    {
        "name": "Java Memory Percentage",
        "description": "Share of the server's memory limit the Java heap may use (50-95). Keep headroom for native memory.",
        "env_variable": "JAVA_RAM_PERCENT",
        "default_value": "85",
        "user_viewable": True,
        "user_editable": True,
        "rules": "required|integer|between:50,95",
        "field_type": "text",
    },
    {
        "name": "Use AOT Cache",
        "description": "Start faster with the AOT cache shipped with the server (1 = on, 0 = off).",
        "env_variable": "USE_AOT_CACHE",
        "default_value": "1",
        "user_viewable": True,
        "user_editable": True,
        "rules": "required|boolean",
        "field_type": "text",
    },
    {
        "name": "Enable World Backups",
        "description": "Let the Hytale server create periodic world backups in ./backups (1 = on, 0 = off).",
        "env_variable": "ENABLE_BACKUPS",
        "default_value": "0",
        "user_viewable": True,
        "user_editable": True,
        "rules": "required|boolean",
        "field_type": "text",
    },
    {
        "name": "Backup Frequency",
        "description": "Minutes between world backups.",
        "env_variable": "BACKUP_FREQUENCY",
        "default_value": "30",
        "user_viewable": True,
        "user_editable": True,
        "rules": "required|integer|between:5,1440",
        "field_type": "text",
    },
    {
        "name": "Disable Sentry",
        "description": "Do not send crash reports to Hytale (1 = disabled).",
        "env_variable": "DISABLE_SENTRY",
        "default_value": "0",
        "user_viewable": True,
        "user_editable": True,
        "rules": "required|boolean",
        "field_type": "text",
    },
    {
        "name": "Accept Early Plugins",
        "description": "Allow early plugins to load (1 = allow).",
        "env_variable": "ACCEPT_EARLY_PLUGINS",
        "default_value": "0",
        "user_viewable": True,
        "user_editable": True,
        "rules": "required|boolean",
        "field_type": "text",
    },
    {
        "name": "Extra JVM Arguments",
        "description": "Additional Java arguments, separated by spaces.",
        "env_variable": "EXTRA_JVM_ARGS",
        "default_value": "",
        "user_viewable": True,
        "user_editable": True,
        "rules": "nullable|string|max:512",
        "field_type": "text",
    },
    {
        "name": "Extra Server Arguments",
        "description": "Additional HytaleServer.jar arguments. Authentication, bind address and assets are managed by the provider.",
        "env_variable": "EXTRA_SERVER_ARGS",
        "default_value": "",
        "user_viewable": True,
        "user_editable": True,
        "rules": "nullable|string|max:512",
        "field_type": "text",
    },
    {
        "name": "Require Provider Session",
        "description": "Refuse to start when the session broker cannot provide a game session (1) instead of starting unauthenticated (0).",
        "env_variable": "HYTALE_REQUIRE_BROKER",
        "default_value": "0",
        "user_viewable": False,
        "user_editable": False,
        "rules": "required|boolean",
        "field_type": "text",
    },
    {
        "name": "Session Broker URL",
        "description": "Managed by the Hytale Hosting module. Do not edit.",
        "env_variable": "HYTALE_BROKER_URL",
        "default_value": "",
        "user_viewable": False,
        "user_editable": False,
        "rules": "nullable|string|max:255",
        "field_type": "text",
    },
    {
        "name": "Session Broker Token",
        "description": "Per-server secret managed by the Hytale Hosting module. Do not edit or share.",
        "env_variable": "HYTALE_BROKER_TOKEN",
        "default_value": "",
        "user_viewable": False,
        "user_editable": False,
        "rules": "nullable|string|max:64",
        "field_type": "text",
    },
]

STARTUP = (
    "if [ -f /mnt/hytale/bin/hytale-start.sh ]; then exec /bin/bash /mnt/hytale/bin/hytale-start.sh; "
    "else exec /bin/bash ./hytale-start.sh; fi"
)


def build_egg() -> None:
    install = (ROOT / "egg" / "install.sh").read_text()
    start = (ROOT / "egg" / "start.sh").read_text().rstrip("\n")
    if "HYTALE_START_SH" in start:
        raise SystemExit("start.sh must not contain the heredoc delimiter")
    install = install.replace("__HYTALE_START_SH__", start)

    egg = {
        "_comment": "DO NOT EDIT: FILE GENERATED AUTOMATICALLY BY PTERODACTYL PANEL - PTERODACTYL.IO",
        "meta": {"version": "PTDL_v2", "update_url": None},
        "exported_at": "2026-10-10T00:00:00+00:00",
        "name": "Hytale (Provider Auth)",
        "author": "hytale-hosting@example.com",
        "description": "Hytale dedicated server with automatic provider authentication through the Hytale Hosting module's session broker. Uses shared, read-only game files from the node when the Hytale mount is attached.",
        "features": ["java_version", "pid_limit"],
        "docker_images": {"Java 25": "ghcr.io/pterodactyl/yolks:java_25"},
        "file_denylist": [],
        "startup": STARTUP,
        "config": {
            "files": "{}",
            "startup": json.dumps({"done": "Hytale Server Booted!"}, indent=4),
            "logs": "{}",
            "stop": "stop",
        },
        "scripts": {
            "installation": {
                "script": install,
                "container": "ghcr.io/pterodactyl/installers:debian",
                "entrypoint": "bash",
            }
        },
        "variables": EGG_VARIABLES,
    }

    (ROOT / "egg" / "egg-hytale.json").write_text(json.dumps(egg, indent=4, ensure_ascii=False) + "\n")


def module_files(exclude_dirs: set[str]):
    for path in sorted(MODULE.rglob("*")):
        relative = path.relative_to(MODULE)
        if path.is_dir() or any(part in exclude_dirs for part in relative.parts):
            continue
        yield path, relative


def build_v1() -> None:
    target = DIST / "hytale-hosting-v1"
    shutil.rmtree(target, ignore_errors=True)
    for path, relative in module_files({"V2"}):
        if relative.name == "extension.json":
            continue
        destination = target / relative
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(path, destination)


def build_v2() -> Path:
    manifest = json.loads((MODULE / "extension.json").read_text())
    archive = DIST / f"{manifest['id']}-{manifest['version']}.pteroext"
    archive.unlink(missing_ok=True)
    with zipfile.ZipFile(archive, "w", zipfile.ZIP_DEFLATED) as zf:
        for path, relative in module_files({"V1"}):
            if relative.name == "composer.json":
                continue
            zf.write(path, relative.as_posix())
    return archive


def main() -> None:
    DIST.mkdir(exist_ok=True)
    build_egg()
    build_v1()
    archive = build_v2()
    print("egg/egg-hytale.json")
    print("dist/hytale-hosting-v1/")
    print(archive.relative_to(ROOT))


if __name__ == "__main__":
    main()
