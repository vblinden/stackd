<?php

namespace App\Commands;

use App\Commands\Concerns\ResolvesServiceInput;
use App\Services\ServiceRegistry;
use App\Support\InstanceManager;
use LaravelZero\Framework\Commands\Command;

class CreateCommand extends Command
{
    use ResolvesServiceInput;

    protected $signature = 'create
                            {service? : The service type (mysql, valkey, mailpit, ...)}
                            {--name= : Instance name}
                            {--port= : Port to bind on 127.0.0.1}
                            {--service-version= : Service version}';

    protected $description = 'Create a new global service instance';

    public function handle(InstanceManager $manager, ServiceRegistry $registry): int
    {
        try {
            if ($this->argument('service') === null) {
                return $this->listServices($registry);
            }

            $service = $this->resolveServiceType($this->argument('service'));
            $port = $this->option('port') !== null ? (int) $this->option('port') : null;

            $instance = $manager->create(
                serviceType: $service,
                name: $this->option('name'),
                port: $port,
                version: $this->option('service-version'),
            );

            $this->components->info("Created {$instance->id()} on {$instance->port}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function listServices(ServiceRegistry $registry): int
    {
        $rows = [];

        foreach (config('stackd.services') as $type) {
            $available = $registry->has($type);
            $service = $available ? $registry->get($type) : null;

            $rows[] = [
                $type,
                $service?->displayName() ?? ucfirst($type),
                (string) config("stackd.default_ports.{$type}", '-'),
                $available ? '<fg=green>available</>' : '<fg=gray>coming soon</>',
            ];
        }

        $this->newLine();
        $this->line('  <fg=white;options=bold>Available services</>');
        $this->newLine();
        $this->table(['Service', 'Name', 'Default port', 'Status'], $rows);
        $this->line('  <fg=gray>Usage:</> stackd create <fg=cyan>mysql</> <fg=gray>--name=laravel --port=3306</>');
        $this->newLine();

        return self::SUCCESS;
    }
}
