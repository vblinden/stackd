<?php

namespace App\Support;

use App\Services\ServiceRegistry;
use Symfony\Component\Process\Process;

class Doctor
{
    public function __construct(
        private readonly StackdPaths $paths,
        private readonly InstanceRepository $repository,
        private readonly InstanceManager $manager,
        private readonly ServiceRegistry $registry,
        private readonly BinaryDownloader $binaries,
        private readonly HomebrewConflict $homebrew,
        private readonly StackdConfig $config,
        private readonly DockerEngine $docker,
    ) {}

    /**
     * @return array<int, DoctorCheck>
     */
    public function run(): array
    {
        return [
            ...$this->environmentChecks(),
            ...$this->toolingChecks(),
            ...$this->homebrewChecks(),
            ...$this->storageChecks(),
            ...$this->autostartChecks(),
            ...$this->dockerChecks(),
            ...$this->endpointChecks(),
            ...$this->instanceChecks(),
            ...$this->optionalChecks(),
        ];
    }

    /**
     * @return array<int, DoctorCheck>
     */
    private function environmentChecks(): array
    {
        $os = PHP_OS_FAMILY;
        $php = PHP_VERSION;
        $phpSupported = version_compare($php, '8.2.0', '>=') && version_compare($php, '8.6.0', '<');

        return [
            new DoctorCheck(
                group: 'Environment',
                label: 'Operating system',
                status: $os === 'Darwin' ? DoctorCheck::PASS : DoctorCheck::FAIL,
                message: $os === 'Darwin' ? 'macOS' : "{$os} (stackd currently targets macOS)",
            ),
            new DoctorCheck(
                group: 'Environment',
                label: 'PHP version',
                status: $phpSupported ? DoctorCheck::PASS : DoctorCheck::FAIL,
                message: $phpSupported ? $php : "{$php} (requires PHP 8.2–8.5)",
            ),
            new DoctorCheck(
                group: 'Environment',
                label: 'pcntl extension',
                status: extension_loaded('pcntl') ? DoctorCheck::PASS : DoctorCheck::WARN,
                message: extension_loaded('pcntl')
                    ? 'Available for animated spinners'
                    : 'Missing — spinners fall back to static output',
            ),
            new DoctorCheck(
                group: 'Environment',
                label: 'posix extension',
                status: extension_loaded('posix') ? DoctorCheck::PASS : DoctorCheck::FAIL,
                message: extension_loaded('posix')
                    ? 'Available for process control'
                    : 'Required for start/stop process management',
            ),
        ];
    }

    /**
     * @return array<int, DoctorCheck>
     */
    private function toolingChecks(): array
    {
        return [
            $this->commandCheck('curl', 'Downloads service binaries'),
            $this->commandCheck('tar', 'Extracts downloaded archives'),
            $this->commandCheck('make', 'Compiles Valkey / MariaDB from source', warn: true),
            $this->commandCheck('cmake', 'Builds MariaDB from source', warn: true),
            $this->commandCheck('unzip', 'Extracts zip archives', warn: true),
        ];
    }

    /**
     * @return array<int, DoctorCheck>
     */
    private function homebrewChecks(): array
    {
        if ($this->homebrew->findBrew() === null) {
            return [
                new DoctorCheck(
                    group: 'Homebrew',
                    label: 'brew',
                    status: DoctorCheck::WARN,
                    message: 'Not found — optional, used to install build tools and detect conflicts',
                ),
            ];
        }

        $conflicts = $this->homebrew->installedConflicts();

        if ($conflicts === []) {
            return [
                new DoctorCheck(
                    group: 'Homebrew',
                    label: 'Conflicting packages',
                    status: DoctorCheck::PASS,
                    message: 'None of mysql/mariadb/postgresql/redis/valkey/…',
                ),
            ];
        }

        return [
            new DoctorCheck(
                group: 'Homebrew',
                label: 'Conflicting packages',
                status: DoctorCheck::WARN,
                message: implode(', ', $conflicts).' — may fight stackd for ports; uninstall or use stackd create',
            ),
        ];
    }

