<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

class DockerEngine
{
    public function __construct(
        private readonly ProcessManager $processes,
        private readonly ?string $binary = null,
    ) {}

    public function containerName(Instance $instance): string
    {
        return 'stackd-'.$instance->service.'-'.$instance->name;
    }

    public function binary(): string
    {
        if ($this->binary !== null && $this->binary !== '') {
            return $this->binary;
        }

        $candidates = array_filter([
            getenv('DOCKER_BINARY') ?: null,
            '/usr/local/bin/docker',
            '/opt/homebrew/bin/docker',
            '/usr/bin/docker',
        ]);

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        $process = Process::fromShellCommandline('command -v docker');
        $process->run();
        $path = trim($process->getOutput());

        if ($process->isSuccessful() && $path !== '' && is_executable($path)) {
            return $path;
        }

        throw new RuntimeException('Docker is not installed. Install Docker Desktop or set runtime back to native with: stackd runtime native');
    }

    public function isAvailable(): bool
    {
        try {
            $this->binary();

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function daemonRunning(): bool
    {
        if (! $this->isAvailable()) {
            return false;
        }

        $process = $this->processes->run([$this->binary(), 'info']);

        return $process->isSuccessful();
    }

    public function assertReady(): void
    {
        $this->binary();

        if (! $this->daemonRunning()) {
            throw new RuntimeException('Docker is installed but the daemon is not running. Open Docker Desktop and try again.');
        }
    }

    public function exists(Instance $instance): bool
    {
        $process = $this->processes->run([
            $this->binary(),
            'inspect',
            '--format', '{{.Id}}',
            $this->containerName($instance),
        ]);

        return $process->isSuccessful();
    }

    public function isRunning(Instance $instance): bool
    {
        $process = $this->processes->run([
            $this->binary(),
            'inspect',
            '--format', '{{.State.Running}}',
            $this->containerName($instance),
        ]);

        return $process->isSuccessful() && trim($process->getOutput()) === 'true';
    }

    /**
     * @return array{id: string|null, image: string|null, running: bool}
     */
    public function inspect(Instance $instance): array
    {
        $process = $this->processes->run([
            $this->binary(),
            'inspect',
            '--format', '{{.Id}}|{{.Config.Image}}|{{.State.Running}}',
            $this->containerName($instance),
        ]);

        if (! $process->isSuccessful()) {
            return ['id' => null, 'image' => null, 'running' => false];
        }

        $parts = explode('|', trim($process->getOutput()), 3);

        return [
            'id' => $parts[0] !== '' ? $parts[0] : null,
            'image' => $parts[1] ?? null,
            'running' => ($parts[2] ?? '') === 'true',
        ];
    }

    public function start(Instance $instance, DockerSpec $spec): void
    {
        $this->assertReady();

        if ($this->isRunning($instance)) {
            throw new RuntimeException('Service is already running.');
        }

        foreach (array_keys($spec->volumes) as $hostPath) {
            if (! is_dir($hostPath)) {
                mkdir($hostPath, 0755, true);
            }
        }

        if ($this->exists($instance)) {
            $this->processes->runOrFail([$this->binary(), 'start', $this->containerName($instance)]);
            $this->setRestartPolicy($instance, $spec->restart);
            $this->waitUntilReady($instance);

            return;
        }

        $this->processes->runOrFail($this->buildRunCommand($instance, $spec));
        $this->waitUntilReady($instance);
    }

    public function stop(Instance $instance): void
    {
        if (! $this->isAvailable() || ! $this->exists($instance) || ! $this->isRunning($instance)) {
            return;
        }

        $this->processes->runOrFail([$this->binary(), 'stop', $this->containerName($instance)]);
    }

    public function remove(Instance $instance): void
    {
        if (! $this->isAvailable() || ! $this->exists($instance)) {
            return;
        }

        $this->processes->runOrFail([$this->binary(), 'rm', '-f', $this->containerName($instance)]);
    }

    public function setRestartPolicy(Instance $instance, string $policy): void
    {
        if (! $this->isAvailable() || ! $this->exists($instance)) {
            return;
        }

        $this->processes->runOrFail([
            $this->binary(),
            'update',
            '--restart',
            $policy,
            $this->containerName($instance),
        ]);
    }

    /**
     * @return list<string>
     */
    public function logsCommand(Instance $instance, bool $follow = false): array
    {
        $command = [$this->binary(), 'logs'];

        if ($follow) {
            $command[] = '--follow';
        }

        $command[] = $this->containerName($instance);

        return $command;
    }

    /**
     * @return list<string>
     */
    public function buildRunCommand(Instance $instance, DockerSpec $spec): array
    {
        $bind = config('stackd.bind_address', '127.0.0.1');
        $command = [
            $this->binary(),
            'run',
            '-d',
            '--name', $this->containerName($instance),
            '--label', 'com.stackd.managed=1',
            '--label', 'com.stackd.instance='.$instance->id(),
            '--restart', $spec->restart,
        ];

        foreach ($spec->ports as $host => $container) {
            $command[] = '-p';
            $command[] = $bind.':'.$host.':'.$container;
        }

        foreach ($spec->env as $key => $value) {
            $command[] = '-e';
            $command[] = $key.'='.$value;
        }

        foreach ($spec->volumes as $host => $container) {
            $command[] = '-v';
            $command[] = $host.':'.$container;
        }

        $command[] = $spec->image;

        foreach ($spec->command as $part) {
            $command[] = $part;
        }

        return $command;
    }

    public function waitUntilReady(Instance $instance, int $timeoutSeconds = 45): void
    {
        $host = config('stackd.bind_address', '127.0.0.1');
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if (! $this->isRunning($instance)) {
                throw new RuntimeException("Docker container {$this->containerName($instance)} exited before it became ready.");
            }

            $connection = @fsockopen($host, $instance->port, $errno, $errstr, 0.2);

            if (is_resource($connection)) {
                fclose($connection);

                return;
            }

            usleep(200_000);
        }

        throw new RuntimeException("Timed out waiting for {$instance->id()} to accept connections on {$host}:{$instance->port}.");
    }
}
