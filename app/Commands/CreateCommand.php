<?php

namespace App\Commands;

use App\Commands\Concerns\ResolvesServiceInput;
use App\Services\ServiceRegistry;
use App\Support\CredentialFormatter;
use App\Support\HomebrewConflict;
use App\Support\InstanceManager;
use App\Support\LaunchAgentManager;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\warning;
use function Termwind\render;

class CreateCommand extends Command
{
    use ResolvesServiceInput;

    protected $signature = 'create
                            {service? : The service type (mysql, valkey, mailpit, ...)}
                            {--name= : Instance name}
                            {--port= : Port to bind on 127.0.0.1}
                            {--service-version= : Service version}';

    protected $description = 'Create a service instance';

    protected $aliases = ['add'];

    public function handle(
        InstanceManager $manager,
        ServiceRegistry $registry,
        LaunchAgentManager $autostart,
        HomebrewConflict $homebrew,
    ): int {
        try {
            if ($this->argument('service') === null) {
                if ($this->laravel->runningUnitTests() || ! stream_isatty(STDIN)) {
                    return $this->listServices($registry);
                }

                $service = $this->promptForService($registry);
            } else {
                $service = $this->resolveServiceType($this->argument('service'));
            }

            $this->resolveHomebrewConflicts($service, $homebrew);

            $port = $this->option('port') !== null ? (int) $this->option('port') : null;
            $startAtLogin = $this->promptForStartAtLogin();

            $instance = $manager->create(
                serviceType: $service,
                name: $this->option('name'),
                port: $port,
                version: $this->option('service-version'),
            );

            info("Created and started {$instance->id()} on {$instance->port}");
            $this->renderCredentials($registry->get($service)->credentials($instance));

            if ($startAtLogin) {
                $autostart->add($instance->service, $instance->name);
                info("Added {$instance->id()} to start at login.");
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function promptForStartAtLogin(): bool
    {
        if ($this->laravel->runningUnitTests() || ! stream_isatty(STDIN)) {
            return false;
        }

        return confirm(
            label: 'Start this service at login?',
            default: false,
        );
    }

    private function resolveHomebrewConflicts(string $service, HomebrewConflict $homebrew): void
    {
        $conflicts = $homebrew->installedConflicts($service);

        if ($conflicts === []) {
            return;
        }

        $label = implode(', ', $conflicts);
        warning("Homebrew has {$label} installed, which often conflicts with stackd on the same ports.");

        if ($this->laravel->runningUnitTests() || ! stream_isatty(STDIN) || ! stream_isatty(STDOUT)) {
            throw new RuntimeException(
                "Uninstall conflicting Homebrew packages first: brew uninstall --force {$label}"
            );
        }

        $shouldUninstall = confirm(
            label: "Uninstall {$label} with Homebrew so stackd can manage this service?",
            default: true,
        );

        if (! $shouldUninstall) {
            throw new RuntimeException(
                "Create cancelled. Uninstall conflicting packages later with: brew uninstall --force {$label}"
            );
        }

        info("Uninstalling {$label}...");
        $homebrew->uninstall($conflicts);
        info('Homebrew packages removed.');
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function renderCredentials(array $credentials): void
    {
        $formatted = CredentialFormatter::display($credentials);

        if ($formatted === []) {
            return;
        }

        foreach ($formatted as $label => $value) {
            $safeLabel = htmlspecialchars(ucfirst($label), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeValue = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            render(<<<HTML
                <div class="ml-1">
                    <span class="text-gray-500">{$safeLabel}:</span>
                    <span class="ml-1 font-bold">{$safeValue}</span>
                </div>
            HTML);
        }

        render('<div class="mb-1"></div>');
    }

    private function promptForService(ServiceRegistry $registry): string
    {
        $options = [];

        foreach (config('stackd.services') as $type) {
            if (! $registry->has($type)) {
                continue;
            }

            $service = $registry->get($type);
            $port = config("stackd.default_ports.{$type}");
            $options[$type] = "{$service->displayName()} (:{$port})";
        }

        render(<<<'HTML'
            <div class="mt-1 ml-1">
                <span class="font-bold">Create a service</span>
            </div>
        HTML);

        return select(
            label: 'Which service?',
            options: $options,
            scroll: count($options),
        );
    }

    private function listServices(ServiceRegistry $registry): int
    {
        render(<<<'HTML'
            <div class="mt-1 ml-1">
                <span class="font-bold">Available services</span>
            </div>
        HTML);

        foreach (config('stackd.services') as $type) {
            $available = $registry->has($type);
            $service = $available ? $registry->get($type) : null;
            $name = $service?->displayName() ?? ucfirst($type);
            $port = (string) config("stackd.default_ports.{$type}", '-');
            $status = $available
                ? '<span class="text-green-500">available</span>'
                : '<span class="text-gray-500">coming soon</span>';

            render(<<<HTML
                <div class="ml-1">
                    <span class="font-bold">{$type}</span>
                    <span class="ml-2 text-gray-500">{$name}</span>
                    <span class="ml-2 text-cyan-400">:{$port}</span>
                    <span class="ml-2">{$status}</span>
                </div>
            HTML);
        }

        render(<<<'HTML'
            <div class="mt-1 ml-1 mb-1">
                <span class="text-gray-500">Usage: </span>
                <span class="text-cyan-400">stackd create mysql --name=laravel</span>
            </div>
        HTML);

        return self::SUCCESS;
    }
}
