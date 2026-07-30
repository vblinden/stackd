# stackd

A local development service manager for macOS, inspired by DBngin and Laravel Herd Pro services. Manage global named instances of common services that any Laravel project can connect to — designed to work alongside [Laravel Valet](https://laravel.com/docs/valet).

## Features

- **Global named instances** — machine-wide services, not per-project
- **Laravel-friendly** — `stackd env --write` updates your `.env`
- **macOS native** — launchd autostart, binds to `127.0.0.1`
- **On-demand downloads** — binaries fetched from official sources when you `create` an instance
- **Extensible** — clean service abstraction for adding new services

## Supported services

| Service | Status |
|---------|--------|
| MySQL | ✅ Implemented |
| MariaDB | ✅ Implemented (builds from source — no official macOS binaries) |
| PostgreSQL | ✅ Implemented |
| Valkey | ✅ Implemented |
| Mailpit | ✅ Implemented |
| Meilisearch | ✅ Implemented |
| MinIO | ✅ Implemented |

## Installation

### Local development

```bash
git clone <repo>
cd stackd
composer install
./stackd list
```

### Global via Composer (planned)

```bash
composer global require stackd/stackd
```

## Quick start

```bash
# Create instances
stackd create mysql --name=laravel
stackd create valkey --name=cache
stackd create mailpit

# Start services
stackd start mysql laravel
stackd start valkey cache
stackd start mailpit

# Inside a Laravel project
stackd env --write

# Open UIs
stackd open mysql laravel    # TablePlus
stackd open mailpit          # Browser

# Autostart on login
stackd autostart add mysql laravel
stackd autostart add valkey cache
stackd autostart enable
```

## Commands

| Command | Description |
|---------|-------------|
| `stackd create <service>` | Create a new instance |
| `stackd start <service> [name]` | Start an instance |
| `stackd stop <service> [name]` | Stop an instance |
| `stackd restart <service> [name]` | Restart an instance |
| `stackd status` | Show all instance status |
| `stackd list` | List all instances |
| `stackd delete <service> [name]` | Delete an instance |
| `stackd env [service] [name]` | Print `.env` lines |
| `stackd env --write` | Write to current Laravel `.env` |
| `stackd doctor` | Check install health and connectivity |
| `stackd logs <service> [name]` | View logs |
| `stackd open <service> [name]` | Open TablePlus or web UI |
| `stackd autostart enable\|disable\|add\|remove\|list` | Manage login autostart |

## Data layout

```
~/.stackd/
├── registry.json          # Instance metadata
├── autostart.json         # Autostart configuration
├── binaries/              # Downloaded service binaries
├── instances/
│   ├── mysql/laravel/
│   │   ├── data/
│   │   ├── logs/
│   │   └── metadata.json
│   └── ...
└── logs/
```

## Requirements

- macOS (launchd support)
- PHP 8.2+
- Xcode Command Line Tools (for compiling Valkey / MariaDB from source)
- `cmake` (required to build MariaDB from source)
- `ext-pcntl` recommended for animated spinners (Laravel Prompts)

## License

MIT
