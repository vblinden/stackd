<?php

namespace App\Commands;

use App\Commands\Concerns\ResolvesServiceInput;
use App\Support\InstanceManager;
use LaravelZero\Framework\Commands\Command;

class DeleteCommand extends Command
{
    use ResolvesServiceInput;

    protected $signature = 'delete
                            {service : The service type}
                            {name? : Instance name}';

    protected $description = 'Delete a service instance and its data';

    public function handle(InstanceManager $manager): int
    {
        try {
            $service = $this->resolveServiceType($this->argument('service'));

            if (! $this->confirm('This will permanently delete the instance and its data. Continue?')) {
                return self::SUCCESS;
            }

            $manager->delete($service, $this->argument('name'));
            $this->components->info("Deleted {$service}".($this->argument('name') ? ' '.$this->argument('name') : ''));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
