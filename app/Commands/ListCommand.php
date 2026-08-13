<?php

namespace App\Commands;

use App\Support\InstanceManager;
use App\Support\InstanceRepository;
use LaravelZero\Framework\Commands\Command;

class ListCommand extends Command
{
    protected $signature = 'list';

    protected $description = 'List all service instances';

    public function handle(InstanceRepository $repository, InstanceManager $manager): int
    {
        $instances = $repository->all();

        if ($instances === []) {
            $this->components->warn('No instances found.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($instances as $instance) {
            $rows[] = [
                $instance->id(),
                (string) $instance->port,
                $instance->version ?? 'latest',
                $instance->runtime,
                $manager->isRunning($instance) ? 'running' : 'stopped',
                $instance->createdAt ?? '-',
            ];
        }

        $this->table(['Instance', 'Port', 'Version', 'Runtime', 'State', 'Created'], $rows);

        return self::SUCCESS;
    }
}
