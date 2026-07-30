<?php

namespace App\Services;

use App\Support\BinaryDownloader;
use App\Support\Instance;
use App\Support\ProcessManager;
use App\Support\ServiceOpener;
use App\Support\StackdPaths;
use RuntimeException;

class ValkeyService extends AbstractService
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
        return 'valkey';
    }

    public static function displayName(): string
    {
        return 'Valkey';
    }

    public function defaultPort(): int
    {
        return 6379;
    }

    protected function provision(Instance $instance): void
    {
        $configPath = $this->configPath($instance);
        $dataDir = $this->paths->dataDir($instance->service, $instance->name);

        file_put_contents($configPath, $this->buildConfig($instance, $dataDir));
        $this->resolveBinary();
    }

    public function start(Instance $instance): void
    {
        if ($this->isRunning($instance)) {
            throw new RuntimeException('Valkey is already running.');
        }

        $binary = $this->resolveBinary();

        $this->processes->start(
            command: [$binary, $this->configPath($instance)],
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
        return [
            'REDIS_CLIENT' => 'phpredis',
            'REDIS_HOST' => $this->bindAddress(),
            'REDIS_PASSWORD' => 'null',
            'REDIS_PORT' => (string) $instance->port,
            'CACHE_STORE' => 'redis',
            'SESSION_DRIVER' => 'redis',
            'QUEUE_CONNECTION' => 'redis',
        ];
    }

    public function openInDatabaseClient(Instance $instance): void
    {
        $this->opener->openDatabase(
            driver: 'redis',
            host: $this->bindAddress(),
            port: $instance->port,
            name: "stackd {$instance->id()}",
        );
    }

    public function statusDetails(Instance $instance): array
    {
        return array_merge(parent::statusDetails($instance), [
            'config' => $this->configPath($instance),
        ]);
    }

    private function configPath(Instance $instance): string
    {
        return $this->paths->instance($instance->service, $instance->name).'/valkey.conf';
    }

    private function buildConfig(Instance $instance, string $dataDir): string
    {
        $bind = $this->bindAddress();
        $port = $instance->port;

        return <<<CONF
bind {$bind}
port {$port}
dir {$dataDir}
daemonize no
save ""
appendonly no
protected-mode yes
CONF;
    }

    private function resolveBinary(): string
    {
        return $this->binaries->resolveBinary('valkey', 'valkey-server', function (string $path): void {
            $this->installValkey(dirname($path));
        });
    }

    private function installValkey(string $destination): void
    {
        $config = config('stackd.downloads.valkey');
        $version = $config['version'];
        $buildDir = $destination.'/build-'.$version;
        $archiveName = "valkey-{$version}.tar.gz";

        $this->binaries->ensureDirectory($buildDir);

        $extractedRoot = $this->binaries->installFromTarball(
            url: $config['url'],
            destination: $buildDir,
            archiveName: $archiveName,
            label: 'Valkey source',
        );

        $sourceDir = is_dir($buildDir.'/'.$config['source_dir'])
            ? $buildDir.'/'.$config['source_dir']
            : $extractedRoot;

        $this->binaries->compile(
            sourceDirectory: $sourceDir,
            binaryName: 'valkey-server',
            outputPath: $destination.'/valkey-server',
            label: 'Valkey',
        );
    }
}
