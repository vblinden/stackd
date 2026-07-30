<?php

namespace App\Support;

class StackdPaths
{
    public function __construct(
        private readonly string $home,
    ) {}

    public static function make(): self
    {
        return new self(config('stackd.home'));
    }

    public function home(): string
    {
        return $this->home;
    }

    public function instances(): string
    {
        return $this->home.'/instances';
    }

    public function instance(string $service, string $name): string
    {
        return $this->instances().'/'.$service.'/'.$name;
    }

    public function binaries(): string
    {
        return $this->home.'/binaries';
    }

    public function binary(string $service, ?string $version = null): string
    {
        $dir = $this->binaries().'/'.$service;

        if ($version !== null) {
            return $dir.'/'.$version;
        }

        return $dir;
    }

    public function registry(): string
    {
        return $this->home.'/registry.json';
    }

    public function autostart(): string
    {
        return $this->home.'/autostart.json';
    }

    public function launchAgents(): string
    {
        return rtrim(getenv('HOME') ?: '', '/').'/Library/LaunchAgents';
    }

    public function launchAgentPlist(string $label): string
    {
        return $this->launchAgents().'/'.$label.'.plist';
    }

    public function metadata(string $service, string $name): string
    {
        return $this->instance($service, $name).'/metadata.json';
    }

    public function dataDir(string $service, string $name): string
    {
        return $this->instance($service, $name).'/data';
    }

    public function logsDir(string $service, string $name): string
    {
        return $this->instance($service, $name).'/logs';
    }

    public function pidFile(string $service, string $name): string
    {
        return $this->instance($service, $name).'/process.pid';
    }

    public function ensureHome(): void
    {
        foreach ([$this->home, $this->instances(), $this->binaries()] as $path) {
            if (! is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
    }
}
