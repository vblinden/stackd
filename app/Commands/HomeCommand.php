<?php

namespace App\Commands;

use App\Support\InstanceManager;
use App\Support\InstanceRepository;
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
    ): int {
        $this->renderServices($repository, $manager);

        $describer->describe($this->getApplication(), $this->output);

        return self::SUCCESS;
    }

    private function renderServices(InstanceRepository $repository, InstanceManager $manager): void
    {
        $instances = $repository->all();
        $running = array_values(array_filter(
            $instances,
            fn ($instance) => $manager->isRunning($instance),
        ));
        $stoppedCount = count($instances) - count($running);

        $this->newLine();
        $this->line('  <fg=white;options=bold>Services</>');
        $this->newLine();

        if ($running === []) {
            if ($instances === []) {
                $this->line('  <fg=gray>No instances yet. Run <fg=cyan>stackd create &lt;service&gt;</> to get started.</>');
            } else {
                $this->line('  <fg=yellow>No services running.</> <fg=gray>'.$stoppedCount.' stopped</>');
            }

            $this->newLine();

            return;
        }

        $rows = [];

        foreach ($running as $instance) {
            $status = $manager->statusFor($instance);

            $rows[] = [
                $instance->service,
                $instance->name,
                '<fg=green>running</>',
                config('stackd.bind_address').':'.$instance->port,
                $status['pid'] ? (string) $status['pid'] : '-',
            ];
        }

        $this->table(['Service', 'Name', 'Status', 'Address', 'PID'], $rows);

        if ($stoppedCount > 0) {
            $this->line("  <fg=gray>{$stoppedCount} service(s) stopped — run <fg=cyan>stackd status</> for details</>");
        }

        $this->newLine();
    }
}