    /**
     * @return array<int, DoctorCheck>
     */
    private function storageChecks(): array
    {
        $home = $this->paths->home();
        $writable = false;

        try {
            $this->paths->ensureHome();
            $writable = is_dir($home) && is_writable($home);
        } catch (\Throwable) {
            $writable = false;
        }

        $checks = [
            new DoctorCheck(
                group: 'Storage',
                label: 'stackd home',
                status: $writable ? DoctorCheck::PASS : DoctorCheck::FAIL,
                message: $writable ? $home : "Unable to write to {$home}",
            ),
        ];

        $launchAgents = $this->paths->launchAgents();

        $checks[] = new DoctorCheck(
            group: 'Storage',
            label: 'LaunchAgents directory',
            status: is_dir($launchAgents) && is_writable($launchAgents) ? DoctorCheck::PASS : DoctorCheck::WARN,
            message: is_dir($launchAgents) && is_writable($launchAgents)
                ? $launchAgents
                : "{$launchAgents} missing or not writable — autostart may fail",
        );

        return $checks;
    }

    /**
     * @return array<int, DoctorCheck>
     */
    private function autostartChecks(): array
    {
        if (! file_exists($this->paths->autostart())) {
            return [
                new DoctorCheck(
                    group: 'Autostart',
                    label: 'Start at login',
                    status: DoctorCheck::PASS,
                    message: 'Not configured',
                ),
            ];
        }

        $script = $this->paths->home().'/autostart.sh';

        if (! is_file($script)) {
            return [
                new DoctorCheck(
                    group: 'Autostart',
                    label: 'LaunchAgent script',
                    status: DoctorCheck::FAIL,
                    message: 'autostart.sh is missing — run stackd autostart enable',
                ),
            ];
        }

        $contents = (string) file_get_contents($script);
        $usesBareStackd = str_contains($contents, "'stackd'")
            || preg_match('/^\s*stackd\s/m', $contents) === 1;

        if ($usesBareStackd) {
            return [
                new DoctorCheck(
                    group: 'Autostart',
                    label: 'LaunchAgent script',
                    status: DoctorCheck::FAIL,
                    message: 'autostart.sh calls bare `stackd`, which launchd cannot find — run stackd autostart enable',
                ),
            ];
        }

        $plist = $this->paths->launchAgentPlist('com.stackd.autostart');

        if (! is_file($plist)) {
            return [
                new DoctorCheck(
                    group: 'Autostart',
                    label: 'LaunchAgent plist',
                    status: DoctorCheck::FAIL,
                    message: 'com.stackd.autostart.plist is missing — run stackd autostart enable',
                ),
            ];
        }

        return [
            new DoctorCheck(
                group: 'Autostart',
                label: 'LaunchAgent script',
                status: DoctorCheck::PASS,
                message: $script,
            ),
        ];
    }

    /**
     * @return array<int, DoctorCheck>
     */
    private function dockerChecks(): array
    {
        $usesDocker = $this->config->runtime() === StackdConfig::RUNTIME_DOCKER;

        if (! $usesDocker) {
            foreach ($this->repository->all() as $instance) {
                if ($instance->isDocker()) {
                    $usesDocker = true;
                    break;
                }
            }
        }

        if (! $usesDocker) {
            return [];
        }

        if (! $this->docker->isAvailable()) {
            return [
                new DoctorCheck(
                    group: 'Docker',
                    label: 'docker CLI',
                    status: DoctorCheck::FAIL,
                    message: 'Not found — install Docker Desktop or run stackd runtime native',
                ),
            ];
        }

        return [
            new DoctorCheck(
                group: 'Docker',
                label: 'docker CLI',
                status: DoctorCheck::PASS,
                message: $this->docker->binary(),
            ),
            new DoctorCheck(
                group: 'Docker',
                label: 'Docker daemon',
                status: $this->docker->daemonRunning() ? DoctorCheck::PASS : DoctorCheck::FAIL,
                message: $this->docker->daemonRunning()
                    ? 'Reachable'
                    : 'Not running — open Docker Desktop and try again',
            ),
        ];
    }

