<?php

namespace App\Commands;

use App\Services\ServiceRegistry;
use App\Support\InstanceManager;
use App\Support\InstanceRepository;
use App\Support\ServicesPresenter;
use LaravelZero\Framework\Commands\Command;
use NunoMaduro\LaravelConsoleSummary\Contracts\DescriberContract;

class HomeCommand extends Command
{
    protected $signature = 'home';

    protected $description = 'Show running services and available commands';

    protected $hidden = true;

    public function handle(
        InstanceRepository $repository,
        InstanceManager $manager,
        DescriberContract $describer,
        ServicesPresenter $presenter,
        ServiceRegistry $registry,
    ): int {
        $presenter->render($this->output, $this->collectServices($repository, $manager, $registry), runningOnly: true);

        $describer->describe($this->getApplication(), $this->output);

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{service: string, name: string, running: bool, address: string, pid: string|null, credentials: array<string, string>, runtime: string}>
     */
    private function collectServices(
        InstanceRepository $repository,
        InstanceManager $manager,
        ServiceRegistry $registry,
    ): array {
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

        return $services;
    }
}
