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

class MySqlService extends AbstractService implements ManagesNamedDatabases
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

    public function dockerSpec(Instance $instance): DockerSpec
    {
        $tag = $this->resolveVersionKey($instance);

        if ($tag === 'latest') {
            $tag = '8.4';
        }

        return new DockerSpec(
            image: 'mysql:'.$tag,
            ports: [$instance->port => 3306],
            env: [
                'MYSQL_ALLOW_EMPTY_PASSWORD' => 'yes',
                'MYSQL_DATABASE' => (string) $instance->option('database', 'laravel'),
            ],
            volumes: [
                $this->paths->dataDir($instance->service, $instance->name) => '/var/lib/mysql',
            ],
        );
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

        $dataDir = $this->paths->dataDir($instance->service, $instance->name);
        $socket = $this->socketPath($instance);
        file_put_contents($this->configPath($instance), $this->buildConfig($instance, $dataDir, $socket));

        $mysqld = $this->mysqldPath($instance);

        $this->processes->start(
            command: [$mysqld, '--defaults-file='.$this->configPath($instance)],
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

    public function credentials(Instance $instance): array
    {
        return [
            'username' => (string) $instance->option('username', 'root'),
            'password' => (string) $instance->option('password', ''),
        ];
    }

    public function openInDatabaseClient(Instance $instance): void
    {
        $this->opener->openDatabase(
            driver: 'mysql',
            host: $this->bindAddress(),
            port: $instance->port,
            user: (string) $instance->option('username', 'root'),
            password: (string) $instance->option('password', '') ?: null,
            name: "stackd {$instance->id()}",
        );
    }

    public function statusDetails(Instance $instance): array
    {
        $details = [
            'database' => (string) $instance->option('database', 'laravel'),
            'version' => $this->resolveVersionKey($instance),
        ];

        if (! $instance->isDocker()) {
            $details['socket'] = $this->socketPath($instance);
        }

        return array_merge(parent::statusDetails($instance), $details);
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

    private function bootstrapIfNeeded(Instance $instance): void
    {
        $marker = $this->bootstrapMarkerPath($instance);

        if (file_exists($marker)) {
            return;
        }

        $this->waitUntilReady($instance);

        $username = (string) $instance->option('username', 'root');
        $password = (string) $instance->option('password', '');
        $database = (string) $instance->option('database', 'laravel');
        $passwordSql = $password === '' ? "''" : "'".addslashes($password)."'";

        foreach ([
            "CREATE USER IF NOT EXISTS '{$username}'@'127.0.0.1' IDENTIFIED BY {$passwordSql}",
            "GRANT ALL PRIVILEGES ON *.* TO '{$username}'@'127.0.0.1' WITH GRANT OPTION",
            "CREATE DATABASE IF NOT EXISTS `{$database}`",
            'FLUSH PRIVILEGES',
        ] as $statement) {
            $this->runSql($instance, $statement);
        }

        file_put_contents($marker, (new \DateTimeImmutable)->format(\DateTimeInterface::ATOM));
    }

    public function databaseExists(Instance $instance, string $database): bool
    {
        $database = $this->assertSafeDatabaseName($database);
        $output = $this->querySql(
            $instance,
            "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '{$database}'",
        );

        return trim($output) !== '';
    }

    public function createDatabase(Instance $instance, string $database): void
    {
        $database = $this->assertSafeDatabaseName($database);
        $this->runSql($instance, "CREATE DATABASE IF NOT EXISTS `{$database}`");
    }

    /**
     * @param  list<string>  $args
     * @return list<string>
     */
    public function sqlClientCommand(Instance $instance, array $args = []): array
    {
        if ($instance->isDocker()) {
            return array_merge([
                $this->docker->binary(),
                'exec',
                $this->docker->containerName($instance),
                'mysql',
                '-uroot',
            ], $args);
        }

        return array_merge([
            $this->mysqlClientPath($instance),
            '-S', $this->socketPath($instance),
            '-u', 'root',
        ], $args);
    }

    private function waitUntilReady(Instance $instance, int $timeout = 30): void
    {
        if ($instance->isDocker()) {
            $this->docker->waitUntilReady($instance, $timeout);

            return;
        }

        $socket = $this->socketPath($instance);
        $deadline = time() + $timeout;

        while (time() < $deadline) {
            if (file_exists($socket)) {
                $process = new Process($this->sqlClientCommand($instance, ['-e', 'SELECT 1']));
                $process->run();

                if ($process->isSuccessful()) {
                    return;
                }
            }

            usleep(200_000);
        }

        throw new RuntimeException('MySQL failed to become ready for bootstrapping.');
    }

    private function runSql(Instance $instance, string $sql): void
    {
        $this->processes->runOrFail($this->sqlClientCommand($instance, ['-e', $sql]));
    }

    private function querySql(Instance $instance, string $sql): string
    {
        $process = $this->processes->run($this->sqlClientCommand($instance, ['-N', '-B', '-e', $sql]));

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'MySQL query failed.');
        }

        return $process->getOutput();
    }

    private function assertSafeDatabaseName(string $database): string
    {
        if ($database === '' || preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1) {
            throw new RuntimeException("Invalid database name [{$database}].");
        }

        return $database;
    }

    private function mysqlClientPath(Instance $instance): string
    {
        $client = $this->basedir($instance).'/bin/mysql';

        if (! is_executable($client)) {
            throw new RuntimeException('MySQL client binary was not found.');
        }

        return $client;
    }

    private function bootstrapMarkerPath(Instance $instance): string
    {
        return $this->paths->instance($instance->service, $instance->name).'/.bootstrapped';
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
skip-log-bin
performance_schema = OFF
innodb_buffer_pool_size = 128M
innodb_log_buffer_size = 8M
innodb_flush_log_at_trx_commit = 2
table_open_cache = 200
thread_cache_size = 8
max_connections = 50
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

        $this->binaries->installFromTarball($url, $destination, $archiveName, 'MySQL');

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