    /**
     * @return array<int, DoctorCheck>
     */
    private function endpointChecks(): array
    {
        $arch = $this->binaries->architecture();
        $mysqlLatest = config('stackd.downloads.mysql.latest', '8.4');
        $mysql = config('stackd.downloads.mysql')[$mysqlLatest] ?? [];
        $machineArch = $this->binaries->machineArch();

        $endpoints = [
            'MySQL downloads' => $mysql[$machineArch] ?? null,
            'MariaDB source' => config('stackd.downloads.mariadb.url'),
            'PostgreSQL binaries' => config("stackd.downloads.postgresql.{$machineArch}"),
            'Valkey source' => config('stackd.downloads.valkey.url'),
            'Mailpit releases' => str_replace('{arch}', $arch, config('stackd.downloads.mailpit.url')),
            'Meilisearch releases' => config("stackd.downloads.meilisearch.{$machineArch}"),
            'MinIO downloads' => config("stackd.downloads.minio.{$machineArch}"),
            'GitHub API' => 'https://api.github.com',
        ];

        $checks = [];

        foreach ($endpoints as $label => $url) {
            if ($url === null) {
                $checks[] = new DoctorCheck(
                    group: 'Endpoints',
                    label: $label,
                    status: DoctorCheck::FAIL,
                    message: 'Download URL is not configured',
                );

                continue;
            }

            $result = $this->probeUrl($url);

            $checks[] = new DoctorCheck(
                group: 'Endpoints',
                label: $label,
                status: $result['ok'] ? DoctorCheck::PASS : DoctorCheck::FAIL,
                message: $result['ok']
                    ? "Reachable ({$result['code']})"
                    : ($result['error'] ?: "Unreachable ({$result['code']})"),
            );
        }

        return $checks;
    }

    /**
     * @return array<int, DoctorCheck>
     */
    private function instanceChecks(): array
    {
        $instances = $this->repository->all();

        if ($instances === []) {
            return [
                new DoctorCheck(
                    group: 'Instances',
                    label: 'Registered instances',
                    status: DoctorCheck::WARN,
                    message: 'None yet — run stackd create',
                ),
            ];
        }

        $checks = [];

        foreach ($instances as $instance) {
            $running = $this->manager->isRunning($instance);
            $address = config('stackd.bind_address').':'.$instance->port;

            if (! $running) {
                $checks[] = new DoctorCheck(
                    group: 'Instances',
                    label: $instance->id(),
                    status: DoctorCheck::WARN,
                    message: "Stopped · {$address}",
                );

                continue;
            }

            $reachable = $this->portOpen(config('stackd.bind_address'), $instance->port);

            $checks[] = new DoctorCheck(
                group: 'Instances',
                label: $instance->id(),
                status: $reachable ? DoctorCheck::PASS : DoctorCheck::FAIL,
                message: $reachable
                    ? "Running · {$address}"
                    : "Process running but {$address} is not accepting connections",
            );
        }

        return $checks;
    }

    /**
     * @return array<int, DoctorCheck>
     */
    private function optionalChecks(): array
    {
        $tablePlus = is_dir('/Applications/TablePlus.app');

        return [
            new DoctorCheck(
                group: 'Optional',
                label: 'TablePlus',
                status: $tablePlus ? DoctorCheck::PASS : DoctorCheck::WARN,
                message: $tablePlus
                    ? 'Installed — stackd open mysql supported'
                    : 'Not installed — install from https://tableplus.com for database opening',
            ),
            new DoctorCheck(
                group: 'Optional',
                label: 'Registered services',
                status: DoctorCheck::PASS,
                message: implode(', ', $this->registry->types()),
            ),
        ];
    }

    private function commandCheck(string $command, string $purpose, bool $warn = false): DoctorCheck
    {
        $process = Process::fromShellCommandline('command -v '.escapeshellarg($command));
        $process->run();
        $path = trim($process->getOutput());
        $found = $process->isSuccessful() && $path !== '';

        return new DoctorCheck(
            group: 'Tooling',
            label: $command,
            status: $found ? DoctorCheck::PASS : ($warn ? DoctorCheck::WARN : DoctorCheck::FAIL),
            message: $found ? $path : "Missing — needed to {$purpose}",
        );
    }

    /**
     * @return array{ok: bool, code: int, error: string}
     */
    private function probeUrl(string $url): array
    {
        $process = new Process([
            'curl', '-fsSIL',
            '--max-time', '10',
            '--retry', '1',
            '-A', 'stackd-doctor/1.0',
            '-o', '/dev/null',
            '-w', '%{http_code}',
            $url,
        ]);
        $process->setTimeout(20);
        $process->run();

        $code = (int) trim($process->getOutput());
        $ok = $process->isSuccessful() && $code >= 200 && $code < 400;

        return [
            'ok' => $ok,
            'code' => $code,
            'error' => trim($process->getErrorOutput()),
        ];
    }

    private function portOpen(string $host, int $port): bool
    {
        $connection = @fsockopen($host, $port, $errno, $errstr, 1);

        if (! is_resource($connection)) {
            return false;
        }

        fclose($connection);

        return true;
    }
}
