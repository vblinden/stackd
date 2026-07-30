<?php

namespace App\Commands;

use App\Commands\Concerns\ResolvesServiceInput;
use App\Support\InstanceManager;
use LaravelZero\Framework\Commands\Command;

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
            $manager->stop($service, $this->argument('name'));
            $this->components->info("Stopped {$service}".($this->argument('name') ? ' '.$this->argument('name') : ''));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
