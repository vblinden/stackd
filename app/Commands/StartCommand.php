<?php

namespace App\Commands;

use App\Commands\Concerns\ResolvesServiceInput;
use App\Support\InstanceManager;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

class StartCommand extends Command
{
    use ResolvesServiceInput;

    protected $signature = 'start
                            {service : The service type}
                            {name? : Instance name}';

    protected $description = 'Start a service instance';

    public function handle(InstanceManager $manager): int
    {
        try {
            $service = $this->resolveServiceType($this->argument('service'));
            $name = $this->argument('name');
            $label = $service.($name ? " {$name}" : '');

            $manager->start($service, $name);
            info("Started {$label}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            error($e->getMessage());

            return self::FAILURE;
        }
    }
}
