<?php

namespace App\Services;

use App\Services\Contracts\ManagesNamedDatabases;
use App\Support\BinaryDownloader;
use App\Support\DockerEngine;
use App\Support\DockerSpec;
use App\Support\Instance;
use App\Support\ProcessManager;
use App\Support\ServiceOpener;
use App\Support\StackdPaths;
use RuntimeException;
use Symfony\Component\Process\Process;

class PostgreSqlService extends AbstractService implements ManagesNamedDatabases
{
    public function __construct(
        StackdPaths $paths,
        ProcessManager $processes,
        BinaryDownloader $binaries,
        DockerEngine $docker,
        private readonly ServiceOpener $opener,
    ) {
        parent::__construct($paths, $processes, $binaries, $docker);
    }

    public static function type(): string
    {
        return 'postgresql';
    }

    public static function displayName(): string
    {
        return 'PostgreSQL';
    }

    public function defaultPort(): int
    {
        return 5432;
    }

    public function availableVersions(): array
    {
        return ['18', 'latest'];
    }

    public function dockerSpec(Instance $instance): DockerSpec
    {
        $tag = $instance->version && $instance->version !== 'latest' ? $instance->version : '18';

        return new DockerSpec(
            image: 'postgres:'.$tag,
            ports: [$instance->port => 5432],
            env: [
                'POSTGRES_USER' => (string) $instance->option('username', 'laravel'),
                'POSTGRES_PASSWORD' => (string) $instance->option('password', ''),
                'POSTGRES_DB' => (string) $instance->option('database', 'laravel'),
                'POSTGRES_HOST_AUTH_METHOD' => 'trust',
            ],
            volumes: [
                $this->paths->dataDir($instance->service, $instance->name) => '/var/lib/postgresql/data',
            ],
        );
    }

    protected function provision(Instance $instance): void
    {
        $this->installIfNeeded($instance);

        $dataDir = $this->paths->dataDir($instance->service, $instance->name);

        if (! file_exists($dataDir.'/PG_VERSION')) {
            $this->initializeDataDirectory($instance);
        }
    }

    public function start(Instance $instance): void
    {
        if ($this->isRunning($instance)) {
            throw new RuntimeException('PostgreSQL is already running.');
        }

        $this->installIfNeeded($instance);

        $postgres = $this->binPath($instance, 'postgres');
        $dataDir = $this->paths->dataDir($instance->service, $instance->name);

        $this->processes->start(
            command: [
                $postgres,
                '-D', $dataDir,
                '-h', $this->bindAddress(),
                '-p', (string) $instance->port,
                '-c', 'shared_buffers=128MB',
                '-c', 'work_mem=4MB',
                '-c', 'maintenance_work_mem=64MB',
                '-c', 'max_connections=40',
                '-c', 'wal_level=minimal',
                '-c', 'max_wal_senders=0',
                '-c', 'synchronous_commit=off',
                '-c', 'checkpoint_timeout=30min',
                '-c', 'autovacuum_max_workers=1',
            ],
            pidFile: $this->pidFile($instance),
            logFile: $this->outputLog($instance),
        );

        $this->bootstrapIfNeeded($instance);
    }

    public function stop(Instance $instance): void
    {
        $this->processes->stop($this->pidFile($instance));
    }

    public function envVariables(Instance $instance): array
    {
        return [
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => $this->bindAddress(),
            'DB_PORT' => (string) $instance->port,
            'DB_DATABASE' => (string) $instance->option('database', 'laravel'),
            'DB_USERNAME' => (string) $instance->option('username', 'laravel'),
            'DB_PASSWORD' => (string) $instance->option('password', ''),
        ];
    }

    public function credentials(Instance $instance): array
    {
        return [
            'username' => (string) $instance->option('username', 'laravel'),
            'password' => (string) $instance->option('password', ''),
        ];
    }

    public function openInDatabaseClient(Instance $instance): void
    {
        $this->opener->openDatabase(
            driver: 'postgresql',
            host: $this->bindAddress(),
            port: $instance->port,
            user: (string) $instance->option('username', 'laravel'),
            password: (string) $instance->option('password', '') ?: null,
            name: "stackd {$instance->id()}",
        );
    }

    public function statusDetails(Instance $instance): array
    {
        return array_merge(parent::statusDetails($instance), [
            'database' => (string) $instance->option('database', 'laravel'),
        ]);
    }

