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

class StopCommand extends Command
{
    use ResolvesServiceInput;

    protected $signature = 'stop
                            {service? : The service type (omit to stop all instances)}
                            {name? : Instance name}';

    protected $description = 'Stop a service instance, or all instances when no service is given';

    public function handle(InstanceManager $manager, InstanceRepository $repository): int
    {
        try {
            $service = $this->argument('service');
            $name = $this->argument('name');

            if ($service === null) {
                if ($name !== null) {
                    throw new RuntimeException('Provide a service type when specifying an instance name.');
                }

                return $this->stopAll($manager, $repository);
            }

            $service = $this->resolveServiceType($service);
            $label = $service.($name ? " {$name}" : '');

            spin(fn () => $manager->stop($service, $name), "Stopping {$label}...");
            info("Stopped {$label}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function stopAll(InstanceManager $manager, InstanceRepository $repository): int
    {
        $instances = $repository->all();

        if ($instances === []) {
            warning('No instances found.');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($instances as $instance) {
            $label = $instance->id();

            try {
                spin(fn () => $manager->stop($instance->service, $instance->name), "Stopping {$label}...");
                info("Stopped {$label}");
            } catch (\Throwable $e) {
                error("Failed to stop {$label}: {$e->getMessage()}");
                $failed++;
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
