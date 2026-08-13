<?php

namespace App\Support;

use App\Services\ServiceRegistry;
use RuntimeException;

class InstanceManager
{
    public function __construct(
        private readonly InstanceRepository $repository,
        private readonly ServiceRegistry $registry,
        private readonly StackdPaths $paths,
        private readonly InstallProgress $progress,
        private readonly LaunchAgentManager $autostart,
        private readonly StackdConfig $config,
        private readonly DockerEngine $docker,
    ) {}

    public function create(string $serviceType, ?string $name = null, ?int $port = null, ?string $version = null, array $options = [], ?string $runtime = null): Instance
    {
        return $this->repository->synchronized(fn () => $this->createLocked($serviceType, $name, $port, $version, $options, $runtime));
    }

    private function createLocked(string $serviceType, ?string $name, ?int $port, ?string $version, array $options, ?string $runtime): Instance
    {
        $service = $this->registry->get($serviceType);
        $name = $name ?: $service->defaultName();
        $port = $port ?? $this->nextAvailablePort($serviceType);

        $this->assertAvailablePorts($serviceType, $port);

        if ($this->repository->find($serviceType, $name) !== null) {
            throw new RuntimeException("Instance [{$serviceType}:{$name}] already exists.");
        }

        if ($serviceType === 'mailpit' && ! isset($options['web_port'])) {
            $options['web_port'] = $port + 7000;
        }

        if (in_array($serviceType, ['mysql', 'mariadb'], true)) {
            $options = array_merge([
                'database' => 'laravel',
                'username' => 'root',
                'password' => '',
            ], $options);
        }

        if ($serviceType === 'postgresql') {
            $options = array_merge([
                'database' => 'laravel',
                'username' => 'laravel',
                'password' => '',
            ], $options);
        }

        if ($serviceType === 'meilisearch' && ! isset($options['master_key'])) {
            $options['master_key'] = bin2hex(random_bytes(16));
        }

        if ($serviceType === 'minio') {
            $options = array_merge([
                'access_key' => 'stackd',
                'secret_key' => 'secretkey',
                'bucket' => 'laravel',
                'console_port' => $port + 1,
            ], $options);
        }

        $instance = new Instance(
            service: $serviceType,
            name: $name,
            port: $port,
            version: $version ?: ($service->availableVersions()[0] ?? 'latest'),
            options: $options,
            runtime: $runtime ?: $this->config->runtime(),
        );

        try {
            $this->paths->ensureHome();

            if ($instance->isDocker()) {
                $this->docker->assertReady();
                $this->repository->save($instance);
            } else {
                $service->create($instance);
                $this->repository->save($instance);
            }

            $this->progress->starting($instance->id(), function () use ($serviceType, $name): void {
                $this->start($serviceType, $name);
            });
        } catch (\Throwable $e) {
            if ($instance->isDocker()) {
                $this->docker->remove($instance);
            }

            $this->repository->delete($serviceType, $name);

            throw $e;
        }

        return $instance;
    }

    public function ensureRunning(Instance $instance): void
    {
        if ($this->isRunning($instance)) {
            return;
        }

        $this->start($instance->service, $instance->name);
    }

    public function start(string $serviceType, ?string $name = null): void
    {
        $instance = $this->resolveInstance($serviceType, $name);

        if ($instance->isDocker()) {
            $this->docker->start($instance, $this->dockerSpecFor($instance));

            return;
        }

        $this->registry->get($serviceType)->start($instance);
    }

    public function stop(string $serviceType, ?string $name = null): void
    {
        $instance = $this->resolveInstance($serviceType, $name);

        if ($instance->isDocker()) {
            $this->docker->stop($instance);

            return;
        }

        $this->registry->get($serviceType)->stop($instance);
    }

    /**
     * Start every registered instance that is not already running.
     *
     * @return list<Instance>
     */
    public function startAll(): array
    {
        $started = [];

        foreach ($this->repository->all() as $instance) {
            if ($this->isRunning($instance)) {
                continue;
            }

            $this->start($instance->service, $instance->name);
            $started[] = $instance;
        }

        return $started;
    }

    /**
     * Stop every registered instance.
     *
     * @return list<Instance>
     */
    public function stopAll(): array
    {
        $stopped = [];

        foreach ($this->repository->all() as $instance) {
            $this->stop($instance->service, $instance->name);
            $stopped[] = $instance;
        }

        return $stopped;
    }

    public function restart(string $serviceType, ?string $name = null): void
    {
        $instance = $this->resolveInstance($serviceType, $name);

        if ($this->isRunning($instance)) {
            $this->stop($serviceType, $instance->name);
        }

        $this->start($serviceType, $instance->name);
    }

    public function delete(string $serviceType, ?string $name = null): void
    {
        $instance = $this->resolveInstance($serviceType, $name);

        if ($this->isRunning($instance)) {
            $this->stop($serviceType, $instance->name);
        }

        if ($instance->isDocker()) {
            $this->docker->remove($instance);
        }

        $this->repository->delete($serviceType, $instance->name);
        $this->autostart->remove($serviceType, $instance->name);
    }

    public function resolveInstance(string $serviceType, ?string $name = null): Instance
    {
        if ($name !== null) {
            return $this->repository->findOrFail($serviceType, $name);
        }

        $default = $this->repository->defaultForService($serviceType);

        if ($default === null) {
            throw new RuntimeException("No instance found for service [{$serviceType}]. Create one with: stackd create {$serviceType}");
        }

        return $default;
    }

    public function envForInstance(Instance $instance): array
    {
        return $this->registry->get($instance->service)->envVariables($instance);
    }

    public function envForServices(array $serviceTypes): array
    {
        $variables = [];

        foreach ($serviceTypes as $serviceType) {
            $instance = $this->repository->defaultForService($serviceType);

            if ($instance === null) {
                continue;
            }

            $variables = array_merge($variables, $this->envForInstance($instance));
        }

        return $variables;
    }

    public function isRunning(Instance $instance): bool
    {
        return $this->registry->get($instance->service)->isRunning($instance);
    }

    public function statusFor(Instance $instance): array
    {
        return $this->registry->get($instance->service)->statusDetails($instance);
    }

    public function dockerSpecFor(Instance $instance): DockerSpec
    {
        $spec = $this->registry->get($instance->service)->dockerSpec($instance);

        if (in_array($instance->id(), $this->autostart->list(), true)) {
            return $spec->withRestart('unless-stopped');
        }

        return $spec;
    }

    private function nextAvailablePort(string $serviceType): int
    {
        $port = $this->repository->nextAvailablePort($serviceType);

        while (! $this->portsAreAvailable($serviceType, $port)) {
            $port++;
        }

        return $port;
    }

    private function assertAvailablePorts(string $serviceType, int $port): void
    {
        if (! $this->portsAreAvailable($serviceType, $port)) {
            throw new RuntimeException("Port {$port} (or a required companion port) is unavailable.");
        }
    }

    private function portsAreAvailable(string $serviceType, int $port): bool
    {
        $ports = match ($serviceType) {
            'mailpit' => [$port, $port + 7000],
            'minio' => [$port, $port + 1],
            default => [$port],
        };

        foreach ($ports as $candidate) {
            if (! $this->repository->isPortAvailable($candidate)) {
                return false;
            }
        }

        return true;
    }
}
