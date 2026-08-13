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

use function Laravel\Prompts\confirm;

class MariaDbService extends AbstractService implements ManagesNamedDatabases
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
        return 'mariadb';
    }

    public static function displayName(): string
    {
        return 'MariaDB';
    }

    public function defaultPort(): int
    {
        return 3307;
    }

    public function availableVersions(): array
    {
        return ['11.4', 'latest'];
    }

    public function dockerSpec(Instance $instance): DockerSpec
    {
        $tag = $instance->version && $instance->version !== 'latest' ? $instance->version : '11.4';

        return new DockerSpec(
            image: 'mariadb:'.$tag,
            ports: [$instance->port => 3306],
            env: [
                'MARIADB_ALLOW_EMPTY_ROOT_PASSWORD' => '1',
                'MARIADB_DATABASE' => (string) $instance->option('database', 'laravel'),
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
            $this->binaries->progress()->provisioning('MariaDB', function () use ($instance): void {
                $this->initializeDataDirectory($instance);
            });
        }

        file_put_contents($configPath, $this->buildConfig($instance, $dataDir, $socket));
    }

    public function start(Instance $instance): void
    {
        if ($this->isRunning($instance)) {
            throw new RuntimeException('MariaDB is already running.');
        }

        $this->installIfNeeded($instance);

        $dataDir = $this->paths->dataDir($instance->service, $instance->name);
        $socket = $this->socketPath($instance);
        file_put_contents($this->configPath($instance), $this->buildConfig($instance, $dataDir, $socket));

        $this->processes->start(
            command: [$this->serverBinary(), '--defaults-file='.$this->configPath($instance)],
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
            'DB_CONNECTION' => 'mariadb',
            'DB_HOST' => $this->bindAddress(),
            'DB_PORT' => (string) $instance->port,
            'DB_DATABASE' => (string) $instance->option('database', 'laravel'),
            'DB_USERNAME' => (string) $instance->option('username', 'root'),
            'DB_PASSWORD' => (string) $instance->option('password', ''),
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
            driver: 'mariadb',
            host: $this->bindAddress(),
            port: $instance->port,
            user: (string) $instance->option('username', 'root'),
            password: (string) $instance->option('password', '') ?: null,
            name: "stackd {$instance->id()}",
        );
    }

    private function initializeDataDirectory(Instance $instance): void
    {
        $installDb = $this->basedir().'/scripts/mariadb-install-db';

        if (! is_executable($installDb)) {
            $installDb = $this->basedir().'/bin/mariadb-install-db';
        }

        if (! is_executable($installDb)) {
            throw new RuntimeException('mariadb-install-db was not found after building MariaDB.');
        }

        $this->processes->runOrFail([
            $installDb,
            '--datadir='.$this->paths->dataDir($instance->service, $instance->name),
            '--basedir='.$this->basedir(),
            '--auth-root-authentication-method=normal',
        ], timeout: 120);
    }

    private function bootstrapIfNeeded(Instance $instance): void
    {
        $marker = $this->paths->instance($instance->service, $instance->name).'/.bootstrapped';

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
                'mariadb',
                '-uroot',
            ], $args);
        }

        return array_merge([
            $this->clientBinary(),
            '-S', $this->socketPath($instance),
            '-u', 'root',
        ], $args);
    }

    private function waitUntilReady(Instance $instance, int $timeout = 45): void
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

        throw new RuntimeException('MariaDB failed to become ready for bootstrapping.');
    }

    private function runSql(Instance $instance, string $sql): void
    {
        $this->processes->runOrFail($this->sqlClientCommand($instance, ['-e', $sql]));
    }

    private function querySql(Instance $instance, string $sql): string
    {
        $process = $this->processes->run($this->sqlClientCommand($instance, ['-N', '-B', '-e', $sql]));

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'MariaDB query failed.');
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
skip-name-resolve
skip-log-bin
performance_schema = OFF
innodb_buffer_pool_size = 128M
innodb_log_buffer_size = 8M
innodb_flush_log_at_trx_commit = 2
aria_pagecache_buffer_size = 8M
key_buffer_size = 8M
table_open_cache = 200
thread_cache_size = 8
max_connections = 50
basedir = {$this->basedir()}
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
        if (is_executable($this->serverBinary())) {
            return;
        }

        $config = config('stackd.downloads.mariadb');
        $version = $config['version'];
        $buildRoot = $this->paths->binary('mariadb', $version);
        $sourceDir = $buildRoot.'/'.$config['source_dir'];
        $prefix = $buildRoot.'/prefix';

        if (! is_dir($sourceDir)) {
            $this->binaries->installFromTarball(
                url: $config['url'],
                destination: $buildRoot,
                archiveName: "mariadb-{$version}.tar.gz",
                label: 'MariaDB source',
            );
        }

        if (! is_dir($sourceDir)) {
            throw new RuntimeException('MariaDB source directory was not found after download.');
        }

        $cmake = $this->resolveCmake();
        $openSslRoot = $this->resolveOpenSslRoot();
        $sdkPath = $this->macOsSdkPath();

        $buildDir = $buildRoot.'/build';
        $logFile = $buildRoot.'/build.log';
        $this->resetDirectory($buildDir);
        $this->binaries->ensureDirectory($prefix);
        $progress = $this->binaries->progress();

        $configure = [
            $cmake,
            '-S', $sourceDir,
            '-B', $buildDir,
            '-DCMAKE_BUILD_TYPE=Release',
            '-DCMAKE_INSTALL_PREFIX='.$prefix,
            '-DWITH_SSL=bundled',
            // Bundled wolfSSL makes Connector/C default to GnuTLS; force OpenSSL instead.
            '-DCONC_WITH_SSL=OPENSSL',
            '-DOPENSSL_ROOT_DIR='.$openSslRoot,
            // System zlib's SDK include path breaks libc++ header resolution on modern macOS.
            '-DWITH_ZLIB=bundled',
            '-DWITH_UNIT_TESTS=OFF',
            '-DWITH_WSREP=OFF',
            '-DPLUGIN_COLUMNSTORE=NO',
            '-DPLUGIN_MROONGA=NO',
            '-DPLUGIN_ROCKSDB=NO',
            '-DPLUGIN_SPIDER=NO',
            '-DPLUGIN_TOKUDB=NO',
            '-DPLUGIN_CONNECT=NO',
        ];

        if ($sdkPath !== null) {
            $configure[] = '-DCMAKE_OSX_SYSROOT='.$sdkPath;
        }

        $progress->configuring('MariaDB', function () use ($configure, $logFile): void {
            $this->processes->runOrFail($configure, timeout: 600, logFile: $logFile);
        });

        $cpuProcess = Process::fromShellCommandline('sysctl -n hw.ncpu');
        $cpuProcess->run();
        $jobs = (string) max(1, (int) trim($cpuProcess->getOutput()));

        $progress->compiling('MariaDB', function () use ($cmake, $buildDir, $jobs, $logFile): void {
            $this->processes->runOrFail([
                $cmake,
                '--build', $buildDir,
                '--parallel', $jobs,
            ], timeout: 3600, logFile: $logFile);
        });

        $progress->installing('MariaDB', function () use ($cmake, $buildDir, $logFile): void {
            $this->processes->runOrFail([
                $cmake,
                '--install', $buildDir,
            ], timeout: 300, logFile: $logFile);
        });

        if (! is_executable($this->serverBinary())) {
            throw new RuntimeException('MariaDB build completed but mariadbd was not found.');
        }
    }

    private function serverBinary(): string
    {
        $prefix = $this->basedir();

        foreach ([$prefix.'/bin/mariadbd', $prefix.'/sbin/mariadbd', $prefix.'/bin/mysqld'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return $prefix.'/bin/mariadbd';
    }

    private function clientBinary(): string
    {
        $prefix = $this->basedir();

        foreach ([$prefix.'/bin/mariadb', $prefix.'/bin/mysql'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        throw new RuntimeException('MariaDB client binary was not found.');
    }

    private function basedir(): string
    {
        $version = config('stackd.downloads.mariadb.version');

        return $this->paths->binary('mariadb', $version).'/prefix';
    }

    private function macOsSdkPath(): ?string
    {
        $process = Process::fromShellCommandline('xcrun --show-sdk-path');
        $process->run();
        $sdk = trim($process->getOutput());

        return ($sdk !== '' && is_dir($sdk)) ? $sdk : null;
    }

    private function resolveOpenSslRoot(): string
    {
        $prefix = $this->findOpenSslPrefix();

        if ($prefix !== null) {
            return $prefix;
        }

        $brew = $this->findBrew();

        if ($brew === null) {
            throw new RuntimeException(
                'OpenSSL is required to build MariaDB. Install openssl@3, then re-run this command.'
            );
        }

        if (! stream_isatty(STDIN) || ! stream_isatty(STDOUT) || ! confirm(
            label: 'OpenSSL is required to build MariaDB. Install openssl@3 with Homebrew?',
            default: true,
        )) {
            throw new RuntimeException(
                'OpenSSL is required to build MariaDB. Install it with Homebrew: brew install openssl@3'
            );
        }

        $this->binaries->progress()->task('Installing openssl@3 with Homebrew...', function () use ($brew): void {
            $this->processes->runOrFail([$brew, 'install', 'openssl@3'], timeout: 600);
        });

        $prefix = $this->findOpenSslPrefix();

        if ($prefix === null) {
            throw new RuntimeException('openssl@3 was installed with Homebrew but could not be located.');
        }

        return $prefix;
    }

    private function findOpenSslPrefix(): ?string
    {
        $brew = $this->findBrew();

        if ($brew !== null) {
            foreach (['openssl@3', 'openssl'] as $formula) {
                $process = new Process([$brew, '--prefix', $formula]);
                $process->run();
                $prefix = trim($process->getOutput());

                if ($this->isOpenSslPrefix($prefix)) {
                    return $prefix;
                }
            }
        }

        foreach ([
            '/opt/homebrew/opt/openssl@3',
            '/usr/local/opt/openssl@3',
            '/opt/homebrew/opt/openssl',
            '/usr/local/opt/openssl',
        ] as $prefix) {
            if ($this->isOpenSslPrefix($prefix)) {
                return $prefix;
            }
        }

        return null;
    }

    private function isOpenSslPrefix(string $prefix): bool
    {
        return $prefix !== '' && is_file($prefix.'/include/openssl/ssl.h');
    }

    private function resetDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            $this->processes->runOrFail(['rm', '-rf', $directory]);
        }

        $this->binaries->ensureDirectory($directory);
    }

    private function resolveCmake(): string
    {
        $cmake = $this->findCmake();

        if ($cmake !== null) {
            return $cmake;
        }

        $brew = $this->findBrew();

        if ($brew === null) {
            throw new RuntimeException(
                'MariaDB has no official macOS binaries, so stackd builds it from source. Install cmake first (e.g. download from https://cmake.org/download/).'
            );
        }

        if (! $this->shouldInstallCmakeViaHomebrew()) {
            throw new RuntimeException(
                'MariaDB has no official macOS binaries, so stackd builds it from source. Install cmake first with Homebrew: brew install cmake'
            );
        }

        $this->binaries->progress()->task('Installing cmake with Homebrew...', function () use ($brew): void {
            $this->processes->runOrFail([$brew, 'install', 'cmake'], timeout: 600);
        });

        $cmake = $this->findCmake();

        if ($cmake === null) {
            throw new RuntimeException('cmake was installed with Homebrew but could not be found on PATH. Try opening a new terminal, then re-run this command.');
        }

        return $cmake;
    }

    private function shouldInstallCmakeViaHomebrew(): bool
    {
        if (! stream_isatty(STDIN) || ! stream_isatty(STDOUT)) {
            return false;
        }

        return confirm(
            label: 'cmake is required to build MariaDB. Install it with Homebrew?',
            default: true,
        );
    }

    private function findCmake(): ?string
    {
        $process = Process::fromShellCommandline('command -v cmake');
        $process->run();
        $cmake = trim($process->getOutput());

        return ($cmake !== '' && is_executable($cmake)) ? $cmake : null;
    }

    private function findBrew(): ?string
    {
        $process = Process::fromShellCommandline('command -v brew');
        $process->run();
        $brew = trim($process->getOutput());

        return ($brew !== '' && is_executable($brew)) ? $brew : null;
    }
}
