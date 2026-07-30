# stackd

**Local database and service manager for macOS.**

Create global named instances of MySQL, MariaDB, PostgreSQL, Valkey, Mailpit, Meilisearch, and MinIO — then connect any Laravel project to them. Built to sit alongside [Laravel Valet](https://laravel.com/docs/valet) without Docker.

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat-square)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)](LICENSE)
[![Platform](https://img.shields.io/badge/platform-macOS-lightgrey?style=flat-square)](#requirements)

## Why stackd?

- **Services** — MySQL, MariaDB, PostgreSQL, Valkey, Mailpit, Meilisearch, and MinIO
- **Global instances** — one MySQL for all your apps, not a container per project
- **Laravel-friendly** — `stackd env --write` drops the right `.env` values into your app
- **No Docker required** — downloads (or builds) native binaries on demand
- **macOS native** — binds to `127.0.0.1`, optional LaunchAgent start-at-login
- **TablePlus & browser ready** — `stackd open` launches the right client

## Default credentials

| Service | Username | Password |
|---------|----------|----------|
| MySQL / MariaDB | `root` | *(empty)* |
| PostgreSQL | `laravel` | *(empty)* |
| MinIO | `stackd` | `secretkey` |
| Meilisearch | — | random master key (shown on create) |

## Requirements

- macOS
- PHP 8.2+ with Composer
- [Xcode Command Line Tools](https://developer.apple.com/xcode/resources/) (Valkey / MariaDB compile)
- `cmake` and OpenSSL for MariaDB (stackd can install these via Homebrew when prompted)

## Installation

### Via Composer

```bash
composer global require vblinden/stackd
```

Make sure Composer's global `bin` directory is on your `PATH`.

### From source

```bash
git clone https://github.com/vblinden/stackd.git
cd stackd
composer install
./stackd doctor
```

Optionally link it onto your PATH:

```bash
sudo ln -sf "$(pwd)/stackd" /usr/local/bin/stackd
```

## Quick start

```bash
# Interactive picker
stackd create

# Or create directly
stackd create mysql
stackd create valkey
stackd create mailpit

# From a Laravel project root
stackd env --write

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
| `stackd start <service> [name]` | Start an instance |
| `stackd stop <service> [name]` | Stop an instance |
| `stackd restart <service> [name]` | Restart an instance |
| `stackd status` | Show all instances |
| `stackd list` | List instances as a table |
| `stackd delete` / `remove` / `uninstall` | Delete an instance and its data |
| `stackd env [service] [name]` | Print Laravel `.env` lines |
| `stackd env --write` | Merge into the current project's `.env` |
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

## Building a PHAR

```bash
composer install --no-dev
php stackd app:build stackd
```

## Contributing

Issues and pull requests are welcome on [GitHub](https://github.com/vblinden/stackd).

## License

[MIT](LICENSE)
