<?php

namespace App\Services;

use App\Support\BinaryDownloader;
use App\Support\Instance;
use App\Support\ProcessManager;
use App\Support\StackdPaths;
use RuntimeException;

class MinioService extends AbstractService
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
        return 'minio';
    }

    public static function displayName(): string
    {
        return 'MinIO';
    }

    public function defaultPort(): int
    {
        return 9000;
    }

    protected function provision(Instance $instance): void
    {
        $this->resolveBinary();
    }

    public function start(Instance $instance): void
    {
        if ($this->isRunning($instance)) {
            throw new RuntimeException('MinIO is already running.');
        }

        $binary = $this->resolveBinary();
        $dataDir = $this->paths->dataDir($instance->service, $instance->name);
        $consolePort = (int) $instance->option('console_port', $instance->port + 1);
        $accessKey = (string) $instance->option('access_key', 'stackd');
        $secretKey = (string) $instance->option('secret_key', 'secretkey');

        $this->processes->start(
            command: [
                $binary,
                'server',
                $dataDir,
                '--address', $this->bindAddress().':'.$instance->port,
                '--console-address', $this->bindAddress().':'.$consolePort,
            ],
            pidFile: $this->pidFile($instance),
            logFile: $this->outputLog($instance),
            env: [
                'MINIO_ROOT_USER' => $accessKey,
                'MINIO_ROOT_PASSWORD' => $secretKey,
            ],
        );
    }

    public function stop(Instance $instance): void
    {
        $this->processes->stop($this->pidFile($instance));
    }

    public function envVariables(Instance $instance): array
    {
        $endpoint = "http://{$this->bindAddress()}:{$instance->port}";
        $consolePort = (int) $instance->option('console_port', $instance->port + 1);

        return [
            'FILESYSTEM_DISK' => 's3',
            'AWS_ACCESS_KEY_ID' => (string) $instance->option('access_key', 'stackd'),
            'AWS_SECRET_ACCESS_KEY' => (string) $instance->option('secret_key', 'secretkey'),
            'AWS_DEFAULT_REGION' => 'us-east-1',
            'AWS_BUCKET' => (string) $instance->option('bucket', 'laravel'),
            'AWS_ENDPOINT' => $endpoint,
            'AWS_USE_PATH_STYLE_ENDPOINT' => 'true',
            'STACKD_MINIO_CONSOLE' => "http://{$this->bindAddress()}:{$consolePort}",
        ];
    }

    public function credentials(Instance $instance): array
    {
        return [
            'username' => (string) $instance->option('access_key', 'stackd'),
            'password' => (string) $instance->option('secret_key', 'secretkey'),
        ];
    }

    public function openUrl(Instance $instance): ?string
    {
        $consolePort = (int) $instance->option('console_port', $instance->port + 1);

        return "http://{$this->bindAddress()}:{$consolePort}";
    }

    public function statusDetails(Instance $instance): array
    {
        return array_merge(parent::statusDetails($instance), [
            'console_port' => (int) $instance->option('console_port', $instance->port + 1),
        ]);
    }

    private function resolveBinary(): string
    {
        return $this->binaries->resolveBinary('minio', 'minio', function (string $path): void {
            $arch = $this->binaries->machineArch();
            $url = config("stackd.downloads.minio.{$arch}");

            if ($url === null) {
                throw new RuntimeException("MinIO is not available for architecture [{$arch}].");
            }

            $this->binaries->downloadExecutable($url, $path, 'MinIO');
        });
    }
}
