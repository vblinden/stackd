<?php

namespace App\Commands;

use App\Commands\Concerns\ResolvesServiceInput;
use App\Support\InstanceManager;
use LaravelZero\Framework\Commands\Command;

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
            $manager->restart($service, $this->argument('name'));
            $this->components->info("Restarted {$service}".($this->argument('name') ? ' '.$this->argument('name') : ''));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
