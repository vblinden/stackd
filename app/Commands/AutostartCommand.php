<?php

namespace App\Commands;

use App\Commands\Concerns\ResolvesServiceInput;
use App\Support\InstanceManager;
use App\Support\LaunchAgentManager;
use LaravelZero\Framework\Commands\Command;

class AutostartCommand extends Command
{
    use ResolvesServiceInput;

    protected $signature = 'autostart
                            {action : enable, disable, add, remove, list, or run}
                            {service? : Service type for add/remove}
                            {name? : Instance name for add/remove}';

    protected $description = 'Manage start at login';

    public function handle(LaunchAgentManager $autostart, InstanceManager $manager): int
    {
        try {
            return match ($this->argument('action')) {
                'enable' => $this->enable($autostart),
                'disable' => $this->disable($autostart),
                'add' => $this->add($autostart),
                'remove' => $this->remove($autostart),
                'list' => $this->listEntries($autostart),
                'run' => $this->runEntries($autostart, $manager),
                default => throw new \RuntimeException('Invalid action. Use: enable, disable, add, remove, list, run'),
            };
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function enable(LaunchAgentManager $autostart): int
    {
        $autostart->enable();
        $this->components->info('Autostart enabled via LaunchAgent.');

        return self::SUCCESS;
    }

    private function disable(LaunchAgentManager $autostart): int
    {
        $autostart->disable();
        $this->components->info('Autostart disabled.');

        return self::SUCCESS;
    }

    private function add(LaunchAgentManager $autostart): int
    {
        $service = $this->resolveServiceType($this->argument('service'));
        $name = $this->argument('name') ?: 'default';

        if (! $autostart->isEnabled()) {
            $autostart->enable();
        }

        $autostart->add($service, $name);
        $this->components->info("Added {$service}:{$name} to autostart.");

        return self::SUCCESS;
    }

    private function remove(LaunchAgentManager $autostart): int
    {
        $service = $this->resolveServiceType($this->argument('service'));
        $name = $this->argument('name') ?: 'default';

        $autostart->remove($service, $name);
        $this->components->info("Removed {$service}:{$name} from autostart.");

        return self::SUCCESS;
    }

    private function listEntries(LaunchAgentManager $autostart): int
    {
        $entries = $autostart->list();

        if ($entries === []) {
            $this->components->warn('No autostart entries configured.');

            return self::SUCCESS;
        }

        $this->table(['Instance'], array_map(fn (string $entry) => [$entry], $entries));

        return self::SUCCESS;
    }

    private function runEntries(LaunchAgentManager $autostart, InstanceManager $manager): int
    {
        $entries = $autostart->list();

        if ($entries === []) {
            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($entries as $entry) {
            if (! str_contains($entry, ':')) {
                fwrite(STDERR, "Invalid autostart entry [{$entry}]\n");
                $failed++;

                continue;
            }

            [$service, $name] = explode(':', $entry, 2);

            try {
                $instance = $manager->resolveInstance($service, $name);

                if ($manager->isRunning($instance)) {
                    continue;
                }

                $manager->start($service, $name);
            } catch (\Throwable $e) {
                fwrite(STDERR, "Failed to start {$entry}: {$e->getMessage()}\n");
                $failed++;
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
