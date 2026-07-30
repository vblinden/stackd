<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

class ProcessManager
{
    public function isRunning(string $pidFile): bool
    {
        $pid = $this->readPid($pidFile);

        if ($pid === null) {
            return false;
        }

        if (! $this->pidExists($pid)) {
            if (file_exists($pidFile)) {
                unlink($pidFile);
            }

            return false;
        }

        return true;
    }

    public function readPid(string $pidFile): ?int
    {
        if (! file_exists($pidFile)) {
            return null;
        }

        $pid = (int) trim((string) file_get_contents($pidFile));

        return $pid > 0 ? $pid : null;
    }

    public function start(array $command, string $pidFile, string $logFile, ?string $cwd = null, array $env = []): int
    {
        if ($this->isRunning($pidFile)) {
            throw new RuntimeException('Service is already running.');
        }

        $logDir = dirname($logFile);

        if (! is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $env = $this->filterEnvironment(array_merge($_ENV, $_SERVER, $env));

        $wrapped = sprintf(
            'nohup %s >> %s 2>&1 & echo $!',
            implode(' ', array_map('escapeshellarg', $command)),
            escapeshellarg($logFile),
        );

        $process = Process::fromShellCommandline($wrapped, $cwd, $env);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'Failed to start process.');
        }

        $pid = (int) trim($process->getOutput());

        if ($pid <= 0) {
            throw new RuntimeException('Failed to obtain process PID.');
        }

        file_put_contents($pidFile, (string) $pid);

        return $pid;
    }

    public function stop(string $pidFile, int $signal = 15): void
    {
        $pid = $this->readPid($pidFile);

        if ($pid === null) {
            return;
        }

        if ($this->pidExists($pid)) {
            posix_kill($pid, $signal);

            $deadline = microtime(true) + 10;

            while (microtime(true) < $deadline && $this->pidExists($pid)) {
                usleep(100_000);
            }

            if ($this->pidExists($pid)) {
                posix_kill($pid, 9);
            }
        }

        if (file_exists($pidFile)) {
            unlink($pidFile);
        }
    }

    public function run(array $command, ?string $cwd = null, int $timeout = 300): Process
    {
        $process = new Process($command, $cwd);
        $process->setTimeout($timeout);
        $process->run();

        return $process;
    }

    public function runOrFail(array $command, ?string $cwd = null, int $timeout = 300): void
    {
        $process = $this->run($command, $cwd, $timeout);

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'Command failed.');
        }
    }

    private function filterEnvironment(array $env): array
    {
        $filtered = [];

        foreach ($env as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if (is_string($value) || is_numeric($value)) {
                $filtered[$key] = (string) $value;
            }
        }

        return $filtered;
    }

    private function pidExists(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        return posix_kill($pid, 0);
    }
}
