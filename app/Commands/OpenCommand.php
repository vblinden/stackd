<?php

namespace App\Commands;

use App\Commands\Concerns\ResolvesServiceInput;
use App\Services\ServiceRegistry;
use App\Support\InstanceManager;
use App\Support\ServiceOpener;
use LaravelZero\Framework\Commands\Command;

class OpenCommand extends Command
{
    use ResolvesServiceInput;

    protected $signature = 'open
                            {service : The service type}
                            {name? : Instance name}';

    protected $description = 'Open a database in TablePlus or web UI in the browser';

    public function handle(
        InstanceManager $manager,
        ServiceRegistry $registry,
        ServiceOpener $opener,
    ): int {
        try {
            $serviceType = $this->resolveServiceType($this->argument('service'));
            $instance = $manager->resolveInstance($serviceType, $this->argument('name'));
            $service = $registry->get($serviceType);

            if (in_array($serviceType, ['mysql', 'mariadb', 'postgresql'], true)) {
                $service->openInDatabaseClient($instance);
                $this->components->info('Opened database client.');

                return self::SUCCESS;
            }

            $url = $service->openUrl($instance);

            if ($url === null) {
                throw new \RuntimeException("{$serviceType} does not have a web interface.");
            }

            $opener->openUrl($url);
            $this->components->info("Opened {$url}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
