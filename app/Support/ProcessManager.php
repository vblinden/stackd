<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

class ProcessManager
{
    public function isRunning(string $pidFile): bool
    {
        $metadata = $this->readMetadata($pidFile);
        $pid = $metadata['pid'] ?? null;

        if ($pid === null) {
            $this->forgetPidFile($pidFile);

            return false;
        }

        if (! $this->pidExists($pid)) {
            $this->forgetPidFile($pidFile);

            return false;
        }

        $command = $metadata['command'];

        // Legacy / command-less metadata cannot prove ownership after PID reuse.
        // Treat a live PID as running for status/start-guard only; stop() will not signal.
        if ($command === null || $command === '') {
            return true;
        }

        if (! $this->processMatches($pid, $command)) {
            $this->forgetPidFile($pidFile);

            return false;
        }

        return true;
    }

    public function readPid(string $pidFile): ?int
    {
        return $this->readMetadata($pidFile)['pid'] ?? null;
    }

    /** @return array{pid: int, command: string|null}|null */
    private function readMetadata(string $pidFile): ?array
    {
        if (! file_exists($pidFile)) {
            return null;
        }

        $contents = trim((string) file_get_contents($pidFile));

        if ($contents === '') {
            return null;
        }

        $data = json_decode($contents, true);

        if (is_array($data) && is_int($data['pid'] ?? null) && $data['pid'] > 0) {
            $command = $data['command'] ?? null;

            return [
                'pid' => $data['pid'],
                'command' => is_string($command) && $command !== '' ? $command : null,
            ];
        }

        // Strict decimal integer only (reject 1e2, 12.5, 0x10, etc.).
        if (preg_match('/^\d+$/', $contents) === 1) {
            $pid = (int) $contents;

            if ($pid > 0) {
                return [
                    'pid' => $pid,
                    'command' => null,
                ];
            }
        }

        return null;
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

        file_put_contents($pidFile, json_encode([
            'pid' => $pid,
            'command' => (string) $command[0],
        ], JSON_THROW_ON_ERROR)."\n", LOCK_EX);

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
        $metadata = $this->readMetadata($pidFile);
        $pid = $metadata['pid'] ?? null;

        if ($pid === null) {
            $this->forgetPidFile($pidFile);

            return;
        }

        $command = $metadata['command'];

        // Never signal without a stored command: legacy PID-only files cannot prove ownership.
        if (
            $command !== null
            && $command !== ''
            && $this->pidExists($pid)
            && $this->processMatches($pid, $command)
        ) {
            posix_kill($pid, $signal);

            $deadline = microtime(true) + 10;

            while (microtime(true) < $deadline && $this->pidExists($pid) && $this->processMatches($pid, $command)) {
                usleep(100_000);
            }

            if ($this->pidExists($pid) && $this->processMatches($pid, $command)) {
                posix_kill($pid, 9);
            }
        }

        $this->forgetPidFile($pidFile);
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

    private function processMatches(int $pid, string $expectedCommand): bool
    {
        if ($expectedCommand === '') {
            return false;
        }

        $process = new Process(['ps', '-p', (string) $pid, '-o', 'command=']);
        $process->setTimeout(5);
        $process->run();

        if (! $process->isSuccessful()) {
            return false;
        }

        return str_contains(trim($process->getOutput()), $expectedCommand);
    }

    private function forgetPidFile(string $pidFile): void
    {
        if (file_exists($pidFile)) {
            @unlink($pidFile);
        }
    }
}
