<?php

namespace App\Commands;

use App\Commands\Concerns\ResolvesServiceInput;
use App\Services\ServiceRegistry;
use App\Support\InstanceManager;
use LaravelZero\Framework\Commands\Command;

class LogsCommand extends Command
{
    use ResolvesServiceInput;

    protected $signature = 'logs
                            {service : The service type}
                            {name? : Instance name}
                            {--follow : Follow log output}';

    protected $description = 'Show logs for a service instance';

    public function handle(InstanceManager $manager, ServiceRegistry $registry): int
    {
        try {
            $service = $this->resolveServiceType($this->argument('service'));
            $instance = $manager->resolveInstance($service, $this->argument('name'));
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
}
