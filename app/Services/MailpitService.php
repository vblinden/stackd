<?php

namespace App\Services;

use App\Support\BinaryDownloader;
use App\Support\DockerEngine;
use App\Support\DockerSpec;
use App\Support\Instance;
use App\Support\ProcessManager;
use App\Support\ServiceOpener;
use App\Support\StackdPaths;
use RuntimeException;

class MailpitService extends AbstractService
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
        return 'mailpit';
    }

    public static function displayName(): string
    {
        return 'Mailpit';
    }

    public function defaultPort(): int
    {
        return 1025;
    }

    public function dockerSpec(Instance $instance): DockerSpec
    {
        $webPort = (int) $instance->option('web_port', $instance->port + 7000);

        return new DockerSpec(
            image: 'axllent/mailpit',
            ports: [
                $instance->port => 1025,
                $webPort => 8025,
            ],
            volumes: [
                $this->paths->dataDir($instance->service, $instance->name) => '/data',
            ],
            command: [
                '--smtp', '0.0.0.0:1025',
                '--listen', '0.0.0.0:8025',
                '--database', '/data/mailpit.db',
                '--max', '500',
            ],
        );
    }

    protected function provision(Instance $instance): void
    {
        $this->resolveBinary();
    }

    public function start(Instance $instance): void
    {
        if ($this->isRunning($instance)) {
            throw new RuntimeException('Mailpit is already running.');
        }

        $binary = $this->resolveBinary();
        $smtpPort = $instance->port;
        $webPort = (int) $instance->option('web_port', $smtpPort + 7000);

        $this->processes->start(
            command: [
                $binary,
                '--smtp', $this->bindAddress().':'.$smtpPort,
                '--listen', $this->bindAddress().':'.$webPort,
                '--database', $this->paths->dataDir($instance->service, $instance->name).'/mailpit.db',
                '--max', '500',
            ],
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
        $smtpPort = $instance->port;
        $webPort = (int) $instance->option('web_port', $smtpPort + 7000);

        return [
            'MAIL_MAILER' => 'smtp',
            'MAIL_HOST' => $this->bindAddress(),
            'MAIL_PORT' => (string) $smtpPort,
            'MAIL_USERNAME' => 'null',
            'MAIL_PASSWORD' => 'null',
            'MAIL_ENCRYPTION' => 'null',
            'MAIL_FROM_ADDRESS' => 'hello@example.com',
            'MAIL_FROM_NAME' => '${APP_NAME}',
            'STACKD_MAILPIT_WEB' => "http://{$this->bindAddress()}:{$webPort}",
        ];
    }

    public function openUrl(Instance $instance): ?string
    {
        $webPort = (int) $instance->option('web_port', $instance->port + 7000);

        return "http://{$this->bindAddress()}:{$webPort}";
    }

    public function statusDetails(Instance $instance): array
    {
        return array_merge(parent::statusDetails($instance), [
            'web_port' => (int) $instance->option('web_port', $instance->port + 7000),
        ]);
    }

    private function resolveBinary(): string
    {
        return $this->binaries->resolveBinary('mailpit', 'mailpit', function (string $path): void {
            $this->installMailpit(dirname($path));
        });
    }

    private function installMailpit(string $destination): void
    {
        $arch = $this->binaries->architecture();
        $url = str_replace('{arch}', $arch, config('stackd.downloads.mailpit.url'));
        $archive = $destination.'/'.basename(parse_url($url, PHP_URL_PATH));

        $this->binaries->download($url, $archive, 'Mailpit');
        $this->binaries->extractTarGz($archive, $destination, 'Mailpit');
        $this->binaries->makeExecutable($destination.'/mailpit');
        unlink($archive);
    }
}