    public function databaseExists(Instance $instance, string $database): bool
    {
        $database = $this->assertSafeDatabaseName($database);
        $username = (string) $instance->option('username', 'laravel');

        $process = $this->processes->run([
            $this->binPath($instance, 'psql'),
            '-h', $this->bindAddress(),
            '-p', (string) $instance->port,
            '-U', $username,
            '-d', 'postgres',
            '-tAc',
            "SELECT 1 FROM pg_database WHERE datname = '{$database}'",
        ]);

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'PostgreSQL query failed.');
        }

        return trim($process->getOutput()) === '1';
    }

    public function createDatabase(Instance $instance, string $database): void
    {
        $database = $this->assertSafeDatabaseName($database);
        $username = (string) $instance->option('username', 'laravel');

        $process = new Process([
            $this->binPath($instance, 'createdb'),
            '-h', $this->bindAddress(),
            '-p', (string) $instance->port,
            '-U', $username,
            $database,
        ]);
        $process->run();

        if (! $process->isSuccessful() && ! str_contains($process->getErrorOutput(), 'already exists')) {
            throw new RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'Failed to create database.');
        }
    }

    private function assertSafeDatabaseName(string $database): string
    {
        if ($database === '' || preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1) {
            throw new RuntimeException("Invalid database name [{$database}].");
        }

        return $database;
    }

    private function initializeDataDirectory(Instance $instance): void
    {
        $initdb = $this->binPath($instance, 'initdb');
        $dataDir = $this->paths->dataDir($instance->service, $instance->name);
        $username = (string) $instance->option('username', 'laravel');

        $this->processes->runOrFail([
            $initdb,
            '-D', $dataDir,
            '--username='.$username,
            '--auth-local=trust',
            '--auth-host=trust',
            '--encoding=UTF8',
            '--locale=C',
        ]);
    }

    private function bootstrapIfNeeded(Instance $instance): void
    {
        $marker = $this->paths->instance($instance->service, $instance->name).'/.bootstrapped';

        if (file_exists($marker)) {
            return;
        }

        $this->waitUntilReady($instance);

        $database = (string) $instance->option('database', 'laravel');
        $username = (string) $instance->option('username', 'laravel');
        $createdb = $this->binPath($instance, 'createdb');

        $process = new Process([
            $createdb,
            '-h', $this->bindAddress(),
            '-p', (string) $instance->port,
            '-U', $username,
            $database,
        ]);
        $process->run();

        if (! $process->isSuccessful() && ! str_contains($process->getErrorOutput(), 'already exists')) {
            throw new RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'Failed to create database.');
        }

        file_put_contents($marker, (new \DateTimeImmutable)->format(\DateTimeInterface::ATOM));
    }

    private function waitUntilReady(Instance $instance, int $timeout = 30): void
    {
        $pgIsReady = $this->binPath($instance, 'pg_isready');
        $deadline = time() + $timeout;

        while (time() < $deadline) {
            $process = new Process([
                $pgIsReady,
                '-h', $this->bindAddress(),
                '-p', (string) $instance->port,
            ]);
            $process->run();

            if ($process->isSuccessful()) {
                return;
            }

            usleep(200_000);
        }

        throw new RuntimeException('PostgreSQL failed to become ready for bootstrapping.');
    }

    private function installIfNeeded(Instance $instance): void
    {
        if (is_executable($this->binPath($instance, 'postgres'))) {
            return;
        }

        $arch = $this->binaries->machineArch();
        $url = config("stackd.downloads.postgresql.{$arch}");

        if ($url === null) {
            throw new RuntimeException("PostgreSQL is not available for architecture [{$arch}].");
        }

        $destination = $this->paths->binary('postgresql');
        $archiveName = basename(parse_url($url, PHP_URL_PATH));
        $this->binaries->installFromTarball($url, $destination, $archiveName, 'PostgreSQL');

        if (! is_executable($this->binPath($instance, 'postgres'))) {
            throw new RuntimeException('PostgreSQL download completed but postgres was not found.');
        }
    }

    private function binPath(Instance $instance, string $binary): string
    {
        $version = config('stackd.downloads.postgresql.version');
        $triple = $this->binaries->darwinTriple();

        return $this->paths->binary('postgresql')."/postgresql-{$version}-{$triple}/bin/{$binary}";
    }
}
