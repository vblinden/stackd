<?php

namespace App\Services;

use App\Services\Contracts\ServiceInterface;
use RuntimeException;

class ServiceRegistry
{
    /** @var array<string, ServiceInterface> */
    private array $services = [];

    public function register(ServiceInterface $service): void
    {
        $this->services[$service::type()] = $service;
    }

    public function get(string $type): ServiceInterface
    {
        $type = strtolower($type);

        if (! isset($this->services[$type])) {
            throw new RuntimeException("Service [{$type}] is not registered.");
        }

        return $this->services[$type];
    }

    public function has(string $type): bool
    {
        return isset($this->services[strtolower($type)]);
    }

    /**
     * @return array<string, ServiceInterface>
     */
    public function all(): array
    {
        return $this->services;
    }

    /**
     * @return array<int, string>
     */
    public function types(): array
    {
        return array_keys($this->services);
    }
}
