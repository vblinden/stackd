<?php

namespace App\Services;

use App\Services\Contracts\ServiceInterface;
use App\Support\BinaryDownloader;
use App\Support\Instance;
use App\Support\ProcessManager;
use App\Support\StackdPaths;

abstract class AbstractService implements ServiceInterface
{
    public function __construct(
        protected readonly StackdPaths $paths,
        protected readonly ProcessManager $processes,
        protected readonly BinaryDownloader $binaries,
    ) {}

    public function defaultName(): string
    {
        return 'default';
    }

    public function availableVersions(): array
    {
        return ['latest'];
    }

    public function create(Instance $instance): void
    {
        $this->ensureInstanceDirectories($instance);
        $this->provision($instance);
    }

    public function isRunning(Instance $instance): bool
    {
        return $this->processes->isRunning($this->paths->pidFile($instance->service, $instance->name));
    }

    public function openUrl(Instance $instance): ?string
    {
        return null;
    }

    /**
     * @return array<string, string>
     */
    public function credentials(Instance $instance): array
    {
        return [];
    }

    public function openInDatabaseClient(Instance $instance): void
    {
        throw new \RuntimeException(static::displayName().' does not support database client opening.');
    }

    public function logFiles(Instance $instance): array
    {
        $logDir = $this->paths->logsDir($instance->service, $instance->name);
        $files = glob($logDir.'/*.log') ?: [];

        if ($files === [] && file_exists($logDir.'/output.log')) {
            return [$logDir.'/output.log'];
        }

        return $files !== [] ? $files : [$logDir.'/output.log'];
    }

    public function statusDetails(Instance $instance): array
    {
        $pid = $this->processes->readPid($this->paths->pidFile($instance->service, $instance->name));

        return [
            'running' => $this->isRunning($instance),
            'pid' => $pid,
            'port' => $instance->port,
            'bind' => config('stackd.bind_address'),
        ];
    }

    protected function bindAddress(): string
    {
        return config('stackd.bind_address');
    }

    protected function ensureInstanceDirectories(Instance $instance): void
    {
        foreach ([
            $this->paths->instance($instance->service, $instance->name),
            $this->paths->dataDir($instance->service, $instance->name),
            $this->paths->logsDir($instance->service, $instance->name),
        ] as $dir) {
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    protected function outputLog(Instance $instance): string
    {
        return $this->paths->logsDir($instance->service, $instance->name).'/output.log';
    }

    protected function pidFile(Instance $instance): string
    {
        return $this->paths->pidFile($instance->service, $instance->name);
    }

    abstract protected function provision(Instance $instance): void;
}
