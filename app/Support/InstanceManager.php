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
    ) {}

    public function create(string $serviceType, ?string $name = null, ?int $port = null, ?string $version = null, array $options = []): Instance
    {
        $service = $this->registry->get($serviceType);
        $name = $name ?: $service->defaultName();
        $port = $port ?: $this->repository->nextAvailablePort($serviceType);

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
        );

        $this->paths->ensureHome();
        $service->create($instance);
        $this->repository->save($instance);
        $this->progress->starting($instance->id(), function () use ($serviceType, $name): void {
            $this->start($serviceType, $name);
        });

        return $instance;
    }

    public function ensureRunning(Instance $instance): void
    {
        if ($this->isRunning($instance)) {
            return;
        }

        $this->registry->get($instance->service)->start($instance);
    }

    public function start(string $serviceType, ?string $name = null): void
    {
        $instance = $this->resolveInstance($serviceType, $name);
        $this->registry->get($serviceType)->start($instance);
    }

    public function stop(string $serviceType, ?string $name = null): void
    {
        $instance = $this->resolveInstance($serviceType, $name);
        $this->registry->get($serviceType)->stop($instance);
    }

    public function restart(string $serviceType, ?string $name = null): void
    {
        $instance = $this->resolveInstance($serviceType, $name);
        $service = $this->registry->get($serviceType);

        if ($service->isRunning($instance)) {
            $service->stop($instance);
        }

        $service->start($instance);
    }

    public function delete(string $serviceType, ?string $name = null): void
    {
        $instance = $this->resolveInstance($serviceType, $name);
        $service = $this->registry->get($serviceType);

        if ($service->isRunning($instance)) {
            $service->stop($instance);
        }

        $this->repository->delete($serviceType, $instance->name);
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
}
