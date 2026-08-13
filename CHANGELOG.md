# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-08-13

### Added

- `stackd runtime [native|docker]` sets a default runtime for new instances. Native stays the default.
- `--docker` / `--native` on `stackd create` override the default for one instance.
- Docker instances pull official images, bind `127.0.0.1`, and keep data under `~/.stackd/instances/…`.
- `stackd doctor` checks the Docker CLI and daemon when Docker is in use.
- Status, list, and home show each instance’s runtime.

Existing instances keep the runtime they were created with. Native data directories are not migrated into Docker. After upgrading, run `stackd runtime` to confirm the default, then `stackd runtime docker` if you want new instances to use images.

## [1.0.10] - 2026-08-13

### Fixed

- Start-at-login now works from a LaunchAgent. The generated script used a bare `stackd` command, which `launchd` cannot find because it does not inherit your shell `PATH`.
- LaunchAgents now invoke PHP and the stackd binary with absolute paths, and set `PATH` and `HOME` on the plist.

### Added

- `stackd autostart run` starts every configured login instance without a TTY or spinner.
- `stackd doctor` fails when `autostart.sh` is missing, still calls bare `stackd`, or the LaunchAgent plist is absent.

After upgrading, run `stackd autostart enable` once so the LaunchAgent is rewritten with the new script.

## [1.0.9] - 2026-07-30

### Added

- `stackd start` and `stackd stop` with no service argument now start or stop every registered instance.
- Start skips instances that are already running.

## [1.0.8] - 2026-07-30

### Fixed

- Legacy integer PID files are treated as running for status and start guards only.
- `stop` no longer signals a process unless the stored command still matches.

## [1.0.7] - 2026-07-30

### Fixed

- Hardened instance start, stop, and PID ownership so stackd does not signal unrelated processes.

## [1.0.6] - 2026-07-30

### Changed

- Service defaults use less idle power on macOS: smaller memory footprints, less aggressive disk sync, and background process priority.

Restart existing instances with `stackd restart <service>` to pick up the new settings.

## [1.0.5] - 2026-07-30

### Changed

- `stackd env` writes `.env` by default and uses the project folder as the database name.

## [1.0.4] - 2026-07-30

### Changed

- Composer installs the committed PHAR from `builds/stackd`.
- `stackd env` applies every installed instance, including switching apps off sqlite.

## [1.0.3] - 2026-07-30

### Fixed

- Release PHARs include Laravel Zero `require-dev` packages so the binary actually runs.

## [1.0.2] - 2026-07-30

### Fixed

- PHAR builds pin Composer’s platform to PHP 8.2 so CI and end users stay compatible.

## [1.0.1] - 2026-07-30

### Changed

- Global Composer installs download the release PHAR instead of pulling Laravel Zero into the lockfile.
- Tagged releases attach the PHAR as a GitHub release asset.

## [1.0.0] - 2026-07-30

### Added

- First public release: named local instances of MySQL, MariaDB, PostgreSQL, Valkey, Mailpit, Meilisearch, and MinIO for Laravel development on macOS.

[1.1.0]: https://github.com/vblinden/stackd/compare/v1.0.10...v1.1.0
[1.0.10]: https://github.com/vblinden/stackd/compare/v1.0.9...v1.0.10
[1.0.9]: https://github.com/vblinden/stackd/compare/v1.0.8...v1.0.9
[1.0.8]: https://github.com/vblinden/stackd/compare/v1.0.7...v1.0.8
[1.0.7]: https://github.com/vblinden/stackd/compare/v1.0.6...v1.0.7
[1.0.6]: https://github.com/vblinden/stackd/compare/v1.0.5...v1.0.6
[1.0.5]: https://github.com/vblinden/stackd/compare/v1.0.4...v1.0.5
[1.0.4]: https://github.com/vblinden/stackd/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/vblinden/stackd/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/vblinden/stackd/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/vblinden/stackd/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/vblinden/stackd/releases/tag/v1.0.0
