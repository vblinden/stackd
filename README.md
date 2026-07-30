# stackd

**Local database and service manager for macOS.**

Create global named instances of MySQL, MariaDB, PostgreSQL, Valkey, Mailpit, Meilisearch, and MinIO — then connect any Laravel project to them. Built to sit alongside [Laravel Valet](https://laravel.com/docs/valet) without Docker.

[![PHP Version](https://img.shields.io/badge/PHP-8.2%E2%80%938.5-777BB4?style=flat-square)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](LICENSE)
[![Platform](https://img.shields.io/badge/platform-macOS-lightgrey?style=flat-square)](#requirements)

## Why stackd?

- **Services** — MySQL, MariaDB, PostgreSQL, Valkey, Mailpit, Meilisearch, and MinIO
- **Global instances** — one MySQL for all your apps, not a container per project
- **Laravel-friendly** — `stackd env` overwrites your `.env` for every installed service (and uses your project folder as the DB name)
- **No Docker required** — downloads (or builds) native binaries on demand
- **macOS native** — binds to `127.0.0.1`, optional LaunchAgent start-at-login
- **TablePlus & browser ready** — `stackd open` launches the right client

## Requirements

- macOS
- PHP 8.2–8.5
- [Xcode Command Line Tools](https://developer.apple.com/xcode/resources/) (Valkey / MariaDB compile)
- `cmake` and OpenSSL for MariaDB (stackd can install these via Homebrew when prompted)
- Avoid Homebrew MySQL/Postgres/Redis on the same ports — `stackd create` detects them and can uninstall

## Installation

### Via Composer (recommended)

```bash
composer global require vblinden/stackd
```

This installs the prebuilt PHAR from the package (`builds/stackd`). Make sure Composer's global `bin` directory is on your `PATH`.

### PHAR

```bash
curl -fsSL https://raw.githubusercontent.com/vblinden/stackd/master/bin/install.sh | bash
```

Or install manually:

```bash
curl -fsSL https://github.com/vblinden/stackd/releases/latest/download/stackd -o stackd
chmod +x stackd
sudo mv stackd /usr/local/bin/stackd
stackd doctor
```

## Default credentials

| Service | Username | Password |
|---------|----------|----------|
| MySQL / MariaDB | `root` | *(empty)* |
| PostgreSQL | `laravel` | *(empty)* |
| MinIO | `stackd` | `secretkey` |
| Meilisearch | — | random master key (shown on create) |

## Quick start

```bash
# Interactive picker
stackd create

# Or create directly
stackd create mysql
stackd create valkey
stackd create mailpit

# From a Laravel project root — writes .env for all installed stackd services
stackd env
stackd env --show   # print only

# Open clients
stackd open mysql      # TablePlus
stackd open mailpit    # Browser

# Check health
stackd doctor
stackd status
```

`create` starts the instance immediately and can optionally add it to start-at-login.

## Commands

| Command | Description |
|---------|-------------|
| `stackd create` / `add` | Create (and start) a service instance |
| `stackd start [service] [name]` | Start an instance, or all instances when no service is given |
| `stackd stop [service] [name]` | Stop an instance, or all instances when no service is given |
| `stackd restart <service> [name]` | Restart an instance |
| `stackd status` | Show all instances |
| `stackd list` | List instances as a table |
| `stackd delete` / `remove` / `uninstall` | Delete an instance and its data |
| `stackd env [service] [name]` | Write `.env` keys for installed stackd services |
| `stackd env --show` | Print variables instead of writing |
| `stackd open <service> [name]` | Open TablePlus or the web UI |
| `stackd logs <service> [name]` | Tail instance logs |
| `stackd doctor` | Diagnose install & dependencies |
| `stackd autostart …` | Manage LaunchAgent start-at-login |

Run `stackd` with no arguments for the home screen (running services + command list).

## How it works

Instances live under `~/.stackd/`:

```text
~/.stackd/
├── registry.json       # Instance metadata
├── autostart.json      # Start-at-login entries
├── binaries/           # Downloaded / built binaries
├── instances/
│   └── mysql/default/
│       ├── data/
│       ├── logs/
│       └── …
└── logs/
```

Binaries are fetched the first time you create a service. MariaDB and Valkey compile locally; other services use official release archives.

Services are tuned for local macOS use: smaller memory footprints, less aggressive disk sync, and background process priority so idle instances are easier on battery. Restart existing instances (`stackd restart <service>`) to pick up the new settings.

## Building a PHAR

```bash
composer install
php stackd app:build stackd --build-version=v1.0.4
php builds/stackd --version
```

Commit `builds/stackd` before tagging. Packagist serves that PHAR via `composer global require`; tagged releases (`v*`) also attach it to the GitHub release.

## Contributing

Issues and pull requests are welcome on [GitHub](https://github.com/vblinden/stackd).

## License

[MIT](LICENSE)
