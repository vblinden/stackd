<?php

namespace App\Commands;

use App\Services\ServiceRegistry;
use App\Support\InstanceManager;
use App\Support\InstanceRepository;
use App\Support\ServicesPresenter;
use LaravelZero\Framework\Commands\Command;

class StatusCommand extends Command
{
    protected $signature = 'status';

    protected $description = 'Show status of all service instances';

    public function handle(
        InstanceRepository $repository,
        InstanceManager $manager,
        ServiceRegistry $registry,
        ServicesPresenter $presenter,
    ): int {
        $services = [];

        foreach ($repository->all() as $instance) {
            $status = $manager->statusFor($instance);

            $services[] = [
                'service' => $instance->service,
                'name' => $instance->name,
                'running' => $status['running'],
                'address' => config('stackd.bind_address').':'.$instance->port,
                'pid' => $status['pid'] ? (string) $status['pid'] : null,
                'credentials' => $registry->get($instance->service)->credentials($instance),
                'runtime' => $instance->runtime,
            ];
        }

        $presenter->render($this->output, $services);

        return self::SUCCESS;
    }
}
