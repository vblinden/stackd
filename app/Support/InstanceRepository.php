<?php

namespace App\Support;

use RuntimeException;

class InstanceRepository
{
    public function __construct(
        private readonly StackdPaths $paths,
    ) {}

    public function all(): array
    {
        $registry = $this->readRegistry();

        return array_map(
            fn (array $data) => Instance::fromArray($data),
            $registry['instances'] ?? [],
        );
    }

    public function forService(string $service): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (Instance $instance) => $instance->service === $service,
        ));
    }

    public function find(string $service, string $name): ?Instance
    {
        foreach ($this->all() as $instance) {
            if ($instance->service === $service && $instance->name === $name) {
                return $instance;
            }
        }

        return null;
    }

    public function findOrFail(string $service, string $name): Instance
    {
        $instance = $this->find($service, $name);

        if ($instance === null) {
            throw new RuntimeException("Instance [{$service}:{$name}] not found.");
        }

        return $instance;
    }

    public function defaultForService(string $service): ?Instance
    {
        $instances = $this->forService($service);

        if ($instances === []) {
            return null;
        }

        foreach ($instances as $instance) {
            if ($instance->name === 'default') {
                return $instance;
            }
        }

        return $instances[0];
    }

    public function save(Instance $instance): void
    {
        $registry = $this->readRegistry();
        $instances = $registry['instances'] ?? [];
        $found = false;

        foreach ($instances as $index => $data) {
            if ($data['service'] === $instance->service && $data['name'] === $instance->name) {
                $instances[$index] = $instance->toArray();
                $found = true;
                break;
            }
        }

        if (! $found) {
            $instances[] = $instance->toArray();
        }

        $registry['instances'] = array_values($instances);
        $this->writeRegistry($registry);

        $this->paths->ensureHome();
        $dir = $this->paths->instance($instance->service, $instance->name);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (! is_dir($this->paths->dataDir($instance->service, $instance->name))) {
            mkdir($this->paths->dataDir($instance->service, $instance->name), 0755, true);
        }

        if (! is_dir($this->paths->logsDir($instance->service, $instance->name))) {
            mkdir($this->paths->logsDir($instance->service, $instance->name), 0755, true);
        }

        file_put_contents(
            $this->paths->metadata($instance->service, $instance->name),
            json_encode($instance->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );
    }

    public function delete(string $service, string $name): void
    {
        $registry = $this->readRegistry();
        $instances = $registry['instances'] ?? [];

        $registry['instances'] = array_values(array_filter(
            $instances,
            fn (array $data) => ! ($data['service'] === $service && $data['name'] === $name),
        ));

        $this->writeRegistry($registry);

        $dir = $this->paths->instance($service, $name);

        if (is_dir($dir)) {
            $this->removeDirectory($dir);
        }
    }

    public function nextAvailablePort(string $service): int
    {
        $default = config("stackd.default_ports.{$service}", 9000);
        $used = array_map(fn (Instance $i) => $i->port, $this->forService($service));

        $port = $default;

        while (in_array($port, $used, true) || $this->portInUse($port)) {
            $port++;
        }

        return $port;
    }

    private function portInUse(int $port): bool
    {
        $connection = @fsockopen(config('stackd.bind_address'), $port, $errno, $errstr, 0.2);

        if (is_resource($connection)) {
            fclose($connection);

            return true;
        }

        return false;
    }

    private function readRegistry(): array
    {
        $this->paths->ensureHome();

        if (! file_exists($this->paths->registry())) {
            return ['instances' => []];
        }

        $contents = file_get_contents($this->paths->registry());

        return json_decode($contents ?: '{}', true) ?: ['instances' => []];
    }

    private function writeRegistry(array $registry): void
    {
        $this->paths->ensureHome();

        file_put_contents(
            $this->paths->registry(),
            json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
        );
    }

    private function removeDirectory(string $dir): void
    {
        $items = scandir($dir) ?: [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.'/'.$item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
