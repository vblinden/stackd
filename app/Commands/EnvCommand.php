<?php

namespace App\Commands;

use App\Commands\Concerns\ResolvesServiceInput;
use App\Support\EnvWriter;
use App\Support\InstanceManager;
use App\Support\LaravelProjectDetector;
use LaravelZero\Framework\Commands\Command;

class EnvCommand extends Command
{
    use ResolvesServiceInput;

    protected $signature = 'env
                            {service? : The service type}
                            {name? : Instance name}
                            {--write : Write variables to the current Laravel .env file}';

    protected $description = 'Print or write Laravel .env lines for stackd services';

    public function handle(
        InstanceManager $manager,
        EnvWriter $envWriter,
        LaravelProjectDetector $detector,
    ): int {
        try {
            if ($this->argument('service')) {
                $service = $this->resolveServiceType($this->argument('service'));
                $instance = $manager->resolveInstance($service, $this->argument('name'));
                $variables = $manager->envForInstance($instance);
            } else {
                $services = $detector->detectNeededServices();
                $variables = $manager->envForServices($services);

                if ($variables === []) {
                    $this->components->warn('No matching stackd instances found for this project.');

                    return self::FAILURE;
                }
            }

            $output = $envWriter->format($variables);

            if ($this->option('write')) {
                if (! $detector->isLaravelProject()) {
                    throw new \RuntimeException('Not inside a Laravel project (artisan + .env required).');
                }

                $envWriter->mergeIntoFile($detector->envPath(), $variables);
                $this->components->info('Updated .env with stackd service configuration.');
            } else {
                $this->line($output);
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
