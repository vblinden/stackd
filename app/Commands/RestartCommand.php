<?php

namespace App\Commands;

use App\Commands\Concerns\ResolvesServiceInput;
use App\Support\InstanceManager;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;

class RestartCommand extends Command
{
    use ResolvesServiceInput;

    protected $signature = 'restart
                            {service : The service type}
                            {name? : Instance name}';

    protected $description = 'Restart a service instance';

    public function handle(InstanceManager $manager): int
    {
        try {
            $service = $this->resolveServiceType($this->argument('service'));
            $name = $this->argument('name');
            $label = $service.($name ? " {$name}" : '');

            spin(fn () => $manager->restart($service, $name), "Restarting {$label}...");
            info("Restarted {$label}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            error($e->getMessage());

            return self::FAILURE;
        }
    }
}
