<?php

namespace App\Commands;

use App\Commands\Concerns\ResolvesServiceInput;
use App\Support\LaunchAgentManager;
use LaravelZero\Framework\Commands\Command;

class AutostartCommand extends Command
{
    use ResolvesServiceInput;

    protected $signature = 'autostart
                            {action : enable, disable, add, remove, or list}
                            {service? : Service type for add/remove}
                            {name? : Instance name for add/remove}';

    protected $description = 'Manage login autostart for stackd services';

    public function handle(LaunchAgentManager $autostart): int
    {
        try {
            return match ($this->argument('action')) {
                'enable' => $this->enable($autostart),
                'disable' => $this->disable($autostart),
                'add' => $this->add($autostart),
                'remove' => $this->remove($autostart),
                'list' => $this->listEntries($autostart),
                default => throw new \RuntimeException('Invalid action. Use: enable, disable, add, remove, list'),
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
}
