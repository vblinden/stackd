<?php

namespace App\Services;

use App\Support\BinaryDownloader;
use App\Support\Instance;
use App\Support\ProcessManager;
use App\Support\StackdPaths;
use RuntimeException;

class MeilisearchService extends AbstractService
{
    public function __construct(
        StackdPaths $paths,
        ProcessManager $processes,
        BinaryDownloader $binaries,
    ) {
        parent::__construct($paths, $processes, $binaries);
    }

    public static function type(): string
    {
        return 'meilisearch';
    }

    public static function displayName(): string
    {
        return 'Meilisearch';
    }

    public function defaultPort(): int
    {
        return 7700;
    }

    protected function provision(Instance $instance): void
    {
        $this->resolveBinary();
    }

    public function start(Instance $instance): void
    {
        if ($this->isRunning($instance)) {
            throw new RuntimeException('Meilisearch is already running.');
        }

        $binary = $this->resolveBinary();
        $dataDir = $this->paths->dataDir($instance->service, $instance->name);
        $masterKey = (string) $instance->option('master_key', '');

        $command = [
            $binary,
            '--http-addr', $this->bindAddress().':'.$instance->port,
            '--db-path', $dataDir.'/data.ms',
            '--env', 'development',
            '--no-analytics',
        ];

        if ($masterKey !== '') {
            $command[] = '--master-key';
            $command[] = $masterKey;
        }

        $this->processes->start(
            command: $command,
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
        $host = "http://{$this->bindAddress()}:{$instance->port}";
        $key = (string) $instance->option('master_key', '');

        return [
            'SCOUT_DRIVER' => 'meilisearch',
            'MEILISEARCH_HOST' => $host,
            'MEILISEARCH_KEY' => $key !== '' ? $key : 'null',
        ];
    }

    public function credentials(Instance $instance): array
    {
        return [
            'master key' => (string) $instance->option('master_key', ''),
        ];
    }

    public function openUrl(Instance $instance): ?string
    {
        return "http://{$this->bindAddress()}:{$instance->port}";
    }

    private function resolveBinary(): string
    {
        return $this->binaries->resolveBinary('meilisearch', 'meilisearch', function (string $path): void {
            $arch = $this->binaries->machineArch();
            $url = config("stackd.downloads.meilisearch.{$arch}");

            if ($url === null) {
                throw new RuntimeException("Meilisearch is not available for architecture [{$arch}].");
            }

            $this->binaries->downloadExecutable($url, $path, 'Meilisearch');
        });
    }
}
