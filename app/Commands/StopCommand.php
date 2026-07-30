<?php

namespace App\Commands;

use App\Commands\Concerns\ResolvesServiceInput;
use App\Support\InstanceManager;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;

class StopCommand extends Command
{
    use ResolvesServiceInput;

    protected $signature = 'stop
                            {service : The service type}
                            {name? : Instance name}';

    protected $description = 'Stop a service instance';

    public function handle(InstanceManager $manager): int
    {
        try {
            $service = $this->resolveServiceType($this->argument('service'));
            $name = $this->argument('name');
            $label = $service.($name ? " {$name}" : '');

            spin(fn () => $manager->stop($service, $name), "Stopping {$label}...");
            info("Stopped {$label}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            error($e->getMessage());

            return self::FAILURE;
        }
    }
}
