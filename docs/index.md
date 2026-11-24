---
layout: default
title: Metamanager Plugin
nav_order: 1
---

# Metamanager Plugin

Lossless image compression and standards-compliant metadata embedding for WordPress.

## Quick Links

- [GitHub](https://github.com/richardkentgates/metamanager-plugin)
- [Architecture Reference](https://github.com/richardkentgates/metamanager-plugin/blob/main/ARCHITECTURE.md)
- [Changelog](https://github.com/richardkentgates/metamanager-plugin/blob/main/CHANGELOG.md)
- [Contributing](https://github.com/richardkentgates/metamanager-plugin/blob/main/CONTRIBUTING.md)

## What is Metamanager?

Metamanager is a WordPress plugin that provides:

- **Lossless Image Compression** — JPEG, PNG, WebP, and video remux via OS-level daemons
- **Metadata Embedding** — EXIF, IPTC, and XMP standards-compliant metadata
- **Bidirectional Sync** — Metadata flows between WordPress and file tags
- **Real-time Dashboard** — Live job queue and history

## Requirements

- WordPress 6.2+
- PHP 8.0+
- **Metamanager daemon package** (`sudo apt install metamanager`) — installs the OS-level tools and bash daemons that process media files:

| Component | Purpose |
|-----------|---------|
| `metamanager-compress-daemon.sh` | inotifywait loop — lossless JPEG/PNG/WebP/video compression via jpegtran, optipng, cwebp, ffmpeg |
| `metamanager-meta-daemon.sh` | inotifywait loop — EXIF/IPTC/XMP metadata read and write via ExifTool |
| `metamanager-install.sh` | Server installer: OS dependencies, systemd service setup |
| systemd units | Process supervision, auto-restart, hardening (`NoNewPrivileges`, `ProtectSystem=strict`) |

> **Note:** The WordPress plugin can be installed without the daemon package, and all web/SEO features (schema, sitemaps, Open Graph, title/description) will work. However, compression and metadata embedding jobs will queue but not execute until the daemons are running.

## Installation

### 1. Add the apt repository

```bash
# Install the signing keyring (binary keyring — download as-is, do NOT dearmor)
sudo curl -fsSL https://apt.richardkentgates.com/gcm-repo-key.gpg \
    -o /usr/share/keyrings/gcm-repo-key.gpg

# Add the repository
echo "deb [signed-by=/usr/share/keyrings/gcm-repo-key.gpg] https://apt.richardkentgates.com stable main" | sudo tee /etc/apt/sources.list.d/metamanager.list

sudo apt update
```

### 2. Install the daemon package

```bash
sudo apt install metamanager
```

This installs:
- `metamanager-compress-daemon.sh` — lossless JPEG/PNG/WebP/video compression
- `metamanager-meta-daemon.sh` — EXIF/IPTC/XMP metadata read/write via ExifTool
- systemd service units with auto-restart and security hardening
- All OS-level tool dependencies (jpegtran, optipng, cwebp, ffmpeg, ExifTool)

### 3. Install the WordPress plugin

```bash
wp plugin install metamanager --activate
```

Or upload the zip via Plugins → Add New → Upload in wp-admin.

### 4. Verify

```bash
# Check daemon is running
systemctl status metamanager-compress-daemon
systemctl status metamanager-meta-daemon

# Check from WordPress
wp metamanager status --path=/srv/www/wordpress
```

---

## Test vs Release Channels

The apt server hosts two suites of daemon packages:

| Suite | Source | Use case |
|-------|--------|----------|
| `stable` | `main` branch (tagged releases) | Production |
| `test` | `test` branch (pre-release builds) | Pre-release testing |

Both suites carry the same `X.Y.Z-N` package version format (from
`debian/changelog`); the suite separates them.

### Release (stable)

```bash
# Install or upgrade to the latest stable version
sudo apt update && sudo apt install metamanager
```

### Test (pre-release)

Test builds are produced from the `test` branch. They include the latest
fixes but have not been promoted to `main` yet.

```bash
# Install from the test suite
sudo apt-get install -t test metamanager

# See all available versions
apt-cache policy metamanager
```

### How updates flow

```
dev  ──push──>  CI (ShellCheck + version bump)
                    │
                    │  workflow_dispatch
                    ▼
              test  ──merge + build .deb + deploy to apt `test` suite
                    │
                    │  workflow_dispatch
                    ▼
              main  ──merge + tag + GitHub release + deploy to apt `stable` suite
                    ▼
              apt upgrade (inside the GCM maintenance window)
```

### Plugin auto-updater

The plugin's `MM_Updater` fetches
`https://apt.richardkentgates.com/metamanager/metadata.json` (or
`metamanager-test/metadata.json` on the test channel) fresh on every WordPress
update check — WordPress rebuilds its update data on its own ~twice-daily
cadence. When a new version is published to the feed, WordPress downloads and
installs the plugin zip through the native update pipeline. A "Check for
Updates" link on the Plugins page forces an immediate re-check.

### Daemon updates

Daemon updates are handled by OS apt inside the GCM maintenance window — the
server's apt channel (test/stable) determines which version installs. The
plugin's `MM_Daemon_Updater` is display-only (reads
`/usr/local/lib/metamanager/VERSION` for the dashboard); it never triggers
updates.

## Documentation

| Section | Description |
|---------|-------------|
| [Architecture](https://github.com/richardkentgates/metamanager-plugin/blob/main/ARCHITECTURE.md) | Internal design, component map, connection points |
| [Changelog](https://github.com/richardkentgates/metamanager-plugin/blob/main/CHANGELOG.md) | Release history and changes |
| [Contributing](https://github.com/richardkentgates/metamanager-plugin/blob/main/CONTRIBUTING.md) | Development setup and PR guidelines |
| [Security](https://github.com/richardkentgates/metamanager-plugin/blob/main/SECURITY.md) | Vulnerability reporting and security model |
