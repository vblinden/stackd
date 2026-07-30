<?php

namespace App\Commands\Concerns;

use App\Services\ServiceRegistry;
use RuntimeException;

trait ResolvesServiceInput
{
    protected function resolveServiceType(?string $service): string
    {
        if ($service === null || $service === '') {
            throw new RuntimeException('Service type is required.');
        }

        $service = strtolower($service);

        if (! $this->registry()->has($service)) {
            $available = implode(', ', $this->registry()->types());
            throw new RuntimeException("Unknown service [{$service}]. Available: {$available}");
        }

        return $service;
    }

    protected function registry(): ServiceRegistry
    {
        return $this->laravel->make(ServiceRegistry::class);
    }
}
