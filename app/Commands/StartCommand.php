<?php

namespace App\Commands;

use App\Commands\Concerns\ResolvesServiceInput;
use App\Support\InstanceManager;
use App\Support\InstanceRepository;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

class StartCommand extends Command
{
    use ResolvesServiceInput;

    protected $signature = 'start
                            {service? : The service type (omit to start all instances)}
                            {name? : Instance name}';

    protected $description = 'Start a service instance, or all instances when no service is given';

    public function handle(InstanceManager $manager, InstanceRepository $repository): int
    {
        try {
            $service = $this->argument('service');
            $name = $this->argument('name');

            if ($service === null) {
                if ($name !== null) {
                    throw new RuntimeException('Provide a service type when specifying an instance name.');
                }

                return $this->startAll($manager, $repository);
            }

            $service = $this->resolveServiceType($service);
            $label = $service.($name ? " {$name}" : '');

            spin(fn () => $manager->start($service, $name), "Starting {$label}...");
            info("Started {$label}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function startAll(InstanceManager $manager, InstanceRepository $repository): int
    {
        $instances = $repository->all();

        if ($instances === []) {
            warning('No instances found.');

            return self::SUCCESS;
        }

        $failed = 0;
        $started = 0;
        $skipped = 0;

        foreach ($instances as $instance) {
            $label = $instance->id();

            try {
                if ($manager->isRunning($instance)) {
                    info("{$label} is already running");
                    $skipped++;

                    continue;
                }

                spin(fn () => $manager->start($instance->service, $instance->name), "Starting {$label}...");
                info("Started {$label}");
                $started++;
            } catch (\Throwable $e) {
                error("Failed to start {$label}: {$e->getMessage()}");
                $failed++;
            }
        }

        if ($started === 0 && $failed === 0 && $skipped > 0) {
            info('All instances are already running.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
