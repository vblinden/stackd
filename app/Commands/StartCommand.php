<?php

namespace App\Commands;

use App\Commands\Concerns\ResolvesServiceInput;
use App\Support\InstanceManager;
use LaravelZero\Framework\Commands\Command;

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
            $manager->start($service, $this->argument('name'));
            $this->components->info("Started {$service}".($this->argument('name') ? ' '.$this->argument('name') : ''));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
