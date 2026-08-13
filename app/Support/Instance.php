<?php

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;

class Instance implements JsonSerializable
{
    public function __construct(
        public readonly string $service,
        public readonly string $name,
        public int $port,
        public ?string $version = null,
        public array $options = [],
        public ?string $createdAt = null,
        public readonly string $runtime = StackdConfig::RUNTIME_NATIVE,
    ) {
        InstanceName::assertValid($this->name);

        if (! in_array($this->runtime, [StackdConfig::RUNTIME_NATIVE, StackdConfig::RUNTIME_DOCKER], true)) {
            throw new InvalidArgumentException('Instance runtime must be native or docker.');
        }

        $this->createdAt ??= (new \DateTimeImmutable)->format(\DateTimeInterface::ATOM);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            service: $data['service'],
            name: $data['name'],
            port: (int) $data['port'],
            version: $data['version'] ?? null,
            options: $data['options'] ?? [],
            createdAt: $data['created_at'] ?? null,
            runtime: $data['runtime'] ?? StackdConfig::RUNTIME_NATIVE,
        );
    }

    public function id(): string
    {
        return $this->service.':'.$this->name;
    }

    public function isDocker(): bool
    {
        return $this->runtime === StackdConfig::RUNTIME_DOCKER;
    }

    public function toArray(): array
    {
        return [
            'service' => $this->service,
            'name' => $this->name,
            'port' => $this->port,
            'version' => $this->version,
            'options' => $this->options,
            'created_at' => $this->createdAt,
            'runtime' => $this->runtime,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    public function withOptions(array $options): self
    {
        return new self(
            service: $this->service,
            name: $this->name,
            port: $this->port,
            version: $this->version,
            options: array_merge($this->options, $options),
            createdAt: $this->createdAt,
            runtime: $this->runtime,
        );
    }
}
