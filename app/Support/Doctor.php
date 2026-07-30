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
    ) {}

    /**
     * @return array<int, DoctorCheck>
     */
    public function run(): array
    {
        return [
            ...$this->environmentChecks(),
            ...$this->toolingChecks(),
            ...$this->storageChecks(),
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
                status: version_compare($php, '8.2.0', '>=') ? DoctorCheck::PASS : DoctorCheck::FAIL,
                message: $php,
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
