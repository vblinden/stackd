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
                @unlink($pidFile);
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
            'nohup %s%s >> %s 2>&1 & echo $!',
            $this->lowPowerPrefix(),
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

    /**
     * Prefer background QoS on macOS so idle services are less aggressive on battery.
     */
    private function lowPowerPrefix(): string
    {
        if (PHP_OS_FAMILY !== 'Darwin') {
            return '';
        }

        $prefix = 'nice -n 10 ';

        if (is_executable('/usr/bin/taskpolicy')) {
            $prefix .= '/usr/bin/taskpolicy -c background ';
        }

        return $prefix;
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

    public function run(array $command, ?string $cwd = null, int $timeout = 300, ?string $logFile = null): Process
    {
        $process = new Process($command, $cwd);
        $process->setTimeout($timeout);

        if ($logFile !== null) {
            $this->ensureLogDirectory($logFile);
            $handle = fopen($logFile, 'w');

            if ($handle === false) {
                throw new RuntimeException("Unable to write log file {$logFile}");
            }

            $process->run(function ($type, $buffer) use ($handle): void {
                fwrite($handle, $buffer);
            });

            fclose($handle);
        } else {
            $process->run();
        }

        return $process;
    }

    public function runOrFail(array $command, ?string $cwd = null, int $timeout = 300, ?string $logFile = null): void
    {
        $process = $this->run($command, $cwd, $timeout, $logFile);

        if ($process->isSuccessful()) {
            return;
        }

        if ($logFile !== null && file_exists($logFile)) {
            throw new RuntimeException($this->summarizeLogFailure($logFile, $command));
        }

        throw new RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'Command failed.');
    }

    /**
     * @param  array<int, string>  $command
     */
    private function summarizeLogFailure(string $logFile, array $command): string
    {
        $lines = file($logFile, FILE_IGNORE_NEW_LINES);

        if ($lines === false || $lines === []) {
            return 'Command failed. See '.$logFile;
        }

        $errors = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            if (
                str_contains($trimmed, 'error:')
                || str_contains($trimmed, 'Error:')
                || str_contains($trimmed, 'CMake Error')
                || str_contains($trimmed, 'fatal error:')
                || str_starts_with($trimmed, 'make: ***')
                || str_contains($trimmed, 'FAILED:')
            ) {
                // Skip noisy SDK nullability warnings that can contain the word in other forms.
                if (str_contains($trimmed, 'nullability') || str_contains($trimmed, '_Nonnull') || str_contains($trimmed, '_Nullable')) {
                    continue;
                }

                $errors[] = $trimmed;

                if (count($errors) >= 8) {
                    break;
                }
            }
        }

        $summary = $errors !== []
            ? implode("\n", $errors)
            : implode("\n", array_slice($lines, -20));

        $label = implode(' ', array_slice($command, 0, 4));

        return "Command failed ({$label}).\n{$summary}\nFull log: {$logFile}";
    }

    private function ensureLogDirectory(string $logFile): void
    {
        $dir = dirname($logFile);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
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
