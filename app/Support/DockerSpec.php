<?php

namespace App\Support;

class DockerSpec
{
    /**
     * @param  array<int, int>  $ports  hostPort => containerPort
     * @param  array<string, string>  $env
     * @param  array<string, string>  $volumes  hostPath => containerPath
     * @param  list<string>  $command
     */
    public function __construct(
        public readonly string $image,
        public readonly array $ports,
        public readonly array $env = [],
        public readonly array $volumes = [],
        public readonly array $command = [],
        public readonly string $restart = 'no',
    ) {}

    public function withRestart(string $restart): self
    {
        return new self(
            image: $this->image,
            ports: $this->ports,
            env: $this->env,
            volumes: $this->volumes,
            command: $this->command,
            restart: $restart,
        );
    }
}
