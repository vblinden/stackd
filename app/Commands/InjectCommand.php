<?php

namespace App\Commands;

use App\Support\EnvWriter;
use App\Support\InstanceManager;
use App\Support\LaravelProjectDetector;
use LaravelZero\Framework\Commands\Command;

class InjectCommand extends Command
{
    protected $signature = 'inject
                            {path : Path to a Laravel project}
                            {--services= : Comma-separated service list}';

    protected $description = 'Inject stackd .env configuration into a Laravel project';

    public function handle(
        InstanceManager $manager,
        EnvWriter $envWriter,
        LaravelProjectDetector $detector,
    ): int {
        try {
            $path = rtrim($this->argument('path'), '/');

            if (! $detector->isLaravelProject($path)) {
                throw new \RuntimeException("{$path} does not look like a Laravel project.");
            }

            $services = $this->option('services')
                ? array_map('trim', explode(',', $this->option('services')))
                : $detector->detectNeededServices($detector->envPath($path));

            $variables = $manager->envForServices($services);

            if ($variables === []) {
                throw new \RuntimeException('No stackd instances found for the requested services.');
            }

            $envWriter->mergeIntoFile($detector->envPath($path), $variables);
            $this->components->info("Injected stackd configuration into {$path}/.env");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
