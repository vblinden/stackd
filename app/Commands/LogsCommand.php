<?php

namespace App\Commands;

use App\Commands\Concerns\ResolvesServiceInput;
use App\Services\ServiceRegistry;
use App\Support\DockerEngine;
use App\Support\Instance;
use App\Support\InstanceManager;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Process\Process;

class LogsCommand extends Command
{
    use ResolvesServiceInput;

    protected $signature = 'logs
                            {service : The service type}
                            {name? : Instance name}
                            {--follow : Follow log output}';

    protected $description = 'Show logs for a service instance';

    public function handle(InstanceManager $manager, ServiceRegistry $registry, DockerEngine $docker): int
    {
        try {
            $service = $this->resolveServiceType($this->argument('service'));
            $instance = $manager->resolveInstance($service, $this->argument('name'));

            if ($instance->isDocker()) {
                return $this->streamDockerLogs($docker, $instance);
            }

            $logFiles = $registry->get($service)->logFiles($instance);
            $logFile = $logFiles[0] ?? null;

            if ($logFile === null || ! file_exists($logFile)) {
                $this->components->warn('No log file found yet. Start the service first.');

                return self::SUCCESS;
            }

            if ($this->option('follow')) {
                $this->line(shell_exec('tail -f '.escapeshellarg($logFile)));

                return self::SUCCESS;
            }

            $this->line(file_get_contents($logFile) ?: '');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function streamDockerLogs(DockerEngine $docker, Instance $instance): int
    {
        $process = new Process($docker->logsCommand($instance, (bool) $this->option('follow')));
        $process->setTimeout($this->option('follow') ? null : 30);
        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        if (! $process->isSuccessful() && ! $this->option('follow')) {
            $this->components->warn('No Docker logs yet. Start the service first.');
        }

        return self::SUCCESS;
    }
}
