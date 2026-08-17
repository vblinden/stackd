<?php

namespace App\Commands;

use App\Commands\Concerns\ResolvesServiceInput;
use App\Services\Contracts\ManagesNamedDatabases;
use App\Services\ServiceRegistry;
use App\Support\EnvWriter;
use App\Support\FrameworkEnv;
use App\Support\Instance;
use App\Support\InstanceManager;
use App\Support\ProjectDatabase;
use App\Support\ProjectDetector;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;

class EnvCommand extends Command
{
    use ResolvesServiceInput;

    protected $signature = 'env
                            {service? : The service type}
                            {name? : Instance name}
                            {--show : Print variables instead of writing them to .env}';

    protected $description = 'Write .env lines for installed stackd services (Laravel or Next.js; use --show to print)';

    public function handle(
        InstanceManager $manager,
        EnvWriter $envWriter,
        ProjectDetector $detector,
        FrameworkEnv $frameworkEnv,
        ServiceRegistry $registry,
        ProjectDatabase $projectDatabase,
    ): int {
        try {
            if ($this->argument('service')) {
                $service = $this->resolveServiceType($this->argument('service'));
                $instance = $manager->resolveInstance($service, $this->argument('name'));
                $variables = $manager->envForInstance($instance);
                $variables = $this->applyProjectDatabase(
                    $variables,
                    $instance,
                    $manager,
                    $registry,
                    $projectDatabase,
                );
            } else {
                $services = $detector->detectNeededServices();
                $variables = [];

                foreach ($services as $serviceType) {
                    $instance = $manager->resolveInstance($serviceType, null);
                    $chunk = $manager->envForInstance($instance);
                    $chunk = $this->applyProjectDatabase(
                        $chunk,
                        $instance,
                        $manager,
                        $registry,
                        $projectDatabase,
                    );
                    $variables = array_merge($variables, $chunk);
                }

                if ($variables === []) {
                    $this->components->warn('No stackd instances found.');
                    $this->line('  Create one with: <fg=cyan>stackd create</>');

                    return self::FAILURE;
                }
            }

            $variables = $frameworkEnv->forFramework($detector->framework(), $variables);
            $output = $envWriter->format($variables);

            if ($this->option('show')) {
                $this->line($output);

                return self::SUCCESS;
            }

            if (! $detector->canWriteEnv()) {
                throw new RuntimeException('Not inside a Laravel or Next.js project (artisan + .env, or package.json with next).');
            }

            $envPath = $detector->envPath();
            $envWriter->mergeIntoFile($envPath, $variables);
            $this->components->info('Updated '.basename($envPath).' with stackd service configuration.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string, string>  $variables
     * @return array<string, string>
     */
    private function applyProjectDatabase(
        array $variables,
        Instance $instance,
        InstanceManager $manager,
        ServiceRegistry $registry,
        ProjectDatabase $projectDatabase,
    ): array {
        $service = $registry->get($instance->service);

        if (! $service instanceof ManagesNamedDatabases) {
            return $variables;
        }

        if (! isset($variables['DB_DATABASE'])) {
            return $variables;
        }

        $database = $projectDatabase->nameFromPath();
        $manager->ensureRunning($instance);

        if (! $service->databaseExists($instance, $database)) {
            $this->ensureDatabaseCreated($service, $instance, $database);
        }

        $variables['DB_DATABASE'] = $database;

        return $variables;
    }

    private function ensureDatabaseCreated(
        ManagesNamedDatabases $service,
        Instance $instance,
        string $database,
    ): void {
        $shouldCreate = true;

        if (! $this->laravel->runningUnitTests() && stream_isatty(STDIN) && stream_isatty(STDOUT)) {
            $shouldCreate = confirm(
                label: "Database [{$database}] does not exist on {$instance->id()}. Create it?",
                default: true,
            );
        }

        if (! $shouldCreate) {
            throw new RuntimeException("Database [{$database}] does not exist. Create it or choose another project folder name.");
        }

        $service->createDatabase($instance, $database);
        info("Created database [{$database}] on {$instance->id()}.");
    }
}
