<?php

namespace App\Services;

use App\Support\BinaryDownloader;
use App\Support\Instance;
use App\Support\ProcessManager;
use App\Support\ServiceOpener;
use App\Support\StackdPaths;
use RuntimeException;

class MySqlService extends AbstractService
{
    public function __construct(
        StackdPaths $paths,
        ProcessManager $processes,
        BinaryDownloader $binaries,
        private readonly ServiceOpener $opener,
    ) {
        parent::__construct($paths, $processes, $binaries);
    }

    public static function type(): string
    {
        return 'mysql';
    }

    public static function displayName(): string
    {
        return 'MySQL';
    }

    public function defaultPort(): int
    {
        return 3306;
    }

    public function availableVersions(): array
    {
        return ['8.4', '8.0', 'latest'];
    }

    protected function provision(Instance $instance): void
    {
        $this->installIfNeeded($instance);

        $dataDir = $this->paths->dataDir($instance->service, $instance->name);
        $configPath = $this->configPath($instance);
        $socket = $this->socketPath($instance);

        if (! file_exists($dataDir.'/mysql')) {
            $this->initializeDataDirectory($instance);
        }

        file_put_contents($configPath, $this->buildConfig($instance, $dataDir, $socket));
    }

    public function start(Instance $instance): void
    {
        if ($this->isRunning($instance)) {
            throw new RuntimeException('MySQL is already running.');
        }

        $this->installIfNeeded($instance);

        $mysqld = $this->mysqldPath($instance);

        $this->processes->start(
            command: [$mysqld, '--defaults-file='.$this->configPath($instance)],
            pidFile: $this->pidFile($instance),
            logFile: $this->outputLog($instance),
        );
    }

    public function stop(Instance $instance): void
    {
        $this->processes->stop($this->pidFile($instance));
    }

    public function envVariables(Instance $instance): array
    {
        $database = (string) $instance->option('database', 'laravel');
        $username = (string) $instance->option('username', 'root');
        $password = (string) $instance->option('password', '');

        return [
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $this->bindAddress(),
            'DB_PORT' => (string) $instance->port,
            'DB_DATABASE' => $database,
            'DB_USERNAME' => $username,
            'DB_PASSWORD' => $password,
        ];
    }

    public function openInDatabaseClient(Instance $instance): void
    {
        $this->opener->openDatabase(
            driver: 'mysql',
            host: $this->bindAddress(),
            port: $instance->port,
            database: (string) $instance->option('database', 'laravel'),
            user: (string) $instance->option('username', 'root'),
            password: (string) $instance->option('password', '') ?: null,
            name: "stackd {$instance->id()}",
        );
    }

    public function statusDetails(Instance $instance): array
    {
        return array_merge(parent::statusDetails($instance), [
            'socket' => $this->socketPath($instance),
            'database' => (string) $instance->option('database', 'laravel'),
            'version' => $this->resolveVersionKey($instance),
        ]);
    }

    private function initializeDataDirectory(Instance $instance): void
    {
        $mysqld = $this->mysqldPath($instance);
        $dataDir = $this->paths->dataDir($instance->service, $instance->name);
        $basedir = $this->basedir($instance);

        $this->processes->runOrFail([
            $mysqld,
            '--initialize-insecure',
            '--datadir='.$dataDir,
            '--basedir='.$basedir,
        ]);
    }

    private function buildConfig(Instance $instance, string $dataDir, string $socket): string
    {
        $bind = $this->bindAddress();
        $port = $instance->port;
        $logDir = $this->paths->logsDir($instance->service, $instance->name);

        return <<<CNF
[mysqld]
bind-address = {$bind}
port = {$port}
datadir = {$dataDir}
socket = {$socket}
pid-file = {$this->pidFile($instance)}
log-error = {$logDir}/error.log
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
sql_mode = STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION
skip-name-resolve
basedir = {$this->basedir($instance)}
CNF;
    }

    private function configPath(Instance $instance): string
    {
        return $this->paths->instance($instance->service, $instance->name).'/my.cnf';
    }

    private function socketPath(Instance $instance): string
    {
        return $this->paths->instance($instance->service, $instance->name).'/mysql.sock';
    }

    private function installIfNeeded(Instance $instance): void
    {
        if (is_executable($this->mysqldPath($instance))) {
            return;
        }

        $versionKey = $this->resolveVersionKey($instance);
        $download = $this->downloadConfig($versionKey);

        if ($download === []) {
            throw new RuntimeException("Unsupported MySQL version [{$versionKey}].");
        }

        $arch = $this->binaries->machineArch();
        $url = $download[$arch] ?? null;

        if ($url === null) {
            throw new RuntimeException("MySQL is not available for architecture [{$arch}].");
        }

        $destination = $this->versionDirectory($instance);
        $archiveName = basename(parse_url($url, PHP_URL_PATH));

        $this->binaries->installFromTarball($url, $destination, $archiveName);

        if (! is_executable($this->mysqldPath($instance))) {
            throw new RuntimeException('MySQL download completed but mysqld was not found.');
        }
    }

    private function mysqldPath(Instance $instance): string
    {
        $basedir = $this->basedir($instance);

        return $basedir.'/bin/mysqld';
    }

    private function basedir(Instance $instance): string
    {
        $versionKey = $this->resolveVersionKey($instance);
        $release = $this->downloadConfig($versionKey)['release'] ?? null;

        if ($release === null) {
            throw new RuntimeException("Unsupported MySQL version [{$versionKey}].");
        }

        $arch = $this->binaries->machineArch();
        $macArch = $arch === 'arm64' ? 'arm64' : 'x86_64';

        return $this->versionDirectory($instance)."/mysql-{$release}-macos15-{$macArch}";
    }

    private function versionDirectory(Instance $instance): string
    {
        return $this->paths->binary('mysql', $this->resolveVersionKey($instance));
    }

    private function resolveVersionKey(Instance $instance): string
    {
        $version = $instance->version ?? 'latest';

        if ($version === 'latest') {
            $downloads = config('stackd.downloads.mysql', []);

            return $downloads['latest'] ?? '8.4';
        }

        return $version;
    }

    /**
     * @return array<string, mixed>
     */
    private function downloadConfig(string $versionKey): array
    {
        $downloads = config('stackd.downloads.mysql', []);

        $config = $downloads[$versionKey] ?? null;

        return is_array($config) ? $config : [];
    }
}
