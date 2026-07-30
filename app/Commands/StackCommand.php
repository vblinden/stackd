<?php

namespace App\Commands;

use App\Commands\Concerns\ResolvesServiceInput;
use App\Support\InstanceManager;
use LaravelZero\Framework\Commands\Command;

class StackCommand extends Command
{
    use ResolvesServiceInput;

    protected $signature = 'stack
                            {name : Shared instance name for the stack}
                            {--mysql : Include MySQL}
                            {--mariadb : Include MariaDB}
                            {--postgresql : Include PostgreSQL}
                            {--valkey : Include Valkey}
                            {--mailpit : Include Mailpit}
                            {--meilisearch : Include Meilisearch}
                            {--minio : Include MinIO}
                            {--reverb : Include Laravel Reverb}
                            {--start : Start services after creating them}';

    protected $description = 'Create a named stack of service instances';

    public function handle(InstanceManager $manager): int
    {
        try {
            $name = $this->argument('name');
            $selected = $this->selectedServices();

            if ($selected === []) {
                throw new \RuntimeException('Select at least one service flag, e.g. --mysql --valkey --mailpit');
            }

            foreach ($selected as $service) {
                $instance = $manager->create(
                    serviceType: $service,
                    name: $name,
                );

                $this->components->info("Created {$instance->id()} on port {$instance->port}");

                if ($this->option('start')) {
                    $manager->start($service, $name);
                    $this->components->info("Started {$instance->id()}");
                }
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @return array<int, string>
     */
    private function selectedServices(): array
    {
        $map = [
            'mysql' => $this->option('mysql'),
            'mariadb' => $this->option('mariadb'),
            'postgresql' => $this->option('postgresql'),
            'valkey' => $this->option('valkey'),
            'mailpit' => $this->option('mailpit'),
            'meilisearch' => $this->option('meilisearch'),
            'minio' => $this->option('minio'),
            'reverb' => $this->option('reverb'),
        ];

        return array_keys(array_filter($map));
    }
}
