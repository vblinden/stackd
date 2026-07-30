<?php

namespace App\Commands;

use App\Support\InstanceManager;
use App\Support\InstanceRepository;
use LaravelZero\Framework\Commands\Command;

class StatusCommand extends Command
{
    protected $signature = 'status';

    protected $description = 'Show status of all service instances';

    public function handle(InstanceRepository $repository, InstanceManager $manager): int
    {
        $instances = $repository->all();

        if ($instances === []) {
            $this->components->warn('No instances found. Create one with: stackd create <service>');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($instances as $instance) {
            $status = $manager->statusFor($instance);

            $rows[] = [
                $instance->service,
                $instance->name,
                $status['running'] ? '<fg=green>running</>' : '<fg=red>stopped</>',
                (string) $instance->port,
                $status['pid'] ? (string) $status['pid'] : '-',
            ];
        }

        $this->table(['Service', 'Name', 'Status', 'Port', 'PID'], $rows);

        return self::SUCCESS;
    }
}
